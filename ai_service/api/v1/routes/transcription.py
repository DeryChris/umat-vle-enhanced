# ============================================================
# POST /api/v1/transcription/transcribe — fast chat voice transcription
# POST /api/v1/transcription/upload     — upload audio/video for transcription
# GET  /api/v1/transcription/{job_id}   — get transcript + outputs
# GET  /api/v1/transcription/{job_id}/flashcards — generate flashcards
# GET  /api/v1/transcription/{job_id}/glossary  — generate glossary
# GET  /api/v1/transcription/{job_id}/chapters  — generate chapter markers
# GET  /api/v1/transcription/{job_id}/export    — export transcript (txt/pdf/docx)
# WS   /api/v1/transcription/live               — live transcription stream
# ============================================================

import os
import uuid
import json
import asyncio
import logging
import traceback
from datetime import datetime
from pathlib import Path
from typing import Optional

from fastapi import APIRouter, Depends, BackgroundTasks, HTTPException, UploadFile, File, Form, WebSocket, WebSocketDisconnect
from fastapi.responses import PlainTextResponse, Response
from sqlalchemy.orm import Session
from sqlalchemy import create_engine, text as sql_text
from sqlalchemy.orm import sessionmaker

from models.schemas import ProcessRecordingResponse
from models.database import get_db, ProcessingJob
from middleware.auth import verify_token
from core.audio_processor import AudioProcessor
from core.transcription import TranscriptionService
from core.document_loader import DocumentLoader
from core.vector_store import VectorStoreManager
from core.llm_processor import LLMProcessor, _make_llm
from config import get_settings

router = APIRouter(prefix="/transcription", tags=["transcription"])
logger = logging.getLogger(__name__)

settings = get_settings()

ALLOWED_EXTENSIONS = {
    ".mp3", ".wav", ".m4a", ".ogg", ".flac", ".aac", ".wma",
    ".mp4", ".webm", ".mov", ".avi", ".mkv", ".m4v",
}
MAX_FILE_SIZE = 500 * 1024 * 1024  # 500 MB

# Singleton transcriber for fast chat transcription (avoids re-init per request)
_chat_transcriber: Optional[TranscriptionService] = None


def _get_chat_transcriber() -> TranscriptionService:
    global _chat_transcriber
    if _chat_transcriber is None:
        _chat_transcriber = TranscriptionService()
    return _chat_transcriber


# ── Fast chat transcription (sync, no background) ────────────

@router.post("/transcribe")
async def transcribe_audio(
    file: UploadFile = File(...),
    token: str = Depends(verify_token),
):
    """Fast synchronous transcription for chat voice clips.

    Accepts an audio file, runs Whisper with lightweight settings,
    and returns the transcript immediately. No indexing, no LLM.
    """
    import time
    t_start = time.time()

    ext = Path(file.filename).suffix.lower().lstrip(".") if file.filename else "webm"
    if ext not in {"webm", "ogg", "wav", "mp3", "mp4", "m4a", "flac", "aac"}:
        raise HTTPException(status_code=400, detail=f"Unsupported format: {ext}")

    content = await file.read()
    if len(content) < 512:
        raise HTTPException(status_code=400, detail="Audio too short")
    if len(content) > 25 * 1024 * 1024:
        raise HTTPException(status_code=413, detail="Audio too large (max 25 MB)")

    t_read = time.time()

    # Write to temp file for Whisper (it needs a file path)
    import tempfile
    with tempfile.NamedTemporaryFile(suffix=f".{ext}", delete=False) as tmp:
        tmp.write(content)
        tmp_path = tmp.name

    try:
        transcriber = _get_chat_transcriber()
        t_before_whisper = time.time()
        result = transcriber.transcribe_chat(tmp_path)
        t_after_whisper = time.time()

        logger.info(
            "Chat transcription: read=%.1fms whisper=%.1fms total=%.1fms chars=%d",
            (t_before_whisper - t_read) * 1000,
            (t_after_whisper - t_before_whisper) * 1000,
            (t_after_whisper - t_start) * 1000,
            len(result.get("text", "")),
        )

        return {
            "success": True,
            "transcript": result.get("text", ""),
            "language": result.get("language", "en"),
        }
    finally:
        try:
            os.unlink(tmp_path)
        except OSError:
            pass


# ── Background processing pipeline ────────────────────────────

def _process_upload_background(job_id: str, file_path: str, filename: str, db_url: str):
    """Background task: extract audio → transcribe → index → generate AI outputs."""
    engine = create_engine(db_url, pool_pre_ping=True, pool_recycle=3600)
    SessionLocal = sessionmaker(bind=engine)
    db = SessionLocal()

    audio_proc = AudioProcessor()
    transcriber = TranscriptionService()
    doc_loader = DocumentLoader()
    vector_store = VectorStoreManager()
    llm = LLMProcessor()

    audio_path = None
    step_failed = None

    try:
        job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()
        if not job:
            logger.error(f"[{job_id}] Job not found in database")
            return

        ext = Path(filename).suffix.lower()

        # Step 1: Extract audio (skip if already an audio file)
        try:
            job.status = "transcribing"
            db.commit()
            if ext in (".mp3", ".wav", ".m4a", ".ogg", ".flac", ".aac", ".wma"):
                # Audio file — convert to WAV for Whisper
                audio_path = audio_proc.extract_audio_from_video(file_path)
            else:
                # Video file — extract audio track
                audio_path = audio_proc.extract_audio_from_video(file_path)
            logger.info(f"[{job_id}] Audio extracted: {audio_path}")
        except Exception as e:
            step_failed = "audio_extraction"
            raise Exception(f"Audio extraction failed: {str(e)}")

        # Step 2: Transcribe
        try:
            logger.info(f"[{job_id}] Transcribing with Whisper")
            result = transcriber.transcribe_audio(audio_path)
            transcript = result["text"]
            segments = result.get("segments", [])

            # Store both formatted text and raw segments JSON
            formatted = transcriber.format_transcript_with_timestamps(segments)
            job.transcript = formatted
            job.segments_json = json.dumps(segments)
            job.status = "processing_ai"
            db.commit()
            logger.info(f"[{job_id}] Transcription complete: {len(transcript)} chars, {len(segments)} segments")
        except Exception as e:
            step_failed = "transcription"
            raise Exception(f"Transcription failed: {str(e)}")

        # Step 3: Index transcript in ChromaDB
        try:
            logger.info(f"[{job_id}] Indexing transcript in ChromaDB")
            session_id = job.session_id
            course_id = job.course_id
            texts, metadatas, ids = doc_loader.process_transcript(transcript, session_id, course_id)
            if texts:
                vector_store.add_documents(course_id, texts, metadatas, ids)
                logger.info(f"[{job_id}] Indexed {len(texts)} chunks")
        except Exception as e:
            step_failed = "chromadb_indexing"
            logger.warning(f"[{job_id}] ChromaDB indexing failed (non-fatal): {e}")

        # Step 4: Generate AI outputs (optional — may fail if LLM quota exhausted)
        try:
            logger.info(f"[{job_id}] Generating summary, notes, quiz")
            job.summary = llm.generate_summary(transcript)
            job.notes = llm.generate_notes(transcript)
            job.quiz = llm.generate_quiz(transcript)
        except Exception as e:
            step_failed = "llm_generation"
            logger.warning(f"[{job_id}] LLM generation failed (non-fatal): {e}")
            # Don't fail the whole job — transcript is still valuable

        job.status = "completed"
        job.completed_at = datetime.utcnow()
        db.commit()
        logger.info(f"[{job_id}] Processing completed successfully")

    except Exception as e:
        error_details = f"{str(e)}\n{traceback.format_exc()}"
        logger.error(f"[{job_id}] Failed at step {step_failed or 'unknown'}: {error_details}")
        try:
            job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()
            if job:
                job.status = "failed"
                job.error_message = f"Step: {step_failed or 'unknown'}, Error: {str(e)}"
                db.commit()
        except Exception:
            pass

    finally:
        try:
            audio_proc.cleanup(file_path, audio_path)
        except Exception:
            pass
        try:
            db.close()
        except Exception:
            pass


# ── Upload endpoint ───────────────────────────────────────────

@router.post("/upload", response_model=ProcessRecordingResponse)
async def upload_for_transcription(
    background_tasks: BackgroundTasks,
    file: UploadFile = File(...),
    course_id: int = Form(...),
    title: str = Form(default=""),
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    """Upload an audio/video file for transcription, indexing, and AI analysis."""
    # Validate extension
    ext = Path(file.filename).suffix.lower()
    if ext not in ALLOWED_EXTENSIONS:
        raise HTTPException(
            status_code=400,
            detail=f"Unsupported file type: {ext}. Allowed: {', '.join(sorted(ALLOWED_EXTENSIONS))}",
        )

    # Read and validate size
    content = await file.read()
    if len(content) > MAX_FILE_SIZE:
        raise HTTPException(status_code=413, detail="File too large. Maximum 500 MB.")

    # Save to disk
    upload_dir = Path(settings.upload_dir)
    upload_dir.mkdir(exist_ok=True)
    saved_name = f"{uuid.uuid4()}{ext}"
    file_path = str(upload_dir / saved_name)
    with open(file_path, "wb") as f:
        f.write(content)

    # Create processing job
    job_id = str(uuid.uuid4())
    session_id = f"upload_{uuid.uuid4().hex[:12]}"

    job = ProcessingJob(
        job_id=job_id,
        session_id=session_id,
        course_id=course_id,
        recording_url=None,  # No URL — uploaded file
        status="queued",
    )
    db.add(job)
    db.commit()

    logger.info(f"[{job_id}] File uploaded: {file.filename} ({len(content)} bytes) → course {course_id}")

    # Launch background processing
    background_tasks.add_task(
        _process_upload_background,
        job_id,
        file_path,
        file.filename,
        settings.database_url,
    )

    return ProcessRecordingResponse(
        job_id=job_id,
        status="queued",
        message=f"Transcription started for '{title or file.filename}'",
    )


# ── Status / Transcript retrieval ─────────────────────────────

@router.get("/{job_id}")
async def get_transcription(
    job_id: str,
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    """Get transcription status, transcript, segments JSON, and AI outputs."""
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
        "job_id": job.job_id,
        "session_id": job.session_id,
        "course_id": job.course_id,
        "status": job.status,
        "progress_percent": job.progress_percent,
        "transcript": job.transcript,
        "segments": segments,
        "outputs": {
            "summary": job.summary,
            "notes": job.notes,
            "quiz": job.quiz,
        },
        "error": job.error_message,
        "created_at": job.created_at.isoformat() if job.created_at else None,
        "completed_at": job.completed_at.isoformat() if job.completed_at else None,
    }


# ── Flashcard generation ──────────────────────────────────────

@router.get("/{job_id}/flashcards")
async def get_flashcards(
    job_id: str,
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    """Generate flashcards from a completed transcript."""
    job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()
    if not job:
        raise HTTPException(status_code=404, detail="Job not found")
    if job.status != "completed" or not job.transcript:
        raise HTTPException(status_code=400, detail="Transcript not ready yet")

    prompt = f"""Based on the following lecture transcript, generate 10-15 flashcards for study review.

For each flashcard, provide:
- "front": The question or term (concise, clear)
- "back": The answer or definition (2-4 sentences max)
- "category": A topic label (e.g., "Key Concept", "Definition", "Process")

Cover a mix of: key concepts, definitions, processes, and important facts.

Return ONLY a JSON array of flashcard objects, no other text.
Example: [{{"front": "What is X?", "back": "X is...", "category": "Definition"}}]

Lecture transcript:
{job.transcript[:8000]}"""

    try:
        response = _make_llm(temperature=0.3).invoke(prompt)
        raw = response.content.strip()
        # Extract JSON from markdown code blocks if present
        if "```" in raw:
            raw = raw.split("```")[1]
            if raw.startswith("json"):
                raw = raw[4:]
            raw = raw.strip()
        cards = json.loads(raw)
        return {"flashcards": cards, "count": len(cards)}
    except Exception as e:
        logger.error(f"Flashcard generation failed: {e}")
        raise HTTPException(status_code=500, detail="Failed to generate flashcards")


# ── Glossary generation ───────────────────────────────────────

@router.get("/{job_id}/glossary")
async def get_glossary(
    job_id: str,
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    """Generate a glossary of key terms from a completed transcript."""
    job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()
    if not job:
        raise HTTPException(status_code=404, detail="Job not found")
    if job.status != "completed" or not job.transcript:
        raise HTTPException(status_code=400, detail="Transcript not ready yet")

    prompt = f"""Based on the following lecture transcript, extract a glossary of key terms and concepts.

For each entry provide:
- "term": The key term or concept name
- "definition": Clear, concise definition (1-3 sentences)
- "importance": "core", "important", or "supplementary"

Include 10-20 entries. Focus on domain-specific terminology and concepts that a student would need to know.
Return ONLY a JSON array, no other text.
Example: [{{"term": "Photosynthesis", "definition": "The process by which plants convert light energy...", "importance": "core"}}]

Lecture transcript:
{job.transcript[:8000]}"""

    try:
        response = _make_llm(temperature=0.2).invoke(prompt)
        raw = response.content.strip()
        if "```" in raw:
            raw = raw.split("```")[1]
            if raw.startswith("json"):
                raw = raw[4:]
            raw = raw.strip()
        entries = json.loads(raw)
        return {"glossary": entries, "count": len(entries)}
    except Exception as e:
        logger.error(f"Glossary generation failed: {e}")
        raise HTTPException(status_code=500, detail="Failed to generate glossary")


# ── Chapter markers ───────────────────────────────────────────

@router.get("/{job_id}/chapters")
async def get_chapters(
    job_id: str,
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    """Segment a transcript into logical chapters with timestamps."""
    job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()
    if not job:
        raise HTTPException(status_code=404, detail="Job not found")
    if job.status != "completed" or not job.transcript:
        raise HTTPException(status_code=400, detail="Transcript not ready yet")

    prompt = f"""Based on the following timestamped lecture transcript, segment it into logical chapters.

For each chapter provide:
- "title": Descriptive chapter title
- "start_time": Timestamp string like "00:00" or "05:30"
- "summary": 1-2 sentence summary of what this chapter covers
- "key_points": Array of 2-4 key points

Aim for 3-8 chapters depending on content length. Each chapter should cover a distinct topic or section.
Return ONLY a JSON array, no other text.

Timestamped transcript:
{job.transcript[:8000]}"""

    try:
        response = _make_llm(temperature=0.2).invoke(prompt)
        raw = response.content.strip()
        if "```" in raw:
            raw = raw.split("```")[1]
            if raw.startswith("json"):
                raw = raw[4:]
            raw = raw.strip()
        chapters = json.loads(raw)
        return {"chapters": chapters, "count": len(chapters)}
    except Exception as e:
        logger.error(f"Chapter generation failed: {e}")
        raise HTTPException(status_code=500, detail="Failed to generate chapters")


# ── Export transcript ─────────────────────────────────────────

@router.get("/{job_id}/export")
async def export_transcript(
    job_id: str,
    format: str = "txt",
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    """Export transcript as TXT, or structured JSON with all outputs."""
    job = db.query(ProcessingJob).filter(ProcessingJob.job_id == job_id).first()
    if not job:
        raise HTTPException(status_code=404, detail="Job not found")
    if not job.transcript:
        raise HTTPException(status_code=400, detail="Transcript not available")

    if format == "json":
        data = {
            "transcript": job.transcript,
            "summary": job.summary,
            "notes": job.notes,
            "quiz": job.quiz,
            "course_id": job.course_id,
            "session_id": job.session_id,
            "created_at": job.created_at.isoformat() if job.created_at else None,
        }
        return Response(
            content=json.dumps(data, indent=2),
            media_type="application/json",
            headers={"Content-Disposition": f'attachment; filename="transcript_{job_id}.json"'},
        )

    # Default: plain text
    content = f"=== Lecture Transcript ===\nSession: {job.session_id}\nCourse: {job.course_id}\n\n{job.transcript}"
    if job.summary:
        content += f"\n\n=== Summary ===\n\n{job.summary}"
    if job.notes:
        content += f"\n\n=== Notes ===\n\n{job.notes}"

    return Response(
        content=content,
        media_type="text/plain",
        headers={"Content-Disposition": f'attachment; filename="transcript_{job_id}.txt"'},
    )


# ── Live transcription WebSocket ──────────────────────────────

class LiveTranscriptionManager:
    """Manages active live transcription sessions."""

    def __init__(self):
        self.active_sessions: dict = {}  # session_id -> {ws, buffer, segments}

    async def handle_audio_chunk(self, session_id: str, audio_data: bytes, ws: WebSocket):
        """Process a chunk of audio data for live transcription."""
        import tempfile
        import subprocess

        if session_id not in self.active_sessions:
            self.active_sessions[session_id] = {
                "ws": ws,
                "buffer": bytearray(),
                "segments": [],
                "chunk_count": 0,
            }

        session = self.active_sessions[session_id]
        session["buffer"].extend(audio_data)
        session["chunk_count"] += 1

        # Process every 5 seconds of accumulated audio
        buffer_threshold = 16000 * 5 * 2  # 5 seconds * 16kHz * 2 bytes/sample
        if len(session["buffer"]) >= buffer_threshold:
            try:
                # Write buffer to temp WAV file
                with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
                    tmp.write(session["buffer"])
                    tmp_path = tmp.name

                # Transcribe the chunk
                transcriber = TranscriptionService()
                result = transcriber.transcribe_audio(tmp_path)
                segment_text = result.get("text", "").strip()

                if segment_text:
                    chunk_num = session["chunk_count"]
                    start_sec = max(0, (chunk_num - 5) * 5)
                    segment = {
                        "start": start_sec,
                        "text": segment_text,
                        "chunk": chunk_num,
                    }
                    session["segments"].append(segment)

                    # Send to client
                    await ws.send_json({
                        "type": "transcript_segment",
                        "data": segment,
                    })

                # Clean up
                os.unlink(tmp_path)
                session["buffer"] = bytearray()

            except Exception as e:
                logger.error(f"Live transcription chunk error: {e}")
                try:
                    await ws.send_json({"type": "error", "message": str(e)})
                except Exception:
                    pass

    async def finalize_session(self, session_id: str):
        """Finalize a live session — compile full transcript."""
        session = self.active_sessions.pop(session_id, None)
        if not session:
            return None

        # Process any remaining buffer
        if session["buffer"] and len(session["buffer"]) > 16000:
            try:
                import tempfile
                with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
                    tmp.write(session["buffer"])
                    tmp_path = tmp.name
                transcriber = TranscriptionService()
                result = transcriber.transcribe_audio(tmp_path)
                text = result.get("text", "").strip()
                if text:
                    session["segments"].append({
                        "start": len(session["segments"]) * 5,
                        "text": text,
                        "chunk": session["chunk_count"],
                    })
                os.unlink(tmp_path)
            except Exception:
                pass

        full_transcript = "\n".join(
            f"[{s['start']//60:02d}:{s['start']%60:02d}] {s['text']}"
            for s in session["segments"]
        )
        return {
            "segments": session["segments"],
            "full_transcript": full_transcript,
            "segment_count": len(session["segments"]),
        }


_live_manager = LiveTranscriptionManager()


@router.websocket("/live/{session_id}")
async def live_transcription_ws(websocket: WebSocket, session_id: str):
    """WebSocket endpoint for real-time transcription.

    Client sends binary audio chunks (16-bit PCM, 16kHz, mono).
    Server responds with JSON transcript segments as they are processed.
    """
    await websocket.accept()
    logger.info(f"Live transcription started: {session_id}")

    try:
        while True:
            data = await websocket.receive()

            if data["type"] == "websocket.receive":
                if "bytes" in data and data["bytes"]:
                    await _live_manager.handle_audio_chunk(session_id, data["bytes"], websocket)
                elif "text" in data:
                    # Control messages (JSON)
                    try:
                        msg = json.loads(data["text"])
                        if msg.get("type") == "stop":
                            break
                        elif msg.get("type") == "ping":
                            await websocket.send_json({"type": "pong"})
                    except json.JSONDecodeError:
                        pass

    except WebSocketDisconnect:
        logger.info(f"Live transcription disconnected: {session_id}")
    except Exception as e:
        logger.error(f"Live transcription error: {e}")
    finally:
        # Finalize and send full transcript
        result = await _live_manager.finalize_session(session_id)
        if result:
            try:
                await websocket.send_json({
                    "type": "completed",
                    "data": result,
                })
            except Exception:
                pass
        logger.info(f"Live transcription finalized: {session_id}")


# ── List recent transcriptions ────────────────────────────────

@router.get("/list/{course_id}")
async def list_transcriptions(
    course_id: int,
    limit: int = 20,
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    """List recent transcription jobs for a course."""
    jobs = (
        db.query(ProcessingJob)
        .filter(ProcessingJob.course_id == course_id)
        .order_by(ProcessingJob.created_at.desc())
        .limit(limit)
        .all()
    )
    return {
        "jobs": [
            {
                "job_id": j.job_id,
                "session_id": j.session_id,
                "status": j.status,
                "transcript_length": len(j.transcript) if j.transcript else 0,
                "has_summary": bool(j.summary),
                "has_notes": bool(j.notes),
                "has_quiz": bool(j.quiz),
                "error": j.error_message,
                "created_at": j.created_at.isoformat() if j.created_at else None,
                "completed_at": j.completed_at.isoformat() if j.completed_at else None,
            }
            for j in jobs
        ],
        "count": len(jobs),
    }
