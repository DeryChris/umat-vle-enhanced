# ============================================================
# Pydantic request/response models for all API endpoints
# ============================================================

from pydantic import BaseModel, Field
from typing import List, Optional
from enum import Enum


class OutputType(str, Enum):
    SUMMARY = "summary"
    NOTES   = "notes"
    QUIZ    = "quiz"
    QA      = "qa"


class ProcessRecordingRequest(BaseModel):
    session_id:    str       = Field(..., description="BBB meeting ID")
    recording_url: str       = Field(..., description="URL to BBB recording")
    course_id:     int       = Field(..., description="Moodle course ID")
    material_ids:  List[int] = Field(default=[], description="Indexed material IDs for context")


class ProcessRecordingResponse(BaseModel):
    job_id:  str
    status:  str
    message: str


class QueryRequest(BaseModel):
    question:  str = Field(..., min_length=3, max_length=1000)
    course_id: int
    user_id:   int
    role:      str = "student"  # "student" or "lecturer" — selects the prompt


class QueryResponse(BaseModel):
    answer:     str
    sources:    List[str] = []
    confidence: float = 0.0


class IndexMaterialRequest(BaseModel):
    material_id: int
    course_id:   int
    file_path:   str
    filename:    str


class IndexMaterialResponse(BaseModel):
    success:        bool
    chunks_indexed: int
    message:        str


class AIOutputResult(BaseModel):
    session_id:  str
    output_type: OutputType
    content:     str
    sources:     List[str] = []


class HealthResponse(BaseModel):
    status:        str
    version:       str
    whisper_model: str
    llm_model:     str


# ── Material Analysis ───────────────────────────────────

class AnalysisType(str, Enum):
    FULL_ANALYSIS = "full_analysis"
    SUMMARY       = "summary"
    KEY_CONCEPTS  = "key_concepts"
    QUIZ          = "quiz"
    CUSTOM        = "custom"


class AnalyzeRequest(BaseModel):
    material_id:   int          = Field(..., description="umat_ai_materials.id")
    course_id:     int          = Field(...)
    file_url:      str          = Field(..., description="pluginfile URL to fetch content")
    filename:      str          = Field(..., description="Original filename with extension")
    analysis_type: AnalysisType = Field(default=AnalysisType.FULL_ANALYSIS)
    force:         bool         = Field(default=False, description="Skip cache, force re-analysis")
    scope:         Optional[str] = Field(default=None, description="null=full, or 'pages:2-5', 'sections:...', 'time:1:30-5:00'")


class AnalyzeResponse(BaseModel):
    model_config = {"protected_namespaces": ()}
    analysis_id:   int
    cached:        bool
    material_id:   int
    file_id:       Optional[int] = None
    course_id:     int
    analysis_type: str
    scope:         str
    content:       dict
    model_version: str
    token_count:   int
    created_at:    str


class AnalysisListItem(BaseModel):
    model_config = {"protected_namespaces": ()}
    id:            int
    analysis_type: str
    scope:         str
    status:        str
    model_version: Optional[str]
    token_count:   Optional[int]
    created_at:    str


class AnalysisListResponse(BaseModel):
    analyses: List[AnalysisListItem]


class BatchAnalyzeRequest(BaseModel):
    course_id:    int           = Field(...)
    material_ids: Optional[List[int]] = Field(default=None, description="null=all unanalyzed in course")


class BatchAnalyzeItem(BaseModel):
    material_id: int
    filename:    str
    status:      str       # queued|cached|failed
    analysis_id: Optional[int] = None
    error:       Optional[str] = None


class BatchAnalyzeResponse(BaseModel):
    total:  int
    items:  List[BatchAnalyzeItem]


class SyncAnalysisRequest(BaseModel):
    """Internal: called by Moodle web service to mirror analysis metadata."""
    model_config = {"protected_namespaces": ()}
    material_id:   int
    fileid:        int
    courseid:      int
    ai_analysis_id: int
    analysis_type: str
    scope:         str
    status:        str
    model_version: Optional[str]
    token_count:   Optional[int]
    summary:       Optional[str]