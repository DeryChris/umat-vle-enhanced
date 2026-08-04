# ============================================================
# FastAPI application entry point
# Run with: python main.py
# Swagger UI: http://localhost:8000/docs
# ============================================================

import logging
import os
from contextlib import asynccontextmanager
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from api.v1.routes import recording, query, materials, analysis, health, analytics, lti, quizgen, video, quiz, admin, transcription, search
from models.database import init_db
from config import get_settings

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
    handlers=[
        logging.StreamHandler(),
        logging.FileHandler("ai_service.log"),
    ],
)
logger   = logging.getLogger(__name__)
settings = get_settings()


@asynccontextmanager
async def lifespan(app: FastAPI):
    # ---- Startup ----
    logger.info("Starting UMaT AI Service...")
    os.makedirs(settings.upload_dir,    exist_ok=True)
    os.makedirs(settings.chroma_db_path, exist_ok=True)

    init_db()
    logger.info("Database initialized.")

    # Pre-load Whisper so the first request is not slow (local mode only)
    if not settings.openai_whisper_api_key:
        from core.transcription import get_whisper_model
        get_whisper_model()
        logger.info("Local Whisper model pre-loaded.")
    else:
        logger.info("Using cloud Whisper API (OPENAI_WHISPER_API_KEY is set).")

    # Recover stuck jobs from previous runs
    try:
        from core.job_queue import recover_stuck_jobs
        recovered = recover_stuck_jobs(settings.database_url)
        if recovered:
            logger.info("Recovered %d stuck job(s) from previous run.", len(recovered))
    except Exception as e:
        logger.warning("Job recovery failed (non-fatal): %s", e)

    # Log accumulated cloud transcription cost
    try:
        import json
        cost_path = settings.openai_whisper_cost_tracker_path
        if cost_path and os.path.exists(cost_path):
            with open(cost_path) as f:
                data = json.load(f)
                logger.info("Accumulated cloud transcription cost: $%.4f", data.get("total_spent", 0))
    except Exception:
        pass

    logger.info(f"UMaT AI Service ready on port {settings.ai_service_port}")
    yield
    # ---- Shutdown ----
    logger.info("UMaT AI Service shutting down.")


import asyncio
import logging

# Suppress noisy Windows proactor ConnectionResetError after client disconnect.
_proactor_logger = logging.getLogger("asyncio")
_orig_exc_handler = None

def _silent_proactor_errors(loop, context):
    exc = context.get("exception")
    if isinstance(exc, ConnectionResetError):
        return  # silently drop — client disconnected, harmless
    if _orig_exc_handler:
        _orig_exc_handler(loop, context)

try:
    loop = asyncio.get_event_loop()
    _orig_exc_handler = loop.get_exception_handler()
    loop.set_exception_handler(_silent_proactor_errors)
except RuntimeError:
    pass

app = FastAPI(
    title       = "UMaT AI Service",
    description = "Generative AI backend for UMaT Virtual Learning Environment",
    version     = "1.0.0",
    lifespan    = lifespan,
    docs_url    = "/docs",
    redoc_url   = "/redoc",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins    = ["http://localhost", "http://localhost/moodle", "https://umat-vle-test.loca.lt"],
    allow_credentials = True,
    allow_methods    = ["POST", "GET", "DELETE"],
    allow_headers    = ["Authorization", "Content-Type"],
)

app.include_router(health.router,    prefix="/api/v1")
app.include_router(recording.router, prefix="/api/v1")
app.include_router(query.router,     prefix="/api/v1")
app.include_router(materials.router, prefix="/api/v1")
app.include_router(analysis.router)  # uses explicit full paths
app.include_router(analytics.router) # uses explicit full paths
app.include_router(lti.router,       prefix="/api/v1")
app.include_router(quizgen.router,   prefix="/api/v1")
app.include_router(video.router,     prefix="/api/v1")
app.include_router(quiz.router,      prefix="/api/v1")
app.include_router(admin.router,     prefix="/api/v1")
app.include_router(transcription.router, prefix="/api/v1")
app.include_router(search.router,       prefix="/api/v1")

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "main:app",
        host      = settings.ai_service_host,
        port      = settings.ai_service_port,
        reload    = False,
        log_level = "info",
    )