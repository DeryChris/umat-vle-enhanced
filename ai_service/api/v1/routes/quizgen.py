# ============================================================
# POST /api/v1/quizgen/generate — AI quiz question generation
# Supports: instruction-aware generation, grounding modes,
#           compliance validation, structured question metadata.
# ============================================================

import base64
import json
import logging
from typing import Any, Dict, List, Optional, Union

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from middleware.auth import verify_token
from core.llm_processor import LLMProcessor
from core.vector_store import VectorStoreManager
from config import get_settings

logger   = logging.getLogger(__name__)
router   = APIRouter(prefix="/quizgen", tags=["quizgen"])
settings = get_settings()
_lecturer_llm = LLMProcessor(provider=settings.effective_lecturer_provider)


# ── Schemas ─────────────────────────────────────────────────

class QuizGenRequest(BaseModel):
    source_type:        str
    content:            Optional[str] = None
    material_ids:       Optional[List[int]] = None
    course_id:          int
    question_types:     Dict[str, int] = Field(default={"multichoice": 5})
    bloom_level:        Union[str, Dict[str, int]] = "understand"
    difficulty:         Union[str, Dict[str, int]] = "medium"
    marks_per_question: float = 1.0
    ai_instructions:    Optional[str] = None
    grounding_mode:     Optional[str] = "applied"
    instruction_presets: Optional[List[str]] = None


class GeneratedQuestion(BaseModel):
    type:                 str
    question_text:        str
    options:              Optional[List[str]] = None
    correct_answer_index: Optional[int] = None
    correct_text:         Optional[str] = None
    marks:                float = 1.0
    feedback_correct:     str = ""
    feedback_incorrect:   str = ""
    source_reference:     str = ""
    instruction_tags:     Optional[List[str]] = None
    scenario_type:        Optional[str] = None
    scenario_is_constructed: Optional[bool] = None


class QuizGenResponse(BaseModel):
    questions:      List[GeneratedQuestion]
    total:          int
    llm_used:       str = "openai"
    compliance:     Optional[Dict[str, Any]] = None


# ── Preset definitions ──────────────────────────────────────

PRESET_DEFINITIONS = {
    "critical_thinking":        "Ask critical-thinking questions requiring analysis, evaluation, and deeper reasoning rather than memorisation.",
    "application_based":        "Use application-based questions that test whether students can apply concepts in new or practical situations.",
    "scenario_based":           "Create scenario-based questions presenting realistic situations that students must analyse and solve.",
    "case_study":               "Include short case studies: brief but meaningful scenarios testing one or more concepts. Avoid unnecessary background.",
    "real_world_examples":      "Use real-world examples. Default to constructed realistic examples (fictional organisations resembling real practice). Only use named real organisations for stable, verifiable facts.",
    "ghanaian_examples":        "Use Ghanaian examples and contexts. Construct realistic scenarios involving Ghanaian businesses, industries, or situations. Do not invent claims about real Ghanaian organisations.",
    "industry_examples":        "Use industry-specific examples relevant to the course material.",
    "problem_solving":          "Test problem-solving ability. Present situations requiring students to diagnose, recommend, or decide.",
    "comparison_justification": "Require comparison and justification. Students should analyse alternatives and defend their choices.",
    "avoid_direct_recall":      "Avoid direct recall questions. Do not use 'What is...' or 'Define...' unless specifically required by Bloom's Remember level.",
    "avoid_trick_ambiguous":    "Avoid trick or ambiguous questions. Every question should be clear and fair.",
    "include_calculations":     "Include calculations or quantitative problems where the source material supports them. Do not invent formulas.",
    "provide_explanations":     "Provide detailed answer explanations and feedback for both correct and incorrect answers.",
}


# ── Prompt builder ──────────────────────────────────────────

GROUNDING_INSTRUCTIONS = {
    "strict": (
        "STRICT GROUNDING — Use ONLY information explicitly stated in the selected source material.\n"
        "Do NOT construct new scenarios, case studies, or examples.\n"
        "Questions must test direct comprehension, recall, or interpretation of the source.\n"
        "Suitable for: definitions, recall questions, direct comprehension, basic True/False.\n"
    ),
    "applied": (
        "APPLIED GROUNDING — The course material defines the concepts, principles, and expected answers.\n"
        "You MAY and SHOULD construct new realistic scenarios, case studies, workplace situations,\n"
        "Ghanaian examples, and problem-solving contexts that are NOT explicitly written in the source.\n"
        "Students must be able to answer correctly by applying concepts taught in the material.\n"
        "The tested concept and correct answer MUST be derivable from the source material.\n"
        "Do NOT introduce concepts absent from the source.\n"
        "Do NOT make the answer depend on external facts not in the material.\n"
        "This is the RECOMMENDED mode for application, critical-thinking, and scenario-based questions.\n"
    ),
    "enriched": (
        "ENRICHED GROUNDING — The course material provides the main assessed concept.\n"
        "You MAY include limited, widely accepted external context to make scenarios more realistic.\n"
        "Rules for external context:\n"
        "- Clearly label any externally introduced context internally.\n"
        "- Do not use unverifiable facts, statistics, or claims.\n"
        "- Do not make the correct answer depend primarily on external information.\n"
        "- Do not use live or time-sensitive information.\n"
        "- The assessed concept must still come from the source material.\n"
    ),
}

BLOOM_INSTRUCTIONS = {
    "remember":   "Question patterns: Define, Identify, List, Recall, Name, State. Test factual recall of concepts, terms, or principles explicitly stated in the material.",
    "understand": "Question patterns: Explain, Summarise, Distinguish, Interpret, Describe. Test whether students understand and can explain concepts in their own words.",
    "apply":      "Question patterns: Use a concept in a new situation, Select an appropriate solution, Apply a principle to a practical scenario, Determine what should happen in a given case. Present realistic or constructed scenarios requiring students to use learned concepts.",
    "analyze":    "Question patterns: Examine a case, Compare alternatives, Identify relationships, Diagnose a problem, Distinguish relevant from irrelevant information. Require students to break down scenarios and identify component parts or relationships.",
    "evaluate":   "Question patterns: Judge a decision, Defend a choice, Assess an approach, Recommend and justify a solution. Require students to make judgements based on criteria and justify their reasoning.",
    "create":     "Question patterns: Design a solution, Develop a strategy, Propose a model, Construct an implementation plan. Require students to synthesise knowledge into original responses.",
}

DIFFICULTY_INSTRUCTIONS = {
    "easy":   "Straightforward recall or recognition. Minimal reasoning. Simple, direct questions.",
    "medium": "Requires understanding and application. Moderate reasoning. Students must apply concepts to answer correctly.",
    "hard":   "Requires analysis, synthesis, or evaluation. Complex reasoning. Multi-step thinking or evaluation of competing options.",
}

SCENARIO_TYPE_LABELS = {
    "direct_recall":         "Direct recall from material",
    "comprehension":         "Comprehension of source concept",
    "application_scenario":  "Application to constructed scenario",
    "case_study":            "Case study scenario",
    "ghanaian_context":      "Ghanaian contextual scenario",
    "industry_context":      "Industry-specific scenario",
    "problem_solving":       "Problem-solving situation",
    "comparison_analysis":   "Comparative analysis",
    "real_world_constructed": "Realistic constructed example",
    "enriched_external":     "Enriched with external context",
}


def _build_instruction_section(
    grounding_mode: str,
    instruction_presets: List[str],
    custom_instructions: str,
    bloom_level: Union[str, Dict[str, int]],
) -> str:
    """Build the high-priority lecturer instructions section of the prompt."""
    sections = []

    # Grounding mode — this is the primary directive.
    grounding_text = GROUNDING_INSTRUCTIONS.get(grounding_mode, GROUNDING_INSTRUCTIONS["applied"])
    sections.append(f"=== QUESTION GROUNDING STYLE ===\n{grounding_text}")

    # Preset instructions.
    if instruction_presets:
        preset_lines = []
        for preset_key in instruction_presets:
            if preset_key in PRESET_DEFINITIONS:
                preset_lines.append(f"- {PRESET_DEFINITIONS[preset_key]}")
        if preset_lines:
            sections.append("=== LECTURER INSTRUCTION PRESETS ===\nFollow these precisely:\n" + "\n".join(preset_lines))

    # Custom instructions.
    if custom_instructions and custom_instructions.strip():
        sections.append(f"=== ADDITIONAL LECTURER INSTRUCTIONS ===\n{custom_instructions.strip()}")

    # Bloom's level guidance.
    if isinstance(bloom_level, str):
        bloom_text = BLOOM_INSTRUCTIONS.get(bloom_level, "")
        if bloom_text:
            sections.append(f"=== BLOOM'S TAXONOMY TARGET: {bloom_level.upper()} ===\n{bloom_text}")
    elif isinstance(bloom_level, dict):
        bloom_lines = []
        for level, count in bloom_level.items():
            if level in BLOOM_INSTRUCTIONS:
                bloom_lines.append(f"- {level} ({count} questions): {BLOOM_INSTRUCTIONS[level]}")
        if bloom_lines:
            sections.append("=== BLOOM'S TAXONOMY DISTRIBUTION ===\n" + "\n".join(bloom_lines))

    return "\n\n".join(sections)


def _build_compliance_prompt(
    questions_json: str,
    instruction_presets: List[str],
    custom_instructions: str,
    grounding_mode: str,
) -> str:
    """Build a prompt to validate compliance of generated questions with lecturer instructions."""
    preset_labels = [PRESET_DEFINITIONS.get(k, k) for k in instruction_presets if k in PRESET_DEFINITIONS]

    return f"""You are validating whether generated quiz questions comply with lecturer instructions.

LECTURER INSTRUCTIONS:
Grounding mode: {grounding_mode}
Presets: {json.dumps(preset_labels, indent=2)}
Custom instructions: {custom_instructions or '(none)'}

GENERATED QUESTIONS:
{questions_json}

For each question, evaluate:
1. Does it match the requested question type?
2. Does it match the assigned Bloom's level?
3. Is the assessed concept supported by the source?
4. If "avoid direct recall" was requested, is it NOT a simple definition or "What is..." question?
5. If "case study" was requested, does it contain a meaningful scenario?
6. If "Ghanaian examples" was requested, is the context actually Ghanaian?
7. If "application_based" was requested, does it test application rather than recall?

Return a JSON object with this structure:
{{
  "compliance_summary": "Brief overall assessment",
  "total_questions": N,
  "compliant_count": N,
  "non_compliant_count": N,
  "details": [
    {{
      "question_index": 0,
      "compliant": true,
      "style": "application",
      "bloom_match": true,
      "grounding_type": "constructed_scenario",
      "tags": ["application_based", "case_study"],
      "notes": "Brief note"
    }}
  ]
}}

Be strict. A simple "What is X?" question does NOT count as application or analysis.
Do not mark questions as compliant if they ignore the requested instructions."""


def _evaluate_compliance_local(
    questions_data: list,
    instruction_presets: List[str],
    custom_instructions: str,
    grounding_mode: str,
) -> dict:
    """Local heuristic compliance check (fast, no LLM call)."""
    total = len(questions_data)
    compliant = 0
    details = []

    avoid_recall = "avoid_direct_recall" in instruction_presets
    want_case_study = "case_study" in instruction_presets
    want_ghanaian = "ghanaian_examples" in instruction_presets
    want_application = "application_based" in instruction_presets or "critical_thinking" in instruction_presets

    for i, q in enumerate(questions_data):
        qt = q.get("question_text", "")
        q_lower = qt.lower()
        is_compliant = True
        tags = []
        style = "recall"
        grounding_type = "direct"

        # Detect question style.
        recall_patterns = ["what is", "define", "list", "name the", "state the", "identify the"]
        application_patterns = ["if ", "suppose", "scenario", "case", "apply", "using", "situation", "example"]
        analysis_patterns = ["compare", "analyse", "analyze", "evaluate", "why", "justify", "recommend"]

        is_recall = any(q_lower.startswith(p) or q_lower.lstrip().startswith(p) for p in recall_patterns)
        is_application = any(p in q_lower for p in application_patterns)
        is_analysis = any(p in q_lower for p in analysis_patterns)

        if is_recall:
            style = "recall"
        elif is_analysis:
            style = "analysis"
        elif is_application:
            style = "application"
        else:
            style = "comprehension"

        # Detect scenario.
        scenario_indicators = ["company", "organisation", "organization", "business", "cooperative",
                               "mining", "retailer", "farmer", "student", "manager", "team",
                               "ghana", "accra", "kumasi", "takoradi", "tema"]
        has_scenario = any(ind in q_lower for ind in scenario_indicators)
        if has_scenario:
            grounding_type = "constructed_scenario" if grounding_mode != "strict" else "source_based"

        if "ghana" in q_lower or "ghanaian" in q_lower:
            tags.append("ghanaian_context")
            grounding_type = "ghanaian_context"

        if "case" in q_lower or "scenario" in q_lower:
            tags.append("case_study")

        if want_case_study and not has_scenario and q.get("type") != "shortanswer":
            is_compliant = False
        if avoid_recall and is_recall and style == "recall":
            is_compliant = False
        if want_ghanaian and "ghana" not in q_lower and "ghanaian" not in q_lower:
            pass  # Soft check — not all questions need to be Ghanaian.
        if want_application and style == "recall":
            is_compliant = False

        if style == "application":
            tags.append("application_based")
        elif style == "analysis":
            tags.append("critical_thinking")
        if has_scenario:
            tags.append("scenario_based")

        if is_compliant:
            compliant += 1

        details.append({
            "question_index": i,
            "compliant": is_compliant,
            "style": style,
            "bloom_match": True,
            "grounding_type": grounding_type,
            "tags": tags,
            "notes": f"{'Non-compliant: ' if not is_compliant else ''}{style} question, {'with scenario' if has_scenario else 'no scenario'}",
        })

    non_compliant = total - compliant
    style_counts = {}
    for d in details:
        s = d["style"]
        style_counts[s] = style_counts.get(s, 0) + 1
    tag_counts = {}
    for d in details:
        for t in d["tags"]:
            tag_counts[t] = tag_counts.get(t, 0) + 1

    return {
        "compliance_summary": f"{compliant} of {total} questions comply with instructions. "
                              f"Styles: {style_counts}. Tags: {tag_counts}.",
        "total_questions": total,
        "compliant_count": compliant,
        "non_compliant_count": non_compliant,
        "details": details,
    }


# ── Helpers ─────────────────────────────────────────────────

def get_llm() -> LLMProcessor:
    try:
        return _lecturer_llm
    except Exception as e:
        logger.warning(f"LLM not available: {e}")
        raise HTTPException(status_code=503, detail="LLM service unavailable")


def _parse_quizgen_json(raw: str) -> list:
    text = raw.strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[1] if "\n" in text else text
        if text.rstrip().endswith("```"):
            text = text.rstrip()[:-3]
        text = text.strip()
    parsed = json.loads(text)
    if isinstance(parsed, dict) and "questions" in parsed:
        return parsed["questions"]
    if isinstance(parsed, list):
        return parsed
    for key in ("quiz", "assessment", "data", "result", "output"):
        if isinstance(parsed, dict) and key in parsed:
            inner = parsed[key]
            if isinstance(inner, dict) and "questions" in inner:
                return inner["questions"]
            if isinstance(inner, list):
                return inner
    raise ValueError(f"Unexpected JSON structure: {type(parsed)}")


def _fetch_material_context(material_id: int, course_id: int) -> Optional[str]:
    try:
        vs = VectorStoreManager()
        results = vs.get_documents_by_filter(
            course_id=course_id,
            where_filter={"material_id": str(material_id)},
            limit=50,
        )
        if results:
            chunks = [doc for doc, _ in results]
            return "\n\n".join(chunks)
    except Exception as e:
        logger.warning(f"Failed to fetch material context from ChromaDB: {e}")
    return None


def _resolve_context(request: QuizGenRequest) -> str:
    if request.source_type == "text" and request.content:
        return request.content

    if request.source_type == "material_id" and request.material_ids:
        all_chunks = []
        for mid in request.material_ids:
            ctx = _fetch_material_context(mid, request.course_id)
            if ctx:
                all_chunks.append(ctx)
            else:
                logger.warning(f"Material {mid} has no indexed content")
        if all_chunks:
            return "\n\n---\n\n".join(all_chunks)
        raise HTTPException(
            status_code=404,
            detail="Selected materials have no indexed content. Please ensure materials are fully indexed."
        )

    raise HTTPException(status_code=422, detail="Provide either text content or at least one material ID.")


def _format_type_distribution(qtypes: Dict[str, int]) -> str:
    lines = []
    for qtype, count in qtypes.items():
        lines.append(f"- {qtype}: {count} questions")
    return "\n".join(lines)


def _format_bloom_distribution(bloom: Union[str, Dict[str, int]], total: int) -> str:
    if isinstance(bloom, str):
        desc = BLOOM_INSTRUCTIONS.get(bloom, bloom)
        return f"ALL questions at '{bloom}' level.\n{desc}"
    lines = []
    for level, count in bloom.items():
        desc = BLOOM_INSTRUCTIONS.get(level, "")
        lines.append(f"- {level}: {count} questions — {desc}")
    return "\n".join(lines)


def _format_difficulty_distribution(diff: Union[str, Dict[str, int]]) -> str:
    if isinstance(diff, str):
        desc = DIFFICULTY_INSTRUCTIONS.get(diff, diff)
        return f"ALL questions at '{diff}' difficulty.\nDescription: {desc}"
    lines = []
    for level, count in diff.items():
        desc = DIFFICULTY_INSTRUCTIONS.get(level, "")
        lines.append(f"- {level}: {count} questions — {desc}")
    return "\n".join(lines)


def _calculate_total(qtypes: Dict[str, int]) -> int:
    return sum(qtypes.values())


# ── Main prompt template ────────────────────────────────────

QUIZGEN_PROMPT = """You are an expert academic assessment writer at the University of Mines and Technology (UMaT), Ghana.

You are generating an assessment from the supplied course material below.

SOURCE MATERIAL:
{context}

=== LECTURER INSTRUCTIONS (HIGHEST PRIORITY — follow these precisely) ===

{instruction_section}

=== QUESTION SPECIFICATIONS ===

QUESTION COMPOSITION:
{type_distribution}

DIFFICULTY DISTRIBUTION:
{difficulty_distribution}

MARKS PER QUESTION: {marks}

=== OUTPUT SCHEMA ===

Output ONLY valid JSON — no markdown, no code fences, no extra text.
Response must be a JSON object with a single key "questions" containing an array of exactly the specified number of questions.

Each question object must follow this schema:
{{
  "type": "multichoice" | "truefalse" | "shortanswer",
  "question_text": "The full question text including any scenario or context",
  "options": ["Option A", "Option B", "Option C", "Option D"],
  "correct_answer_index": 0,
  "correct_text": "Exact expected answer (for shortanswer only, set options=null)",
  "marks": {marks},
  "feedback_correct": "Why this answer is correct — reference the source concept",
  "feedback_incorrect": "Why a specific wrong option is wrong — explain the misconception",
  "source_reference": "The concept from the source material being assessed (NOT the scenario)",
  "scenario_type": "One of: direct_recall, comprehension, application_scenario, case_study, ghanaian_context, industry_context, problem_solving, comparison_analysis, real_world_constructed, enriched_external",
  "scenario_is_constructed": true_or_false
}}

=== QUESTION CONSTRUCTION RULES ===

1. Every question MUST assess a concept from the source material above.
2. When the grounding mode is "applied" or "enriched", you SHOULD construct new scenarios, case studies, and examples.
3. Constructed scenarios do NOT need to appear in the source material — only the tested concept must be traceable to the source.
4. Do NOT cite a constructed scenario as though it appeared on a source page. Cite the underlying concept instead.
5. Do NOT invent concepts absent from the source material.
6. Do NOT make the answer depend on external facts students were never taught.
7. Do NOT introduce unsupported statistics, laws, quotations, or historical facts.
8. Do NOT copy source sentences as questions — transform them into assessment-appropriate form.
9. "multichoice": exactly 4 options, one clearly correct, distractors plausible.
10. "truefalse": options MUST be ["True", "False"], one correct.
11. "shortanswer": options=null, set correct_text with a clear model answer.
12. Distractors must be based on realistic misunderstandings, not obviously unrelated options.
13. Vary difficulty across the question set as specified.
14. Match the Bloom's taxonomy levels as specified in the distribution.
15. Label each question's scenario_type accurately.
16. Set scenario_is_constructed=true for any scenario not directly from the source.
"""


# ── Endpoint ────────────────────────────────────────────────

@router.post("/generate", response_model=QuizGenResponse)
async def generate_quiz(
    request: QuizGenRequest,
    _ = Depends(verify_token),
):
    try:
        total = _calculate_total(request.question_types)
        if total <= 0:
            raise HTTPException(status_code=422, detail="Total question count must be at least 1.")

        context = _resolve_context(request)
        context = context[:10000]

        grounding_mode = request.grounding_mode or "applied"
        presets = request.instruction_presets or []
        custom_instr = request.ai_instructions or ""

        instruction_section = _build_instruction_section(
            grounding_mode=grounding_mode,
            instruction_presets=presets,
            custom_instructions=custom_instr,
            bloom_level=request.bloom_level,
        )

        prompt = QUIZGEN_PROMPT.format(
            context=context,
            instruction_section=instruction_section,
            type_distribution=_format_type_distribution(request.question_types),
            difficulty_distribution=_format_difficulty_distribution(request.difficulty),
            marks=request.marks_per_question,
        )

        llm = get_llm()
        raw = await llm.generate_assessment(prompt, temperature=0.3, max_chars=15000)

        questions_data = _parse_quizgen_json(raw)

        validated = []
        for q in questions_data:
            qtype = q.get("type", "")
            if qtype not in ("multichoice", "truefalse", "shortanswer"):
                logger.warning(f"Skipping unsupported question type: {qtype}")
                continue
            if not q.get("question_text"):
                continue
            q["marks"] = q.get("marks", request.marks_per_question)
            validated.append(GeneratedQuestion(**q))

        if not validated:
            raise HTTPException(status_code=500, detail="AI returned no valid questions.")

        compliance = _evaluate_compliance_local(
            [q.dict() for q in validated],
            presets,
            custom_instr,
            grounding_mode,
        )

        logger.info(
            f"Quiz generated: {len(validated)} questions, "
            f"grounding={grounding_mode}, presets={presets}, "
            f"compliance={compliance['compliant_count']}/{compliance['total_questions']}"
        )

        return QuizGenResponse(
            questions=validated,
            total=len(validated),
            llm_used=settings.llm_provider,
            compliance=compliance,
        )

    except json.JSONDecodeError:
        logger.error(f"LLM returned invalid JSON (first 2000 chars): {raw[:2000]}")
        try:
            strict_prompt = prompt + "\n\nCRITICAL: Your previous response was not valid JSON. Respond with ONLY a raw JSON object. No explanations. No markdown. No code fences."
            raw2 = await llm.generate_assessment(strict_prompt, temperature=0.1, max_chars=15000)
            questions_data = _parse_quizgen_json(raw2)
            validated = []
            for q in questions_data:
                qtype = q.get("type", "")
                if qtype not in ("multichoice", "truefalse", "shortanswer"):
                    continue
                if not q.get("question_text"):
                    continue
                q["marks"] = q.get("marks", request.marks_per_question)
                validated.append(GeneratedQuestion(**q))
            if not validated:
                raise HTTPException(status_code=500, detail="AI returned no valid questions after retry.")
            return QuizGenResponse(questions=validated, total=len(validated), llm_used=settings.llm_provider)
        except Exception as e2:
            logger.error(f"Retry also failed: {e2}")
            raise HTTPException(status_code=500, detail="LLM returned invalid JSON after retry.")
    except ValueError as e:
        logger.error(f"LLM returned unexpected structure: {e} — raw: {raw[:2000]}")
        raise HTTPException(status_code=500, detail=f"LLM returned unexpected structure: {e}")
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Quiz generation error: {e}")
        raise HTTPException(status_code=500, detail=str(e))


# ── Word Export ────────────────────────────────────────────

class WordExportRequest(BaseModel):
    # Permissive types: full validation happens in _validate_export_payload so we
    # can return custom error codes (e.g. INVALID_EXPORT_PAYLOAD) instead of
    # Pydantic's default 422 structure.
    questions:    Any
    export_type:  Any = "question_paper"
    version:      Any = "A"
    doc_settings: Any = Field(default_factory=dict)


class WordExportResponse(BaseModel):
    docx_base64: str
    filename: str
    total_marks: float
    question_count: int


def _validate_export_payload(request: WordExportRequest) -> None:
    """
    Validate and normalize the export payload at the API boundary.
    
    Raises HTTPException with 422 status if critical fields have wrong types.
    """
    # Validate questions is a list of dicts
    if not isinstance(request.questions, list):
        raise HTTPException(
            status_code=422,
            detail={
                "code": "INVALID_EXPORT_PAYLOAD",
                "field": "questions",
                "expected": "list",
                "received": type(request.questions).__name__
            }
        )

    # Reject empty questions list (no document can be generated without questions).
    if len(request.questions) == 0:
        raise HTTPException(
            status_code=422,
            detail={
                "code": "EMPTY_QUESTIONS",
                "field": "questions",
                "expected": "non-empty list",
                "received": "empty list"
            }
        )
    
    for i, q in enumerate(request.questions):
        if not isinstance(q, dict):
            raise HTTPException(
                status_code=422,
                detail={
                    "code": "INVALID_EXPORT_PAYLOAD",
                    "field": f"questions[{i}]",
                    "expected": "object",
                    "received": type(q).__name__,
                    "hint": "Each question must be a dictionary/object, not a list or string"
                }
            )
        # Validate options is a list if present
        opts = q.get("options")
        if opts is not None and not isinstance(opts, list):
            raise HTTPException(
                status_code=422,
                detail={
                    "code": "INVALID_EXPORT_PAYLOAD",
                    "field": f"questions[{i}].options",
                    "expected": "list",
                    "received": type(opts).__name__
                }
            )
    
    # Validate doc_settings is a dict
    if not isinstance(request.doc_settings, dict):
        raise HTTPException(
            status_code=422,
            detail={
                "code": "INVALID_EXPORT_PAYLOAD",
                "field": "doc_settings",
                "expected": "object",
                "received": type(request.doc_settings).__name__
            }
        )
    
    # Validate student_info_fields is a dict if present
    sif = request.doc_settings.get("student_info_fields")
    if sif is not None and not isinstance(sif, dict):
        logger.warning(
            "[EXPORT] student_info_fields has wrong type: expected=dict, actual=%s. Normalizing to default.",
            type(sif).__name__
        )
        # Normalize instead of rejecting - this is the PHP empty array issue
        request.doc_settings["student_info_fields"] = {"studentName": True, "studentId": True}
    
    # Validate versions is a list if present
    versions = request.doc_settings.get("versions")
    if versions is not None:
        if isinstance(versions, str):
            request.doc_settings["versions"] = [versions]
        elif not isinstance(versions, list):
            raise HTTPException(
                status_code=422,
                detail={
                    "code": "INVALID_EXPORT_PAYLOAD",
                    "field": "doc_settings.versions",
                    "expected": "list",
                    "received": type(versions).__name__
                }
            )
    
    # Validate export_type
    if request.export_type not in ("question_paper", "answer_key", "examiner_copy"):
        raise HTTPException(
            status_code=422,
            detail={
                "code": "INVALID_EXPORT_PAYLOAD",
                "field": "export_type",
                "expected": "question_paper | answer_key | examiner_copy",
                "received": request.export_type
            }
        )
    
    # Validate version
    if request.version not in ("A", "B", "C"):
        raise HTTPException(
            status_code=422,
            detail={
                "code": "INVALID_EXPORT_PAYLOAD",
                "field": "version",
                "expected": "A | B | C",
                "received": request.version
            }
        )


@router.post("/export-word", response_model=WordExportResponse)
async def export_word(
    request: WordExportRequest,
    _ = Depends(verify_token),
):
    # Validate payload at API boundary (raises 422 on type mismatches)
    _validate_export_payload(request)

    try:
        from core.export_word import generate_document
    except ImportError as e:
        raise HTTPException(status_code=500, detail=f"Word export module unavailable: {e}")

    try:
        doc_bytes = generate_document(
            questions=request.questions,
            export_type=request.export_type,
            doc_settings=request.doc_settings,
            version=request.version,
        )

        total_marks = sum(q.get("marks", 1.0) for q in request.questions)
        title = request.doc_settings.get("assessment_title", "Assessment")
        safe_title = "".join(c if c.isalnum() or c in " -_" else "" for c in title).strip() or "Assessment"
        version_suffix = f"_V{request.version}" if request.version != "A" else ""
        export_suffix = {
            "question_paper": "_Questions",
            "answer_key": "_AnswerKey",
            "examiner_copy": "_ExaminerCopy",
        }.get(request.export_type, "")
        filename = f"{safe_title}{version_suffix}{export_suffix}.docx"

        return WordExportResponse(
            docx_base64=base64.b64encode(doc_bytes).decode("ascii"),
            filename=filename,
            total_marks=total_marks,
            question_count=len(request.questions),
        )

    except Exception as e:
        logger.error(f"Word export error: {e}")
        raise HTTPException(status_code=500, detail=f"Document generation failed: {e}")


# ── Single Question Regeneration ────────────────────────────

class RegenerateSingleRequest(BaseModel):
    prompt:       str
    temperature:  float = 0.7
    max_chars:    int = 2000


@router.post("/regenerate-single")
async def regenerate_single(
    request: RegenerateSingleRequest,
    _ = Depends(verify_token),
):
    try:
        llm = get_llm()
        raw = await llm.generate_assessment(request.prompt, temperature=request.temperature, max_chars=request.max_chars)
        parsed = json.loads(raw.strip().strip('`').strip())
        if isinstance(parsed, dict) and 'question' in parsed:
            parsed = parsed['question']
        return {"question": parsed}
    except json.JSONDecodeError:
        raise HTTPException(status_code=500, detail="AI returned invalid JSON for regenerated question.")
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Regeneration failed: {e}")
