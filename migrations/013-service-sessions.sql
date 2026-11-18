ALTER TABLE signal_session
    ADD COLUMN service_by integer REFERENCES employee,
    ADD COLUMN service_at timestamptz,
    ADD CHECK ((service_by IS NULL) = (service_at IS NULL));

CREATE TABLE service_session_link (
    sid text NOT NULL,
    device integer NOT NULL,
    session_key text NOT NULL,
    code_hash text NOT NULL UNIQUE,
    expires_at timestamptz NOT NULL,
    PRIMARY KEY (sid, device, session_key),
    FOREIGN KEY (sid, device, session_key) REFERENCES signal_session ON DELETE CASCADE
);

CREATE INDEX service_session_link_expiry ON service_session_link (expires_at);
