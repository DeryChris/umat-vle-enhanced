import logging
import time
from datetime import datetime
from typing import Optional, Callable

from sqlalchemy import create_engine, or_
from sqlalchemy.orm import sessionmaker

from config import get_settings
from models.database import ProcessingJob

logger = logging.getLogger(__name__)
settings = get_settings()

# Status FSM: queued → downloading → transcribing → processing_ai → completed
# Any state → failed (on error)

RECOVERABLE_STATUSES = [
    "queued", "downloading", "transcribing", "processing_ai",
]

MAX_RETRY_AGE_HOURS = 24  # Don't retry jobs older than this


def recover_stuck_jobs(db_url: str) -> list[ProcessingJob]:
    """On startup, find any jobs stuck in a recoverable state and reset them.

    Returns list of recovered jobs that need their background task re-run.
    """
    engine = create_engine(db_url, pool_pre_ping=True, pool_recycle=3600)
    Session = sessionmaker(bind=engine)
    db = Session()

    try:
        cutoff = datetime.utcnow().timestamp() - (MAX_RETRY_AGE_HOURS * 3600)

        stuck = (
            db.query(ProcessingJob)
            .filter(
                ProcessingJob.status.in_(RECOVERABLE_STATUSES),
                ProcessingJob.completed_at.is_(None),
            )
            .all()
        )

        recovered = []
        for job in stuck:
            age_hours = (datetime.utcnow() - job.created_at).total_seconds() / 3600
            if age_hours > MAX_RETRY_AGE_HOURS:
                job.status = "failed"
                job.error_message = "Stuck — exceeded max retry age"
                logger.warning("[recover] Job %s too old (%.1f hrs), marking failed", job.job_id, age_hours)
            else:
                job.status = "queued"
                job.progress_percent = 0
                recovered.append(job)
                logger.info("[recover] Job %s reset to queued (was %s)", job.job_id, job._original_status)

        db.commit()
        return recovered
    finally:
        db.close()


def update_progress(job_id: str, db_url: str, status: str, percent: int) -> None:
    """Update job status and progress percent."""
    from sqlalchemy import create_engine
    from sqlalchemy.orm import sessionmaker

    engine = create_engine(db_url, pool_pre_ping=True, pool_recycle=3600)
    Session = sessionmaker(bind=engine)
    db = Session()

    try:
        job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()
        if job:
            job.status = status
            job.progress_percent = percent
            db.commit()
    except Exception as e:
        logger.error("[progress] Failed to update job %s: %s", job_id, e)
    finally:
        db.close()


def call_webhook(job_id: str, db_url: str, payload: dict) -> None:
    """Call the configured webhook URL on job completion.

    Falls back to logging if webhook call fails.
    """
    import httpx

    from sqlalchemy import create_engine
    from sqlalchemy.orm import sessionmaker

    engine = create_engine(db_url, pool_pre_ping=True, pool_recycle=3600)
    Session = sessionmaker(bind=engine)
    db = Session()

    try:
        job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()
        if not job or not job.webhook_url:
            return

        try:
            with httpx.Client(timeout=15.0) as client:
                resp = client.post(
                    job.webhook_url,
                    json=payload,
                    headers={"Content-Type": "application/json"},
                )
                logger.info(
                    "[webhook] Called %s → %d for job %s",
                    job.webhook_url, resp.status_code, job_id,
                )
        except Exception as e:
            logger.warning("[webhook] Failed to call %s: %s", job.webhook_url, e)
    finally:
        db.close()