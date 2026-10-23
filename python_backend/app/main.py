from __future__ import annotations

import hashlib
import hmac
import logging
from contextlib import asynccontextmanager
from datetime import datetime
from typing import Any

from fastapi import Depends, FastAPI, Header, HTTPException, Request, Response
from fastapi.responses import JSONResponse
from ldap3.core.exceptions import LDAPBindError
from pydantic import BaseModel
from redis import Redis
from sqlalchemy import text
from sqlalchemy.engine import Connection, Engine
from starlette.middleware.sessions import SessionMiddleware

from app.cache import ResponseCache
from app.config import Settings
from app.database import create_database_engine
from app.repositories import opt_out
from app.repositories.opt_out import EntryNotFound, UpdateConflict
from app.services.ldap_auth import LdapAuthenticator
from app.services.opencast import OpencastClient
from app.services.scheduler import create_scheduler

logger = logging.getLogger(__name__)


class OptOutUpdate(BaseModel):
    status: bool


def _version_from_header(value: str | None) -> int:
    if value is None:
        raise HTTPException(
            status_code=428,
            detail="If-Match must contain the version returned by the read request",
        )
    try:
        version = int(value.strip('"'))
    except ValueError as exc:
        raise HTTPException(
            status_code=400,
            detail="If-Match must be an integer",
        ) from exc
    if version < 0:
        raise HTTPException(status_code=400, detail="If-Match cannot be negative")
    return version


def _department_payload(row: dict[str, Any], year: int) -> dict[str, Any]:
    full_hash = hashlib.sha256(f"{row['dept']}.{row['secret']}".encode()).hexdigest()
    return {
        "dept": row["dept"],
        "name": row["name"],
        "hod": " ".join(part for part in (row["firstname"], row["lastname"]) if part),
        "mail": row["email"],
        "eid": None,
        "is_optout": bool(row["is_optout"]),
        "hash": full_hash[:6],
        "version": row["version"] or 0,
        "year": year,
        "rest": False,
        "skipCourses": True,
        "courses": [],
        "updated_by": row["updated_by"],
        "updated_at": row["updated_at"],
    }


def _course_payload(row: dict[str, Any], year: int) -> dict[str, Any]:
    full_hash = hashlib.sha256(
        f"{row['course_code']}.{row['secret']}".encode()
    ).hexdigest()
    return {
        "courseCode": row["course_code"],
        "dept": row["dept"],
        "year": year,
        "convenor": {
            "eid": row["convenor_eid"],
            "title": None,
            "name": row["convenor_name"],
            "email": None,
        },
        "optoutStatus": int(bool(row["is_optout"])),
        "updatedAt": row["updated_at"],
        "updatedBy": row["updated_by"],
        "hash": full_hash[:6],
        "version": row["version"] or 0,
        "mails": [],
    }


def _check_hash(actual: str, expected: str | None) -> None:
    if expected is None or not hmac.compare_digest(actual, expected):
        raise HTTPException(status_code=401, detail="invalid hash")


def create_app(
    settings: Settings | None = None,
    engine: Engine | None = None,
    redis_client: Any | None = None,
    start_scheduler: bool = True,
) -> FastAPI:
    app_settings = settings or Settings.from_environment()
    owns_engine = engine is None
    db_engine = engine or create_database_engine(app_settings.database_url)
    owns_redis = redis_client is None
    cache_client = redis_client or Redis.from_url(
        app_settings.redis_url,
        decode_responses=True,
        socket_connect_timeout=3,
        socket_timeout=3,
    )

    @asynccontextmanager
    async def lifespan(application: FastAPI):
        application.state.engine = db_engine
        application.state.redis = cache_client
        application.state.cache = ResponseCache(
            cache_client,
            app_settings.redis_cache_ttl,
        )
        application.state.ldap_authenticator = LdapAuthenticator(app_settings)
        application.state.opencast = OpencastClient(app_settings)
        scheduler = None
        if start_scheduler and app_settings.scheduler_enabled:
            scheduler = create_scheduler(app_settings, db_engine, cache_client)
            scheduler.start()
        application.state.scheduler = scheduler
        try:
            yield
        finally:
            if scheduler is not None:
                scheduler.shutdown(wait=False)
            if owns_redis:
                cache_client.close()
            if owns_engine:
                db_engine.dispose()

    application = FastAPI(
        title="UCT Opt-Out API",
        version="0.1.0",
        lifespan=lifespan,
    )
    application.add_middleware(
        SessionMiddleware,
        secret_key=app_settings.session_secret,
        https_only=app_settings.cookie_secure,
        same_site="lax",
        max_age=8 * 60 * 60,
    )

    def get_connection(request: Request):
        connection = request.app.state.engine.connect()
        try:
            yield connection
        finally:
            connection.close()

    def get_cache(request: Request) -> ResponseCache:
        return request.app.state.cache

    def require_user(request: Request) -> str:
        username = request.session.get("username")
        if not username:
            raise HTTPException(status_code=401, detail="Please login to make changes")
        return username

    @application.get("/health")
    def health(request: Request) -> JSONResponse:
        checks = {"database": "ok", "redis": "ok"}
        status_code = 200
        try:
            with request.app.state.engine.connect() as connection:
                connection.execute(text("SELECT 1"))
        except Exception:
            logger.exception("Database health check failed")
            checks["database"] = "unavailable"
            status_code = 503
        try:
            request.app.state.redis.ping()
        except Exception:
            logger.exception("Redis health check failed")
            checks["redis"] = "unavailable"
            status_code = 503
        return JSONResponse(
            {"status": "ok" if status_code == 200 else "unavailable", **checks},
            status_code=status_code,
        )

    @application.get("/me")
    def me(request: Request) -> dict[str, Any]:
        return {"username": request.session.get("username")}

    @application.post("/authenticate")
    async def authenticate(request: Request) -> Response:
        form = await request.form()
        username = str(form.get("username", "")).strip()
        password = str(form.get("password", ""))
        if not username or not password:
            raise HTTPException(
                status_code=400,
                detail="Username and password required",
            )
        try:
            authenticated = request.app.state.ldap_authenticator.authenticate(
                username,
                password,
            )
        except LDAPBindError as exc:
            raise HTTPException(
                status_code=401,
                detail="Invalid username/password combination",
            ) from exc
        except RuntimeError as exc:
            raise HTTPException(status_code=503, detail=str(exc)) from exc
        except Exception as exc:
            logger.exception("LDAP authentication failed")
            raise HTTPException(
                status_code=503,
                detail="LDAP service unavailable",
            ) from exc
        if not authenticated:
            raise HTTPException(
                status_code=401,
                detail="Invalid username/password combination",
            )
        request.session["username"] = username
        return Response("OK", status_code=200)

    @application.get("/api/v0/dept/{department_code}")
    def read_department(
        department_code: str,
        request: Request,
        year: int | None = None,
        entity_hash: str | None = Header(default=None, alias="x-entity-hash"),
        connection: Connection = Depends(get_connection),
        cache: ResponseCache = Depends(get_cache),
    ) -> dict[str, Any]:
        target_year = year or datetime.now().year
        key = cache.digest_key("department", f"{department_code}:{target_year}")
        cached = cache.get_json(key)
        if cached is None:
            try:
                cached = _department_payload(
                    opt_out.get_department(connection, department_code, target_year),
                    target_year,
                )
            except EntryNotFound as exc:
                raise HTTPException(status_code=404, detail=str(exc)) from exc
            cache.set_json(key, cached)
        _check_hash(cached["hash"], entity_hash)
        return cached

    @application.put("/api/v0/dept/{department_code}")
    def update_department(
        department_code: str,
        body: OptOutUpdate,
        request: Request,
        year: int | None = None,
        entity_hash: str | None = Header(default=None, alias="x-entity-hash"),
        if_match: str | None = Header(default=None, alias="If-Match"),
        username: str = Depends(require_user),
        connection: Connection = Depends(get_connection),
        cache: ResponseCache = Depends(get_cache),
    ) -> dict[str, Any]:
        target_year = year or datetime.now().year
        try:
            row = opt_out.get_department(connection, department_code, target_year)
        except EntryNotFound as exc:
            raise HTTPException(status_code=404, detail=str(exc)) from exc
        full_hash = hashlib.sha256(
            f"{row['dept']}.{row['secret']}".encode()
        ).hexdigest()[:6]
        _check_hash(full_hash, entity_hash)
        expected_version = _version_from_header(if_match)
        try:
            with request.app.state.engine.connect() as write_connection:
                version = opt_out.update_department_status(
                    write_connection,
                    department_code,
                    target_year,
                    body.status,
                    username,
                    expected_version,
                )
        except UpdateConflict as exc:
            raise HTTPException(
                status_code=409,
                detail={
                    "error": str(exc),
                    "current_version": exc.current_version,
                },
            ) from exc
        cache.invalidate(
            cache.digest_key("department", f"{department_code}:{target_year}")
        )
        return {
            "success": True,
            "user": username,
            "date": datetime.now().isoformat(timespec="seconds"),
            "version": version,
        }

    @application.get("/api/v0/course/{course_code}")
    def read_course(
        course_code: str,
        year: int | None = None,
        entity_hash: str | None = Header(default=None, alias="x-entity-hash"),
        connection: Connection = Depends(get_connection),
        cache: ResponseCache = Depends(get_cache),
    ) -> dict[str, Any]:
        target_year = year or datetime.now().year
        key = cache.digest_key("course", f"{course_code}:{target_year}")
        cached = cache.get_json(key)
        if cached is None:
            try:
                cached = _course_payload(
                    opt_out.get_course(connection, course_code, target_year),
                    target_year,
                )
            except EntryNotFound as exc:
                raise HTTPException(status_code=404, detail=str(exc)) from exc
            cache.set_json(key, cached)
        _check_hash(cached["hash"], entity_hash)
        return cached

    @application.put("/api/v0/course/{course_code}")
    def update_course(
        course_code: str,
        body: OptOutUpdate,
        request: Request,
        year: int | None = None,
        entity_hash: str | None = Header(default=None, alias="x-entity-hash"),
        if_match: str | None = Header(default=None, alias="If-Match"),
        username: str = Depends(require_user),
        connection: Connection = Depends(get_connection),
        cache: ResponseCache = Depends(get_cache),
    ) -> dict[str, Any]:
        target_year = year or datetime.now().year
        try:
            row = opt_out.get_course(connection, course_code, target_year)
        except EntryNotFound as exc:
            raise HTTPException(status_code=404, detail=str(exc)) from exc
        full_hash = hashlib.sha256(
            f"{row['course_code']}.{row['secret']}".encode()
        ).hexdigest()[:6]
        _check_hash(full_hash, entity_hash)
        expected_version = _version_from_header(if_match)
        try:
            with request.app.state.engine.connect() as write_connection:
                version = opt_out.update_course_status(
                    write_connection,
                    course_code,
                    target_year,
                    row["dept"],
                    body.status,
                    username,
                    expected_version,
                )
        except UpdateConflict as exc:
            raise HTTPException(
                status_code=409,
                detail={
                    "error": str(exc),
                    "current_version": exc.current_version,
                },
            ) from exc
        cache.invalidate(cache.digest_key("course", f"{course_code}:{target_year}"))
        return {
            "success": True,
            "user": username,
            "date": datetime.now().isoformat(timespec="seconds"),
            "version": version,
        }

    @application.get("/api/v0/{entity_type}/{entity_name}/hash")
    def get_entity_hash(
        entity_type: str,
        entity_name: str,
        year: int | None = None,
        connection: Connection = Depends(get_connection),
    ) -> Response:
        target_year = year or datetime.now().year
        try:
            if entity_type in {"dept", "department"}:
                row = opt_out.get_department(connection, entity_name, target_year)
                code = row["dept"]
            elif entity_type == "course":
                row = opt_out.get_course(connection, entity_name, target_year)
                code = row["course_code"]
            else:
                raise HTTPException(status_code=404, detail="Unknown entity type")
        except EntryNotFound as exc:
            raise HTTPException(status_code=404, detail=str(exc)) from exc
        full_hash = hashlib.sha256(f"{code}.{row['secret']}".encode()).hexdigest()
        return Response(full_hash[:6], media_type="text/plain")

    @application.get("/api/v0/episode/{event_id}")
    def get_episode(event_id: str, request: Request) -> Any:
        try:
            return request.app.state.opencast.get_event(event_id.replace("_", "-"))
        except RuntimeError as exc:
            raise HTTPException(status_code=502, detail=str(exc)) from exc

    return application


app = create_app()
