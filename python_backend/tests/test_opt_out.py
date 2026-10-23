from __future__ import annotations

from collections.abc import Iterator

import pytest
from sqlalchemy import create_engine
from sqlalchemy.engine import Connection

from app.models import course_optouts, department_optouts, metadata
from app.repositories.opt_out import (
    UpdateConflict,
    update_course_status,
    update_department_status,
)


@pytest.fixture
def connection() -> Iterator[Connection]:
    engine = create_engine("sqlite://")
    metadata.create_all(engine)
    with engine.connect() as current:
        yield current
    engine.dispose()


def test_department_update_increments_version(connection: Connection) -> None:
    version = update_department_status(
        connection,
        "ABC",
        2026,
        True,
        "staff-id",
        0,
    )

    assert version == 1
    row = (
        connection.execute(
            department_optouts.select().where(
                department_optouts.c.dept == "ABC",
                department_optouts.c.year == 2026,
            )
        )
        .mappings()
        .one()
    )
    assert row["is_optout"] is True
    assert row["version"] == 1


def test_stale_department_update_is_rejected(connection: Connection) -> None:
    update_department_status(connection, "ABC", 2026, True, "first", 0)

    with pytest.raises(UpdateConflict) as conflict:
        update_department_status(connection, "ABC", 2026, False, "second", 0)

    assert conflict.value.current_version == 1
    stored_status = connection.execute(
        department_optouts.select().with_only_columns(department_optouts.c.is_optout)
    ).scalar_one()
    assert stored_status is True


def test_course_updates_use_course_and_year_key(connection: Connection) -> None:
    assert (
        update_course_status(
            connection,
            "ABC1000S",
            2026,
            "ABC",
            True,
            "staff-id",
            0,
        )
        == 1
    )
    row = (
        connection.execute(
            course_optouts.select().where(course_optouts.c.course_code == "ABC1000S")
        )
        .mappings()
        .one()
    )
    assert (row["year"], row["dept"], row["version"]) == (2026, "ABC", 1)


def test_missing_entry_requires_initial_version(connection: Connection) -> None:
    with pytest.raises(UpdateConflict) as conflict:
        update_department_status(connection, "ABC", 2026, True, "staff-id", 1)

    assert conflict.value.current_version is None
