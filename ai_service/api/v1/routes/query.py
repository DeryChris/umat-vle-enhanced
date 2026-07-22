from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import StreamingResponse
from sqlalchemy.orm import Session
from models.schemas import QueryRequest, QueryResponse, QuizData
from models.database import get_db
from middleware.auth import verify_token
from core.llm_processor import LLMProcessor
from core.content_classifier import check_response_leakage
from api.v1.query_pipeline import prepare_query, log_chat, extract_quiz_json
from config import get_settings
from collections import defaultdict
from threading import Lock
import time
import json
import logging

router = APIRouter(prefix="/query", tags=["query"])
logger = logging.getLogger(__name__)
settings = get_settings()
_student_llm = LLMProcessor()  # Gemini for students
_lecturer_llm = LLMProcessor(provider=settings.effective_lecturer_provider)  # OpenAI for lecturers

RATE_LIMIT_SECONDS = 60
RATE_LIMIT_MAX = 10

_rate_limit_store = defaultdict(list)
_rate_limit_lock = Lock()


def check_rate_limit(user_id: int) -> tuple[bool, int]:
    now = time.time()
    window_start = now - RATE_LIMIT_SECONDS

    with _rate_limit_lock:
        user_requests = _rate_limit_store[user_id]
        user_requests[:] = [t for t in user_requests if t > window_start]
        remaining = RATE_LIMIT_MAX - len(user_requests)
        if remaining <= 0:
            return False, 0
        user_requests.append(now)
        return True, remaining


def _sse(event: str, data: dict) -> str:
    return f"event: {event}\ndata: {json.dumps(data)}\n\n"


@router.post("/stream")
async def query_course_ai_stream(
    request: QueryRequest,
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    """Stream tutor response as Server-Sent Events (SSE)."""
    if request.role == "lecturer":
        allowed, remaining = True, RATE_LIMIT_MAX
    else:
        allowed, remaining = check_rate_limit(request.user_id)

    def generate():
        if not allowed:
            yield _sse("error", {
                "message": "Too many requests. Please wait before asking another question.",
                "error": "rate_limit",
                "remaining": 0,
            })
            return

        start_time = time.time()
        prepared = prepare_query(request, db)

        yield _sse("meta", {
            "task": prepared.task,
            "sources": prepared.sources,
            "remaining": max(0, remaining - 1),
        })

        if prepared.instant_answer is not None:
            answer = prepared.instant_answer
            yield _sse("token", {"text": answer})
            elapsed_ms = (time.time() - start_time) * 1000
            log_chat(db, request, answer, prepared.sources, elapsed_ms)
            yield _sse("done", {
                "answer": answer,
                "sources": prepared.sources,
                "confidence": prepared.confidence,
            })
            return

        llm = _lecturer_llm if request.role == "lecturer" else _student_llm
        answer_parts: list[str] = []
        try:
            for chunk in llm.stream_prompt(prepared.prompt, task=prepared.task):
                answer_parts.append(chunk)
                yield _sse("token", {"text": chunk})
        except Exception as e:
            logger.error(f"LLM stream error: {e}")
            yield _sse("error", {
                "message": "AI assistant is processing your request. Please try again in a moment.",
                "error": "llm_error",
            })
            return

        answer = "".join(answer_parts).strip()

        quiz_data = extract_quiz_json(answer)

        # --- Privacy Layer 5: output leak detection ----------------------
        # Extract quiz JSON BEFORE leak detection to avoid false positives
        # from quiz explanations (e.g. "The correct answer is...").
        if quiz_data is None:
            answer = check_response_leakage(answer, request.role)

        elapsed_ms = (time.time() - start_time) * 1000
        log_chat(db, request, answer, prepared.sources, elapsed_ms)

        if quiz_data is not None:
            logger.info("Sending quiz_data SSE event (%d questions)", len(quiz_data.get("questions", [])))
            yield _sse("quiz_data", {"quiz": quiz_data})
        else:
            logger.debug("No quiz data extracted from answer (len=%d)", len(answer))

        yield _sse("done", {
            "answer": answer,
            "sources": prepared.sources,
            "confidence": prepared.confidence,
        })

    return StreamingResponse(
        generate(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",
        },
    )


@router.post("", response_model=QueryResponse)
async def query_course_ai(
    request: QueryRequest,
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    if request.role == "lecturer":
        allowed, remaining = True, RATE_LIMIT_MAX
    else:
        allowed, remaining = check_rate_limit(request.user_id)
    if not allowed:
        raise HTTPException(
            status_code=429,
            detail={
                "error": "Rate limit exceeded",
                "message": "Too many requests. Please wait before asking another question.",
                "retry_after": RATE_LIMIT_SECONDS,
            },
        )

    start_time = time.time()
    prepared = prepare_query(request, db)

    if prepared.instant_answer is not None:
        elapsed_ms = (time.time() - start_time) * 1000
        log_chat(db, request, prepared.instant_answer, prepared.sources, elapsed_ms)
        return QueryResponse(
            answer=prepared.instant_answer,
            sources=prepared.sources,
            confidence=prepared.confidence,
        )

    llm = _lecturer_llm if request.role == "lecturer" else _student_llm
    try:
        answer = llm.answer_with_prompt(prepared.prompt, task=prepared.task)
    except Exception as e:
        logger.error(f"LLM error: {e}")
        return QueryResponse(
            answer="AI assistant is processing your request. Please try again in a moment.",
            sources=prepared.sources,
            confidence=0.0,
        )

    quiz_raw = extract_quiz_json(answer)

    # --- Privacy Layer 5: output leak detection --------------------------
    # Skip leak detection for quiz responses to avoid false positives
    # from quiz explanations (e.g. "The correct answer is...").
    if quiz_raw is None:
        answer = check_response_leakage(answer, request.role)

    elapsed_ms = (time.time() - start_time) * 1000
    log_chat(db, request, answer, prepared.sources, elapsed_ms)

    quiz_data = None
    if quiz_raw is not None:
        try:
            quiz_data = QuizData(**quiz_raw)
        except Exception as e:
            logger.warning(f"Failed to parse quiz_data: {e}")

    return QueryResponse(
        answer=answer,
        sources=prepared.sources,
        confidence=prepared.confidence,
        quiz_data=quiz_data,
    )
