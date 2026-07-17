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
    if settings.llm_provider == "openai":
        active_model = settings.openai_llm_model
    elif settings.llm_provider == "openrouter":
        active_model = settings.openrouter_model
    else:
        active_model = settings.llm_model
    return HealthResponse(
        status        = "healthy",
        version       = "1.0.0",
        whisper_model = settings.whisper_model,
        llm_model     = f"{settings.llm_provider}:{active_model}",
    )