# ============================================================
# Dynamic system prompt builder for adaptive UMAT AI tutoring
# ============================================================

from typing import List, Optional

from analytics.student_profile import StudentProfile


def _format_struggle_topics(profile: StudentProfile) -> str:
    if not profile.struggle_topics:
        return "None identified yet"
    lines = []
    for t in profile.struggle_topics[:5]:
        lines.append(f"- {t.topic} (score: {t.score:.0f}, reason: {t.reason})")
    return "\n".join(lines)


def _format_recent_events(profile: StudentProfile) -> str:
    if not profile.recent_events:
        return "No recent activity recorded"
    lines = []
    for ev in profile.recent_events[:5]:
        evtype = ev.get("type", "unknown")
        lines.append(f"- {evtype}")
    return "\n".join(lines)


def _adaptive_instructions(profile: StudentProfile) -> str:
    if profile.is_struggling:
        topics = ", ".join(profile.struggle_topic_names()[:3]) or "recent topics"
        return (
            f"The student is struggling with {topics}. "
            "Use Socratic questioning. Break concepts into small steps. "
            "Do not give the answer directly — guide them to discover it."
        )
    if profile.is_excelling:
        mastered = ", ".join(profile.struggle_topic_names()[:2]) or "core course topics"
        return (
            f"The student is performing well (grade: {profile.current_grade:.0f}%). "
            f"They have mastered {mastered}. "
            "Challenge them with advanced application questions or real-world scenarios."
        )
    return (
        "Adapt your explanation depth to the student's questions. "
        "Be encouraging and check for understanding before moving on."
    )


def build_tutor_system_prompt(
    profile: Optional[StudentProfile],
    rag_context: str,
    user_question: str,
    task_guidance: str = "",
    conversation_history: str = "",
) -> str:
    """
    Construct the full tutor prompt dynamically before every OpenAI/Gemini call.
    """
    grade = f"{profile.current_grade:.0f}%" if profile and profile.current_grade is not None else "Not available"
    struggle = _format_struggle_topics(profile) if profile else "None identified yet"
    recent = _format_recent_events(profile) if profile else "No recent activity"
    adaptive = _adaptive_instructions(profile) if profile else "Be supportive and clear."
    history = conversation_history or "(no previous conversation in this session)"
    guidance = task_guidance or "Answer the student's question clearly and accurately."

    return f"""### ROLE
You are an empathetic, expert AI Tutor for UMAT VLE (University of Mines and Technology, Ghana).

### STUDENT CONTEXT
- Current Course Grade: {grade}
- Identified Struggle Areas:
{struggle}
- Recent Activity:
{recent}
- Learning Style: {profile.learning_style if profile else "standard"}

### INSTRUCTIONS
1. If the student asks about a Struggle Area, use analogies and step-by-step scaffolding.
2. If the student is performing well, focus on critical thinking and synthesis.
3. Cite specific course modules from the Retrieved Context below when answering factual questions.
4. Tone: Encouraging, professional, human-like (avoid robotic lists).
5. GROUNDING: ONLY use the provided Course Context to answer factual course questions.
   If the answer is not in the context, state "I cannot find this in your course materials"
   and do not hallucinate.
6. ADAPTIVE GUIDANCE: {adaptive}
7. QUIZ MODE: If you detect the student wants to test their knowledge with practice questions,
   quizzes, or assessments (even implicitly), output the quiz as a structured JSON code block
   wrapped in ```json after a brief text introduction. Use the JSON schema described in the
   TASK GUIDANCE section when it is provided; otherwise, use:
   {{"quiz":{{"title":"...","questions":[{{"type":"objective|theoretical","question":"...","options":["A","B","C","D"],"correct":0,"explanation":"..."}}]}}}}

### TASK GUIDANCE
{guidance}

### CONVERSATION HISTORY
{history}

### RETRIEVED COURSE CONTEXT
{rag_context}

### STUDENT QUERY
{user_question}

RESPONSE:"""


def build_lecturer_system_prompt(
    rag_context: str,
    user_question: str,
    conversation_history: str = "",
) -> str:
    history = conversation_history or "(no previous conversation in this session)"
    return f"""You are the UMaT AI Teaching Assistant for lecturers.

COURSE CONTEXT:
{rag_context}

CONVERSATION HISTORY:
{history}

LECTURER REQUEST:
{user_question}

Provide evidence-based, actionable teaching insights. Base content answers solely on the course context.

RESPONSE:"""
