# ============================================================
# Course material ingestion — LlamaIndex indexing over shared ChromaDB
# Wraps existing document_loader + VectorStoreManager
# ============================================================

import logging
import os
from typing import List, Optional

from core.document_loader import DocumentLoader
from core.vector_store import VectorStoreManager
from config import get_settings

logger = logging.getLogger(__name__)
settings = get_settings()


def ingest_material(
    material_id: int,
    course_id: int,
    file_path: str,
    filename: str,
) -> int:
    """
    Parse a Moodle material file, chunk it, and index into the course's
    ChromaDB collection (shared with HybridRetriever / LlamaIndex).
    Returns the number of chunks indexed.
    """
    if not os.path.isfile(file_path):
        raise FileNotFoundError(f"Material file not found: {file_path}")

    loader = DocumentLoader()
    text = loader.load_file(file_path)
    if not text.strip():
        logger.warning(f"No text extracted from {filename}")
        return 0

    metadata = {
        "source": filename,
        "source_type": "course_material",
        "material_id": str(material_id),
        "course_id": str(course_id),
    }
    texts, metadatas, ids = loader.split_text(text, metadata)
    if not texts:
        logger.warning(f"No chunks extracted from {filename}")
        return 0

    vector_store = VectorStoreManager()
    count = vector_store.add_documents(course_id, texts, metadatas, ids)

    # Optionally warm LlamaIndex index (lazy — retriever builds on first query)
    try:
        _warm_llamaindex_index(course_id)
    except Exception as e:
        logger.debug(f"LlamaIndex warm-up skipped: {e}")

    logger.info(f"Ingested {count} chunks for material {material_id} in course {course_id}")
    return count


def _warm_llamaindex_index(course_id: int) -> None:
    """Touch the LlamaIndex vector store so the index is ready for hybrid retrieval."""
    from core.vector_store import get_chroma_client
    from llama_index.core import VectorStoreIndex
    from llama_index.vector_stores.chroma import ChromaVectorStore
    from core.vector_store import get_embedding_function

    client = get_chroma_client()
    coll_name = VectorStoreManager().get_collection_name(course_id)
    chroma_collection = client.get_collection(name=coll_name)
    vector_store = ChromaVectorStore(chroma_collection=chroma_collection)

    embed_fn = get_embedding_function()

    class _EmbedAdapter:
        def __call__(self, texts: List[str]) -> List[List[float]]:
            return embed_fn.embed_documents(texts)

    VectorStoreIndex.from_vector_store(vector_store, embed_model=_EmbedAdapter())


def remove_material(material_id: int, course_id: int, filename: str) -> None:
    """Remove all indexed chunks for a material from the course collection."""
    vector_store = VectorStoreManager()
    vector_store.delete_course_documents(course_id, source_filter=filename)
