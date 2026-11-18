CREATE TABLE attendance_account (
    code text PRIMARY KEY CHECK (code ~ '^[A-Z][A-Z0-9_]{0,31}$'),
    name text NOT NULL,
    description text NOT NULL DEFAULT '',
    position integer NOT NULL,
    behavior text NOT NULL CHECK (behavior IN ('work', 'timed_absence', 'shift_absence', 'break')),
    requires_proof boolean NOT NULL DEFAULT false,
    active boolean NOT NULL DEFAULT true,
    system boolean NOT NULL DEFAULT false
);
INSERT INTO attendance_account (code, name, position, behavior, requires_proof, system) VALUES
    ('PRACE', 'Přítomnost', 10, 'work', false, true),
    ('DOVOLENA', 'Dovolená', 20, 'shift_absence', false, true),
    ('LEKAR', 'Lékař', 30, 'timed_absence', true, true),
    ('PRESTAVKA', 'Přestávka', 40, 'break', false, true);

CREATE TABLE attendance_account_payroll (
    account text NOT NULL REFERENCES attendance_account(code),
    effective_on date NOT NULL,
    payroll_code text NOT NULL,
    PRIMARY KEY (account, effective_on)
);
INSERT INTO attendance_account_payroll SELECT code, DATE '0001-01-01', code FROM attendance_account;

ALTER TABLE request DROP CONSTRAINT request_kind_check,
    ADD FOREIGN KEY (kind) REFERENCES attendance_account(code),
    ADD COLUMN requires_proof boolean NOT NULL DEFAULT false;
UPDATE request SET requires_proof = true WHERE kind = 'LEKAR';
CREATE INDEX request_kind_idx ON request (kind);

CREATE TABLE work_model (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL UNIQUE
);
CREATE TABLE work_model_version (
    model integer NOT NULL REFERENCES work_model(id),
    effective_on date NOT NULL,
    week jsonb NOT NULL CHECK (jsonb_typeof(week) = 'array' AND jsonb_array_length(week) = 7),
    PRIMARY KEY (model, effective_on)
);
CREATE TABLE employee_work_model (
    employee integer NOT NULL REFERENCES employee(id),
    effective_on date NOT NULL,
    model integer NOT NULL REFERENCES work_model(id),
    PRIMARY KEY (employee, effective_on)
);
