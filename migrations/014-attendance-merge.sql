CREATE TABLE attendance_merge_limit (
    effective_on date PRIMARY KEY,
    gap_minutes smallint NOT NULL CHECK (gap_minutes BETWEEN 0 AND 1439)
);

ALTER TABLE request ADD COLUMN merged_work boolean NOT NULL DEFAULT false
    CHECK (NOT merged_work OR kind = 'PRACE');
