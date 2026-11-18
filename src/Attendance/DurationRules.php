<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

final class DurationRules
{
    public static function forDay(array $rules, string $day): ?array
    {
        foreach ($rules as $rule) {
            if ($rule['effective_on'] <= $day) return $rule;
        }
        return null;
    }

    public static function rounded(int $seconds, ?array $rule): int
    {
        $step = ($rule['minutes'] ?? 1) * 60;
        return match ($rule['mode'] ?? 'off') {
            'down' => intdiv($seconds, $step) * $step,
            'up' => intdiv($seconds + $step - 1, $step) * $step,
            'nearest' => intdiv($seconds + intdiv($step, 2), $step) * $step,
            default => $seconds,
        };
    }

    public static function rowKey(array $row): string
    {
        return implode('/', [$row['day'], $row['kind'], strtotime($row['from']), strtotime($row['to'])]);
    }

    public static function hasOverlongWork(array $work, array $taken, int $length, int $deadline): bool
    {
        usort($taken, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
        $index = 0;
        $elapsed = 0;
        foreach ($work as [$from, $to]) {
            while (isset($taken[$index]) && $taken[$index][1] <= $from) {
                if ($taken[$index][1] - $taken[$index][0] >= $length) $elapsed = 0;
                $index++;
            }
            $elapsed += $to - $from;
            if ($elapsed > $deadline) return true;
        }
        return false;
    }

    /** @return list<array{int,int}> */
    public static function proposeBreaks(array $work, array $taken, int $length, int $preferred, int $deadline): array
    {
        // When a real break is already documented, let the compliance check report
        // the preceding work. Generating another break there would deduct fictitious time.
        $lastTaken = null;
        foreach ($taken as [$from, $to]) {
            if ($to - $from >= 1800) $lastTaken = max($lastTaken ?? $to, $to);
        }
        if ($lastTaken !== null) {
            $work = array_values(array_filter($work, static fn(array $part): bool => $part[0] >= $lastTaken));
        }
        $result = [];
        while ($work) {
            $total = array_sum(array_map(static fn(array $part): int => $part[1] - $part[0], $work));
            if ($total <= $deadline) break;
            $elapsed = 0;
            $best = null;
            foreach ($work as $index => [$from, $to]) {
                $minimum = $elapsed === 0 ? 1 : 0;
                $remainingAfter = $total - $elapsed - ($to - $from);
                $maximum = min($deadline - $elapsed, $to - $from - $length - ($remainingAfter === 0 ? 1 : 0));
                if ($minimum <= $maximum) {
                    $offset = max($minimum, min($maximum, $preferred - $elapsed));
                    $distance = abs($preferred - $elapsed - $offset);
                    if ($best === null || $distance < $best['distance']) {
                        $best = ['index' => $index, 'from' => $from + $offset, 'to' => $from + $offset + $length, 'distance' => $distance];
                    }
                }
                $elapsed += $to - $from;
                if ($elapsed > $deadline) break;
            }
            if ($best === null) break;
            $result[] = [$best['from'], $best['to']];
            $work = array_slice($work, $best['index']);
            if ($work[0][1] === $best['to']) array_shift($work);
            else $work[0][0] = $best['to'];
        }
        return $result;
    }
}
