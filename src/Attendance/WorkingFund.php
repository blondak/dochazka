<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use DateTimeImmutable;

final class WorkingFund
{
    public static function calculate(array $plan, array $rows, array $accounts, DateTimeImmutable $start,
        DateTimeImmutable $end, string $today, int $tolerance): array
    {
        $days = [];
        for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
            $day = $d->format('Y-m-d');
            $ranges = $plan['credits'][$day]['ranges'] ?? $plan['schedule'][$day] ?? null;
            $days[$day] = [
                'planned' => $ranges === null ? null : array_sum(array_map(static fn($r) => $r[1] - $r[0], $ranges)),
                'work' => 0, 'absence' => 0,
            ];
        }
        foreach ($rows as $row) {
            $behavior = Catalog::behavior($row['kind'], $accounts);
            if ($behavior === 'break' || !isset($days[$row['day']])) continue;
            $field = $behavior === 'work' && empty($row['generated_holiday']) ? 'work' : 'absence';
            $days[$row['day']][$field] += $row['seconds'] ?? 0;
        }
        $review = [];
        $complete = true;
        foreach ($days as $day => &$item) {
            $actual = $item['work'] + $item['absence'];
            $item['difference'] = $item['planned'] === null ? null : $actual - $item['planned'];
            if ($item['planned'] === null) $complete = false;
            if ($day < $today && $item['difference'] < 0 && (!$actual || -$item['difference'] > $tolerance * 60)) {
                $review[$day] = $item['difference'];
            }
        }
        unset($item);
        $planned = $complete ? array_sum(array_column($days, 'planned')) : null;
        $work = array_sum(array_column($days, 'work'));
        $absence = array_sum(array_column($days, 'absence'));
        return compact('planned','work','absence','days','review') + [
            'difference' => $planned === null ? null : $work + $absence - $planned,
        ];
    }
}
