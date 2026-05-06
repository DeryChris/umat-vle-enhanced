# ============================================================
# Whisper speech-to-text transcription (local model)
# Install:
#   pip install openai-whisper
# Also requires ffmpeg installed and on PATH.
# ============================================================

import whisper
from config import get_settings

settings = get_settings()

_whisper_model = None  # Loaded once


def get_whisper_model():
    global _whisper_model
    if _whisper_model is None:
        print(f"Loading Whisper model: {settings.whisper_model}")
        _whisper_model = whisper.load_model(settings.whisper_model)
        print("Whisper model loaded.")
    return _whisper_model


class TranscriptionService:
    def transcribe_audio(self, audio_path: str) -> dict:
        """
        Transcribe audio file to text using Whisper.
        Returns dict with: text, segments, language
        """
        model = get_whisper_model()

        # Keep parameters conservative for Windows CPU stability.
        # If your whisper package supports word_timestamps, this will work.
        # If it doesn't, remove word_timestamps or wrap it as shown below.
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
            # Fallback: older whisper versions don't accept word_timestamps
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
            if text:
                formatted.append(f"[{start}] {text}")
        return "\n".join(formatted)

    def _format_time(self, seconds: float) -> str:
        minutes = int(seconds // 60)
        secs = int(seconds % 60)
        return f"{minutes:02d}:{secs:02d}"