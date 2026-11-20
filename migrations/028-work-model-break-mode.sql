-- Preserve the historical fund, including shifts entered without a break.
UPDATE work_model_version AS v SET week = (
    SELECT jsonb_agg(CASE WHEN jsonb_typeof(shift) = 'object' AND NOT (shift ? 'break_mode')
        THEN shift || '{"break_mode":"fixed"}'::jsonb ELSE shift END ORDER BY position)
    FROM jsonb_array_elements(v.week) WITH ORDINALITY AS days(shift, position)
);
