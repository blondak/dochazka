<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use Dochazka\Http\Problem;

use DateTimeImmutable as Date;
use DateTimeZone;

final class Timesheet
{
    public static function month(string $month): Date
    {
        if (!preg_match('/^(?!0000)\d{4}-(0[1-9]|1[0-2])$/D', $month)) throw new Problem('Neplatný měsíc.');
        return new Date($month.'-01 00:00', new DateTimeZone('Europe/Prague'));
    }

    /** Produce disjoint ranges; endpoints are Unix timestamps. */
    private static function union(array $ranges): array
    {
        sort($ranges);
        $out = [];
        foreach ($ranges as [$a,$b]) {
            $last = array_key_last($out);
            if ($last !== null && $a <= $out[$last][1]) $out[$last][1] = max($b,$out[$last][1]);
            elseif ($a < $b) $out[] = [$a,$b];
        }
        return $out;
    }

    private static function subtract(array $work, array $cuts): array
    {
        foreach (self::union($cuts) as [$from,$to]) {
            $result = [];
            foreach ($work as [$a,$b]) {
                if ($a < min($b,$from)) $result[] = [$a,min($b,$from)];
                if (max($a,$to) < $b) $result[] = [max($a,$to),$b];
            }
            $work = $result;
        }
        return $work;
    }

    public static function build(string $month, array $signals, array $requests, ?Date $from = null, ?Date $until = null,
        array $mergeLimits = [], array $serviceRanges = [], array $accounts = [], array $schedule = [], array $calendarEntries = [], array $policies = []): array
    {
        $first = self::month($month);
        $limit = ($until ?? $first->modify('+1 month'))->getTimestamp();
        $start = ($from ?? $first)->getTimestamp();
        $open = $sleeping = $work = $breaks = $absences = $allDay = $legacy = $customWork = $issues = [];
        $freshLogins = $completeLogins = [];
        $autoMerge = array_any($mergeLimits, static fn(int $minutes) => $minutes > 0);
        $flag = static function(int $ts, string $why) use (&$issues,$first,$start,$limit) {
            if ($ts >= $start && $ts < $limit) $issues[] = $first->setTimestamp($ts)->format('d.m. H:i').' '.$why;
        };
        foreach ($signals as $s) {
            $ts = strtotime($s['happened']);
            $key = $s['device'].'/'.$s['session_key'];
            switch ($s['kind']) {
                case 'login':
                    if (isset($open[$key])) $flag($open[$key], 'Opakované přihlášení.');
                    if (isset($sleeping[$key])) $flag($sleeping[$key], 'Chybí probuzení před dalším přihlášením.');
                    unset($sleeping[$key]);
                    $open[$key] = $ts;
                    if ($autoMerge) $freshLogins[$key] = self::microseconds($s['happened']);
                    break;
                case 'logout':
                    if (isset($open[$key])) {
                        $work[] = [$open[$key],$ts];
                        if (isset($freshLogins[$key]) && $open[$key] < $ts) {
                            $completeLogins[$s['device']][] = [$freshLogins[$key], self::microseconds($s['happened'])];
                        }
                        unset($open[$key]);
                    } elseif (isset($sleeping[$key])) {
                        $flag($ts, 'Chybí probuzení před odhlášením.');
                        unset($sleeping[$key]);
                    } else $flag($ts,'Chybí přihlášení.');
                    unset($freshLogins[$key]);
                    break;
                case 'sleep':
                    if (isset($open[$key])) {
                        $work[] = [$open[$key], $ts];
                        unset($open[$key]);
                        $sleeping[$key] = $ts;
                    } elseif (!isset($sleeping[$key])) {
                        $flag($ts, 'Uspání bez přihlášení.');
                    }
                    unset($freshLogins[$key]);
                    break;
                case 'resume':
                    if (isset($sleeping[$key])) {
                        unset($sleeping[$key]);
                        $open[$key] = $ts;
                    } else {
                        $flag($ts, 'Probuzení bez odpovídajícího uspání.');
                    }
                    break;
                case 'gap':
                    if (isset($open[$key]) && $open[$key] < $limit && $ts > $start) {
                        $flag(max($open[$key], $start), 'Neúplná relace před mezerou v záznamu.');
                    }
                    unset($open[$key], $sleeping[$key], $freshLogins[$key]);
                    $flag($ts, 'Mezera v záznamu zařízení.');
                    break;
            }
        }
        foreach ($open as $ts) $flag($ts,'Chybí odhlášení.');
        foreach ($sleeping as $ts) $flag($ts, 'Chybí probuzení po uspání.');
        $pending = 0;
        $adjustments = $partialVacation = [];
        $missingActual = 0;
        foreach ($requests as $r) {
            if ($r['generated_holiday'] ?? false) continue;
            if ($r['status'] === 'pending') $pending++;
            if ($r['status'] !== 'approved') continue;
            $range = [strtotime($r['begins']),strtotime($r['ends'])];
            $kind = $r['kind'];
            if ($r['needs_actual'] ?? false) {
                if (empty($r['actual_from']) || !empty($r['actual_returned']) || (($r['requires_proof'] ?? false) && empty($r['has_proof']))) {
                    $missingActual++;
                    continue;
                }
                $actual = [strtotime($r['actual_from']), strtotime($r['actual_to'])];
                foreach (self::days($actual, $first, $start, $limit) as [$date, [$a, $b]]) {
                    $key = $date->format('Y-m-d');
                    if (!isset($schedule[$key])) {
                        $missingActual++;
                        continue;
                    }
                    foreach ($schedule[$key] as [$x, $y]) {
                        if (max($a, $x) < min($b, $y)) $absences[$kind][] = [max($a, $x), min($b, $y)];
                    }
                }
                continue;
            }
            if (isset($r['vacation_seconds'])) {
                $date = $first->setTimestamp($range[0]);
                $day = $date->format('Y-m-d');
                if ($range[0] >= $start && $range[0] < $limit) $partialVacation[$day] = (int) $r['vacation_seconds'];
                continue;
            }
            switch (Catalog::behavior($kind, $accounts)) {
                case 'timed_absence': $absences[$kind][] = $range; break;
                case 'shift_absence':
                    foreach (self::days($range, $first, $start, $limit) as [$date, $part]) {
                        $key = $date->format('Y-m-d');
                        if (isset($schedule[$key])) {
                            $allDay[$kind] = array_merge($allDay[$kind] ?? [], $schedule[$key]);
                        } elseif ($kind === 'DOVOLENA') {
                            $legacy[$kind][] = $part;
                        }
                    }
                    break;
                case 'break': $breaks[] = $range; break;
                case 'work':
                    if ($kind !== 'PRACE') {
                        $customWork[$kind][] = $range;
                    } elseif (($r['merged_work'] ?? false) || isset($r['replaces_from'])) {
                        $adjustments[$r['id']] = [$range, $r['replaces_from'] ?? null, $r['replaces_to'] ?? null];
                    } else {
                        $work[] = $range;
                    }
                    break;
            }
        }
        $absenceConflict = false;
        $claimed = [];
        foreach ($absences as $ranges) {
            foreach (self::union($ranges) as [$a, $b]) {
                if (array_any($claimed, static fn(array $part): bool => $part[0] < $b && $part[1] > $a)) {
                    $absenceConflict = true;
                    $flag(max($a, $start), 'Skutečné časové nepřítomnosti se překrývají.');
                }
            }
            array_push($claimed, ...$ranges);
        }
        $timedCuts = array_merge($breaks, ...array_values($absences));
        $shiftCuts = array_merge([], ...array_values($allDay), ...array_values($legacy));
        $rows = [];
        $seconds = 0;
        $vacationConflict = false;
        $gaps = self::shortGaps($completeLogins, $mergeLimits, $first, array_merge($timedCuts, $shiftCuts, $serviceRanges));
        $work = self::union(array_merge($work, $gaps));
        ksort($adjustments, SORT_NUMERIC);
        foreach ($adjustments as [$range, $replacesFrom, $replacesTo]) {
            if ($replacesFrom !== null) {
                $work = self::subtract($work, [[strtotime($replacesFrom), strtotime($replacesTo)]]);
            }
            $work = self::union(array_merge($work, self::subtract([$range], $serviceRanges)));
        }
        $presence = $breakRows = [];
        $unresolvedBreaks = 0;
        if (!empty($policies['break_rules']) || $breaks || !empty($policies['break_exceptions'])) {
            $available = [];
            $rulesByDay = [];
            foreach (['PRACE' => $work] + $customWork as $kind => $ranges) {
                foreach (self::union($ranges) as $range) {
                    foreach (self::days($range, $first, $start, $limit) as [$date, $part]) {
                        $day = $date->format('Y-m-d');
                        $available[$day][] = $part;
                        $rule = DurationRules::forDay($policies['break_rules'][$kind] ?? [], $day);
                        if (($rule['enabled'] ?? false) && (!isset($rulesByDay[$day])
                            || $rule['deadline'] < $rulesByDay[$day]['deadline']
                            || ($rule['deadline'] === $rulesByDay[$day]['deadline'] && $rule['minutes'] > $rulesByDay[$day]['minutes']))) {
                            $rulesByDay[$day] = $rule;
                        }
                    }
                }
            }
            $breakMeta = static fn(string $day, int $a, int $b, string $source): array => [
                'day' => $day, 'from' => $first->setTimestamp($a)->format(DATE_ATOM),
                'to' => $first->setTimestamp($b)->format(DATE_ATOM), 'source' => $source,
            ];
            foreach (self::union($breaks) as $range) {
                foreach (self::days($range, $first, $start, $limit) as [$date, [$a, $b]]) {
                    $breakRows[] = $breakMeta($date->format('Y-m-d'), $a, $b, 'recorded');
                }
            }
            ksort($available);
            $groups = [];
            foreach ($available as $day => $ranges) {
                $rule = $rulesByDay[$day] ?? null;
                $ranges = self::union($ranges);
                if (!$rule && !$breaks) continue;
                foreach ($ranges as [$a, $b]) $presence[] = $breakMeta($day, $a, $b, 'presence');
                if (!$rule) continue;
                $exception = $policies['break_exceptions'][$day] ?? null;
                if ($exception !== null) {
                    $flag($ranges[0][0], $exception === 'ack' ? 'Přestávka je doložená s nesrovnalostí.'
                        : 'Přestávka se nečerpala. Skutečná práce zůstala započtená.');
                    continue;
                }
                $last = array_key_last($groups);
                if ($last !== null && $groups[$last]['rule'] === $rule
                    && $groups[$last]['ranges'][array_key_last($groups[$last]['ranges'])][1] === $ranges[0][0]) {
                    $groups[$last]['ranges'] = self::union(array_merge($groups[$last]['ranges'], $ranges));
                } else {
                    $groups[] = ['rule' => $rule, 'ranges' => $ranges];
                }
            }
            foreach ($groups as ['rule' => $rule, 'ranges' => $ranges]) {
                $taken = array_values(array_filter(self::union($breaks), static fn(array $part) =>
                    $part[0] > $ranges[0][0] && $part[1] < $ranges[array_key_last($ranges)][1]));
                $net = self::subtract($ranges, array_merge($timedCuts, $shiftCuts, $serviceRanges));
                $length = $rule['minutes'] * 60;
                $limitSeconds = $rule['deadline'] * 60;
                $proposed = DurationRules::proposeBreaks($net, $taken, $length, $rule['preferred'] * 60, $limitSeconds);
                foreach ($proposed as $part) {
                    $timedCuts[] = $part;
                    foreach (self::days($part, $first, $start, $limit) as [$date, [$a, $b]]) {
                        $breakRows[] = $breakMeta($date->format('Y-m-d'), $a, $b, 'proposed');
                    }
                }
                if (DurationRules::hasOverlongWork(self::subtract($net, $proposed), array_merge($taken, $proposed), 1800, $limitSeconds)) {
                    $unresolvedBreaks++;
                    $flag($ranges[0][0], 'Prověřte přestávku a nepřetržitou práci.');
                }
            }
        }
        $work = self::subtract($work, $timedCuts);
        $allocated = [];
        ksort($customWork);
        foreach ($customWork as &$ranges) {
            $ranges = self::subtract(self::union($ranges), array_merge($timedCuts, $allocated));
            array_push($allocated, ...$ranges);
        }
        unset($ranges);
        $work = self::subtract($work, $allocated);
        $conflictRanges = array_merge($work, $allocated, ...array_values($absences));
        foreach ($allDay as $ranges) {
            foreach ($ranges as [$from, $to]) {
                if (array_any($conflictRanges, static fn(array $range) => $range[0] < $to && $range[1] > $from)) {
                    $flag($from, 'Celosměnová nepřítomnost se překrývá s prací nebo jinou nepřítomností.');
                    $vacationConflict = true;
                }
            }
            array_push($conflictRanges, ...$ranges);
        }

        foreach ($legacy['DOVOLENA'] ?? [] as [$from, $to]) {
            if (array_any($conflictRanges, static fn(array $range) => $range[0] < $to && $range[1] > $from)) {
                $flag($from, 'Dovolená se překrývá s prací nebo lékařem.');
                $vacationConflict = true;
            }
        }

        // A shift absence and a legacy vacation can share a code across different dates.
        $accountsWithIntervals = ['PRACE' => $work] + $customWork + $absences + $allDay;
        foreach ($accountsWithIntervals as $kind => $ranges) {
            foreach (self::union($ranges) as $range) {
                foreach (self::days($range, $first, $start, $limit) as [$date, [$from, $to]]) {
                    $rows[] = ['day' => $date->format('Y-m-d'), 'kind' => $kind, 'from' => $first->setTimestamp($from)->format(DATE_ATOM),
                        'to' => $first->setTimestamp($to)->format(DATE_ATOM)];
                    if (Catalog::behavior($kind, $accounts) === 'work') $seconds += $to - $from;
                }
            }
        }
        foreach ($calendarEntries as $day => $entry) {
            foreach ($entry['ranges'] as [$from, $to]) {
                $rows[] = ['day' => $day, 'kind' => $entry['account'], 'from' => $first->setTimestamp($from)->format(DATE_ATOM),
                    'to' => $first->setTimestamp($to)->format(DATE_ATOM), 'generated_holiday' => true];
                if (Catalog::behavior($entry['account'], $accounts) === 'work') $seconds += $to - $from;
            }
        }
        foreach (self::union($legacy['DOVOLENA'] ?? []) as $range) {
            foreach (self::days($range, $first, $start, $limit) as [$date]) {
                $rows[] = ['day' => $date->format('Y-m-d'), 'kind' => 'DOVOLENA', 'from' => '', 'to' => ''];
            }
        }
        $unresolvedAdjustments = $policies['adjustments'] ?? [];
        if ($unresolvedAdjustments) {
            foreach ($rows as &$row) {
                if ($row['from'] === '' || ($row['generated_holiday'] ?? false) || Catalog::behavior($row['kind'], $accounts) !== 'work') continue;
                $key = DurationRules::rowKey($row);
                if (!isset($unresolvedAdjustments[$key])) continue;
                $calculated = strtotime($row['to']) - strtotime($row['from']);
                $recognized = $unresolvedAdjustments[$key]['seconds'];
                $row['calculated_seconds'] = $calculated;
                $row['seconds'] = $recognized;
                $seconds -= $calculated - $recognized;
                unset($unresolvedAdjustments[$key]);
            }
            unset($row);
        }
        foreach ($partialVacation as $day => $duration) {
            $shift = $schedule[$day] ?? [];
            $fund = array_sum(array_map(static fn(array $r): int => $r[1] - $r[0], $shift));
            $used = $duration;
            foreach ($rows as $row) {
                if ($row['day'] !== $day || $row['from'] === '' || ($row['generated_holiday'] ?? false)) continue;
                $from = strtotime($row['from']);
                $to = strtotime($row['to']);
                $overlap = 0;
                foreach ($shift as [$a, $b]) $overlap += max(0, min($to, $b) - max($from, $a));
                $used += min($overlap, $row['seconds'] ?? ($to - $from));
            }
            if ($fund === 0 || $used > $fund || $duration < intdiv($fund + 1, 2)) {
                $vacationConflict = true;
                $flag($first->modify($day)->getTimestamp(), 'Zkontrolujte fond směny a rozsah částečné dovolené.');
            }
            $rows[] = ['day' => $day, 'kind' => 'DOVOLENA', 'from' => '', 'to' => '', 'seconds' => $duration, 'partial_vacation' => true];
        }
        usort($rows, static function (array $a, array $b): int {
            $day = $a['day'] <=> $b['day'];
            if ($day !== 0 || $a['from'] === '' || $b['from'] === '') {
                return $day ?: ($a['from'] <=> $b['from']);
            }
            return strtotime($a['from']) <=> strtotime($b['from']);
        });
        $issues = array_values(array_unique($issues));
        $result = compact('rows','seconds','issues','pending') + ['vacation_conflict' => $vacationConflict];
        if ($absenceConflict) $result['absence_conflict'] = true;
        if ($presence) $result['presence'] = $presence;
        if ($breakRows) $result['breaks'] = $breakRows;
        if ($missingActual) $result['missing_actual'] = $missingActual;
        if ($unresolvedBreaks) $result['unresolved_breaks'] = $unresolvedBreaks;
        if ($unresolvedAdjustments) $result['unresolved_adjustments'] = array_values($unresolvedAdjustments);
        return $result;
    }

    private static function days(array $range, Date $zone, int $start, int $limit): iterable
    {
        $from = max($start, $range[0]);
        $until = min($limit, $range[1]);
        while ($from < $until) {
            $date = $zone->setTimestamp($from);
            $end = min($until, $date->modify('tomorrow midnight')->getTimestamp());
            yield [$date, [$from, $end]];
            $from = $end;
        }
    }

    private static function shortGaps(array $byDevice, array $limits, Date $date, array $excluded): array
    {
        $gaps = [];
        foreach ($byDevice as $ranges) {
            $previousEnd = null;
            foreach (self::union($ranges) as [$from, $to]) {
                if ($previousEnd !== null) {
                    $fromSecond = (int) floor($from / 1_000_000);
                    $day = $date->setTimestamp($fromSecond)->format('Y-m-d');
                    if ($from - $previousEnd <= ($limits[$day] ?? 0) * 60_000_000
                        && $date->setTimestamp((int) floor(($previousEnd - 1) / 1_000_000))->format('Y-m-d') === $day
                        && !array_any($excluded, static fn(array $range) =>
                            $range[0] * 1_000_000 < $from && $range[1] * 1_000_000 > $previousEnd)) {
                        $gaps[] = [(int) floor($previousEnd / 1_000_000), $fromSecond];
                    }
                }
                $previousEnd = $to;
            }
        }
        return $gaps;
    }

    private static function microseconds(string $value): int
    {
        $date = new Date($value);
        return $date->getTimestamp() * 1_000_000 + (int) $date->format('u');
    }
}
