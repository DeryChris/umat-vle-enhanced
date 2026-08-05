# ============================================================
# Tests for POST /api/v1/flashcards/generate (F3)
# Run with: pytest tests/test_flashcards_api.py -v
# ============================================================

import sys
import os
import pytest
from unittest.mock import patch, AsyncMock
from fastapi.testclient import TestClient

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from main import app
from config import get_settings

settings = get_settings()
client   = TestClient(app)

HEADERS = {"Authorization": f"Bearer {settings.ai_service_token}"}

GOOD_CARDS_JSON = """{"cards": [
    {"front": "What is a firewall?", "back": "A network security device filtering traffic.", "topic": "Week 1"},
    {"front": "Define OSI layer 2.", "back": "The data link layer.", "topic": "Week 1"},
    {"front": "", "back": "Broken card — must be dropped.", "topic": "Week 1"},
    {"front": "Duplicate?", "back": "Same content.", "topic": "Week 1"},
    {"front": "Duplicate?", "back": "Same content.", "topic": "Week 1"}
]}"""


def _payload(**overrides):
    data = {
        "course_id": 2,
        "material_ids": [11, 12],
        "count": 3,
        "role": "lecturer",
    }
    data.update(overrides)
    return data


class TestAuth:
    def test_missing_token_returns_403_not_authenticated(self):
        # FastAPI HTTPBearer auto-error → 403 "Not authenticated" when the
        # Authorization header is absent (401 only for a wrong token).
        response = client.post("/api/v1/flashcards/generate", json=_payload())
        assert response.status_code == 403

    def test_student_role_forbidden(self):
        with patch("api.v1.routes.flashcards._resolve_context") as mock_ctx:
            response = client.post(
                "/api/v1/flashcards/generate",
                json=_payload(role="student"),
                headers=HEADERS,
            )
            assert response.status_code == 403
            mock_ctx.assert_not_called()


class TestGenerate:
    @patch("api.v1.routes.flashcards._lecturer_llm")
    def test_generates_and_validates_cards(self, mock_llm):
        mock_llm.generate_assessment = AsyncMock(return_value=GOOD_CARDS_JSON)
        with patch("api.v1.routes.flashcards._resolve_context", return_value="material chunk text") as mock_ctx:
            response = client.post(
                "/api/v1/flashcards/generate",
                json=_payload(),
                headers=HEADERS,
            )
        assert response.status_code == 200
        data = response.json()
        assert data["total"] == 3  # 5 → 1 broken dropped, 1 duplicate dropped, capped at count=3
        assert all(c["front"] and c["back"] for c in data["cards"])
        assert data["cards"][0]["topic"] == "Week 1"
        assert data["llm_used"]
        # Grounding context and exact card count must be in the prompt.
        prompt = mock_llm.generate_assessment.await_args.args[0]
        assert "material chunk text" in prompt
        assert '"cards"' in prompt and "Exactly 3 cards" in prompt

    @patch("api.v1.routes.flashcards._lecturer_llm")
    def test_empty_material_ids_422(self, mock_llm):
        with patch("api.v1.routes.flashcards._fetch_material_context"):
            response = client.post(
                "/api/v1/flashcards/generate",
                json=_payload(material_ids=[]),
                headers=HEADERS,
            )
        assert response.status_code == 422
        mock_llm.generate_assessment.assert_not_called()

    @patch("api.v1.routes.flashcards._lecturer_llm")
    def test_no_indexed_content_404(self, mock_llm):
        with patch("api.v1.routes.flashcards._fetch_material_context", return_value=None):
            response = client.post(
                "/api/v1/flashcards/generate",
                json=_payload(),
                headers=HEADERS,
            )
        assert response.status_code == 404
        mock_llm.generate_assessment.assert_not_called()

    @patch("api.v1.routes.flashcards._lecturer_llm")
    def test_invalid_json_retries_then_succeeds(self, mock_llm):
        from api.v1.routes import flashcards as fc
        mock_llm.generate_assessment = AsyncMock(
            side_effect=["not json at all", GOOD_CARDS_JSON]
        )
        with patch("api.v1.routes.flashcards._resolve_context", return_value="chunks"):
            response = client.post(
                "/api/v1/flashcards/generate",
                json=_payload(count=2),
                headers=HEADERS,
            )
        assert response.status_code == 200
        assert response.json()["total"] == 2
        assert mock_llm.generate_assessment.await_count == 2

    @patch("api.v1.routes.flashcards._lecturer_llm")
    def test_retry_also_invalid_returns_500(self, mock_llm):
        mock_llm.generate_assessment = AsyncMock(side_effect=["nope", "still nope"])
        with patch("api.v1.routes.flashcards._resolve_context", return_value="chunks"):
            response = client.post(
                "/api/v1/flashcards/generate",
                json=_payload(),
                headers=HEADERS,
            )
        assert response.status_code == 500
        assert "invalid JSON" in response.json()["detail"]


class TestParseCardsJson:
    def test_fenced_json_stripped(self):
        from api.v1.routes.flashcards import _parse_cards_json
        raw = "```json\n{\"cards\": [{\"front\": \"a\", \"back\": \"b\"}]}\n```"
        cards = _parse_cards_json(raw)
        assert cards[0]["front"] == "a"

    def test_plain_list_accepted(self):
        from api.v1.routes.flashcards import _parse_cards_json
        cards = _parse_cards_json('[{"front": "a", "back": "b"}]')
        assert len(cards) == 1
