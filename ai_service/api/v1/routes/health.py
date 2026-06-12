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
    active_model = (settings.openai_llm_model if settings.llm_provider == "openai"
                    else settings.llm_model)
    return HealthResponse(
        status        = "healthy",
        version       = "1.0.0",
        whisper_model = settings.whisper_model,
        llm_model     = f"{settings.llm_provider}:{active_model}",
    )