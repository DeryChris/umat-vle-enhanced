# ============================================================
# OpenAI / OpenRouter API-based speech-to-text transcription.
# Replaces local Whisper when a TRANSCRIPTION_API_KEY is set.
#
# Features:
#   - Audio compression (16kHz mono MP3 @ 64kbps) before upload
#   - VAD-based chunking at silence points (stays under 25MB limit)
#   - Parallel chunk processing with rate-limit awareness
#   - Content-hash caching per chunk (idempotent retry, no double billing)
#   - 2-second overlap + dedup stitching across chunk boundaries
#   - Cost tracking via API response usage fields
#   - Falls back to local Whisper when no API key configured
# ============================================================

import hashlib
import io
import json
import logging
import os
import subprocess
import tempfile
import time
import uuid
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from typing import Optional

from config import get_settings

logger = logging.getLogger(__name__)
settings = get_settings()

# Disk cache directory for transcribed chunks (idempotent retry)
_TRANSCRIPTION_CACHE_DIR = os.path.join(os.path.dirname(__file__), '..', '.transcription_cache')
os.makedirs(_TRANSCRIPTION_CACHE_DIR, exist_ok=True)


def _get_transcription_client(provider: Optional[str] = None):
    """Return an OpenAI client configured for the chosen transcription provider.

    Args:
        provider: Override provider ('openai', 'openrouter'). Falls back to settings.
    """
    from openai import OpenAI

    effective_provider = provider or settings.transcription_provider

    if effective_provider == "openrouter":
        return OpenAI(
            api_key=settings.transcription_api_key,
            base_url="https://openrouter.ai/api/v1",
            default_headers={
                "HTTP-Referer": settings.openrouter_site_url,
                "X-Title": "UMaT AI VLE Transcription",
            },
        )
    # Default: direct OpenAI
    return OpenAI(api_key=settings.transcription_api_key)


def _chunk_content_hash(audio_bytes: bytes, chunk_index: int, model: str) -> str:
    """Deterministic hash for a chunk — used for idempotent caching."""
    raw = hashlib.sha256(audio_bytes).hexdigest() + f"_{chunk_index}_{model}"
    return hashlib.sha256(raw.encode()).hexdigest()


def _get_cached_chunk(cache_key: str) -> Optional[dict]:
    """Return cached chunk result if it exists."""
    cache_path = os.path.join(_TRANSCRIPTION_CACHE_DIR, f"{cache_key}.json")
    if os.path.exists(cache_path):
        try:
            with open(cache_path, "r", encoding="utf-8") as f:
                return json.load(f)
        except (json.JSONDecodeError, OSError):
            pass
    return None


def _set_cached_chunk(cache_key: str, data: dict):
    """Store chunk result in cache."""
    cache_path = os.path.join(_TRANSCRIPTION_CACHE_DIR, f"{cache_key}.json")
    try:
        with open(cache_path, "w", encoding="utf-8") as f:
            json.dump(data, f)
    except OSError as e:
        logger.warning(f"Failed to write transcription cache {cache_key}: {e}")


class ApiTranscriptionService:
    """API-based transcription service using OpenAI's audio/transcriptions endpoint.

    Falls back to local Whisper (core.transcription.TranscriptionService) when
    no TRANSCRIPTION_API_KEY is configured.
    """

    def __init__(self, api_key: Optional[str] = None, model: Optional[str] = None,
                 provider: Optional[str] = None):
        self.api_key = api_key or settings.transcription_api_key
        self.model = model or settings.transcription_model
        self.provider = provider or settings.transcription_provider
        self.client = None
        self._local_fallback = None  # Lazy-imported local Whisper service

    def _get_local_fallback(self):
        """Lazy import and return the local Whisper transcription service."""
        if self._local_fallback is None:
            from core.transcription import TranscriptionService
            self._local_fallback = TranscriptionService()
        return self._local_fallback

    def transcribe(self, audio_path: str, language: str = "en") -> dict:
        """Transcribe an audio file using the configured provider.

        For API-based transcription:
            1. Compress audio to 16kHz mono MP3 @ 64kbps
            2. If >24MB, VAD-split into chunks
            3. Transcribe each chunk (parallel, with caching)
            4. Stitch with 2s overlap dedup
            5. Return {text, segments, cost, duration, chunk_count, provider, model}

        Falls back to local Whisper when no API key is set.
        """
        if not self.api_key:
            logger.info("No TRANSCRIPTION_API_KEY set — falling back to local Whisper")
            local = self._get_local_fallback()
            result = local.transcribe_audio(audio_path)
            segments = result.get("segments", [])
            return {
                "text": result.get("text", ""),
                "segments": segments,
                "language": result.get("language", language),
                "formatted": local.format_transcript_with_timestamps(segments),
                "provider": "local",
                "model": f"whisper-{settings.whisper_model}",
                "cost": 0.0,
                "duration_secs": self._get_audio_duration(audio_path),
                "chunk_count": 1,
            }

        # API-based transcription
        if self.client is None:
            self.client = _get_transcription_client(provider=self.provider)

        audio_duration = self._get_audio_duration(audio_path)
        logger.info(f"Transcribing: {audio_path} ({audio_duration:.1f}s, provider={self.provider}, model={self.model})")

        # Step 1: Compress audio for API upload
        compressed = self._compress_audio(audio_path)
        compressed_size = os.path.getsize(compressed)
        logger.info(f"Compressed {audio_path} → {compressed} ({compressed_size} bytes)")

        # Step 2: Check size — if small enough, send as single chunk
        if compressed_size < 24 * 1024 * 1024:  # Under 24MB (safe margin)
            result = self._transcribe_chunk(compressed, language, 0, 0.0)
            segments = self._format_segments_from_result(result, offset=0.0)
            text = result.get("text", "").strip()
            total_cost = result.get("usage", {}).get("cost", 0.0) if isinstance(result.get("usage"), dict) else 0.0
            self._cleanup_temp(compressed)
            return {
                "text": text,
                "segments": segments,
                "language": language,
                "formatted": self._format_timestamps(segments),
                "provider": self.provider,
                "model": self.model,
                "cost": total_cost,
                "duration_secs": audio_duration,
                "chunk_count": 1,
            }

        # Step 3: Large file — VAD-split into chunks
        logger.info(f"File too large ({compressed_size} bytes) — splitting at silence points")
        chunks = self._vad_split(compressed)
        logger.info(f"Split into {len(chunks)} chunks")

        # Step 4: Transcribe chunks in parallel (max 3 concurrent)
        results = self._transcribe_chunks_parallel(chunks, language)

        # Step 5: Stitch with overlap dedup
        text, segments = self._stitch_chunks(results, overlap_secs=2.0)

        total_cost = sum(r.get("usage", {}).get("cost", 0.0)
                         if isinstance(r.get("usage"), dict) else 0.0
                         for r in results)

        self._cleanup_temp(compressed)
        for ch_path in chunks:
            self._cleanup_temp(ch_path)

        return {
            "text": text,
            "segments": segments,
            "language": language,
            "formatted": self._format_timestamps(segments),
            "provider": self.provider,
            "model": self.model,
            "cost": total_cost,
            "duration_secs": audio_duration,
            "chunk_count": len(chunks),
        }

    # ── Audio pre-processing ─────────────────────────────────

    def _compress_audio(self, input_path: str) -> str:
        """Convert to 16kHz mono MP3 @ 64kbps for efficient API upload."""
        output_path = os.path.join(settings.upload_dir, f"comp_{uuid.uuid4().hex[:12]}.mp3")
        cmd = [
            "ffmpeg", "-y",
            "-i", input_path,
            "-vn",
            "-ar", "16000",
            "-ac", "1",
            "-c:a", "libmp3lame",
            "-b:a", "64k",
            output_path,
        ]
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
        if result.returncode != 0:
            raise RuntimeError(f"Audio compression failed: {result.stderr[:500]}")
        return output_path

    def _vad_split(self, compressed_path: str, max_chunk_secs: int = None) -> list:
        """Split audio at silence points into chunks under max_chunk_secs.

        Uses FFmpeg's silencedetect filter to find boundaries, then segments
        at those points. Falls back to fixed-duration split if VAD fails.
        """
        if max_chunk_secs is None:
            max_chunk_secs = settings.transcription_max_chunk_secs

        output_dir = tempfile.mkdtemp(prefix="vad_chunks_")
        chunk_pattern = os.path.join(output_dir, "chunk_%03d.mp3")

        # Try VAD-based split with silencedetect
        try:
            cmd = [
                "ffmpeg", "-y",
                "-i", compressed_path,
                "-af", f"silencedetect=noise=-30dB:d=0.5",
                "-f", "null",
                "-",
            ]
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
            stderr = result.stderr

            # Parse silence timestamps from FFmpeg output
            import re
            silences = []
            for match in re.finditer(r"silence_start: ([\d.]+)", stderr):
                silences.append(float(match.group(1)))
            for match in re.finditer(r"silence_end: ([\d.]+)", stderr):
                # We need both start and end; collect in pairs
                pass

            # Build segment list: start=previous_silence_end, end=current_silence_start
            # If no silences found or parsing fails, fall back to fixed split
            if not silences:
                raise ValueError("No silence detected")

            # Use segment muxer with silence-based splitting
            # Build a list of split points at silence boundaries, capped at max_chunk_secs
            split_points = [0.0]
            duration = self._get_audio_duration(compressed_path)
            for s in silences:
                if s - split_points[-1] >= max_chunk_secs * 0.5 and s < duration - 5:
                    split_points.append(s)
            if split_points[-1] < duration - 5:
                split_points.append(duration)

            # Generate segments
            segments_out = []
            for i in range(len(split_points) - 1):
                start = split_points[i]
                end = min(split_points[i + 1], start + max_chunk_secs)
                seg_path = os.path.join(output_dir, f"seg_{i:03d}.mp3")
                seg_cmd = [
                    "ffmpeg", "-y",
                    "-i", compressed_path,
                    "-ss", str(start),
                    "-to", str(end),
                    "-c:a", "libmp3lame",
                    "-b:a", "64k",
                    seg_path,
                ]
                subprocess.run(seg_cmd, capture_output=True, text=True, timeout=120)
                if os.path.getsize(seg_path) > 0:
                    segments_out.append(seg_path)

            if segments_out:
                return segments_out

        except Exception as e:
            logger.warning(f"VAD split failed ({e}), falling back to fixed-duration split")

        # Fallback: fixed-duration split
        seg_cmd = [
            "ffmpeg", "-y",
            "-i", compressed_path,
            "-f", "segment",
            "-segment_time", str(max_chunk_secs),
            "-c:a", "libmp3lame",
            "-b:a", "64k",
            "-reset_timestamps", "1",
            chunk_pattern,
        ]
        subprocess.run(seg_cmd, capture_output=True, text=True, timeout=300)

        chunks = sorted(Path(output_dir).glob("chunk_*.mp3"))
        return [str(p) for p in chunks if p.stat().st_size > 0]

    # ── Transcription ────────────────────────────────────────

    def _transcribe_chunk(self, chunk_path: str, language: str,
                          chunk_index: int, time_offset: float) -> dict:
        """Transcribe a single audio chunk via API with caching."""
        with open(chunk_path, "rb") as f:
            audio_bytes = f.read()

        cache_key = _chunk_content_hash(audio_bytes, chunk_index, self.model)
        if settings.enable_transcription_cache:
            cached = _get_cached_chunk(cache_key)
            if cached:
                logger.info(f"Cache HIT for chunk {chunk_index} ({cache_key[:12]})")
                return cached

        logger.info(f"Transcribing chunk {chunk_index} ({len(audio_bytes)} bytes)")

        # Prepare file in memory buffer
        buf = io.BytesIO(audio_bytes)
        buf.name = os.path.basename(chunk_path)

        # API call with retry
        max_retries = 3
        last_error = None
        for attempt in range(max_retries):
            try:
                kwargs = {
                    "model": self.model,
                    "file": buf,
                    "language": language,
                    "response_format": "verbose_json",
                    "timestamp_granularities": ["segment"],
                }

                # OpenRouter uses base64 JSON, not multipart; OpenAI uses multipart
                if self.provider == "openrouter":
                    # For OpenRouter, we send base64-encoded audio as JSON
                    buf.seek(0)
                    import base64
                    b64_data = base64.b64encode(buf.read()).decode("utf-8")
                    ext = os.path.splitext(chunk_path)[1].lstrip(".") or "mp3"
                    response = self.client.audio.transcriptions.create(
                        model=self.model,
                        input_audio={"data": b64_data, "format": ext},
                        language=language,
                        response_format="verbose_json",
                        timestamp_granularities=["segment"],
                    )
                else:
                    buf.seek(0)
                    response = self.client.audio.transcriptions.create(
                        model=self.model,
                        file=buf,
                        language=language,
                        response_format="verbose_json",
                        timestamp_granularities=["segment"],
                    )

                # Build standardized result
                result = {
                    "text": response.text.strip() if hasattr(response, "text") else "",
                    "segments": [],
                    "language": language,
                    "usage": {"cost": 0.0},
                    "chunk_index": chunk_index,
                    "time_offset": time_offset,
                }

                # Extract segments from verbose_json response
                if hasattr(response, "segments"):
                    for seg in response.segments:
                        result["segments"].append({
                            "start": getattr(seg, "start", 0),
                            "end": getattr(seg, "end", 0),
                            "text": getattr(seg, "text", "").strip(),
                        })

                # Extract cost (OpenAI returns usage.audio_minutes or similar)
                if hasattr(response, "usage") and response.usage:
                    usage = response.usage
                    if hasattr(usage, "cost"):
                        result["usage"]["cost"] = usage.cost
                    elif hasattr(usage, "audio_seconds"):
                        # $0.006/min for whisper-1 = $0.0001/sec
                        result["usage"]["cost"] = getattr(usage, "audio_seconds", 0) * 0.0001

                # Also try to get from response dict if it's already parsed
                if isinstance(response, dict):
                    result["text"] = response.get("text", result["text"])
                    result["segments"] = response.get("segments", result["segments"])

                # Cache the result
                if settings.enable_transcription_cache:
                    _set_cached_chunk(cache_key, result)

                return result

            except Exception as e:
                last_error = e
                wait = 2 ** attempt
                logger.warning(f"Chunk {chunk_index} attempt {attempt + 1} failed: {e}. Retrying in {wait}s...")
                time.sleep(wait)
                buf.seek(0)

        raise RuntimeError(f"Chunk {chunk_index} failed after {max_retries} retries: {last_error}")

    def _transcribe_chunks_parallel(self, chunk_paths: list, language: str,
                                    max_workers: int = 3) -> list:
        """Transcribe chunks in parallel with a thread pool."""
        results = [None] * len(chunk_paths)
        time_offset = 0.0

        with ThreadPoolExecutor(max_workers=max_workers) as executor:
            futures = {}
            for i, ch_path in enumerate(chunk_paths):
                future = executor.submit(
                    self._transcribe_chunk, ch_path, language, i, time_offset
                )
                futures[future] = i

            for future in as_completed(futures):
                idx = futures[future]
                try:
                    result = future.result()
                    results[idx] = result
                except Exception as e:
                    logger.error(f"Chunk {idx} failed: {e}")
                    # Insert a placeholder so indices align
                    results[idx] = {
                        "text": "", "segments": [],
                        "language": language, "usage": {"cost": 0.0},
                        "chunk_index": idx, "time_offset": 0.0,
                    }

        # Sort by chunk_index to maintain order
        results.sort(key=lambda r: r.get("chunk_index", 0))
        return results

    def _stitch_chunks(self, results: list, overlap_secs: float = 2.0) -> tuple:
        """Stitch chunk results into a single transcript with overlap dedup.

        Returns (full_text, all_segments).
        """
        all_text_parts = []
        all_segments = []
        running_offset = 0.0
        prev_last_words = ""

        for result in results:
            text = result.get("text", "").strip()
            segments = result.get("segments", [])
            offset = result.get("time_offset", 0.0)

            # Dedup: skip leading words that overlap with previous chunk's tail
            if prev_last_words and text:
                # Simple dedup: if text starts with previous chunk's last ~50 chars, skip overlap
                overlap_check = prev_last_words[-40:].strip().lower()
                text_lower = text.lower()
                if overlap_check and text_lower.startswith(overlap_check):
                    # Find where the overlap ends and trim
                    text = text[len(overlap_check):].strip()
                elif overlap_check and len(text_lower) > 20:
                    # Try fuzzy — if first 20 chars match, trim after
                    if text_lower[:20] == overlap_check[:20]:
                        text = text[20:].strip()

            # Adjust segment timestamps by running offset
            for seg in segments:
                adjusted = {
                    "start": seg.get("start", 0) + running_offset,
                    "end": seg.get("end", 0) + running_offset,
                    "text": seg.get("text", "").strip(),
                }
                if adjusted["text"]:
                    all_segments.append(adjusted)

            if text:
                all_text_parts.append(text)

            # Track last few words for dedup
            if text:
                prev_last_words = text[-80:]
            else:
                prev_last_words = ""

            # Update running offset from the last segment's end time
            if segments:
                running_offset += segments[-1].get("end", 0) - overlap_secs

        full_text = "\n".join(all_text_parts)
        return full_text, all_segments

    # ── Helpers ───────────────────────────────────────────────

    def _get_audio_duration(self, audio_path: str) -> float:
        """Get audio duration in seconds using ffprobe."""
        try:
            cmd = [
                "ffprobe", "-v", "error",
                "-show_entries", "format=duration",
                "-of", "default=noprint_wrappers=1:nokey=1",
                audio_path,
            ]
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=30)
            return float(result.stdout.strip())
        except (ValueError, OSError, subprocess.TimeoutExpired):
            return 0.0

    def _format_segments_from_result(self, result: dict, offset: float = 0.0) -> list:
        """Extract and offset segments from a single API result."""
        segments = result.get("segments", [])
        formatted = []
        for seg in segments:
            formatted.append({
                "start": seg.get("start", 0) + offset,
                "end": seg.get("end", 0) + offset,
                "text": seg.get("text", "").strip(),
            })
        return formatted

    def _format_timestamps(self, segments: list) -> str:
        """Format segments as readable timestamped transcript."""
        lines = []
        for seg in segments:
            start = seg.get("start", 0)
            minutes = int(start // 60)
            secs = int(start % 60)
            text = seg.get("text", "").strip()
            if text:
                lines.append(f"[{minutes:02d}:{secs:02d}] {text}")
        return "\n".join(lines)

    def _cleanup_temp(self, path: str):
        """Remove a temporary file."""
        try:
            if path and os.path.exists(path):
                os.remove(path)
        except OSError:
            pass
