CREATE TABLE manager_cover (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    manager integer NOT NULL REFERENCES employee(id),
    substitute integer NOT NULL REFERENCES employee(id),
    starts date NOT NULL, ends date NOT NULL,
    revoked boolean NOT NULL DEFAULT false,
    CHECK (starts <= ends AND manager <> substitute)
);
CREATE INDEX manager_cover_dates ON manager_cover(manager,starts,ends) WHERE NOT revoked;
