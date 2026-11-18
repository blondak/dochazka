ALTER TABLE request
    ADD COLUMN vacation_estimate jsonb
        CHECK (vacation_estimate IS NULL OR (vacation_seconds IS NOT NULL AND jsonb_typeof(vacation_estimate) = 'array'));
