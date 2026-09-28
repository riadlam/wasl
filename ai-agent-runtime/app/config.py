from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    runtime_host: str = "0.0.0.0"
    runtime_port: int = 8090
    runtime_service_key: str = "change-me-shared-with-laravel"
    log_level: str = "INFO"

    llm_base_url: str = "https://fal.run/openrouter/router/openai/v1"
    llm_api_key: str = ""
    llm_model: str = "anthropic/claude-sonnet-4.5"
    translation_model: str = "google/gemini-2.5-flash"

    # Fal OpenRouter GPT embeddings (same FAL_KEY as chat)
    embedding_base_url: str = "https://fal.run/openrouter/router/openai/v1"
    embedding_api_key: str = ""
    embedding_model: str = "qwen/qwen3-embedding-8b"
    embedding_dims: int = 1536

    laravel_base_url: str = "http://127.0.0.1:8000"
    laravel_internal_key: str = "change-me-shared-with-laravel"

    supabase_db_url: str = ""
    supabase_schema: str = "public"

    otel_exporter_otlp_endpoint: str = ""
    otel_service_name: str = "ai-agent-runtime"
    max_tool_iterations: int = 8
    session_ttl_seconds: int = 86400

    caption_approver_max_rounds: int = 3
    metacognition_enabled: bool = True
    metacognition_max_retries: int = 1

    # Dedicated walkthrough log (agent inputs/outputs only — not uvicorn noise)
    agent_activity_log_enabled: bool = True
    agent_activity_log_path: str = "logs/agent-activity.log"
    # One-line companion log (no system prompts / long dumps)
    agent_activity_brief_log_path: str = "logs/agent-activity-brief.log"


@lru_cache
def get_settings() -> Settings:
    return Settings()
