from fastapi import APIRouter, Depends, BackgroundTasks, HTTPException
from sqlalchemy.orm import Session
from models.schemas import VideoGenerateRequest, VideoGenerateResponse, VideoStatusResponse
from models.database import get_db, VideoJob
from middleware.auth import verify_token
import uuid
import asyncio
import logging
import os
from datetime import datetime
from pathlib import Path
from config import get_settings

settings = get_settings()
router = APIRouter(prefix="/video", tags=["video"])
logger = logging.getLogger(__name__)


def run_video_pipeline_sync(
    job_id: str,
    material_id: int,
    course_id: int,
    file_content_b64: str,
    filename: str,
    db_url: str,
):
    from sqlalchemy import create_engine
    from sqlalchemy.orm import sessionmaker
    from models.database import VideoJob
    import os, base64, tempfile

    engine = create_engine(db_url, pool_pre_ping=True, pool_recycle=3600)
    SessionLocal = sessionmaker(bind=engine)
    db = SessionLocal()

    try:
        job = db.query(VideoJob).filter(VideoJob.job_id == job_id).first()
        if not job:
            logger.error(f"Job {job_id} not found in background task")
            return

        def update_progress(pct: int, msg: str):
            job.progress = pct
            job.status_message = msg
            db.commit()

        job.status = "processing"
        job.progress = 0
        job.status_message = "Decoding file"
        db.commit()

        # Decode base64 file content.
        update_progress(5, "Decoding file data")
        file_bytes = base64.b64decode(file_content_b64)

        tmpdir = tempfile.mkdtemp(prefix="umat_video_")
        local_path = os.path.join(tmpdir, filename or f"material_{material_id}")
        with open(local_path, "wb") as f:
            f.write(file_bytes)

        logger.info(f"Decoded {len(file_bytes)} bytes to {local_path}")

        from core.video_processor import generate_video_pipeline

        video_path = asyncio.run(generate_video_pipeline(
            material_id=material_id,
            course_id=course_id,
            file_path=local_path,
            filename=filename,
            progress_callback=update_progress,
        ))

        job.status = "completed"
        job.progress = 100
        job.status_message = "Video ready"
        job.video_path = video_path
        job.completed_at = datetime.utcnow()
        db.commit()

        logger.info(f"Video job {job_id} completed: {video_path}")

    except Exception as e:
        logger.exception(f"Video job {job_id} failed")
        job = db.query(VideoJob).filter(VideoJob.job_id == job_id).first()
        if job:
            job.status = "failed"
            job.error_message = str(e)[:500]
            job.completed_at = datetime.utcnow()
            db.commit()
    finally:
        db.close()


@router.post("/generate", response_model=VideoGenerateResponse)
async def generate_video(
    req: VideoGenerateRequest,
    background_tasks: BackgroundTasks,
    db: Session = Depends(get_db),
    _=Depends(verify_token),
):
    job_id = str(uuid.uuid4())

    job = VideoJob(
        job_id=job_id,
        material_id=req.material_id,
        course_id=req.course_id,
        filename=req.filename,
        status="queued",
        progress=0,
        status_message="Queued",
    )
    db.add(job)
    db.commit()

    background_tasks.add_task(
        run_video_pipeline_sync,
        job_id=job_id,
        material_id=req.material_id,
        course_id=req.course_id,
        file_content_b64=req.file_content,
        filename=req.filename,
        db_url=settings.database_url,
    )

    return VideoGenerateResponse(
        job_id=job_id,
        status="queued",
        message="Video generation queued"
    )


@router.get("/status/{job_id}", response_model=VideoStatusResponse)
async def video_status(
    job_id: str,
    db: Session = Depends(get_db),
    _=Depends(verify_token),
):
    job = db.query(VideoJob).filter(VideoJob.job_id == job_id).first()
    if not job:
        raise HTTPException(status_code=404, detail="Job not found")

    video_url = None
    if job.video_path and os.path.exists(job.video_path if isinstance(job.video_path, str) else str(job.video_path)):
        from fastapi.responses import FileResponse
        video_url = f"/api/v1/video/file/{job.job_id}"

    return VideoStatusResponse(
        job_id=job.job_id,
        status=job.status,
        progress=job.progress,
        status_message=job.status_message,
        video_url=video_url,
        error=job.error_message,
        created_at=job.created_at.isoformat() if job.created_at else None,
        completed_at=job.completed_at.isoformat() if job.completed_at else None,
    )


import os as os_mod


@router.get("/file/{job_id}")
async def video_file(
    job_id: str,
    db: Session = Depends(get_db),
):
    job = db.query(VideoJob).filter(VideoJob.job_id == job_id).first()
    if not job or not job.video_path:
        raise HTTPException(status_code=404, detail="Video not found")

    vpath = str(job.video_path)
    if not os_mod.path.exists(vpath):
        raise HTTPException(status_code=404, detail="Video file not found on disk")

    from fastapi.responses import FileResponse
    return FileResponse(
        vpath,
        media_type="video/mp4",
        filename=f"lecture_video_{job.filename or job_id}.mp4"
    )
