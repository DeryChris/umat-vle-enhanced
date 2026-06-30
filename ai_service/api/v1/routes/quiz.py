import logging
from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel
from middleware.auth import verify_token
from core.llm_processor import LLMProcessor

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/quiz", tags=["quiz"])

class GradeTheoryRequest(BaseModel):
    question: str
    answer_hint: str = ""
    student_answer: str

class GradeTheoryResponse(BaseModel):
    correct: bool
    explanation: str
    score: int

@router.post("/grade", response_model=GradeTheoryResponse)
async def grade_theory(
    request: GradeTheoryRequest,
    _ = Depends(verify_token),
):
    try:
        llm = LLMProcessor()
        result = await llm.grade_theory_answer(
            question=request.question,
            answer_hint=request.answer_hint,
            student_answer=request.student_answer,
        )
        return GradeTheoryResponse(**result)
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Theory grading error: {e}")
        raise HTTPException(status_code=500, detail=str(e))
