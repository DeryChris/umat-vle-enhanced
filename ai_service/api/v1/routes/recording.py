# ============================================================
# POST /api/v1/recording/process  — submit a BBB recording for processing
# GET  /api/v1/recording/status/{job_id} — check job status
# POST /api/v1/recording/{session_id}/retranscribe — re-run failed jobs
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

from models.schemas import ProcessRecordingRequest, ProcessRecordingResponse
from models.database import get_db, ProcessingJob
from middleware.auth import verify_token
from core.audio_processor import AudioProcessor, UrlExpiredError
from core.transcription import TranscriptionService, BudgetExceededError
from core.document_loader import DocumentLoader
from core.vector_store import VectorStoreManager
from core.llm_processor import LLMProcessor
from core.job_queue import update_progress, call_webhook

router = APIRouter(prefix="/recording", tags=["recording"])
logger = logging.getLogger(__name__)

ADMIN_EMAIL = "admin@umat.edu.gh"


def notify_admin(job_id: str, error: str, session_id: str):
    logger.critical(
        f"[ADMIN ALERT] Recording processing job {job_id} failed.\n"
        f"Session: {session_id}\n"
        f"Error: {error}\n"
    )


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

    engine       = create_engine(db_url, pool_pre_ping=True, pool_recycle=3600)
    SessionLocal = sessionmaker(bind=engine)
    db           = SessionLocal()

    audio_proc   = AudioProcessor()
    transcriber  = TranscriptionService()
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

        # Step 1: Download (0-20%)
        _progress("downloading", 5)
        logger.info(f"[{job_id}] Downloading from {request.recording_url}")
        try:
            video_path = audio_proc.download_recording(request.recording_url)
            _progress("downloading", 20)
        except (httpx.TimeoutException, httpx.ConnectError, httpx.RemoteProtocolError) as e:
            step_failed = "download"
            raise Exception(f"BBB API timeout or connection error: {str(e)}")
        except UrlExpiredError as e:
            step_failed = "download_url_expired"
            raise Exception(f"Recording URL expired: {str(e)}")

        # Step 2: Extract audio (20-35%)
        _progress("transcribing", 25)
        logger.info(f"[{job_id}] Extracting audio")
        try:
            audio_path = audio_proc.extract_audio_from_video(video_path)
            _progress("transcribing", 35)
        except Exception as e:
            step_failed = "audio_extraction"
            raise Exception(f"Audio extraction failed: {str(e)}")

        # Step 3: Transcribe (35-70%)
        _progress("transcribing", 40)
        logger.info(f"[{job_id}] Transcribing")
        try:
            result     = transcriber.transcribe_audio(audio_path)
            transcript = result["text"]
            segments   = result.get("segments", [])
            job.transcript     = transcript
            job.segments_json  = json.dumps(segments)
            _progress("processing_ai", 70)
            db.commit()
        except BudgetExceededError:
            step_failed = "budget_exceeded"
            raise
        except Exception as e:
            step_failed = "transcription"
            raise Exception(f"Transcription failed: {str(e)}")

        # Step 4: Index transcript in ChromaDB (70-85%)
        _progress("processing_ai", 75)
        try:
            logger.info(f"[{job_id}] Indexing transcript")
            texts, metadatas, ids = doc_loader.process_transcript(
                transcript, request.session_id, request.course_id
            )
            if texts:
                vector_store.add_documents(request.course_id, texts, metadatas, ids)
            _progress("processing_ai", 85)
        except Exception as e:
            logger.warning(f"[{job_id}] ChromaDB indexing failed (non-fatal): {e}")

        # Step 5: Generate AI outputs (85-95%)
        _progress("processing_ai", 90)
        try:
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

    segments = []
    if job.segments_json:
        try:
            segments = json.loads(job.segments_json)
        except (json.JSONDecodeError, TypeError):
            pass

    return {
        "job_id":         job.job_id,
        "session_id":     job.session_id,
        "status":         job.status,
        "progress_percent": job.progress_percent,
        "created_at":     job.created_at,
        "completed_at":   job.completed_at,
        "transcript":     job.transcript,
        "segments":       segments,
        "outputs": {
            "summary":    job.summary,
            "notes":      job.notes,
            "quiz":       job.quiz,
        },
        "error":          job.error_message,
    }


@router.post("/{session_id}/retranscribe", response_model=ProcessRecordingResponse)
async def retranscribe_recording(
    session_id: str,
    background_tasks: BackgroundTasks,
    db:     Session = Depends(get_db),
    token:  str     = Depends(verify_token),
):
    """Re-run transcription for a failed or completed session.

    Clears existing transcript and outputs, then re-submits for processing.
    """
    job = (
        db.query(ProcessingJob)
        .filter(ProcessingJob.session_id == session_id)
        .order_by(ProcessingJob.created_at.desc())
        .first()
    )
    if not job:
        raise HTTPException(status_code=404, detail="No job found for this session")

    if not job.recording_url:
        raise HTTPException(status_code=400, detail="No recording URL to retranscribe")

    from models.schemas import ProcessRecordingRequest

    new_job_id = str(uuid.uuid4())
    new_job = ProcessingJob(
        job_id        = new_job_id,
        session_id    = session_id,
        course_id     = job.course_id,
        recording_url = job.recording_url,
        status        = "queued",
        progress_percent = 0,
    )
    db.add(new_job)
    db.commit()

    request = ProcessRecordingRequest(
        session_id    = session_id,
        recording_url = job.recording_url,
        course_id     = job.course_id,
        material_ids  = [],
    )

    from config import get_settings
    cfg = get_settings()

    background_tasks.add_task(
        process_recording_background,
        new_job_id,
        request,
        cfg.database_url,
    )

    return ProcessRecordingResponse(
        job_id  = new_job_id,
        status  = "queued",
        message = f"Retranscription started for session {session_id}",
    )