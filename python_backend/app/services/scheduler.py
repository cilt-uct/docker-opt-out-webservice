from __future__ import annotations

import logging
import secrets
from collections.abc import Callable
from datetime import datetime
from zoneinfo import ZoneInfo

from apscheduler.schedulers.background import BackgroundScheduler
from redis import Redis
from sqlalchemy import select, update
from sqlalchemy.engine import Engine

from app.config import Settings
from app.models import retention_batches, workflows

logger = logging.getLogger(__name__)
LOCK_RELEASE_SCRIPT = """
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
end
return 0
"""


def _run_with_lock(
    client: Redis,
    lock_name: str,
    job: Callable[[Engine], None],
    engine: Engine,
) -> None:
    token = secrets.token_urlsafe(24)
    if not client.set(lock_name, token, nx=True, ex=300):
        return
    try:
        job(engine)
    except Exception:
        logger.exception("Scheduled job %s failed", lock_name)
        raise
    finally:
        client.eval(LOCK_RELEASE_SCRIPT, 1, lock_name, token)


def advance_workflows(engine: Engine) -> None:
    now = datetime.now(ZoneInfo("Africa/Johannesburg")).replace(tzinfo=None)
    transitions = {
        "init": ("start", None),
        "start": ("run", "date_start"),
        "run": ("dept", "date_dept"),
        "dept": ("course", "date_course"),
        "course": ("done", "date_schedule"),
    }
    with engine.begin() as connection:
        active = connection.execute(
            select(workflows).where(workflows.c.active.is_(True))
        ).mappings()
        for workflow in active:
            transition = transitions.get(workflow["status"])
            if transition is None:
                continue
            next_status, due_column = transition
            due_at = workflow[due_column] if due_column else now
            if due_at is None or due_at > now:
                continue
            connection.execute(
                update(workflows)
                .where(
                    workflows.c.id == workflow["id"],
                    workflows.c.status == workflow["status"],
                    workflows.c.active.is_(True),
                )
                .values(status=next_status)
            )


def advance_retention_batches(engine: Engine) -> None:
    now = datetime.now(ZoneInfo("Africa/Johannesburg")).replace(tzinfo=None)
    with engine.begin() as connection:
        batches = connection.execute(
            select(retention_batches).where(retention_batches.c.active.is_(True))
        ).mappings()
        for batch in batches:
            if batch["status"] == "created":
                next_status = "waiting"
            elif (
                batch["status"] == "waiting"
                and batch["date_start"] is not None
                and batch["date_start"] <= now
            ):
                next_status = "running"
            else:
                continue
            connection.execute(
                update(retention_batches)
                .where(
                    retention_batches.c.id == batch["id"],
                    retention_batches.c.status == batch["status"],
                    retention_batches.c.active.is_(True),
                )
                .values(status=next_status)
            )


def create_scheduler(
    settings: Settings,
    engine: Engine,
    redis_client: Redis,
) -> BackgroundScheduler:
    scheduler = BackgroundScheduler(timezone="Africa/Johannesburg")
    interval = settings.scheduler_interval_seconds

    def workflow_job() -> None:
        _run_with_lock(redis_client, "optout:lock:workflow", advance_workflows, engine)

    def retention_job() -> None:
        _run_with_lock(
            redis_client,
            "optout:lock:retention",
            advance_retention_batches,
            engine,
        )

    scheduler.add_job(
        workflow_job,
        "interval",
        seconds=interval,
        id="workflow-monitor",
        max_instances=1,
        coalesce=True,
    )
    scheduler.add_job(
        retention_job,
        "interval",
        seconds=interval,
        id="retention-monitor",
        max_instances=1,
        coalesce=True,
    )
    return scheduler
