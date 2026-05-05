# ============================================================
# Pytest unit tests for the FastAPI AI service
# Run with: pytest tests/ -v
# ============================================================

import pytest
from fastapi.testclient import TestClient
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
