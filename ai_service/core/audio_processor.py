# ============================================================
# Downloads BBB recordings and extracts audio using ffmpeg
# Requires ffmpeg on system PATH
# ============================================================

import os
import subprocess
import uuid
from pathlib import Path
from config import get_settings

settings = get_settings()


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

    def cleanup(self, *file_paths: str):
        """Remove temporary files after processing."""
        for path in file_paths:
            try:
                if path and os.path.exists(path):
                    os.remove(path)
            except OSError:
                pass