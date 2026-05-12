# ============================================================
# POST /api/v1/recording/process  — submit a BBB recording for processing
# GET  /api/v1/recording/status/{job_id} — check job status
# ============================================================

from fastapi import APIRouter, Depends, BackgroundTasks, HTTPException
from sqlalchemy.orm import Session
from models.schemas import ProcessRecordingRequest, ProcessRecordingResponse
from models.database import get_db, ProcessingJob
from middleware.auth import verify_token
from core.audio_processor import AudioProcessor
from core.transcription import TranscriptionService
from core.document_loader import DocumentLoader
from core.vector_store import VectorStoreManager
from core.llm_processor import LLMProcessor
import uuid
from datetime import datetime
import logging
import time
import traceback
from typing import Optional

router = APIRouter(prefix="/recording", tags=["recording"])
logger = logging.getLogger(__name__)

RETRY_CONFIG = {
    "max_retries": 3,
    "delays": [1, 2, 4],
    "backoff_multiplier": 2,
}

ADMIN_EMAIL = "admin@umat.edu.gh"


def notify_admin_admin(job_id: str, error: str, session_id: str):
    """Send notification to admin when job fails after all retries."""
    logger.critical(
        f"[ADMIN ALERT] Recording processing job {job_id} failed after retries.\n"
        f"Session: {session_id}\n"
        f"Error: {error}\n"
        f"Admin email: {ADMIN_EMAIL}"
    )


def with_retry(func):
    """Decorator to add retry logic with exponential backoff."""
    def wrapper(*args, **kwargs):
        last_error = None
        for attempt in range(RETRY_CONFIG["max_retries"]):
            try:
                return func(*args, **kwargs)
            except Exception as e:
                last_error = e
                if attempt < RETRY_CONFIG["max_retries"] - 1:
                    delay = RETRY_CONFIG["delays"][attempt]
                    logger.warning(f"Attempt {attempt + 1} failed: {str(e)}. Retrying in {delay}s...")
                    time.sleep(delay)
                else:
                    logger.error(f"All {RETRY_CONFIG['max_retries']} attempts failed: {str(e)}")
        raise last_error
    return wrapper


def process_recording_background(
    job_id:  str,
    request: ProcessRecordingRequest,
    db_url:  str,
):
    """Background task: download → extract audio → transcribe → index → generate AI outputs."""
    from sqlalchemy import create_engine
    from sqlalchemy.orm import sessionmaker
    from sqlalchemy.exc import OperationalError
    from models.database import ProcessingJob
    import requests as http_requests

    engine       = create_engine(db_url, pool_pre_ping=True, pool_recycle=3600)
    SessionLocal = sessionmaker(bind=engine)
    db           = SessionLocal()

    audio_proc  = AudioProcessor()
    transcriber = TranscriptionService()
    doc_loader  = DocumentLoader()
    vector_store = VectorStoreManager()
    llm          = LLMProcessor()

    video_path = None
    audio_path = None
    step_failed = None

    try:
        job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()

        try:
            # Step 1: Download
            job.status = "downloading"
            db.commit()
            logger.info(f"[{job_id}] Downloading from {request.recording_url}")
            video_path = audio_proc.download_recording(request.recording_url)
        except (http_requests.exceptions.Timeout, http_requests.exceptions.ConnectionError) as e:
            step_failed = "download"
            raise Exception(f"BBB API timeout or connection error: {str(e)}")

        try:
            # Step 2: Extract audio
            job.status = "transcribing"
            db.commit()
            logger.info(f"[{job_id}] Extracting audio")
            audio_path = audio_proc.extract_audio_from_video(video_path)
        except Exception as e:
            if "ffmpeg" in str(e).lower() or "video" in str(e).lower():
                step_failed = "audio_extraction"
                raise Exception(f"Audio extraction failed: {str(e)}")

        try:
            # Step 3: Transcribe
            logger.info(f"[{job_id}] Transcribing")
            result     = transcriber.transcribe_audio(audio_path)
            transcript = result["text"]
            job.transcript = transcript
            job.status     = "processing_ai"
            db.commit()
        except Exception as e:
            if "whisper" in str(e).lower() or "audio" in str(e).lower():
                step_failed = "transcription"
                raise Exception(f"Transcription failed: {str(e)}")

        try:
            # Step 4: Index transcript in ChromaDB
            logger.info(f"[{job_id}] Indexing transcript")
            texts, metadatas, ids = doc_loader.process_transcript(
                transcript, request.session_id, request.course_id
            )
            if texts:
                vector_store.add_documents(request.course_id, texts, metadatas, ids)
        except Exception as e:
            step_failed = "chromadb_indexing"
            raise Exception(f"ChromaDB indexing failed: {str(e)}")

        try:
            # Step 5: Generate AI outputs (Gemini)
            logger.info(f"[{job_id}] Generating summary, notes, quiz")
            llm.generate_summary(transcript)
            llm.generate_notes(transcript)
            llm.generate_quiz(transcript)
        except Exception as e:
            if "gemini" in str(e).lower() or "api" in str(e).lower():
                step_failed = "llm_generation"
                raise Exception(f"Gemini API error: {str(e)}")

        job.status       = "completed"
        job.completed_at = datetime.utcnow()
        db.commit()
        logger.info(f"[{job_id}] Completed")

    except Exception as e:
        error_details = f"{str(e)}\n{traceback.format_exc()}"
        logger.error(f"[{job_id}] Failed at step {step_failed or 'unknown'}: {error_details}")

        try:
            job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()
            if job:
                job.status        = "failed"
                job.error_message = f"Step: {step_failed or 'unknown'}, Error: {str(e)}"
                db.commit()

                if step_failed:
                    notify_admin_admin(job_id, str(e), request.session_id)
        except OperationalError as db_error:
            logger.critical(f"[{job_id}] Database connection lost: {db_error}")

    finally:
        try:
            audio_proc.cleanup(video_path, audio_path)
        except Exception as cleanup_error:
            logger.warning(f"[{job_id}] Cleanup error: {cleanup_error}")
        finally:
            try:
                db.close()
            except Exception:
                pass


@router.post("/process", response_model=ProcessRecordingResponse)
async def process_recording(
    request:          ProcessRecordingRequest,
    background_tasks: BackgroundTasks,
    db:               Session = Depends(get_db),
    token:            str     = Depends(verify_token),
):
    from config import get_settings
    cfg    = get_settings()
    job_id = str(uuid.uuid4())

    job = ProcessingJob(
        job_id        = job_id,
        session_id    = request.session_id,
        course_id     = request.course_id,
        recording_url = request.recording_url,
        status        = "queued",
    )
    db.add(job)
    db.commit()

    background_tasks.add_task(
        process_recording_background,
        job_id,
        request,
        cfg.database_url,
    )

    return ProcessRecordingResponse(
        job_id  = job_id,
        status  = "queued",
        message = "Recording processing started",
    )


@router.get("/status/{job_id}")
async def get_job_status(
    job_id: str,
    db:     Session = Depends(get_db),
    token:  str     = Depends(verify_token),
):
    job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()
    if not job:
        raise HTTPException(status_code=404, detail="Job not found")

    return {
        "job_id":       job.job_id,
        "session_id":   job.session_id,
        "status":       job.status,
        "created_at":   job.created_at,
        "completed_at": job.completed_at,
        "error":        job.error_message,
    }