# ============================================================
# POST /api/v1/quizgen/generate — AI quiz question generation
# ============================================================

import json
import logging
from typing import List, Optional

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel

from middleware.auth import verify_token
from core.llm_processor import LLMProcessor
from core.vector_store import VectorStoreManager
from config import get_settings
from models.database import get_db
from sqlalchemy.orm import Session

logger   = logging.getLogger(__name__)
router   = APIRouter(prefix="/quizgen", tags=["quizgen"])
settings = get_settings()


# ── Schemas ─────────────────────────────────────────────────

class QuizGenRequest(BaseModel):
    source_type:      str                     # "text" or "material_id"
    content:          Optional[str] = None    # raw text
    material_id:      Optional[int] = None    # if source_type = "material_id"
    course_id:        int
    bloom_level:      str = "understand"      # remember | understand | apply | analyze | evaluate | create
    question_types:   List[str] = ["multichoice"]
    total_questions:  int = 5
    difficulty:       str = "medium"          # easy | medium | hard
    ai_instructions:  Optional[str] = None    # lecturer's custom instructions for the AI

class GeneratedQuestion(BaseModel):
    type:                str
    question_text:       str
    options:             Optional[List[str]] = None
    correct_answer_index: Optional[int] = None
    correct_text:        Optional[str] = None
    feedback_correct:    str
    feedback_incorrect:  str
    source_reference:    str = ""

class QuizGenResponse(BaseModel):
    questions: List[GeneratedQuestion]
    llm_used: str = "gemini"


# ── Prompt ──────────────────────────────────────────────────

QUIZGEN_PROMPT = """You are an expert academic assessor at the University of Mines and Technology (UMaT), Ghana. Generate exactly {total} {difficulty}-difficulty questions based on the context below.

CONTEXT:
{context}

BLOOM'S TAXONOMY LEVEL: {bloom_level}

QUESTION TYPES REQUESTED: {types}

{instructions}

REQUIREMENTS:
1. Output ONLY valid JSON — no markdown, no code fences, no extra text.
2. Response must be a JSON object with a single key "questions" containing an array of question objects.
3. Each question object must follow this schema:
   {{
     "type": "multichoice" | "truefalse" | "shortanswer",
     "question_text": "Full question text with proper formatting",
     "options": ["Option A", "Option B", "Option C", "Option D"],
     "correct_answer_index": 0,
     "correct_text": "Exact expected answer",
     "feedback_correct": "Brief explanation of why this answer is correct",
     "feedback_incorrect": "Brief explanation of why a wrong answer is incorrect",
     "source_reference": "Specific reference to the source material"
   }}
4. Distribute the requested question types across the total.
5. For "truefalse" questions: options MUST be exactly ["True", "False"].
6. For "shortanswer" questions: set correct_text to the exact expected answer.
7. 'feedback_incorrect' MUST explain why the wrong option is wrong.
8. Base ALL questions strictly on the CONTEXT provided.
"""


# ── Helpers ─────────────────────────────────────────────────

def get_llm() -> LLMProcessor:
    try:
        return LLMProcessor()
    except Exception as e:
        logger.warning(f"LLM not available: {e}")
        raise HTTPException(status_code=503, detail="LLM service unavailable")

def _parse_quizgen_json(raw: str) -> list:
    text = raw.strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[1] if "\n" in text else text
        if text.rstrip().endswith("```"):
            text = text.rstrip()[:-3]
        text = text.strip()
    parsed = json.loads(text)
    if isinstance(parsed, dict) and "questions" in parsed:
        return parsed["questions"]
    if isinstance(parsed, list):
        return parsed
    # Handle nested wrapping: {"quiz": {"questions": [...]}} or similar.
    for key in ("quiz", "assessment", "data", "result", "output"):
        if isinstance(parsed, dict) and key in parsed:
            inner = parsed[key]
            if isinstance(inner, dict) and "questions" in inner:
                return inner["questions"]
            if isinstance(inner, list):
                return inner
    raise ValueError(f"Unexpected JSON structure: {type(parsed)}")

def _fetch_material_context(material_id: int, course_id: int) -> Optional[str]:
    """Retrieve indexed document chunks for a material from ChromaDB or PostgreSQL."""
    # Try ChromaDB first (vector store).
    try:
        vs = VectorStoreManager()
        client = vs.get_chroma_client()  # need to expose this properly
    except Exception:
        pass

    # Fall back to PostgreSQL indexed_documents table.
    from models.database import IndexedDocument
    db: Session = next(get_db())
    try:
        rows = db.query(IndexedDocument).filter(
            IndexedDocument.material_id == material_id,
            IndexedDocument.course_id  == course_id,
        ).all()
        if not rows:
            return None
        chunks = [r.chunk_text for r in rows[:20] if r.chunk_text]
        return "\n\n".join(chunks) if chunks else None
    except Exception as e:
        logger.warning(f"Failed to fetch material context: {e}")
        return None
    finally:
        db.close()


# ── Endpoint ────────────────────────────────────────────────

@router.post("/generate", response_model=QuizGenResponse)
async def generate_quiz(
    request: QuizGenRequest,
    _ = Depends(verify_token),
):
    try:
        # 1. Resolve context text.
        if request.source_type == "material_id" and request.material_id:
            context = _fetch_material_context(request.material_id, request.course_id)
            if not context:
                raise HTTPException(status_code=404, detail="Material not found or has no indexed content")
        elif request.source_type == "text" and request.content:
            context = request.content
        else:
            raise HTTPException(status_code=422, detail="Provide either content (text) or material_id")

        context = context[:8000]
        types_str = ", ".join(request.question_types)
        instr = ""
        if request.ai_instructions:
            instr = f"LECTURER'S ADDITIONAL INSTRUCTIONS:\n{request.ai_instructions}"

        # 2. Build prompt.
        prompt = QUIZGEN_PROMPT.format(
            total      = request.total_questions,
            difficulty = request.difficulty,
            context    = context,
            bloom_level= request.bloom_level,
            types      = types_str,
            instructions = instr,
        )

        # 3. Call LLM with JSON mode.
        llm = get_llm()
        raw = await llm.generate_assessment(prompt, temperature=0.3, max_chars=12000)

        # 4. Parse.
        questions_data = _parse_quizgen_json(raw)
        questions = [GeneratedQuestion(**q) for q in questions_data]
        return QuizGenResponse(questions=questions, llm_used=settings.llm_provider)

    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON (first 2000 chars): {raw[:2000]}")
        try:
            strict_prompt = prompt + "\n\nCRITICAL: Your previous response was not valid JSON. Respond with ONLY a raw JSON object. No explanations. No markdown."
            raw2 = await llm.generate_assessment(strict_prompt, temperature=0.1, max_chars=12000)
            questions_data = _parse_quizgen_json(raw2)
            questions = [GeneratedQuestion(**q) for q in questions_data]
            return QuizGenResponse(questions=questions, llm_used=settings.llm_provider)
        except Exception as e2:
            logger.error(f"Retry also failed: {e2}")
            raise HTTPException(status_code=500, detail="LLM returned invalid JSON after retry")
    except ValueError as e:
        logger.error(f"LLM returned unexpected structure: {e} — raw: {raw[:2000]}")
        raise HTTPException(status_code=500, detail=f"LLM returned unexpected structure: {e}")
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Quiz generation error: {e}")
        raise HTTPException(status_code=500, detail=str(e))
