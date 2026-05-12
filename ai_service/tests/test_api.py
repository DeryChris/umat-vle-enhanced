# ============================================================
# Pytest unit tests for the FastAPI AI service
# Run with: pytest tests/ -v
# ============================================================

import pytest
import sys
import os
import time
from fastapi.testclient import TestClient

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from main import app
from config import get_settings

settings = get_settings()
client   = TestClient(app)

HEADERS = {"Authorization": f"Bearer {settings.ai_service_token}"}


def test_health_check():
    response = client.get("/api/v1/health")
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "healthy"
    assert "whisper_model" in data
    assert "llm_model" in data


def test_query_no_materials():
    """Should return a graceful 'no materials' message for an unknown course."""
    response = client.post(
        "/api/v1/query",
        json={"question": "What is mining?", "course_id": 99999, "user_id": 1},
        headers=HEADERS,
    )
    assert response.status_code == 200
    data = response.json()
    assert "answer" in data
    assert len(data["sources"]) == 0


def test_query_without_auth():
    """No bearer token should return 403."""
    response = client.post(
        "/api/v1/query",
        json={"question": "test", "course_id": 1, "user_id": 1},
    )
    assert response.status_code == 403


def test_query_invalid_auth():
    """Wrong bearer token should return 401."""
    response = client.post(
        "/api/v1/query",
        json={"question": "test", "course_id": 1, "user_id": 1},
        headers={"Authorization": "Bearer wrong-token"},
    )
    assert response.status_code == 401


def test_process_recording_creates_job():
    """Submitting a recording URL should return a job_id with status 'queued'."""
    response = client.post(
        "/api/v1/recording/process",
        json={
            "session_id":    "test-session-123",
            "recording_url": "http://example.com/recording.mp4",
            "course_id":     1,
            "material_ids":  [],
        },
        headers=HEADERS,
    )
    assert response.status_code == 200
    data = response.json()
    assert "job_id" in data
    assert data["status"] == "queued"


def test_get_job_status_not_found():
    """Querying an unknown job_id should return 404."""
    response = client.get(
        "/api/v1/recording/status/nonexistent-job-id",
        headers=HEADERS,
    )
    assert response.status_code == 404


def test_rate_limit_exceeded():
    """Exceeding 10 requests per minute should return 429."""
    from api.v1.routes.query import _rate_limit_store, RATE_LIMIT_MAX
    from collections import defaultdict

    user_id = 99999

    _rate_limit_store[user_id] = []
    for i in range(RATE_LIMIT_MAX):
        _rate_limit_store[user_id].append(time.time() - 1)

    response = client.post(
        "/api/v1/query",
        json={"question": "test question", "course_id": 1, "user_id": user_id},
        headers=HEADERS,
    )

    assert response.status_code == 429
    data = response.json()
    assert "rate limit" in data["detail"]["error"].lower()
    assert data["detail"]["retry_after"] == 60


def test_rate_limit_allows_within_limit():
    """Within rate limit should return 200."""
    from api.v1.routes.query import _rate_limit_store

    user_id = 88888
    _rate_limit_store[user_id] = []

    response = client.post(
        "/api/v1/query",
        json={"question": "test question", "course_id": 999, "user_id": user_id},
        headers=HEADERS,
    )
    assert response.status_code in [200, 500]


def test_rate_limit_different_users():
    """Different users should have separate rate limits."""
    from api.v1.routes.query import _rate_limit_store

    _rate_limit_store[11111] = [time.time() - 1] * 10
    _rate_limit_store[22222] = []

    response1 = client.post(
        "/api/v1/query",
        json={"question": "test", "course_id": 1, "user_id": 11111},
        headers=HEADERS,
    )
    assert response1.status_code == 429

    _rate_limit_store[22222] = []
    response2 = client.post(
        "/api/v1/query",
        json={"question": "test", "course_id": 999, "user_id": 22222},
        headers=HEADERS,
    )
    assert response2.status_code in [200, 500]


def test_error_handling_graceful_chromadb_failure():
    """ChromaDB failure should return graceful error message."""
    from unittest.mock import patch
    from core.vector_store import VectorStoreManager

    with patch.object(VectorStoreManager, 'similarity_search', side_effect=Exception("ChromaDB connection failed")):
        response = client.post(
            "/api/v1/query",
            json={"question": "test", "course_id": 1, "user_id": 55555},
            headers=HEADERS,
        )
        assert response.status_code == 200
        data = response.json()
        assert "temporarily unavailable" in data["answer"].lower()


def test_error_handling_graceful_llm_failure():
    """LLM failure should return graceful error message."""
    from unittest.mock import patch
    from core.llm_processor import LLMProcessor
    from core.vector_store import VectorStoreManager

    with patch.object(VectorStoreManager, 'similarity_search', return_value=[("context", {"source": "test"})]), \
         patch.object(LLMProcessor, 'answer_question', side_effect=Exception("Gemini API error")):

        response = client.post(
            "/api/v1/query",
            json={"question": "test", "course_id": 1, "user_id": 55556},
            headers=HEADERS,
        )
        assert response.status_code == 200
        data = response.json()
        assert "try again" in data["answer"].lower()
