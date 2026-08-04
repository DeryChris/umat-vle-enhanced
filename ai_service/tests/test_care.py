import pytest
import sys
import os
from unittest.mock import MagicMock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from api.v1.query_pipeline import prepare_query, detect_task
from care.classifier import CAREAClassifier, CareResult
from care.course_profile import CourseProfile, CourseProfileBuilder
from models.schemas import QueryRequest


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
    db = MagicMock()
    db.query.return_value.filter.return_value.order_by.return_value.limit.return_value.all.return_value = []
    return db


def _fake_profile(course_id=1, title="E-Commerce", has_materials=True, keywords=None):
    return CourseProfile(
        course_id=course_id,
        course_title=title,
        topics=["EC 2.pdf", "Lecture 1.pdf"],
        keywords=keywords or ["ecommerce", "business", "digital", "marketing", "online", "trade", "payment"],
        chunk_count=70 if has_materials else 0,
    )


def _chunk(text, source="EC 2.pdf", score=0.05, material_id="1"):
    return (text, {"source": source, "material_id": material_id, "rrf_score": score})


class TestCAREAClassifier:
    """Unit tests for the CARE classifier logic."""

    def setup_method(self):
        self.classifier = CAREAClassifier()

    def test_curriculum_grounded_strong_rag(self):
        profile = _fake_profile()
        results = [_chunk("E-commerce involves online transactions.", score=0.06)]
        result = self.classifier.classify("qa", "explain e-commerce", profile, results)
        assert result.mode == "curriculum_grounded"
        assert result.use_rag is True
        assert result.show_sources is True
        assert result.response_depth == "full"

    def test_curriculum_grounded_with_off_topic_keyword_but_academic_signal(self):
        profile = _fake_profile()
        question = "explain the advantages of e-commerce business models"
        results = [_chunk("Advantages include global reach and lower costs.", score=0.05)]
        result = self.classifier.classify("qa", question, profile, results)
        assert result.mode == "curriculum_grounded"
        assert result.use_rag is True

    def test_general_academic_weak_rag(self):
        profile = _fake_profile()
        results = [_chunk("Some content.", score=0.01)]
        result = self.classifier.classify("qa", "explain database normalization", profile, results)
        assert result.mode == "general_academic"
        assert result.show_sources is False
        assert result.source_mode == "general"

    def test_general_academic_no_materials_strong_signal(self):
        profile = _fake_profile(has_materials=False)
        results = []
        result = self.classifier.classify("qa", "explain supply chain management", profile, results)
        assert result.mode == "general_academic"
        assert result.show_sources is False

    def test_outside_scope_off_topic(self):
        profile = _fake_profile()
        results = []
        result = self.classifier.classify("qa", "what is love", profile, results)
        assert result.mode == "outside_scope"
        assert result.use_rag is False
        assert result.show_sources is False
        assert result.response_depth == "brief"

    def test_outside_scope_with_off_topic_task(self):
        profile = _fake_profile()
        results = []
        result = self.classifier.classify("off_topic", "tell me a joke", profile, results)
        assert result.mode == "outside_scope"
        assert result.show_sources is False

    def test_greeting_routed_outside_scope(self):
        profile = _fake_profile()
        results = []
        result = self.classifier.classify("greeting", "hello", profile, results)
        assert result.mode == "outside_scope"
        assert result.use_rag is False

    def test_chitchat_routed_outside_scope(self):
        profile = _fake_profile()
        results = []
        result = self.classifier.classify("chitchat", "how are you", profile, results)
        assert result.mode == "outside_scope"

    def test_different_courses_different_classification(self):
        profile_ecom = _fake_profile(title="E-Commerce", keywords=["ecommerce", "b2b", "b2c"])
        profile_mining = _fake_profile(title="Mining Engineering", keywords=["mining", "mineral", "geology"])

        q = "what is mineral processing"
        r_ecom = self.classifier.classify("qa", q, profile_ecom, [])
        r_mining = self.classifier.classify("qa", q, profile_mining, [])

        assert r_ecom.mode == "general_academic"
        assert r_mining.mode == "general_academic"

        q2 = "explain e-commerce business models"
        r_ecom2 = self.classifier.classify("qa", q2, profile_ecom, [
            _chunk("B2B and B2C are key e-commerce models.", score=0.05),
        ])
        assert r_ecom2.mode == "curriculum_grounded"

    def test_quiz_command_not_outsidescope(self):
        profile = _fake_profile()
        results = [_chunk("Quiz content.", score=0.04)]
        result = self.classifier.classify("quiz", "create a quiz on e-commerce", profile, results)
        assert result.mode == "curriculum_grounded"
        assert result.use_rag is True

    def test_low_similarity_no_sources(self):
        profile = _fake_profile()
        results = [_chunk("Low relevance text.", score=0.01)]
        result = self.classifier.classify("qa", "e-commerce trends", profile, results)
        assert result.show_sources is False


class TestPrepareQueryIntegration:
    """Integration tests for prepare_query with CARE routing."""

    @pytest.fixture(autouse=True)
    def _patch_dependencies(self, monkeypatch):
        self._mock_results = []

        def fake_search(course_id, query, n_results=5, material_ids=None, role="student"):
            return list(self._mock_results)

        monkeypatch.setattr("api.v1.query_pipeline.hybrid.search", fake_search)
        monkeypatch.setattr("api.v1.query_pipeline.get_student_profile", lambda *a, **kw: None)

        real_builder = CourseProfileBuilder()

        def fake_build(course_id):
            return _fake_profile()

        monkeypatch.setattr("api.v1.query_pipeline._profile_builder.build", fake_build)

    def _set_results(self, docs_with_meta):
        self._mock_results = docs_with_meta

    def test_01_course_question_strong_retrieval(self):
        self._set_results([
            ("E-commerce involves online transactions between buyers and sellers.",
             {"source": "EC 2.pdf", "material_id": "1", "rrf_score": 0.06}),
        ])
        req = _make_request("explain e-commerce")
        prepared = prepare_query(req, _mock_db())
        assert "EC 2.pdf" in prepared.sources
        assert prepared.instant_answer is None
        assert prepared.prompt is not None

    def test_02_course_question_weak_retrieval(self):
        self._set_results([
            ("Tangential content.", {"source": "EC 2.pdf", "material_id": "1", "rrf_score": 0.01}),
        ])
        req = _make_request("explain e-commerce")
        prepared = prepare_query(req, _mock_db())
        assert prepared.sources == []
        assert prepared.prompt is not None
        assert "general academic knowledge" in prepared.prompt or "academically relevant" in prepared.prompt

    def test_03_foundational_concept_no_materials(self):
        self._set_results([])
        req = _make_request("what is a database")
        prepared = prepare_query(req, _mock_db())
        assert prepared.sources == []
        assert prepared.prompt is not None

    def test_04_unrelated_question(self):
        self._set_results([])
        req = _make_request("what is love")
        prepared = prepare_query(req, _mock_db())
        assert prepared.sources == []
        assert prepared.instant_answer is not None
        assert "academic" in prepared.instant_answer.lower()
        assert "love" in prepared.instant_answer.lower()

    def test_06_greeting(self):
        req = _make_request("hello")
        prepared = prepare_query(req, _mock_db())
        assert prepared.sources == []
        assert prepared.instant_answer is not None
        assert prepared.task == "greeting"

    def test_07_quiz_command(self):
        self._set_results([
            ("E-commerce content for quiz.",
             {"source": "EC 2.pdf", "material_id": "1", "rrf_score": 0.04}),
        ])
        req = _make_request("create a quiz on e-commerce")
        prepared = prepare_query(req, _mock_db())
        assert prepared.prompt is not None
        assert prepared.task == "quiz"

    def test_08_low_similarity_no_displayed_sources(self):
        self._set_results([
            ("Low relevance.", {"source": "EC 2.pdf", "material_id": "1", "rrf_score": 0.005}),
        ])
        req = _make_request("e-commerce trends")
        prepared = prepare_query(req, _mock_db())
        assert prepared.sources == []

    def test_09_citations_not_leaked(self):
        self._set_results([
            ("Doc A content.", {"source": "A.pdf", "material_id": "1", "rrf_score": 0.05}),
        ])
        req1 = _make_request("query one")
        p1 = prepare_query(req1, _mock_db())
        assert "A.pdf" in p1.sources

        self._set_results([
            ("Doc B content.", {"source": "B.pdf", "material_id": "2", "rrf_score": 0.05}),
        ])
        req2 = _make_request("query two")
        p2 = prepare_query(req2, _mock_db())
        assert "B.pdf" in p2.sources
        assert "A.pdf" not in p2.sources

    def test_10_borderline_no_false_citations(self):
        self._set_results([
            ("Weak match.", {"source": "Weak.pdf", "material_id": "1", "rrf_score": 0.015}),
        ])
        req = _make_request("hello world")
        prepared = prepare_query(req, _mock_db())
        assert "Weak.pdf" not in prepared.sources

    def test_11_stream_and_rag_still_function(self):
        self._set_results([
            ("E-commerce definition.", {"source": "EC 2.pdf", "material_id": "1", "rrf_score": 0.06}),
        ])
        req = _make_request("what is e-commerce", course_id=2)
        prepared = prepare_query(req, _mock_db())
        assert prepared.prompt is not None
        assert prepared.instant_answer is None
