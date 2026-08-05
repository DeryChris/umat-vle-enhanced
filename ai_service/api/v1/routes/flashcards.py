# ============================================================
# POST /api/v1/flashcards/generate — AI flashcard generation (F3)
#
# Lecturer-only: generates spaced-repetition study cards (front/back/topic)
# strictly grounded in the indexed chunks of the selected materials.
# Moodle persists the cards with status=pending and drives the SM-2 review
# loop itself (see local_umat_ai classes/external/flashcards.php).
# ============================================================

import json
import logging
from typing import List, Optional

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from middleware.auth import verify_token
from core.llm_processor import LLMProcessor
from core.vector_store import VectorStoreManager
from config import get_settings

logger   = logging.getLogger(__name__)
router   = APIRouter(prefix="/flashcards", tags=["flashcards"])
settings = get_settings()
_lecturer_llm = LLMProcessor(provider=settings.effective_lecturer_provider)

MAX_CARDS = 30
CONTEXT_LIMIT = 10000


# ── Schemas ─────────────────────────────────────────────────

class FlashcardGenRequest(BaseModel):
    course_id:    int
    material_ids: List[int] = Field(default_factory=list)
    count:        int = Field(10, ge=1, le=MAX_CARDS)
    topic_label:  str = ""
    role:         str = "lecturer"


class FlashcardItem(BaseModel):
    front: str
    back:  str
    topic: str = ""


class FlashcardGenResponse(BaseModel):
    cards:    List[FlashcardItem]
    total:    int
    llm_used: str = ""


# ── Helpers ─────────────────────────────────────────────────

def get_llm() -> LLMProcessor:
    try:
        return _lecturer_llm
    except Exception as e:  # pragma: no cover — defensive
        logger.warning(f"LLM not available: {e}")
        raise HTTPException(status_code=503, detail="LLM service unavailable")


def _fetch_material_context(material_id: int, course_id: int) -> Optional[str]:
    """Pull the indexed chunks for one material (same pattern as quizgen)."""
    try:
        vs = VectorStoreManager()
        results = vs.get_documents_by_filter(
            course_id=course_id,
            where_filter={"material_id": str(material_id)},
            limit=50,
        )
        if results:
            return "\n\n".join(doc for doc, _ in results)
    except Exception as e:
        logger.warning(f"Failed to fetch material {material_id} context: {e}")
    return None


def _resolve_context(course_id: int, material_ids: List[int]) -> str:
    if not material_ids:
        raise HTTPException(status_code=422, detail="At least one material ID is required.")
    all_chunks = []
    missing = []
    for mid in material_ids:
        ctx = _fetch_material_context(mid, course_id)
        if ctx:
            all_chunks.append(ctx)
        else:
            missing.append(mid)
    if not all_chunks:
        raise HTTPException(
            status_code=404,
            detail=(
                "Selected materials have no indexed content. "
                "Please ensure the materials are fully indexed first."
            ),
        )
    if missing:
        logger.warning(f"Materials without indexed content (skipped): {missing}")
    return "\n\n---\n\n".join(all_chunks)[:CONTEXT_LIMIT]


def _parse_cards_json(raw: str) -> list:
    text = raw.strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[1] if "\n" in text else text
        if text.rstrip().endswith("```"):
            text = text.rstrip()[:-3]
        text = text.strip()
    parsed = json.loads(text)
    if isinstance(parsed, dict) and "cards" in parsed:
        return parsed["cards"]
    if isinstance(parsed, list):
        return parsed
    for key in ("flashcards", "deck", "data", "result", "output"):
        if isinstance(parsed, dict) and key in parsed:
            inner = parsed[key]
            if isinstance(inner, dict) and "cards" in inner:
                return inner["cards"]
            if isinstance(inner, list):
                return inner
    raise ValueError(f"Unexpected JSON structure: {type(parsed)}")


def _validate_cards(raw_cards: list, count: int) -> List[FlashcardItem]:
    """Normalise + validate generated cards; dedupe by (front, back)."""
    seen = set()
    out: List[FlashcardItem] = []
    for c in raw_cards:
        if not isinstance(c, dict):
            continue
        front = str(c.get("front") or "").strip()
        back = str(c.get("back") or "").strip()
        topic = str(c.get("topic") or "").strip()
        if not front or not back:
            continue
        if len(front) > 500 or len(back) > 1000:
            continue
        key = (front.lower(), back.lower())
        if key in seen:
            continue
        seen.add(key)
        out.append(FlashcardItem(front=front, back=back, topic=topic[:100]))
        if len(out) >= count:
            break
    return out


# ── Prompt ──────────────────────────────────────────────────

FLASHCARD_PROMPT = """You are an expert study-card author at the University of Mines and Technology (UMaT), Ghana.

You are creating spaced-repetition flashcards from the course material below.

SOURCE MATERIAL:
{context}

=== RULES ===

1. STRICT GROUNDING: every card must be fully answerable from the source material alone. Do NOT invent facts, figures, or concepts absent from the source.
2. Each card is a question → answer pair. "front" is a concise question or cue; "back" is the complete, correct answer.
3. Fronts must be self-contained — no ambiguous references, no "according to the text".
4. Backs must be complete enough to study from alone: 1-3 precise sentences (definitions, key terms, processes, formulas, classifications, comparisons, step sequences).
5. Cover the most important, exam-relevant concepts — avoid trivia.
6. "topic" is a short label summarising the card's subject (e.g. "Week 1 — Introduction", "Slide 5", or a section name).{topic_note}

=== OUTPUT SCHEMA ===

Output ONLY valid JSON — no markdown, no code fences, no extra text.
Response must be a JSON object with a single key "cards" containing exactly {count} card objects:

{{
  "cards": [
    {{
      "front": "Question or cue",
      "back": "Complete answer",
      "topic": "Short topic label"
    }}
  ]
}}

=== QUALITY RULES ===

- Exactly {count} cards.
- Every front and back must be non-empty.
- Vary card types: definitions, explanations, lists, comparisons, process steps.
- Never invent page numbers, citations, or external facts.
"""


# ── Endpoint ────────────────────────────────────────────────

@router.post("/generate", response_model=FlashcardGenResponse)
async def generate_flashcards(
    request: FlashcardGenRequest,
    _ = Depends(verify_token),
):
    """Generate flashcards from indexed material chunks (lecturer only)."""
    if (request.role or "student").lower() != "lecturer":
        raise HTTPException(status_code=403, detail="Flashcard generation is lecturer-only.")

    try:
        context = _resolve_context(request.course_id, request.material_ids)
        topic_note = ""
        if request.topic_label and request.topic_label.strip():
            topic_note = (
                f'\n7. Use "{request.topic_label.strip()}" as the topic label for every card '
                "unless a card clearly belongs to a more specific sub-topic."
            )
        prompt = FLASHCARD_PROMPT.format(
            context=context,
            count=request.count,
            topic_note=topic_note,
        )

        llm = get_llm()
        raw = await llm.generate_assessment(prompt, temperature=0.3, max_chars=12000)
        cards = _validate_cards(_parse_cards_json(raw), request.count)

        if not cards:
            raise HTTPException(status_code=500, detail="AI returned no valid flashcards.")

        logger.info(
            "Flashcards generated: %d (requested %d) for course %s, materials %s",
            len(cards), request.count, request.course_id, request.material_ids,
        )
        return FlashcardGenResponse(cards=cards, total=len(cards), llm_used=settings.llm_provider)

    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON (first 2000 chars): {raw[:2000]}")
        try:
            strict_prompt = prompt + (
                "\n\nCRITICAL: Your previous response was not valid JSON. "
                "Respond with ONLY a raw JSON object. No explanations. No markdown. No code fences."
            )
            raw2 = await llm.generate_assessment(strict_prompt, temperature=0.1, max_chars=12000)
            cards = _validate_cards(_parse_cards_json(raw2), request.count)
            if not cards:
                raise HTTPException(status_code=500, detail="AI returned no valid flashcards after retry.")
            return FlashcardGenResponse(cards=cards, total=len(cards), llm_used=settings.llm_provider)
        except Exception as e2:
            logger.error(f"Retry also failed: {e2}")
            raise HTTPException(status_code=500, detail="LLM returned invalid JSON after retry.")
    except ValueError as e:
        logger.error(f"LLM returned unexpected structure: {e}")
        raise HTTPException(status_code=500, detail=f"LLM returned unexpected structure: {e}")
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Flashcard generation error: {e}")
        raise HTTPException(status_code=500, detail=str(e))
