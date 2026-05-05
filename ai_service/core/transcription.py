# ============================================================
# OpenAI Whisper speech-to-text transcription
# Install: pip install openai-whisper
# ============================================================

import whisper
from config import get_settings

settings = get_settings()

# Load Whisper model once at startup — expensive
_whisper_model = None


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
        Transcribe audio file to text using OpenAI Whisper.
        Returns dict with 'text', 'segments', and 'language'.
        """
        model = get_whisper_model()

        result = model.transcribe(
            audio_path,
            language="en",
            verbose=False,
            word_timestamps=True,
            condition_on_previous_text=True,
        )

        return {
            "text":     result["text"].strip(),
            "segments": result.get("segments", []),
            "language": result.get("language", "en"),
        }

    def format_transcript_with_timestamps(self, segments: list) -> str:
        """Format transcript segments with readable timestamps."""
        formatted = []
        for seg in segments:
            start = self._format_time(seg["start"])
            text  = seg["text"].strip()
            formatted.append(f"[{start}] {text}")
        return "\n".join(formatted)

    def _format_time(self, seconds: float) -> str:
        minutes = int(seconds // 60)
        secs    = int(seconds % 60)
        return f"{minutes:02d}:{secs:02d}"