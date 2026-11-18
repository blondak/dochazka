<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use Doctrine\DBAL\Connection;
use Dochazka\Audit\AuditTrail;
use Dochazka\Http\Problem;

final class Catalog
{
    public function __construct(private Connection $db, private AuditTrail $audit) {}

    public function accounts(): array
    {
        return array_column($this->db->createQueryBuilder()->select('*')->from('attendance_account')
            ->orderBy('position')->addOrderBy('code')->fetchAllAssociative(), null, 'code');
    }

    public function activeCode(mixed $code): string
    {
        if (!is_string($code) || !$this->db->createQueryBuilder()->select('code')->from('attendance_account')
            ->where('code = :code', 'active')->setParameter('code', $code)->fetchOne()) {
            throw new Problem('Vyberte aktivní konto.');
        }
        return $code;
    }

    public function payrollVersions(): array
    {
        $versions = [];
        $rows = $this->db->createQueryBuilder()->select('*')->from('attendance_account_payroll')
            ->orderBy('effective_on', 'DESC')->fetchAllAssociative();
        foreach ($rows as $row) $versions[$row['account']][] = $row;
        return $versions;
    }

    public function durationRules(string $type): array
    {
        $table = match ($type) {
            'rounding' => 'attendance_account_rounding',
            'break' => 'attendance_account_break',
        };
        $rules = [];
        foreach ($this->db->createQueryBuilder()->select('*')->from($table)->orderBy('effective_on', 'DESC')->fetchAllAssociative() as $row) {
            $rules[$row['account']][] = $row;
        }
        return $rules;
    }

    public function listing(bool $includeVersions): array
    {
        $accounts = $this->accounts();
        if ($includeVersions) {
            $payroll = $this->payrollVersions();
            $rounding = $this->durationRules('rounding');
            $breaks = $this->durationRules('break');
            foreach ($accounts as $code => &$account) {
                $account['payroll_versions'] = $payroll[$code] ?? [];
                $account['rounding_rules'] = $rounding[$code] ?? [];
                $account['break_rules'] = $breaks[$code] ?? [];
            }
            unset($account);
        }
        return array_values($accounts);
    }

    public function save(int $actor, array $input): array
    {
        $code = $input['code'] ?? '';
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $position = $input['position'] ?? null;
        $active = $input['active'] ?? null;
        $proof = $input['requires_proof'] ?? null;
        $behavior = $input['behavior'] ?? '';
        if (!is_string($code) || !preg_match('/^[A-Z][A-Z0-9_]{0,31}$/D', $code) || $name === ''
            || strlen($name) > 160 || strlen($description) > 2000 || str_contains($name.$description, "\0")
            || !is_int($position) || $position < 0 || $position > 9999 || !is_bool($active) || !is_bool($proof)) {
            throw new Problem('Zkontrolujte kód, název, popis a pořadí konta.');
        }
        $existing = $this->accounts()[$code] ?? null;
        if ($existing && $existing['system']) {
            $behavior = $existing['behavior'];
            $active = true;
            $proof = $existing['requires_proof'];
        } elseif (!in_array($behavior, ['work', 'timed_absence', 'shift_absence', 'calendar'], true)) {
            throw new Problem('Vyberte způsob započítání konta.');
        }
        if ($existing && $behavior !== $existing['behavior'] && $this->db->createQueryBuilder()->select('id')
            ->from('request')->where('kind = :kind')->setParameter('kind', $code)->setMaxResults(1)->fetchOne()) {
            throw new Problem('Způsob započítání použitého konta nelze změnit.', 409);
        }
        if ($existing && $existing['behavior'] !== $behavior) {
            foreach (['attendance_holiday_account', 'attendance_calendar_day', 'holiday_rule', 'generated_holiday_entry'] as $table) {
                if ($this->db->createQueryBuilder()->select('account')->from($table)
                    ->where('account = :account')->setParameter('account', $code)->setMaxResults(1)->fetchOne()) {
                    throw new Problem('Způsob započítání konta použitého v kalendáři nelze změnit.', 409);
                }
            }
        }
        $properties = ['name' => $name, 'description' => $description, 'position' => $position,
            'behavior' => $behavior, 'active' => $active, 'requires_proof' => $proof];
        $types = ['active' => \Doctrine\DBAL\ParameterType::BOOLEAN, 'requires_proof' => \Doctrine\DBAL\ParameterType::BOOLEAN];
        if ($existing) {
            $this->db->update('attendance_account', $properties, ['code' => $code], $types);
        } else {
            $this->db->insert('attendance_account', ['code' => $code] + $properties, $types);
            $this->db->insert('attendance_account_payroll', ['account' => $code, 'effective_on' => '0001-01-01', 'payroll_code' => $code]);
        }
        $version = null;
        if (isset($input['payroll_code'])) {
            $payrollCode = $input['payroll_code'];
            if (!is_string($payrollCode) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,39}$/D', $payrollCode)) {
                throw new Problem('Zadejte platný mzdový kód.');
            }
            $version = ['account' => $code, 'effective_on' => WorkModels::day($input['effective_on'] ?? '')->format('Y-m-d'),
                'payroll_code' => $payrollCode];
            $this->db->insert('attendance_account_payroll', $version);
        }
        $this->audit->audit($actor, 'attendance-account', ['code' => $code, 'before' => $existing, 'after' => $properties, 'payroll_version' => $version]);
        if (isset($input['rounding'])) {
            $rule = $input['rounding'];
            $mode = $rule['mode'] ?? '';
            $minutes = $rule['minutes'] ?? 1;
            if (!in_array($mode, ['off','down','up','nearest'], true) || !is_int($minutes) || $minutes < 1 || $minutes > 1440) {
                throw new Problem('Zkontrolujte krok a způsob zaokrouhlení.');
            }
            $this->saveDurationRule($actor, 'attendance_account_rounding', $code, $rule, compact('mode','minutes'));
        }
        if (isset($input['break_rule'])) {
            $rule = $input['break_rule'];
            $enabled = $rule['enabled'] ?? null;
            $minutes = $rule['minutes'] ?? 30;
            $preferred = $rule['preferred'] ?? 240;
            $deadline = $rule['deadline'] ?? 360;
            if ($behavior !== 'work' || !is_bool($enabled) || !is_int($minutes) || $minutes < 30 || $minutes > 180
                || !is_int($preferred) || !is_int($deadline) || $preferred < 1 || $preferred > $deadline || $deadline > 360) {
                throw new Problem('Zkontrolujte parametry přestávky.');
            }
            $this->saveDurationRule($actor, 'attendance_account_break', $code, $rule, compact('enabled','minutes','preferred','deadline'));
        }
        return ['ok' => true];
    }

    private function saveDurationRule(int $actor, string $table, string $account, array $rule, array $values): void
    {
        $key = ['account' => $account, 'effective_on' => WorkModels::day($rule['effective_on'] ?? '')->format('Y-m-d')];
        $before = $this->db->createQueryBuilder()->select('*')->from($table)
            ->where('account = :account', 'effective_on = :effective_on')->setParameters($key)->fetchAssociative();
        $types = isset($values['enabled']) ? ['enabled' => \Doctrine\DBAL\ParameterType::BOOLEAN] : [];
        if ($before) $this->db->update($table, $values, $key, $types);
        else $this->db->insert($table, $values + $key, $types);
        $this->audit->audit($actor, $table, $key + ['before' => $before ?: null, 'after' => $values]);
    }

    public static function behavior(string $code, array $accounts): string
    {
        return $accounts[$code]['behavior'] ?? match ($code) {
            'PRACE' => 'work', 'DOVOLENA' => 'shift_absence', 'LEKAR' => 'timed_absence', 'PRESTAVKA' => 'break',
        };
    }
}
