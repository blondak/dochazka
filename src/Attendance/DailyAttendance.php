<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Dochazka\Http\Problem;
use Dochazka\Identity\Accounts;

final class DailyAttendance
{
    public function __construct(private Connection $db, private Accounts $accounts, private \Dochazka\Staff\OrgUnits $units, private MergePolicy $mergePolicy, private Catalog $catalog, private WorkModels $models) {}

    public function view(array $input): array
    {
        $actor = $this->accounts->actor('manager');
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Prague'));
        $today = $now->setTime(0, 0);
        $start = $today;
        if (array_key_exists('day', $input)) {
            $day = $input['day'];
            if (!is_string($day) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)) throw new Problem('Zadejte platné datum.');
            $start = WorkModels::day($day);
            if ($start > $today) throw new Problem('Denní přehled je dostupný nejvýše do dneška.');
        }
        $day = $start->format('Y-m-d');
        $end = $start->modify('+1 day');
        $cutoff = min($end, $now)->format('Y-m-d\TH:i:s.uP');
        $roster = $this->db->createQueryBuilder()
            ->select('id', 'name', 'sid')
            ->from('employee')
            ->where('org_unit_id IN (:units)', 'attendance_enabled = true', 'starts <= :day')
            ->andWhere('(ends IS NULL OR ends >= :day)', '(archived_on IS NULL OR archived_on >= :day)')
            ->setParameter('units', array_keys($this->units->managed($actor)), ArrayParameterType::INTEGER)
            ->setParameter('day', $day)
            ->orderBy('name')->addOrderBy('id')
            ->fetchAllAssociative();
        $sids = array_values(array_filter(array_column($roster, 'sid')));
        $events = $sids === [] ? [] : $this->db->createQueryBuilder()
            ->select('s.sid', 's.kind', 's.happened', 's.device', 's.session_key', 'd.revoked', 'ss.service_at', 'ss.first_event')
            ->from('signal_session', 'ss')
            ->innerJoin('ss', 'signal', 's', 's.sid = ss.sid AND s.device = ss.device AND s.session_key = ss.session_key')
            ->innerJoin('ss', 'device', 'd', 'd.id = ss.device')
            ->where('ss.sid IN (:sids)', 's.happened <= :cutoff')
            ->andWhere("(s.happened < :end OR s.kind = 'logout')")
            ->andWhere("tstzrange(ss.first_event, ss.last_event, '[]') && tstzrange(:start, :end, '[)')")
            ->setParameter('sids', $sids, ArrayParameterType::STRING)
            ->setParameter('start', $start->format(DATE_ATOM))
            ->setParameter('end', $end->format(DATE_ATOM))
            ->setParameter('cutoff', $cutoff)
            ->orderBy('s.happened')->addOrderBy('s.device')->addOrderBy('s.event_id')
            ->fetchAllAssociative();

        $bySid = [];
        foreach ($events as $event) {
            $bySid[$event['sid']][] = $event;
        }
        $requests = [];
        if ($roster !== []) {
            $approved = $this->db->createQueryBuilder()
                ->select('r.*', 'EXISTS(SELECT 1 FROM medical_proof p WHERE p.request_id = r.id) AS has_proof')
                ->from('request', 'r')
                ->where('employee IN (:ids)', 'NOT generated_holiday', "status = 'approved'", 'begins < :cutoff', 'ends > :start')
                ->andWhere("(kind <> 'PRACE' OR ((merged_work OR replaces_from IS NOT NULL) AND ends <= :cutoff))")
                ->setParameter('ids', array_column($roster, 'id'), ArrayParameterType::INTEGER)
                ->setParameter('cutoff', $cutoff)
                ->setParameter('start', $start->format(DATE_ATOM))
                ->fetchAllAssociative();
            foreach ($approved as $request) {
                $requests[$request['employee']][] = $request;
            }
        }
        $limits = $this->mergePolicy->limits($start, $end);
        $people = [];
        $accounts = $this->catalog->accounts();
        $breakRules = $this->catalog->durationRules('break');
        $corrections = $exceptions = [];
        if ($roster) {
            $scope = ['ids' => array_column($roster, 'id'), 'day' => $day];
            $rows = $this->db->createQueryBuilder()->select('employee', 'day', 'account AS kind', 'begins AS "from"', 'ends AS "to"', 'seconds')
                ->from('recognized_work')->where('employee IN (:ids)', 'day = :day')
                ->setParameters($scope, ['ids' => ArrayParameterType::INTEGER])->fetchAllAssociative();
            foreach ($rows as $row) $corrections[$row['employee']][DurationRules::rowKey($row)] = $row;
            $exceptions = $this->db->createQueryBuilder()->select('employee', 'mode')->from('attendance_break_exception')
                ->where('employee IN (:ids)', 'day = :day')
                ->setParameters($scope, ['ids' => ArrayParameterType::INTEGER])->fetchAllKeyValue();
        }
        foreach ($roster as $person) {
            $exception = $exceptions[$person['id']] ?? null;
            $policy = ['break_rules' => $breakRules, 'adjustments' => $corrections[$person['id']] ?? [],
                'break_exceptions' => $exception ? [$day => $exception] : []];
            $people[] = ['id' => (int) $person['id'], 'name' => $person['name']] + (
                $person['sid'] === null
                    ? ['status' => 'untracked', 'worked_seconds' => null, 'from' => null, 'to' => null]
                    : self::summarize($bySid[$person['sid']] ?? [], $requests[$person['id']] ?? [], $start, $end, $limits, $accounts, $this->models->schedule($person['id'], $start, $end), $policy)
            );
        }
        return ['day' => $day, 'updated' => $now->format(DATE_ATOM), 'people' => $people];
    }

    private static function summarize(array $events, array $requests, DateTimeImmutable $start, DateTimeImmutable $end, array $limits, array $accounts, array $schedule, array $policy): array
    {
        $loggedIn = $present = $closed = $serviceRanges = [];
        $firstLogin = $lastLogout = null;
        $dayStart = $start->getTimestamp();
        foreach ($events as $event) {
            $key = $event['device'].'/'.$event['session_key'];
            if ($event['service_at'] !== null) {
                $serviceRanges[$key] ??= [strtotime($event['first_event']), PHP_INT_MAX];
                if ($event['kind'] === 'logout') $serviceRanges[$key][1] = strtotime($event['happened']);
                continue;
            }
            switch ($event['kind']) {
                case 'login':
                    $loggedIn[$key] = true;
                    $firstLogin ??= max($dayStart, strtotime($event['happened']));
                    // A login before midnight is included when this session has an event today.
                    if (!$event['revoked']) $present[$key] = true;
                    break;
                case 'resume':
                    if (isset($loggedIn[$key]) && !$event['revoked']) $present[$key] = true;
                    break;
                case 'sleep':
                    unset($present[$key]);
                    break;
                case 'logout':
                    $logout = strtotime($event['happened']);
                    if (isset($loggedIn[$key]) && $logout >= $dayStart) {
                        $closed[$key] = true;
                        $lastLogout = $logout;
                    }
                    unset($loggedIn[$key], $present[$key]);
                    break;
                case 'gap':
                    // The end of an interrupted session is unknown.
                    if (isset($loggedIn[$key])) $lastLogout = null;
                    unset($loggedIn[$key], $present[$key]);
                    break;
            }
        }
        $completed = array_values(array_filter($events, static fn(array $event) =>
            isset($closed[$event['device'].'/'.$event['session_key']])));
        $seconds = $closed
            ? Timesheet::build($start->format('Y-m'), $completed, $requests, $start, $end, $limits, $serviceRanges, $accounts, $schedule, policies: $policy)['seconds']
            : null;
        return [
            'status' => $present ? 'present' : ($closed ? 'finished' : 'absent'),
            'worked_seconds' => $seconds,
            'from' => $firstLogin === null ? null : $start->setTimestamp($firstLogin)->format(DATE_ATOM),
            'to' => $lastLogout === null || $loggedIn ? null : $start->setTimestamp($lastLogout)->format(DATE_ATOM),
        ];
    }
}
