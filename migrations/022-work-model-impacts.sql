CREATE TABLE work_model_impact (
    employee integer NOT NULL REFERENCES employee(id),
    month text NOT NULL,
    revision bigint NOT NULL CHECK (revision > 0),
    PRIMARY KEY (employee, month)
);
