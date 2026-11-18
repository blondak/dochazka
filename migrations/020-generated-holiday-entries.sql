CREATE TABLE generated_holiday_entry (
    employee integer NOT NULL REFERENCES employee(id),
    day date NOT NULL,
    account text NOT NULL REFERENCES attendance_account(code),
    ranges jsonb NOT NULL CHECK (jsonb_typeof(ranges) = 'array' AND jsonb_array_length(ranges) > 0),
    PRIMARY KEY (employee, day)
);
CREATE INDEX generated_holiday_entry_day ON generated_holiday_entry (day);
