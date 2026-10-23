from __future__ import annotations

from urllib.parse import quote

import httpx

from app.config import Settings


class OpencastClient:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings

    def get_event(self, event_id: str) -> dict[str, object] | list[object]:
        settings = self._settings
        if not settings.opencast_host or not settings.opencast_user:
            raise RuntimeError("Opencast access is not configured")
        if not settings.opencast_password:
            raise RuntimeError("Opencast password is not configured")

        url = (
            f"{settings.opencast_host}/search/episode.json"
            f"?id={quote(event_id, safe='')}"
        )
        try:
            response = httpx.get(
                url,
                auth=httpx.DigestAuth(
                    settings.opencast_user,
                    settings.opencast_password,
                ),
                headers={
                    "X-Requested-Auth": "Digest",
                    "X-Opencast-Matterhorn-Authorization": "true",
                },
                timeout=15,
            )
            response.raise_for_status()
            result = response.json()
        except (httpx.HTTPError, ValueError) as exc:
            raise RuntimeError("Unable to retrieve Opencast event") from exc

        if not isinstance(result, (dict, list)):
            raise RuntimeError("Opencast returned an invalid event response")
        return result
