# ============================================================
# ChromaDB manager — one collection per Moodle course
# NOTE: ChromaDB API changed in versions after 0.5.0.
# If you upgrade ChromaDB, verify the import path for Settings.
# ============================================================

import chromadb
from chromadb.config import Settings as ChromaSettings
from config import get_settings
from typing import List, Tuple
import requests

settings = get_settings()

_chroma_client       = None
_embedding_cache    = {}


def get_chroma_client():
    global _chroma_client
    if _chroma_client is None:
        _chroma_client = chromadb.PersistentClient(
            path=settings.chroma_db_path,
            settings=ChromaSettings(anonymized_telemetry=False),
        )
    return _chroma_client


def embed_texts(texts: List[str]) -> List[List[float]]:
    """Direct API call for embeddings — provider chosen by LLM_PROVIDER.
    OpenRouter does not support embeddings, so openrouter falls back to
    text-embedding-3-small via OpenAI's embedding API (requires OPENAI_API_KEY)."""
    if settings.llm_provider in ("openai", "openrouter"):
        return _embed_texts_openai(texts)
    return _embed_texts_gemini(texts)


def _embed_texts_openai(texts: List[str]) -> List[List[float]]:
    """Local sentence-transformers embeddings (no API key needed)."""
    import logging
    from langchain_community.embeddings import HuggingFaceEmbeddings
    logger = logging.getLogger(__name__)

    global _local_embedder
    if '_local_embedder' not in globals():
        _local_embedder = HuggingFaceEmbeddings(model_name="all-MiniLM-L6-v2")
        logger.info("Loaded local HuggingFace embedding model (all-MiniLM-L6-v2, 384-dim)")

    embeddings = []
    for i in range(0, len(texts), 100):
        batch = [t[:8000] for t in texts[i:i + 100]]
        try:
            batch_embeds = _local_embedder.embed_documents(batch)
            embeddings.extend(batch_embeds)
            logger.info(f"Embedded batch {i // 100}: {len(batch)} texts")
        except Exception as e:
            logger.error(f"Embedding error for batch {i // 100}: {str(e)}")
            embeddings.extend([[0.0] * 384] * len(batch))

    return embeddings


def _embed_texts_gemini(texts: List[str]) -> List[List[float]]:
    """Direct Google API call for embeddings."""
    import logging
    logger = logging.getLogger(__name__)

    # Use the correct embedding model
    url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent?key={settings.google_api_key}"

    embeddings = []

    for i, text in enumerate(texts):
        try:
            payload = {
                "content": {
                    "role": "user",
                    "parts": [{"text": text[:8000]}]  # Truncate to avoid token limits
                }
            }
            response = requests.post(url, json=payload, timeout=30)

            logger.info(f"Embedding chunk {i}: status={response.status_code}")

            if response.status_code == 200:
                result = response.json()
                embedding = result.get('embedding', {}).get('values', [])
                if embedding:
                    embeddings.append(embedding)
                    logger.info(f"Got embedding with {len(embedding)} dimensions")
                else:
                    logger.warning(f"No embedding in response: {result}")
                    embeddings.append([0.0] * 768)  # Fallback
            else:
                logger.error(f"Embedding failed: {response.status_code} - {response.text[:200]}")
                # Use fallback
                embeddings.append([0.0] * 768)

        except Exception as e:
            logger.error(f"Embedding error for chunk {i}: {str(e)}")
            embeddings.append([0.0] * 768)

    return embeddings


def get_embedding_function():
    # Return a wrapper that uses our direct API call
    class DirectEmbedder:
        def embed_documents(self, texts):
            return embed_texts(texts)

        def embed_query(self, text):
            return embed_texts([text])[0]

    return DirectEmbedder()


class VectorStoreManager:

    def get_collection_name(self, course_id: int) -> str:
        # Provider-scoped: different embedding models have different
        # dimensions, so each provider keeps its own collection per course.
        if settings.llm_provider == "openai":
            return f"course_{course_id}_local"
        return f"course_{course_id}"

    def _resolve_collection(self, course_id: int):
        """Get the collection, falling back to legacy name if needed."""
        client = get_chroma_client()
        name = self.get_collection_name(course_id)
        try:
            return client.get_collection(name=name)
        except Exception:
            pass
        # Fallback: try the legacy collection names
        for fallback in [f"course_{course_id}", f"course_{course_id}_openai"]:
            if fallback == name:
                continue
            try:
                return client.get_collection(name=fallback)
            except Exception:
                continue
        return client.get_collection(name=name)

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
        course_id:     int,
        query:         str,
        n_results:     int = 5,
        material_ids:  List[int] = None,
    ) -> List[Tuple[str, dict]]:
        """Return top-N relevant chunks for a query string.

        If material_ids is provided and non-empty, restrict search to only chunks
        belonging to those materials (using the material_id metadata field).
        """
        embedder = get_embedding_function()

        try:
            collection = self._resolve_collection(course_id)
        except Exception:
            return []  # No documents indexed for this course yet

        query_embedding = embedder.embed_query(query)

        where_filter = None
        if material_ids:
            where_filter = {"material_id": {"$in": [str(mid) for mid in material_ids]}}

        try:
            results = collection.query(
                query_embeddings=[query_embedding],
                n_results=n_results,
                where=where_filter,
                include=["documents", "metadatas", "distances"],
            )
        except Exception as e:
            import logging
            logging.getLogger(__name__).warning(f"Dense search failed (likely dimension mismatch): {e}")
            return []

        documents = results["documents"][0] if results["documents"] else []
        metadatas = results["metadatas"][0] if results["metadatas"] else []
        return list(zip(documents, metadatas))

    def get_documents_by_filter(
        self,
        course_id: int,
        where_filter: dict,
        limit: int = 50,
    ) -> List[Tuple[str, dict]]:
        """Retrieve documents from ChromaDB by metadata filter without similarity search.

        Uses collection.get() instead of collection.query() — no embedding needed.
        Useful for fetching all chunks belonging to a specific material_id.
        """
        try:
            collection = self._resolve_collection(course_id)
        except Exception:
            return []

        results = collection.get(
            where=where_filter,
            limit=limit,
            include=["documents", "metadatas"],
        )

        documents = results.get("documents", []) or []
        metadatas = results.get("metadatas", []) or []
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