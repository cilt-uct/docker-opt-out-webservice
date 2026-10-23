from __future__ import annotations

from app.config import Settings


def test_password_file_is_used_for_database_url(tmp_path, monkeypatch) -> None:
    secret_file = tmp_path / "db-password"
    secret_file.write_text("p@ssword\n", encoding="utf-8")
    monkeypatch.setenv("DB_PASSWORD_FILE", str(secret_file))
    monkeypatch.setenv("DB_HOST", "database.internal")
    monkeypatch.setenv("DB_USER", "service")

    settings = Settings.from_environment()

    assert "service:p%40ssword@database.internal" in settings.database_url
