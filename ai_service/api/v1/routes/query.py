from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import StreamingResponse
from sqlalchemy.orm import Session
from models.schemas import QueryRequest, QueryResponse
from models.database import get_db
from middleware.auth import verify_token
from core.llm_processor import LLMProcessor
from api.v1.query_pipeline import prepare_query, log_chat
from collections import defaultdict
from threading import Lock
import time
import json
import logging

router = APIRouter(prefix="/query", tags=["query"])
logger = logging.getLogger(__name__)
llm = LLMProcessor()

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
        elapsed_ms = (time.time() - start_time) * 1000
        log_chat(db, request, answer, prepared.sources, elapsed_ms)
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

    try:
        answer = llm.answer_with_prompt(prepared.prompt, task=prepared.task)
    except Exception as e:
        logger.error(f"LLM error: {e}")
        return QueryResponse(
            answer="AI assistant is processing your request. Please try again in a moment.",
            sources=prepared.sources,
            confidence=0.0,
        )

    elapsed_ms = (time.time() - start_time) * 1000
    log_chat(db, request, answer, prepared.sources, elapsed_ms)
    return QueryResponse(answer=answer, sources=prepared.sources, confidence=prepared.confidence)
