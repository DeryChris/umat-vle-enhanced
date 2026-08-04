# ============================================================
# Downloads BBB recordings and extracts audio using ffmpeg
# Requires ffmpeg on system PATH
# ============================================================

import os
import subprocess
import uuid
import tempfile
import logging
from pathlib import Path
from typing import Optional
from config import get_settings

settings = get_settings()
logger = logging.getLogger(__name__)


class AudioProcessor:

    def __init__(self):
        self.upload_dir = Path(settings.upload_dir)
        self.upload_dir.mkdir(exist_ok=True)

    def extract_audio_from_video(self, video_path: str) -> str:
        """Extract audio from video file using ffmpeg. Returns path to .wav file."""
        audio_path = str(self.upload_dir / f"{uuid.uuid4()}.wav")

        command = [
            "ffmpeg",
            "-i", video_path,
            "-vn",                   # No video
            "-acodec", "pcm_s16le",  # WAV format
            "-ar", "16000",          # 16kHz sample rate (Whisper optimal)
            "-ac", "1",              # Mono
            "-y",                    # Overwrite output
            audio_path,
        ]

        result = subprocess.run(command, capture_output=True, text=True)

        if result.returncode != 0:
            raise RuntimeError(f"ffmpeg failed: {result.stderr}")

        return audio_path

    def download_recording(self, recording_url: str) -> str:
        """Download BBB recording to local storage using the correct extension."""
        import httpx
        from urllib.parse import urlparse

        ext = ".webm"
        parsed = urlparse(recording_url)
        path = parsed.path
        if path and "." in path:
            ext_from_url = "." + path.rsplit(".", 1)[-1].lower()
            if ext_from_url in (".mp4", ".webm", ".mov", ".avi", ".mkv"):
                ext = ext_from_url

        filename = f"{uuid.uuid4()}{ext}"
        filepath = str(self.upload_dir / filename)

        with httpx.stream("GET", recording_url, timeout=300.0) as response:
            response.raise_for_status()
            ct = response.headers.get("content-type", "")
            if ext == ".webm" and "webm" not in ct and "mp4" in ct:
                filepath = filepath.rsplit(".", 1)[0] + ".mp4"
                filename = os.path.basename(filepath)
            with open(filepath, "wb") as f:
                for chunk in response.iter_bytes(chunk_size=8192):
                    f.write(chunk)

        return filepath

    def compress_for_api(self, input_path: str) -> str:
        """Convert audio to 16kHz mono MP3 @ 64kbps for efficient API upload.

        Returns path to compressed MP3 file. Use cleanup() to remove it later.
        """
        output_path = str(self.upload_dir / f"comp_{uuid.uuid4().hex[:12]}.mp3")
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
        logger.info(f"Compressed {input_path} → {output_path} ({os.path.getsize(output_path)} bytes)")
        return output_path

    def vad_split(self, compressed_path: str, max_chunk_secs: int = None) -> list:
        """Split audio at silence points into chunks under max_chunk_secs.

        Uses FFmpeg silencedetect to find silence boundaries, then segments
        at those points. Falls back to fixed-duration split if VAD fails.

        Returns list of chunk file paths. Caller must clean up with cleanup(*chunks).
        """
        if max_chunk_secs is None:
            max_chunk_secs = getattr(settings, 'transcription_max_chunk_secs', 600)

        output_dir = tempfile.mkdtemp(prefix="vad_chunks_")

        try:
            # Detect silence boundaries
            cmd = [
                "ffmpeg", "-y",
                "-i", compressed_path,
                "-af", "silencedetect=noise=-30dB:d=0.5",
                "-f", "null",
                "-",
            ]
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
            stderr = result.stderr

            import re
            silence_starts = [float(m.group(1)) for m in re.finditer(r"silence_start: ([\d.]+)", stderr)]

            if not silence_starts:
                raise ValueError("No silence detected")

            duration = self._get_audio_duration(compressed_path)
            split_points = [0.0]
            for s in silence_starts:
                if s - split_points[-1] >= max_chunk_secs * 0.5 and s < duration - 5:
                    split_points.append(s)
            if split_points[-1] < duration - 5:
                split_points.append(duration)

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
                logger.info(f"VAD split: {len(segments_out)} chunks from {compressed_path}")
                return segments_out

        except Exception as e:
            logger.warning(f"VAD split failed ({e}), using fixed-duration split")

        # Fallback: fixed-duration split
        chunk_pattern = os.path.join(output_dir, "chunk_%03d.mp3")
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
        result = [str(p) for p in chunks if p.stat().st_size > 0]
        logger.info(f"Fixed split: {len(result)} chunks from {compressed_path}")
        return result

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

    def cleanup(self, *file_paths: str):
        """Remove temporary files after processing."""
        for path in file_paths:
            try:
                if path and os.path.exists(path):
                    os.remove(path)
            except OSError:
                pass