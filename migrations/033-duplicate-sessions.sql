ALTER TABLE signal_session ADD COLUMN duplicate_of text,
    ADD FOREIGN KEY (sid, device, duplicate_of) REFERENCES signal_session(sid,device,session_key),
    ADD CHECK (duplicate_of IS NULL OR (duplicate_of <> session_key AND service_at IS NULL));
CREATE INDEX signal_session_starts ON signal_session(first_event);
CREATE INDEX signal_session_kept ON signal_session(sid,device,duplicate_of) WHERE duplicate_of IS NOT NULL;

-- Every newly inserted signal updates the bounds, including events at an identical timestamp.
CREATE FUNCTION restore_duplicate_session() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO audit(action, details) VALUES ('duplicate-session-new-event',
        jsonb_build_object('sid', OLD.sid, 'device', OLD.device, 'session', OLD.session_key, 'kept', OLD.duplicate_of));
    NEW.duplicate_of := NULL;
    RETURN NEW;
END;
$$;
CREATE TRIGGER restore_duplicate_on_signal BEFORE UPDATE OF first_event,last_event ON signal_session
    FOR EACH ROW WHEN (OLD.duplicate_of IS NOT NULL) EXECUTE FUNCTION restore_duplicate_session();
