"""Centralised settings — every knob the service exposes lives here.

We use pydantic-settings so the same module works in tests (env-vars
provided per-test) and in production (env-vars from systemd / docker).
"""

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_prefix="HPH_")

    # Postgres / pgvector connection. The role must already have the
    # `vector` extension created (`CREATE EXTENSION vector;`).
    pg_dsn: str = "postgresql://hphistory:hphistory@localhost:5432/hphistory"

    # Shared secret with the clawyard Laravel app. Must equal
    # config('services.hp_history.hmac_secret') on the other side.
    hmac_secret: str = ""
    # Allowed clock drift (seconds) for X-HP-Timestamp. Tighten only if
    # the two servers' clocks are well synchronised (NTP is mandatory
    # in any case).
    hmac_tolerance_seconds: int = 300

    # Embeddings. We default to Voyage's `voyage-3-large` (1024-dim) —
    # swap for `text-embedding-3-small` (1536-dim) on OpenAI by changing
    # the embedding driver. The two are NOT interchangeable in the
    # same DB — re-ingest if you switch.
    embedding_provider: str = "voyage"   # voyage | openai
    embedding_model: str = "voyage-3-large"
    embedding_dim: int = 1024
    voyage_api_key: str = ""
    openai_api_key: str = ""

    # Search defaults — overridable per-request.
    max_results_default: int = 5
    max_results_cap: int = 20

    # Trust proxy headers (DigitalOcean droplet behind Cloudflare).
    trust_forwarded: bool = True


settings = Settings()
