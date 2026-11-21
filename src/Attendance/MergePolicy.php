<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Dochazka\Audit\AuditTrail;
use Dochazka\Http\Problem;
use Dochazka\Identity\Accounts;

final class MergePolicy
{
    public function __construct(private Connection $db, private Accounts $accounts, private AuditTrail $audit) {}

    public function fundTolerance(): int
    {
        return (int) $this->db->fetchOne('SELECT tolerance_minutes FROM fund_review_settings WHERE singleton');
    }

    public function settings(): array
    {
        $this->accounts->actor('admin');
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Prague'));
        return ['fund_tolerance' => $this->fundTolerance(), 'gap_minutes' => $this->limits($today, $today->modify('+1 day'))[$today->format('Y-m-d')] ?? 0];
    }

    public function change(array $input): array
    {
        $actor = $this->accounts->actor('admin');
        $minutes = $input['gap_minutes'] ?? null;
        if (!is_int($minutes) || $minutes < 0 || $minutes >= 1440) {
            throw new Problem('Zadejte limit od 0 do 1439 minut.');
        }
        if (isset($input['fund_tolerance'])) {
            $tolerance = $input['fund_tolerance'];
            if (!is_int($tolerance) || $tolerance < 0 || $tolerance >= 1440) throw new Problem('Zadejte toleranci v minutách od 0 do 1439.');
            $this->db->update('fund_review_settings', ['tolerance_minutes' => $tolerance], ['singleton' => true]);
            $this->audit->audit($actor['id'], 'fund-tolerance', ['minutes' => $tolerance]);
        }
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Prague'));
        $day = $today->format('Y-m-d');
        $this->db->executeStatement(
            'INSERT INTO attendance_merge_limit (effective_on, gap_minutes) VALUES (?, ?)
             ON CONFLICT (effective_on) DO UPDATE SET gap_minutes = EXCLUDED.gap_minutes',
            [$day, $minutes],
        );
        $this->audit->audit($actor['id'], 'attendance-merge-limit', ['effective_on' => $day, 'gap_minutes' => $minutes]);
        return ['gap_minutes' => $minutes];
    }

    /** Gap limits in whole minutes, by local date. */
    public function limits(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $start = $start->setTime(0, 0);
        if ($end <= $start) return [];
        $firstDay = $start->format('Y-m-d');
        $endsBefore = $end->format('Y-m-d');
        $rows = $this->db->fetchAllKeyValue(
            'SELECT effective_on, gap_minutes FROM attendance_merge_limit
             WHERE (effective_on >= ? AND effective_on < ?)
               OR effective_on = (SELECT max(effective_on) FROM attendance_merge_limit WHERE effective_on < ?)
             ORDER BY effective_on',
            [$firstDay, $endsBefore, $firstDay],
        );
        $current = 0;
        $days = [];
        foreach ($rows as $day => $minutes) {
            if ($day < $firstDay) $current = (int) $minutes;
        }
        for ($date = $start; $date < $end; $date = $date->modify('+1 day')) {
            $day = $date->format('Y-m-d');
            if (isset($rows[$day])) $current = (int) $rows[$day];
            $days[$day] = $current;
        }
        return $days;
    }
}
