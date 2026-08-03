# ============================================================
# Tests for RAG grounding and citation behaviour
# Run with: pytest tests/test_rag_citations.py -v
# ============================================================

import pytest
import sys
import os
from unittest.mock import MagicMock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from api.v1.query_pipeline import detect_task, prepare_query, _MIN_RRF_THRESHOLD
from models.schemas import QueryRequest


# ── detect_task tests ────────────────────────────────────────

class TestDetectTask:
    """Verify task classification for various input patterns."""

    def test_pure_greeting(self):
        assert detect_task("Hello") == "greeting"
        assert detect_task("Hi") == "greeting"
        assert detect_task("Hey there") == "greeting"
        assert detect_task("Good morning") == "greeting"
        assert detect_task("Good afternoon") == "greeting"
        assert detect_task("Howdy") == "greeting"
        assert detect_task("Yo") == "greeting"
        assert detect_task("Sup") == "greeting"

    def test_greeting_with_punctuation(self):
        assert detect_task("Hello!") == "greeting"
        assert detect_task("Hi!") == "greeting"
        assert detect_task("Hey!") == "greeting"

    def test_off_topic_with_greeting_prefix(self):
        """Greeting + non-course content should be off_topic, not qa."""
        assert detect_task("Hello, I want to fall in love") == "off_topic"
        assert detect_task("Hi, tell me a joke") == "off_topic"
        assert detect_task("Hey, what's the weather today") == "off_topic"
        assert detect_task("Hello, who won the football match") == "off_topic"

    def test_greeting_with_course_question(self):
        """Greeting + course question should be handled by course patterns."""
        assert detect_task("Hello, explain photosynthesis") == "explain"
        assert detect_task("Hi, what is mining engineering") == "explain"
        assert detect_task("Hey, define mineral processing") == "explain"

    def test_chitchat(self):
        assert detect_task("How are you") == "chitchat"
        assert detect_task("What's up") == "chitchat"
        assert detect_task("I'm doing great") == "chitchat"
        assert detect_task("I am fine") == "chitchat"

    def test_off_topic_without_greeting(self):
        """Non-course content without greeting prefix but no course signals."""
        assert detect_task("I want to fall in love") == "off_topic"
        assert detect_task("Tell me a joke") == "off_topic"
        assert detect_task("What's the weather forecast") == "off_topic"

    def test_course_questions(self):
        assert detect_task("Explain the rock cycle") == "explain"
        assert detect_task("What is photosynthesis") == "explain"
        assert detect_task("Summarize chapter 3") == "summary"
        assert detect_task("Create a quiz on geology") == "quiz"
        assert detect_task("Prepare me for the exam") == "exam_prep"

    def test_qa_fallback(self):
        """Questions about course topics without specific task keywords."""
        assert detect_task("Tell me about gold mining in Ghana") == "explain"
        assert detect_task("How does acid mine drainage work") == "explain"


# ── prepare_query integration tests (with mocked retrieval) ──

def _make_request(question, course_id=1, user_id=1, role="student", material_ids=None):
    return QueryRequest(
        question=question,
        course_id=course_id,
        user_id=user_id,
        role=role,
        session_key="test_session",
        material_ids=material_ids or [],
    )


def _mock_db():
    """Return a mock DB session that returns empty chat logs."""
    db = MagicMock()
    db.query.return_value.filter.return_value.order_by.return_value.limit.return_value.all.return_value = []
    return db


class TestPrepareQueryCitations:
    """Test citation behaviour in the query pipeline."""

    @pytest.fixture(autouse=True)
    def _patch_hybrid(self, monkeypatch):
        """Mock hybrid search and student profile to return controlled results."""
        self._mock_results = []

        def fake_search(course_id, query, n_results=5, material_ids=None, role="student"):
            return list(self._mock_results)

        monkeypatch.setattr(
            "api.v1.query_pipeline.hybrid.search", fake_search
        )
        # Return None for student profile so prompt builder uses defaults
        monkeypatch.setattr(
            "api.v1.query_pipeline.get_student_profile", lambda *a, **kw: None
        )

    def _set_results(self, docs_with_meta):
        """Set what the mock retriever returns. Each item is (doc_text, metadata_dict)."""
        self._mock_results = docs_with_meta

    def test_greeting_returns_no_sources(self):
        req = _make_request("Hello")
        prepared = prepare_query(req, _mock_db())
        assert prepared.sources == []
        assert prepared.instant_answer is not None
        assert prepared.task == "greeting"

    def test_off_topic_returns_no_sources(self):
        """Test 1: Unrelated personal question returns no course citation."""
        req = _make_request("Hello, I want to fall in love")
        prepared = prepare_query(req, _mock_db())
        assert prepared.sources == []
        assert prepared.instant_answer is not None
        assert prepared.task == "off_topic"

    def test_chitchat_returns_no_sources(self):
        """Test 2: Greeting returns no citation."""
        req = _make_request("How are you")
        prepared = prepare_query(req, _mock_db())
        assert prepared.sources == []
        assert prepared.instant_answer is not None

    def test_relevant_chunks_return_sources(self):
        """Test 3: Relevant course question returns only genuinely supporting sources."""
        self._set_results([
            ("Gold mining is a major industry in Ghana...", {
                "source": "EC 2.pdf", "material_id": "1", "rrf_score": 0.05,
            }),
            ("Mineral processing involves crushing and grinding...", {
                "source": "EC 2.pdf", "material_id": "1", "rrf_score": 0.04,
            }),
        ])
        req = _make_request("Explain gold mining processes")
        prepared = prepare_query(req, _mock_db())
        assert "EC 2.pdf" in prepared.sources
        assert len(prepared.sources) == 1

    def test_low_similarity_chunks_excluded(self):
        """Test 4: Low-similarity chunks are excluded, but LLM still answers from general knowledge."""
        self._set_results([
            ("Some tangential content...", {
                "source": "EC 2.pdf", "material_id": "1", "rrf_score": 0.01,
            }),
            ("Another weak match...", {
                "source": "EC 3.pdf", "material_id": "2", "rrf_score": 0.005,
            }),
        ])
        req = _make_request("What is photosynthesis")
        prepared = prepare_query(req, _mock_db())
        assert prepared.sources == []
        # LLM should still answer (no instant_answer blocking)
        assert prepared.instant_answer is None
        assert prepared.prompt is not None

    def test_mixed_relevant_and_weak_chunks(self):
        """Only high-score chunks contribute to sources."""
        self._set_results([
            ("Strong match about mining...", {
                "source": "Mining.pdf", "material_id": "1", "rrf_score": 0.06,
            }),
            ("Weak unrelated match...", {
                "source": "Unrelated.pdf", "material_id": "2", "rrf_score": 0.01,
            }),
        ])
        req = _make_request("Explain mining techniques")
        prepared = prepare_query(req, _mock_db())
        assert "Mining.pdf" in prepared.sources
        assert "Unrelated.pdf" not in prepared.sources

    def test_no_relevant_evidence_empty_citations(self):
        """Test 6: If retrieval returns no relevant evidence, citation list is empty."""
        self._set_results([])
        req = _make_request("Quantum entanglement explained")
        prepared = prepare_query(req, _mock_db())
        assert prepared.sources == []

    def test_filename_matches_chunk_source(self):
        """Test 7: Displayed filename matches the actual supporting chunk."""
        self._set_results([
            ("Content from lecture 5...", {
                "source": "Lecture 5 - Geology.pdf", "material_id": "3", "rrf_score": 0.05,
            }),
        ])
        req = _make_request("What is covered in lecture 5")
        prepared = prepare_query(req, _mock_db())
        assert prepared.sources == ["Lecture 5 - Geology.pdf"]

    def test_citations_not_leaked_from_previous_query(self):
        """Test 5: Citations from a previous response do not appear in the next response."""
        # First query
        self._set_results([
            ("First query content...", {
                "source": "FirstDoc.pdf", "material_id": "1", "rrf_score": 0.05,
            }),
        ])
        req1 = _make_request("What is mining")
        prepared1 = prepare_query(req1, _mock_db())
        assert "FirstDoc.pdf" in prepared1.sources

        # Second query — different results, FirstDoc.pdf should NOT appear
        self._set_results([
            ("Second query content...", {
                "source": "SecondDoc.pdf", "material_id": "2", "rrf_score": 0.05,
            }),
        ])
        req2 = _make_request("Explain geology")
        prepared2 = prepare_query(req2, _mock_db())
        assert "SecondDoc.pdf" in prepared2.sources
        assert "FirstDoc.pdf" not in prepared2.sources

    def test_rrf_threshold_value(self):
        """Verify the threshold is reasonable."""
        assert 0.01 < _MIN_RRF_THRESHOLD < 0.1


# ── Relevance threshold unit tests ──────────────────────────

class TestRelevanceThreshold:
    """Verify chunk filtering against the threshold."""

    def test_chunk_at_threshold_included(self):
        """A chunk exactly at the threshold is included."""
        results = [("doc", {"source": "a.pdf", "rrf_score": _MIN_RRF_THRESHOLD})]
        filtered = [(d, m) for d, m in results if m.get("rrf_score", 0) >= _MIN_RRF_THRESHOLD]
        assert len(filtered) == 1

    def test_chunk_below_threshold_excluded(self):
        """A chunk below the threshold is excluded."""
        results = [("doc", {"source": "a.pdf", "rrf_score": _MIN_RRF_THRESHOLD - 0.001})]
        filtered = [(d, m) for d, m in results if m.get("rrf_score", 0) >= _MIN_RRF_THRESHOLD]
        assert len(filtered) == 0

    def test_chunk_above_threshold_included(self):
        """A chunk above the threshold is included."""
        results = [("doc", {"source": "a.pdf", "rrf_score": _MIN_RRF_THRESHOLD + 0.01})]
        filtered = [(d, m) for d, m in results if m.get("rrf_score", 0) >= _MIN_RRF_THRESHOLD]
        assert len(filtered) == 1

    def test_missing_score_treated_as_zero(self):
        """A chunk with no rrf_score is treated as 0 and excluded."""
        results = [("doc", {"source": "a.pdf"})]
        filtered = [(d, m) for d, m in results if m.get("rrf_score", 0) >= _MIN_RRF_THRESHOLD]
        assert len(filtered) == 0

