ALTER TABLE attendance_break_exception
    ADD COLUMN mode text NOT NULL DEFAULT 'none' CHECK (mode IN ('none','ack'));
