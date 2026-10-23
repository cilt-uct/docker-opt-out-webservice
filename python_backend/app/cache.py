from __future__ import annotations

import hashlib
import json
from typing import Any


class ResponseCache:
    def __init__(self, client: Any, ttl_seconds: int) -> None:
        self._client = client
        self._ttl_seconds = ttl_seconds

    def get_json(self, key: str) -> Any | None:
        value = self._client.get(key)
        return json.loads(value) if value is not None else None

    def set_json(self, key: str, value: Any) -> None:
        self._client.setex(
            key,
            self._ttl_seconds,
            json.dumps(value, default=str),
        )

    def invalidate(self, key: str) -> None:
        self._client.delete(key)

    @staticmethod
    def digest_key(prefix: str, value: str) -> str:
        digest = hashlib.sha256(value.encode("utf-8")).hexdigest()
        return f"optout:{prefix}:{digest}"
