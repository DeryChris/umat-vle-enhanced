import logging
import re
from dataclasses import dataclass, field
from typing import List, Optional, Tuple

from care.course_profile import CourseProfile

logger = logging.getLogger(__name__)


@dataclass
class CareResult:
    mode: str = "general_academic"
    academic_relevance_score: float = 0.0
    retrieval_confidence: float = 0.0
    reason: str = ""
    use_rag: bool = False
    response_depth: str = "moderate"
    show_sources: bool = False
    source_mode: str = "none"


_OFF_TOPIC_LEARNED_PATTERNS = [
    r"\b(love|relationship|dating|boyfriend|girlfriend|crush)\b",
    r"\b(movie|music|song|album|concert|celebrity|actor|actress)\b",
    r"\b(sport|football|basketball|soccer|tennis|fifa)\b",
    r"\b(recipe|cook|food|restaurant|meal|dinner|lunch)\b",
    r"\b(weather|forecast|temperature|rain|sunny)\b",
    r"\b(joke|riddle|funny|humor|laugh)\b",
    r"\b(astrology|horoscope|zodiac)\b",
    r"\b(gossip|drama)\b",
    r"\b(world\s*cup|president|election|politic)\b",
    r"\b(game|gaming|video\s*game|playstation|xbox|nintendo)\b",
    r"\b(pet|dog|cat|animal)\b",
    r"\b(travel|vacation|holiday|beach|hotel)\b",
    r"\b(fashion|clothes|shoes|outfit)\b",
    r"\b(health|fitness|workout|diet|exercise)\b",
]

_STRONG_ACADEMIC_SIGNALS = [
    r"\b(explain|define|describe|compare|contrast|what\s+(is|are|do|does)|"
    r"how\s+(does|do|is|are|can|could)|why\s+(is|are|do|does|can|could)|"
    r"tell\s+me\s+about|give\s+me|list|name|summarize|summarise|"
    r"describe|elaborate|teach|show\s+me|walk\s+me|break\s+down|"
    r"difference\s+between|similarities|advantage|disadvantage|"
    r"pros?\s+and\s+cons|example|illustrate|clarify|"
    r"lecture|tutorial|course|module|chapter|lesson|"
    r"assignment|homework|project|practical|"
    r"formula|equation|theory|principle|concept"
    r")\b",
]


def _has_off_topic_keywords(text: str) -> bool:
    t = text.lower()
    for pat in _OFF_TOPIC_LEARNED_PATTERNS:
        if re.search(pat, t):
            return True
    return False


def _has_academic_signal(text: str) -> bool:
    t = text.lower()
    for pat in _STRONG_ACADEMIC_SIGNALS:
        if re.search(pat, t):
            return True
    return False


def _rag_contains_question_terms(question: str, results: List[Tuple[str, dict]], min_ratio: float = 0.1) -> bool:
    q_words = set(w.lower().strip(".,;:!?()[]{}'\"") for w in question.split() if len(w) > 2)
    if not q_words:
        return False
    combined_text = " ".join(doc.lower() for doc, _ in results)
    matches = sum(1 for w in q_words if w in combined_text)
    return (matches / len(q_words)) >= min_ratio


def _keyword_overlap(question: str, profile: CourseProfile) -> float:
    if not profile.keywords:
        return 0.0
    q_words = set(w.lower().strip(".,;:!?()[]{}") for w in question.split() if len(w) > 2)
    if not q_words:
        return 0.0
    profile_words = set(w.lower() for w in profile.keywords)
    matches = q_words & profile_words
    return len(matches) / len(q_words)


def _rag_contains_off_topic_keywords(question: str, results: List[Tuple[str, dict]]) -> bool:
    q_lower = question.lower()
    matched_patterns = set()
    for pat in _OFF_TOPIC_LEARNED_PATTERNS:
        m = re.search(pat, q_lower)
        if m:
            matched_patterns.add(m.group(0).strip().lower())
    if not matched_patterns:
        return False
    for chunk_text, _ in results:
        chunk_lower = chunk_text.lower()
        for kw in matched_patterns:
            if kw in chunk_lower:
                return True
    return False


_MAX_RRF_SCORE = 0.083


def _retrieval_confidence(results: List[Tuple[str, dict]]) -> float:
    if not results:
        return 0.0
    max_score = max(meta.get("rrf_score", 0) for _, meta in results)
    return min(max_score / _MAX_RRF_SCORE, 1.0)


def _has_strong_retrieval(results: List[Tuple[str, dict]], threshold: float = 0.025) -> bool:
    return any(meta.get("rrf_score", 0) >= threshold for _, meta in results)



class CAREAClassifier:
    def __init__(self):
        self._off_topic_words = set()

    def classify(
        self,
        task: str,
        question: str,
        profile: CourseProfile,
        retrieval_results: List[Tuple[str, dict]],
    ) -> CareResult:
        if task in ("greeting", "chitchat"):
            return CareResult(
                mode="outside_scope",
                academic_relevance_score=0.0,
                retrieval_confidence=0.0,
                reason=f"task={task}",
                use_rag=False,
                response_depth="brief",
                show_sources=False,
                source_mode="none",
            )

        has_strong_rag = _has_strong_retrieval(retrieval_results)
        rag_conf = _retrieval_confidence(retrieval_results)
        has_academic = _has_academic_signal(question)
        is_off_topic = _has_off_topic_keywords(question)
        keyword_overlap = _keyword_overlap(question, profile)
        has_materials = profile.has_materials
        rag_confirms_off_topic = _rag_contains_off_topic_keywords(question, retrieval_results) if is_off_topic else False

        rag_overlap = _rag_contains_question_terms(question, retrieval_results) if has_strong_rag else False

        if is_off_topic and not rag_confirms_off_topic and rag_conf < 0.5:
            return CareResult(
                mode="outside_scope",
                academic_relevance_score=max(0.0, 0.3 - rag_conf),
                retrieval_confidence=rag_conf,
                reason="off_topic_keywords+rag_does_not_confirm",
                use_rag=False,
                response_depth="brief",
                show_sources=False,
                source_mode="none",
            )

        if task in ("off_topic",):
            return CareResult(
                mode="outside_scope",
                academic_relevance_score=0.1,
                retrieval_confidence=rag_conf,
                reason="task=off_topic",
                use_rag=False,
                response_depth="brief",
                show_sources=False,
                source_mode="none",
            )

        if has_strong_rag and (has_academic or keyword_overlap > 0.05) and rag_overlap:
            return CareResult(
                mode="curriculum_grounded",
                academic_relevance_score=max(0.6, keyword_overlap),
                retrieval_confidence=rag_conf,
                reason=f"strong_rag+academic_signal(n={len(retrieval_results)})",
                use_rag=True,
                response_depth="full",
                show_sources=True,
                source_mode="course",
            )

        if has_strong_rag and not is_off_topic:
            return CareResult(
                mode="curriculum_grounded",
                academic_relevance_score=0.5,
                retrieval_confidence=rag_conf,
                reason=f"strong_rag+no_off_topic(n={len(retrieval_results)})",
                use_rag=True,
                response_depth="full",
                show_sources=True,
                source_mode="course",
            )

        if has_academic or keyword_overlap > 0.05:
            return CareResult(
                mode="general_academic",
                academic_relevance_score=max(0.3, keyword_overlap),
                retrieval_confidence=rag_conf,
                reason="academic_signal+weak_rag" if not has_strong_rag else "academic_signal+rag_below_threshold",
                use_rag=True,
                response_depth="moderate",
                show_sources=False,
                source_mode="general",
            )

        if is_off_topic:
            return CareResult(
                mode="outside_scope",
                academic_relevance_score=0.1,
                retrieval_confidence=rag_conf,
                reason="off_topic_keywords",
                use_rag=False,
                response_depth="brief",
                show_sources=False,
                source_mode="none",
            )

        if has_materials and rag_conf > 0.05:
            return CareResult(
                mode="curriculum_grounded",
                academic_relevance_score=0.4,
                retrieval_confidence=rag_conf,
                reason="materials_exist+marginal_rag",
                use_rag=True,
                response_depth="moderate",
                show_sources=False,
                source_mode="course",
            )

        return CareResult(
            mode="general_academic",
            academic_relevance_score=0.2,
            retrieval_confidence=rag_conf,
            reason="fallback_no_strong_signal",
            use_rag=True,
            response_depth="moderate",
            show_sources=False,
            source_mode="general",
        )
