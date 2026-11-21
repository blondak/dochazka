<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use DateTimeImmutable as Date;
use Doctrine\DBAL\Connection;

final class HolidayEntries
{
    private const EMPTY_RESULT = [
        'added' => 0, 'changed' => 0, 'removed' => 0,
        'skipped' => ['missing_model' => 0, 'without_shift' => 0, 'occupied' => 0, 'unchanged' => 0],
    ];

    public function __construct(private Connection $db, private WorkModels $models) {}

    /** Return only stored entries still matching the employee's current plan and actual evidence. */
    public function visible(int $employee, ?string $sid, Date $from, Date $until, array $candidates): array
    {
        if (!$candidates) return [];
        $saved = $this->stored($employee, $from, $until);
        $possible = array_intersect_key($candidates, $saved);
        $visible = [];
        foreach (array_diff_key($possible, $this->occupiedDays($employee, $sid, array_keys($possible))) as $day => $entry) {
            if ($saved[$day]['account'] === $entry['account'] && $saved[$day]['ranges'] === $entry['ranges']) {
                $visible[$day] = $saved[$day];
            }
        }
        return $visible;
    }

    public function proofVisible(int $request): bool
    {
        $row = $this->db->createQueryBuilder()->select('g.employee', 'g.day', 'e.sid')
            ->from('generated_holiday_entry', 'g')->innerJoin('g', 'employee', 'e', 'e.id = g.employee')
            ->where('g.request_id = :request', 'e.attendance_enabled', 'e.starts <= g.day',
                '(LEAST(e.ends, e.archived_on) IS NULL OR LEAST(e.ends, e.archived_on) >= g.day)')
            ->setParameter('request', $request)->fetchAssociative();
        if (!$row) return false;
        $from = WorkModels::day($row['day']);
        $until = $from->modify('+1 day');
        $plan = $this->models->plan($row['employee'], $from, $until);
        return (bool) $this->visible($row['employee'], $row['sid'], $from, $until, $plan['credits']);
    }

    public function reconcileExisting(array $impact, int $actor): void
    {
        foreach ($impact as $item) {
            $from = WorkModels::day($item['month'].'-01');
            $until = $from->modify('+1 month');
            $days = array_keys($this->stored($item['employee'], $from, $until));
            $details = null;
            foreach ($days as $day) {
                $date = WorkModels::day($day);
                $this->reconcile($item['employee'], $date, $date->modify('+1 day'), false, $actor, $details);
            }
        }
    }

    public function reconcileBreakRule(string $effective, int $actor): void
    {
        $next = $this->db->createQueryBuilder()->select('min(effective_on)')->from('attendance_account_break')
            ->where("account = 'PRACE'", 'effective_on > :effective')->setParameter('effective', $effective)->fetchOne();
        $entries = $this->db->createQueryBuilder()->select('employee', 'day')->from('generated_holiday_entry')
            ->where('day >= :effective')->setParameter('effective', $effective);
        if ($next) $entries->andWhere('day < :next')->setParameter('next', $next);
        $details = null;
        foreach ($entries->fetchAllAssociative() as $entry) {
            $day = WorkModels::day($entry['day']);
            $this->reconcile($entry['employee'], $day, $day->modify('+1 day'), false, $actor, $details);
        }
    }

    /** Reconcile each employee in one transaction per month, using the same lock as API writers. */
    public function fill(Date $from, Date $until, bool $dryRun, ?array $scope = null, ?int $actor = null, ?array &$details = null): array
    {
        $counts = self::EMPTY_RESULT;
        if ($scope === []) return $counts;
        for ($first = $from; $first < $until; $first = $end) {
            $end = min($first->modify('first day of next month'), $until);
            $query = $this->db->createQueryBuilder()->select('id')->from('employee')
                ->where('((attendance_enabled AND starts < :end AND (LEAST(ends, archived_on) IS NULL OR LEAST(ends, archived_on) >= :first)) OR id IN (SELECT employee FROM generated_holiday_entry WHERE day >= :first AND day < :end))')
                ->setParameter('first', $first->format('Y-m-d'))->setParameter('end', $end->format('Y-m-d'))
                ->orderBy('id');
            if ($scope !== null) {
                $query->andWhere('id IN (:scope)')->setParameter('scope', $scope, \Doctrine\DBAL\ArrayParameterType::INTEGER);
            }
            foreach ($query->fetchFirstColumn() as $id) {
                $changes = $this->db->transactional(function () use ($id, $first, $end, $dryRun, $actor, &$details): array {
                    $this->db->executeStatement('SELECT pg_advisory_xact_lock(718312)');
                    return $this->reconcile((int) $id, $first, $end, $dryRun, $actor, $details);
                });
                foreach (['added', 'changed', 'removed'] as $key) $counts[$key] += $changes[$key];
                foreach ($changes['skipped'] as $key => $value) $counts['skipped'][$key] += $value;
            }
        }
        return $counts;
    }

    private function reconcile(int $employee, Date $from, Date $until, bool $dryRun, ?int $actor, ?array &$details): array
    {
        $person = $this->db->createQueryBuilder()->select('attendance_enabled', 'starts', 'LEAST(ends, archived_on) AS last_day', 'sid')
            ->from('employee')->where('id = :id')->setParameter('id', $employee)->fetchAssociative();
        $counts = self::EMPTY_RESULT;
        $wanted = [];
        if ($person && $person['attendance_enabled']) {
            $start = max($from, WorkModels::day($person['starts']));
            $end = $person['last_day'] ? min($until, WorkModels::day($person['last_day'])->modify('+1 day')) : $until;
            $plan = $this->models->plan($employee, $start, $end);
            $occupied = $this->occupiedDays($employee, $person['sid'], array_keys($plan['calendarDays']));
            $wanted = array_diff_key($plan['credits'], $occupied);
            $withoutCredit = array_diff_key($plan['calendarDays'], $plan['credits']);
            $counts['skipped']['missing_model'] = count(array_diff_key($withoutCredit, $plan['schedule']));
            $counts['skipped']['without_shift'] = count(array_intersect_key($withoutCredit, $plan['schedule']));
            $counts['skipped']['occupied'] = count($occupied);
        }
        $saved = $this->stored($employee, $from, $until);
        foreach (array_unique(array_merge(array_keys($saved), array_keys($wanted))) as $day) {
            $before = $saved[$day] ?? null;
            $after = $wanted[$day] ?? null;
            if ($before && $after && $before['account'] === $after['account'] && $before['ranges'] === $after['ranges']) {
                $counts['skipped']['unchanged']++;
                continue;
            }
            $key = ['employee' => $employee, 'day' => $day];
            $change = $after === null ? 'removed' : ($before === null ? 'added' : 'changed');
            $counts[$change]++;
            if ($details !== null) $details[] = $key + ['action' => $change, 'before' => $before, 'after' => $after];
            if ($dryRun) continue;
            $requestId = $before['request_id'] ?? null;
            if ($requestId !== null && ($after === null || $after['account'] !== $before['account'])) {
                $this->db->update('request', ['status' => 'rejected', 'reason' => 'Automatický zápis již není účinný.'], ['id' => $requestId]);
                $requestId = null;
            }
            if ($after === null) {
                $this->db->delete('generated_holiday_entry', $key);
            } else {
                $first = $after['ranges'][0][0];
                $last = $after['ranges'][array_key_last($after['ranges'])][1];
                $times = ['begins' => gmdate(DATE_ATOM, $first), 'ends' => gmdate(DATE_ATOM, $last)];
                if ($requestId !== null) {
                    $this->db->update('request', $times, ['id' => $requestId]);
                } elseif ((!$before || $before['account'] !== $after['account']) && $this->db->createQueryBuilder()
                    ->select('requires_proof')->from('attendance_account')->where('code = :code')
                    ->setParameter('code', $after['account'])->fetchOne()) {
                    $this->db->insert('request', $times + ['employee' => $employee, 'kind' => $after['account'],
                        'status' => 'approved', 'note' => '', 'reason' => 'Automaticky doplněno z kalendáře.',
                        'decided' => gmdate(DATE_ATOM), 'generated_holiday' => 'true', 'requires_proof' => 'true']);
                    $requestId = (int) $this->db->lastInsertId();
                }
                $data = ['account' => $after['account'], 'ranges' => json_encode($after['ranges'], JSON_THROW_ON_ERROR), 'request_id' => $requestId];
                if ($before === null) $this->db->insert('generated_holiday_entry', $key + $data);
                else $this->db->update('generated_holiday_entry', $data, $key);
            }
            $this->db->insert('audit', ['actor' => $actor, 'action' => 'holiday-entry-'.$change,
                'details' => json_encode($key + ['before' => $before, 'after' => $after], JSON_THROW_ON_ERROR)]);
        }
        return $counts;
    }

    private function stored(int $employee, Date $from, Date $until): array
    {
        $entries = $this->db->createQueryBuilder()->select('day', 'account', 'ranges', 'request_id')->from('generated_holiday_entry')
            ->where('employee = :id', 'day >= :first', 'day < :end')->setParameter('id', $employee)
            ->setParameter('first', $from->format('Y-m-d'))->setParameter('end', $until->format('Y-m-d'))
            ->fetchAllAssociativeIndexed();
        foreach ($entries as &$entry) $entry['ranges'] = json_decode($entry['ranges'], true, flags: JSON_THROW_ON_ERROR);
        unset($entry);
        return $entries;
    }

    private function occupiedDays(int $employee, ?string $sid, array $days): array
    {
        if (!$days) return [];
        // A shift absence already skips holidays.
        $occupied = $this->db->executeQuery(<<<'SQL'
            WITH days AS (SELECT value::date AS day FROM jsonb_array_elements_text(CAST(:days AS jsonb)))
            SELECT day FROM days d WHERE EXISTS (
                SELECT FROM request r JOIN attendance_account a ON a.code = r.kind
                WHERE r.employee = :employee AND NOT r.generated_holiday AND r.status IN ('pending','approved') AND a.behavior <> 'shift_absence'
                AND r.begins < (d.day + 1)::timestamptz AND r.ends > d.day::timestamptz
            ) OR EXISTS (
                SELECT FROM signal_session s WHERE s.sid = :sid AND s.service_at IS NULL AND s.duplicate_of IS NULL
                AND tstzrange(s.first_event, s.last_event, '[]') && tstzrange(d.day::timestamptz, (d.day + 1)::timestamptz, '[)')
            )
            SQL, ['days' => json_encode($days, JSON_THROW_ON_ERROR), 'employee' => $employee, 'sid' => $sid])
            ->fetchFirstColumn();
        return array_flip($occupied);
    }
}
