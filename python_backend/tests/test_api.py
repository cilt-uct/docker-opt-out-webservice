from __future__ import annotations

import hashlib

from fastapi.testclient import TestClient
from sqlalchemy import create_engine

from app.config import Settings
from app.main import create_app
from app.models import courses, departments, metadata


class FakeRedis:
    def __init__(self) -> None:
        self.values: dict[str, str] = {}

    def get(self, key: str) -> str | None:
        return self.values.get(key)

    def setex(self, key: str, ttl: int, value: str) -> None:
        self.values[key] = value

    def delete(self, key: str) -> None:
        self.values.pop(key, None)

    def ping(self) -> bool:
        return True


class SuccessfulAuthenticator:
    def authenticate(self, username: str, password: str) -> bool:
        return username == "staff" and password == "valid"


def test_reads_return_hash_and_revision(tmp_path) -> None:
    engine = create_engine(f"sqlite:///{tmp_path}/test.db")
    metadata.create_all(engine)
    with engine.begin() as connection:
        connection.execute(
            departments.insert().values(
                dept="ABC",
                name="Department",
                firstname="Ada",
                lastname="Lovelace",
                email="ada@example.test",
                secret="secret",
            )
        )
        connection.execute(
            courses.insert().values(
                course_code="ABC1000S",
                term=2026,
                dept="ABC",
                secret="course-secret",
                convenor_name="Ada",
                convenor_eid="1234",
            )
        )
    settings = Settings(
        database_url="sqlite://",
        redis_url="redis://unused",
        redis_cache_ttl=60,
        session_secret="test-session-secret",
        cookie_secure=False,
        scheduler_interval_seconds=60,
        scheduler_enabled=False,
        ldap_host="",
        ldap_port=636,
        ldap_user="",
        ldap_password="",
        ldap_base_dn="",
        ldap_filter="(cn={username})",
        d2l_host="",
        d2l_user="",
        d2l_password="",
        vula_host="",
        vula_user="",
        vula_password="",
        opencast_host="",
        opencast_user="",
        opencast_password="",
    )
    app = create_app(settings, engine, FakeRedis(), start_scheduler=False)

    with TestClient(app) as client:
        client.app.state.ldap_authenticator = SuccessfulAuthenticator()
        login = client.post(
            "/authenticate",
            data={"username": "staff", "password": "valid"},
        )
        department = client.get(
            "/api/v0/dept/ABC?year=2026",
            headers={"x-entity-hash": hashlib.sha256(b"ABC.secret").hexdigest()[:6]},
        )
        course_hash = hashlib.sha256(b"ABC1000S.course-secret").hexdigest()[:6]
        course = client.get(
            "/api/v0/course/ABC1000S?year=2026",
            headers={"x-entity-hash": course_hash},
        )
        update_headers = {
            "x-entity-hash": hashlib.sha256(b"ABC.secret").hexdigest()[:6],
            "If-Match": "0",
        }
        first_update = client.put(
            "/api/v0/dept/ABC?year=2026",
            headers=update_headers,
            json={"status": True},
        )
        stale_update = client.put(
            "/api/v0/dept/ABC?year=2026",
            headers=update_headers,
            json={"status": False},
        )

    assert login.status_code == 200
    assert department.status_code == 200
    assert department.json()["version"] == 0
    assert department.json()["is_optout"] is False
    assert course.status_code == 200
    assert course.json()["version"] == 0
    assert first_update.status_code == 200
    assert first_update.json()["version"] == 1
    assert stale_update.status_code == 409
    assert stale_update.json()["detail"]["current_version"] == 1
    engine.dispose()
