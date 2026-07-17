# ============================================================
# POST /api/v1/materials/analyze       — Analyze or return cached
# GET  /api/v1/materials/{id}/analyses  — List analyses for a material
# GET  /api/v1/analyses/{id}            — Get full analysis content
# POST /api/v1/materials/analyze/batch  — Batch analyze unanalyzed
# ============================================================

import json
import logging
import os
import uuid
from datetime import datetime
from pathlib import Path
from typing import List, Optional

import httpx
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session
from sqlalchemy import desc

from models.schemas import (
    AnalyzeRequest, AnalyzeResponse,
    AnalysisListItem, AnalysisListResponse,
    BatchAnalyzeRequest, BatchAnalyzeResponse, BatchAnalyzeItem,
    SyncAnalysisRequest,
)
from models.database import get_db, MaterialAnalysis
from middleware.auth import verify_token
from core.document_loader import DocumentLoader
from core.audio_processor import AudioProcessor
from core.llm_processor import _make_llm
from config import get_settings

logger = logging.getLogger(__name__)
router = APIRouter(tags=["analysis"])
settings = get_settings()
doc_loader = DocumentLoader()
audio_processor = AudioProcessor()

# ── Prompts ─────────────────────────────────────────────

FULL_ANALYSIS_PROMPT = """You are an academic assistant for the University of Mines and Technology (UMaT), Ghana.
Analyze the following document content and return a structured JSON analysis.

Document content:
{content}

Return valid JSON with EXACTLY these fields (no markdown, no code fences):
{{
  "title": "Inferred document title or subject",
  "summary": "3-4 sentence executive summary of the entire document",
  "main_topics": ["Topic 1 heading", "Topic 2 heading", ...],
  "key_concepts": ["Concept 1", "Concept 2", ...],
  "key_insights": ["Insight 1", "Insight 2", ...],
  "difficulty_level": "beginner" or "intermediate" or "advanced",
  "estimated_reading_time_minutes": integer,
  "target_audience": "Short description of who this material is for",
  "prerequisites": ["Prerequisite 1", ...] or empty list if none mentioned,
  "sections": [
    {{
      "heading": "Section heading or 'Introduction'",
      "summary": "1-2 sentence summary of this section",
      "key_points": ["Point 1", "Point 2", ...]
    }}
  ]
}}
"""

SUMMARY_PROMPT = """You are an academic assistant for the University of Mines and Technology (UMaT), Ghana.
Summarize the following document content concisely.

Document content:
{content}

Return valid JSON with EXACTLY these fields (no markdown, no code fences):
{{
  "title": "Inferred document title",
  "overview": "3-4 sentence overview",
  "key_takeaways": ["Takeaway 1", "Takeaway 2", "Takeaway 3", ...],
  "suggested_study_time_minutes": integer
}}
"""

KEY_CONCEPTS_PROMPT = """You are an academic assistant for the University of Mines and Technology (UMaT), Ghana.
Extract and explain the key concepts from the following document content.

Document content:
{content}

Return valid JSON with EXACTLY these fields (no markdown, no code fences):
{{
  "title": "Inferred document title",
  "concepts": [
    {{
      "term": "Concept name",
      "definition": "Clear definition",
      "importance": "Why this concept matters",
      "related_concepts": ["Related concept 1", "Related concept 2"]
    }}
  ],
  "total_concepts": integer
}}
"""

QUIZ_PROMPT = """You are an academic assistant for the University of Mines and Technology (UMaT), Ghana.
Generate practice questions based on the following document content.

Document content:
{content}

Return valid JSON with EXACTLY these fields (no markdown, no code fences):
{{
  "title": "Inferred document title",
  "total_questions": 5,
  "questions": [
    {{
      "id": 1,
      "type": "multiple_choice",
      "question": "Question text",
      "options": ["A) Option 1", "B) Option 2", "C) Option 3", "D) Option 4"],
      "correct_answer": "A) Option 1",
      "explanation": "Brief explanation"
    }}
  ]
}}
"""

CUSTOM_PROMPT = """You are an academic assistant for the University of Mines and Technology (UMaT), Ghana.
Analyze the following document content based on the user's request.

User request: {user_request}

Document content:
{content}

Return a structured JSON analysis addressing the user's request. Use these fields as a guide:
{{
  "title": "Inferred document title",
  "response": "Your detailed analysis addressing the user request",
  "key_findings": ["Finding 1", "Finding 2", ...],
  "confidence": "high" or "medium" or "low"
}}
"""


def _invoke_llm(prompt: str, temperature: float = 0.3, max_chars: int = 50000) -> str:
    llm = _make_llm(temperature)
    prompt = prompt[:max_chars]
    result = llm.invoke(prompt)
    return result.content.strip()


def _download_file(url: str, ext: str) -> str:
    """Download a file from URL to local upload dir. Returns local path."""
    filename = f"{uuid.uuid4()}{ext}"
    filepath = str(Path(settings.upload_dir) / filename)
    with httpx.stream("GET", url, timeout=300.0) as resp:
        resp.raise_for_status()
        with open(filepath, "wb") as f:
            for chunk in resp.iter_bytes(chunk_size=8192):
                f.write(chunk)
    return filepath


def _extract_text(file_path: str, filename: str, scope: Optional[str] = None) -> str:
    """Extract text from a downloaded file, optionally scoping to a portion."""
    ext = Path(filename).suffix.lower()
    text = doc_loader.load_file(file_path, ext_hint=ext)
    if not text or not text.strip():
        raise HTTPException(status_code=400, detail="File is empty or could not be parsed")

    if scope:
        text = _apply_scope(text, scope)
    return text


def _apply_scope(text: str, scope: str) -> str:
    """Extract a portion of text based on scope directive."""
    if scope.startswith("pages:"):
        # For scoped page extraction, return full text (the LLM can handle it)
        # We could implement page splitting for PDF here if needed
        return text
    elif scope.startswith("sections:"):
        section_name = scope.split(":", 1)[1].strip().lower()
        lines = text.split("\n")
        result = []
        in_section = False
        for line in lines:
            if line.lower().strip().startswith("#") or line.lower().strip().startswith(section_name):
                if in_section:
                    break
                if section_name in line.lower():
                    in_section = True
            if in_section:
                result.append(line)
        return "\n".join(result) if result else text
    elif scope.startswith("time:"):
        # For media transcript scoping, return full text
        return text
    return text


def _parse_json_response(raw: str) -> dict:
    """Parse JSON from LLM response, handling markdown code fences."""
    text = raw.strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[-1]
        text = text.rsplit("```", 1)[0]
    text = text.strip()
    if text.startswith("json"):
        text = text[4:].strip()
    return json.loads(text)


def _extract_and_count_tokens(content_text: str) -> int:
    """Rough token estimate (word count * 1.3)."""
    return int(len(content_text.split()) * 1.3)


# ── Endpoints ───────────────────────────────────────────


@router.post("/api/v1/materials/analyze", response_model=AnalyzeResponse)
async def analyze_material(
    req: AnalyzeRequest,
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    # 1. Check cache
    if not req.force:
        existing = db.query(MaterialAnalysis).filter(
            MaterialAnalysis.material_id == req.material_id,
            MaterialAnalysis.analysis_type == req.analysis_type.value,
            MaterialAnalysis.scope == (req.scope or "full"),
        ).first()
        if existing:
            return AnalyzeResponse(
                analysis_id=existing.id,
                cached=True,
                material_id=existing.material_id,
                course_id=existing.course_id,
                analysis_type=existing.analysis_type,
                scope=existing.scope,
                content=json.loads(existing.content),
                model_version=existing.model_version or "",
                token_count=existing.token_count or 0,
                created_at=existing.created_at.isoformat() if existing.created_at else "",
            )

    # 2. Download file
    ext = Path(req.filename).suffix.lower()
    file_path = _download_file(req.file_url, ext or ".bin")

    try:
        # 3. Extract text (transcribe media if needed)
        text = _extract_text(file_path, req.filename, req.scope)

        # 4. Select prompt
        analysis_type = req.analysis_type.value
        if analysis_type == "full_analysis":
            prompt = FULL_ANALYSIS_PROMPT.format(content=text[:40000])
        elif analysis_type == "summary":
            prompt = SUMMARY_PROMPT.format(content=text[:30000])
        elif analysis_type == "key_concepts":
            prompt = KEY_CONCEPTS_PROMPT.format(content=text[:40000])
        elif analysis_type == "quiz":
            prompt = QUIZ_PROMPT.format(content=text[:30000])
        elif analysis_type == "custom":
            prompt = CUSTOM_PROMPT.format(
                user_request=req.scope or "Analyze this document",
                content=text[:35000],
            )
        else:
            prompt = FULL_ANALYSIS_PROMPT.format(content=text[:40000])

        # 5. Run LLM
        raw = _invoke_llm(prompt, temperature=0.2)
        content_dict = _parse_json_response(raw)
        token_count = _extract_and_count_tokens(text)

        # 6. Save to DB
        now = datetime.utcnow()
        record = MaterialAnalysis(
            material_id=req.material_id,
            file_id=0,
            course_id=req.course_id,
            analysis_type=analysis_type,
            scope=req.scope or "full",
            content=json.dumps(content_dict, ensure_ascii=False),
            model_version=settings.llm_model,
            token_count=token_count,
            user_request=req.scope if analysis_type == "custom" else None,
            created_at=now,
            updated_at=now,
        )
        db.add(record)
        db.commit()
        db.refresh(record)

        # 7. Attempt to sync metadata back to Moodle (best-effort)
        _try_sync_to_moodle(req, record.id, content_dict.get("summary", ""))

        return AnalyzeResponse(
            analysis_id=record.id,
            cached=False,
            material_id=req.material_id,
            course_id=req.course_id,
            analysis_type=analysis_type,
            scope=req.scope or "full",
            content=content_dict,
            model_version=settings.llm_model,
            token_count=token_count,
            created_at=now.isoformat(),
        )

    finally:
        if os.path.exists(file_path):
            os.remove(file_path)


def _try_sync_to_moodle(req: AnalyzeRequest, ai_analysis_id: int, summary_text: str):
    """Best-effort callback to Moodle to mirror analysis metadata."""
    try:
        # Build Moodle web service URL from the file_url's origin
        # file_url is like http://moodle/pluginfile.php/... — extract origin
        from urllib.parse import urlparse
        parsed = urlparse(req.file_url)
        moodle_url = f"{parsed.scheme}://{parsed.netloc}/local/umat_ai/analysis_sync.php"
        payload = {
            "material_id": req.material_id,
            "courseid": req.course_id,
            "ai_analysis_id": ai_analysis_id,
            "analysis_type": req.analysis_type.value,
            "scope": req.scope or "full",
            "status": "completed",
            "model_version": settings.llm_model,
            "token_count": _extract_and_count_tokens(summary_text),
            "summary": summary_text[:500] if summary_text else "",
        }
        httpx.post(moodle_url, json=payload, timeout=10.0)
    except Exception as e:
        logger.warning(f"Failed to sync analysis to Moodle: {e}")


@router.get("/api/v1/materials/{material_id}/analyses", response_model=AnalysisListResponse)
async def list_analyses(
    material_id: int,
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    records = db.query(MaterialAnalysis).filter(
        MaterialAnalysis.material_id == material_id,
    ).order_by(desc(MaterialAnalysis.created_at)).all()

    return AnalysisListResponse(analyses=[
        AnalysisListItem(
            id=r.id,
            analysis_type=r.analysis_type,
            scope=r.scope,
            status="completed",
            model_version=r.model_version,
            token_count=r.token_count,
            created_at=r.created_at.isoformat() if r.created_at else "",
        )
        for r in records
    ])


@router.get("/api/v1/analyses/{analysis_id}")
async def get_analysis(
    analysis_id: int,
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    record = db.query(MaterialAnalysis).filter(MaterialAnalysis.id == analysis_id).first()
    if not record:
        raise HTTPException(status_code=404, detail="Analysis not found")

    return {
        "id": record.id,
        "material_id": record.material_id,
        "course_id": record.course_id,
        "analysis_type": record.analysis_type,
        "scope": record.scope,
        "content": json.loads(record.content) if record.content else {},
        "model_version": record.model_version,
        "token_count": record.token_count,
        "user_request": record.user_request,
        "created_at": record.created_at.isoformat() if record.created_at else None,
        "updated_at": record.updated_at.isoformat() if record.updated_at else None,
    }


@router.post("/api/v1/materials/analyze/batch", response_model=BatchAnalyzeResponse)
async def batch_analyze(
    req: BatchAnalyzeRequest,
    db: Session = Depends(get_db),
    token: str = Depends(verify_token),
):
    # For batch, we just mark them — actual analysis happens per-request
    # Return list of materials that need analysis
    items: List[BatchAnalyzeItem] = []

    if req.material_ids:
        material_ids = req.material_ids
    else:
        # Query all unanalyzed materials (no existing analysis record)
        existing = db.query(MaterialAnalysis.material_id).filter(
            MaterialAnalysis.course_id == req.course_id,
        ).distinct().all()
        analyzed_ids = {r[0] for r in existing}
        # We can't query umat_ai_materials from here (it's in Moodle DB)
        # So we return an empty batch — the caller should provide material_ids
        material_ids = []

    for mid in material_ids:
        exist = db.query(MaterialAnalysis).filter(
            MaterialAnalysis.material_id == mid,
        ).first()
        if exist:
            items.append(BatchAnalyzeItem(
                material_id=mid,
                filename="",
                status="cached",
                analysis_id=exist.id,
            ))
        else:
            items.append(BatchAnalyzeItem(
                material_id=mid,
                filename="",
                status="queued",
            ))

    return BatchAnalyzeResponse(total=len(items), items=items)
