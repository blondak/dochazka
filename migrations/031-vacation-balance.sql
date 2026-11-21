CREATE TABLE vacation_balance (
    employee integer NOT NULL REFERENCES employee(id),
    effective_on date NOT NULL,
    seconds integer NOT NULL,
    PRIMARY KEY (employee,effective_on)
);
