# ============================================================
# Pytest tests for cloud/local transcription and budget tracking
# Run with: pytest tests/test_transcription.py -v
# ============================================================

import json
import os
import sys
import tempfile
import time
from pathlib import Path
from unittest.mock import MagicMock, patch, ANY

import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
os.environ["OPENAI_WHISPER_API_KEY"] = "sk-test-fake-key-for-tests"
os.environ["OPENAI_WHISPER_BUDGET_USD"] = "10.0"
os.environ["OPENAI_WHISPER_COST_TRACKER_PATH"] = tempfile.mktemp(suffix=".json")
os.environ["OPENAI_WHISPER_MODEL"] = "gpt-4o-mini-transcribe"
os.environ["OPENAI_WHISPER_DIARIZE"] = "false"

from config import get_settings, Settings
from core.transcription import (
    TranscriptionService,
    _transcribe_cloud_segment,
    _transcribe_local,
    _split_audio,
    _check_budget,
    _track_cost,
    _trim_silence_vad,
    _load_cost_tracker,
    _resolve_model,
    BudgetExceededError,
    WHISPER_PRICING,
)

# Force reload settings with test env vars. We must also reload
# core.transcription so its module-level `settings` binding picks up the
# fresh cache (otherwise it keeps the copy made when another test module
# imported config first, and the cloud path is skipped).
import importlib
import config as config_module
import core.transcription as transcription_module
importlib.reload(config_module)
importlib.reload(transcription_module)
from config import get_settings as _fresh_get_settings
settings = _fresh_get_settings()

SAMPLE_AUDIO_PATH = os.path.join(os.path.dirname(__file__), "fixtures", "test_audio.wav")


# ── Cloud transcription (mocked) ─────────────────────────────

class MockSegment:
    def __init__(self, start, end, text, speaker=None):
        self.start = start
        self.end = end
        self.text = text
        self.speaker = speaker

    def get(self, key, default=None):
        return getattr(self, key, default)


class MockTranscriptionResult:
    def __init__(self, text="Hello world this is a test lecture.", language="en", segments=None):
        self.text = text
        self.language = language
        self.segments = segments or []


def test_resolve_model_default():
    """Default model should be gpt-4o-mini-transcribe (budget-friendly)."""
    model = _resolve_model()
    assert model == "gpt-4o-mini-transcribe"


def test_resolve_model_diarize(monkeypatch):
    """When diarize is enabled, model should switch to diarize variant."""
    monkeypatch.setattr(settings, "openai_whisper_diarize", True)
    model = _resolve_model()
    assert model == "gpt-4o-transcribe-diarize"
    monkeypatch.setattr(settings, "openai_whisper_diarize", False)


def test_resolve_model_custom(monkeypatch):
    """Custom model from settings should be respected."""
    monkeypatch.setattr(settings, "openai_whisper_model", "whisper-1")
    model = _resolve_model()
    assert model == "whisper-1"
    monkeypatch.setattr(settings, "openai_whisper_model", "gpt-4o-mini-transcribe")


@patch("openai.OpenAI")
def test_transcribe_cloud_segment_basic(mock_openai):
    """_transcribe_cloud_segment should return text and segments."""
    mock_client = MagicMock()
    mock_openai.return_value = mock_client

    mock_client.audio.transcriptions.create.return_value = MockTranscriptionResult(
        text="Hello world",
        segments=[
            MockSegment(start=0.0, end=2.5, text="Hello world"),
        ],
    )

    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as f:
        f.write(b"\x00" * 16000 * 2)  # 1 second of silence
        tmp = f.name

    try:
        result = _transcribe_cloud_segment(tmp)
        assert result["text"] == "Hello world"
        assert len(result["segments"]) == 1
        assert result["segments"][0]["start"] == 0.0
        assert result["segments"][0]["end"] == 2.5
        assert result["segments"][0]["text"] == "Hello world"
        assert result["language"] == "en"
    finally:
        os.unlink(tmp)


@patch("openai.OpenAI")
def test_transcribe_cloud_segment_diarize(mock_openai):
    """Diarize segments should include speaker labels."""
    mock_client = MagicMock()
    mock_openai.return_value = mock_client

    mock_client.audio.transcriptions.create.return_value = MockTranscriptionResult(
        text="Hello I am the lecturer",
        segments=[
            MockSegment(start=0.0, end=1.5, text="Hello", speaker="Speaker A"),
            MockSegment(start=1.5, end=3.0, text="I am the lecturer", speaker="Speaker A"),
        ],
    )

    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as f:
        f.write(b"\x00" * 16000 * 2)
        tmp = f.name

    try:
        result = _transcribe_cloud_segment(tmp)
        assert len(result["segments"]) == 2
        assert result["segments"][0]["speaker"] == "Speaker A"
        assert result["segments"][1]["speaker"] == "Speaker A"
    finally:
        os.unlink(tmp)


@patch("openai.OpenAI")
def test_transcribe_cloud_segment_no_segments_fallback(mock_openai):
    """When API returns no segments, should create one from full text."""
    mock_client = MagicMock()
    mock_openai.return_value = mock_client

    mock_client.audio.transcriptions.create.return_value = MockTranscriptionResult(
        text="Fallback transcript without segments",
    )

    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as f:
        f.write(b"\x00" * 16000 * 2)
        tmp = f.name

    try:
        result = _transcribe_cloud_segment(tmp)
        assert len(result["segments"]) == 1
        assert result["segments"][0]["text"] == "Fallback transcript without segments"
    finally:
        os.unlink(tmp)


# ── Budget tracking tests ────────────────────────────────────

def test_budget_check_unlimited():
    """Budget of 0 means unlimited."""
    with patch.object(settings, "openai_whisper_budget_usd", 0):
        assert _check_budget(3600) is True  # even 1hr is fine


def test_budget_check_within():
    """Within budget should return True."""
    with patch.object(settings, "openai_whisper_budget_usd", 10.0):
        assert _check_budget(60) is True  # 1 min @ $0.0006/min = $0.0006


def test_budget_check_exceeded():
    """Exceeding budget should return False."""
    with patch.object(settings, "openai_whisper_budget_usd", 0.001):
        assert _check_budget(600) is False  # 10 min @ $0.0006/min = $0.006 > $0.001


def test_cost_tracker_persistence():
    """Cost should persist between calls."""
    path = Path(settings.openai_whisper_cost_tracker_path)
    if path.exists():
        path.unlink()

    _track_cost(60)  # 1 minute
    _track_cost(120)  # 2 minutes

    tracker = _load_cost_tracker()
    assert tracker["total_spent"] > 0
    assert len(tracker["jobs"]) == 2
    assert tracker["jobs"][0]["duration_sec"] == 60
    assert tracker["jobs"][1]["duration_sec"] == 120

    # Cleanup
    path.unlink(missing_ok=True)


def test_pricing_constants():
    """Pricing dict should have known models."""
    assert "gpt-4o-mini-transcribe" in WHISPER_PRICING
    assert "gpt-4o-transcribe" in WHISPER_PRICING
    assert "gpt-4o-transcribe-diarize" in WHISPER_PRICING
    assert "whisper-1" in WHISPER_PRICING
    assert WHISPER_PRICING["gpt-4o-mini-transcribe"] < WHISPER_PRICING["gpt-4o-transcribe"]


# ── Timestamp formatting ─────────────────────────────────────

def test_format_transcript_with_timestamps():
    """Formatting should produce [MM:SS] prefixed lines."""
    service = TranscriptionService()
    segments = [
        {"start": 0, "end": 2.5, "text": "Hello"},
        {"start": 2.5, "end": 5.0, "text": "world"},
    ]
    result = service.format_transcript_with_timestamps(segments)
    assert "[00:00] Hello" in result
    assert "[00:02] world" in result


def test_format_transcript_with_speaker():
    """Formatting with speaker should include (Speaker) label."""
    service = TranscriptionService()
    segments = [
        {"start": 0, "end": 2.5, "text": "Hello", "speaker": "Speaker A"},
    ]
    result = service.format_transcript_with_timestamps(segments)
    assert "(Speaker A)" in result
    assert "[00:00]" in result
    assert "Hello" in result


# ── VAD ──────────────────────────────────────────────────────

def test_trim_silence_vad_no_vad_module():
    """When webrtcvad is not installed, should return original path."""
    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as f:
        f.write(b"\x00" * 16000 * 2)
        tmp = f.name

    try:
        result = _trim_silence_vad(tmp)
        assert result == tmp  # Returns original
    finally:
        os.unlink(tmp)


# ── Local transcription (lightly mocked) ─────────────────────

@patch("core.transcription.get_whisper_model")
def test_transcribe_local(mock_get_model):
    """_transcribe_local should return expected structure."""
    mock_model = MagicMock()
    mock_model.transcribe.return_value = {
        "text": "Local transcript",
        "segments": [{"start": 0.0, "end": 1.0, "text": "Local transcript"}],
        "language": "en",
    }
    mock_get_model.return_value = mock_model

    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as f:
        f.write(b"\x00" * 16000 * 2)
        tmp = f.name

    try:
        result = _transcribe_local(tmp)
        assert result["text"] == "Local transcript"
        assert len(result["segments"]) == 1
        assert result["segments"][0]["start"] == 0.0
    finally:
        os.unlink(tmp)


# ── Service-level transcribe_audio ───────────────────────────

@patch("core.transcription._transcribe_cloud")
def test_transcribe_audio_uses_cloud_when_key_set(mock_cloud):
    """transcribe_audio should prefer cloud when API key is set."""
    mock_cloud.return_value = {
        "text": "Cloud transcript",
        "segments": [{"start": 0.0, "end": 1.0, "text": "Cloud transcript"}],
        "language": "en",
    }

    service = TranscriptionService()
    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as f:
        f.write(b"\x00" * 16000 * 2)
        tmp = f.name

    try:
        result = service.transcribe_audio(tmp)
        assert result["text"] == "Cloud transcript"
        assert len(result["segments"]) == 1
        mock_cloud.assert_called_once()
    finally:
        os.unlink(tmp)


@patch("core.transcription._transcribe_cloud")
@patch("core.transcription.get_whisper_model")
def test_transcribe_audio_falls_back_to_local(mock_get_model, mock_cloud):
    """transcribe_audio should fall back to local when cloud fails."""
    mock_cloud.side_effect = Exception("API error")
    mock_model = MagicMock()
    mock_model.transcribe.return_value = {
        "text": "Local fallback",
        "segments": [{"start": 0.0, "end": 1.0, "text": "Local fallback"}],
        "language": "en",
    }
    mock_get_model.return_value = mock_model

    service = TranscriptionService()
    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as f:
        f.write(b"\x00" * 16000 * 2)
        tmp = f.name

    try:
        result = service.transcribe_audio(tmp)
        assert result["text"] == "Local fallback"
    finally:
        os.unlink(tmp)


# ── Chat transcription ───────────────────────────────────────

@patch("core.transcription._transcribe_cloud")
def test_transcribe_chat(mock_cloud):
    """transcribe_chat should use cloud endpoint."""
    mock_cloud.return_value = {
        "text": "Chat transcript",
        "segments": [{"start": 0.0, "end": 0.5, "text": "Chat transcript"}],
        "language": "en",
    }

    service = TranscriptionService()
    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as f:
        f.write(b"\x00" * 8000)
        tmp = f.name

    try:
        result = service.transcribe_chat(tmp)
        assert result["text"] == "Chat transcript"
        mock_cloud.assert_called_once()
    finally:
        os.unlink(tmp)


# ── Audio splitting ──────────────────────────────────────────

def test_split_audio_small_file():
    """Files under 25MB should not be split."""
    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as f:
        f.write(b"\x00" * 1024)  # 1KB
        tmp = f.name

    try:
        chunks = _split_audio(tmp)
        assert len(chunks) == 1
        assert chunks[0] == tmp
    finally:
        os.unlink(tmp)