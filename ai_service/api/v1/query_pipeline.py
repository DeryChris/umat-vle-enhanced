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

logger = logging.getLogger(__name__)
hybrid = get_hybrid_retriever()

_GREETING_RESPONSES = [
    "Hello! I'm your UMaT AI Tutor. I can help you understand your course materials, prepare for exams, or answer questions about your lectures. What would you like to learn about today?",
    "Hi there! Ready to dive into your studies? I can explain concepts, create practice quizzes, help you prepare for exams, or answer questions based on your course materials. What do you need help with?",
    "Hey! I'm here to help you learn. Whether you need an explanation, practice questions, or exam prep — just let me know what topic you're studying and I'll assist based on your course materials.",
    "Welcome back! I've got your course materials loaded and ready. Ask me anything about your lectures, readings, or assignments and I'll help you understand them better.",
]

_GREETING_PATTERNS = [
    r"^(hi|hey|hello|yo|sup|howdy|good\s*(morning|afternoon|evening))(!)?\s*$",
    r"^(what'?s up|how'?s it going|nice to meet you)\s*$",
    r"^(hey|hi|hello)\s+(there|everyone|guys?)\s*$",
    r"^good\s*(morning|afternoon|evening)(\s*!)?\s*$",
    r"^are\s+you\s+(there|real|a\s*(real\s*)?(ai|bot|tutor))\s*\??\s*$",
    r"^who\s+are\s+you\s*\??\s*$",
    r"^what\s+can\s+you\s+do\s*\??\s*$",
    r"^thanks?( you)?(\s*!)?\s*$",
    r"^ok(ay)?(\s*!)?\s*$",
    r"^(i\s+)?(don'?t\s+)?(have\s+)?(a\s+)?question\s*(right\s*now|yet)?\s*$",
    r"^bye|goodbye|see\s+(ya|you|you\s+later)\s*$",
]

_CHITCHAT_PATTERNS = [
    r"how\s+are\s+you",
    r"what'?s\s+up",
    r"(i'?m|i\s+am)\s*(fine|good|great|ok|okay|learning|studying)",
    r"that'?s\s+(helpful|great|good|awesome|amazing|cool)",
    r"i\s+(understand|see|get\s+it)",
    r"(makes\s+sense|got\s+it|understood)",
]

_CHITCHAT_RESPONSES = {
    "how_are_you": "I'm doing great, thanks for asking! Ready to help you learn. What topic are we tackling today?",
    "thats_helpful": "Glad that was helpful! Feel free to ask follow-up questions or explore another topic. I'm here whenever you need me.",
    "i_understand": "Perfect! Understanding builds step by step. If you want to go deeper into any aspect, just ask — or we can move on to a new topic.",
    "default": "Got it! Whenever you're ready with a question about your course materials, I'll be here to help.",
}


def detect_task(question: str) -> str:
    q = question.lower().strip()

    for pat in _GREETING_PATTERNS:
        if re.match(pat, q):
            return "greeting"
    for pat in _CHITCHAT_PATTERNS:
        if re.search(pat, q):
            return "chitchat"

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

    if not results and request.role != "lecturer":
        return PreparedQuery(
            task=task, sources=[],
            instant_answer=(
                "No course materials have been indexed for this course yet. "
                "Please ask your lecturer to upload course materials."
            ),
            confidence=0.0,
        )

    context_texts = [doc for doc, _ in results]
    sources = list({meta.get("source", "Unknown source") for _, meta in results})

    student_profile = None
    if request.role == "student":
        student_profile = get_student_profile(db, request.user_id, request.course_id)

    context_block = (
        "\n\n---\n\n".join(context_texts)
        if context_texts
        else "(no indexed material matched this request)"
    )
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

    return PreparedQuery(task=task, sources=sources, prompt=prompt)


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
