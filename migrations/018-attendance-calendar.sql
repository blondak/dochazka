ALTER TABLE attendance_account DROP CONSTRAINT attendance_account_behavior_check,
    ADD CHECK (behavior IN ('work', 'timed_absence', 'shift_absence', 'break', 'calendar')),
    ADD CHECK (behavior <> 'calendar' OR NOT requires_proof);

CREATE TABLE attendance_holiday_account (
    effective_on date PRIMARY KEY,
    account text NOT NULL REFERENCES attendance_account(code)
);
CREATE TABLE attendance_calendar_day (
    day date PRIMARY KEY,
    name text NOT NULL,
    account text NOT NULL REFERENCES attendance_account(code)
);

-- Preserve an existing custom account even when the administrator used the suggested code.
DO $$
DECLARE
    selected_code text := 'SVATEK';
    suffix integer := 0;
BEGIN
    WHILE EXISTS (SELECT FROM attendance_account WHERE code = selected_code) LOOP
        suffix := suffix + 1;
        selected_code := 'SVATEK_' || suffix;
    END LOOP;
    INSERT INTO attendance_account (code, name, position, behavior, system)
        VALUES (selected_code, 'Svátek', 35, 'calendar', true);
    INSERT INTO attendance_account_payroll (account, effective_on, payroll_code)
        VALUES (selected_code, DATE '0001-01-01', selected_code);
    INSERT INTO attendance_holiday_account (effective_on, account) VALUES (DATE '0001-01-01', selected_code);
END $$;
