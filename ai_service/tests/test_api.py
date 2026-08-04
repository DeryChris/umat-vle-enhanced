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
    from rag.hybrid_retriever import HybridRetriever

    with patch.object(HybridRetriever, 'search', return_value=[("course material context", {"source": "test.pdf"})]), \
         patch.object(LLMProcessor, 'answer_with_prompt', side_effect=Exception("Gemini API error")):

        response = client.post(
            "/api/v1/query",
            json={
                "question": "Explain the water cycle in detail based on my course materials",
                "course_id": 1,
                "user_id": 55556,
            },
            headers=HEADERS,
        )
        assert response.status_code == 200
        data = response.json()
        assert "try again" in data["answer"].lower()


# ============================================================
# M1 — Smart Search (/api/v1/search) + structured citations
# ============================================================

def test_search_without_auth():
    """No bearer token should return 403."""
    response = client.post(
        "/api/v1/search",
        json={"query": "mining methods", "course_id": 1, "user_id": 1},
    )
    assert response.status_code == 403


def test_search_invalid_auth():
    """Wrong bearer token should return 401."""
    response = client.post(
        "/api/v1/search",
        json={"query": "mining methods", "course_id": 1, "user_id": 1},
        headers={"Authorization": "Bearer wrong-token"},
    )
    assert response.status_code == 401


def test_search_no_materials():
    """Unknown course → 200 with an empty (never null) results list."""
    response = client.post(
        "/api/v1/search",
        json={"query": "mining methods", "course_id": 99999, "user_id": 1},
        headers=HEADERS,
    )
    assert response.status_code == 200
    data = response.json()
    assert isinstance(data["results"], list)
    assert len(data["results"]) == 0


def test_search_validation_min_length():
    """Query shorter than 2 chars should be rejected by the schema (422)."""
    response = client.post(
        "/api/v1/search",
        json={"query": "a", "course_id": 1, "user_id": 1},
        headers=HEADERS,
    )
    assert response.status_code == 422


def test_search_rate_limit_exceeded_student():
    """Students over the per-minute limit get an empty result set (200)."""
    from api.v1.routes.query import _rate_limit_store, RATE_LIMIT_MAX
    import time as _t

    user_id = 70001
    _rate_limit_store[user_id] = []
    for _ in range(RATE_LIMIT_MAX):
        _rate_limit_store[user_id].append(_t.time() - 1)

    response = client.post(
        "/api/v1/search",
        json={"query": "mining methods", "course_id": 1, "user_id": user_id},
        headers=HEADERS,
    )
    assert response.status_code == 200
    assert response.json()["results"] == []


def test_search_lecturer_bypasses_rate_limit():
    """Lecturers are not throttled on search."""
    from api.v1.routes.query import _rate_limit_store, RATE_LIMIT_MAX
    import time as _t

    user_id = 70002
    _rate_limit_store[user_id] = []
    for _ in range(RATE_LIMIT_MAX):
        _rate_limit_store[user_id].append(_t.time() - 1)

    response = client.post(
        "/api/v1/search",
        json={"query": "mining methods", "course_id": 1, "user_id": user_id, "role": "lecturer"},
        headers=HEADERS,
    )
    assert response.status_code == 200
    assert "results" in response.json()


def test_search_sensitive_query_student_blocked():
    """Sensitive queries from students are refused with empty results."""
    from unittest.mock import patch

    with patch("api.v1.routes.search.is_sensitive_query", return_value=True):
        response = client.post(
            "/api/v1/search",
            json={"query": "how to make a bomb", "course_id": 1, "user_id": 70003},
            headers=HEADERS,
        )
    assert response.status_code == 200
    assert response.json()["results"] == []


def test_query_response_includes_citations():
    """QueryResponse now carries structured citations derived from chunks."""
    from unittest.mock import patch
    from core.llm_processor import LLMProcessor
    from rag.hybrid_retriever import HybridRetriever

    chunks = [
        ("[Page 3] Introduction to mineral exploration and sampling methods.",
         {"source": "lecture1.pdf", "material_id": 42, "chunk_index": 2,
          "rrf_score": 0.812, "visibility": "student", "source_type": "course_material"}),
        ("--- Slide 5 --- This slide covers the geology of gold deposits.",
         {"source": "lecture2.pptx", "material_id": 43, "chunk_index": 4,
          "rrf_score": 0.601, "visibility": "student", "source_type": "course_material"}),
    ]
    with patch.object(HybridRetriever, 'search', return_value=chunks), \
         patch('api.v1.query_pipeline.get_student_profile', return_value=None), \
         patch.object(LLMProcessor, 'answer_with_prompt', return_value="Mineral exploration starts with sampling [1]."):

        response = client.post(
            "/api/v1/query",
            json={"question": "How does mineral exploration start?", "course_id": 1, "user_id": 70004},
            headers=HEADERS,
        )
    assert response.status_code == 200
    data = response.json()
    assert "citations" in data
    cites = data["citations"]
    assert len(cites) == 2
    # 1-based numbering in rank order.
    assert [c["index"] for c in cites] == [1, 2]
    assert cites[0]["material_id"] == 42
    assert cites[0]["location"] == "Page 3"
    assert cites[0]["score"] == 0.812
    assert cites[1]["location"] == "Slide 5"
    assert cites[1]["title"] == "lecture2.pptx"
    # Backward-compatible sources still present.
    assert "sources" in data and len(data["sources"]) == 2


def test_build_citations_dedup_by_material():
    """Multiple chunks of the same material collapse into one citation."""
    from api.v1.query_pipeline import build_citations

    results = [
        ("[Page 1] First chunk of lecture.",
         {"source": "a.pdf", "material_id": 1, "chunk_index": 0, "rrf_score": 0.9}),
        ("[Page 2] Second chunk of the same lecture.",
         {"source": "a.pdf", "material_id": 1, "chunk_index": 1, "rrf_score": 0.8}),
        ("--- Slide 1 --- Different deck.",
         {"source": "b.pptx", "material_id": 2, "chunk_index": 0, "rrf_score": 0.7}),
    ]
    cites = build_citations(results, max_citations=6)
    assert len(cites) == 2
    assert [c.material_id for c in cites] == [1, 2]
    # First-seen chunk wins for the duplicate material.
    assert cites[0].chunk_index == 0
    assert cites[0].location == "Page 1"


def test_build_citations_snippet_cleaned_and_truncated():
    """Loader markers stripped from snippets; long snippets truncated to ~220 chars."""
    from api.v1.query_pipeline import build_citations

    long_doc = "[Page 1] " + "word " * 120
    results = [
        (long_doc, {"source": "c.pdf", "material_id": 7, "chunk_index": 0, "rrf_score": 0.5}),
        ("[01:23 - 01:45] The lecturer explains the concept of drilling.",
         {"source": "s1", "material_id": 8, "chunk_index": 1, "rrf_score": 0.4,
          "source_type": "transcript", "session_id": "bbb-9"}),
    ]
    cites = build_citations(results, max_citations=6)
    assert cites[0].snippet.startswith("word") and "Page" not in cites[0].snippet
    assert len(cites[0].snippet) <= 224
    assert cites[1].location == "01:23"
    assert cites[1].session_id == "bbb-9"


def test_build_citations_section_fallback():
    """Untagged chunks fall back to a 'Section N' label."""
    from api.v1.query_pipeline import build_citations

    cites = build_citations(
        [("Plain chunk with no markers.",
          {"source": "d.pdf", "material_id": 9, "chunk_index": 3, "rrf_score": 0.3})],
        max_citations=6,
    )
    assert cites[0].location == "Section 4"
