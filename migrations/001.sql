CREATE TABLE employee (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 number text UNIQUE NOT NULL, name text NOT NULL, login text UNIQUE NOT NULL,
 password_hash text NOT NULL, role text NOT NULL CHECK(role IN ('employee','manager','payroll','admin')),
 team text NOT NULL, sid text UNIQUE, starts date NOT NULL DEFAULT CURRENT_DATE,
 ends date, CHECK (ends IS NULL OR ends >= starts)
);
CREATE TABLE device (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY, name text NOT NULL,
 enrollment text UNIQUE, expires timestamptz, credential text UNIQUE, seen timestamptz,
 revoked boolean NOT NULL DEFAULT false
);
CREATE TABLE signal (
 device integer NOT NULL REFERENCES device, event_id text NOT NULL, kind text NOT NULL,
 happened timestamptz NOT NULL, sid text NOT NULL, session_key text NOT NULL,
 received timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(device,event_id)
);
CREATE INDEX signal_employee_time ON signal(sid,happened);
CREATE TABLE request (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, employee integer NOT NULL REFERENCES employee,
 kind text NOT NULL CHECK(kind IN ('LEKAR','DOVOLENA','PRACE','PRESTAVKA')),
 begins timestamptz NOT NULL, ends timestamptz NOT NULL CHECK(ends > begins),
 note text NOT NULL, status text NOT NULL DEFAULT 'pending', reviewer integer REFERENCES employee,
 reason text, decided timestamptz
);
CREATE TABLE approval (
 employee integer NOT NULL REFERENCES employee, month text NOT NULL, fingerprint text NOT NULL,
 reviewer integer NOT NULL REFERENCES employee, note text NOT NULL, approved timestamptz DEFAULT now(),
 PRIMARY KEY(employee,month)
);
CREATE TABLE export (
 month text NOT NULL, version integer NOT NULL, content text NOT NULL, fingerprint text NOT NULL,
 creator integer NOT NULL REFERENCES employee, created timestamptz DEFAULT now(), PRIMARY KEY(month,version)
);
CREATE TABLE audit (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, actor integer REFERENCES employee,
 action text NOT NULL, details jsonb NOT NULL, created timestamptz DEFAULT now()
);
