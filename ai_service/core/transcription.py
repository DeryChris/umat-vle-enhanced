# ============================================================
# Whisper speech-to-text transcription
#
# Three modes:
#   1. Cloud (budget):  gpt-4o-mini-transcribe — $0.0006/min  ← DEFAULT
#   2. Cloud (premium): gpt-4o-transcribe / diarize — $0.006-0.012/min
#   3. Local (free):    openai-whisper on CPU — $0.00 (but slow)
#
# Cloud is used when OPENAI_WHISPER_API_KEY is set in .env.
# Local is the fallback when no API key or when budget is exceeded.
#
# Cost optimizations built-in:
#   - VAD pre-processing removes silent sections before API call
#   - Budget cap via OPENAI_WHISPER_BUDGET_USD
#   - Cost tracking per-transcription logged + persisted
#   - Default model is 10x cheaper than the standard model
#
# Install (local mode only):
#   pip install openai-whisper
#   Also requires ffmpeg installed and on PATH.
# ============================================================

import json
import os
import uuid
import time
import logging
from pathlib import Path
from typing import Optional

from config import get_settings

logger = logging.getLogger(__name__)
settings = get_settings()

_whisper_model = None  # Loaded once (local mode only)

CLOUD_API_TIMEOUT = 600  # 10 min max for long recordings
MAX_FILE_SIZE_CLOUD = 25 * 1024 * 1024  # 25 MB OpenAI API limit

# Whisper per-minute pricing (USD) — updated 2026
WHISPER_PRICING = {
    "gpt-4o-mini-transcribe": 0.0006,
    "gpt-4o-transcribe": 0.006,
    "gpt-4o-transcribe-diarize": 0.012,
    "whisper-1": 0.006,
}


def get_whisper_model():
    """Load the local Whisper model (only used in local fallback mode)."""
    global _whisper_model
    if _whisper_model is None:
        import whisper
        print(f"Loading local Whisper model: {settings.whisper_model}")
        _whisper_model = whisper.load_model(settings.whisper_model)
        print("Local Whisper model loaded.")
    return _whisper_model


# ── Budget & Cost Tracking ────────────────────────────────────

def _load_cost_tracker() -> dict:
    """Load accumulated cost from local tracker file."""
    path = Path(settings.openai_whisper_cost_tracker_path)
    if path.exists():
        try:
            with open(path) as f:
                return json.load(f)
        except (json.JSONDecodeError, OSError):
            pass
    return {"total_spent": 0.0, "jobs": []}


def _save_cost_tracker(tracker: dict) -> None:
    """Persist accumulated cost to local tracker file."""
    path = Path(settings.openai_whisper_cost_tracker_path)
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        with open(path, "w") as f:
            json.dump(tracker, f, indent=2)
    except OSError:
        pass


def _check_budget(audio_duration_sec: float) -> bool:
    """Return True if cloud API is within budget for this audio duration.

    Returns False if the estimated cost would exceed the remaining budget,
    forcing a fallback to local Whisper.
    """
    budget = settings.openai_whisper_budget_usd
    if budget <= 0:
        return True  # Unlimited

    tracker = _load_cost_tracker()
    model = _resolve_model()
    rate = WHISPER_PRICING.get(model, 0.006)
    estimated_cost = (audio_duration_sec / 60) * rate
    total_after = tracker["total_spent"] + estimated_cost

    if total_after > budget:
        logger.warning(
            "[budget] Estimated cost $%.4f would exceed budget $%.2f "
            "(spent=%.2f + est=%.4f). Falling back to local Whisper.",
            estimated_cost, budget, tracker["total_spent"], estimated_cost,
        )
        return False
    return True


def _track_cost(audio_duration_sec: float) -> str:
    """Record cost for a completed transcription job and persist it."""
    model = _resolve_model()
    rate = WHISPER_PRICING.get(model, 0.006)
    cost = (audio_duration_sec / 60) * rate

    tracker = _load_cost_tracker()
    tracker["total_spent"] += cost
    tracker["jobs"].append({
        "model": model,
        "duration_sec": audio_duration_sec,
        "cost": round(cost, 6),
        "timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    })
    _save_cost_tracker(tracker)

    logger.info(
        "[cost] model=%s duration=%.1fs cost=$%.6f total_spent=$%.4f",
        model, audio_duration_sec, cost, tracker["total_spent"],
    )
    return f"{cost:.6f}"


def _resolve_model() -> str:
    """Determine the actual model string to use based on config."""
    if settings.openai_whisper_diarize:
        return "gpt-4o-transcribe-diarize"
    return settings.openai_whisper_model or "gpt-4o-mini-transcribe"


def _get_audio_duration(audio_path: str) -> float:
    """Get audio duration in seconds using ffprobe."""
    import subprocess
    cmd = [
        "ffprobe", "-v", "error",
        "-show_entries", "format=duration",
        "-of", "default=noprint_wrappers=1:nokey=1",
        audio_path,
    ]
    try:
        return float(subprocess.check_output(cmd, text=True).strip())
    except (subprocess.CalledProcessError, ValueError):
        return 0.0


# ── Voice Activity Detection (VAD) — skip silent sections ─────

def _trim_silence_vad(audio_path: str) -> str:
    """Remove silent sections from audio using WebRTC VAD before sending to API.

    This saves money by only billing for actual speech minutes.
    Returns path to the trimmed audio file, or original path if trimming unavailable.

    Requires: pip install webrtcvad
    """
    try:
        import webrtcvad
        import soundfile as sf
        import numpy as np
    except ImportError:
        return audio_path  # VAD not available, skip

    try:
        audio, sr = sf.read(audio_path)
        if sr != 16000:
            return audio_path  # VAD expects 16kHz

        vad = webrtcvad.Vad(2)  # Aggressiveness 2 (balances speed vs accuracy)
        frame_duration = 30  # 30ms frames
        frame_size = int(sr * frame_duration / 1000)

        # Convert to 16-bit PCM if needed
        if audio.dtype in (np.float32, np.float64):
            audio = (audio * 32767).astype(np.int16)

        # Split into frames, keep only those with speech
        speech_frames = []
        for start in range(0, len(audio), frame_size):
            frame = audio[start:start + frame_size]
            if len(frame) < frame_size:
                padded = np.zeros(frame_size, dtype=np.int16)
                padded[:len(frame)] = frame
                frame = padded
            try:
                if vad.is_speech(frame.tobytes(), sr):
                    speech_frames.append(frame)
            except Exception:
                speech_frames.append(frame)  # Keep on error

        if not speech_frames:
            return audio_path

        trimmed = np.concatenate(speech_frames)
        trimmed_path = str(Path(settings.upload_dir) / f"{uuid.uuid4()}_vad.wav")
        sf.write(trimmed_path, trimmed, sr)

        original_dur = len(audio) / sr
        trimmed_dur = len(trimmed) / sr
        saved = (original_dur - trimmed_dur) / original_dur * 100
        logger.info(
            "[VAD] trimmed %.1fs → %.1fs (saved %.0f%%)",
            original_dur, trimmed_dur, saved,
        )
        return trimmed_path
    except Exception as e:
        logger.warning("[VAD] failed (%s), using original audio", e)
        return audio_path


# ── Audio chunking for long files (>25 MB) ───────────────────

def _split_audio(audio_path: str) -> list[str]:
    """Split audio file into chunks under 25 MB for the OpenAI API limit.

    Uses ffmpeg to segment by duration since bitrate varies.
    Returns list of paths to chunk files.
    """
    import subprocess
    import uuid
    from pathlib import Path as P

    size = os.path.getsize(audio_path)
    if size <= MAX_FILE_SIZE_CLOUD:
        return [audio_path]

    chunk_dir = Path(settings.upload_dir) / "chunks"
    chunk_dir.mkdir(parents=True, exist_ok=True)

    dur_cmd = [
        "ffprobe", "-v", "error",
        "-show_entries", "format=duration",
        "-of", "default=noprint_wrappers=1:nokey=1",
        audio_path,
    ]
    duration = float(subprocess.check_output(dur_cmd, text=True).strip())
    ratio = size / MAX_FILE_SIZE_CLOUD
    chunk_duration = duration / (ratio * 1.1)

    base = str(chunk_dir / f"{uuid.uuid4()}")
    split_cmd = [
        "ffmpeg", "-i", audio_path,
        "-f", "segment",
        "-segment_time", str(max(chunk_duration, 30)),
        "-c", "copy",
        "-y",
        f"{base}_%03d.wav",
    ]
    subprocess.run(split_cmd, capture_output=True, text=True, check=True)

    chunks = sorted(P(base).parent.glob(f"{P(base).name}_*"))
    logger.info(
        "[_split_audio] split %.1f MB into %d chunks",
        size / 1024 / 1024, len(chunks),
    )
    return [str(c) for c in chunks]


# ── Cloud transcription (OpenAI Whisper API) ─────────────────

def _transcribe_cloud_segment(audio_path: str, prev_text: str = "") -> dict:
    """Transcribe via OpenAI's hosted Whisper API with segment timestamps.

    Uses the model specified in settings (default: gpt-4o-mini-transcribe).
    Requires OPENAI_WHISPER_API_KEY in .env.

    Args:
        audio_path: Path to audio file.
        prev_text:  Transcript of preceding chunk for context continuity.

    Returns dict with: text, segments [{start, end, text, speaker?}], language.
    """
    import openai

    api_key = settings.openai_whisper_api_key
    base_url = settings.openai_whisper_base_url or "https://api.openai.com/v1"

    client = openai.OpenAI(api_key=api_key, base_url=base_url, timeout=CLOUD_API_TIMEOUT)

    t0 = time.time()

    model = _resolve_model()

    with open(audio_path, "rb") as audio_file:
        result = client.audio.transcriptions.create(
            model=model,
            file=audio_file,
            language="en",
            prompt=prev_text.strip() or None,
            response_format="verbose_json",
            timestamp_granularities=["segment"],
        )

    t1 = time.time()

    text = getattr(result, "text", "").strip()
    lang = getattr(result, "language", "en") or "en"

    # Parse segments from verbose JSON response
    segments = []
    raw_segments = getattr(result, "segments", None)
    if raw_segments:
        for seg in raw_segments:
            s = {
                "start": float(seg.get("start", 0)),
                "end": float(seg.get("end", 0)),
                "text": seg.get("text", "").strip(),
            }
            speaker = seg.get("speaker")
            if speaker:
                s["speaker"] = speaker
            if s["text"]:
                segments.append(s)

    if not segments and text:
        segments.append({"start": 0.0, "end": 0.0, "text": text})

    logger.info(
        "[_transcribe_cloud_segment] model=%s API=%.2fs chars=%d segments=%d lang=%s",
        model, t1 - t0, len(text), len(segments), lang,
    )

    return {"text": text, "segments": segments, "language": lang}


def _transcribe_cloud(audio_path: str) -> dict:
    """Transcribe audio via cloud API with VAD, chunking, and budget check.

    1. Check budget — fallback to local if insufficient
    2. VAD trim — remove silence to save cost
    3. Chunk — split files > 25 MB
    4. Transcribe each chunk with context continuity
    5. Track cost

    Returns dict with: text, segments, language.
    """
    duration = _get_audio_duration(audio_path)
    if not _check_budget(duration):
        raise BudgetExceededError("Cloud transcription budget exceeded")

    trimmed_path = _trim_silence_vad(audio_path)
    is_trimmed = trimmed_path != audio_path
    chunk_paths = []

    try:
        chunk_paths = _split_audio(trimmed_path)

        prev_text = ""
        all_segments = []
        for i, chunk_path in enumerate(chunk_paths):
            logger.info(
                "[_transcribe_cloud] chunk %d/%d: %s",
                i + 1, len(chunk_paths), chunk_path,
            )
            result = _transcribe_cloud_segment(chunk_path, prev_text=prev_text)
            all_segments.extend(result.get("segments", []))
            prev_text = result.get("text", "")[-2000:]

        full_text = " ".join(s["text"] for s in all_segments if s.get("text"))

        return {
            "text": full_text,
            "segments": all_segments,
            "language": all_segments[0]["language"] if all_segments else "en",
        }
    finally:
        _track_cost(duration)

        for chunk_path in chunk_paths:
            if chunk_path != audio_path and chunk_path != trimmed_path and os.path.exists(chunk_path):
                try:
                    os.remove(chunk_path)
                except OSError:
                    pass

        if is_trimmed and os.path.exists(trimmed_path):
            try:
                os.remove(trimmed_path)
            except OSError:
                pass


# ── Local transcription (openai-whisper on CPU) ──────────────

def _transcribe_local(audio_path: str) -> dict:
    """Transcribe using local Whisper model on CPU (~10-30 seconds)."""
    t0 = time.time()

    model = get_whisper_model()
    t_model = time.time()

    result = model.transcribe(
        audio_path,
        language="en",
        verbose=False,
        fp16=False,
        word_timestamps=False,
        condition_on_previous_text=False,
    )
    t_transcribe = time.time()

    text = result.get("text", "").strip()
    lang = result.get("language", "en")

    logger.info(
        "[transcribe_local] model_load=%.2fs whisper=%.2fs total=%.2fs chars=%d lang=%s",
        t_model - t0, t_transcribe - t_model, t_transcribe - t0, len(text), lang,
    )

    return {"text": text, "segments": result.get("segments", []), "language": lang}


# ── Public API ───────────────────────────────────────────────

class BudgetExceededError(Exception):
    """Raised when cloud transcription would exceed the configured budget."""


class TranscriptionService:
    def transcribe_chat(self, audio_path: str) -> dict:
        """Fast transcription for short chat voice clips.

        Tries cloud API first (~1-3s), falls back to local Whisper (~10-30s).
        Returns dict with: text, language.
        """
        t_start = time.time()

        if settings.openai_whisper_api_key:
            try:
                result = _transcribe_cloud(audio_path)
                logger.info(
                    "[transcribe_chat] CLOUD total=%.2fs text=%d chars",
                    time.time() - t_start, len(result.get("text", "")),
                )
                return result
            except BudgetExceededError:
                logger.warning("[transcribe_chat] Budget exceeded, falling back to local")
            except Exception as e:
                logger.warning(
                    "[transcribe_chat] Cloud failed (%s), falling back to local", e
                )

        result = _transcribe_local(audio_path)
        logger.info(
            "[transcribe_chat] LOCAL total=%.2fs text=%d chars",
            time.time() - t_start, len(result.get("text", "")),
        )
        return result

    def transcribe_audio(self, audio_path: str) -> dict:
        """Full transcription for lecture recordings.

        Cloud path: VAD trim → chunk → transcribe with context → track cost.
        Local path: Direct Whisper transcription.

        Returns dict with: text, segments [{start, end, text, speaker?}], language.
        """
        if settings.openai_whisper_api_key:
            try:
                return _transcribe_cloud(audio_path)
            except BudgetExceededError:
                logger.warning("[transcribe_audio] Budget exceeded, falling back to local")
            except Exception as e:
                logger.warning("Cloud transcription failed (%s), using local", e)

        model = get_whisper_model()
        try:
            result = model.transcribe(
                audio_path,
                language="en",
                verbose=False,
                fp16=False,
                condition_on_previous_text=True,
                word_timestamps=True,
            )
        except TypeError:
            result = model.transcribe(
                audio_path,
                language="en",
                verbose=False,
                fp16=False,
                condition_on_previous_text=True,
            )

        return {
            "text": result.get("text", "").strip(),
            "segments": result.get("segments", []),
            "language": result.get("language", "en"),
        }

    def format_transcript_with_timestamps(self, segments: list) -> str:
        """Format transcript segments with readable timestamps."""
        formatted = []
        for seg in segments:
            start = self._format_time(float(seg.get("start", 0)))
            text = str(seg.get("text", "")).strip()
            speaker = seg.get("speaker")
            if text:
                prefix = f"[{start}]"
                if speaker:
                    prefix = f"[{start}] ({speaker})"
                formatted.append(f"{prefix} {text}")
        return "\n".join(formatted)

    def _format_time(self, seconds: float) -> str:
        minutes = int(seconds // 60)
        secs = int(seconds % 60)
        return f"{minutes:02d}:{secs:02d}"
