# FastAPI transition plan

## Target architecture

- Keep the existing MySQL/MariaDB database and its opt-out/workflow tables.
- Add a FastAPI API service in `python_backend/`, Redis-backed short-lived response
  caching, and APScheduler jobs for the workflow and retention monitors.
- Use mounted Docker secret files for database, LDAP, Opencast, and session secrets.
- Keep the current PHP/Symfony UI available during the transition; the Python API
  is exposed independently on port 8000 until UI routes have been migrated.

## Concurrency and schema

The current `dept_optout` unique key (`dept`, `year`) and `course_optout` primary
key (`course_code`, `year`) are retained. Add an unsigned `version` column to each
table using `python_backend/migrations/001_optout_revision.sql`; existing records
start at version 0. Reads return the revision. Writes require that revision through
`If-Match`; a stale revision receives HTTP 409 without changing the record.
The additive revision is necessary because timestamp-based checks are not reliable
with the legacy timestamp precision and row replacement can silently overwrite a
concurrent edit.

## Transition phases

1. **Foundation:** FastAPI application, DB/Redis connections, secret-file loading,
   core department/course reads and opt-out writes, optimistic concurrency, and
   APScheduler wiring. Scheduled state mutations stay disabled until their legacy
   side effects are ported.
2. **Integrations and parity:** port the legacy UI/API behaviors for LDAP-backed
   search, Sakai/D2L, mail generation/results, series administration, full
   department/course detail, and all Opencast operations. Add contract fixtures
   from the current database and approved external-service test environments.
3. **Cutover:** route the UI through FastAPI, verify legacy response compatibility,
   run the new scheduler once per deployment, then retire the PHP backend only
   after operational and user acceptance.

The current branch implements the foundation and a thin Opencast episode lookup.
Do not remove legacy PHP paths or switch the UI to the Python API until the parity
phase is complete.
