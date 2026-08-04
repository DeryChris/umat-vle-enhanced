# ============================================================
# Application configuration loaded from .env via pydantic-settings
# ============================================================

import sys

from pydantic import ConfigDict, ValidationError
from pydantic_settings import BaseSettings
from functools import lru_cache


class Settings(BaseSettings):
    # Service
    ai_service_token: str
    ai_service_host: str = "0.0.0.0"
    ai_service_port: int = 8000

    # LLM provider: "gemini" or "openai" or "openrouter".
    # Each provider needs its own API key; only the selected one is required.
    llm_provider: str = "gemini"

    # LLM provider for lecturers: "openai" or "gemini".
    # When set, lecturer queries use this provider instead of llm_provider.
    llm_provider_lecturer: str = "openai"

    # Google Gemini (LLM + embeddings)
    google_api_key: str = ""
    llm_model: str = "gemini-2.5-flash"
    embedding_model: str = "gemini-embedding-001"

    # OpenAI / OpenRouter (used when llm_provider=openai)
    openai_api_key: str = ""
    openai_llm_model: str = "gpt-4o-mini"
    openai_embedding_model: str = "text-embedding-3-small"
    openai_base_url: str = ""  # Set to https://openrouter.ai/api/v1 for OpenRouter

    # OpenRouter (used when llm_provider=openrouter)
    # OpenRouter uses OpenAI-compatible API format — reuses ChatOpenAI with custom base_url.
    # Embeddings are routed through OpenRouter's own API at /api/v1/embeddings.
    openrouter_api_key: str = ""
    openrouter_model: str = "meta-llama/llama-4-maverick"
    openrouter_site_url: str = "https://umat.edu.gh"
    openrouter_site_name: str = "UMaT AI VLE"

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

    # OpenAI Whisper API (cloud) — set this for fast cloud transcription (~1-3s).
    # If empty, falls back to local Whisper on CPU (~10-30s).
    # Can use your existing OpenAI API key, or get one at https://platform.openai.com/api-keys
    openai_whisper_api_key: str = ""
    openai_whisper_base_url: str = ""  # Leave empty for default OpenAI endpoint

    # Cloud transcription model — use cheaper model by default to stay within budget.
    #   gpt-4o-mini-transcribe  = $0.0006/min (~278 hrs per $10)  ← DEFAULT (budget-friendly)
    #   gpt-4o-transcribe       = $0.006/min  (~28 hrs per $10)
    #   gpt-4o-transcribe-diarize = $0.012/min (~14 hrs per $10, includes speaker labels)
    #   whisper-1               = $0.006/min  (~28 hrs per $10)
    openai_whisper_model: str = "gpt-4o-mini-transcribe"

    # Speaker diarization — when true, uses gpt-4o-transcribe-diarize model
    # which labels segments by speaker (e.g. "Speaker A", "Speaker B").
    # WARNING: diarize model is 20x more expensive than mini!
    # Only applies when OPENAI_WHISPER_API_KEY is set.
    openai_whisper_diarize: bool = False

    # Monthly budget cap for cloud transcription (in USD). Set to 0 for unlimited.
    # When the running total exceeds this, cloud API falls back to local Whisper.
    openai_whisper_budget_usd: float = 10.0

    # Path to a local file that tracks accumulated cloud transcription cost.
    openai_whisper_cost_tracker_path: str = "./.whisper_cost.json"

    # Chunking
    max_chunk_size: int = 1000
    chunk_overlap: int = 200

    # LTI 1.3 (optional — set LTI_ENABLED=true to activate)
    lti_enabled: bool = False
    lti_client_id: str = ""
    lti_deployment_id: str = ""
    lti_platform_issuer: str = ""
    lti_auth_login_url: str = ""
    lti_auth_token_url: str = ""
    lti_key_set_url: str = ""
    lti_target_link_uri: str = "http://localhost:8000/lti/launch"
    lti_private_key_path: str = "./lti_keys/private.key"
    lti_key_id: str = "umat-ai-key-1"

    @property
    def effective_lecturer_provider(self) -> str:
        return self.llm_provider_lecturer or self.llm_provider

    @property
    def database_url(self) -> str:
        return (
            f"postgresql+psycopg2://{self.ai_db_user}:"
            f"{self.ai_db_password}@{self.ai_db_host}:"
            f"{self.ai_db_port}/{self.ai_db_name}"
        )

    model_config = ConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
    )


# Required vars have no default in Settings above; everything else is optional.
REQUIRED_VARS_HELP = {
    "ai_service_token": "Shared bearer token — must match the Moodle plugin setting (min 32 chars)",
    "ai_db_password":   "PostgreSQL password for the umat_ai_db database",
}


def _fail_startup(lines: list) -> None:
    print("=" * 60, file=sys.stderr)
    print("AI Service cannot start: .env configuration is incomplete.", file=sys.stderr)
    for line in lines:
        print(line, file=sys.stderr)
    print("\nCopy ai_service/.env.example to ai_service/.env and fill in the values.", file=sys.stderr)
    print("=" * 60, file=sys.stderr)
    sys.exit(1)


def _validate_provider(s: Settings) -> None:
    if s.llm_provider not in ("gemini", "openai", "openrouter"):
        _fail_startup([f"\nLLM_PROVIDER must be 'gemini', 'openai', or 'openrouter', got: {s.llm_provider!r}"])
    if s.llm_provider == "gemini" and not s.google_api_key:
        _fail_startup(["\nLLM_PROVIDER is 'gemini' but GOOGLE_API_KEY is not set.",
                       "Get a key at https://aistudio.google.com/apikey, or set LLM_PROVIDER=openai or openrouter."])
    if s.llm_provider == "openai" and not s.openai_api_key:
        _fail_startup(["\nLLM_PROVIDER is 'openai' but OPENAI_API_KEY is not set.",
                       "Get a key at https://platform.openai.com/api-keys, or set LLM_PROVIDER=gemini or openrouter."])
    if s.llm_provider == "openrouter" and not s.openrouter_api_key:
        _fail_startup(["\nLLM_PROVIDER is 'openrouter' but OPENROUTER_API_KEY is not set.",
                       "Get your OpenRouter API key at https://openrouter.ai/keys."])

    # Validate lecturer provider (defaults to openai, falls back to llm_provider).
    lecturer_provider = s.llm_provider_lecturer or s.llm_provider
    if lecturer_provider not in ("gemini", "openai", "openrouter"):
        _fail_startup([f"\nLLM_PROVIDER_LECTURER must be 'gemini', 'openai', or 'openrouter', got: {lecturer_provider!r}"])
    if lecturer_provider == "gemini" and not s.google_api_key:
        _fail_startup(["\nLLM_PROVIDER_LECTURER is 'gemini' but GOOGLE_API_KEY is not set.",
                       "Get a key at https://aistudio.google.com/apikey"])
    if lecturer_provider == "openai" and not s.openai_api_key:
        _fail_startup(["\nLLM_PROVIDER_LECTURER is 'openai' but OPENAI_API_KEY is not set.",
                       "Get a key at https://platform.openai.com/api-keys"])
    if lecturer_provider == "openrouter" and not s.openrouter_api_key:
        _fail_startup(["\nLLM_PROVIDER_LECTURER is 'openrouter' but OPENROUTER_API_KEY is not set.",
                       "Get your OpenRouter API key at https://openrouter.ai/keys."])


@lru_cache()
def get_settings() -> Settings:
    try:
        loaded = Settings()
        _validate_provider(loaded)
        return loaded
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