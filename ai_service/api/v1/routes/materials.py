# ============================================================
# POST   /api/v1/materials/index       — upload + index a course file
# DELETE /api/v1/materials/{material_id} — remove from vector index
# ============================================================

from fastapi import APIRouter, Depends, UploadFile, File, Form, HTTPException
from sqlalchemy.orm import Session
from models.schemas import IndexMaterialResponse
from models.database import get_db, IndexedDocument
from middleware.auth import verify_token
from core.document_loader import DocumentLoader
from core.vector_store import VectorStoreManager
import os
import uuid
from pathlib import Path
from config import get_settings
from datetime import datetime

router       = APIRouter(prefix="/materials", tags=["materials"])
settings     = get_settings()
doc_loader   = DocumentLoader()
vector_store = VectorStoreManager()

ALLOWED_EXTENSIONS = {
    ".pdf", ".txt", ".md", ".markdown",
    ".doc", ".docx", ".ppt", ".pptx", ".xlsx", ".csv",
    ".py", ".js", ".ts", ".jsx", ".tsx", ".php", ".rb", ".go", ".rs",
    ".java", ".kt", ".swift", ".c", ".cpp", ".h", ".hpp", ".cs",
    ".sql", ".sh", ".bash", ".ps1", ".bat", ".pl", ".lua", ".r",
    ".html", ".htm", ".css", ".scss", ".less", ".json", ".xml",
    ".yaml", ".yml", ".toml", ".ini", ".cfg",
    ".mp3", ".wav", ".ogg", ".flac", ".m4a",
    ".mp4", ".webm", ".mov", ".avi", ".mkv",
}


@router.post("/index", response_model=IndexMaterialResponse)
async def index_material(
    course_id:   int        = Form(...),
    material_id: int        = Form(...),
    filename:    str        = Form(...),
    file:        UploadFile = File(...),
    db:          Session    = Depends(get_db),
    token:       str        = Depends(verify_token),
):
    file_ext = Path(filename).suffix.lower()

    if file_ext not in ALLOWED_EXTENSIONS:
        raise HTTPException(
            status_code=400,
            detail=f"File type {file_ext} not supported. Allowed: {ALLOWED_EXTENSIONS}",
        )

    temp_path = str(Path(settings.upload_dir) / f"{uuid.uuid4()}{file_ext}")

    try:
        content = await file.read()
        with open(temp_path, "wb") as f:
            f.write(content)

        text = doc_loader.load_file(temp_path)
        if not text.strip():
            raise HTTPException(status_code=400, detail="File is empty or could not be parsed")

        metadata = {
            "source":      filename,
            "source_type": "course_material",
            "material_id": str(material_id),
            "course_id":   str(course_id),
        }
        texts, metadatas, ids = doc_loader.split_text(text, metadata)
        chunk_count = vector_store.add_documents(course_id, texts, metadatas, ids)

        db.add(IndexedDocument(
            material_id     = material_id,
            course_id       = course_id,
            filename        = filename,
            chunk_count     = chunk_count,
            collection_name = vector_store.get_collection_name(course_id),
            indexed_at      = datetime.utcnow(),
        ))
        db.commit()

        return IndexMaterialResponse(
            success        = True,
            chunks_indexed = chunk_count,
            message        = f"Successfully indexed {chunk_count} chunks from {filename}",
        )

    finally:
        if os.path.exists(temp_path):
            os.remove(temp_path)


@router.get("/{material_id}/text")
async def get_material_text(
    material_id: int,
    course_id:   int,
    token:       str = Depends(verify_token),
):
    """Return the indexed text content for a material from ChromaDB."""
    try:
        vs = VectorStoreManager()
        results = vs.get_documents_by_filter(
            course_id=course_id,
            where_filter={"material_id": str(material_id)},
            limit=50,
        )
        if results:
            chunks = [doc for doc, _ in results]
            text = "\n\n".join(chunks)
            return {"success": True, "text": text, "chunks": len(chunks)}
        return {"success": False, "text": "", "chunks": 0, "message": "No indexed content found"}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


@router.delete("/{material_id}")
async def remove_material_from_index(
    material_id: int,
    course_id:   int,
    filename:    str,
    db:          Session = Depends(get_db),
    token:       str     = Depends(verify_token),
):
    vector_store.delete_course_documents(course_id, source_filter=filename)

    db.query(IndexedDocument).filter(
        IndexedDocument.material_id == material_id,
        IndexedDocument.course_id   == course_id,
    ).delete()
    db.commit()

    return {"success": True, "message": "Material removed from index"}