-- The journal is append-only. Track session bounds independently of delivery order
-- so that a month can load an entire overnight session without reading every year.
LOCK TABLE signal IN SHARE ROW EXCLUSIVE MODE;

CREATE TABLE signal_session (
    sid text NOT NULL,
    device integer NOT NULL REFERENCES device,
    session_key text NOT NULL,
    first_event timestamptz NOT NULL,
    last_event timestamptz NOT NULL,
    PRIMARY KEY (sid, device, session_key)
);

INSERT INTO signal_session (sid, device, session_key, first_event, last_event)
SELECT sid, device, session_key, min(happened), max(happened)
FROM signal GROUP BY sid, device, session_key;

CREATE INDEX signal_session_period ON signal_session USING gist (tstzrange(first_event, last_event, '[]'));
CREATE INDEX signal_session_events ON signal (sid, device, session_key, happened);

CREATE FUNCTION record_signal_bounds() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO signal_session (sid, device, session_key, first_event, last_event)
    VALUES (NEW.sid, NEW.device, NEW.session_key, NEW.happened, NEW.happened)
    ON CONFLICT (sid, device, session_key) DO UPDATE SET
        first_event = least(signal_session.first_event, EXCLUDED.first_event),
        last_event = greatest(signal_session.last_event, EXCLUDED.last_event);
    RETURN NULL;
END;
$$;

CREATE TRIGGER signal_inserted AFTER INSERT ON signal
FOR EACH ROW EXECUTE FUNCTION record_signal_bounds();
