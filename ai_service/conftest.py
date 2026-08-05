# ============================================================
# Shared pytest fixtures for the UMaT AI Service test suite.
#
# Fixes cross-test pollution: test_export_word.py (and potentially other
# tests) installs `app.dependency_overrides[verify_token]` and never
# clears it, which silently disables auth for every later test in the
# session. The autouse fixture below resets overrides after every test
# so each test starts from a clean app state.
# ============================================================

import pytest
from main import app


@pytest.fixture(autouse=True)
def _reset_dependency_overrides():
    """Clear leftover FastAPI dependency overrides after every test.

    Tests that legitimately override dependencies set them again inside
    their own body, so clearing at teardown cannot break them.
    """
    yield
    app.dependency_overrides.clear()
