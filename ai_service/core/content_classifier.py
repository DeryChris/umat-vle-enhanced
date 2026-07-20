"""
Content classification, sensitive query detection, and output leak filtering.

Layer 1: classify_content()   — filename heuristics for visibility/content_type
Layer 4: is_sensitive_query()  — blocks explicit requests before LLM
Layer 5: check_response_leakage() — catches leaked content post-LLM
"""

import re
import logging

logger = logging.getLogger(__name__)


# ── Layer 1: Filename-based content classification ─────────────────────

# Patterns that indicate lecturer-only content.
# Each tuple: (content_type, visibility, filename_substrings)
_FILENAME_RULES = [
    ("marking_scheme",  "lecturer", ["marking", "mark scheme", "mark_scheme"]),
    ("marking_scheme",  "lecturer", ["grading rubric", "grading_rubric", "grader"]),
    ("quiz_answer_key", "lecturer", ["answer key", "answer_key", "answers"]),
    ("quiz_answer_key", "lecturer", ["model answer", "model_answer", "solutions"]),
    ("admin_document",  "admin",    ["confidential", "internal", "staff only", "staff_only"]),
]

# Default when nothing matches.
_DEFAULT_VISIBILITY  = "student"
_DEFAULT_CONTENT_TYPE = "lecture_notes"


def classify_content(filename: str) -> dict:
    """Return metadata dict with 'visibility' and 'content_type' inferred from filename.

    This is a fallback when the caller (Moodle frontend) does not provide
    explicit visibility/content_type.  The Moodle side should still send
    these fields whenever possible for maximum accuracy.
    """
    name_lower = filename.lower().strip()

    for content_type, visibility, substrings in _FILENAME_RULES:
        for sub in substrings:
            if sub in name_lower:
                logger.info(
                    "Auto-classified '%s' as %s/%s",
                    filename, visibility, content_type,
                )
                return {
                    "visibility":   visibility,
                    "content_type": content_type,
                }

    return {
        "visibility":   _DEFAULT_VISIBILITY,
        "content_type": _DEFAULT_CONTENT_TYPE,
    }


def validate_visibility(value: str) -> str:
    """Normalise and validate a caller-supplied visibility value."""
    allowed = {"student", "lecturer", "admin"}
    v = (value or "").lower().strip()
    if v not in allowed:
        logger.warning("Invalid visibility '%s', defaulting to 'student'", value)
        return _DEFAULT_VISIBILITY
    return v


# ── Layer 4: Sensitive query detection ─────────────────────────────────

# Patterns that match when a student explicitly asks for restricted content.
_SENSITIVE_QUERY_PATTERNS = [
    # Marking / grading
    r"marking\s*(scheme|rubric|guide|criteria|table)",
    r"grading\s*(rubric|criteria|scheme|guide|table|matrix)",
    r"how\s*(will|is|are)\s+(this|it|the)\s+(exam|test|quiz|assessment|assignment)\s*(be\s*)?graded",
    r"how\s+many\s+marks",
    r"what('s|\s+is)\s+the\s+(passing|pass)\s*(mark|score|grade|percentage)",
    # Answer keys
    r"answer\s*key",
    r"model\s*answer",
    r"correct\s*answers?\b",
    r"right\s*answers?\b",
    # Explicit requests
    r"show\s*me\s*(the\s*)?(answers?|solutions?|marking|grading)",
    r"give\s*me\s*(the\s*)?(answers?|solutions?|marking|grading|answer\s*key|marking\s*scheme)",
    r"tell\s*me\s*(the\s*)?(answers?|solutions?|correct)",
    r"what\s*(are|is)\s+the\s+(answers?|solutions?|correct)",
    # Assessment internals
    r"(exam|test|quiz)\s*(paper|questions?)\s*(with\s*)?(answers?|solutions?)",
    r"(answers?|solutions?)\s*(to|for)\s*(the|this|that)\s*(exam|test|quiz|assessment)",
    r"(past|previous)\s*(exam|test|quiz)\s*(papers?|questions?)\s*(with\s*)?(answers?|solutions?)",
    # Leaked internal info
    r"(internal|confidential)\s*(marking|grading|criteria)",
    r"assessment\s*blueprint",
    r"exam\s*blueprint",
]

_COMPILED_SENSITIVE = [re.compile(p, re.IGNORECASE) for p in _SENSITIVE_QUERY_PATTERNS]

_SENSITIVE_REFUSAL = (
    "I'm sorry, but I cannot provide marking schemes, answer keys, "
    "or grading criteria. These materials are for your lecturer's use only. "
    "Please ask your lecturer for feedback on your assessments."
)


def is_sensitive_query(question: str) -> bool:
    """Return True if the question matches any sensitive pattern."""
    for pattern in _COMPILED_SENSITIVE:
        if pattern.search(question):
            logger.info("Sensitive query blocked: '%s' matched '%s'", question[:80], pattern.pattern)
            return True
    return False


def get_sensitive_refusal() -> str:
    """Return the standard refusal message for sensitive queries."""
    return _SENSITIVE_REFUSAL


# ── Layer 5: Output leak detection ────────────────────────────────────

# Patterns that suggest the LLM leaked restricted content in its response.
_LEAK_PATTERNS = [
    r"(marking|grading)\s*(scheme|rubric|criteria|guide|table)",
    r"answer\s*key",
    r"model\s*answer",
    r"the\s+correct\s+answer\s+is\b",
    r"the\s+right\s+answer\s+is\b",
    r"correct\s+option\s+is\b",
    r"the\s+answer\s+should\s+be\b.*\b[A-D]\b",
    r"full\s*marks?\s*(are|is|will)\s+awarded",
    r"(out\s+of|worth)\s+\d+\s+marks?\b",
]

_COMPILED_LEAK = [re.compile(p, re.IGNORECASE) for p in _LEAK_PATTERNS]

_LEAK_REFUSAL = (
    "I'm sorry, but I cannot provide this information. "
    "Please consult your lecturer for assessment-related queries."
)


def check_response_leakage(answer: str, role: str = "student") -> str:
    """Check if an LLM response leaked sensitive content.

    If a leak is detected for a student, returns a refusal message.
    Lecturers and admins see the original response unchanged.
    """
    if role in ("lecturer", "admin"):
        return answer

    for pattern in _COMPILED_LEAK:
        if pattern.search(answer):
            logger.warning(
                "Output leak detected (pattern: %s) — replacing with refusal",
                pattern.pattern,
            )
            return _LEAK_REFUSAL

    return answer
