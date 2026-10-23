# Python transition API

This directory contains the FastAPI transition backend. It uses the existing
MySQL/MariaDB database, Redis for short-lived response caching, and APScheduler
for the workflow and retention monitor ticks. The legacy PHP UI remains available
under the `legacy` Compose profile while its remaining behavior is migrated.

## Run with Docker Compose

From the repository root, prepare the non-secret connection settings and local
secret files:

```sh
cp python_backend/.env.example .env
cp python_backend/secrets/db_password.example.txt python_backend/secrets/db_password.txt
cp python_backend/secrets/ldap_password.example.txt python_backend/secrets/ldap_password.txt
cp python_backend/secrets/d2l_password.example.txt python_backend/secrets/d2l_password.txt
cp python_backend/secrets/vula_password.example.txt python_backend/secrets/vula_password.txt
cp python_backend/secrets/opencast_password.example.txt python_backend/secrets/opencast_password.txt
cp python_backend/secrets/session_secret.example.txt python_backend/secrets/session_secret.txt
chmod 600 python_backend/secrets/*.txt
```

Replace the example hostnames, usernames, and placeholder values before use.
Docker Compose mounts each password file at `/run/secrets/...`; do not put
passwords in `.env`, Compose environment variables, or committed files. The
service binds to `127.0.0.1:8000`; put a TLS reverse proxy in front of it for
remote access and set `COOKIE_SECURE=true`.

`SCHEDULER_ENABLED` intentionally defaults to `false`: full legacy workflow and
retention side effects (course refreshes, notification creation, and cleanup)
are not yet ported. Keep it disabled until those features are implemented and
verified in the parity phase.

Apply the additive revision migration to the target `timetable` database before
accepting writes, then start the Python API and Redis:

```sh
mysql -h "$DB_HOST" -u "$DB_USER" -p timetable \
  < python_backend/migrations/001_optout_revision.sql
docker compose up --build
```

To run the old UI during migration, start the legacy profile as well:

```sh
docker compose --profile legacy up --build
```

The API is documented by FastAPI at `http://127.0.0.1:8000/docs`. `/health`
checks the database and Redis. The database and external service addresses are
configured in `.env`; their passwords are read from separate secret files.

## Concurrent updates

Department and course reads include a `version`. A write must include the version
it read in the HTTP `If-Match` header, along with the existing `x-entity-hash`
and authenticated session. For example, after reading a record with version 2:

```sh
curl -X PUT http://127.0.0.1:8000/api/v0/dept/ABC \
  -H 'Content-Type: application/json' \
  -H 'x-entity-hash: <entity-hash>' \
  -H 'If-Match: 2' \
  -b cookies.txt \
  -d '{"status":true}'
```

A stale revision returns `409 Conflict` and the current revision; a missing
`If-Match` returns `428 Precondition Required`. This intentionally prevents a
legacy client from silently overwriting an intervening edit.

## Local checks

```sh
cd python_backend
python -m venv .venv
. .venv/bin/activate
python -m pip install -e '.[dev]'
ruff check .
pytest
```
