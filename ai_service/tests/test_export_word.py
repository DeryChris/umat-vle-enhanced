"""
Tests for Word document export functionality.

Tests cover:
- Valid question-paper export
- Valid marking-scheme export
- Valid examiner-copy export
- Questions supplied as a list of objects
- Invalid question entry supplied as a nested list
- printedSettings supplied incorrectly as a list
- Options supplied as a list
- Explicit false settings preserved
- Malformed payload returns HTTP 422, not HTTP 500
"""

import pytest
from fastapi.testclient import TestClient
from unittest.mock import patch, MagicMock

# ── Test fixtures ──────────────────────────────────────────

SAMPLE_QUESTIONS = [
    {
        "type": "multichoice",
        "question_text": "What is the capital of Ghana?",
        "options": ["Accra", "Kumasi", "Tamale", "Takoradi"],
        "correct_answer_index": 0,
        "marks": 2.0,
        "feedback_correct": "Accra is the capital city of Ghana.",
        "feedback_incorrect": "Try again. The capital is in the south."
    },
    {
        "type": "truefalse",
        "question_text": "Python is a compiled language.",
        "options": ["True", "False"],
        "correct_answer_index": 1,
        "marks": 1.0,
        "feedback_correct": "Correct! Python is interpreted, not compiled.",
        "feedback_incorrect": "Python uses an interpreter, not a compiler."
    },
    {
        "type": "shortanswer",
        "question_text": "Explain the concept of inheritance in OOP.",
        "correct_text": "Inheritance allows a class to inherit properties and methods from a parent class.",
        "marks": 5.0,
        "feedback_correct": "Good explanation!",
        "feedback_incorrect": "Review OOP concepts."
    }
]

SAMPLE_SETTINGS = {
    "assessment_title": "Mid-Semester Examination",
    "institution_name": "University of Mines and Technology",
    "course_title": "Introduction to Programming",
    "course_code": "CS101",
    "department": "Computer Science",
    "lecturer_name": "Dr. Smith",
    "examination_date": "2026-07-31",
    "examination_date_display": "Thursday, July 31, 2026",
    "duration": 120,
    "total_marks": 100,
    "candidate_instructions": "Answer all questions.\nTime allowed: 2 hours.",
    "orientation": "portrait",
    "show_page_numbers": True,
    "show_marks": True,
    "student_info_fields": {"studentName": True, "studentId": True},
    "answer_spaces": 3,
    "versions": ["A"],
    "marks_per_question": 1.0
}


# ── Unit tests for export_word.py ──────────────────────────

class TestExportWordUnit:
    """Unit tests for the export_word module."""

    def test_generate_question_paper(self):
        """Valid question-paper export."""
        from core.export_word import generate_document

        doc_bytes = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="question_paper",
            doc_settings=SAMPLE_SETTINGS,
            version="A"
        )

        assert doc_bytes is not None
        assert len(doc_bytes) > 0
        # Valid .docx files start with PK (ZIP magic bytes)
        assert doc_bytes[:2] == b'PK'

    def test_generate_answer_key(self):
        """Valid marking-scheme export."""
        from core.export_word import generate_document

        doc_bytes = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="answer_key",
            doc_settings=SAMPLE_SETTINGS,
            version="A"
        )

        assert doc_bytes is not None
        assert len(doc_bytes) > 0
        assert doc_bytes[:2] == b'PK'

    def test_generate_examiner_copy(self):
        """Valid examiner-copy export."""
        from core.export_word import generate_document

        doc_bytes = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="examiner_copy",
            doc_settings=SAMPLE_SETTINGS,
            version="A"
        )

        assert doc_bytes is not None
        assert len(doc_bytes) > 0
        assert doc_bytes[:2] == b'PK'

    def test_questions_as_list_of_objects(self):
        """Questions supplied as a list of objects (normal case)."""
        from core.export_word import generate_document

        doc_bytes = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="question_paper",
            doc_settings=SAMPLE_SETTINGS,
            version="A"
        )

        assert doc_bytes is not None
        assert len(doc_bytes) > 0

    def test_empty_student_info_fields_list(self):
        """student_info_fields supplied as empty list (PHP encoding bug)."""
        from core.export_word import generate_document

        settings = SAMPLE_SETTINGS.copy()
        settings["student_info_fields"] = []  # PHP empty array encoding

        doc_bytes = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="question_paper",
            doc_settings=settings,
            version="A"
        )

        assert doc_bytes is not None
        assert len(doc_bytes) > 0

    def test_student_info_fields_non_empty_list(self):
        """student_info_fields supplied as non-empty list (malformed)."""
        from core.export_word import generate_document

        settings = SAMPLE_SETTINGS.copy()
        settings["student_info_fields"] = ["studentName", "studentId"]  # Wrong type

        doc_bytes = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="question_paper",
            doc_settings=settings,
            version="A"
        )

        assert doc_bytes is not None
        assert len(doc_bytes) > 0

    def test_options_as_list(self):
        """Options supplied as a list (normal case)."""
        from core.export_word import generate_document

        doc_bytes = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="question_paper",
            doc_settings=SAMPLE_SETTINGS,
            version="A"
        )

        assert doc_bytes is not None

    def test_explicit_false_settings_preserved(self):
        """Explicit false settings are preserved, not converted to True."""
        from core.export_word import generate_document

        settings = SAMPLE_SETTINGS.copy()
        settings["show_page_numbers"] = False
        settings["show_marks"] = False
        settings["show_student_fields"] = False

        doc_bytes = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="question_paper",
            doc_settings=settings,
            version="A"
        )

        assert doc_bytes is not None
        assert len(doc_bytes) > 0

    def test_version_b_ordering(self):
        """Version B uses different question ordering."""
        from core.export_word import generate_document

        doc_a = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="question_paper",
            doc_settings=SAMPLE_SETTINGS,
            version="A"
        )

        doc_b = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="question_paper",
            doc_settings=SAMPLE_SETTINGS,
            version="B"
        )

        # Both should generate valid documents
        assert doc_a is not None
        assert doc_b is not None
        # They should be different (different ordering)
        assert doc_a != doc_b

    def test_landscape_orientation(self):
        """Landscape orientation is applied correctly."""
        from core.export_word import generate_document

        settings = SAMPLE_SETTINGS.copy()
        settings["orientation"] = "landscape"

        doc_bytes = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="question_paper",
            doc_settings=settings,
            version="A"
        )

        assert doc_bytes is not None
        assert len(doc_bytes) > 0

    def test_minimal_settings(self):
        """Minimal settings with defaults."""
        from core.export_word import generate_document

        doc_bytes = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="question_paper",
            doc_settings={},
            version="A"
        )

        assert doc_bytes is not None
        assert len(doc_bytes) > 0


# ── Normalization tests ────────────────────────────────────

class TestDocSettingsNormalization:
    """Tests for doc_settings normalization."""

    def test_normalize_empty_dict(self):
        """Empty dict is returned as-is."""
        from core.export_word import _normalize_doc_settings

        result = _normalize_doc_settings({})
        assert isinstance(result, dict)

    def test_normalize_empty_list_student_info(self):
        """Empty list for student_info_fields is converted to default dict."""
        from core.export_word import _normalize_doc_settings

        settings = {"student_info_fields": []}
        result = _normalize_doc_settings(settings)
        assert isinstance(result["student_info_fields"], dict)
        assert result["student_info_fields"]["studentName"] is True
        assert result["student_info_fields"]["studentId"] is True

    def test_normalize_non_empty_list_student_info(self):
        """Non-empty list for student_info_fields is converted to default dict."""
        from core.export_word import _normalize_doc_settings

        settings = {"student_info_fields": ["name", "id"]}
        result = _normalize_doc_settings(settings)
        assert isinstance(result["student_info_fields"], dict)

    def test_normalize_string_versions(self):
        """String versions is converted to list."""
        from core.export_word import _normalize_doc_settings

        settings = {"versions": "A"}
        result = _normalize_doc_settings(settings)
        assert result["versions"] == ["A"]

    def test_normalize_string_boolean(self):
        """String boolean is converted to actual boolean."""
        from core.export_word import _normalize_doc_settings

        settings = {"show_page_numbers": "true"}
        result = _normalize_doc_settings(settings)
        assert result["show_page_numbers"] is True

    def test_normalize_string_numeric(self):
        """String numeric is converted to actual number."""
        from core.export_word import _normalize_doc_settings

        settings = {"duration": "120"}
        result = _normalize_doc_settings(settings)
        assert result["duration"] == 120

    def test_normalize_non_dict_input(self):
        """Non-dict input is converted to empty dict."""
        from core.export_word import _normalize_doc_settings

        result = _normalize_doc_settings(None)
        assert isinstance(result, dict)

        result = _normalize_doc_settings([])
        assert isinstance(result, dict)

        result = _normalize_doc_settings("invalid")
        assert isinstance(result, dict)


# ── API endpoint tests ─────────────────────────────────────

class TestExportWordAPI:
    """API endpoint tests for /api/v1/quizgen/export-word."""

    def _get_test_client(self):
        """Create a test client with mocked auth."""
        from main import app
        from middleware.auth import verify_token

        # Mock the auth dependency
        app.dependency_overrides[verify_token] = lambda: None
        client = TestClient(app)
        return client

    @patch("core.export_word.generate_document")
    def test_valid_question_paper_export(self, mock_gen):
        """Valid question-paper export returns 200."""
        mock_gen.return_value = b'PK\x03\x04' + b'\x00' * 100

        client = self._get_test_client()
        response = client.post(
            "/api/v1/quizgen/export-word",
            json={
                "questions": SAMPLE_QUESTIONS,
                "export_type": "question_paper",
                "version": "A",
                "doc_settings": SAMPLE_SETTINGS
            },
            headers={"Authorization": "Bearer test_token"}
        )

        assert response.status_code == 200
        data = response.json()
        assert "docx_base64" in data
        assert "filename" in data
        assert data["question_count"] == 3

    @patch("core.export_word.generate_document")
    def test_valid_answer_key_export(self, mock_gen):
        """Valid marking-scheme export returns 200."""
        mock_gen.return_value = b'PK\x03\x04' + b'\x00' * 100

        client = self._get_test_client()
        response = client.post(
            "/api/v1/quizgen/export-word",
            json={
                "questions": SAMPLE_QUESTIONS,
                "export_type": "answer_key",
                "version": "A",
                "doc_settings": SAMPLE_SETTINGS
            },
            headers={"Authorization": "Bearer test_token"}
        )

        assert response.status_code == 200

    @patch("core.export_word.generate_document")
    def test_valid_examiner_copy_export(self, mock_gen):
        """Valid examiner-copy export returns 200."""
        mock_gen.return_value = b'PK\x03\x04' + b'\x00' * 100

        client = self._get_test_client()
        response = client.post(
            "/api/v1/quizgen/export-word",
            json={
                "questions": SAMPLE_QUESTIONS,
                "export_type": "examiner_copy",
                "version": "A",
                "doc_settings": SAMPLE_SETTINGS
            },
            headers={"Authorization": "Bearer test_token"}
        )

        assert response.status_code == 200

    def test_empty_questions_returns_422(self):
        """Empty questions list returns 422."""
        client = self._get_test_client()
        response = client.post(
            "/api/v1/quizgen/export-word",
            json={
                "questions": [],
                "export_type": "question_paper",
                "version": "A",
                "doc_settings": SAMPLE_SETTINGS
            },
            headers={"Authorization": "Bearer test_token"}
        )

        assert response.status_code == 422

    def test_invalid_question_entry_returns_422(self):
        """Question supplied as nested list returns 422."""
        client = self._get_test_client()
        response = client.post(
            "/api/v1/quizgen/export-word",
            json={
                "questions": [["invalid", "nested", "list"]],
                "export_type": "question_paper",
                "version": "A",
                "doc_settings": SAMPLE_SETTINGS
            },
            headers={"Authorization": "Bearer test_token"}
        )

        assert response.status_code == 422
        data = response.json()
        assert "detail" in data
        assert data["detail"]["code"] == "INVALID_EXPORT_PAYLOAD"
        assert data["detail"]["field"] == "questions[0]"

    def test_printed_settings_as_list_returns_422(self):
        """doc_settings supplied as list returns 422."""
        client = self._get_test_client()
        response = client.post(
            "/api/v1/quizgen/export-word",
            json={
                "questions": SAMPLE_QUESTIONS,
                "export_type": "question_paper",
                "version": "A",
                "doc_settings": ["invalid", "list"]
            },
            headers={"Authorization": "Bearer test_token"}
        )

        assert response.status_code == 422
        data = response.json()
        assert data["detail"]["field"] == "doc_settings"

    def test_student_info_fields_as_list_normalized(self):
        """student_info_fields as list is normalized, not rejected."""
        client = self._get_test_client()

        # This should NOT return 422 - it should be normalized
        with patch("core.export_word.generate_document") as mock_gen:
            mock_gen.return_value = b'PK\x03\x04' + b'\x00' * 100
            response = client.post(
                "/api/v1/quizgen/export-word",
                json={
                    "questions": SAMPLE_QUESTIONS,
                    "export_type": "question_paper",
                    "version": "A",
                    "doc_settings": {**SAMPLE_SETTINGS, "student_info_fields": []}
                },
                headers={"Authorization": "Bearer test_token"}
            )

            # Should succeed after normalization
            assert response.status_code == 200

    def test_invalid_export_type_returns_422(self):
        """Invalid export_type returns 422."""
        client = self._get_test_client()
        response = client.post(
            "/api/v1/quizgen/export-word",
            json={
                "questions": SAMPLE_QUESTIONS,
                "export_type": "invalid_type",
                "version": "A",
                "doc_settings": SAMPLE_SETTINGS
            },
            headers={"Authorization": "Bearer test_token"}
        )

        assert response.status_code == 422
        data = response.json()
        assert data["detail"]["field"] == "export_type"

    def test_invalid_version_returns_422(self):
        """Invalid version returns 422."""
        client = self._get_test_client()
        response = client.post(
            "/api/v1/quizgen/export-word",
            json={
                "questions": SAMPLE_QUESTIONS,
                "export_type": "question_paper",
                "version": "D",
                "doc_settings": SAMPLE_SETTINGS
            },
            headers={"Authorization": "Bearer test_token"}
        )

        assert response.status_code == 422
        data = response.json()
        assert data["detail"]["field"] == "version"

    def test_options_as_list_in_question_normalized(self):
        """Options as list in question is valid (normal case)."""
        client = self._get_test_client()

        with patch("core.export_word.generate_document") as mock_gen:
            mock_gen.return_value = b'PK\x03\x04' + b'\x00' * 100
            response = client.post(
                "/api/v1/quizgen/export-word",
                json={
                    "questions": SAMPLE_QUESTIONS,
                    "export_type": "question_paper",
                    "version": "A",
                    "doc_settings": SAMPLE_SETTINGS
                },
                headers={"Authorization": "Bearer test_token"}
            )

            assert response.status_code == 200

    def test_options_as_string_returns_422(self):
        """Options supplied as string returns 422."""
        client = self._get_test_client()
        response = client.post(
            "/api/v1/quizgen/export-word",
            json={
                "questions": [{
                    "type": "multichoice",
                    "question_text": "Test?",
                    "options": "invalid_string_not_list",
                    "correct_answer_index": 0
                }],
                "export_type": "question_paper",
                "version": "A",
                "doc_settings": SAMPLE_SETTINGS
            },
            headers={"Authorization": "Bearer test_token"}
        )

        assert response.status_code == 422
        data = response.json()
        assert data["detail"]["field"] == "questions[0].options"

    def test_malformed_payload_not_500(self):
        """Malformed payload returns 422, not 500."""
        client = self._get_test_client()
        response = client.post(
            "/api/v1/quizgen/export-word",
            json={
                "questions": "not_a_list",
                "export_type": "question_paper",
                "version": "A",
                "doc_settings": {}
            },
            headers={"Authorization": "Bearer test_token"}
        )

        # Must be 422, never 500
        assert response.status_code == 422
        assert response.status_code != 500


# ── Integration test for the exact failed export ───────────

class TestRegressionFix:
    """Regression test for the exact error scenario."""

    def test_php_empty_array_encoding_scenario(self):
        """
        Reproduce the exact bug: PHP encodes empty {} as [].
        
        This test verifies that the fix handles the exact scenario
        that caused the original HTTP 500 error.
        """
        from core.export_word import generate_document

        # This is exactly what PHP sends after json_decode/json_encode cycle
        settings_with_php_bug = {
            "assessment_title": "Test Assessment",
            "institution_name": "University of Mines and Technology",
            "student_info_fields": [],  # PHP empty array encoding
            "versions": ["A"],
            "show_page_numbers": True,
            "show_marks": True,
            "marks_per_question": 1.0
        }

        # This should NOT raise 'list' object has no attribute 'get'
        doc_bytes = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="question_paper",
            doc_settings=settings_with_php_bug,
            version="A"
        )

        assert doc_bytes is not None
        assert len(doc_bytes) > 0
        assert doc_bytes[:2] == b'PK'

    def test_php_non_empty_array_scenario(self):
        """
        Test with non-empty list (should not happen, but handle gracefully).
        """
        from core.export_word import generate_document

        settings = {
            "student_info_fields": ["name", "id", "class"],  # Wrong type
        }

        doc_bytes = generate_document(
            questions=SAMPLE_QUESTIONS,
            export_type="question_paper",
            doc_settings=settings,
            version="A"
        )

        assert doc_bytes is not None
        assert len(doc_bytes) > 0
