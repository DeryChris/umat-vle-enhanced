# ============================================================
# ChromaDB manager — one collection per Moodle course
# NOTE: ChromaDB API changed in versions after 0.5.0.
# If you upgrade ChromaDB, verify the import path for Settings.
# ============================================================

import logging
import chromadb
from chromadb.config import Settings as ChromaSettings
from chromadb.errors import InvalidDimensionException
from config import get_settings
from typing import List, Tuple
import requests

logger = logging.getLogger(__name__)

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
    OpenRouter routes through its own API (openrouter.ai/api/v1/embeddings)
    using the OPENROUTER_API_KEY."""
    if settings.llm_provider == "openrouter":
        return _embed_texts_openrouter(texts)
    if settings.llm_provider == "openai":
        return _embed_texts_openai(texts)
    return _embed_texts_gemini(texts)


def _embed_texts_openrouter(texts: List[str]) -> List[List[float]]:
    """Embed texts via OpenRouter's OpenAI-compatible embeddings API."""
    embeddings = []
    for i in range(0, len(texts), 100):
        batch = [t[:8000] for t in texts[i:i + 100]]
        try:
            response = requests.post(
                "https://openrouter.ai/api/v1/embeddings",
                headers={
                    "Authorization": f"Bearer {settings.openrouter_api_key}",
                    "Content-Type": "application/json",
                    "HTTP-Referer": settings.openrouter_site_url,
                    "X-Title": settings.openrouter_site_name,
                },
                json={
                    "model": "openai/text-embedding-3-small",
                    "input": batch,
                    "dimensions": 1536,
                },
                timeout=30,
            )
            logger.info(f"Embedding batch {i // 100}: status={response.status_code}")
            if response.status_code == 200:
                data = response.json().get("data", [])
                embeddings.extend(item["embedding"] for item in data)
            else:
                logger.error(f"Embedding failed: {response.status_code} - {response.text[:200]}")
                embeddings.extend([[0.0] * 1536] * len(batch))
        except Exception as e:
            logger.error(f"Embedding error for batch {i // 100}: {str(e)}")
            embeddings.extend([[0.0] * 1536] * len(batch))

    return embeddings


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
        if settings.llm_provider == "openrouter":
            return f"course_{course_id}_openrouter"
        return f"course_{course_id}"

    def _resolve_collection(self, course_id: int, prefer_generic: bool = True):
        """Get the collection, falling back to legacy name if needed.

        When *prefer_generic* is True (default for reads), the bare-named
        collection ``course_{course_id}`` is tried first.  This ensures that
        course material which was originally indexed under a previous provider
        (Gemini) is still found even after switching to OpenRouter or OpenAI.
        The provider-scoped name is used as a fallback so that new provider-
        specific indexes are also discoverable once they exist.
        """
        client = get_chroma_client()
        name = self.get_collection_name(course_id)

        # ── Try order: generic → provider-scoped → legacy names ──
        probes = [f"course_{course_id}"]
        if prefer_generic:
            probes.append(name)
        else:
            probes.insert(0, name)
        probes += [f"course_{course_id}_openai"]

        seen = set()
        for candidate in probes:
            if candidate in seen:
                continue
            seen.add(candidate)
            try:
                return client.get_collection(name=candidate)
            except Exception:
                continue

        # Nothing found — create the provider-scoped collection so the
        # caller gets an empty (but usable) collection rather than an error.
        return client.create_collection(
            name=name,
            metadata={"course_id": course_id},
        )

    def _add_batches(
        self,
        collection: object,
        texts:      List[str],
        embeddings: List[List[float]],
        metadatas:  List[dict],
        ids:        List[str],
        batch_size: int = 100,
    ) -> None:
        """Internal: add documents/embeddings to a collection in batches."""
        for i in range(0, len(texts), batch_size):
            collection.add(
                documents=texts[i:i + batch_size],
                embeddings=embeddings[i:i + batch_size],
                metadatas=metadatas[i:i + batch_size],
                ids=ids[i:i + batch_size],
            )

    def add_documents(
        self,
        course_id:  int,
        texts:      List[str],
        metadatas:  List[dict],
        ids:        List[str],
    ) -> int:
        """Embed and store text chunks in the course's ChromaDB collection.

        Auto-heals dimension mismatches: if the underlying embedder now outputs
        a different vector size (e.g. after switching to a different provider or
        embedding model), the old collection is automatically recreated with the
        correct dimensionality.
        """
        client    = get_chroma_client()
        embedder  = get_embedding_function()
        coll_name = self.get_collection_name(course_id)

        collection = client.get_or_create_collection(
            name=coll_name,
            metadata={"course_id": course_id},
        )

        embeddings = embedder.embed_documents(texts)

        try:
            self._add_batches(collection, texts, embeddings, metadatas, ids)
        except InvalidDimensionException:
            logger.warning(
                "Dimension mismatch in collection '%s' — "
                "recreating with new embedding dimension", coll_name,
            )
            client.delete_collection(coll_name)
            collection = client.create_collection(
                name=coll_name,
                metadata={"course_id": course_id},
            )
            self._add_batches(collection, texts, embeddings, metadatas, ids)

        return len(texts)

    def similarity_search(
        self,
        course_id:     int,
        query:         str,
        n_results:     int = 5,
        material_ids:  List[int] = None,
        role:          str = "student",
    ) -> List[Tuple[str, dict]]:
        """Return top-N relevant chunks for a query string.

        Privacy Layer 2: when role is 'student', chunks whose metadata
        carry ``visibility`` = ``"lecturer"`` or ``"admin"`` are excluded
        from the results.  Chunks without a ``visibility`` field (legacy
        data) are treated as ``"student"`` and therefore visible.

        If material_ids is provided and non-empty, restrict search to only chunks
        belonging to those materials (using the material_id metadata field).

        Returns an empty list if:
        - No documents have been indexed for this course yet, OR
        - The collection's embedding dimension differs from the current embedder
          (e.g. after switching providers).  The dimension will be healed on the
          next call to add_documents().
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

        # Request extra results so we have enough after visibility filtering.
        fetch_n = n_results * 3 if role not in ("lecturer", "admin") else n_results

        try:
            results = collection.query(
                query_embeddings=[query_embedding],
                n_results=fetch_n,
                where=where_filter,
                include=["documents", "metadatas", "distances"],
            )
        except InvalidDimensionException:
            logger.info(
                "Dimension mismatch for collection '%s' — "
                "returning empty results (will heal on next add)",
                self.get_collection_name(course_id),
            )
            return []
        except Exception as e:
            logger.warning(f"Dense search failed: {e}")
            return []

        documents = results["documents"][0] if results["documents"] else []
        metadatas = results["metadatas"][0] if results["metadatas"] else []

        # --- Privacy Layer 2: visibility post-filter --------------------
        if role not in ("lecturer", "admin"):
            paired = [
                (doc, meta)
                for doc, meta in zip(documents, metadatas)
                if meta.get("visibility", "student") not in ("lecturer", "admin")
            ]
            documents = [doc for doc, _ in paired[:n_results]]
            metadatas = [meta for _, meta in paired[:n_results]]

        return list(zip(documents, metadatas))

    def get_documents_by_filter(
        self,
        course_id: int,
        where_filter: dict,
        limit: int = 50,
    ) -> List[Tuple[str, dict]]:
        """Retrieve documents from ChromaDB by metadata filter without similarity search.

        Uses collection.get() instead of collection.query() - no embedding needed.
        Useful for fetching all chunks belonging to a specific material_id.

        Cross-collection fallback: after a provider switch (e.g. OpenRouter ->
        Gemini) materials may live in a different collection with a different
        embedding dimension. Because this method never embeds anything, it is
        safe to search EVERY candidate collection (generic + provider-scoped +
        legacy names) until matching documents are found, so historical index
        data remains usable for flashcards / material-text retrieval.
        """
        client = get_chroma_client()
        name = self.get_collection_name(course_id)

        # Try order: generic -> provider-scoped -> legacy names.
        candidates = [f"course_{course_id}", name]
        candidates += [f"course_{course_id}_openrouter", f"course_{course_id}_openai", f"course_{course_id}_local"]

        seen = set()
        for candidate in candidates:
            if candidate in seen:
                continue
            seen.add(candidate)
            try:
                collection = client.get_collection(name=candidate)
            except Exception:
                continue
            try:
                results = collection.get(
                    where=where_filter,
                    limit=limit,
                    include=["documents", "metadatas"],
                )
            except Exception as e:
                logger.warning(f"get_documents_by_filter failed on '{candidate}': {e}")
                continue

            documents = results.get("documents", []) or []
            metadatas = results.get("metadatas", []) or []
            if documents:
                logger.info(
                    "get_documents_by_filter: %d doc(s) from collection '%s' (course %s)",
                    len(documents), candidate, course_id,
                )
                return list(zip(documents, metadatas))

        return []

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