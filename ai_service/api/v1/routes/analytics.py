# ============================================================
# POST /api/v1/analytics/classify-questions  — LLM topic classification
# POST /api/v1/analytics/struggle-topics     — Topic struggle analysis
# POST /api/v1/analytics/student-risk        — Per-student risk assessment
# ============================================================

import json
import logging
from typing import List, Optional

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel

from middleware.auth import verify_token
from core.llm_processor import LLMProcessor

logger = logging.getLogger(__name__)
router = APIRouter(tags=["analytics"])


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
        classifications = json.loads(result)
        return ClassifyResponse(
            classifications=[
                ClassificationResult(**c) for c in classifications
            ],
            llm_used="gemini",
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
        parsed = json.loads(result)
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


@router.post("/api/v1/analytics/student-risk", response_model=StudentRiskResponse)
async def student_risk(
    students_data: dict,
    _ = Depends(verify_token),
):
    try:
        students_json = json.dumps(students_data.get("students", []))
        prompt = STUDENT_RISK_PROMPT.format(students_json=students_json)
        result = _call_llm(prompt, max_chars=4096)
        parsed = json.loads(result)
        return StudentRiskResponse(
            students=[StudentRiskItem(**s) for s in parsed],
        )
    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON: {result}")
        raise HTTPException(status_code=500, detail="LLM returned invalid JSON")
    except Exception as e:
        logger.error(f"Student risk error: {e}")
        raise HTTPException(status_code=500, detail=str(e))
