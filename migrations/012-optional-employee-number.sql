ALTER TABLE employee
    ALTER COLUMN number DROP NOT NULL,
    ADD CONSTRAINT employee_attendance_number_required
        CHECK (NOT attendance_enabled OR (number IS NOT NULL AND btrim(number) <> ''));
