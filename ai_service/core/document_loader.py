# ============================================================
# Loads PDFs and text files, splits them into chunks for ChromaDB
# ============================================================

from langchain.text_splitter import RecursiveCharacterTextSplitter
from pypdf import PdfReader
from pathlib import Path
from typing import List, Tuple
from config import get_settings
import uuid

settings = get_settings()


class DocumentLoader:

    def __init__(self):
        self.splitter = RecursiveCharacterTextSplitter(
            chunk_size=settings.max_chunk_size,
            chunk_overlap=settings.chunk_overlap,
            separators=["\n\n", "\n", ". ", " ", ""],
            length_function=len,
        )

    def load_pdf(self, file_path: str) -> str:
        """Extract text from PDF page by page."""
        reader     = PdfReader(file_path)
        text_parts = []

        for page_num, page in enumerate(reader.pages):
            text = page.extract_text()
            if text:
                text_parts.append(f"[Page {page_num + 1}]\n{text}")

        return "\n\n".join(text_parts)

    def load_text(self, file_path: str) -> str:
        with open(file_path, "r", encoding="utf-8", errors="ignore") as f:
            return f.read()

    def load_file(self, file_path: str) -> str:
        ext = Path(file_path).suffix.lower()
        if ext == ".pdf":
            return self.load_pdf(file_path)
        elif ext in [".txt", ".md"]:
            return self.load_text(file_path)
        else:
            raise ValueError(f"Unsupported file type: {ext}")

    def split_text(
        self,
        text:     str,
        metadata: dict,
    ) -> Tuple[List[str], List[dict], List[str]]:
        """
        Split text into chunks.
        Returns (texts, metadatas, ids).
        """
        chunks = self.splitter.split_text(text)
        texts, metadatas, ids = [], [], []

        for i, chunk in enumerate(chunks):
            texts.append(chunk)
            metadatas.append({
                **metadata,
                "chunk_index":  i,
                "total_chunks": len(chunks),
            })
            ids.append(f"{metadata.get('source', 'doc')}_{i}_{uuid.uuid4().hex[:8]}")

        return texts, metadatas, ids

    def process_transcript(
        self,
        transcript: str,
        session_id: str,
        course_id:  int,
    ) -> Tuple[List[str], List[dict], List[str]]:
        """Process a lecture transcript for indexing into ChromaDB."""
        metadata = {
            "source":      f"transcript_{session_id}",
            "source_type": "transcript",
            "session_id":  session_id,
            "course_id":   str(course_id),
        }
        return self.split_text(transcript, metadata)