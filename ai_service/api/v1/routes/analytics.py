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
    risk_score: float
    risk_factors: List[str]
    recommendation: str

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
    """Parse JSON from an LLM reply, tolerating markdown code fences and
    surrounding prose (gpt-4o-mini in particular wraps JSON in ```json)."""
    text = raw.strip()
    if text.startswith("```"):
        # Drop the opening fence (with optional language tag) and closing fence.
        text = text.split("\n", 1)[1] if "\n" in text else text
        if text.rstrip().endswith("```"):
            text = text.rstrip()[:-3]
        text = text.strip()
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        # Last resort: extract the outermost JSON array or object.
        for opener, closer in (("[", "]"), ("{", "}")):
            start = text.find(opener)
            end   = text.rfind(closer)
            if start != -1 and end > start:
                return json.loads(text[start:end + 1])
        raise


# ── Prompts ──────────────────────────────────────────────────

CLASSIFY_PROMPT = """You are an educational analytics assistant. Classify each student question into one of the provided topics and a struggle type.

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

Struggle type meanings:
- conceptual: student misunderstands a core concept
- procedural: student doesn't know the steps/process
- clarity: student needs clarification on terminology
- application: student can't apply the concept to a problem

Questions:
{questions_json}
"""

STRUGGLE_TOPICS_PROMPT = """You are an educational analytics assistant. Analyze the following topics with their question counts and student counts. Identify the topics that students struggle with most and provide recommendations.

For each topic, respond with a JSON object:
{{
  "topics": [
    {{
      "topic": "<topic name>",
      "question_count": <number>,
      "struggle_score": <0-100>,
      "recommendation": "<specific actionable recommendation for the lecturer>"
    }}
  ],
  "summary": "<one-sentence summary of the overall struggle pattern>"
}}

Consider:
- Higher question counts indicate more struggle
- Topics with many unique students indicate widespread difficulty
- Look for patterns across related topics

Topics data:
{topics_json}
"""

EXTRACT_TOPICS_PROMPT = """You are an expert academic diagnostician. Your job is to identify the REAL topics, concepts, and subject areas where students are genuinely struggling, by triangulating ALL available data sources.

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
      "suggestion": "Dedicated recap session on SSL handshake and certificate chains with live demo",
      "suggestion_type": "recap"
    }}
  ],
  "confidence": 0.92,
  "summary_insight": "Students are struggling most with payment security fundamentals (SSL, certificate validation) and API integration patterns. Recommend a hands-on workshop covering certificate chain validation."
}}
"""

STUDENT_RISK_PROMPT = """You are an educational analytics assistant. Assess student risk based on their question patterns.

For each student, respond with a JSON array:
[
  {{
    "user_id": <id>,
    "question_count": <number>,
    "risk_score": <0-100>,
    "risk_factors": ["<factor1>", "<factor2>"],
    "recommendation": "<actionable recommendation>"
  }}
]

Consider:
- Higher question counts = higher risk
- Multiple struggle topics = higher risk
- Increasing trend = higher risk

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


NLQ_PROMPT = """You are an expert academic diagnostician. Analyze the student data and evidence to answer the lecturer's query.

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
      "root_cause": "<2-3 sentence explanation of why this student is struggling>",
      "evidence_citations": ["<source>"],
      "ai_draft_message": "<2-3 sentence encouraging message the lecturer can send>"
    }}
  ],
  "global_insight": "<one-sentence summary of the overall class pattern>"
}}

Rules:
- If the query is too vague, return the top 3 most common struggle tags instead of individual students.
- If no evidence is found, set evidence_citations to [] and explain in root_cause using only quantitative metrics.
- Keep ai_draft_message supportive and specific.
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
        return StruggleTopicsResponse(
            topics=[StruggleTopic(**t) for t in parsed["topics"]],
            summary=parsed.get("summary", ""),
        )
    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON: {result}")
        raise HTTPException(status_code=500, detail="LLM returned invalid JSON")
    except Exception as e:
        logger.error(f"Struggle topics error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/api/v1/analytics/extract-topics", response_model=ExtractTopicsResponse)
async def extract_course_topics(
    request: ExtractTopicsRequest,
    db: Session = Depends(get_db),
    _ = Depends(verify_token),
):
    """Extract meaningful struggle topics from ALL student data sources using LLM + memory cache."""
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

        # ── Build context from all data sources ──
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
            [f"{i+1}. {q}" for i, q in enumerate(request.questions[:150])]
        ) if request.questions else "No student questions available."

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
        for pt in all_previous[:10]:
            prev_topic = pt.get("topic_name", pt.get("topic", ""))
            prev_score = pt.get("struggle_score", "N/A")
            previous_context_lines.append(f"- {prev_topic} (previous score: {prev_score})")
        previous_context = "\n".join(previous_context_lines) or "No previous analysis available."

        prompt = EXTRACT_TOPICS_PROMPT.format(
            course_name=request.course_name or "General Course",
            course_sections=course_sections_text,
            material_context=material_context,
            q_count=len(request.questions),
            question_list=question_list,
            issue_context=issue_context,
            event_context=event_context,
            previous_context=previous_context,
        )

        llm = get_llm()
        result = llm._invoke(prompt, temperature=0.3, max_chars=8192)
        parsed = _parse_llm_json(result)

        generic_verbs = {
            'explain', 'define', 'describe', 'list', 'discuss',
            'give', 'practice', 'summarize', 'outline', 'identify',
            'state', 'mention', 'tell', 'show', 'write', 'create',
            'referenc', 'common',
        }

        validated = []
        for topic in parsed.get("topics", []):
            name = topic.get("topic_name", "").strip()
            if not name:
                continue
            words = name.lower().split()
            if len(words) < 2:
                continue
            if any(w in generic_verbs for w in words):
                continue
            ev_src = topic.get("evidence_sources", {})
            validated.append(ExtractTopicsTopic(
                topic_name=name,
                struggle_score=min(100, max(0, float(topic.get("struggle_score", 50)))),
                severity=topic.get("severity", "watch"),
                trend=topic.get("trend", "stable"),
                student_count=int(topic.get("student_count", 0)),
                question_count=int(topic.get("question_count", 0)),
                evidence_sources=ExtractedTopicEvidence(
                    chat_questions=int(ev_src.get("chat_questions", 0)),
                    quiz_failures=int(ev_src.get("quiz_failures", 0)),
                    repeated_views=int(ev_src.get("repeated_views", 0)),
                    assignment_failures=int(ev_src.get("assignment_failures", 0)),
                    issue_reports=int(ev_src.get("issue_reports", 0)),
                ),
                sample_questions=topic.get("sample_questions", [])[:3],
                related_materials=topic.get("related_materials", []),
                related_sections=topic.get("related_sections", []),
                affected_student_ids=[int(s) for s in (topic.get("affected_student_ids", []) or []) if s],
                suggestion=topic.get("suggestion", ""),
                suggestion_type=topic.get("suggestion_type", "recap"),
            ))

        response = ExtractTopicsResponse(
            topics=validated[:20],
            confidence=float(parsed.get("confidence", 0.0)),
            summary_insight=parsed.get("summary_insight", ""),
            total_questions_analyzed=len(request.questions),
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

DASHBOARD_PROMPT = """You are an educational analytics assistant producing a dashboard summary for a university lecturer.

Given the following course analytics data, produce a brief dashboard summary.

Course data:
{course_data_json}

Respond with a JSON object:
{{
  "top_topic_insight": "<one-sentence insight about the most difficult topic based on risk scores and student data>",
  "recommendations": ["<recommendation 1>", "<recommendation 2>"],
  "impact_summary": "<one paragraph natural language summary of course health>"
}}

Keep it concise and actionable. Base the top_topic_insight on the student risk data provided.
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

COURSE_HEALTH_PROMPT = """You are an educational analytics assistant producing a comprehensive course health report for a university lecturer.

Given the following course analytics data, produce a natural-language report that a lecturer can understand at a glance.

Course data:
{course_data_json}

Respond with a JSON object:
{{
  "overall_health": "<healthy|moderate|struggling>",
  "summary": "<2-3 sentence overall assessment>",
  "key_findings": ["<finding 1>", "<finding 2>", ...],
  "worst_topic_analysis": "<brief analysis of the most problematic topic>",
  "student_risk_summary": "<summary of at-risk student patterns>",
  "recommendations": ["<recommendation 1>", "<recommendation 2>", ...],
  "event_pattern_insight": "<if events exist, what the pattern suggests; otherwise empty string>"
}}

Keep the report concise and actionable. Focus on patterns the lecturer can actually do something about.
"""


class CourseHealthResponse(BaseModel):
    overall_health: str
    summary: str
    key_findings: List[str]
    worst_topic_analysis: str
    student_risk_summary: str
    recommendations: List[str]
    event_pattern_insight: Optional[str] = ""


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
        logger.error(f"LLM returned invalid JSON: {result}")
        raise HTTPException(status_code=500, detail="LLM returned invalid JSON")
    except Exception as e:
        logger.error(f"Student progress error: {e}")
        raise HTTPException(status_code=500, detail=str(e))
