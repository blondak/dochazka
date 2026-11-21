ALTER TABLE approval ADD COLUMN reviewed_days jsonb;
CREATE TABLE fund_review_settings (
    singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton),
    tolerance_minutes integer NOT NULL CHECK (tolerance_minutes BETWEEN 0 AND 1439)
);
INSERT INTO fund_review_settings(tolerance_minutes) VALUES (15);
