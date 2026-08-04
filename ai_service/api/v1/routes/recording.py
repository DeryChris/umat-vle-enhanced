# ============================================================
# POST /api/v1/recording/process  — submit a BBB recording for processing
# GET  /api/v1/recording/status/{job_id} — check job status
# POST /api/v1/recording/reprocess — re-run transcription with provider/model overrides
# ============================================================

import json
import uuid
import logging
import time
import traceback
from datetime import datetime
from typing import Optional

import httpx

from fastapi import APIRouter, Depends, BackgroundTasks, HTTPException
from sqlalchemy.orm import Session

from models.schemas import ProcessRecordingRequest, ProcessRecordingResponse, ReprocessRecordingResponse
from models.database import get_db, ProcessingJob
from middleware.auth import verify_token
from core.audio_processor import AudioProcessor, UrlExpiredError
from core.api_transcription import ApiTranscriptionService
from core.document_loader import DocumentLoader
from core.vector_store import VectorStoreManager
from core.llm_processor import LLMProcessor
from core.job_queue import update_progress, call_webhook
from config import get_settings

router = APIRouter(prefix="/recording", tags=["recording"])
logger = logging.getLogger(__name__)

ADMIN_EMAIL = "admin@umat.edu.gh"


def notify_admin(job_id: str, error: str, session_id: str):
    logger.critical(
        f"[ADMIN ALERT] Recording processing job {job_id} failed.\n"
        f"Session: {session_id}\n"
        f"Error: {error}\n"
        f"Admin email: {ADMIN_EMAIL}"
    )


def process_recording_background(
    job_id:  str,
    request: ProcessRecordingRequest,
    db_url:  str,
    transcription_provider_override: Optional[str] = None,
    transcription_model_override:    Optional[str] = None,
):
    """Background task: download → extract audio → transcribe → index → generate AI outputs.

    Uses ApiTranscriptionService (OpenAI API) when TRANSCRIPTION_API_KEY is set,
    falls back to local Whisper otherwise.

    Args:
        job_id: Unique job identifier.
        request: Original process request.
        db_url: Database connection string.
        transcription_provider_override: If set, overrides the config-level transcription provider.
        transcription_model_override: If set, overrides the config-level transcription model.
    """
    from sqlalchemy import create_engine
    from sqlalchemy.orm import sessionmaker
    from sqlalchemy.exc import OperationalError
    from models.database import ProcessingJob

    engine       = create_engine(db_url, pool_pre_ping=True, pool_recycle=3600)
    SessionLocal = sessionmaker(bind=engine)
    db           = SessionLocal()

    audio_proc   = AudioProcessor()
    transcriber  = ApiTranscriptionService(
        provider=transcription_provider_override,
        model=transcription_model_override,
    )
    doc_loader   = DocumentLoader()
    vector_store = VectorStoreManager()
    llm          = LLMProcessor()

    video_path = None
    audio_path = None
    step_failed = None

    def _progress(status: str, percent: int):
        try:
            update_progress(job_id, db_url, status, percent)
        except Exception:
            pass

    try:
        job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()

        # Store title if provided
        if request.title:
            job.title = request.title
            db.commit()

        try:
            # Step 1: Download (0-20%)
            _progress("downloading", 5)
            logger.info(f"[{job_id}] Downloading from {request.recording_url}")
            video_path = audio_proc.download_recording(request.recording_url)
            _progress("downloading", 20)
        except (httpx.TimeoutException, httpx.ConnectError, httpx.RemoteProtocolError) as e:
            step_failed = "download"
            raise Exception(f"BBB API timeout or connection error: {str(e)}")
        except UrlExpiredError as e:
            step_failed = "download_url_expired"
            raise Exception(f"Recording URL expired: {str(e)}")

        try:
            # Step 2: Extract audio (20-35%)
            _progress("transcribing", 25)
            logger.info(f"[{job_id}] Extracting audio")
            audio_path = audio_proc.extract_audio_from_video(video_path)
            _progress("transcribing", 35)
        except Exception as e:
            step_failed = "audio_extraction"
            raise Exception(f"Audio extraction failed: {str(e)}")

        try:
            # Step 3: Transcribe using ApiTranscriptionService (35-70%)
            _progress("transcribing", 40)
            logger.info(f"[{job_id}] Transcribing")
            result = transcriber.transcribe(audio_path)
            transcript = result["text"]
            segments   = result.get("segments", [])
            formatted  = result.get("formatted", transcript)

            job.transcript = formatted
            job.transcription_provider = result.get("provider", "local")
            job.transcription_model    = result.get("model", "unknown")
            job.transcription_cost     = result.get("cost", 0.0)
            job.audio_duration_secs    = result.get("duration_secs", 0.0)
            job.chunk_count            = result.get("chunk_count", 1)

            # Store segments as JSON for the Moodle side to consume
            if segments:
                job.segments_json = json.dumps(segments)

            job.status = "processing_ai"
            _progress("processing_ai", 70)
            db.commit()
            logger.info(f"[{job_id}] Transcription complete ({len(transcript)} chars, "
                        f"provider={job.transcription_provider}, cost=${job.transcription_cost:.6f})")
        except Exception as e:
            step_failed = "transcription"
            raise Exception(f"Transcription failed: {str(e)}")

        try:
            # Step 4: Index transcript in ChromaDB (70-85%, non-fatal)
            _progress("processing_ai", 75)
            logger.info(f"[{job_id}] Indexing transcript")
            texts, metadatas, ids = doc_loader.process_transcript(
                transcript, request.session_id, request.course_id
            )
            if texts:
                vector_store.add_documents(request.course_id, texts, metadatas, ids)
            _progress("processing_ai", 85)
        except Exception as e:
            logger.warning(f"[{job_id}] ChromaDB indexing failed (non-fatal): {e}")

        try:
            # Step 5: Generate AI outputs (85-95%, non-fatal)
            _progress("processing_ai", 90)
            logger.info(f"[{job_id}] Generating summary, notes, quiz")
            job.summary = llm.generate_summary(transcript)
            job.notes   = llm.generate_notes(transcript)
            job.quiz    = llm.generate_quiz(transcript)
            _progress("processing_ai", 95)
        except Exception as e:
            logger.warning(f"[{job_id}] LLM generation failed (non-fatal): {e}")

        job.status       = "completed"
        job.progress_percent = 100
        job.completed_at = datetime.utcnow()
        db.commit()
        logger.info(f"[{job_id}] Completed")

        # Webhook notification
        call_webhook(job_id, db_url, {
            "event": "recording.completed",
            "job_id": job_id,
            "session_id": request.session_id,
            "course_id": request.course_id,
            "status": "completed",
        })

    except Exception as e:
        error_details = f"{str(e)}\n{traceback.format_exc()}"
        logger.error(f"[{job_id}] Failed at step {step_failed or 'unknown'}: {error_details}")

        try:
            job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()
            if job:
                job.status        = "failed"
                job.progress_percent = 0
                job.error_message = f"Step: {step_failed or 'unknown'}, Error: {str(e)}"
                db.commit()

                if step_failed:
                    notify_admin(job_id, str(e), request.session_id)
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
    cfg    = get_settings()
    job_id = str(uuid.uuid4())

    job = ProcessingJob(
        job_id        = job_id,
        session_id    = request.session_id,
        course_id     = request.course_id,
        recording_url = request.recording_url,
        title         = request.title or None,
        status        = "queued",
        progress_percent = 0,
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
        transcription_provider = cfg.transcription_provider,
        transcription_model    = cfg.transcription_model,
    )


@router.get("/status/{job_id}")
async def get_job_status(
    job_id: str,
    db:     Session = Depends(get_db),
    token:  str     = Depends(verify_token),
):
    job = db.query(ProcessingJob).filter(
        (ProcessingJob.job_id == job_id) | (ProcessingJob.session_id == job_id)
    ).first()
    if not job:
        raise HTTPException(status_code=404, detail="Job not found")

    # Parse segments_json if present
    segments = []
    if job.segments_json:
        try:
            segments = json.loads(job.segments_json)
        except (json.JSONDecodeError, TypeError):
            pass

    return {
        "job_id":           job.job_id,
        "session_id":       job.session_id,
        "status":           job.status,
        "title":            job.title or "",
        "progress_percent": job.progress_percent,
        "created_at":       job.created_at,
        "completed_at":     job.completed_at,
        "transcript":       job.transcript,
        "segments":         segments,
        "transcription": {
            "provider": job.transcription_provider or "local",
            "model":    job.transcription_model or "whisper-local",
            "cost":     job.transcription_cost or 0.0,
            "duration_secs": job.audio_duration_secs or 0.0,
            "chunk_count":   job.chunk_count or 1,
        },
        "outputs": {
            "summary":    job.summary,
            "notes":      job.notes,
            "quiz":       job.quiz,
        },
        "error": job.error_message,
    }


@router.post("/reprocess", response_model=ReprocessRecordingResponse)
async def reprocess_recording(
    request:          ProcessRecordingRequest,
    background_tasks: BackgroundTasks,
    db:               Session = Depends(get_db),
    token:            str     = Depends(verify_token),
):
    """Re-transcribe a recording with optional provider/model overrides.

    Use this endpoint when a lecturer wants to re-run transcription with
    a different provider (e.g. openai → openrouter) or model.

    The endpoint creates a new processing job, passing the override
    provider/model to the transcription service.
    """
    cfg    = get_settings()
    job_id = str(uuid.uuid4())

    # Resolve overrides: request level → config level default.
    prov_override  = request.transcription_provider or None
    model_override = request.transcription_model or None

    job = ProcessingJob(
        job_id        = job_id,
        session_id    = request.session_id,
        course_id     = request.course_id,
        recording_url = request.recording_url,
        title         = request.title or None,
        status        = "queued",
        progress_percent = 0,
    )
    db.add(job)
    db.commit()

    background_tasks.add_task(
        process_recording_background,
        job_id,
        request,
        cfg.database_url,
        prov_override,
        model_override,
    )

    return ReprocessRecordingResponse(
        job_id  = job_id,
        status  = "queued",
        message = "Re-transcription job queued",
        transcription_provider = prov_override or cfg.transcription_provider,
        transcription_model    = model_override or cfg.transcription_model,
    )
