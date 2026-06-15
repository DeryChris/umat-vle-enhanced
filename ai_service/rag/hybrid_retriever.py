# ============================================================
# Hybrid retriever — LlamaIndex dense retrieval + BM25 keyword fusion
# Exposed as a LangChain tool for agent orchestration
# ============================================================

import logging
from typing import List, Optional, Tuple

from rank_bm25 import BM25Okapi

from core.vector_store import VectorStoreManager, get_chroma_client, get_embedding_function
from config import get_settings

logger = logging.getLogger(__name__)
settings = get_settings()


def _tokenize(text: str) -> List[str]:
    return text.lower().split()


def _reciprocal_rank_fusion(
    ranked_lists: List[List[Tuple[str, dict]]],
    k: int = 60,
) -> List[Tuple[str, dict, float]]:
    """Merge multiple ranked result lists using Reciprocal Rank Fusion."""
    scores: dict = {}

    for result_list in ranked_lists:
        for rank, (doc, meta) in enumerate(result_list):
            key = (doc, meta.get("source", ""), str(meta.get("material_id", "")))
            scores[key] = scores.get(key, 0.0) + 1.0 / (k + rank + 1)

    fused = sorted(scores.items(), key=lambda x: x[1], reverse=True)
    output = []
    for (doc, source, mid), score in fused:
        output.append((doc, {"source": source, "material_id": mid, "rrf_score": score}, score))
    return output


class HybridRetriever:
    """
    Combines ChromaDB dense search (LlamaIndex-compatible vector store) with
    BM25 keyword retrieval, then fuses results for higher recall on course materials.
    """

    def __init__(self):
        self._vector = VectorStoreManager()
        self._bm25_cache: dict = {}

    def _load_corpus(self, course_id: int, material_ids: Optional[List[int]] = None):
        """Load all indexed chunks for a course to build/reuse BM25 index."""
        cache_key = (course_id, tuple(material_ids or []))
        if cache_key in self._bm25_cache:
            return self._bm25_cache[cache_key]

        client = get_chroma_client()
        coll_name = self._vector.get_collection_name(course_id)

        try:
            collection = client.get_collection(name=coll_name)
        except Exception:
            self._bm25_cache[cache_key] = ([], None, [])
            return self._bm25_cache[cache_key]

        where_filter = None
        if material_ids:
            where_filter = {"material_id": {"$in": [str(mid) for mid in material_ids]}}

        results = collection.get(
            where=where_filter,
            include=["documents", "metadatas"],
        )

        docs = results.get("documents") or []
        metas = results.get("metadatas") or []

        if not docs:
            self._bm25_cache[cache_key] = ([], None, [])
            return self._bm25_cache[cache_key]

        tokenized = [_tokenize(d) for d in docs]
        bm25 = BM25Okapi(tokenized)
        self._bm25_cache[cache_key] = (docs, bm25, metas)
        return self._bm25_cache[cache_key]

    def _bm25_search(
        self,
        course_id: int,
        query: str,
        n_results: int,
        material_ids: Optional[List[int]] = None,
    ) -> List[Tuple[str, dict]]:
        docs, bm25, metas = self._load_corpus(course_id, material_ids)
        if not docs or bm25 is None:
            return []

        scores = bm25.get_scores(_tokenize(query))
        ranked = sorted(enumerate(scores), key=lambda x: x[1], reverse=True)[:n_results]

        results = []
        for idx, score in ranked:
            if score <= 0:
                continue
            meta = metas[idx] if idx < len(metas) else {}
            meta = dict(meta) if meta else {}
            meta["bm25_score"] = float(score)
            results.append((docs[idx], meta))
        return results

    def _llamaindex_search(
        self,
        course_id: int,
        query: str,
        n_results: int,
        material_ids: Optional[List[int]] = None,
    ) -> List[Tuple[str, dict]]:
        """
        Optional LlamaIndex retriever over the shared ChromaDB collection.
        Falls back to VectorStoreManager if LlamaIndex is unavailable.
        """
        try:
            import chromadb
            from llama_index.core import VectorStoreIndex
            from llama_index.core.schema import TextNode
            from llama_index.vector_stores.chroma import ChromaVectorStore

            client = get_chroma_client()
            coll_name = self._vector.get_collection_name(course_id)
            chroma_collection = client.get_collection(name=coll_name)
            vector_store = ChromaVectorStore(chroma_collection=chroma_collection)

            embed_fn = get_embedding_function()

            class _EmbedAdapter:
                def __call__(self, texts: List[str]) -> List[List[float]]:
                    return embed_fn.embed_documents(texts)

            index = VectorStoreIndex.from_vector_store(
                vector_store,
                embed_model=_EmbedAdapter(),
            )
            retriever = index.as_retriever(similarity_top_k=n_results)
            nodes = retriever.retrieve(query)

            results = []
            for node in nodes:
                meta = dict(node.metadata or {})
                meta["llamaindex_score"] = float(node.score or 0.0)
                if material_ids:
                    mid = str(meta.get("material_id", ""))
                    if mid and int(mid) not in material_ids:
                        continue
                results.append((node.get_content(), meta))
            return results[:n_results]
        except Exception as e:
            logger.debug(f"LlamaIndex retriever unavailable, using dense search: {e}")
            return self._vector.similarity_search(
                course_id=course_id,
                query=query,
                n_results=n_results,
                material_ids=material_ids,
            )

    def search(
        self,
        course_id: int,
        query: str,
        n_results: int = 5,
        material_ids: Optional[List[int]] = None,
    ) -> List[Tuple[str, dict]]:
        """Hybrid search: fuse dense (LlamaIndex/Chroma), BM25 keyword, and legacy dense."""
        dense = self._llamaindex_search(course_id, query, n_results * 2, material_ids)
        keyword = self._bm25_search(course_id, query, n_results * 2, material_ids)
        legacy = self._vector.similarity_search(
            course_id, query, n_results * 2, material_ids
        )

        fused = _reciprocal_rank_fusion([dense, keyword, legacy])
        return [(doc, meta) for doc, meta, _ in fused[:n_results]]

    def as_langchain_tool(self, course_id: int, material_ids: Optional[List[int]] = None):
        """Return a LangChain @tool bound to a specific course namespace."""
        from langchain.tools import tool

        retriever = self

        @tool
        def search_course_material(query: str) -> str:
            """Searches UMAT course materials for specific topics, definitions, or lecture notes."""
            nodes = retriever.search(
                course_id=course_id,
                query=query,
                n_results=3,
                material_ids=material_ids,
            )
            if not nodes:
                return "No matching course materials found."
            return "\n\n---\n\n".join(doc for doc, _ in nodes)

        return search_course_material


_hybrid_retriever: Optional[HybridRetriever] = None


def get_hybrid_retriever() -> HybridRetriever:
    global _hybrid_retriever
    if _hybrid_retriever is None:
        _hybrid_retriever = HybridRetriever()
    return _hybrid_retriever
