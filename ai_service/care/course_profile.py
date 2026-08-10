import logging
from typing import List, Optional

from core.vector_store import VectorStoreManager

logger = logging.getLogger(__name__)


class CourseProfile:
    def __init__(
        self,
        course_id: int,
        course_title: str = "",
        topics: List[str] = None,
        keywords: List[str] = None,
        chunk_count: int = 0,
    ):
        self.course_id = course_id
        self.course_title = course_title
        self.topics = topics or []
        self.keywords = keywords or []
        self.chunk_count = chunk_count

    @property
    def has_materials(self) -> bool:
        return self.chunk_count > 0

    def summary(self, max_topics: int = 10) -> str:
        parts = []
        if self.course_title:
            parts.append(f"Course: {self.course_title}")
        if self.topics:
            shown = self.topics[:max_topics]
            parts.append(f"Topics: {'; '.join(shown)}")
        if self.keywords:
            shown = self.keywords[:max_topics]
            parts.append(f"Keywords: {'; '.join(shown)}")
        return " | ".join(parts) if parts else "No course profile available"


class CourseProfileBuilder:
    def __init__(self):
        self._vector = VectorStoreManager()
        self._cache: dict = {}

    def build(self, course_id: int) -> CourseProfile:
        if course_id in self._cache:
            return self._cache[course_id]

        try:
            paired = self._vector.get_all_documents(course_id)
            docs = [doc for doc, _ in paired]
            metas = [meta for _, meta in paired]
        except Exception as e:
            logger.debug(f"Cannot build course profile for {course_id}: {e}")
            profile = CourseProfile(course_id=course_id)
            self._cache[course_id] = profile
            return profile

        chunk_count = len(docs)
        filenames = set()
        topic_texts = []
        for meta in metas:
            src = meta.get("source", "") if isinstance(meta, dict) else ""
            if src:
                filenames.add(src)
        for doc in docs[:20]:
            words = doc.split()[:50]
            topic_texts.append(" ".join(words))

        all_text = " ".join(topic_texts)
        words = [w.lower().strip(".,;:!?()[]{}") for w in all_text.split() if len(w) > 3]
        freq = {}
        for w in words:
            freq[w] = freq.get(w, 0) + 1
        sorted_words = sorted(freq.items(), key=lambda x: -x[1])
        keywords = [w for w, c in sorted_words[:30] if c > 1]

        course_title = self._guess_title(filenames)

        profile = CourseProfile(
            course_id=course_id,
            course_title=course_title,
            topics=list(filenames)[:10],
            keywords=keywords,
            chunk_count=chunk_count,
        )
        self._cache[course_id] = profile
        return profile

    def _guess_title(self, filenames: set) -> str:
        cleaned = []
        for fn in filenames:
            name = fn.replace(".pdf", "").replace(".docx", "").replace(".txt", "")
            name = name.replace("_", " ").replace("-", " ").strip()
            if name and len(name) > 5:
                cleaned.append(name)
        return cleaned[0] if cleaned else ""

    def clear_cache(self, course_id: int = None):
        if course_id:
            self._cache.pop(course_id, None)
        else:
            self._cache.clear()
