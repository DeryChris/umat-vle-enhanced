# ============================================================
# Downloads BBB recordings and extracts audio using ffmpeg
# Requires ffmpeg on system PATH
# ============================================================

import logging
import os
import subprocess
import uuid
from pathlib import Path
from config import get_settings

logger = logging.getLogger(__name__)
settings = get_settings()


class UrlExpiredError(Exception):
    """Raised when a BBB recording URL returns 401/403 (likely expired)."""


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

    def apply_noise_reduction(self, audio_path: str) -> str:
        """Apply spectral noise gating to clean up audio before transcription.

        Uses noisereduce if available. Falls back to original if library missing.
        Only applies when audio is longer than 10 seconds.
        """
        try:
            import soundfile as sf
            import noisereduce as nr
        except ImportError:
            logger.info("[noise_reduction] noisereduce/soundfile not installed, skipping")
            return audio_path

        try:
            audio, sr = sf.read(audio_path)
            if len(audio) < sr * 10:
                return audio_path

            reduced = nr.reduce_noise(
                y=audio,
                sr=sr,
                stationary=False,
                prop_decrease=0.85,
            )

            cleaned_path = str(self.upload_dir / f"{uuid.uuid4()}_cleaned.wav")
            sf.write(cleaned_path, reduced, sr)
            logger.info("[noise_reduction] applied: %s → %s", audio_path, cleaned_path)
            return cleaned_path
        except Exception as e:
            logger.warning("[noise_reduction] failed (%s), using original", e)
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

        try:
            with httpx.stream("GET", recording_url, timeout=300.0, follow_redirects=True) as response:
                if response.status_code in (401, 403):
                    raise UrlExpiredError(
                        f"Recording URL returned {response.status_code} (likely expired): {recording_url}"
                    )
                response.raise_for_status()
                ct = response.headers.get("content-type", "")
                if ext == ".webm" and "webm" not in ct and "mp4" in ct:
                    filepath = filepath.rsplit(".", 1)[0] + ".mp4"
                    filename = os.path.basename(filepath)
                with open(filepath, "wb") as f:
                    for chunk in response.iter_bytes(chunk_size=8192):
                        f.write(chunk)
        except httpx.HTTPStatusError as e:
            if e.response.status_code in (401, 403):
                raise UrlExpiredError(
                    f"Recording URL returned {e.response.status_code} (likely expired): {recording_url}"
                )
            raise

        return filepath

    def cleanup(self, *file_paths: str):
        """Remove temporary files after processing."""
        for path in file_paths:
            try:
                if path and os.path.exists(path):
                    os.remove(path)
            except OSError:
                pass

    def chunk_for_cloud(self, audio_path: str) -> list[str]:
        """Split audio into chunks under 25 MB for OpenAI API limit.

        Delegates to core.transcription._split_audio.
        """
        from core.transcription import _split_audio
        return _split_audio(audio_path)