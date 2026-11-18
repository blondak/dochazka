ALTER TABLE employee
    ADD COLUMN roles jsonb,
    ADD COLUMN attendance_enabled boolean,
    ADD COLUMN archived_on date;

UPDATE employee e
SET roles = jsonb_build_array(e.role),
    attendance_enabled = e.role = 'employee' OR e.sid IS NOT NULL
        OR EXISTS (SELECT 1 FROM request r WHERE r.employee = e.id)
        OR EXISTS (SELECT 1 FROM approval a WHERE a.employee = e.id);

ALTER TABLE employee
    ALTER COLUMN roles SET NOT NULL,
    ALTER COLUMN attendance_enabled SET NOT NULL,
    ADD CONSTRAINT employee_roles_valid CHECK (
        jsonb_typeof(roles) = 'array' AND roles <> '[]'::jsonb
        AND roles <@ '["employee","manager","payroll","admin"]'::jsonb
    ),
    DROP COLUMN role;
