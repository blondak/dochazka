ALTER TABLE employee ADD COLUMN credentials_ready boolean NOT NULL DEFAULT false;

-- A stored passkey confirms that the owner already set a password.
-- Other existing credentials are marked ready on successful authentication.
UPDATE employee e SET credentials_ready = true
WHERE EXISTS (SELECT 1 FROM passkey p WHERE p.employee_id = e.id);
