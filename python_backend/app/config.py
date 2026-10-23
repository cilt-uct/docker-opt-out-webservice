from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import quote_plus


def _read_secret(name: str) -> str:
    file_path = os.getenv(f"{name}_FILE")
    if file_path:
        try:
            return Path(file_path).read_text(encoding="utf-8").strip()
        except OSError as exc:
            message = f"Unable to read configured secret file for {name}"
            raise RuntimeError(message) from exc
    return os.getenv(name, "")


@dataclass(frozen=True, slots=True)
class Settings:
    database_url: str
    redis_url: str
    redis_cache_ttl: int
    session_secret: str
    cookie_secure: bool
    scheduler_interval_seconds: int
    scheduler_enabled: bool
    ldap_host: str
    ldap_port: int
    ldap_user: str
    ldap_password: str
    ldap_base_dn: str
    ldap_filter: str
    d2l_host: str
    d2l_user: str
    d2l_password: str
    vula_host: str
    vula_user: str
    vula_password: str
    opencast_host: str
    opencast_user: str
    opencast_password: str

    @classmethod
    def from_environment(cls) -> Settings:
        database_url = os.getenv("DATABASE_URL")
        if not database_url:
            user = quote_plus(os.getenv("DB_USER", "optout"))
            password = quote_plus(_read_secret("DB_PASSWORD"))
            host = os.getenv("DB_HOST", "localhost")
            port = os.getenv("DB_PORT", "3306")
            database = quote_plus(os.getenv("DB_NAME", "timetable"))
            database_url = (
                f"mysql+pymysql://{user}:{password}@{host}:{port}/{database}"
                "?charset=utf8mb4"
            )

        return cls(
            database_url=database_url,
            redis_url=os.getenv("REDIS_URL", "redis://redis:6379/0"),
            redis_cache_ttl=int(os.getenv("REDIS_CACHE_TTL", "60")),
            session_secret=_read_secret("SESSION_SECRET")
            or "development-only-change-this-session-secret",
            cookie_secure=os.getenv("COOKIE_SECURE", "false").lower() == "true",
            scheduler_interval_seconds=int(
                os.getenv("SCHEDULER_INTERVAL_SECONDS", "60")
            ),
            scheduler_enabled=os.getenv("SCHEDULER_ENABLED", "false").lower() == "true",
            ldap_host=os.getenv("LDAP_HOST", ""),
            ldap_port=int(os.getenv("LDAP_PORT", "636")),
            ldap_user=os.getenv("LDAP_USER", ""),
            ldap_password=_read_secret("LDAP_PASSWORD"),
            ldap_base_dn=os.getenv("LDAP_BASE_DN", ""),
            ldap_filter=os.getenv("LDAP_FILTER") or "(cn={username})",
            d2l_host=os.getenv("D2L_HOST", "").rstrip("/"),
            d2l_user=os.getenv("D2L_USER", ""),
            d2l_password=_read_secret("D2L_PASSWORD"),
            vula_host=os.getenv("VULA_HOST", "").rstrip("/"),
            vula_user=os.getenv("VULA_USER", ""),
            vula_password=_read_secret("VULA_PASSWORD"),
            opencast_host=os.getenv("OC_HOST", "").rstrip("/"),
            opencast_user=os.getenv("OC_USER", ""),
            opencast_password=_read_secret("OC_PASSWORD"),
        )
