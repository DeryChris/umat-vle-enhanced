# ============================================================
# POST /api/v1/search — Smart Search over a course's indexed materials
# Returns ranked, snippet-previewed, source-cited results (F2).
# ============================================================

from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from models.schemas import SearchRequest, SearchResponse, SearchResult
from models.database import get_db
from middleware.auth import verify_token
from core.content_classifier import is_sensitive_query, get_sensitive_refusal
from api.v1.query_pipeline import build_citations
from rag.hybrid_retriever import get_hybrid_retriever
from api.v1.routes.query import check_rate_limit
import logging

router = APIRouter(prefix="/search", tags=["search"])
logger = logging.getLogger(__name__)

MAX_RESULTS = 12


@router.post("", response_model=SearchResponse)
async def smart_search(
    request: SearchRequest,
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    """Ranked search across a course's indexed materials.

    Students are rate-limited and only ever see chunks whose ``visibility``
    metadata is student-accessible (Privacy Layer 2, enforced in the retriever).
    Sensitive/off-topic queries are refused via the shared classifier (Layer 4).
    """
    if request.role != "lecturer":
        allowed, _remaining = check_rate_limit(request.user_id)
        if not allowed:
            return SearchResponse(results=[])

    # Privacy Layer 4: block sensitive queries (mirrors the Q&A pipeline).
    if request.role != "lecturer" and is_sensitive_query(request.query):
        logger.info(
            "Sensitive search blocked for user %s in course %s: '%s'",
            request.user_id, request.course_id, request.query[:80],
        )
        return SearchResponse(results=[])

    hybrid = get_hybrid_retriever()
    try:
        results = hybrid.search(
            course_id=request.course_id,
            query=request.query,
            n_results=MAX_RESULTS,
            material_ids=request.material_ids or None,
            role=request.role,
        )
    except Exception as e:
        logger.error("Smart search retrieval error: %s", e)
        return SearchResponse(results=[])

    if not results:
        return SearchResponse(results=[])

    citations = build_citations(results, max_citations=MAX_RESULTS)
    # Re-index citations 1-based in final order (dedup may compact the list).
    for i, c in enumerate(citations):
        c.index = i + 1

    # Map each citation back to its chunk (dedup-safe: first chunk per material).
    chunk_by_material: dict = {}
    for doc, meta in results:
        mid = meta.get("material_id", "")
        if mid not in chunk_by_material:
            chunk_by_material[mid] = doc

    return SearchResponse(results=[
        SearchResult(chunk=chunk_by_material.get(str(c.material_id), ""), citation=c, score=c.score)
        for c in citations
    ])
