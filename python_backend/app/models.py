from sqlalchemy import (
    Boolean,
    Column,
    DateTime,
    Integer,
    MetaData,
    SmallInteger,
    String,
    Table,
)

metadata = MetaData()

departments = Table(
    "uct_dept",
    metadata,
    Column("dept", String(3), primary_key=True),
    Column("name", String(255)),
    Column("email", String(255)),
    Column("firstname", String(255)),
    Column("lastname", String(255)),
    Column("alt_email", String(255)),
    Column("active", Boolean),
    Column("secret", String(128), nullable=False),
)

department_optouts = Table(
    "dept_optout",
    metadata,
    Column("dept", String(3), primary_key=True),
    Column("year", Integer, primary_key=True),
    Column("is_optout", Boolean),
    Column("updated_by", String(36)),
    Column("updated_at", DateTime),
    Column("version", Integer, nullable=False, default=0),
)

courses = Table(
    "ps_courses",
    metadata,
    Column("course_code", String(20), primary_key=True),
    Column("term", SmallInteger, primary_key=True),
    Column("dept", String(3)),
    Column("secret", String(128)),
    Column("acad_career", String(20)),
    Column("convenor_name", String(255)),
    Column("convenor_eid", String(36)),
)

course_optouts = Table(
    "course_optout",
    metadata,
    Column("course_code", String(20), primary_key=True),
    Column("year", SmallInteger, primary_key=True),
    Column("dept", String(3), nullable=False),
    Column("is_optout", Boolean, nullable=False, default=False),
    Column("updated_by", String(36)),
    Column("updated_at", DateTime),
    Column("version", Integer, nullable=False, default=0),
)

workflows = Table(
    "uct_workflow",
    metadata,
    Column("id", Integer, primary_key=True),
    Column("year", SmallInteger),
    Column("status", String(20), nullable=False),
    Column("date_start", DateTime),
    Column("date_dept", DateTime),
    Column("date_course", DateTime),
    Column("date_schedule", DateTime),
    Column("active", Boolean, nullable=False, default=True),
)

retention_batches = Table(
    "opencast_retention_batch",
    metadata,
    Column("id", Integer, primary_key=True),
    Column("status", String(20), nullable=False),
    Column("date_start", DateTime),
    Column("date_scheduled", DateTime),
    Column("active", Boolean, nullable=False, default=True),
)
