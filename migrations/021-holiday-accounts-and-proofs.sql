ALTER TABLE holiday_rule ADD COLUMN account text REFERENCES attendance_account(code);
ALTER TABLE attendance_account DROP CONSTRAINT attendance_account_check;
ALTER TABLE request ADD COLUMN generated_holiday boolean NOT NULL DEFAULT false;
ALTER TABLE generated_holiday_entry ADD COLUMN request_id bigint UNIQUE REFERENCES request(id);
