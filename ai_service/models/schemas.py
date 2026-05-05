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