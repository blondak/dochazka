ALTER TABLE employee ADD COLUMN session_version integer NOT NULL DEFAULT 1;
CREATE INDEX login_limit_since ON login_limit (since);
