from __future__ import annotations

from datetime import datetime
from typing import Any

from sqlalchemy import and_, select, update
from sqlalchemy.engine import Connection
from sqlalchemy.exc import IntegrityError, OperationalError
from sqlalchemy.sql.schema import Table

from app.models import (
    course_optouts,
    courses,
    department_optouts,
    departments,
)


class EntryNotFound(Exception):
    pass


class UpdateConflict(Exception):
    def __init__(self, current_version: int | None) -> None:
        self.current_version = current_version
        super().__init__("The entry has changed since it was read")


def _is_concurrent_write_error(error: Exception) -> bool:
    if isinstance(error, IntegrityError):
        message = str(error.orig).lower()
        return "unique constraint failed" in message or "duplicate entry" in message
    if isinstance(error, OperationalError):
        error_code = getattr(error.orig, "args", (None,))[0]
        return error_code in {1205, 1213}
    return False


def get_department(
    connection: Connection,
    department_code: str,
    year: int,
) -> dict[str, Any]:
    statement = (
        select(
            departments.c.dept,
            departments.c.name,
            departments.c.email,
            departments.c.firstname,
            departments.c.lastname,
            departments.c.alt_email,
            departments.c.secret,
            department_optouts.c.is_optout,
            department_optouts.c.updated_by,
            department_optouts.c.updated_at,
            department_optouts.c.version,
        )
        .select_from(
            departments.outerjoin(
                department_optouts,
                and_(
                    departments.c.dept == department_optouts.c.dept,
                    department_optouts.c.year == year,
                ),
            )
        )
        .where(departments.c.dept == department_code)
    )
    result = connection.execute(statement).mappings().first()
    if result is None:
        raise EntryNotFound("Department not found")
    return dict(result)


def get_course(
    connection: Connection,
    course_code: str,
    year: int,
) -> dict[str, Any]:
    statement = (
        select(
            courses.c.course_code,
            courses.c.term,
            courses.c.dept,
            courses.c.secret,
            courses.c.convenor_name,
            courses.c.convenor_eid,
            course_optouts.c.is_optout,
            course_optouts.c.updated_by,
            course_optouts.c.updated_at,
            course_optouts.c.version,
        )
        .select_from(
            courses.outerjoin(
                course_optouts,
                and_(
                    courses.c.course_code == course_optouts.c.course_code,
                    courses.c.term == course_optouts.c.year,
                ),
            )
        )
        .where(courses.c.course_code == course_code, courses.c.term == year)
    )
    result = connection.execute(statement).mappings().first()
    if result is None:
        raise EntryNotFound("Course not found")
    return dict(result)


def update_status(
    connection: Connection,
    table: Table,
    keys: dict[str, Any],
    department_code: str,
    status: bool,
    updated_by: str,
    expected_version: int,
) -> int:
    try:
        with connection.begin():
            current = connection.execute(
                select(table.c.version)
                .where(*(table.c[key] == value for key, value in keys.items()))
                .with_for_update()
            ).scalar_one_or_none()

            if current is None:
                if expected_version != 0:
                    raise UpdateConflict(None)
                values = {
                    **keys,
                    "dept": department_code,
                    "is_optout": status,
                    "updated_by": updated_by,
                    "updated_at": datetime.now(),
                    "version": 1,
                }
                connection.execute(table.insert().values(**values))
                return 1

            if current != expected_version:
                raise UpdateConflict(current)

            result = connection.execute(
                update(table)
                .where(
                    *(table.c[key] == value for key, value in keys.items()),
                    table.c.version == expected_version,
                )
                .values(
                    is_optout=status,
                    updated_by=updated_by,
                    updated_at=datetime.now(),
                    version=expected_version + 1,
                )
            )
            if result.rowcount != 1:
                raise UpdateConflict(current)
            return expected_version + 1
    except (IntegrityError, OperationalError) as exc:
        if not _is_concurrent_write_error(exc):
            raise
        current = connection.execute(
            select(table.c.version).where(
                *(table.c[key] == value for key, value in keys.items())
            )
        ).scalar_one_or_none()
        raise UpdateConflict(current) from exc


def update_department_status(
    connection: Connection,
    department_code: str,
    year: int,
    status: bool,
    updated_by: str,
    expected_version: int,
) -> int:
    return update_status(
        connection,
        department_optouts,
        {"dept": department_code, "year": year},
        department_code,
        status,
        updated_by,
        expected_version,
    )


def update_course_status(
    connection: Connection,
    course_code: str,
    year: int,
    department_code: str,
    status: bool,
    updated_by: str,
    expected_version: int,
) -> int:
    return update_status(
        connection,
        course_optouts,
        {"course_code": course_code, "year": year},
        department_code,
        status,
        updated_by,
        expected_version,
    )
