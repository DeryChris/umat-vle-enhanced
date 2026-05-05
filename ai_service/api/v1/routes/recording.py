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

router = APIRouter(prefix="/recording", tags=["recording"])
logger = logging.getLogger(__name__)


def process_recording_background(
    job_id:  str,
    request: ProcessRecordingRequest,
    db_url:  str,
):
    """Background task: download → extract audio → transcribe → index → generate AI outputs."""
    from sqlalchemy import create_engine
    from sqlalchemy.orm import sessionmaker
    from models.database import ProcessingJob

    # NOTE: Creating a new engine per background task is acceptable but not optimal.
    # A shared engine with pool_pre_ping=True would be preferred for production.
    engine       = create_engine(db_url)
    SessionLocal = sessionmaker(bind=engine)
    db           = SessionLocal()

    audio_proc  = AudioProcessor()
    transcriber = TranscriptionService()
    doc_loader  = DocumentLoader()
    vector_store = VectorStoreManager()
    llm          = LLMProcessor()

    video_path = None
    audio_path = None

    try:
        job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()

        # Step 1: Download
        job.status = "downloading"
        db.commit()
        logger.info(f"[{job_id}] Downloading from {request.recording_url}")
        video_path = audio_proc.download_recording(request.recording_url)

        # Step 2: Extract audio
        job.status = "transcribing"
        db.commit()
        logger.info(f"[{job_id}] Extracting audio")
        audio_path = audio_proc.extract_audio_from_video(video_path)

        # Step 3: Transcribe
        logger.info(f"[{job_id}] Transcribing")
        result     = transcriber.transcribe_audio(audio_path)
        transcript = result["text"]
        job.transcript = transcript
        job.status     = "processing_ai"
        db.commit()

        # Step 4: Index transcript in ChromaDB
        logger.info(f"[{job_id}] Indexing transcript")
        texts, metadatas, ids = doc_loader.process_transcript(
            transcript, request.session_id, request.course_id
        )
        if texts:
            vector_store.add_documents(request.course_id, texts, metadatas, ids)

        # Step 5: Generate AI outputs
        logger.info(f"[{job_id}] Generating summary, notes, quiz")
        llm.generate_summary(transcript)
        llm.generate_notes(transcript)
        llm.generate_quiz(transcript)
        # TODO: store outputs in umat_ai_db and push to Moodle via callback or sync task

        job.status       = "completed"
        job.completed_at = datetime.utcnow()
        db.commit()
        logger.info(f"[{job_id}] Completed")

    except Exception as e:
        logger.error(f"[{job_id}] Failed: {str(e)}")
        job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()
        if job:
            job.status        = "failed"
            job.error_message = str(e)
            db.commit()

    finally:
        audio_proc.cleanup(video_path, audio_path)
        db.close()


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