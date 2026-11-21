ALTER TABLE employee DROP CONSTRAINT employee_roles_valid,
    ADD CONSTRAINT employee_roles_valid CHECK (
        jsonb_typeof(roles) = 'array' AND roles <> '[]'::jsonb
        AND roles <@ '["employee","manager","hr","payroll","admin"]'::jsonb
    );
