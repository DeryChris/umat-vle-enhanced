# ============================================================
# ChromaDB manager — one collection per Moodle course
# NOTE: ChromaDB API changed in versions after 0.5.0.
# If you upgrade ChromaDB, verify the import path for Settings.
# ============================================================

import chromadb
from chromadb.config import Settings as ChromaSettings
from langchain_openai import OpenAIEmbeddings
from config import get_settings
from typing import List, Tuple

settings = get_settings()

_chroma_client       = None
_embedding_function  = None


def get_chroma_client():
    global _chroma_client
    if _chroma_client is None:
        _chroma_client = chromadb.PersistentClient(
            path=settings.chroma_db_path,
            settings=ChromaSettings(anonymized_telemetry=False),
        )
    return _chroma_client


def get_embedding_function():
    global _embedding_function
    if _embedding_function is None:
        _embedding_function = OpenAIEmbeddings(
            model=settings.embedding_model,
            openai_api_key=settings.openai_api_key,
        )
    return _embedding_function


class VectorStoreManager:

    def get_collection_name(self, course_id: int) -> str:
        return f"course_{course_id}"

    def add_documents(
        self,
        course_id:  int,
        texts:      List[str],
        metadatas:  List[dict],
        ids:        List[str],
    ) -> int:
        """Embed and store text chunks in the course's ChromaDB collection."""
        client    = get_chroma_client()
        embedder  = get_embedding_function()
        coll_name = self.get_collection_name(course_id)

        collection = client.get_or_create_collection(
            name=coll_name,
            metadata={"course_id": course_id},
        )

        embeddings = embedder.embed_documents(texts)

        # Add in batches of 100
        batch_size = 100
        for i in range(0, len(texts), batch_size):
            collection.add(
                documents=texts[i:i + batch_size],
                embeddings=embeddings[i:i + batch_size],
                metadatas=metadatas[i:i + batch_size],
                ids=ids[i:i + batch_size],
            )

        return len(texts)

    def similarity_search(
        self,
        course_id: int,
        query:     str,
        n_results: int = 5,
    ) -> List[Tuple[str, dict]]:
        """Return top-N relevant chunks for a query string."""
        client   = get_chroma_client()
        embedder = get_embedding_function()
        coll_name = self.get_collection_name(course_id)

        try:
            collection = client.get_collection(name=coll_name)
        except Exception:
            return []  # No documents indexed for this course yet

        query_embedding = embedder.embed_query(query)
        results = collection.query(
            query_embeddings=[query_embedding],
            n_results=n_results,
            include=["documents", "metadatas", "distances"],
        )

        documents = results["documents"][0] if results["documents"] else []
        metadatas = results["metadatas"][0] if results["metadatas"] else []
        return list(zip(documents, metadatas))

    def delete_course_documents(self, course_id: int, source_filter: str = None):
        """Remove indexed documents for a course (or specific source file)."""
        client    = get_chroma_client()
        coll_name = self.get_collection_name(course_id)

        try:
            collection = client.get_collection(name=coll_name)
            if source_filter:
                collection.delete(where={"source": source_filter})
            else:
                client.delete_collection(name=coll_name)
        except Exception:
            pass