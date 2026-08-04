# ============================================================
# POST /api/v1/analytics/classify-questions  — LLM topic classification
# POST /api/v1/analytics/struggle-topics     — Topic struggle analysis
# POST /api/v1/analytics/student-risk        — Per-student risk assessment
# ============================================================

import json
import logging
from typing import List, Optional
import hashlib
from datetime import datetime

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel

from middleware.auth import verify_token
from core.llm_processor import LLMProcessor
from core.vector_store import get_chroma_client, embed_texts, get_embedding_function
from config import get_settings
from sqlalchemy.orm import Session
from models.database import get_db, StudentSnapshot, AnalyticsCache
from analytics.student_profile import upsert_student_context

logger   = logging.getLogger(__name__)
router   = APIRouter(tags=["analytics"])
settings = get_settings()


# ── Schemas ─────────────────────────────────────────────────

class QuestionItem(BaseModel):
    id: int
    text: str

class ClassifyRequest(BaseModel):
    course_id: int
    questions: List[QuestionItem]
    known_topics: List[str] = []

class ClassificationResult(BaseModel):
    id: int
    topic: str
    confidence: float
    struggle_type: str  # conceptual / procedural / clarity / application

class ClassifyResponse(BaseModel):
    classifications: List[ClassificationResult]
    llm_used: str = "gemini"

class StruggleTopic(BaseModel):
    topic: str
    question_count: int
    struggle_score: float
    recommendation: str

class StruggleTopicsResponse(BaseModel):
    topics: List[StruggleTopic]
    summary: str

class StudentRiskItem(BaseModel):
    user_id: int
    question_count: int
    risk_score: float = 0.0
    narrative: str = ""
    risk_factors: Optional[List[str]] = []
    recommendation: str = ""

class StudentRiskResponse(BaseModel):
    students: List[StudentRiskItem]


class AnalyticsUpdateRequest(BaseModel):
    user_id: int
    course_id: int
    event_type: str
    payload: dict = {}
    profile: dict = {}


class AnalyticsUpdateResponse(BaseModel):
    success: bool
    message: str


class SnapshotStudent(BaseModel):
    userid: int
    logins: int = 0
    avg_quiz_grade: float = 0.0
    ai_questions_asked: int = 0
    risk_score: float = 0.0
    last_active: int = 0


class SnapshotRequest(BaseModel):
    course_id: int
    snapshot_time: int
    students: List[SnapshotStudent]


class SnapshotResponse(BaseModel):
    status: str
    count: int


class NLQRequest(BaseModel):
    course_id: int
    query: str


class NLQStudent(BaseModel):
    student_id: int
    name: str
    risk_level: str
    root_cause: str
    evidence_citations: List[str]
    ai_draft_message: str


class NLQResponse(BaseModel):
    students: List[NLQStudent]
    global_insight: str


# ── Dashboard Schemas ───────────────────────────────────────

class DashboardRequest(BaseModel):
    course_id: int
    time_window_days: int = 60

class DashboardKPIs(BaseModel):
    engagement_score: float
    at_risk_count: int
    total_students: int
    top_topic_insight: str

class DashboardResponse(BaseModel):
    kpis: DashboardKPIs
    recommendations: List[str]
    impact_summary: str


# ── Extract Topics Schemas ──────────────────────────────────

class ExtractTopicsRequest(BaseModel):
    course_id: int = 0
    course_name: Optional[str] = ""
    questions: List[str] = []
    course_materials: Optional[List[dict]] = []
    course_sections: Optional[List[dict]] = []
    issue_reports: Optional[List[dict]] = []
    student_events: Optional[List[dict]] = []
    previous_topics: Optional[List[dict]] = []

class ExtractedTopicEvidence(BaseModel):
    chat_questions: int = 0
    quiz_failures: int = 0
    repeated_views: int = 0
    assignment_failures: int = 0
    issue_reports: int = 0

class ExtractTopicsTopic(BaseModel):
    topic_name: str
    struggle_score: float = 50.0
    severity: str = "watch"        # critical / attention / watch
    trend: str = "stable"          # up / down / stable / new
    student_count: int = 0
    question_count: int = 0
    evidence_sources: ExtractedTopicEvidence = ExtractedTopicEvidence()
    sample_questions: List[str] = []
    related_materials: List[str] = []
    related_sections: List[str] = []
    affected_student_ids: List[int] = []
    suggestion: str = ""
    suggestion_type: str = "recap" # recap / practice / review / one_on_one / material

class ExtractTopicsResponse(BaseModel):
    topics: List[ExtractTopicsTopic]
    confidence: float = 0.0
    summary_insight: str = ""
    total_questions_analyzed: int = 0
    from_cache: bool = False


# ── LLM Processor Helper ────────────────────────────────────

def get_llm() -> LLMProcessor:
    try:
        return LLMProcessor()
    except Exception as e:
        logger.warning(f"LLM not available: {e}")
        raise HTTPException(status_code=503, detail="LLM service unavailable")

def _call_llm(prompt: str, max_chars: int = 4096) -> str:
    llm = get_llm()
    return llm._invoke(prompt, temperature=0.2, max_chars=max_chars)


def _parse_llm_json(raw: str):
    """Parse JSON from an LLM reply, tolerating markdown code fences anywhere
    in the text and surrounding prose. Uses bracket-depth tracking for the
    fallback to avoid picking up stray brackets in narrative text."""
    import re as _re
    text = raw.strip()

    # 1. Direct parse.
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        pass

    # 2. Strip markdown code fences (```json … ```) wherever they appear.
    cleaned = _re.sub(r'```(?:json)?\s*\n?', '', text)
    cleaned = cleaned.strip()
    if cleaned.endswith('```'):
        cleaned = cleaned[:-3].strip()
    try:
        return json.loads(cleaned)
    except json.JSONDecodeError:
        pass

    # 3. Bracket-depth tracking — find outermost [...] or {...}
    #    This correctly skips stray brackets in prose (e.g. "struggle_topics: []").
    for opener, closer in (('[', ']'), ('{', '}')):
        depth = 0
        start = -1
        for i, c in enumerate(text):
            if c == opener:
                if depth == 0:
                    start = i
                depth += 1
            elif c == closer:
                depth -= 1
                if depth == 0 and start != -1:
                    try:
                        return json.loads(text[start:i + 1])
                    except json.JSONDecodeError:
                        start = -1
                        continue

    raise json.JSONDecodeError("No valid JSON found in LLM response")


# ── Prompts ──────────────────────────────────────────────────

CLASSIFY_PROMPT = """You are an educational analytics assistant helping a university lecturer understand their students. Classify each student question into one of the provided topics and identify what TYPE of difficulty the student is having.

Known topics: {known_topics}

For each question, respond with a JSON array of objects:
[
  {{
    "id": <question_id>,
    "topic": "<topic from list, or 'Other' if none match>",
    "confidence": <0.0-1.0>,
    "struggle_type": "<conceptual|procedural|clarity|application>"
  }}
]

Struggle type meanings (use these in your reasoning but output the type tag):
- conceptual: student misunderstands a core concept or theory
- procedural: student doesn't know the steps, process, or method
- clarity: student is confused by terminology, wording, or definitions
- application: student understands the concept but can't apply it to a problem

Questions:
{questions_json}
"""

STRUGGLE_TOPICS_PROMPT = """You are an educational analytics assistant helping a university lecturer understand where their students need help.

Below are topics that students are struggling with, with student counts and question counts. For each topic, write a recommendation that a busy lecturer could actually USE this week.

IMPORTANT RULES FOR RECOMMENDATIONS:
- Write as if explaining to a colleague, not an analytics system
- Every recommendation MUST include specific numbers (how many students, what percentage)
- Recommend concrete actions: "Dedicate 20 minutes to a live demo" not "Consider reviewing"
- Use plain language: say "students are confused about X" not "struggle_score of 78"
- Format: [What's wrong + who's affected] → [What to do about it]

For each topic, respond with a JSON object:
{{
  "topics": [
    {{
      "topic": "<topic name>",
      "question_count": <number>,
      "struggle_score": <0-100>,
      "recommendation": "<specific actionable recommendation with numbers and concrete actions>"
    }}
  ],
  "summary": "<one-sentence plain-English overview of what the lecturer needs to know>"
}}

Consider:
- More questions = more students hitting the same wall
- Topics affecting many different students = widespread confusion, not isolated cases
- Look for RELATED topics that might share a common root cause

Topics data:
{topics_json}
"""

EXTRACT_TOPICS_PROMPT = """You are an expert academic diagnostician helping a university lecturer understand their students' real struggles. Your job is to identify the topics where students are genuinely confused — not just where they asked questions, but where there are clear patterns of misunderstanding.

CRITICAL COMMUNICATION RULES (read these first!):
1. Write ALL suggestions as if explaining to a busy lecturer over coffee, not writing an analytics report
2. Every suggestion MUST include: WHICH students (count + percentage), WHAT topic, and a SPECIFIC action
3. Never use technical terms like "struggle_score", "severity", "evidence_sources" in the suggestion text
4. Use complete, natural sentences in suggestions
5. Example GOOD suggestion: "12 students (30%) are confused about SSL certificate validation. Dedicate 20 minutes in your next lecture to a live demo of the certificate chain validation process."
6. Example BAD suggestion: "Consider a dedicated recap session on SSL" (too vague, no numbers, no specifics)

COURSE: {course_name}

--- DATA SOURCE 1: COURSE STRUCTURE ---
Course sections / weeks:
{course_sections}

--- DATA SOURCE 2: COURSE MATERIALS ---
{material_context}

--- DATA SOURCE 3: STUDENT QUESTIONS (sample of {q_count} total) ---
{question_list}

--- DATA SOURCE 4: STUDENT ISSUE REPORTS ---
{issue_context}

--- DATA SOURCE 5: STUDENT EVENT SIGNALS ---
{event_context}
- quiz_failure = student failed a quiz/question on this topic
- repeated_views = student rewatched a video/read material repeatedly
- assignment_failure = student failed an assignment

--- DATA SOURCE 6: PREVIOUS ANALYSIS (for continuity) ---
{previous_context}

TASK:
1. Identify 5-15 meaningful TOPICS / CONCEPTS / SUBJECT AREAS that students are genuinely struggling with.
2. Topics MUST be specific to the course domain (e.g. "SSL Certificate Validation", not "practice").
3. CRITICAL: IGNORE generic academic verbs (explain, define, describe, list, discuss, give, practice, summarize, outline, identify, mention, show, write, create).
4. IGNORE greetings, conversational words, single-word fragments.
5. Cross-reference ALL data sources to validate that each topic represents a real struggle, not just one-off questions.
6. For each topic, determine SEVERITY based on: number of affected students, question volume, event signals, trend momentum.
7. Assign a STRUGGLE SCORE (0-100) based on: question volume (weight 30%), student breadth (weight 25%), event signals (weight 25%), trend direction (weight 20%).
8. Provide actionable SUGGESTIONS for each topic that a lecturer could actually do (recap session, practice quiz, one-on-one tutoring, review material, etc.).

OUTPUT STRICT JSON (no markdown, no code fences):
{{
  "topics": [
    {{
      "topic_name": "SSL Certificate Validation (3-6 words max)",
      "struggle_score": 78,
      "severity": "critical",
      "trend": "up",
      "student_count": 12,
      "question_count": 35,
      "evidence_sources": {{
        "chat_questions": 28,
        "quiz_failures": 5,
        "repeated_views": 8,
        "assignment_failures": 2,
        "issue_reports": 3
      }},
      "sample_questions": [
        "Why does my SSL certificate keep failing?",
        "How do I get a valid certificate?"
      ],
      "related_materials": ["Week3_Payment_Systems.pdf"],
      "related_sections": ["Week 3: Payment Security"],
      "affected_student_ids": [101, 102, 105, 110, 115],
      "suggestion": "12 students (30%) are struggling with SSL certificate validation — questions jumped 25% this week. Dedicate 20 minutes in your next lecture to a hands-on demo of the certificate chain validation process.",
      "suggestion_type": "recap"
    }}
  ],
  "confidence": 0.92,
  "summary_insight": "12 of 40 students (30%) are struggling most with SSL certificate validation — questions on this topic jumped 25% this week and quiz scores are dropping. I recommend a hands-on workshop covering certificate chain validation in your next lecture."
}}
"""

STUDENT_RISK_PROMPT = """You are a supportive educational advisor helping a university lecturer understand individual students who may need help.

For each student, write a ONE-SENTENCE plain-English narrative that explains their situation. Then provide a specific recommendation for the lecturer.

Rules for narratives:
- Write like a colleague describing a student to their lecturer
- Include specific details the lecturer would recognize
- Use natural language: "Kofi hasn't logged in for 12 days" not "risk_factor: low_engagement"
- End the narrative with the implications for learning

Rules for recommendations:
- Be specific about what the lecturer can do
- Include the student's topic struggles if known
- Suggest 1-1 meeting, encouragement message, or practice materials as appropriate

For each student, respond with a JSON array:
[
  {{
    "user_id": <id>,
    "question_count": <number>,
    "risk_score": <0-100>,
    "narrative": "<ONE sentence in plain English. Example: 'Kofi hasn't logged in for 12 days and failed 2 quizzes on payment security — he risks falling significantly behind.'>",
    "recommendation": "<specific, doable action for the lecturer. Example: 'Send Kofi a quick check-in message and offer a 1-1 review session on payment security topics.'>"
  }}
]

Consider in your analysis:
- Higher question counts = struggling, may need targeted help
- Multiple struggle topics = spreading too thin or missing prerequisites
- Increasing trend = getting worse, needs intervention now
- Low engagement + high questions = frustrated, needs encouragement
- Low engagement + low questions = disengaged/disconnected, needs outreach

Students data:
{students_json}
"""


# ── Endpoints ────────────────────────────────────────────────

@router.post("/api/v1/analytics/update", response_model=AnalyticsUpdateResponse)
async def analytics_update(
    request: AnalyticsUpdateRequest,
    db: Session = Depends(get_db),
    _ = Depends(verify_token),
):
    """Receive student activity events from Moodle event observers."""
    try:
        upsert_student_context(
            db=db,
            user_id=request.user_id,
            course_id=request.course_id,
            profile=request.profile,
            event_type=request.event_type,
        )
        logger.info(
            f"Analytics update: user={request.user_id} course={request.course_id} "
            f"event={request.event_type}"
        )
        return AnalyticsUpdateResponse(
            success=True,
            message=f"Profile updated for user {request.user_id} in course {request.course_id}",
        )
    except Exception as e:
        logger.error(f"Analytics update error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/api/v1/analytics/snapshot", response_model=SnapshotResponse)
async def ingest_snapshot(
    request: SnapshotRequest,
    db: Session = Depends(get_db),
    _ = Depends(verify_token),
):
    """Receive hourly aggregated student metrics from Moodle cron."""
    try:
        for s in request.students:
            record = StudentSnapshot(
                course_id=request.course_id,
                user_id=s.userid,
                snapshot_data=s.model_dump_json(),
                created_at=datetime.utcnow(),
            )
            db.add(record)
        db.commit()
        return SnapshotResponse(status="received", count=len(request.students))
    except Exception as e:
        db.rollback()
        logger.error(f"Snapshot ingestion error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


NLQ_PROMPT = """You are an expert academic advisor helping a lecturer understand their students. Answer the lecturer's question in plain, helpful language.

Query: {query}

Evidence from course materials and chat logs:
{evidence}

Course context:
{course_context}

Output STRICT JSON only:
{{
  "students": [
    {{
      "student_id": <int>,
      "name": "<full name>",
      "risk_level": "<high|medium|low>",
      "root_cause": "<2-3 sentence plain-English explanation of what this student is struggling with and why — include specific topics and numbers>",
      "evidence_citations": ["<source>"],
      "ai_draft_message": "<2-3 sentence encouraging message the lecturer can send. Be specific about the student's situation and offer concrete help.>"
    }}
  ],
  "global_insight": "<one-sentence plain-English summary of the overall class pattern. Include numbers.>"
}}

Rules:
- Write root_cause as if telling a colleague: "Kofi has asked 5 questions about SSL certificates this week and failed 2 quizzes on payment security."
- NOT: "Student demonstrates elevated struggle_score in domain SSL Certificate Validation."
- If the query is too vague, return the top 3 most common struggle topics instead of individual students.
- If no evidence is found, set evidence_citations to [] and explain in root_cause using quantitative metrics (question counts, quiz scores, login data).
- Keep ai_draft_message supportive and SPECIFIC — mention their name, the topic they struggle with, and a specific thing the lecturer can offer.
- Maximum 10 students in the response.
"""


@router.post("/api/v1/analytics/natural-language-query", response_model=NLQResponse)
async def natural_language_query(
    request: NLQRequest,
    db: Session = Depends(get_db),
    _ = Depends(verify_token),
):
    """Answer a natural language query about student struggles using RAG and semantic caching."""
    try:
        query_hash = hashlib.sha256(request.query.encode()).hexdigest()

        # 1. Semantic cache check
        cached = db.query(AnalyticsCache).filter(
            AnalyticsCache.query_hash == query_hash,
            AnalyticsCache.created_at > datetime(2020, 1, 1),
        ).order_by(AnalyticsCache.created_at.desc()).first()

        if cached:
            client = get_chroma_client()
            try:
                cache_collection = client.get_collection("analytics_cache")
                query_embedding = embed_texts([request.query])[0]
                hits = cache_collection.query(
                    query_embeddings=[query_embedding],
                    n_results=1,
                )
                if hits["distances"] and len(hits["distances"][0]) > 0:
                    distance = hits["distances"][0][0]
                    if distance < 0.12:
                        logger.info(f"NLQ cache hit (dist={distance:.4f}): {request.query[:60]}")
                        return NLQResponse(**json.loads(cached.response_json))
            except Exception:
                pass

        # 2. Retrieve evidence from ChromaDB course collections
        vector_store = get_chroma_client()
        evidence_chunks = []
        for coll_name in [f"course_{request.course_id}", f"course_{request.course_id}_openai"]:
            try:
                coll = vector_store.get_collection(name=coll_name)
                query_embedding = embed_texts([request.query])[0]
                results = coll.query(
                    query_embeddings=[query_embedding],
                    n_results=8,
                    include=["documents", "metadatas", "distances"],
                )
                if results["documents"]:
                    for doc, meta in zip(results["documents"][0], results["metadatas"][0]):
                        source = meta.get("source", "unknown") if meta else "unknown"
                        evidence_chunks.append(f"[{source}] {doc[:500]}")
            except Exception:
                pass

        evidence_text = "\n\n".join(evidence_chunks[:10]) if evidence_chunks else "No specific evidence found for this query."

        # 3. Get course context from latest snapshots
        recent_snapshots = db.query(StudentSnapshot).filter(
            StudentSnapshot.course_id == request.course_id,
        ).order_by(StudentSnapshot.created_at.desc()).limit(50).all()

        student_context_list = []
        for snap in recent_snapshots:
            data = json.loads(snap.snapshot_data)
            student_context_list.append(f"Student {data.get('userid')}: risk={data.get('risk_score')}, "
                                        f"logins={data.get('logins')}, quiz_avg={data.get('avg_quiz_grade')}, "
                                        f"ai_questions={data.get('ai_questions_asked')}")
        course_context = "\n".join(student_context_list[:20]) if student_context_list else "No recent snapshot data."

        # 4. LLM synthesis
        prompt = NLQ_PROMPT.format(
            query=request.query,
            evidence=evidence_text,
            course_context=course_context,
        )
        llm = get_llm()
        result = llm._invoke(prompt, temperature=0.2, max_chars=4096)
        parsed = _parse_llm_json(result)

        # 5. Validate evidence_citations not empty; fallback to quantitative
        for s in parsed.get("students", []):
            if not s.get("evidence_citations"):
                s["evidence_citations"] = []

        response = NLQResponse(**parsed)

        # 6. Cache the response
        try:
            cache_entry = AnalyticsCache(
                query_hash=query_hash,
                query_text=request.query,
                response_json=response.model_dump_json(),
                created_at=datetime.utcnow(),
            )
            db.add(cache_entry)

            # Also cache in ChromaDB for semantic lookup
            cache_collection = get_chroma_client().get_or_create_collection(
                name="analytics_cache",
                metadata={"hnsw:space": "cosine"},
            )
            cache_embedding = embed_texts([request.query])[0]
            cache_collection.add(
                documents=[response.model_dump_json()],
                embeddings=[cache_embedding],
                metadatas=[{"query": request.query[:200], "course_id": request.course_id}],
                ids=[f"nlq_{query_hash[:16]}"],
            )
            db.commit()
        except Exception as e:
            db.rollback()
            logger.warning(f"NLQ cache write failed: {e}")

        return response

    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON for NLQ: {result}")
        raise HTTPException(status_code=500, detail="LLM returned invalid JSON")
    except Exception as e:
        logger.error(f"NLQ error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/api/v1/analytics/classify-questions", response_model=ClassifyResponse)
async def classify_questions(
    request: ClassifyRequest,
    _ = Depends(verify_token),
):
    try:
        questions_json = json.dumps([q.model_dump() for q in request.questions])
        known_topics = ", ".join(request.known_topics) if request.known_topics else "General course topics"
        prompt = CLASSIFY_PROMPT.format(
            known_topics=known_topics,
            questions_json=questions_json,
        )
        result = _call_llm(prompt, max_chars=4096)
        classifications = _parse_llm_json(result)
        return ClassifyResponse(
            classifications=[
                ClassificationResult(**c) for c in classifications
            ],
            llm_used=settings.llm_provider,
        )
    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON: {result}")
        raise HTTPException(status_code=500, detail="LLM returned invalid JSON")
    except Exception as e:
        logger.error(f"Classification error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/api/v1/analytics/struggle-topics", response_model=StruggleTopicsResponse)
async def struggle_topics(
    topics_data: dict,
    _ = Depends(verify_token),
):
    try:
        topics_json = json.dumps(topics_data.get("topics", []))
        prompt = STRUGGLE_TOPICS_PROMPT.format(topics_json=topics_json)
        result = _call_llm(prompt, max_chars=2048)
        parsed = _parse_llm_json(result)
        # LLM occasionally returns the topic array directly instead of the
        # {"topics": [...], "summary": ...} envelope — normalize both shapes.
        if isinstance(parsed, list):
            parsed = {"topics": parsed, "summary": ""}
        topics = parsed.get("topics", [])
        if not isinstance(topics, list):
            topics = [topics] if isinstance(topics, dict) else []
        return StruggleTopicsResponse(
            topics=[StruggleTopic(**t) for t in topics],
            summary=parsed.get("summary", ""),
        )
    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON: {result}")
        raise HTTPException(status_code=500, detail="LLM returned invalid JSON")
    except Exception as e:
        logger.error(f"Struggle topics error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


COMPILE_TOPICS_PROMPT = """You are an expert academic diagnostician. Your task is to COMPILE and MERGE multiple topic analyses from different batches of student data into a single, coherent, deduplicated topic list.

Below are {batch_count} separate topic analyses produced from different subsets/batches of the same course data. Each batch extracted topics from different student questions, issues, and events.

BATCH ANALYSES:
{batch_analyses}

TASK:
1. Merge topics that refer to the SAME underlying concept across batches (e.g. "SSL Certificate Problems" and "Certificate Validation Issues" → "SSL Certificate Validation")
2. For merged topics, COMBINE and SUM: question counts, student counts, evidence source numbers
3. Keep the HIGHEST struggle_score for merged topics
4. Preserve the most specific topic_name
5. Preserve ALL unique sample_questions, related_materials, related_sections, and affected_student_ids from ALL batches
6. Re-rank final topics by their combined struggle_score
7. Choose the most severe severity label across merged batches
8. Choose the most relevant suggestion from merged batches
9. Provide a concise summary_insight that captures the overall picture in plain language

IMPORTANT for summary_insight:
- Write in plain English, as if telling a busy lecturer what's going on
- Include specific numbers: how many students, which topics
- Keep it to ONE sentence
- Example: "12 of 40 students are struggling with SSL certificate validation and API integration — questions jumped 25% this week."

OUTPUT STRICT JSON (no markdown, no code fences) — same format as individual extraction:
{{
  "topics": [
    {{
      "topic_name": "Merged Topic Name",
      "struggle_score": 85,
      "severity": "critical",
      "trend": "up",
      "student_count": 25,
      "question_count": 72,
      "evidence_sources": {{
        "chat_questions": 60,
        "quiz_failures": 8,
        "repeated_views": 12,
        "assignment_failures": 3,
        "issue_reports": 5
      }},
      "sample_questions": ["Best question from batch 1", "Best question from batch 2"],
      "related_materials": ["All unique materials across batches"],
      "related_sections": ["All unique sections across batches"],
      "affected_student_ids": [101, 102, 105, 110, 115, 120],
      "suggestion": "Best suggestion from merged batches (include numbers + specific action)",
      "suggestion_type": "recap"
    }}
  ],
  "confidence": 0.90,
  "summary_insight": "Concise merged analysis in plain English — with numbers, for a busy lecturer"
}}
"""

@router.post("/api/v1/analytics/extract-topics", response_model=ExtractTopicsResponse)
async def extract_course_topics(
    request: ExtractTopicsRequest,
    db: Session = Depends(get_db),
    _ = Depends(verify_token),
):
    """Extract meaningful struggle topics from ALL student data sources using LLM + memory cache.
    Handles large datasets by processing in batches and compiling intelligently."""
    try:
        if not request.questions and not request.issue_reports and not request.student_events:
            raise HTTPException(status_code=400, detail="No student data provided")

        # ── Memory cache: check for existing analysis for this course ──
        cache_key = f"struggle_topics_{request.course_id}"
        previous_topics_raw = []

        cached = db.query(AnalyticsCache).filter(
            AnalyticsCache.query_hash == cache_key,
        ).order_by(AnalyticsCache.created_at.desc()).first()

        if cached:
            try:
                prev = json.loads(cached.response_json)
                previous_topics_raw = prev.get("topics", [])
            except Exception:
                pass

        # Merge user-supplied previous topics with cached ones
        all_previous = request.previous_topics or []
        if previous_topics_raw:
            existing_names = {t.get("topic_name", "").lower() for t in all_previous}
            for pt in previous_topics_raw:
                if pt.get("topic_name", "").lower() not in existing_names:
                    all_previous.append(pt)

        def validate_topic(topic: dict) -> bool:
            """Validate a single extracted topic."""
            name = topic.get("topic_name", "").strip()
            if not name:
                return False
            words = name.lower().split()
            if len(words) < 2:
                return False
            generic_verbs = {
                'explain', 'define', 'describe', 'list', 'discuss',
                'give', 'practice', 'summarize', 'outline', 'identify',
                'state', 'mention', 'tell', 'show', 'write', 'create',
                'referenc', 'common',
            }
            if any(w in generic_verbs for w in words):
                return False
            return True

        def parse_topic(topic: dict) -> dict:
            """Parse a validated topic dict into a structured ExtractTopicsTopic."""
            ev_src = topic.get("evidence_sources", {})
            return {
                "topic_name": topic.get("topic_name", "").strip(),
                "struggle_score": min(100, max(0, float(topic.get("struggle_score", 50)))),
                "severity": topic.get("severity", "watch"),
                "trend": topic.get("trend", "stable"),
                "student_count": int(topic.get("student_count", 0)),
                "question_count": int(topic.get("question_count", 0)),
                "evidence_sources": {
                    "chat_questions": int(ev_src.get("chat_questions", 0)),
                    "quiz_failures": int(ev_src.get("quiz_failures", 0)),
                    "repeated_views": int(ev_src.get("repeated_views", 0)),
                    "assignment_failures": int(ev_src.get("assignment_failures", 0)),
                    "issue_reports": int(ev_src.get("issue_reports", 0)),
                },
                "sample_questions": topic.get("sample_questions", [])[:3],
                "related_materials": topic.get("related_materials", []),
                "related_sections": topic.get("related_sections", []),
                "affected_student_ids": [int(s) for s in (topic.get("affected_student_ids", []) or []) if s],
                "suggestion": topic.get("suggestion", ""),
                "suggestion_type": topic.get("suggestion_type", "recap"),
            }

        # ── Determine if batching is needed ──
        total_questions = len(request.questions)
        BATCH_THRESHOLD = 100  # Max questions per batch
        needs_batching = total_questions > BATCH_THRESHOLD or (total_questions + len(request.issue_reports or []) * 2 + len(request.student_events or []) * 2) > 300

        if not needs_batching:
            # ── Single-pass processing (small dataset) ──
            batch_results = [await _run_extraction_batch(request, request.questions, all_previous)]
            # Use the first (and only) batch result directly
            parsed = batch_results[0]
            validated = []
            for topic in parsed.get("topics", []):
                if validate_topic(topic):
                    validated.append(ExtractTopicsTopic(**parse_topic(topic)))

            response = ExtractTopicsResponse(
                topics=validated[:20],
                confidence=float(parsed.get("confidence", 0.0)),
                summary_insight=parsed.get("summary_insight", ""),
                total_questions_analyzed=total_questions,
                from_cache=False,
            )

        else:
            # ── Batch processing (large dataset) ──
            logger.info(f"Batch processing {total_questions} questions across "
                        f"{len(request.issue_reports or [])} issues and {len(request.student_events or [])} events")

            # Split questions into batches
            all_questions = request.questions
            batches = [all_questions[i:i + BATCH_THRESHOLD] for i in range(0, len(all_questions), BATCH_THRESHOLD)]

            # Distribute issues and events across batches proportionally
            issue_batches = [[] for _ in batches]
            event_batches = [[] for _ in batches]
            if request.issue_reports:
                for i, ir in enumerate(request.issue_reports):
                    issue_batches[i % len(batches)].append(ir)
            if request.student_events:
                for i, ev in enumerate(request.student_events):
                    event_batches[i % len(batches)].append(ev)

            # Process each batch
            all_batch_topics = []
            batch_summaries = []
            for i, (batch_qs, batch_issues, batch_events) in enumerate(zip(batches, issue_batches, event_batches)):
                logger.info(f"Processing batch {i+1}/{len(batches)} ({len(batch_qs)} questions)")

                # Create a sub-request for this batch
                batch_request = ExtractTopicsRequest(
                    course_id=request.course_id,
                    course_name=request.course_name,
                    questions=batch_qs,
                    course_materials=request.course_materials,
                    course_sections=request.course_sections,
                    issue_reports=batch_issues,
                    student_events=batch_events,
                    previous_topics=all_previous if i == 0 else all_batch_topics,
                )

                # Call the shared extraction logic
                batch_result = await _run_extraction_batch(batch_request, batch_qs, all_previous if i == 0 else all_batch_topics)

                if batch_result and batch_result.get("topics"):
                    for t in batch_result["topics"]:
                        if validate_topic(t):
                            all_batch_topics.append(parse_topic(t))
                    if batch_result.get("summary_insight"):
                        batch_summaries.append(batch_result["summary_insight"])

            # ── Compilation step: merge all batch topics into one coherent list ──
            if len(all_batch_topics) <= 15:
                # Few topics, skip compilation, just sort and return
                all_batch_topics.sort(key=lambda t: t["struggle_score"], reverse=True)
                validated = [ExtractTopicsTopic(**t) for t in all_batch_topics[:20]]
                combined_insight = " | ".join(filter(None, batch_summaries)) or "Cross-course analysis completed."
                response = ExtractTopicsResponse(
                    topics=validated,
                    confidence=0.85,
                    summary_insight=combined_insight,
                    total_questions_analyzed=total_questions,
                    from_cache=False,
                )
            else:
                # Use LLM to intelligently compile batches
                logger.info(f"Compiling {len(all_batch_topics)} topics from {len(batches)} batches")

                # Format batch analyses for the compilation prompt
                batch_analyses_text = ""
                for bi, batch_topics in enumerate([all_batch_topics]):
                    for j, t in enumerate(batch_topics):
                        batch_analyses_text += f"\nBatch Topic {j+1}: {t['topic_name']} (score={t['struggle_score']}, severity={t['severity']}, students={t['student_count']}, questions={t['question_count']})"

                compile_prompt = COMPILE_TOPICS_PROMPT.format(
                    batch_count=len(batches),
                    batch_analyses=batch_analyses_text,
                )

                llm = get_llm()
                compile_result = llm._invoke(compile_prompt, temperature=0.2, max_chars=8192)
                compiled = _parse_llm_json(compile_result)

                compiled_topics = []
                for t in compiled.get("topics", []):
                    if validate_topic(t):
                        compiled_topics.append(ExtractTopicsTopic(**parse_topic(t)))

                # If compilation succeeded, use it; otherwise use raw merged list
                if compiled_topics:
                    validated = compiled_topics[:20]
                else:
                    all_batch_topics.sort(key=lambda t: t["struggle_score"], reverse=True)
                    validated = [ExtractTopicsTopic(**t) for t in all_batch_topics[:20]]

                response = ExtractTopicsResponse(
                    topics=validated,
                    confidence=float(compiled.get("confidence", 0.8)),
                    summary_insight=compiled.get("summary_insight", " | ".join(filter(None, batch_summaries)) or "Batch analysis compiled."),
                    total_questions_analyzed=total_questions,
                    from_cache=False,
                )

        # ── Save to memory cache for future continuity ──
        try:
            existing = db.query(AnalyticsCache).filter(
                AnalyticsCache.query_hash == cache_key,
            ).order_by(AnalyticsCache.created_at.desc()).first()
            if existing:
                existing.response_json = response.model_dump_json()
                existing.created_at = datetime.utcnow()
            else:
                db.add(AnalyticsCache(
                    query_hash=cache_key,
                    query_text=f"struggle_topics_{request.course_id}",
                    response_json=response.model_dump_json(),
                    created_at=datetime.utcnow(),
                ))
            db.commit()
        except Exception as e:
            logger.warning(f"Failed to cache struggle topics: {e}")
            db.rollback()

        return response

    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON for extract-topics: {result}")
        raise HTTPException(status_code=500, detail="LLM returned invalid JSON")
    except Exception as e:
        logger.error(f"Topic extraction error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


async def _run_extraction_batch(request: ExtractTopicsRequest, batch_questions: list, previous_topics: list) -> dict:
    """Shared extraction logic for a single batch of data.
    Returns parsed dict with 'topics' and optional 'summary_insight'."""
    try:
        # Build context from all data sources
        material_context_lines = []
        for m in (request.course_materials or [])[:30]:
            fname = m.get("filename", "")
            concepts = m.get("key_concepts", [])
            if concepts:
                material_context_lines.append(f"- {fname} (concepts: {', '.join(concepts[:5])})")
            else:
                material_context_lines.append(f"- {fname}")
        material_context = "\n".join(material_context_lines) or "No course materials available."

        question_list = "\n".join(
            [f"{i+1}. {q}" for i, q in enumerate(batch_questions[:150])]
        ) if batch_questions else "No student questions available."

        issue_lines = []
        for ir in (request.issue_reports or [])[:30]:
            issue_lines.append(f"- Topic: {ir.get('topic', 'unspecified')} | Category: {ir.get('category', '')} | Description: {ir.get('description', '')[:120]}")
        issue_context = "\n".join(issue_lines) or "No issue reports."

        event_lines = []
        for ev in (request.student_events or [])[:40]:
            event_lines.append(f"- Student {ev.get('userid')}: {ev.get('reason', 'event')} on {ev.get('topic_label', 'unknown topic')}")
        event_context = "\n".join(event_lines) or "No event signals."

        section_lines = []
        for sec in (request.course_sections or [])[:20]:
            section_lines.append(f"- {sec.get('name', 'Week ' + str(sec.get('section', 0)))}: {sec.get('summary', '')[:100]}")
        course_sections_text = "\n".join(section_lines) or "No course sections."

        previous_context_lines = []
        for pt in (previous_topics or [])[:10]:
            if isinstance(pt, dict):
                prev_topic = pt.get("topic_name", pt.get("topic", ""))
                prev_score = pt.get("struggle_score", "N/A")
                previous_context_lines.append(f"- {prev_topic} (previous score: {prev_score})")
        previous_context = "\n".join(previous_context_lines) or "No previous analysis available."

        prompt = EXTRACT_TOPICS_PROMPT.format(
            course_name=request.course_name or "General Course",
            course_sections=course_sections_text,
            material_context=material_context,
            q_count=len(batch_questions),
            question_list=question_list,
            issue_context=issue_context,
            event_context=event_context,
            previous_context=previous_context,
        )

        llm = get_llm()
        result = llm._invoke(prompt, temperature=0.3, max_chars=8192)
        parsed = _parse_llm_json(result)

        return {
            "topics": parsed.get("topics", []),
            "summary_insight": parsed.get("summary_insight", ""),
            "confidence": parsed.get("confidence", 0.0),
        }

    except Exception as e:
        logger.error(f"Extraction batch error: {e}")
        return {"topics": [], "summary_insight": "", "confidence": 0.0}


@router.post("/api/v1/analytics/student-risk", response_model=StudentRiskResponse)
async def student_risk(
    students_data: dict,
    _ = Depends(verify_token),
):
    try:
        students_json = json.dumps(students_data.get("students", []))
        prompt = STUDENT_RISK_PROMPT.format(students_json=students_json)
        result = _call_llm(prompt, max_chars=4096)
        parsed = _parse_llm_json(result)
        return StudentRiskResponse(
            students=[StudentRiskItem(**s) for s in parsed],
        )
    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON: {result}")
        raise HTTPException(status_code=500, detail="LLM returned invalid JSON")
    except Exception as e:
        logger.error(f"Student risk error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


# ── Dashboard ────────────────────────────────────────────────

DASHBOARD_PROMPT = """You are an educational advisor producing a quick dashboard snapshot for a university lecturer.

Course data:
{course_data_json}

Respond with a JSON object:
{{
  "top_topic_insight": "<one-sentence plain-English insight about the most difficult topic. Include how many students and what to do. Example: '12 of 40 students are struggling with SSL certificate validation — consider a recap session this week.'>",
  "recommendations": ["<recommendation 1 — include specific numbers>", "<recommendation 2>"],
  "impact_summary": "<one paragraph plain-English summary of course health. Lead with the most important number.>"
}}

Keep it concise and immediately useful. No jargon. Every claim needs a number.
"""


@router.post("/api/v1/analytics/dashboard", response_model=DashboardResponse)
async def analytics_dashboard(
    request: DashboardRequest,
    db: Session = Depends(get_db),
    _ = Depends(verify_token),
):
    """Generate a dashboard summary with KPIs, LLM-powered insight, and recommendations."""
    try:
        latest_ts_row = db.query(StudentSnapshot.created_at).filter(
            StudentSnapshot.course_id == request.course_id,
        ).order_by(StudentSnapshot.created_at.desc()).first()

        if not latest_ts_row:
            return DashboardResponse(
                kpis=DashboardKPIs(
                    engagement_score=0.0,
                    at_risk_count=0,
                    total_students=0,
                    top_topic_insight="No data available for this course yet.",
                ),
                recommendations=["Ingest student snapshots to enable analytics."],
                impact_summary="No course data has been collected yet.",
            )

        latest_ts = latest_ts_row[0]
        latest_snapshots = db.query(StudentSnapshot).filter(
            StudentSnapshot.course_id == request.course_id,
            StudentSnapshot.created_at == latest_ts,
        ).all()

        total_students = len(latest_snapshots)
        at_risk_count = 0
        student_data_list = []

        for snap in latest_snapshots:
            data = json.loads(snap.snapshot_data)
            risk_score = float(data.get("risk_score", 0))
            if risk_score >= 60:
                at_risk_count += 1
            student_data_list.append(data)

        avg_risk = (
            sum(float(s.get("risk_score", 0)) for s in student_data_list) / total_students
            if total_students
            else 0
        )
        engagement_score = round(100.0 - avg_risk, 1)

        course_context = {
            "course_id": request.course_id,
            "time_window_days": request.time_window_days,
            "total_students": total_students,
            "at_risk_count": at_risk_count,
            "engagement_score": engagement_score,
            "students_sample": student_data_list[:20],
        }

        prompt = DASHBOARD_PROMPT.format(course_data_json=json.dumps(course_context, indent=2))
        result = _call_llm(prompt, max_chars=2048)
        parsed = _parse_llm_json(result)

        return DashboardResponse(
            kpis=DashboardKPIs(
                engagement_score=engagement_score,
                at_risk_count=at_risk_count,
                total_students=total_students,
                top_topic_insight=parsed.get("top_topic_insight", "No insight available."),
            ),
            recommendations=parsed.get("recommendations", []),
            impact_summary=parsed.get("impact_summary", ""),
        )

    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON for dashboard: {result}")
        raise HTTPException(status_code=500, detail="LLM returned invalid JSON")
    except Exception as e:
        logger.error(f"Dashboard error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


# ── Course Health Report ─────────────────────────────────────

COURSE_HEALTH_PROMPT = """You are a senior educational advisor creating a course health briefing for a university lecturer. Your job is to give them a clear, honest, and immediately useful picture of their course.

CRITICAL RULES:
- Write like a helpful colleague, not a report generator
- Every claim MUST include SPECIFIC NUMBERS (counts, percentages, trends)
- Never use analytics jargon: say "students are confused" not "struggle_score is 78"
- Be honest but constructive — if things are going well, say so first
- If things need attention, say what, why, and how many students are affected

Course data:
{course_data_json}

Respond with a JSON object:
{{
  "overall_health": "<healthy|moderate|struggling>",
  "health_grade": "<A|B|C|D|F> based on at-risk percentage: A(<10%%), B(10-20%%), C(20-35%%), D(35-50%%), F(>50%%)>",
  "health_label": "<Excellent|Good|Needs Attention|Concerning|Critical>",
  "executive_summary": "<2-3 sentence briefing. FIRST sentence: the single most important metric or trend with a specific number. SECOND sentence: what this means for learning. THIRD sentence: the one action the lecturer should take this week. Example: '12 of 40 students (30%%) are struggling with SSL certificate validation — questions jumped 25%% this week. This means 1 in 3 students may not be ready for the payment systems exam. I recommend a 20-minute hands-on demo in your next lecture.'>",
  "going_well": ["0-2 specific positive things with numbers. Example: 'Question activity is up 18%% this week — students are engaged.' If nothing positive, include 'No significant positive signals yet — early in the term.'"],
  "needs_attention": ["2-3 specific concerns with numbers and student counts. Example: '3 students haven't logged in for 7+ days — they may need a personal check-in.'"],
  "top_recommendation": "<SINGLE most important action for this week. Be very specific: include the topic, the action, and the format. Example: 'Dedicate 20 minutes in Thursday's lecture to a live demo of SSL certificate chain validation.'>",
  "student_risk_summary": "<one-sentence summary of at-risk student patterns with counts. Example: '5 students are at risk, primarily due to struggles with SSL (3 students) and API integration (2 students).'>",
  "event_pattern_insight": "<if events exist, what the pattern suggests (e.g. 'Quiz failure events are concentrated in Week 3 materials — students may need practice questions before the exam'); otherwise empty string>",
  "section_insights": ["For EACH course section with data, one sentence with numbers. Example: 'Week 3 (Payment Security): 8 students struggling, 35 questions asked — highest concern section.' Only include sections that have actual student activity."]
}}

IMPORTANT:
- executive_summary is the FIRST thing the lecturer reads — it MUST be 2-3 sentences max with specific numbers
- health_grade: A means most students are doing fine, F means urgent intervention needed
- going_well should have at least 1 item if there's ANY positive signal (it's important for morale)
- needs_attention should be concrete with student counts, not generic
- top_recommendation must be a DOABLE action the lecturer can take THIS WEEK
- section_insights helps the lecturer know which part of their course needs focus
"""


class CourseHealthResponse(BaseModel):
    overall_health: str
    health_grade: Optional[str] = ""
    health_label: Optional[str] = ""
    executive_summary: Optional[str] = ""
    summary: Optional[str] = ""
    key_findings: Optional[List[str]] = []
    going_well: Optional[List[str]] = []
    needs_attention: Optional[List[str]] = []
    top_recommendation: Optional[str] = ""
    worst_topic_analysis: Optional[str] = ""
    student_risk_summary: Optional[str] = ""
    recommendations: Optional[List[str]] = []
    event_pattern_insight: Optional[str] = ""
    section_insights: Optional[List[str]] = []


@router.post("/api/v1/analytics/course-health", response_model=CourseHealthResponse)
async def course_health(
    health_data: dict,
    _ = Depends(verify_token),
):
    try:
        course_data_json = json.dumps(health_data, indent=2)
        prompt = COURSE_HEALTH_PROMPT.format(course_data_json=course_data_json)
        result = _call_llm(prompt, max_chars=4096)
        parsed = _parse_llm_json(result)
        return CourseHealthResponse(**parsed)
    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON: {result}")
        raise HTTPException(status_code=500, detail="LLM returned invalid JSON")
    except Exception as e:
        logger.error(f"Course health error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


# ── Student Personal Progress Recommendation ────────────────

STUDENT_PROGRESS_PROMPT = """You are a supportive educational coach. A student has shared their learning progress data. Provide a brief, encouraging, personalized recommendation to help them improve.

Student data:
{student_data_json}

Respond with a JSON object:
{{
  "recommendation": "<2-3 sentence personalized message. Be specific about their courses/topics of struggle. Encourage specific actions like reviewing materials, asking more questions on weak topics, or resolving open issues. Keep tone supportive and constructive.>"
}}

Focus on:
- Their struggle topics and scores — suggest which topics need attention
- Their question activity — encourage consistency if low, praise if high
- Their open issues — suggest following up if any are unresolved
- Overall patterns — identify if they're doing well or need a study plan
"""


class StudentProgressResponse(BaseModel):
    recommendation: str


# ── Transcription Cost Aggregation ─────────────────────────

from models.schemas import TranscriptionCostRequest, TranscriptionCostResponse, PerCourseCost, MonthlyCost, ProviderCostSummary
from models.database import ProcessingJob
from sqlalchemy import func as sa_func, extract


@router.post("/api/v1/analytics/transcription-costs", response_model=TranscriptionCostResponse)
async def get_transcription_costs(
    req: TranscriptionCostRequest,
    db: Session = Depends(get_db),
    _: str = Depends(verify_token),
):
    """Aggregate transcription costs per course and month from processing_jobs."""
    query = db.query(ProcessingJob).filter(
        ProcessingJob.status == "completed",
        ProcessingJob.transcription_cost.isnot(None),
        ProcessingJob.transcription_cost > 0,
    )

    if req.course_id > 0:
        query = query.filter(ProcessingJob.course_id == req.course_id)

    jobs = query.all()

    if not jobs:
        return TranscriptionCostResponse()

    # Per-course aggregation
    course_map = {}
    for j in jobs:
        cid = j.course_id
        if cid not in course_map:
            course_map[cid] = {
                "course_id": cid,
                "total_cost": 0.0,
                "total_duration_secs": 0.0,
                "recording_count": 0,
                "transcribed_count": 0,
                "provider_breakdown": {},
            }
        cm = course_map[cid]
        cm["total_cost"] += j.transcription_cost or 0.0
        cm["total_duration_secs"] += j.audio_duration_secs or 0.0
        cm["recording_count"] += 1
        if j.transcription_provider:
            cm["transcribed_count"] += 1
            prov = j.transcription_provider
            cm["provider_breakdown"][prov] = cm["provider_breakdown"].get(prov, 0) + 1

    # Monthly trend
    month_map = {}
    for j in jobs:
        month_key = j.created_at.strftime("%Y-%m") if j.created_at else "unknown"
        if month_key not in month_map:
            month_map[month_key] = {
                "month": month_key,
                "total_cost": 0.0,
                "total_duration_secs": 0.0,
                "recording_count": 0,
            }
        mm = month_map[month_key]
        mm["total_cost"] += j.transcription_cost or 0.0
        mm["total_duration_secs"] += j.audio_duration_secs or 0.0
        mm["recording_count"] += 1

    # Provider breakdown
    provider_map = {}
    for j in jobs:
        prov = j.transcription_provider or "unknown"
        if prov not in provider_map:
            provider_map[prov] = {
                "provider": prov,
                "recording_count": 0,
                "total_cost": 0.0,
                "total_duration_secs": 0.0,
            }
        pm = provider_map[prov]
        pm["recording_count"] += 1
        pm["total_cost"] += j.transcription_cost or 0.0
        pm["total_duration_secs"] += j.audio_duration_secs or 0.0

    total_cost = sum(j.transcription_cost or 0.0 for j in jobs)
    total_dur = sum(j.audio_duration_secs or 0.0 for j in jobs)

    return TranscriptionCostResponse(
        total_cost          = round(total_cost, 6),
        total_duration_secs = round(total_dur, 2),
        total_recordings    = len(jobs),
        per_course          = [PerCourseCost(**course_map[cid]) for cid in sorted(course_map)],
        monthly_trend       = [MonthlyCost(**month_map[m]) for m in sorted(month_map)],
        provider_breakdown  = [ProviderCostSummary(**provider_map[p]) for p in sorted(provider_map)],
    )


@router.post("/api/v1/analytics/student-progress", response_model=StudentProgressResponse)
async def student_progress(
    progress_data: dict,
    _ = Depends(verify_token),
):
    try:
        student_data_json = json.dumps(progress_data, indent=2)
        prompt = STUDENT_PROGRESS_PROMPT.format(student_data_json=student_data_json)
        result = _call_llm(prompt, max_chars=2048)
        parsed = _parse_llm_json(result)
        return StudentProgressResponse(**parsed)
    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON for student progress: {result}")
        raise HTTPException(status_code=500, detail="LLM returned invalid JSON")
    except Exception as e:
        logger.error(f"Student progress error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


# ── AI Teaching Intelligence Recommendation Engine ────────────

TEACHING_INTELLIGENCE_PROMPT = """You are an expert academic advisor producing prioritized, evidence-based teaching recommendations for a university lecturer.

Given the following course analytics data, produce actionable recommendations ranked by urgency and impact.

Course data:
{course_data_json}

TASK:
1. Analyze ALL data sources (students at risk, topic struggles, quiz analytics, recording analytics, resource analytics, AI learning analytics).
2. Produce 5-10 prioritized recommendations, ordered by urgency (most urgent first).
3. Each recommendation MUST include:
   - A specific, actionable title
   - The EXACT evidence from the data that justifies this recommendation (quote numbers)
   - A confidence score (0-100) based on how strongly the data supports this recommendation
   - A specific suggestion the lecturer can act on immediately
   - An urgency level: critical, high, medium, or low
4. Cross-reference data sources: a topic that appears in struggle areas AND quiz failures AND high AI queries should rank higher than one appearing in only one source.
5. Ignore generic advice. Every recommendation must reference specific students, topics, or metrics.

OUTPUT STRICT JSON (no markdown, no code fences):
{{
  "recommendations": [
    {{
      "priority": 1,
      "type": "<topic_review|student_contact|lecture_split|material_review|quiz_update|engagement_boost>",
      "title": "<specific actionable title>",
      "urgency": "<critical|high|medium|low>",
      "confidence": 92.5,
      "evidence": "<exact numbers and data points that justify this recommendation>",
      "suggestion": "<specific action the lecturer can take>",
      "affected_students": ["<student names if applicable>"],
      "affected_topics": ["<topic names if applicable>"],
      "impact_estimate": "<estimated impact if this recommendation is acted on>"
    }}
  ],
  "global_insight": "<2-3 sentence overall assessment of the course health>",
  "course_health": "<healthy|moderate|struggling>",
  "priority_focus": "<the single most important thing the lecturer should do this week>"
}}

Rules:
- confidence must be between 0 and 100
- urgency must be one of: critical, high, medium, low
- type must be one of: topic_review, student_contact, lecture_split, material_review, quiz_update, engagement_boost
- Each recommendation must reference specific data (numbers, student names, topic names)
- Maximum 10 recommendations
"""


class TeachingIntelligenceRequest(BaseModel):
    course_id: int
    students_at_risk: list = []
    topic_struggles: list = []
    quiz_analytics: dict = {}
    recording_analytics: list = []
    resource_analytics: list = []
    ai_learning_analytics: dict = {}
    common_questions: list = []


class TeachingRecommendation(BaseModel):
    priority: int
    type: str
    title: str
    urgency: str
    confidence: float
    evidence: str
    suggestion: str
    affected_students: List[str] = []
    affected_topics: List[str] = []
    impact_estimate: str = ""


class TeachingIntelligenceResponse(BaseModel):
    recommendations: List[TeachingRecommendation]
    global_insight: str
    course_health: str
    priority_focus: str


@router.post("/api/v1/analytics/teaching-intelligence", response_model=TeachingIntelligenceResponse)
async def teaching_intelligence(
    request: TeachingIntelligenceRequest,
    _ = Depends(verify_token),
):
    """Generate AI-powered prioritized teaching recommendations with evidence and confidence scores."""
    try:
        course_data = {
            "course_id": request.course_id,
            "students_at_risk": request.students_at_risk[:20],
            "topic_struggles": request.topic_struggles[:15],
            "quiz_analytics": request.quiz_analytics,
            "recording_analytics": request.recording_analytics[:10],
            "resource_analytics": request.resource_analytics[:10],
            "ai_learning_analytics": request.ai_learning_analytics,
            "common_questions": request.common_questions[:10],
        }

        prompt = TEACHING_INTELLIGENCE_PROMPT.format(
            course_data_json=json.dumps(course_data, indent=2)
        )
        result = _call_llm(prompt, max_chars=6144)
        parsed = _parse_llm_json(result)

        recommendations = []
        for rec in parsed.get("recommendations", []):
            recommendations.append(TeachingRecommendation(
                priority=int(rec.get("priority", 0)),
                type=rec.get("type", "topic_review"),
                title=rec.get("title", ""),
                urgency=rec.get("urgency", "medium"),
                confidence=min(100, max(0, float(rec.get("confidence", 50)))),
                evidence=rec.get("evidence", ""),
                suggestion=rec.get("suggestion", ""),
                affected_students=rec.get("affected_students", []),
                affected_topics=rec.get("affected_topics", []),
                impact_estimate=rec.get("impact_estimate", ""),
            ))

        recommendations.sort(key=lambda r: r.priority)

        return TeachingIntelligenceResponse(
            recommendations=recommendations,
            global_insight=parsed.get("global_insight", ""),
            course_health=parsed.get("course_health", "moderate"),
            priority_focus=parsed.get("priority_focus", ""),
        )
    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON for teaching intelligence: {result}")
        raise HTTPException(status_code=500, detail="LLM returned invalid JSON")
    except Exception as e:
        logger.error(f"Teaching intelligence error: {e}")
        raise HTTPException(status_code=500, detail=str(e))
