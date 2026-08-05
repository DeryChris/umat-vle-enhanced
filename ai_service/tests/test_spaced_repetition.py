# ============================================================
# Unit tests for the SM-2 spaced-repetition algorithm (F3)
# Run with: pytest tests/test_spaced_repetition.py -v
# NOTE: mirrors local_umat_ai SM-2 expectations in Moodle lib.php —
# keep in sync with moodle/public/local/umat_ai/lib.php.
# ============================================================

import sys
import os
import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from core.spaced_repetition import (
    sm2_update,
    next_due_at,
    button_quality,
    QUALITY_BUTTONS,
    DEFAULT_EASE,
    MIN_EASE,
)


class TestSm2Update:
    """Verify the canonical SM-2 state transitions."""

    def test_new_card_good_quality_interval_one(self):
        state = sm2_update(4)
        assert state["interval"] == 1
        assert state["repetitions"] == 1
        assert state["ease"] == pytest.approx(2.5)  # q=4 → no ease change

    def test_new_card_easy_quality_increases_ease(self):
        state = sm2_update(5)
        assert state["ease"] == pytest.approx(2.6)
        assert state["interval"] == 1
        assert state["repetitions"] == 1

    def test_second_review_six_day_interval(self):
        s1 = sm2_update(4)
        s2 = sm2_update(4, ease=s1["ease"], interval=int(s1["interval"]), repetitions=int(s1["repetitions"]))
        assert s2["interval"] == 6
        assert s2["repetitions"] == 2

    def test_third_review_multiplies_by_ease(self):
        s1 = sm2_update(5)
        s2 = sm2_update(5, ease=s1["ease"], interval=int(s1["interval"]), repetitions=int(s1["repetitions"]))
        s3 = sm2_update(5, ease=s2["ease"], interval=int(s2["interval"]), repetitions=int(s2["repetitions"]))
        # Interval multiplies by the NEW ease factor: 6 * 2.8 = 16.8 → 17.
        assert s3["ease"] == pytest.approx(2.8)
        assert s3["interval"] == 17
        assert s3["repetitions"] == 3

    def test_failed_review_resets_repetitions_and_interval(self):
        s1 = sm2_update(5)
        s2 = sm2_update(4, ease=s1["ease"], interval=int(s1["interval"]), repetitions=int(s1["repetitions"]))
        s3 = sm2_update(4, ease=s2["ease"], interval=int(s2["interval"]), repetitions=int(s2["repetitions"]))
        failed = sm2_update(1, ease=s3["ease"], interval=int(s3["interval"]), repetitions=int(s3["repetitions"]))
        assert failed["repetitions"] == 0
        assert failed["interval"] == 1
        # Ease still decreases but is never below the floor.
        assert failed["ease"] >= MIN_EASE

    def test_ease_never_below_floor(self):
        state = sm2_update(0, ease=1.4)
        assert state["ease"] >= MIN_EASE

    def test_ease_formula_quality_three(self):
        # 2.5 + 0.1 - 2*(0.08 + 0.04) = 2.36
        assert sm2_update(3)["ease"] == pytest.approx(2.36)

    def test_quality_clamped_to_0_5(self):
        assert sm2_update(-3)["ease"] == sm2_update(0)["ease"]
        assert sm2_update(99)["ease"] == sm2_update(5)["ease"]


class TestNextDueAt:
    """Verify due timestamp calculation."""

    def test_due_at_is_now_plus_interval_days(self):
        state = next_due_at(4, now=1_000_000)
        assert state["due_at"] == 1_000_000 + 86400
        assert state["quality"] == 4

    def test_due_at_after_lapse(self):
        s1 = next_due_at(5, now=1_000_000)
        s2 = next_due_at(4, ease=s1["ease"], interval=int(s1["interval"]),
                         repetitions=int(s1["repetitions"]), now=2_000_000)
        s3 = next_due_at(1, ease=s2["ease"], interval=int(s2["interval"]),
                         repetitions=int(s2["repetitions"]), now=3_000_000)
        assert s3["due_at"] == 3_000_000 + 86400  # lapse → tomorrow
        assert s3["repetitions"] == 0


class TestButtonQuality:
    """UI button → SM-2 quality mapping (4-button grading)."""

    def test_mapping(self):
        assert QUALITY_BUTTONS == {"again": 1, "hard": 3, "good": 4, "easy": 5}
        assert button_quality("again") == 1
        assert button_quality("hard") == 3
        assert button_quality("good") == 4
        assert button_quality("easy") == 5

    def test_unknown_button_returns_none(self):
        assert button_quality("perfect") is None
        assert button_quality("") is None
        assert button_quality(None) is None
