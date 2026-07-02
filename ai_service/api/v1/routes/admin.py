# ============================================================
# Admin control endpoints — used by the Moodle Admin Control FAB
# All endpoints require Bearer token auth.
# ============================================================

import os
import logging
from datetime import datetime

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session

from middleware.auth import verify_token
from core.vector_store import get_chroma_client
from models.database import get_db, ProcessingJob

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/admin", tags=["admin"])


@router.get("/health")
async def admin_health(_=Depends(verify_token)):
    """Deep system health check including ChromaDB stats and memory usage."""
    client = get_chroma_client()
    collections = client.list_collections()

    total_docs = 0
    for col in collections:
        try:
            total_docs += col.count()
        except Exception:
            pass

    import psutil
    process = psutil.Process(os.getpid())
    mem_mb = process.memory_info().rss / (1024 * 1024)

    from core.transcription import get_whisper_model
    whisper_model = get_whisper_model()
    whisper_loaded = whisper_model is not None

    return {
        "status": "healthy",
        "chroma_collections": len(collections),
        "chroma_total_documents": total_docs,
        "python_memory_mb": round(mem_mb, 2),
        "whisper_loaded": whisper_loaded,
    }


@router.post("/clear-cache")
async def clear_semantic_cache(_=Depends(verify_token)):
    """Delete and recreate the analytics_cache collection."""
    client = get_chroma_client()
    try:
        try:
            client.delete_collection("analytics_cache")
        except Exception:
            pass
        client.create_collection(
            "analytics_cache",
            metadata={"hnsw:space": "cosine"},
        )
        return {"status": "success", "message": "Semantic cache cleared."}
    except Exception as e:
        logger.error("Failed to clear semantic cache: %s", e)
        raise HTTPException(status_code=500, detail=str(e))


@router.get("/jobs")
async def get_processing_jobs(db: Session = Depends(get_db), _=Depends(verify_token)):
    """List active and recent processing jobs."""
    jobs = (
        db.query(ProcessingJob)
        .order_by(ProcessingJob.created_at.desc())
        .limit(20)
        .all()
    )
    return {
        "jobs": [
            {
                "id": j.id,
                "job_id": j.job_id,
                "course_id": j.course_id,
                "status": j.status,
                "error_message": j.error_message,
                "created_at": j.created_at.isoformat() if j.created_at else None,
                "completed_at": j.completed_at.isoformat() if j.completed_at else None,
            }
            for j in jobs
        ]
    }
