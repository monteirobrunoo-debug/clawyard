"""Pluggable embedding driver. Default: Voyage `voyage-3-large` (1024
dims, optimised for retrieval). Alternative: OpenAI
`text-embedding-3-small` (1536 dims).

Switching providers is NOT a runtime decision — the embedding
dimensionality is baked into the `chunks.embedding` column type. To
swap, `DROP TABLE chunks` + reset settings + re-ingest.
"""

from __future__ import annotations

from typing import Iterable, List

from .settings import settings


async def embed_documents(texts: List[str]) -> List[List[float]]:
    """Vectorise an iterable of chunk texts. Returns one vector per
    input, in the same order. Empty input returns []."""
    texts = [t for t in texts if t and t.strip()]
    if not texts:
        return []

    if settings.embedding_provider == "voyage":
        return await _embed_voyage(texts, input_type="document")
    if settings.embedding_provider == "openai":
        return await _embed_openai(texts)
    raise ValueError(f"unknown embedding provider: {settings.embedding_provider}")


async def embed_query(text: str) -> List[float]:
    if settings.embedding_provider == "voyage":
        v = await _embed_voyage([text], input_type="query")
        return v[0]
    if settings.embedding_provider == "openai":
        v = await _embed_openai([text])
        return v[0]
    raise ValueError(f"unknown embedding provider: {settings.embedding_provider}")


async def _embed_voyage(texts: List[str], *, input_type: str) -> List[List[float]]:
    # Voyage's Python SDK is sync; wrap in asyncio.to_thread to avoid
    # blocking the event loop on a long batch.
    import asyncio
    import voyageai

    if not settings.voyage_api_key:
        raise RuntimeError("HPH_VOYAGE_API_KEY is empty — set it in env")

    client = voyageai.Client(api_key=settings.voyage_api_key)

    def _call() -> List[List[float]]:
        result = client.embed(
            texts=texts, model=settings.embedding_model, input_type=input_type
        )
        return result.embeddings  # type: ignore[no-any-return]

    return await asyncio.to_thread(_call)


async def _embed_openai(texts: List[str]) -> List[List[float]]:
    import httpx

    if not settings.openai_api_key:
        raise RuntimeError("HPH_OPENAI_API_KEY is empty — set it in env")
    async with httpx.AsyncClient(timeout=30) as http:
        r = await http.post(
            "https://api.openai.com/v1/embeddings",
            headers={"Authorization": f"Bearer {settings.openai_api_key}"},
            json={"model": settings.embedding_model, "input": texts},
        )
        r.raise_for_status()
        data = r.json()
    return [d["embedding"] for d in data["data"]]
