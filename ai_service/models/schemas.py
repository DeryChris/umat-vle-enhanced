# ============================================================
# Pydantic request/response models for all API endpoints
# ============================================================

from pydantic import BaseModel, Field
from typing import List, Optional, Union
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
    title:         str       = Field(default="", description="Display title for the recording")
    # Re-transcription overrides (optional — only used by /recording/reprocess)
    transcription_provider: Optional[str] = Field(default=None, description="Override transcription provider")
    transcription_model:    Optional[str] = Field(default=None, description="Override transcription model")


class ProcessRecordingResponse(BaseModel):
    job_id:          str
    status:          str
    message:         str
    transcription_provider: Optional[str] = Field(default=None, description="Provider used for transcription")
    transcription_model:    Optional[str] = Field(default=None, description="Model used for transcription")


# ── Transcription Segment ────────────────────────────────

class TranscriptionSegment(BaseModel):
    start: float = Field(..., description="Start time in seconds")
    end:   float = Field(..., description="End time in seconds")
    text:  str   = Field(..., description="Transcribed text segment")


class QueryRequest(BaseModel):
    question:      str = Field(..., min_length=1, max_length=2000)
    course_id:     int
    user_id:       int
    role:          str = "student"  # "student" or "lecturer" — selects the prompt
    session_key:   str = ""  # For conversation continuity — groups messages into a thread
    material_ids:  List[int] = Field(default=[], description="If non-empty, restrict RAG search to these material IDs only")


class QuizQuestion(BaseModel):
    type:        str = "objective"  # "objective", "fill_in", "true_false", or "theoretical"
    question:    str
    options:     Optional[List[str]] = None
    correct:     Optional[Union[int, str]] = None
    explanation: Optional[str] = None
    answer_hint: Optional[str] = None


class QuizData(BaseModel):
    title:     str = "Practice Quiz"
    questions: List[QuizQuestion]


class QueryResponse(BaseModel):
    answer:     str
    sources:    List[str] = []
    confidence: float = 0.0
    quiz_data:  Optional[QuizData] = None


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
    status:                 str
    version:                str
    whisper_model:          str
    llm_model:              str
    transcription_provider: str
    transcription_model:    str


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


# ── Video Generation ────────────────────────────────────

class VideoGenerateRequest(BaseModel):
    material_id:  int    = Field(..., description="ID of the indexed material")
    course_id:    int    = Field(...)
    file_content: str    = Field(..., description="Base64-encoded file content")
    file_mime:    str    = Field("application/octet-stream", description="MIME type of the file")
    filename:     str    = Field(..., description="Original filename for display")


class VideoGenerateResponse(BaseModel):
    job_id:  str
    status:  str
    message: str


class VideoStatusResponse(BaseModel):
    job_id:         str
    status:         str
    progress:       int = 0
    status_message: str = ""
    video_url:      Optional[str] = None
    error:          Optional[str] = None
    created_at:     Optional[str] = None
    completed_at:   Optional[str] = None


# ── Transcription Cost Aggregation ──────────────────────────

class TranscriptionCostRequest(BaseModel):
    course_id:    int = Field(default=0, description="Course ID (0 = all courses)")
    months_back:  int = Field(default=12, description="Months of history to include")


class PerCourseCost(BaseModel):
    course_id:            int
    total_cost:           float = 0.0
    total_duration_secs:  float = 0.0
    recording_count:      int   = 0
    transcribed_count:    int   = 0
    provider_breakdown:   dict  = {}


class MonthlyCost(BaseModel):
    month:             str   = ""
    total_cost:        float = 0.0
    total_duration_secs: float = 0.0
    recording_count:   int   = 0


class ProviderCostSummary(BaseModel):
    provider:          str   = ""
    recording_count:   int   = 0
    total_cost:        float = 0.0
    total_duration_secs: float = 0.0


class TranscriptionCostResponse(BaseModel):
    total_cost:          float                = 0.0
    total_duration_secs: float                = 0.0
    total_recordings:    int                  = 0
    per_course:          list[PerCourseCost]  = []
    monthly_trend:       list[MonthlyCost]    = []
    provider_breakdown:  list[ProviderCostSummary] = []


# ── Re-process Recording ────────────────────────────────────

class ReprocessRecordingResponse(BaseModel):
    job_id:  str
    status:  str
    message: str
    transcription_provider: Optional[str] = None
    transcription_model:    Optional[str] = None

