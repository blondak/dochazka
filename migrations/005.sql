CREATE TABLE passkey (
    credential_id text PRIMARY KEY,
    employee_id integer NOT NULL REFERENCES employee(id) ON DELETE CASCADE,
    public_key text NOT NULL,
    signature_counter bigint,
    label text NOT NULL,
    created_at timestamptz NOT NULL
);
CREATE INDEX passkey_employee_id ON passkey(employee_id);
