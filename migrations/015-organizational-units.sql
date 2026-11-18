CREATE TABLE org_unit (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL,
    parent_id integer REFERENCES org_unit(id) ON DELETE RESTRICT,
    CHECK (parent_id <> id),
    UNIQUE NULLS NOT DISTINCT (parent_id, name)
);
INSERT INTO org_unit (name) SELECT DISTINCT team FROM employee;
ALTER TABLE employee ADD COLUMN org_unit_id integer REFERENCES org_unit(id) ON DELETE RESTRICT;
UPDATE employee e SET org_unit_id = u.id FROM org_unit u WHERE u.name = e.team;
ALTER TABLE employee ALTER COLUMN org_unit_id SET NOT NULL;
CREATE INDEX employee_org_unit_idx ON employee(org_unit_id);
ALTER TABLE employee DROP COLUMN team;

CREATE VIEW org_unit_tree AS
WITH RECURSIVE tree(id, name, parent_id, path, ancestors) AS (
    SELECT id, name, parent_id, name, ARRAY[id] FROM org_unit WHERE parent_id IS NULL
    UNION ALL
    SELECT u.id, u.name, u.parent_id, t.path || ' › ' || u.name, t.ancestors || u.id
    FROM org_unit u JOIN tree t ON t.id = u.parent_id
)
SELECT * FROM tree;
