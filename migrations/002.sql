ALTER TABLE employee ADD COLUMN invitation text UNIQUE, ADD COLUMN invite_until timestamptz;
CREATE TABLE login_limit (bucket text PRIMARY KEY, attempts integer NOT NULL, since timestamptz NOT NULL);
CREATE INDEX requests_employee ON request(employee,begins);
