# ============================================================
# Student profile — struggle detection context for adaptive tutoring
# ============================================================

import json
import logging
from dataclasses import dataclass, field
from typing import List, Optional

from sqlalchemy.orm import Session

from models.database import StudentContext

logger = logging.getLogger(__name__)


@dataclass
class StruggleTopic:
    topic: str
    score: float = 0.0
    reason: str = ""


@dataclass
class StudentProfile:
    user_id: int
    course_id: int
    current_grade: Optional[float] = None
    struggle_topics: List[StruggleTopic] = field(default_factory=list)
    recent_events: List[dict] = field(default_factory=list)
    learning_style: str = "standard"

    @property
    def is_struggling(self) -> bool:
        return len(self.struggle_topics) > 0

    @property
    def is_excelling(self) -> bool:
        return (
            self.current_grade is not None
            and self.current_grade >= 80
            and len(self.struggle_topics) == 0
        )

    def struggle_topic_names(self) -> List[str]:
        return [t.topic for t in self.struggle_topics]


def get_student_profile(db: Session, user_id: int, course_id: int) -> StudentProfile:
    """Fetch the latest student analytics profile from the AI service database."""
    record = (
        db.query(StudentContext)
        .filter(
            StudentContext.user_id == user_id,
            StudentContext.course_id == course_id,
        )
        .order_by(StudentContext.updated_at.desc())
        .first()
    )

    if not record:
        return StudentProfile(user_id=user_id, course_id=course_id)

    struggle_topics = []
    try:
        raw = json.loads(record.struggle_topics or "[]")
        for item in raw:
            struggle_topics.append(StruggleTopic(
                topic=item.get("topic", "Unknown"),
                score=float(item.get("score", 0)),
                reason=item.get("reason", ""),
            ))
    except (json.JSONDecodeError, TypeError):
        logger.warning(f"Invalid struggle_topics JSON for user {user_id}")

    recent_events = []
    try:
        recent_events = json.loads(record.recent_events or "[]")
    except (json.JSONDecodeError, TypeError):
        pass

    return StudentProfile(
        user_id=user_id,
        course_id=course_id,
        current_grade=record.current_grade,
        struggle_topics=struggle_topics,
        recent_events=recent_events,
        learning_style=record.learning_style or "standard",
    )


def upsert_student_context(
    db: Session,
    user_id: int,
    course_id: int,
    profile: dict,
    event_type: str = "",
) -> StudentContext:
    """Create or update a student context record from a Moodle webhook payload."""
    record = (
        db.query(StudentContext)
        .filter(
            StudentContext.user_id == user_id,
            StudentContext.course_id == course_id,
        )
        .first()
    )

    struggle_json = json.dumps(profile.get("struggle_topics", []))
    events_json = json.dumps(profile.get("recent_events", []))

    if record:
        record.current_grade = profile.get("current_grade")
        record.struggle_topics = struggle_json
        record.recent_events = events_json
        record.learning_style = profile.get("learning_style", "standard")
        record.last_event_type = event_type
    else:
        record = StudentContext(
            user_id=user_id,
            course_id=course_id,
            current_grade=profile.get("current_grade"),
            struggle_topics=struggle_json,
            recent_events=events_json,
            learning_style=profile.get("learning_style", "standard"),
            last_event_type=event_type,
        )
        db.add(record)

    db.commit()
    db.refresh(record)
    return record
