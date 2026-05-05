# ============================================================
# Application configuration loaded from .env via pydantic-settings
# ============================================================

from pydantic_settings import BaseSettings
from functools import lru_cache


class Settings(BaseSettings):
    # Service
    ai_service_token: str
    ai_service_host: str = "0.0.0.0"
    ai_service_port: int = 8000

    # OpenAI
    openai_api_key: str
    llm_model: str = "gpt-4o-mini"
    embedding_model: str = "text-embedding-3-small"

    # Database
    ai_db_host: str = "localhost"
    ai_db_port: int = 5432
    ai_db_name: str = "umat_ai_db"
    ai_db_user: str = "aiserviceuser"
    ai_db_password: str

    # ChromaDB
    chroma_db_path: str = "./chroma_db"

    # Files
    upload_dir: str = "./uploads"
    max_file_size_mb: int = 500

    # Whisper
    whisper_model: str = "base"

    # Chunking
    max_chunk_size: int = 1000
    chunk_overlap: int = 200

    @property
    def database_url(self) -> str:
        return (
            f"postgresql+psycopg2://{self.ai_db_user}:"
            f"{self.ai_db_password}@{self.ai_db_host}:"
            f"{self.ai_db_port}/{self.ai_db_name}"
        )

    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"


@lru_cache()
def get_settings() -> Settings:
    return Settings()