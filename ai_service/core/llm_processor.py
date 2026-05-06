from typing import List
from config import get_settings
from langchain_google_genai import ChatGoogleGenerativeAI

settings = get_settings()

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


RAG_PROMPT = """You are an academic assistant for the University of Mines and Technology (UMaT), Ghana.

Answer the student's question using ONLY the context provided below from course materials and lecture transcripts.
If the answer cannot be found in the context, say: "I could not find information about this in your course materials. Please ask your lecturer."
Do not use any external knowledge. Be concise and accurate.

CONTEXT FROM COURSE MATERIALS:
{context}

STUDENT QUESTION:
{question}

ANSWER:"""


class LLMProcessor:
    def __init__(self):
        self.llm = ChatGoogleGenerativeAI(
            model=settings.llm_model,                 # gemini-1.5-flash
            google_api_key=settings.google_api_key,
            temperature=0.3,
        )

    def _invoke(self, prompt: str, temperature: float, max_chars: int) -> str:
        # Gemini through LangChain uses "temperature" set on model; easiest is to recreate per call
        llm = ChatGoogleGenerativeAI(
            model=settings.llm_model,
            google_api_key=settings.google_api_key,
            temperature=temperature,
        )
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

    def answer_question(self, question: str, context_chunks: List[str]) -> str:
        context = "\n\n---\n\n".join(context_chunks)
        prompt = RAG_PROMPT.format(context=context, question=question)
        return self._invoke(prompt, temperature=0.1, max_chars=20000)