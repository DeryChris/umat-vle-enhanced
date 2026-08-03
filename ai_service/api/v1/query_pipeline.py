# ============================================================
# Shared query preparation — used by sync and streaming endpoints
# ============================================================

import json
import random
import re
import logging
from dataclasses import dataclass
from typing import List, Optional

from sqlalchemy.orm import Session

from models.schemas import QueryRequest, QuizData
from models.database import ChatLog
from core.llm_processor import TASK_GUIDANCE
from core.content_classifier import is_sensitive_query, get_sensitive_refusal
from rag.hybrid_retriever import get_hybrid_retriever
from analytics.student_profile import get_student_profile
from prompts.system_tutor import build_tutor_system_prompt, build_lecturer_system_prompt
from care.classifier import CAREAClassifier, CareResult
from care.course_profile import CourseProfileBuilder

logger = logging.getLogger(__name__)
hybrid = get_hybrid_retriever()
_care = CAREAClassifier()
_profile_builder = CourseProfileBuilder()

_OUTSIDE_SCOPE_TEMPLATE = (
    "This AI assistant is designed primarily for curriculum-aligned academic support. "
    "Responses to topics outside the current course are intentionally brief "
    "to help maintain academic focus."
)

_GENERAL_ACADEMIC_PREFIX = (
    "This topic is academically relevant to the course, but it was not found in the "
    "uploaded materials. The following explanation is based on general academic knowledge."
)

_GREETING_RESPONSES = [
    "Hello! I'm your UMaT AI Tutor. I can help you understand your course materials, prepare for exams, or answer questions about your lectures. What would you like to learn about today?",
    "Hi there! Ready to dive into your studies? I can explain concepts, create practice quizzes, help you prepare for exams, or answer questions based on your course materials. What do you need help with?",
    "Hey! I'm here to help you learn. Whether you need an explanation, practice questions, or exam prep — just let me know what topic you're studying and I'll assist based on your course materials.",
    "Welcome back! I've got your course materials loaded and ready. Ask me anything about your lectures, readings, or assignments and I'll help you understand them better.",
]

# Relaxed patterns — match greeting at START of message, not the whole message.
_GREETING_PATTERNS = [
    r"^(hi|hey|hello|yo|sup|howdy|good\s*(morning|afternoon|evening))(!|,|\s|$)",
    r"^(what'?s up|how'?s it going|nice to meet you)",
    r"^(hey|hi|hello)\s+(there|everyone|guys?)",
    r"^good\s*(morning|afternoon|evening)",
    r"^are\s+you\s+(there|real|a\s*(real\s*)?(ai|bot|tutor))",
    r"^who\s+are\s+you",
    r"^what\s+can\s+you\s+do",
    r"^thanks?( you)?",
    r"^ok(ay)?",
    r"^(i\s+)?(don'?t\s+)?(have\s+)?(a\s+)?question\s*(right\s*now|yet)?",
    r"^bye|goodbye|see\s+(ya|you|you\s+later)",
]

_CHITCHAT_PATTERNS = [
    r"how\s+are\s+you",
    r"what'?s\s+up",
    r"(i'?m|i\s+am)\s+(fine|good|great|ok|okay|doing|learning|studying)",
    r"that'?s\s+(helpful|great|good|awesome|amazing|cool)",
    r"i\s+(understand|see|get\s+it)",
    r"(makes\s+sense|got\s+it|understood)",
]

# Non-course content keywords — used to detect off-topic questions that
# should not trigger RAG retrieval.
_OFF_TOPIC_KEYWORDS = [
    r"\b(love|relationship|dating|boyfriend|girlfriend|partner|crush)\b",
    r"\b(movie|music|song|album|concert|celebrity|actor|actress)\b",
    r"\b(sports?|football|basketball|soccer|tennis|fifa|nba)\b",
    r"\b(recipe|cook|food|restaurant|meal|dinner|lunch)\b",
    r"\b(weather|forecast|temperature|rain|sunny)\b",
    r"\b(joke|riddle|funny|humor|laugh)\b",
    r"\b(astrology|horoscope|zodiac|sign)\b",
    r"\b(gossip|drama|tea|shade)\b",
]

# Course-related verbs/patterns — if a message contains these, it is treated
# as a course question even if it also contains off-topic keywords.
_COURSE_QUESTION_SIGNALS = [
    r"\b(explain|define|describe|compare|contrast|what\s+(is|are|do|does)|"
    r"how\s+(does|do|is|are|can|could)|why\s+(is|are|do|does|can|could)|"
    r"tell\s+me\s+about|give\s+me|list|name|summarize|summarise|"
    r"describe|elaborate|teach|show\s+me|walk\s+me|break\s+down|"
    r"difference\s+between|similarities|advantage|disadvantage|"
    r"pros?\s+and\s+cons|example|illustrate|clarify)\b",
]

_CHITCHAT_RESPONSES = {
    "how_are_you": "I'm doing great, thanks for asking! Ready to help you learn. What topic are we tackling today?",
    "thats_helpful": "Glad that was helpful! Feel free to ask follow-up questions or explore another topic. I'm here whenever you need me.",
    "i_understand": "Perfect! Understanding builds step by step. If you want to go deeper into any aspect, just ask — or we can move on to a new topic.",
    "default": "Got it! Whenever you're ready with a question about your course materials, I'll be here to help.",
}

_OFF_TOPIC_RESPONSES = [
    "I'm your AI tutor for this course, designed to help you with your lecture materials, assignments, and exam preparation. I'd love to help you with a course-related question!",
    "That's an interesting topic, but I'm specifically built to assist you with your course materials here at UMaT. Feel free to ask me anything about your lectures, readings, or assignments!",
    "I appreciate the question! My role is to help you succeed in this course. Want to try asking about something from your lectures or course materials instead?",
]

# Minimum RRF score threshold — chunks below this are discarded as
# irrelevant.  RRF scores range from ~0.016 (rank 59) to ~0.067 (rank 0)
# for a single list; with two lists the max is ~0.083.  A threshold of
# 0.025 roughly corresponds to appearing in the top 40 of at least one
# retrieval method.
_MIN_RRF_THRESHOLD = 0.025


def detect_task(question: str) -> str:
    q = question.lower().strip()

    is_greeting = any(re.match(pat, q) for pat in _GREETING_PATTERNS)
    is_chitchat = any(re.search(pat, q) for pat in _CHITCHAT_PATTERNS)
    has_course_signal = any(re.search(pat, q) for pat in _COURSE_QUESTION_SIGNALS)
    is_off_topic = any(re.search(pat, q) for pat in _OFF_TOPIC_KEYWORDS)

    # Chitchat has priority — these are specific conversational phrases
    if is_chitchat:
        return "chitchat"

    # Greeting without course-related follow-up — no RAG needed
    if is_greeting and not has_course_signal:
        return "off_topic" if is_off_topic else "greeting"

    # Off-topic question (with or without greeting) that has no course signals
    if is_off_topic and not has_course_signal:
        return "off_topic"

    quiz_patterns = [
        "quiz", "test me", "test my", "test myself", "test my knowledge",
        "practice question", "practice test", "practice problems", "practice",
        "mcq", "multiple choice", "true or false", "fill in the blank",
        "make me a quiz", "create a quiz", "generate questions", "test questions",
        "practice with questions", "question bank", "sample questions",
        "exam questions", "past papers", "past questions", "revision questions",
        "ask me questions", "give me questions", "prepare some questions",
        "question me", "drill me", "challenge me", "test my understanding",
        "check my understanding", "assess my knowledge", "evaluate me",
        "give me practice", "question session", "quick test",
    ]
    exam_patterns = [
        "exam", "midterm", "final", "prepare for", "get ready for",
        "revision", "revise", "study guide", "study plan",
        "exam preparation", "exam prep", "how to study", "study tips",
        "what to study", "important topics", "key concepts", "focus areas",
        "exam tips", "pass the exam", "exam strategy", "exam techniques",
        "review session", "cram", "last minute", "test preparation",
        "study material", "what to focus on",
    ]
    explain_patterns = [
        "explain", "explain like i'm 5", "eli5", "break down", "walk me through",
        "step by step", "how does", "how do", "how is", "how are", "what is", "what are", "define",
        "definition of", "meaning of", "difference between", "compare", "contrast",
        "simpler", "simple terms", "in plain english", "dont understand",
        "don't understand", "confused", "unclear", "unclear on", "help me understand",
        "i don't get", "i dont get", "can you explain", "could you explain",
        "teach me", "show me how", "how to solve", "worked example",
        "elaborate", "tell me about", "tell me more", "go deeper", "in detail",
    ]
    lookup_patterns = [
        "summarize", "summary of", "overview of", "what does it say",
        "what is covered", "what did we cover", "what was taught",
        "key points", "main points", "main ideas", "takeaways",
        "recap", "give me the highlights", "what's in", "contents of",
        "what do i need to know", "what should i know",
    ]

    if any(p in q for p in quiz_patterns):
        return "quiz"
    if any(p in q for p in exam_patterns):
        return "exam_prep"
    if any(p in q for p in lookup_patterns):
        return "summary"
    if any(p in q for p in explain_patterns):
        return "explain"
    return "qa"


_QUIZ_JSON_PATTERN = re.compile(r"```(?:json)?\s*(\{.*?\"quiz\"\s*:.*?\})\s*```", re.DOTALL)
_QUIZ_BARE_PATTERN = re.compile(r'(\{[\s\S]*?"quiz"\s*:[\s\S]*?"questions"\s*:\s*\[[\s\S]*?\]\s*\})', re.DOTALL)


def extract_quiz_json(text: str) -> Optional[dict]:
    """Scan the LLM response text for a JSON quiz code block and parse it.
    Returns the parsed quiz dict (i.e. the value of the 'quiz' key), or None."""
    patterns = [_QUIZ_JSON_PATTERN, _QUIZ_BARE_PATTERN]
    for pat in patterns:
        m = pat.search(text)
        if not m:
            continue
        try:
            data = json.loads(m.group(1))
            quiz = data.get("quiz") if isinstance(data, dict) else None
            if quiz and isinstance(quiz.get("questions"), list) and len(quiz["questions"]) > 0:
                logger.info("Quiz JSON extracted successfully (%d questions)", len(quiz["questions"]))
                return quiz
        except (json.JSONDecodeError, KeyError, TypeError) as e:
            logger.debug("Quiz JSON parse failed with pattern %s: %s", pat.pattern[:40], e)
            continue
    if '"quiz"' in text and '"questions"' in text:
        logger.warning("Quiz keywords found but no valid JSON extracted. Text snippet: %s", text[:300])
    return None


def _get_chitchat_response(question: str) -> str:
    q = question.lower()
    if any(w in q for w in ["how are you", "how're you", "how doin", "how you doing"]):
        return _CHITCHAT_RESPONSES["how_are_you"]
    if any(w in q for w in ["helpful", "great", "awesome", "amazing", "cool", "thanks", "thank you"]):
        return _CHITCHAT_RESPONSES["thats_helpful"]
    if any(w in q for w in ["understand", "see", "get it", "makes sense", "got it"]):
        return _CHITCHAT_RESPONSES["i_understand"]
    return _CHITCHAT_RESPONSES["default"]


def _build_conversation_context(
    db: Session, user_id: int, course_id: int, session_key: str, max_messages: int = 20,
) -> str:
    if not session_key:
        return ""

    logs = (
        db.query(ChatLog)
        .filter(
            ChatLog.user_id == user_id,
            ChatLog.course_id == course_id,
            ChatLog.session_key == session_key,
        )
        .order_by(ChatLog.created_at.asc())
        .limit(max_messages)
        .all()
    )
    if not logs:
        return ""

    lines = []
    for log in logs:
        lines.append(f"Student: {log.question}")
        lines.append(f"Tutor: {log.answer}")
    return "\n".join(lines)


@dataclass
class PreparedQuery:
    task: str
    sources: List[str]
    prompt: Optional[str] = None
    instant_answer: Optional[str] = None
    confidence: float = 0.85


def prepare_query(request: QueryRequest, db: Session) -> PreparedQuery:
    task = detect_task(request.question)

    # ── Early exits for non-RAG task types ──
    if task == "greeting":
        return PreparedQuery(
            task=task, sources=[],
            instant_answer=random.choice(_GREETING_RESPONSES), confidence=1.0,
        )

    if task == "chitchat":
        return PreparedQuery(
            task=task, sources=[],
            instant_answer=_get_chitchat_response(request.question), confidence=1.0,
        )

    if task == "off_topic":
        return PreparedQuery(
            task=task, sources=[],
            instant_answer=random.choice(_OFF_TOPIC_RESPONSES), confidence=1.0,
        )

    # --- Privacy Layer 4: block sensitive queries from students ------------
    if request.role != "lecturer" and is_sensitive_query(request.question):
        logger.info(
            "Sensitive query blocked for user %s in course %s: '%s'",
            request.user_id, request.course_id, request.question[:80],
        )
        return PreparedQuery(
            task=task, sources=[],
            instant_answer=get_sensitive_refusal(),
            confidence=1.0,
        )

    conversation_history = _build_conversation_context(
        db, request.user_id, request.course_id, request.session_key or "",
    )

    material_ids = request.material_ids or []
    try:
        results = hybrid.search(
            course_id=request.course_id,
            query=request.question,
            n_results=10 if task in ("quiz", "exam_prep") else 5,
            material_ids=material_ids if material_ids else None,
            role=request.role,
        )
    except Exception as e:
        logger.error(f"Hybrid retrieval error: {e}")
        return PreparedQuery(
            task=task, sources=[],
            instant_answer="AI service temporarily unavailable. Please try again later.",
            confidence=0.0,
        )

    # --- CARE classification: determine response mode -----------------------
    profile = _profile_builder.build(request.course_id)
    care_result = _care.classify(task, request.question, profile, results)

    logger.info(
        "CARE[course=%s]: mode=%s reason=%s rag_conf=%.2f acad_rel=%.2f",
        request.course_id, care_result.mode, care_result.reason,
        care_result.retrieval_confidence, care_result.academic_relevance_score,
    )

    # ── Mode 3: outside_scope → brief redirect, no RAG, no sources ──
    if care_result.mode == "outside_scope":
        brief_answer = _get_brief_outside_scope_answer(request.question)
        return PreparedQuery(
            task=task, sources=[],
            instant_answer=brief_answer,
            confidence=max(0.3, care_result.academic_relevance_score),
        )

    # --- Relevance threshold: discard weak retrieval results ---------------
    filtered = []
    for doc, meta in results:
        score = meta.get("rrf_score", 0)
        if score >= _MIN_RRF_THRESHOLD:
            filtered.append((doc, meta))
        else:
            logger.debug(
                "Discarded weak chunk (rrf=%.4f, source=%s)",
                score, meta.get("source", "?"),
            )

    context_texts = [doc for doc, _ in filtered]

    # ── Mode 2: general_academic → answer from general knowledge, no sources ──
    if care_result.mode == "general_academic":
        sources = []
        context_block = _GENERAL_ACADEMIC_PREFIX

        student_profile = _get_student_profile_if_needed(db, request)
        guidance = TASK_GUIDANCE.get(task, "")

        if request.role == "lecturer":
            prompt = build_lecturer_system_prompt(
                rag_context=context_block,
                user_question=request.question,
                conversation_history=conversation_history,
            )
        else:
            prompt = build_tutor_system_prompt(
                profile=student_profile,
                rag_context=context_block,
                user_question=request.question,
                task_guidance=guidance,
                conversation_history=conversation_history,
            )
        return PreparedQuery(task=task, sources=sources, prompt=prompt,
                             confidence=care_result.academic_relevance_score)

    # ── Mode 1: curriculum_grounded → full RAG answer with sources ──
    sources = list({meta.get("source", "Unknown source") for _, meta in filtered})
    context_block = (
        "\n\n---\n\n".join(context_texts)
        if context_texts
        else "(no indexed material matched this request)"
    )

    student_profile = _get_student_profile_if_needed(db, request)
    guidance = TASK_GUIDANCE.get(task, "")

    if request.role == "lecturer":
        prompt = build_lecturer_system_prompt(
            rag_context=context_block,
            user_question=request.question,
            conversation_history=conversation_history,
        )
    else:
        prompt = build_tutor_system_prompt(
            profile=student_profile,
            rag_context=context_block,
            user_question=request.question,
            task_guidance=guidance,
            conversation_history=conversation_history,
        )

    return PreparedQuery(task=task, sources=sources, prompt=prompt,
                         confidence=care_result.retrieval_confidence)


def _get_brief_outside_scope_answer(question: str) -> str:
    """Generate a brief answer for outside-scope questions with academic redirect."""
    q = question.lower().strip().rstrip(".!?")
    brief = _get_brief_factual_answer(q)
    return f"{brief}\n\n{_OUTSIDE_SCOPE_TEMPLATE}"


def _get_brief_factual_answer(question: str) -> str:
    """Return a one-sentence factual answer for common off-topic question types."""
    love_pats = [
        r"what\s+is\s+love", r"define\s+love", r"meaning\s+of\s+love",
        r"how\s+(do|can)\s+i\s+fall\s+in\s+love", r"tell\s+me\s+about\s+love",
    ]
    for pat in love_pats:
        if re.search(pat, question):
            return (
                "Love is a strong emotional bond involving care, "
                "affection, trust, and commitment."
            )
    joke_pats = [r"(tell|say|know|hear)\s+(me\s+)?(a\s+)?joke", r"make\s+me\s+laugh"]
    for pat in joke_pats:
        if re.search(pat, question):
            return (
                "Humor is subjective, but here is a light one: "
                "Why do programmers prefer dark mode? Because light attracts bugs."
            )
    weather_pats = [r"weather", r"temperature", r"forecast", r"rain", r"sunny"]
    if any(w in question for w in weather_pats):
        return (
            "I do not have access to real-time weather data. "
            "Please check a weather service for current conditions."
        )
    greeting_pats = [
        r"how\s+are\s+you", r"how'?re?\s+you", r"how\s+doin",
        r"what'?s\s+up", r"how'?s\s+it\s+going",
    ]
    for pat in greeting_pats:
        if re.search(pat, question):
            return "I am functioning well and ready to help you learn."
    thanks_pats = [r"^thanks?", r"thank\s+you", r"appreciate"]
    if any(re.search(p, question) for p in thanks_pats):
        return "You are welcome."
    farewell_pats = [r"^bye", r"goodbye", r"see\s+(ya|you)"]
    for pat in farewell_pats:
        if re.search(pat, question):
            return "Goodbye. Feel free to return when you have course-related questions."
    return (
        f'Regarding "{question}", I do not have information '
        "in the current course materials to provide a detailed answer."
    )


def _get_student_profile_if_needed(db: Session, request: QueryRequest):
    if request.role == "student":
        return get_student_profile(db, request.user_id, request.course_id)
    return None


def log_chat(
    db: Session,
    request: QueryRequest,
    answer: str,
    sources: List[str],
    elapsed_ms: float,
) -> None:
    try:
        db.add(ChatLog(
            user_id=request.user_id,
            course_id=request.course_id,
            session_key=request.session_key or None,
            question=request.question,
            answer=answer,
            sources=", ".join(sources) if sources else None,
            response_time_ms=elapsed_ms,
        ))
        db.commit()
    except Exception as e:
        logger.warning(f"Failed to log chat: {e}")
