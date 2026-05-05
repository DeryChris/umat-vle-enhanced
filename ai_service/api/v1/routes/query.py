# ============================================================
# POST /api/v1/query — student asks a question; RAG retrieves context
# ============================================================

from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session
from models.schemas import QueryRequest, QueryResponse
from models.database import get_db, ChatLog
from middleware.auth import verify_token
from core.vector_store import VectorStoreManager
from core.llm_processor import LLMProcessor
from datetime import datetime
import time
import logging

router       = APIRouter(prefix="/query", tags=["query"])
logger       = logging.getLogger(__name__)
vector_store = VectorStoreManager()
llm          = LLMProcessor()


@router.post("", response_model=QueryResponse)
async def query_course_ai(
    request: QueryRequest,
    db:      Session = Depends(get_db),
    token:   str     = Depends(verify_token),
):
    start_time = time.time()

    # Retrieve relevant context from ChromaDB
    results = vector_store.similarity_search(
        course_id=request.course_id,
        query=request.question,
        n_results=5,
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

    answer     = llm.answer_question(request.question, context_texts)
    elapsed_ms = (time.time() - start_time) * 1000

    # Log interaction
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

    return QueryResponse(answer=answer, sources=sources, confidence=0.85)