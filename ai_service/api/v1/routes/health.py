# ============================================================
# GET /api/v1/health — no auth required; used for readiness checks
# ============================================================

from fastapi import APIRouter
from models.schemas import HealthResponse
from config import get_settings

router   = APIRouter(prefix="/health", tags=["health"])
settings = get_settings()


@router.get("", response_model=HealthResponse)
async def health_check():
    return HealthResponse(
        status        = "healthy",
        version       = "1.0.0",
        whisper_model = settings.whisper_model,
        llm_model     = settings.llm_model,
    )