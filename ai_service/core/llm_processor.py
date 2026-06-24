import hashlib
import os
from typing import List, Optional
from diskcache import Cache
from config import get_settings
from langchain_google_genai import ChatGoogleGenerativeAI

settings = get_settings()

# Disk-based LLM response cache (persists across restarts, LRU eviction).
_cache_dir = os.path.join(os.path.dirname(__file__), '..', '.llm_cache')
os.makedirs(_cache_dir, exist_ok=True)
_llm_cache = Cache(_cache_dir, size_limit=500 * 1024 * 1024)  # 500 MB limit.


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


TUTOR_PROMPT = """You are the UMaT AI Tutor — a sharp, thoughtful, and enthusiastic academic assistant for students at the University of Mines and Technology (UMaT), Ghana.

You have two superpowers:
1. You deeply understand the provided course materials and can explain them clearly.
2. You can think beyond them — making connections, drawing analogies, and helping students truly understand, not just memorize.

COURSE CONTEXT (from course materials):
{context}

CONVERSATION HISTORY (recent messages):
{conversation_history}

STUDENT REQUEST:
{question}

TASK GUIDANCE:
{task_guidance}

INSTRUCTIONS:
1. ACCURACY: Ground your response primarily in the COURSE CONTEXT above. When you use external knowledge, general principles, or draw analogies beyond the materials, clearly mark them with "Here's a way to think about it:" or "To put it in a broader context:". Never pretend external knowledge came from the materials.
2. INTELLIGENT SYNTHESIS: Connect ideas across different materials, identify patterns, and help students see the bigger picture. For example, if a student asks about a specific formula, also mention what it relates to and why it matters.
3. CONVERSATION MEMORY: Pay attention to the CONVERSATION HISTORY. Reference earlier topics naturally ("As we discussed earlier...", "Building on what we talked about..."). If the student refers back to something they asked before, pick up right where you left off.
4. CLARITY & DEPTH: Start with a clear, direct answer. Then offer to go deeper. Use analogies, real-world applications, and thought-provoking questions to stimulate genuine understanding.
5. INTELLECTUAL HUMILITY: If you're less certain about a connection or extrapolation, say so: "Based on the course materials, I can tell you X. I think it may relate to Y, but that's my interpretation — you might want to discuss this with your lecturer."
6. ENGAGING DELIVERY: Be conversational and warm. Use "you" to address the student. Vary your sentence structure. Ask follow-up questions to encourage dialogue ("Does that clarify it?", "Would you like me to go deeper on any part?").
7. STRUCTURE: For complex answers, use clear sections. For simple answers, be direct. Match your structure to the question.
8. FORMATTING: Use **bold** for key terms, bullet points for lists, and short paragraphs for readability. Never use markdown tables unless the data truly requires it.

RESPONSE:"""

TASK_GUIDANCE = {
    "quiz": """
TASK: Generate practice questions based strictly on the COURSE CONTEXT to test understanding.

CRITICAL — You MUST structure your response as follows:

1. Begin with a brief 1-2 sentence text introduction (e.g. "Here are some practice questions on [topic] to test your understanding.")
2. Then immediately output a JSON code block with the quiz structure. The JSON code block MUST be wrapped in triple backticks with the `json` marker.

The quiz JSON must follow this exact schema:
```json
{"quiz":{"title":"Practice Quiz: [Topic Name]","questions":[{"type":"objective","question":"Question text?","options":["Opt A","Opt B","Opt C","Opt D"],"correct":0,"explanation":"Why this is correct."},{"type":"theoretical","question":"Explain X in your own words.","answer_hint":"Key points: definition, example, importance"}]}}
```

RULES:
- Generate an appropriate number of questions (5 if the student did not specify). Mix objective (multiple-choice) and theoretical (explain/essay) questions.
- Objective questions: exactly 4 options, `correct` is 0-based index of the right answer, include a one-sentence `explanation`.
- Theoretical questions: include `answer_hint` with key points the answer should cover.
- Vary difficulty: ~2 straightforward, ~2 moderate, ~1 challenging.
- Only use information explicitly present in the COURSE CONTEXT.
- Make distractors (wrong options) plausible but clearly incorrect based on the materials.
- Keep language clear and appropriate for undergraduate level.
""",
    "exam_prep": """
TASK: Create a comprehensive exam preparation guide based on the COURSE CONTEXT.

INCLUDE:
1. KEY TOPICS: List 4-6 main topics/concepts that are most important for the exam
2. MUST-KNOW DEFINITIONS: Provide clear definitions for 3-5 essential terms or concepts
3. COMMON PITFALLS: Identify 2-3 frequent mistakes students make and how to avoid them
4. STUDY STRATEGIES: Suggest 2-3 effective approaches to prepare for this exam
5. SAMPLE QUESTIONS: Provide 3 exam-style questions (not multiple choice) with brief model answers

FORMAT:
## Exam Preparation Guide

### Key Topics
- [Topic 1]: [Brief importance/explanation]
- [Topic 2]: [Brief importance/explanation]

### Must-Know Definitions
- [Term 1]: [Clear definition]

### Common Pitfalls to Avoid
- [Pitfall 1]: [What it is and how to avoid it]

### Recommended Study Strategies
- [Strategy 1]: [Brief explanation]

### Sample Questions & Answer Guidelines
1. [Question]: [Brief model answer or key points to cover]

CONSTRAINTS:
- Base all content strictly on the COURSE CONTEXT
- Focus on what is most likely to be important based on the materials provided
""",
    "explain": """
TASK: Provide a clear, step-by-step explanation of the concept or process asked about. If the COURSE CONTEXT has relevant information, anchor your explanation there. If the context is sparse, supplement with general academic knowledge but clearly distinguish what came from materials vs. what is general knowledge.

STRUCTURE:
1. **Definition**: Start with a clear, concise definition in simple terms
2. **Breakdown**: Divide the concept/process into logical steps or components
3. **Explanation**: Explain each step/component clearly, using examples from the context where possible
4. **Examples**: Provide 1-2 concrete examples from the materials that illustrate the concept
5. **Summary**: End with a brief summary tying everything together

APPROACH:
- Use the "explain like I'm 12" principle — make complex ideas accessible
- Define any technical terms or jargon when first used
- Draw analogies and make real-world connections to aid understanding
- If the context doesn't contain enough information, say so and offer a general explanation

CONSTRAINTS:
- Mark general knowledge clearly ("In broader engineering context...")
- If insufficient information exists, be transparent about limitations
""",
    "summary": """
TASK: Provide a clear, organized summary of the key content from the COURSE CONTEXT.

STRUCTURE:
1. **Overview**: 2-3 sentences summarizing what the materials cover
2. **Key Points**: Bullet-point list of the most important takeaways
3. **Connections**: How the topics relate to each other and to the broader subject
4. **Suggested Focus**: What the student should pay most attention to

Keep it concise but comprehensive. Use plain language.
""",
    "qa": ""
}

LECTURER_PROMPT = """You are the UMaT AI Teaching Assistant — a knowledgeable and practical assistant for lecturers at the University of Mines and Technology (UMaT), Ghana.

Your role is to support teaching effectiveness by providing data-informed insights, clear explanations, and helpful teaching resources based on the provided context.

COURSE CONTEXT:
{context}

CONVERSATION HISTORY:
{conversation_history}

LECTURER REQUEST:
{question}

INSTRUCTIONS:
1. EVIDENCE-BASED: When answering performance or struggle-related questions, rely ONLY on the analytics context provided in the request. Be transparent about limitations in the data.
2. CONTENT ACCURACY: For course content questions, base your response solely on the COURSE CONTEXT above. Do not invent or extrapolate beyond the provided materials.
3. PRACTICALITY: Offer concrete, actionable suggestions that a lecturer can realistically implement in their teaching practice.
4. CLARITY: Explain educational concepts and data insights in clear, accessible language.
5. BALANCE: Acknowledge both strengths and areas for improvement when discussing student performance.
6. CONCISENESS: Provide thorough but succinct responses focused on actionable insights.
7. TONE: Be professional, supportive, and collaborative in your tone.

RESPONSE:"""


class LLMProcessor:
    def __init__(self):
        self.llm = _make_llm(temperature=0.3)

    def _invoke(self, prompt: str, temperature: float, max_chars: int) -> str:
        # Build a deterministic cache key so identical prompts skip the LLM.
        raw = f"{prompt}___{temperature}___{max_chars}"
        key = hashlib.sha256(raw.encode()).hexdigest()
        cached = _llm_cache.get(key)
        if cached is not None:
            return cached

        llm = _make_llm(temperature=temperature)
        prompt = prompt[:max_chars]
        result = llm.invoke(prompt)
        text = result.content.strip()

        # Cache for 15 minutes.
        _llm_cache.set(key, text, expire=900)
        return text

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
                        role: str = "student", task: str = "qa",
                        conversation_history: Optional[str] = None) -> str:
        context = "\n\n---\n\n".join(context_chunks) if context_chunks else "(no indexed material matched this request)"
        history = conversation_history or "(no previous conversation in this session)"
        if role == "lecturer":
            prompt = LECTURER_PROMPT.format(
                context=context, question=question,
                conversation_history=history
            )
            return self._invoke(prompt, temperature=0.3, max_chars=24000)

        guidance = TASK_GUIDANCE.get(task, "")
        prompt = TUTOR_PROMPT.format(
            task_guidance=guidance,
            context=context,
            conversation_history=history,
            question=question,
        )
        temperature = 0.1 if task == "qa" else 0.4
        return self._invoke(prompt, temperature=temperature, max_chars=24000)

    def answer_with_prompt(self, prompt: str, task: str = "qa") -> str:
        """Generate a response from a fully constructed system prompt."""
        temperature = 0.1 if task == "qa" else 0.4
        return self._invoke(prompt, temperature=temperature, max_chars=24000)

    def stream_prompt(self, prompt: str, task: str = "qa"):
        """Yield text chunks as the LLM generates them."""
        temperature = 0.1 if task == "qa" else 0.4
        llm = _make_llm(temperature=temperature)
        prompt = prompt[:24000]
        for chunk in llm.stream(prompt):
            text = getattr(chunk, "content", None) or str(chunk)
            if text:
                yield text
