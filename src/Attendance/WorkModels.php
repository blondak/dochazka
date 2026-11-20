<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use DateTimeImmutable as Date;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Dochazka\Audit\AuditTrail;
use Dochazka\Http\Problem;

final class WorkModels
{
    public function __construct(private Connection $db, private AuditTrail $audit, private AttendanceCalendar $calendar, private Catalog $catalog) {}

    public static function day(string $input): Date
    {
        $date = Date::createFromFormat('!Y-m-d', $input, new DateTimeZone('Europe/Prague'));
        if (!$date || $date->format('Y-m-d') !== $input || $date->format('Y') === '0000') {
            throw new Problem('Zadejte platné datum.');
        }
        return $date;
    }

    public function listing(): array
    {
        $models = $this->db->createQueryBuilder()->select('id', 'name')->from('work_model')->orderBy('name')
            ->fetchAllAssociativeIndexed();
        $versions = $this->db->createQueryBuilder()->select('*')->from('work_model_version')
            ->orderBy('effective_on', 'DESC')->fetchAllAssociative();
        $rules = $this->catalog->durationRules('break')['PRACE'] ?? [];
        $today = (new Date('today', new DateTimeZone('Europe/Prague')))->format('Y-m-d');
        // Use a neutral date for the nominal weekly fund, independent of DST transitions.
        $nominalDay = new Date('2000-01-03', new DateTimeZone('UTC'));
        foreach ($versions as $version) {
            $week = json_decode($version['week'], true, flags: JSON_THROW_ON_ERROR);
            $rule = DurationRules::forDay($rules, max($today, $version['effective_on']));
            $seconds = 0;
            foreach ($week as $shift) {
                if ($shift === null) continue;
                foreach (self::shiftRanges($nominalDay, $shift, $rule) as [$from, $to]) $seconds += $to - $from;
            }
            $models[$version['model']]['versions'][] = [
                'effective_on' => $version['effective_on'], 'week' => $week, 'weekly_seconds' => $seconds,
            ];
        }
        return array_map(static fn($id, $model) => ['id' => $id] + $model + ['versions' => []],
            array_keys($models), array_values($models));
    }

    public function assignments(int $employee): array
    {
        return $this->db->createQueryBuilder()->select('model', 'effective_on')->from('employee_work_model')
            ->where('employee = :employee')->setParameter('employee', $employee)->orderBy('effective_on', 'DESC')->fetchAllAssociative();
    }

    public function preview(int $actor, array $input): array
    {
        $this->db->beginTransaction();
        try {
            $change = isset($input['employee'])
                ? $this->assignmentChange($actor, $input['person'], $input, true)
                : $this->modelChange($actor, $input, true);
        } finally {
            $this->db->rollBack();
        }
        return $change;
    }

    public function save(int $actor, array $input): array
    {
        return $this->modelChange($actor, $input, false);
    }

    private function modelChange(int $actor, array $input, bool $preview): array
    {
        $id = $input['id'] ?? null;
        $name = trim($input['name'] ?? '');
        if ($name === '' || strlen($name) > 160 || str_contains($name, "\0")
            || ($id !== null && (!is_int($id) || !$this->db->createQueryBuilder()->select('id')
                ->from('work_model')->where('id = :id')->setParameter('id', $id)->fetchOne()))) {
            throw new Problem('Zadejte název platného pracovního modelu.');
        }
        $week = $input['week'] ?? null;
        if (!is_array($week) || !array_is_list($week) || count($week) !== 7) {
            throw new Problem('Nastavte všech sedm dnů týdne.');
        }
        foreach ($week as &$shift) {
            if ($shift === null) continue;
            if (!is_array($shift)) throw new Problem('Zadejte směnu nebo volno.');
            $start = self::time($shift['start'] ?? '', false);
            $end = self::time($shift['end'] ?? '', true);
            $breakStart = isset($shift['break_start']) ? self::time($shift['break_start'], false) : null;
            $breakEnd = isset($shift['break_end']) ? self::time($shift['break_end'], true) : null;
            if ($start >= $end || ($breakStart === null) !== ($breakEnd === null)
                || ($breakStart !== null && !($start < $breakStart && $breakStart < $breakEnd && $breakEnd < $end))) {
                throw new Problem('Přestávka musí celá ležet uvnitř směny a konec musí následovat po začátku.');
            }
            $mode = $shift['break_mode'] ?? 'fixed';
            if (!in_array($mode, ['account', 'fixed'], true) || ($mode === 'account' && $breakStart !== null)) {
                throw new Problem('Zkontrolujte způsob určení přestávky.');
            }
            $shift = ['break_mode' => $mode, 'start' => $start, 'end' => $end, 'break_start' => $breakStart, 'break_end' => $breakEnd];
        }
        unset($shift);
        $effective = self::day($input['effective_on'] ?? '')->format('Y-m-d');
        $original = isset($input['original_effective_on']) ? self::day($input['original_effective_on'])->format('Y-m-d') : null;
        $before = $original === null ? null : $this->db->createQueryBuilder()->select('v.week', 'm.name')
            ->from('work_model_version', 'v')->innerJoin('v', 'work_model', 'm', 'm.id = v.model')
            ->where('v.model = :model', 'v.effective_on = :day')->setParameter('model', $id)
            ->setParameter('day', $original)->fetchAssociative();
        if ($before === false) throw new Problem('Verze modelu již neexistuje.', 409);
        $first = min($effective, $original ?? $effective);
        $from = self::day($first);
        $pastChange = $id !== null && $from < new Date('today', $from->getTimezone());
        $scope = $pastChange ? $this->impactMonths($first, $id, null) : [];
        $previous = $this->impactState($scope);
        if ($id === null) {
            $id = (int) $this->db->executeQuery('INSERT INTO work_model (name) VALUES (?) RETURNING id', [$name])->fetchOne();
        } else {
            $this->db->update('work_model', ['name' => $name], ['id' => $id]);
        }
        $values = ['effective_on' => $effective, 'week' => json_encode($week, JSON_THROW_ON_ERROR)];
        if ($original === null) $this->db->insert('work_model_version', ['model' => $id] + $values);
        else $this->db->update('work_model_version', $values, ['model' => $id, 'effective_on' => $original]);
        if ($this->db->createQueryBuilder()->select('a.employee')->from('employee_work_model', 'a')
            ->where('a.model = :model', 'NOT EXISTS (SELECT 1 FROM work_model_version v WHERE v.model = a.model AND v.effective_on <= a.effective_on)')
            ->setParameter('model', $id)->setMaxResults(1)->fetchOne() !== false) {
            throw new Problem('Přiřazení zaměstnance by zůstalo bez verze modelu.', 409);
        }
        $change = ['model' => $id, 'name' => $name, 'before' => $before, 'original_effective_on' => $original,
            'effective_on' => $effective, 'week' => $week];
        return $this->finishChange($actor, 'work-model-version', $change, $input, $previous, $scope, $preview,
            $original !== null || ($pastChange && (bool) $scope)) + ['id' => $id];
    }

    public function assign(int $actor, array $person, array $input): array
    {
        return $this->assignmentChange($actor, $person, $input, false);
    }

    private function assignmentChange(int $actor, array $person, array $input, bool $preview): array
    {
        $effective = self::day($input['effective_on'] ?? '')->format('Y-m-d');
        $id = $input['model'] ?? null;
        if (!$person['attendance_enabled'] || $effective < $person['starts'] || ($person['ends'] && $effective > $person['ends'])) {
            throw new Problem('Účinnost musí spadat do období evidence zaměstnance.');
        }
        if (!is_int($id) || !$this->db->createQueryBuilder()->select('model')->from('work_model_version')
            ->where('model = :id', 'effective_on <= :effective')->setParameter('id', $id)
            ->setParameter('effective', $effective)->setMaxResults(1)->fetchOne()) {
            throw new Problem('K datu přiřazení musí existovat platná verze pracovního modelu.');
        }
        $original = isset($input['original_effective_on']) ? self::day($input['original_effective_on'])->format('Y-m-d') : null;
        $before = $original === null ? null : $this->db->createQueryBuilder()->select('model')->from('employee_work_model')
            ->where('employee = :employee', 'effective_on = :day')->setParameter('employee', $person['id'])
            ->setParameter('day', $original)->fetchOne();
        if ($before === false) throw new Problem('Přiřazení již neexistuje.', 409);
        $first = min($effective, $original ?? $effective);
        $scope = $first < (new Date('today', new DateTimeZone('Europe/Prague')))->format('Y-m-d')
            ? $this->impactMonths($first, null, $person['id']) : [];
        $previous = $this->impactState($scope);
        $assignment = ['model' => $id, 'effective_on' => $effective, 'employee' => $person['id']];
        if ($original === null) $this->db->insert('employee_work_model', $assignment);
        else $this->db->update('employee_work_model', $assignment, ['employee' => $person['id'], 'effective_on' => $original]);
        return $this->finishChange($actor, 'work-model-assignment', $assignment + ['before' => $before, 'original_effective_on' => $original],
            $input, $previous, $scope, $preview, $original !== null || (bool) $scope);
    }

    private function impactMonths(string $first, ?int $model, ?int $employee): array
    {
        if ($employee === null) {
            $people = $this->db->createQueryBuilder()->select('DISTINCT employee')->from('employee_work_model')
                ->where('model = :model')->setParameter('model', $model)->fetchFirstColumn();
        } else {
            $people = [$employee];
        }
        $scope = [];
        foreach ($people as $id) {
            $months = $this->db->fetchFirstColumn(<<<'SQL'
                SELECT month FROM approval WHERE employee = :employee AND month >= :month
                UNION SELECT to_char(day, 'YYYY-MM') FROM generated_holiday_entry WHERE employee = :employee AND day >= :first
                UNION SELECT to_char(d, 'YYYY-MM') FROM request r
                    CROSS JOIN LATERAL generate_series(date_trunc('month', greatest(r.begins, CAST(:first AS timestamptz))),
                        date_trunc('month', r.ends - interval '1 microsecond'), interval '1 month') d
                    WHERE r.employee = :employee AND r.status IN ('pending','approved') AND r.ends > :first
                UNION SELECT to_char(s.happened, 'YYYY-MM') FROM signal s JOIN employee e USING (sid)
                    WHERE e.id = :employee AND s.happened >= :first
                ORDER BY 1
                SQL, ['employee' => $id, 'first' => $first, 'month' => substr($first,0,7)]);
            foreach ($months as $month) $scope[] = [(int) $id, $month];
        }
        sort($scope);
        return $scope;
    }

    private function impactState(array $scope): array
    {
        return array_map(function (array $item): array {
            [$employee, $month] = $item;
            $start = self::day($month.'-01');
            return $this->plan($employee, $start, $start->modify('+1 month'));
        }, $scope);
    }

    private function finishChange(int $actor, string $action, array $change, array $input, array $previous, array $scope,
        bool $preview, bool $requiresConfirmation): array
    {
        $after = $this->impactState($scope);
        $impact = [];
        foreach ($scope as $index => [$employee, $month]) {
            if ($after[$index] === $previous[$index]) continue;
            $person = $this->db->createQueryBuilder()->select('name')->from('employee')->where('id = :id')
                ->setParameter('id', $employee)->fetchOne();
            $requests = $this->db->createQueryBuilder()->select('id','kind','begins','ends')->from('request')
                ->where('employee = :id', "status IN ('pending','approved')", 'begins < :end', 'ends > :start')
                ->setParameter('id', $employee)->setParameter('start', $month.'-01')
                ->setParameter('end', self::day($month.'-01')->modify('+1 month')->format('Y-m-d'))->orderBy('id')->fetchAllAssociative();
            $impact[] = ['employee' => $employee, 'name' => $person, 'month' => $month, 'requests' => $requests];
        }
        $token = hash('sha256', json_encode([$change, $impact, $previous, $after], JSON_THROW_ON_ERROR));
        if ($preview) return ['token' => $token, 'impact' => $impact];
        $reason = trim($input['reason'] ?? '');
        if ($requiresConfirmation && ($reason === '' || ($input['preview_token'] ?? '') !== $token)) {
            throw new Problem('Uveďte důvod a potvrďte aktuální náhled dopadů.', 409);
        }
        foreach ($impact as $item) {
            $this->db->executeStatement(
                'INSERT INTO work_model_impact(employee, month, revision) VALUES (?, ?, 1)
                ON CONFLICT (employee, month) DO UPDATE SET revision = work_model_impact.revision + 1',
                [$item['employee'], $item['month']],
            );
        }
        $this->audit->audit($actor, $action, $change + ['reason' => $reason, 'impact' => $impact]);
        return ['ok' => true, 'impact' => $impact];
    }

    /** Missing day means the employee has no model. An empty interval list means a scheduled day off. */
    public function schedule(int $employee, Date $from, Date $until): array
    {
        return $this->plan($employee, $from, $until, includeUnassignedDays: false)['schedule'];
    }

    /** Chargeable working shifts, calendar credits and display names use the same dated calendar and model. */
    public function plan(int $employee, Date $from, Date $until, bool $includeUnassignedDays = true): array
    {
        $schedule = $credits = $calendarDays = [];
        if ($from >= $until) return compact('schedule', 'credits', 'calendarDays');
        $zone = new DateTimeZone('Europe/Prague');
        $from = $from->setTimezone($zone);
        $until = $until->setTimezone($zone);
        $revisions = $this->db->createQueryBuilder()->select('a.effective_on AS assignment', 'v.effective_on', 'v.week')
            ->from('employee_work_model', 'a')->innerJoin('a', 'work_model_version', 'v', 'v.model = a.model')
            ->where('a.employee = :employee', 'a.effective_on <= :last_day', 'v.effective_on <= :last_day')
            ->setParameter('employee', $employee)->setParameter('last_day', $until->modify('-1 microsecond')->format('Y-m-d'))
            ->orderBy('a.effective_on', 'DESC')->addOrderBy('v.effective_on', 'DESC')->fetchAllAssociative();
        if ($revisions || $includeUnassignedDays) $calendarDays = $this->calendar->days($from, $until);
        if (!$revisions) return compact('schedule', 'credits', 'calendarDays');
        $rules = $this->catalog->durationRules('break')['PRACE'] ?? [];
        foreach ($revisions as &$revision) $revision['week'] = json_decode($revision['week'], true, flags: JSON_THROW_ON_ERROR);
        unset($revision);
        for ($date = $from->setTime(0, 0); $date < $until; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            foreach ($revisions as $revision) {
                if ($revision['assignment'] > $key || $revision['effective_on'] > $key) continue;
                $shift = $revision['week'][(int) $date->format('N') - 1];
                $ranges = $shift ? self::shiftRanges($date, $shift, DurationRules::forDay($rules, $key)) : [];
                if (isset($calendarDays[$key])) {
                    $schedule[$key] = [];
                    if ($ranges) {
                        $credits[$key] = ['account' => $calendarDays[$key]['account'], 'ranges' => $ranges];
                    }
                } else {
                    $schedule[$key] = $ranges;
                }
                break;
            }
        }
        return compact('schedule', 'credits', 'calendarDays');
    }

    public function chargeableShifts(int $employee, Date $start, Date $end): array
    {
        $schedule = $this->schedule($employee, $start, $end);
        if (count($schedule) !== $start->diff($end)->days) {
            throw new Problem('Pro všechny dny musí být přiřazen pracovní model.', 409);
        }
        $ranges = array_merge([], ...array_values($schedule));
        if (!$ranges) throw new Problem('Ve vybraném období není žádná směna k čerpání.');
        return $ranges;
    }

    public static function shiftRanges(Date $date, array $shift, ?array $rule = null): array
    {
        $time = static fn(string $clock) => $date->setTime((int) substr($clock, 0, 2), (int) substr($clock, 3))->getTimestamp();
        $start = $time($shift['start']);
        $end = $time($shift['end']);
        if (($shift['break_mode'] ?? 'fixed') === 'account' && ($rule['enabled'] ?? false)) {
            $breaks = DurationRules::proposeBreaks([[$start, $end]], [], $rule['minutes'] * 60, $rule['preferred'] * 60, $rule['deadline'] * 60);
            $ranges = [];
            foreach ($breaks as [$from, $to]) {
                $ranges[] = [$start, $from];
                $start = $to;
            }
            $ranges[] = [$start, $end];
            return $ranges;
        }
        return $shift['break_start'] === null ? [[$start, $end]]
            : [[$start, $time($shift['break_start'])], [$time($shift['break_end']), $end]];
    }

    private static function time(string $time, bool $allowMidnight): string
    {
        if (($allowMidnight && $time === '24:00') || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) return $time;
        throw new Problem('Čas směny musí mít formát HH:MM.');
    }
}
