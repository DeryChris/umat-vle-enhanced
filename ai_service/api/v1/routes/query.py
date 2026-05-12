# ============================================================
# POST /api/v1/query — student asks a question; RAG retrieves context
# ============================================================

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session
from models.schemas import QueryRequest, QueryResponse
from models.database import get_db, ChatLog
from middleware.auth import verify_token
from core.vector_store import VectorStoreManager
from core.llm_processor import LLMProcessor
from datetime import datetime
from collections import defaultdict
from threading import Lock
import time
import logging

router       = APIRouter(prefix="/query", tags=["query"])
logger       = logging.getLogger(__name__)
vector_store = VectorStoreManager()
llm          = LLMProcessor()

RATE_LIMIT_SECONDS = 60
RATE_LIMIT_MAX = 10

_rate_limit_store = defaultdict(list)
_rate_limit_lock = Lock()


def check_rate_limit(user_id: int) -> tuple[bool, int]:
    """Check if user is within rate limit. Returns (allowed, remaining)."""
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


@router.post("", response_model=QueryResponse)
async def query_course_ai(
    request: QueryRequest,
    db:      Session = Depends(get_db),
    token:   str     = Depends(verify_token),
):
    allowed, remaining = check_rate_limit(request.user_id)

    if not allowed:
        raise HTTPException(
            status_code=429,
            detail={
                "error": "Rate limit exceeded",
                "message": "Too many requests. Please wait before asking another question.",
                "retry_after": RATE_LIMIT_SECONDS,
            }
        )

    start_time = time.time()

    try:
        results = vector_store.similarity_search(
            course_id=request.course_id,
            query=request.question,
            n_results=5,
        )
    except Exception as e:
        logger.error(f"ChromaDB error: {str(e)}")
        return QueryResponse(
            answer="AI service temporarily unavailable. Please try again later.",
            sources=[],
            confidence=0.0,
        )

    if not results:
        return QueryResponse(
            answer="No course materials have been indexed for this course yet. "
                   "Please ask your lecturer to upload course materials.",
            sources=[],
            confidence=0.0,
        )

    context_texts = [doc for doc, _ in results]
    sources = list(set([
        meta.get("source", "Unknown source")
        for _, meta in results
    ]))

    try:
        answer = llm.answer_question(request.question, context_texts)
    except Exception as e:
        logger.error(f"LLM error: {str(e)}")
        return QueryResponse(
            answer="AI assistant is processing your request. Please try again in a moment.",
            sources=sources,
            confidence=0.0,
        )

    elapsed_ms = (time.time() - start_time) * 1000

    try:
        db.add(ChatLog(
            user_id          = request.user_id,
            course_id        = request.course_id,
            question         = request.question,
            answer           = answer,
            sources          = ", ".join(sources),
            response_time_ms = elapsed_ms,
            created_at       = datetime.utcnow(),
        ))
        db.commit()
    except Exception as e:
        logger.warning(f"Failed to log chat: {str(e)}")

    return QueryResponse(answer=answer, sources=sources, confidence=0.85)