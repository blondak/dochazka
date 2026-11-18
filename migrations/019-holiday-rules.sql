CREATE TABLE holiday_rule (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code text NOT NULL CHECK (code ~ '^[A-Z][A-Z0-9_]{0,39}$'),
    name text NOT NULL CHECK (length(trim(name)) > 0),
    valid_from date NOT NULL,
    valid_to date CHECK (valid_to >= valid_from),
    month smallint,
    day smallint,
    easter_offset smallint,
    CHECK (
        (month IS NOT NULL AND day IS NOT NULL AND month BETWEEN 1 AND 12 AND day BETWEEN 1 AND 31 AND easter_offset IS NULL)
        OR (month IS NULL AND day IS NULL AND easter_offset IS NOT NULL AND easter_offset BETWEEN -366 AND 366)
    ),
    UNIQUE(code, valid_from)
);

-- 245/2000 Sb. effective 2000-08-09. Name changes from 101/2004 Sb. and 48/2019 Sb.
INSERT INTO holiday_rule(code, name, valid_from, valid_to, month, day) VALUES
    ('NOVY_ROK', 'Nový rok a Den obnovy samostatného českého státu', '2000-08-09', NULL, 1, 1),
    ('SVATEK_PRACE', 'Svátek práce', '2000-08-09', NULL, 5, 1),
    ('DEN_VITEZSTVI', 'Den osvobození', '2000-08-09', '2004-03-04', 5, 8),
    ('DEN_VITEZSTVI', 'Den vítězství', '2004-03-05', NULL, 5, 8),
    ('CYRIL_METODEJ', 'Den slovanských věrozvěstů Cyrila a Metoděje', '2000-08-09', NULL, 7, 5),
    ('JAN_HUS', 'Den upálení mistra Jana Husa', '2000-08-09', NULL, 7, 6),
    ('CESKA_STATNOST', 'Den české státnosti', '2000-08-09', NULL, 9, 28),
    ('VZNIK_CESKOSLOVENSKA', 'Den vzniku samostatného československého státu', '2000-08-09', NULL, 10, 28),
    ('SEDMNACTY_LISTOPAD', 'Den boje za svobodu a demokracii', '2000-08-09', '2019-02-14', 11, 17),
    ('SEDMNACTY_LISTOPAD', 'Den boje za svobodu a demokracii a Mezinárodní den studentstva', '2019-02-15', NULL, 11, 17),
    ('STEDRY_DEN', 'Štědrý den', '2000-08-09', NULL, 12, 24),
    ('PRVNI_SVATEK_VANOCNI', '1. svátek vánoční', '2000-08-09', NULL, 12, 25),
    ('DRUHY_SVATEK_VANOCNI', '2. svátek vánoční', '2000-08-09', NULL, 12, 26);
INSERT INTO holiday_rule(code, name, valid_from, easter_offset) VALUES
    ('VELIKONOCNI_PONDELI', 'Velikonoční pondělí', '2000-08-09', 1),
    ('VELKY_PATEK', 'Velký pátek', '2015-12-21', -2);
