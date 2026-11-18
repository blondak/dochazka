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
        array $mergeLimits = [], array $serviceRanges = []): array
    {
        $first = self::month($month);
        $limit = ($until ?? $first->modify('+1 month'))->getTimestamp();
        $start = ($from ?? $first)->getTimestamp();
        $open = $sleeping = $work = $doctor = $breaks = $vacations = $issues = [];
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
        foreach ($requests as $r) {
            if ($r['status'] === 'pending') $pending++;
            if ($r['status'] !== 'approved') continue;
            $range = [strtotime($r['begins']),strtotime($r['ends'])];
            switch ($r['kind']) {
                case 'LEKAR': $doctor[] = $range; break;
                case 'DOVOLENA': $vacations[] = $range; break;
                case 'PRESTAVKA': $breaks[] = $range; break;
                case 'PRACE':
                    array_push($work, ...(($r['merged_work'] ?? false) ? self::subtract([$range], $serviceRanges) : [$range]));
                    break;
            }
        }
        $rows = [];
        $seconds = 0;
        $vacationConflict = false;
        $gaps = self::shortGaps($completeLogins, $mergeLimits, $first, array_merge($doctor, $breaks, $vacations, $serviceRanges));
        $work = self::subtract(self::union(array_merge($work, $gaps)),array_merge($doctor,$breaks));
        foreach (['PRACE'=>$work,'LEKAR'=>self::union($doctor),'DOVOLENA'=>self::union($vacations)] as $kind=>$ranges) {
            foreach ($ranges as [$a,$b]) {
                $a = max($a,$start); $b = min($b,$limit);
                while ($a < $b) {
                    $date = $first->setTimestamp($a);
                    $end = min($b,$date->modify('tomorrow midnight')->getTimestamp());
                    $allDay = $kind === 'DOVOLENA';
                    $rows[] = ['day'=>$date->format('Y-m-d'),'kind'=>$kind,'from'=>$allDay?'':$date->format(DATE_ATOM),
                        'to'=>$allDay?'':$first->setTimestamp($end)->format(DATE_ATOM)];
                    if ($kind === 'PRACE') $seconds += $end-$a;
                    if ($allDay) {
                        foreach (array_merge($work,$doctor) as [$x,$y]) {
                            if ($x < $end && $y > $a) {
                                $flag($a, 'Dovolená se překrývá s prací nebo lékařem.');
                                $vacationConflict = true;
                                break;
                            }
                        }
                    }
                    $a = $end;
                }
            }
        }
        usort($rows, static function (array $a, array $b): int {
            $day = $a['day'] <=> $b['day'];
            if ($day !== 0 || $a['from'] === '' || $b['from'] === '') {
                return $day ?: ($a['from'] <=> $b['from']);
            }
            return strtotime($a['from']) <=> strtotime($b['from']);
        });
        $issues = array_values(array_unique($issues));
        return compact('rows','seconds','issues','pending') + ['vacation_conflict' => $vacationConflict];
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
