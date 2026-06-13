from typing import List
from config import get_settings
from langchain_google_genai import ChatGoogleGenerativeAI

settings = get_settings()


def _make_llm(temperature: float):
    """Build a chat model for the configured provider (gemini or openai)."""
    if settings.llm_provider == "openai":
        from langchain_openai import ChatOpenAI
        return ChatOpenAI(
            model=settings.openai_llm_model,
            api_key=settings.openai_api_key,
            temperature=temperature,
        )
    return ChatGoogleGenerativeAI(
        model=settings.llm_model,
        google_api_key=settings.google_api_key,
        temperature=temperature,
    )

SUMMARY_PROMPT = """You are an academic assistant for the University of Mines and Technology (UMaT), Ghana.

Based on the following lecture transcript, generate a structured academic summary.

Format your response as:
## Lecture Summary
[2-3 sentence overview]

## Key Topics Covered
- [Topic 1]
- [Topic 2]
...

## Important Concepts
[Explain the 3-5 most important concepts in simple terms]

## Key Takeaways
- [Takeaway 1]
- [Takeaway 2]
...

Keep the summary academic and appropriate for undergraduate university students.
Do not include any information not present in the transcript.

TRANSCRIPT:
{transcript}"""


NOTES_PROMPT = """You are an academic assistant for the University of Mines and Technology (UMaT), Ghana.

Generate well-structured study notes from this lecture transcript.
Format as detailed, organized notes with headers, subheaders, and bullet points.
Include definitions, examples mentioned, and important relationships between concepts.
Write in a clear style suitable for exam revision.

TRANSCRIPT:
{transcript}"""


QUIZ_PROMPT = """You are an academic assistant for the University of Mines and Technology (UMaT), Ghana.

Based on this lecture transcript, generate 5 multiple-choice practice questions.

For each question use this exact format:
Q[number]: [Question text]
A) [Option A]
B) [Option B]
C) [Option C]
D) [Option D]
Answer: [Letter]
Explanation: [Brief explanation of why this is correct]

Only generate questions based on content explicitly covered in the transcript.

TRANSCRIPT:
{transcript}"""


TUTOR_PROMPT = """You are the UMaT AI Tutor — an academic assistant for students at the University of Mines and Technology (UMaT), Ghana.

You can help with any academic task the student asks for, including:
- answering questions about course content
- generating practice quizzes and multiple-choice questions
- building exam preparation outlines and study plans
- explaining concepts step by step in simpler terms
- summarising or comparing topics from the materials

GROUNDING RULES:
- Base course-specific content on the COURSE CONTEXT below; it is excerpted from this course's materials and lecture transcripts.
- When the request is a greeting or a general study-skills question, respond helpfully and briefly.
- When the student asks about course content that is NOT covered in the context, say you could not find it in the course materials and suggest asking the lecturer — never invent course-specific facts.
{task_guidance}
COURSE CONTEXT:
{context}

STUDENT REQUEST:
{question}

RESPONSE:"""

# Extra instruction appended for detected task intents.
TASK_GUIDANCE = {
    "quiz": """
TASK: The student wants practice questions. Generate 5 multiple-choice questions strictly from the COURSE CONTEXT, each in this format:
Q[number]: [Question text]
A) ... B) ... C) ... D) ...
Answer: [Letter]
Explanation: [one sentence]
""",
    "exam_prep": """
TASK: The student wants exam preparation. Produce a structured revision guide from the COURSE CONTEXT: key topics, must-know definitions, common pitfalls, and 3 likely exam-style questions with brief model answers.
""",
    "explain": """
TASK: The student wants an explanation. Explain step by step in simple terms, define any jargon, and use examples from the COURSE CONTEXT where possible.
""",
    "qa": "",
}

LECTURER_PROMPT = """You are the UMaT AI Teaching Assistant for lecturers at the University of Mines and Technology (UMaT), Ghana.

The lecturer may ask about student performance and struggle patterns, ask about course content, or ask you to produce teaching artifacts (quiz questions for class, revision material, lesson recaps, announcements).

RULES:
- For performance questions, use the analytics context embedded in the request, and be honest about what the data does and does not show.
- For content requests, base course-specific facts on the COURSE CONTEXT below; never invent course-specific facts.
- Be practical and concise. Offer concrete, actionable suggestions a lecturer can apply.

COURSE CONTEXT:
{context}

LECTURER REQUEST:
{question}

RESPONSE:"""


class LLMProcessor:
    def __init__(self):
        self.llm = _make_llm(temperature=0.3)

    def _invoke(self, prompt: str, temperature: float, max_chars: int) -> str:
        # Temperature is set on the model; easiest is to recreate per call.
        llm = _make_llm(temperature=temperature)
        prompt = prompt[:max_chars]
        result = llm.invoke(prompt)
        return result.content.strip()

    def generate_summary(self, transcript: str) -> str:
        prompt = SUMMARY_PROMPT.format(transcript=transcript)
        return self._invoke(prompt, temperature=0.3, max_chars=20000)

    def generate_notes(self, transcript: str) -> str:
        prompt = NOTES_PROMPT.format(transcript=transcript)
        return self._invoke(prompt, temperature=0.2, max_chars=20000)

    def generate_quiz(self, transcript: str) -> str:
        prompt = QUIZ_PROMPT.format(transcript=transcript)
        return self._invoke(prompt, temperature=0.4, max_chars=18000)

    def answer_question(self, question: str, context_chunks: List[str],
                        role: str = "student", task: str = "qa") -> str:
        context = "\n\n---\n\n".join(context_chunks) if context_chunks else "(no indexed material matched this request)"
        if role == "lecturer":
            prompt = LECTURER_PROMPT.format(context=context, question=question)
            return self._invoke(prompt, temperature=0.3, max_chars=24000)
        guidance = TASK_GUIDANCE.get(task, "")
        prompt = TUTOR_PROMPT.format(task_guidance=guidance, context=context, question=question)
        # Generative tasks (quiz, exam prep) benefit from a little more freedom.
        temperature = 0.1 if task == "qa" else 0.4
        return self._invoke(prompt, temperature=temperature, max_chars=24000)