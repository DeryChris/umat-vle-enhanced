# ============================================================
# core/spaced_repetition.py — SM-2 scheduling algorithm (F3)
#
# Canonical SM-2 (SuperMemo-2) as pure functions so the logic can be
# unit-tested and shared. NOTE: the Moodle PHP review path mirrors this
# exactly in `local_umat_ai/lib.php` (local_umat_ai_sm2_review). Keep the
# two implementations in sync — any change here MUST be mirrored there.
# ============================================================

from __future__ import annotations

import time
from typing import Dict, Optional, Union

# UI button → SM-2 quality mapping (4-button grading).
# Again=1 (fail), Hard=3, Good=4, Easy=5.
QUALITY_BUTTONS: Dict[str, int] = {
    "again": 1,
    "hard": 3,
    "good": 4,
    "easy": 5,
}

MIN_EASE = 1.3
DEFAULT_EASE = 2.5
FIRST_INTERVAL_DAYS = 1
SECOND_INTERVAL_DAYS = 6


def clamp_ease(ease: float) -> float:
    """SM-2 ease factor is floored at 1.3 (never below)."""
    return max(MIN_EASE, ease)


def sm2_update(
    quality: int,
    ease: float = DEFAULT_EASE,
    interval: int = 0,
    repetitions: int = 0,
) -> Dict[str, float]:
    """Apply one SM-2 review.

    Args:
        quality: 0-5 self-assessment (0=complete blackout … 5=perfect recall).
        ease:    current ease factor (default 2.5).
        interval: current interval in days (0 = never reviewed).
        repetitions: current repetition count.

    Returns:
        New state: {"ease", "interval", "repetitions"}.
        - quality < 3  → repetitions reset to 0, interval back to 1 day.
        - quality >= 3 → repetitions+1, interval = 1 → 6 → ease*interval.
        - ease updated by the standard SM-2 formula, floored at 1.3.
    """
    q = int(quality)
    if q < 0:
        q = 0
    if q > 5:
        q = 5

    # Ease factor update (SM-2 formula).
    new_ease = clamp_ease(ease + (0.1 - (5 - q) * (0.08 + (5 - q) * 0.02)))

    if q < 3:
        # Failed — lapse: repeat tomorrow, repetitions reset.
        return {
            "ease": new_ease,
            "interval": FIRST_INTERVAL_DAYS,
            "repetitions": 0.0,
        }

    reps = int(repetitions) + 1
    if reps == 1:
        new_interval = FIRST_INTERVAL_DAYS
    elif reps == 2:
        new_interval = SECOND_INTERVAL_DAYS
    else:
        new_interval = round(int(interval) * new_ease)
        if new_interval < 1:
            new_interval = 1

    return {
        "ease": new_ease,
        "interval": float(new_interval),
        "repetitions": float(reps),
    }


def next_due_at(
    quality: int,
    ease: float = DEFAULT_EASE,
    interval: int = 0,
    repetitions: int = 0,
    now: Optional[Union[int, float]] = None,
) -> Dict:
    """Apply SM-2 and return the new state plus the next due timestamp.

    Args:
        quality, ease, interval, repetitions: as sm2_update().
        now: epoch seconds override (for deterministic tests); default time.time().

    Returns:
        {"ease", "interval", "repetitions", "due_at", "quality"}
        due_at is an integer epoch timestamp: now + interval days (86400 s/day).
    """
    now = int(now if now is not None else time.time())
    state = sm2_update(quality, ease, interval, repetitions)
    due_at = now + int(state["interval"]) * 86400
    state["due_at"] = due_at
    state["quality"] = int(quality)
    return state


def button_quality(button: str) -> Optional[int]:
    """Map a UI button key ('again'|'hard'|'good'|'easy') to SM-2 quality."""
    return QUALITY_BUTTONS.get((button or "").lower())
