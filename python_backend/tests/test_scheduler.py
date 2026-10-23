from __future__ import annotations

from datetime import datetime

from sqlalchemy import create_engine

from app.models import metadata, workflows
from app.services.scheduler import advance_workflows


def test_workflow_monitor_uses_compare_and_swap_transition(tmp_path) -> None:
    engine = create_engine(f"sqlite:///{tmp_path}/scheduler.db")
    metadata.create_all(engine)
    with engine.begin() as connection:
        connection.execute(
            workflows.insert().values(
                id=1,
                year=2026,
                status="start",
                date_start=datetime(2020, 1, 1),
                active=True,
            )
        )

    advance_workflows(engine)

    with engine.connect() as connection:
        status = connection.execute(
            workflows.select()
            .with_only_columns(workflows.c.status)
            .where(workflows.c.id == 1)
        ).scalar_one()
    engine.dispose()

    assert status == "run"
