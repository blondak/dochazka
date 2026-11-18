ALTER TABLE request
    ADD COLUMN replaces_from timestamptz,
    ADD COLUMN replaces_to timestamptz,
    ADD CHECK (
        (replaces_from IS NULL AND replaces_to IS NULL)
        OR (replaces_from IS NOT NULL AND replaces_to > replaces_from AND replaces_to IS NOT NULL
            AND kind = 'PRACE' AND NOT merged_work)
    );
