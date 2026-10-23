-- Additive migration: existing primary/unique keys and table contents stay intact.
ALTER TABLE dept_optout
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE course_optout
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0;
