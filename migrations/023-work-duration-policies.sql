CREATE TABLE attendance_account_rounding (
    account text NOT NULL REFERENCES attendance_account(code),
    effective_on date NOT NULL,
    mode text NOT NULL CHECK (mode IN ('off', 'down', 'up', 'nearest')),
    minutes integer NOT NULL CHECK (minutes BETWEEN 1 AND 1440),
    PRIMARY KEY (account, effective_on)
);

CREATE TABLE attendance_account_break (
    account text NOT NULL REFERENCES attendance_account(code),
    effective_on date NOT NULL,
    enabled boolean NOT NULL,
    minutes integer NOT NULL CHECK (minutes BETWEEN 30 AND 180),
    preferred integer NOT NULL CHECK (preferred BETWEEN 1 AND 360),
    deadline integer NOT NULL CHECK (deadline BETWEEN 1 AND 360 AND preferred <= deadline),
    PRIMARY KEY (account, effective_on)
);

CREATE TABLE recognized_work (
    employee integer NOT NULL REFERENCES employee(id),
    day date NOT NULL,
    account text NOT NULL REFERENCES attendance_account(code),
    begins timestamptz NOT NULL,
    ends timestamptz NOT NULL CHECK (ends > begins),
    seconds integer NOT NULL CHECK (seconds >= 0 AND seconds <= extract(epoch FROM ends - begins)),
    PRIMARY KEY (employee, day, account, begins, ends)
);

CREATE TABLE attendance_break_exception (
    employee integer NOT NULL REFERENCES employee(id),
    day date NOT NULL,
    PRIMARY KEY (employee, day)
);
