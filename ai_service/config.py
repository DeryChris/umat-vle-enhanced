# ============================================================
# Application configuration loaded from .env via pydantic-settings
# ============================================================

import sys

from pydantic import ValidationError
from pydantic_settings import BaseSettings
from functools import lru_cache


class Settings(BaseSettings):
    # Service
    ai_service_token: str
    ai_service_host: str = "0.0.0.0"
    ai_service_port: int = 8000

    # Google Gemini (LLM + embeddings)
    google_api_key: str
    llm_model: str = "gemini-2.0-flash"
    embedding_model: str = "text-embedding-004"

    # Database
    ai_db_host: str = "localhost"
    ai_db_port: int = 5432
    ai_db_name: str = "umat_ai_db"
    ai_db_user: str = "postgres"
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


# Required vars have no default in Settings above; everything else is optional.
REQUIRED_VARS_HELP = {
    "ai_service_token": "Shared bearer token — must match the Moodle plugin setting (min 32 chars)",
    "google_api_key":   "Google Gemini API key from https://aistudio.google.com/apikey",
    "ai_db_password":   "PostgreSQL password for the umat_ai_db database",
}


@lru_cache()
def get_settings() -> Settings:
    try:
        return Settings()
    except ValidationError as e:
        missing = [str(err["loc"][0]) for err in e.errors() if err["type"] == "missing"]
        invalid = [str(err["loc"][0]) for err in e.errors() if err["type"] != "missing"]
        print("=" * 60, file=sys.stderr)
        print("AI Service cannot start: .env configuration is incomplete.", file=sys.stderr)
        if missing:
            print("\nMissing required variables:", file=sys.stderr)
            for name in missing:
                hint = REQUIRED_VARS_HELP.get(name, "")
                print(f"  - {name.upper()}" + (f"  ({hint})" if hint else ""), file=sys.stderr)
        if invalid:
            print("\nVariables with invalid values:", file=sys.stderr)
            for name in invalid:
                print(f"  - {name.upper()}", file=sys.stderr)
        print("\nCopy ai_service/.env.example to ai_service/.env and fill in the values.", file=sys.stderr)
        print("=" * 60, file=sys.stderr)
        sys.exit(1)