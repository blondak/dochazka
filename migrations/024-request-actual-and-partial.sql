ALTER TABLE request
    ADD COLUMN needs_actual boolean NOT NULL DEFAULT false,
    ADD COLUMN actual_from timestamptz,
    ADD COLUMN actual_to timestamptz,
    ADD COLUMN actual_returned boolean NOT NULL DEFAULT false,
    ADD COLUMN vacation_seconds integer CHECK (vacation_seconds > 0),
    ADD COLUMN vacation_side text CHECK (vacation_side IN ('start','end')),
    ADD COLUMN vacation_consent boolean NOT NULL DEFAULT false,
    ADD CHECK ((actual_from IS NULL AND actual_to IS NULL) OR
        (needs_actual AND actual_from IS NOT NULL AND actual_to > actual_from AND actual_to IS NOT NULL)),
    ADD CHECK ((vacation_seconds IS NULL AND vacation_side IS NULL) OR
        (kind = 'DOVOLENA' AND vacation_seconds IS NOT NULL AND vacation_side IS NOT NULL));

UPDATE request r SET needs_actual = true FROM attendance_account a
WHERE a.code = r.kind AND a.behavior = 'timed_absence' AND r.status = 'pending' AND NOT r.generated_holiday;
