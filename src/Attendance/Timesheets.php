<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DateTimeImmutable;
use Dochazka\Absence\AbsenceRequest;
use Dochazka\Absence\Requests;
use Dochazka\Http\Problem;
use Dochazka\Audit\AuditTrail;
use Dochazka\Identity\Accounts;
use Dochazka\Staff\Directory;
use Dochazka\Staff\Employee;

final class Timesheets
{
    public function __construct(
        private Connection $db,
        private EntityManagerInterface $em,
        private AuditTrail $audit,
        private Accounts $accounts,
        private Directory $directory,
        private MergePolicy $mergePolicy,
        private Catalog $catalog,
        private WorkModels $models,
        private HolidayEntries $holidayEntries,
        private Requests $requests,
    ) {}

    public function holidayControl(array $input): array
    {
        $actor = $this->accounts->actor('manager');
        $from = WorkModels::day($input['from'] ?? '');
        $to = WorkModels::day($input['to'] ?? '');
        if ($from > $to || $to >= new DateTimeImmutable('today', $to->getTimezone()) || $from->diff($to)->days > 30) {
            throw new Problem('Vyberte nejvýše 31 dokončených dnů.');
        }
        if (isset($input['employee'])) {
            $scope = [$this->directory->person($actor, (int) $input['employee'], true)['id']];
        } else {
            $scope = array_values(array_map(static fn(array $person): int => $person['id'],
                array_filter($this->directory->people(), static fn(array $person): bool => $person['managed'])));
        }
        sort($scope);
        $end = $to->modify('+1 day');
        $changes = [];
        $counts = $this->holidayEntries->fill($from, $end, true, $scope, details: $changes);
        $token = hash('sha256', json_encode([$actor['id'], $input['from'], $input['to'], $scope, $changes, $counts], JSON_THROW_ON_ERROR));
        if (!isset($input['token'])) return $counts + ['token' => $token];
        if ($token !== $input['token']) throw new Problem('Výsledek se změnil. Vytvořte nový náhled.', 409);
        $counts = $this->holidayEntries->fill($from, $end, false, $scope, $actor['id']);
        $this->audit->audit($actor['id'], 'holiday-control', ['from' => $input['from'], 'to' => $input['to'], 'counts' => $counts]);
        return $counts;
    }

    public function sheet(array $person, string $month): array
    {
        $start = Timesheet::month($month);
        $end = $start->modify('+1 month');
        $start = max($start, new DateTimeImmutable($person['starts'], $start->getTimezone()));
        if ($person['ends'] !== null) {
            $end = min($end, (new DateTimeImmutable($person['ends'], $start->getTimezone()))->modify('+1 day'));
        }
        $recorded = $person['sid'] === null || $start >= $end ? [] : $this->db->fetchAllAssociative(
            "SELECT s.*, ss.service_at, d.name AS station FROM signal_session ss JOIN signal s USING (sid, device, session_key)
             JOIN device d ON d.id = ss.device
             WHERE ss.sid = ? AND tstzrange(ss.first_event, ss.last_event, '[]') && tstzrange(?, ?, '[)')
             ORDER BY s.happened, s.device, s.event_id",
            [$person['sid'], $start->format(DATE_ATOM), $end->format(DATE_ATOM)],
        );
        $signals = $sessions = [];
        foreach ($recorded as $event) {
            $key = $event['device'].'/'.$event['session_key'];
            $service = $event['service_at'] !== null;
            $sessions[$key] ??= [
                'device' => (int) $event['device'], 'station' => $event['station'], 'session' => $event['session_key'],
                'from' => null, 'to' => null, 'service' => $service,
            ];
            if ($event['kind'] === 'login') {
                $sessions[$key]['from'] ??= (new DateTimeImmutable($event['happened']))->format(DATE_ATOM);
            } elseif ($event['kind'] === 'logout') {
                $sessions[$key]['to'] = (new DateTimeImmutable($event['happened']))->format(DATE_ATOM);
            }
            if (!$service) {
                unset($event['service_at'], $event['station']);
                $signals[] = $event;
            }
        }
        $plan = $this->models->plan($person['id'], $start, $end);
        $schedule = $plan['schedule'];
        $entries = ($person['attendance_enabled'] ?? true)
            ? $this->holidayEntries->visible($person['id'], $person['sid'], $start, $end, $plan['credits']) : [];
        $requests = $start >= $end ? [] : $this->db->fetchAllAssociative(
            'SELECT r.*, EXISTS(SELECT 1 FROM medical_proof p WHERE p.request_id=r.id) AS has_proof FROM request r WHERE employee=? AND begins < ? AND ends > ? ORDER BY begins,id',
            [$person['id'],$end->format(DATE_ATOM),$start->format(DATE_ATOM)]);
        $proofRequests = array_filter(array_column($entries, 'request_id'));
        $requests = array_values(array_filter($requests, static fn(array $r) =>
            !$r['generated_holiday'] || in_array($r['id'], $proofRequests, true)));
        $missingProofs = 0;
        foreach ($requests as &$request) {
            if ($request['requires_proof'] && $request['status'] === 'approved' && !$request['has_proof']) $missingProofs++;
            if (!$request['needs_actual']) unset($request['needs_actual'], $request['actual_from'], $request['actual_to'], $request['actual_returned']);
            if ($request['vacation_seconds'] === null) unset($request['vacation_seconds'], $request['vacation_side'], $request['vacation_consent']);
            if ($request['vacation_estimate'] === null) unset($request['vacation_estimate']);
            if (!$request['generated_holiday']) unset($request['generated_holiday']);
            if (!$request['merged_work']) unset($request['merged_work']);
            if ($request['replaces_from'] === null) unset($request['replaces_from'], $request['replaces_to']);
        }
        unset($request);
        $sessions = array_values(array_filter($sessions, static fn(array $session) => $session['from'] !== null));
        $serviceRanges = [];
        foreach ($sessions as $session) {
            if ($session['service']) {
                $serviceRanges[] = [strtotime($session['from']), $session['to'] === null ? PHP_INT_MAX : strtotime($session['to'])];
            }
        }
        $accounts = $this->catalog->accounts();
        $scope = ['employee' => $person['id'], 'first' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
        $recognized = $this->db->createQueryBuilder()->select('day', 'account AS kind', 'begins AS "from"', 'ends AS "to"', 'seconds')
            ->from('recognized_work')->where('employee = :employee', 'day >= :first', 'day < :end')->setParameters($scope)
            ->orderBy('day')->addOrderBy('kind')->addOrderBy('begins')->fetchAllAssociative();
        $exceptions = $this->db->createQueryBuilder()->select('day', 'mode')->from('attendance_break_exception')
            ->where('employee = :employee', 'day >= :first', 'day < :end')->setParameters($scope)->fetchAllKeyValue();
        $policies = ['adjustments' => array_combine(array_map(DurationRules::rowKey(...), $recognized), $recognized),
            'break_rules' => $this->catalog->durationRules('break'), 'break_exceptions' => $exceptions];
        $sheet = Timesheet::build($month, $signals, $requests, $start, $end,
            $this->mergePolicy->limits($start, $end), $serviceRanges, $accounts, $schedule, $entries, $policies);
        $relevant = array_values(array_filter($signals,static fn($s) => strtotime($s['happened']) >= $start->getTimestamp() && strtotime($s['happened']) < $end->getTimestamp()));
        $versions = $this->catalog->payrollVersions();
        $changes = [];
        $totals = $byDay = [];
        foreach ($accounts as $code => $account) {
            if (in_array($code, ['PRACE', 'LEKAR', 'DOVOLENA'], true)) {
                $totals[$code] = ['code' => $code, 'name' => $account['name'], 'seconds' => 0, 'legacy_days' => 0];
            }
        }
        $fingerprintSheet = $sheet;
        unset($fingerprintSheet['presence'], $fingerprintSheet['day_issues']);
        if (isset($fingerprintSheet['breaks'])) {
            $proposals = array_values(array_filter($fingerprintSheet['breaks'], static fn(array $item): bool => $item['source'] === 'proposed'));
            if ($proposals) $fingerprintSheet['breaks'] = $proposals;
            else unset($fingerprintSheet['breaks']);
        }
        // Keep historical approval fingerprints stable after the calculation has used the full proof policy.
        foreach ($requests as &$request) {
            if (!($request['needs_actual'] ?? false) && $request['requires_proof'] === ($request['kind'] === 'LEKAR')) unset($request['requires_proof']);
        }
        unset($request);
        $payload = [$person['number'], $fingerprintSheet, $requests, $relevant];
        foreach ($sheet['rows'] as $index => &$row) {
            $kind = $row['kind'];
            $payrollCode = $kind;
            foreach ($versions[$kind] ?? [] as $version) {
                if ($row['day'] < $version['effective_on']) continue;
                $payrollCode = $version['payroll_code'];
                break;
            }
            if ($payrollCode !== $kind) $changes[$index] = $payrollCode;
            $row['payroll_code'] = $payrollCode;
            $row['seconds'] ??= $row['from'] === '' ? null : strtotime($row['to']) - strtotime($row['from']);
            if ($row['seconds'] !== null) {
                $byDay[$row['day']][$kind] = ($byDay[$row['day']][$kind] ?? 0) + $row['seconds'];
            }
            $totals[$kind] ??= ['code' => $kind, 'name' => $accounts[$kind]['name'], 'seconds' => 0, 'legacy_days' => 0];
            $totals[$kind]['seconds'] += $row['seconds'] ?? 0;
            if ($row['seconds'] === null) $totals[$kind]['legacy_days']++;
        }
        unset($row);
        $totals['PRACE']['seconds'] = $sheet['seconds'];
        $rounding = $this->catalog->durationRules('rounding');
        $internal = $internalTotals = [];
        foreach ($byDay as $date => $kinds) {
            foreach ($kinds as $kind => $length) {
                $rule = DurationRules::forDay($rounding[$kind] ?? [], $date);
                $rounded = DurationRules::rounded($length, $rule);
                $internalTotals[$kind] = ($internalTotals[$kind] ?? 0) + $rounded;
                if (($rule['mode'] ?? 'off') !== 'off') {
                    $internal[$date][$kind] = ['seconds' => $rounded, 'recognized_seconds' => $length];
                }
            }
        }
        if ($internal) {
            foreach ($internalTotals as $kind => $length) $totals[$kind]['internal_seconds'] = $length;
        }
        if ($changes) $payload[] = $changes;
        $modelRevision = $this->db->createQueryBuilder()->select('revision')->from('work_model_impact')
            ->where('employee = :employee', 'month = :month')->setParameter('employee', $person['id'])
            ->setParameter('month', $month)->fetchOne();
        if ($modelRevision !== false) $payload[] = ['work_model_revision' => $modelRevision];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $approval = $this->db->fetchAssociative('SELECT * FROM approval WHERE employee=? AND month=?', [$person['id'], $month]);
        $planned = array_map(static fn(array $ranges) => array_map(static fn(array $range) => [
            'from' => $start->setTimestamp($range[0])->format(DATE_ATOM), 'to' => $start->setTimestamp($range[1])->format(DATE_ATOM),
        ], $ranges), $schedule);
        foreach ($requests as &$request) {
            $account = $accounts[$request['kind']];
            $request['account_name'] = $account['name'];
            $request['behavior'] = $account['behavior'];
            $request['requires_proof'] ??= $request['kind'] === 'LEKAR';
            if (isset($request['vacation_seconds'])) {
                $request['scheduled_seconds'] = $request['vacation_seconds'];
                if (isset($request['vacation_estimate'])) {
                    $request['vacation_estimate'] = json_decode($request['vacation_estimate'], true, flags: JSON_THROW_ON_ERROR);
                }
            } elseif ($request['generated_holiday'] ?? false) {
                $day = (new DateTimeImmutable($request['begins']))->setTimezone($start->getTimezone())->format('Y-m-d');
                $request['scheduled_seconds'] = array_sum(array_map(static fn(array $part) => $part[1] - $part[0], $entries[$day]['ranges']));
            } elseif ($account['behavior'] === 'shift_absence') {
                $begin = new DateTimeImmutable($request['begins']);
                $finish = new DateTimeImmutable($request['ends']);
                $requestSchedule = $this->models->schedule($person['id'], $begin, $finish);
                $request['scheduled_seconds'] = array_sum(array_map(static fn(array $ranges) =>
                    array_sum(array_map(static fn(array $part) => $part[1] - $part[0], $ranges)),
                    $requestSchedule));
                if ($request['scheduled_seconds'] === 0 && !$requestSchedule) {
                    $request['scheduled_seconds'] = null;
                }
            }
        }
        unset($request);
        $fund = WorkingFund::calculate($plan, $sheet['rows'], $accounts, $start, $end,
            (new DateTimeImmutable('today', $start->getTimezone()))->format('Y-m-d'), $this->mergePolicy->fundTolerance());
        foreach ($fund['review'] as $day => $difference) {
            $minutes = intdiv(-$difference, 60);
            $sheet['day_issues'][$day] ??= ['status' => 'review', 'reasons' => []];
            $sheet['day_issues'][$day]['reasons'][] = sprintf('Do fondu směny chybí %d:%02d.', intdiv($minutes, 60), $minutes % 60);
        }
        $previousReview = isset($approval['reviewed_days']) ? json_decode($approval['reviewed_days'], true, flags: JSON_THROW_ON_ERROR) : null;
        $sheet['missing_proofs'] = $missingProofs;
        $tasks = self::approvalTasks($sheet);
        return $sheet + [
            'planned_shifts' => (object) $planned,
            'calendar_days' => (object) $plan['calendarDays'],
            'account_totals' => array_values(array_replace(array_intersect_key($accounts, $totals), $totals)),
            'attendance_start' => $person['starts'],
            'attendance_end' => $person['ends'],
            'sessions' => $sessions,
            'requests' => $requests,
            'internal_by_day' => (object) $internal,
            'approval_blockers' => array_column($tasks, 'reason'),
            'approval_tasks' => $tasks,
            'fund' => $fund,
            'approval_note_required' => $sheet['issues'] !== [] || $fund['review'] !== [],
            'fingerprint' => $hash,
            'approval' => $approval,
            'approved' => !$tasks && $approval && $approval['fingerprint'] === $hash
                && ($previousReview === null || !array_diff_assoc($fund['review'], $previousReview)),
        ];
    }

    private static function approvalTasks(array $sheet): array
    {
        $rules = [
            'pending' => ['Vyřiďte čekající žádosti.', 'Vedoucí'],
            'missing_proofs' => ['Zaměstnanec musí přiložit povinné doklady.', 'Zaměstnanec'],
            'missing_actual' => ['Doplňte skutečný čas žádostí, doklad nebo rozvrh.', 'Čas a doklad doplňuje zaměstnanec, chybějící rozvrh správce'],
            'absence_conflict' => ['Opravte překryv skutečných nepřítomností.', 'Zaměstnanec nebo vedoucí'],
            'unresolved_breaks' => ['Prověřte přestávky.', 'Vedoucí'],
            'unresolved_adjustments' => ['Prověřte dřívější úpravy uznané doby.', 'Vedoucí'],
            'vacation_conflict' => ['Opravte překryv evidence s dovolenou.', 'Vedoucí'],
        ];
        $tasks = [];
        foreach ($rules as $code => [$reason, $owner]) {
            if (!empty($sheet[$code])) $tasks[] = compact('code', 'reason', 'owner');
        }
        return $tasks;
    }

    private function approve(array $actor,array $person,array $input): void
    {
        $sheet = $this->sheet($person,$input['month']);
        if ($sheet['fingerprint'] !== ($input['fingerprint'] ?? '')) throw new Problem('Výkaz se změnil. Načtěte jej znovu.', 409);
        if ($sheet['approval_blockers']) throw new Problem($sheet['approval_blockers'][0], 409);
        $note = trim($input['note'] ?? '');
        if ($sheet['approval_note_required'] && $note === '') throw new Problem('Doplňte opravu a popište vyřešení nesrovnalostí.');
        $approval = $this->em->find(Approval::class, ['employee' => $person['id'], 'month' => $input['month']]);
        if (!$approval) {
            $approval = new Approval($this->em->getReference(Employee::class, $person['id']), $input['month']);
            $this->em->persist($approval);
        }
        $approval->reviewedDays = $sheet['fund']['review'];
        $approval->confirm($this->em->getReference(Employee::class, $actor['id']), $sheet['fingerprint'], $note);
        $this->audit->audit($actor['id'],'approve',['employee'=>$person['id'],'month'=>$input['month'],'fingerprint'=>$sheet['fingerprint'],'note'=>$note]);
    }

    public function teamCoverage(array $input): array
    {
        $actor = $this->accounts->actor('manager');
        $request = $this->db->fetchAssociative('SELECT * FROM request WHERE id=?', [(int) ($input['id'] ?? 0)]);
        if (!$request) throw new Problem('Žádost nenalezena.', 404);
        $person = $this->directory->person($actor, $request['employee'], true);
        if ($person['id'] === $actor['id']) throw new Problem('Vlastní žádost posuzuje jiný vedoucí.', 403);
        $from = new DateTimeImmutable($request['begins']);
        $to = new DateTimeImmutable($request['ends']);
        $start = $from->setTimezone(new \DateTimeZone('Europe/Prague'))->setTime(0,0);
        $end = $to->setTimezone($start->getTimezone())->modify('-1 microsecond')->setTime(0,0)->modify('+1 day');
        $windows = self::requestWindows($request);
        $scope = ['units'=>$this->directory->managedUnits($actor), 'self'=>$person['id'], 'first'=>$start->format('Y-m-d'), 'end'=>$end->format('Y-m-d')];
        $team = $this->db->createQueryBuilder()->select('id','name','starts','LEAST(ends,archived_on) AS last')
            ->from('employee')->where('org_unit_id IN (:units)', 'id <> :self', 'attendance_enabled',
                'starts < :end', '(LEAST(ends,archived_on) IS NULL OR LEAST(ends,archived_on) >= :first)')
            ->setParameters($scope, ['units'=>\Doctrine\DBAL\ArrayParameterType::INTEGER])->orderBy('name')->fetchAllAssociative();
        if (!$team) return [];
        $others = $this->db->createQueryBuilder()->select('r.employee','r.begins','r.ends','r.status','r.vacation_estimate','a.behavior')
            ->from('request','r')->innerJoin('r','attendance_account','a','a.code=r.kind')
            ->where('r.employee IN (:ids)', "r.status IN ('approved','pending')", "a.behavior IN ('shift_absence','timed_absence')",
                'r.begins < :end','r.ends > :start','NOT r.generated_holiday')
            ->setParameter('ids', array_column($team,'id'), \Doctrine\DBAL\ArrayParameterType::INTEGER)
            ->setParameter('start',$from->format(DATE_ATOM))->setParameter('end',$to->format(DATE_ATOM))
            ->orderBy('r.begins')->fetchAllAssociative();
        $absences = [];
        foreach ($others as $r) {
            foreach (self::requestWindows($r) as [$a,$b]) {
                foreach ($windows as [$x,$y]) {
                    if ($a >= $y || $b <= $x) continue;
                    $absences[$r['employee']][] = ['status'=>$r['status'], 'from'=>$start->setTimestamp(max($a,$x))->format(DATE_ATOM),
                        'to'=>$start->setTimestamp(min($b,$y))->format(DATE_ATOM), 'whole_day'=>$r['behavior'] === 'shift_absence' && !$r['vacation_estimate'] && !$request['vacation_estimate']];
                }
            }
        }
        $result = [];
        foreach ($team as $member) {
            $begin = max($start, WorkModels::day($member['starts']));
            $until = $member['last'] ? min($end, WorkModels::day($member['last'])->modify('+1 day')) : $end;
            $plan = $this->models->schedule($member['id'], $begin, $until);
            $seconds = 0;
            foreach ($plan as $ranges) foreach ($ranges as [$a,$b]) foreach ($windows as [$x,$y]) {
                $seconds += max(0,min($b,$y) - max($a,$x));
            }
            $result[] = ['name'=>$member['name'], 'planned'=>$plan ? $seconds : null, 'absences'=>$absences[$member['id']] ?? []];
        }
        return $result;
    }

    private static function requestWindows(array $request): array
    {
        if ($request['vacation_estimate']) {
            $ranges = json_decode($request['vacation_estimate'], true, flags: JSON_THROW_ON_ERROR);
            return array_map(static fn($r) => [strtotime($r['from']), strtotime($r['to'])], $ranges);
        }
        return [[strtotime($request['begins']), strtotime($request['ends'])]];
    }

    public function overview(array $input): array
    {
        $team = ($input['team'] ?? true) === true;
        $actor = $this->accounts->actor($team ? 'manager' : ['hr', 'payroll', 'admin']);
        $month = $input['month'] ?? date('Y-m');
        $units = $team ? $this->directory->managedUnits($actor) : null;
        $people = $tasks = [];
        if ($team) {
            $tasks = $this->db->createQueryBuilder()->select('e.id AS employee', 'e.name', 'r.id AS request',
                'a.name AS reason', 'r.begins::date AS day', "'manager' AS owner")
                ->from('request', 'r')->innerJoin('r', 'employee', 'e', 'e.id=r.employee')
                ->innerJoin('r', 'attendance_account', 'a', 'a.code=r.kind')
                ->where("r.status='pending'", 'e.attendance_enabled', 'e.id <> :self', 'e.org_unit_id IN (:units)')
                ->setParameter('self', $actor['id'])->setParameter('units', $units, \Doctrine\DBAL\ArrayParameterType::INTEGER)
                ->orderBy('r.begins')->addOrderBy('r.id')->fetchAllAssociative();
        }
        foreach ($this->directory->roster($month, $units) as $person) {
            $s = $this->sheet($person, $month);
            $fund = $s['fund'];
            $days = array_filter($fund['days'], static fn($d) => $d['difference'] !== 0);
            $people[] = ['employee'=>$person['id'], 'name'=>$person['name'], 'approved'=>$s['approved'],
                'planned'=>$fund['planned'], 'work'=>$fund['work'], 'absence'=>$fund['absence'], 'difference'=>$fund['difference'],
                'days'=>array_keys($days)];
            if (!$team || $person['id'] === $actor['id'] || $s['approved']) continue;
            foreach ($s['requests'] as $r) {
                if ($r['status'] !== 'approved') continue;
                if ((!$r['requires_proof'] || $r['has_proof']) && (empty($r['needs_actual']) || (!empty($r['actual_to']) && empty($r['actual_returned'])))) continue;
                if (empty($r['actual_to']) && strtotime($r['ends']) > time()) continue;
                $tasks[] = ['employee'=>$person['id'], 'name'=>$person['name'], 'day'=>substr($r['begins'],0,10),
                    'request'=>$r['id'], 'owner'=>'employee', 'reason'=>'Doplnit skutečnost nebo doklad · '.$r['account_name']];
            }
            foreach ($s['approval_tasks'] as $task) {
                if (in_array($task['code'], ['pending','missing_proofs'], true)) continue;
                $tasks[] = ['employee'=>$person['id'], 'name'=>$person['name'], 'owner'=>'manager',
                    'day'=>$month.'-01', 'reason'=>$task['reason']];
            }
            if (!$s['approval_tasks'] && $month <= date('Y-m')) {
                $tasks[] = ['employee'=>$person['id'], 'name'=>$person['name'], 'owner'=>'manager',
                    'day'=>array_key_first($fund['review']) ?? $month.'-01',
                    'reason'=>$fund['review'] ? 'Prověřit odchylky výkazu' : 'Zkontrolovat výkaz'];
            }
        }
        if (!$team) return compact('people','tasks');
        $covers = $this->directory->covers($actor);
        $reviewers = $this->directory->reviewers($actor,date('Y-m-d'),date('Y-m-d'));
        $memberUnits = array_column($this->directory->people(), 'org_unit_id', 'id');
        foreach ($covers as $cover) {
            if (!$cover['active']) continue;
            $branch = $this->directory->managedUnits(['roles'=>['manager'],'org_unit_id'=>$cover['org_unit_id']]);
            foreach ($tasks as &$task) {
                if ($task['owner'] !== 'manager' || $task['employee'] === $cover['manager'] ||
                    !in_array($memberUnits[$task['employee']] ?? null, $branch,true)) continue;
                $task['substitute'] = $cover['substitute_name'];
            }
            unset($task);
        }
        return compact('people','tasks','covers','reviewers');
    }

    public function view(array $i): array
    {
        $actor=$this->accounts->actor();
        $person=$this->directory->person($actor,(int)($i['employee'] ?? $actor['id']));
        if ($person['id'] === $actor['id'] && !$actor['attendance_enabled']) {
            throw new Problem('Tomuto účtu se docházka nezpracovává.', 403);
        }
        $sheet = $this->sheet($person, $i['month'] ?? date('Y-m'));
        $sheet['reviewers'] = $this->directory->reviewers($person,date('Y-m-d'),date('Y-m-d'));
        foreach ($sheet['requests'] as $index => $request) {
            foreach (['begins', 'ends', 'decided', 'replaces_from', 'replaces_to', 'actual_from', 'actual_to'] as $field) {
                if (isset($request[$field])) {
                    $sheet['requests'][$index][$field] = (new DateTimeImmutable($request[$field]))->format(DATE_RFC3339_EXTENDED);
                }
            }
        }
        if ($sheet['approval']) {
            $sheet['approval']['approved'] = (new DateTimeImmutable($sheet['approval']['approved']))->format(DATE_RFC3339_EXTENDED);
        }
        if ($person['id'] !== $actor['id'] && !$person['managed']) {
            foreach ($sheet['requests'] as &$request) {
                unset($request['note'], $request['reason']);
            }
            unset($request);
            if ($sheet['approval']) {
                unset($sheet['approval']['note']);
            }
        }
        return $sheet;
    }

    public function confirm(array $i): array
    {
        $actor=$this->accounts->actor('manager');
        $person=$this->directory->person($actor,(int)($i['employee'] ?? 0),true);
        if ($person['id'] === $actor['id']) {
            throw new Problem('Vlastní výkaz musí schválit jiný vedoucí týmu.', 403);
        }
        if (!$person['attendance_enabled']) {
            throw new Problem('Tomuto účtu se docházka nezpracovává.', 409);
        }
        $month = Timesheet::month($i['month'] ?? '');
        if ($person['starts'] >= $month->modify('+1 month')->format('Y-m-d')
            || ($person['ends'] !== null && $person['ends'] < $month->format('Y-m-d'))) {
            throw new Problem('Zaměstnanec v tomto měsíci nebyl v evidenci.', 409);
        }
        $this->approve($actor,$person,$i);
        return ['ok'=>true];
    }

    public function merge(array $input): array
    {
        $actor = $this->accounts->actor('manager');
        $person = $this->directory->person($actor, (int) ($input['employee'] ?? 0), true);
        if ($person['id'] === $actor['id'] || !$person['attendance_enabled']) {
            throw new Problem('Spojení může potvrdit vedoucí jiného účtu s evidovanou docházkou.', 403);
        }
        $sheet = $this->sheet($person, $input['month'] ?? '');
        if ($sheet['fingerprint'] !== ($input['fingerprint'] ?? '')) {
            throw new Problem('Výkaz se změnil. Vyberte úseky znovu.', 409);
        }
        $selected = $input['rows'] ?? null;
        if (!is_array($selected) || !array_is_list($selected) || count($selected) < 2) {
            throw new Problem('Vyberte alespoň dva pracovní úseky.');
        }
        $parts = [];
        $days = [];
        foreach ($selected as $index) {
            $row = is_int($index) ? ($sheet['rows'][$index] ?? null) : null;
            if (!$row || $row['kind'] !== 'PRACE' || ($row['generated_holiday'] ?? false)) {
                throw new Problem('Vyberte platné pracovní úseky.');
            }
            $parts[$index] = $row;
            $days[$row['day']] = true;
        }
        if (count($parts) < 2 || count($days) !== 1) {
            throw new Problem('Vyberte různé úseky v jednom kalendářním dni.');
        }
        $start = new DateTimeImmutable('@'.min(array_map('strtotime', array_column($parts, 'from'))));
        $end = new DateTimeImmutable('@'.max(array_map('strtotime', array_column($parts, 'to'))));
        $this->requireFreeOfAbsences($sheet, $start, $end);
        foreach ($sheet['sessions'] as $session) {
            if ($session['service'] && new DateTimeImmutable($session['from']) < $end
                && ($session['to'] === null || new DateTimeImmutable($session['to']) > $start)) {
                throw new Problem('Úseky nelze spojit přes servisní relaci.');
            }
        }
        $request = new AbsenceRequest(
            $this->em->getReference(Employee::class, $person['id']), 'PRACE', $start, $end, 'Ruční spojení pracovních úseků',
        );
        $request->mergedWork = true;
        $request->decide('approved', $this->em->getReference(Employee::class, $actor['id']), 'Potvrzeno ve výkazu.');
        $this->em->persist($request);
        $this->em->flush();
        $this->audit->audit($actor['id'], 'attendance-merge', ['request' => $request->id, 'employee' => $person['id'], 'rows' => array_values($parts)]);
        return ['ok' => true];
    }

    private function requireFreeOfAbsences(array $sheet, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        foreach ($sheet['requests'] as $request) {
            if (!($request['generated_holiday'] ?? false) && $request['status'] === 'approved' && in_array($request['behavior'], ['timed_absence', 'break'], true)
                && new DateTimeImmutable($request['begins']) < $end && new DateTimeImmutable($request['ends']) > $start) {
                throw new Problem('Výsledný pracovní úsek zasahuje do schválené přestávky nebo nepřítomnosti.');
            }
        }
        foreach ($sheet['rows'] as $row) {
            if ($row['kind'] === 'PRACE' || ($row['generated_holiday'] ?? false)) continue;
            if ($row['kind'] === 'DOVOLENA' && $row['seconds'] !== null && empty($row['partial_vacation']) && $row['from'] === '') {
                foreach ($sheet['planned_shifts']->{$row['day']} ?? [] as $part) {
                    if (new DateTimeImmutable($part['from']) < $end && new DateTimeImmutable($part['to']) > $start) {
                        throw new Problem('Výsledný úsek zasahuje do schválené dovolené.');
                    }
                }
                continue;
            }
            $from = $row['from'] === '' ? WorkModels::day($row['day']) : new DateTimeImmutable($row['from']);
            $to = $row['to'] === '' ? $from->modify('+1 day') : new DateTimeImmutable($row['to']);
            if ($from < $end && $to > $start) {
                throw new Problem('Výsledný úsek zasahuje do jiného schváleného konta.');
            }
        }
    }

    public function adjustDuration(array $input): array
    {
        [$actor, $person, $sheet] = $this->correctionContext($input);
        $stale = ($input['stale'] ?? false) === true;
        $index = $input['row'] ?? null;
        $row = !is_int($index) ? null : ($stale ? ($sheet['unresolved_adjustments'][$index] ?? null) : ($sheet['rows'][$index] ?? null));
        if (!$row || ($row['generated_holiday'] ?? false) || Catalog::behavior($row['kind'], $this->catalog->accounts()) !== 'work'
            || !$row['from'] || !$row['to']) {
            throw new Problem('Vyberte platný pracovní úsek.');
        }
        $seconds = $input['seconds'] ?? null;
        if ($seconds !== null && ($stale || !is_int($seconds) || $seconds < 0 || $seconds > strtotime($row['to']) - strtotime($row['from']))) {
            throw new Problem('Uznaná doba musí být mezi nulou a vypočtenou délkou.');
        }
        $key = ['employee' => $person['id'], 'day' => $row['day'], 'account' => $row['kind'],
            'begins' => $row['from'], 'ends' => $row['to']];
        $this->db->delete('recognized_work', $key);
        if ($seconds !== null) $this->db->insert('recognized_work', $key + ['seconds' => $seconds]);
        $restored = $stale ? null : strtotime($row['to']) - strtotime($row['from']);
        $this->audit->audit($actor['id'], 'recognized-work', $key + ['before' => $row['seconds'],
            'after' => $seconds ?? $restored, 'reset' => $seconds === null, 'reason' => $input['reason']]);
        return ['ok' => true];
    }

    public function adjustBreak(array $input): array
    {
        [$actor, $person, $sheet] = $this->correctionContext($input);
        $day = WorkModels::day($input['day'] ?? '');
        $date = $day->format('Y-m-d');
        if ($day->format('Y-m') !== $input['month'] || $date < $person['starts'] || ($person['ends'] && $date > $person['ends'])
            || $date > (new DateTimeImmutable('today'))->format('Y-m-d')) {
            throw new Problem('Vyberte platný den evidence.');
        }
        $mode = $input['mode'] ?? '';
        if (!in_array($mode, ['record','none','ack','auto'], true)) throw new Problem('Vyberte způsob evidence přestávky.');
        $prior = $this->em->find(AbsenceRequest::class, (int) ($input['id'] ?? 0));
        if (!empty($input['id']) && (!$prior || $prior->employee->id !== $person['id'] || $prior->kind !== 'PRESTAVKA'
            || $prior->status !== 'approved' || $prior->begins->format('Y-m-d') !== $date)) {
            throw new Problem('Přestávka již není dostupná.', 409);
        }
        $key = ['employee' => $person['id'], 'day' => $date];
        $this->db->delete('attendance_break_exception', $key);
        if ($mode === 'ack' && !$prior) throw new Problem('Vyberte doloženou přestávku.');
        if ($prior && $mode !== 'ack') $prior->decide('rejected', $this->em->getReference(Employee::class, $actor['id']), $input['reason']);
        if ($mode === 'none' || $mode === 'ack') $this->db->insert('attendance_break_exception', $key + ['mode' => $mode]);
        if ($mode === 'record') {
            $from = self::correctionTime($input['from'] ?? '', $day, null);
            $to = self::correctionTime($input['to'] ?? '', $day, null);
            $presence = $sheet['presence'] ?? $sheet['rows'];
            $before = $after = false;
            foreach ($presence as $row) {
                if ($row['day'] !== $date || !$row['from'] || !$row['to']
                    || (isset($row['kind']) && (($row['generated_holiday'] ?? false) || Catalog::behavior($row['kind'], $this->catalog->accounts()) !== 'work'))) continue;
                $before = $before || strtotime($row['from']) < $from->getTimestamp();
                $after = $after || strtotime($row['to']) > $to->getTimestamp();
            }
            if ($from >= $to || $to > new DateTimeImmutable() || !$before || !$after) {
                throw new Problem('Přestávka musí ležet uvnitř pracovní doby.');
            }
            $break = new AbsenceRequest($this->em->getReference(Employee::class, $person['id']), 'PRESTAVKA', $from, $to, $input['reason']);
            $break->decide('approved', $this->em->getReference(Employee::class, $actor['id']), $input['reason']);
            $this->em->persist($break);
        }
        $this->audit->audit($actor['id'], 'attendance-break', $key + ['mode' => $mode, 'previous_request' => $prior?->id,
            'from' => $input['from'] ?? null, 'to' => $input['to'] ?? null, 'reason' => $input['reason']]);
        return ['ok' => true];
    }

    private function correctionContext(array $input): array
    {
        $actor = $this->accounts->actor('manager');
        $person = $this->directory->person($actor, (int) ($input['employee'] ?? 0), true);
        if ($person['id'] === $actor['id'] || !$person['attendance_enabled']) throw new Problem('Tuto docházku nelze upravit.', 403);
        $sheet = $this->sheet($person, $input['month'] ?? '');
        if ($sheet['fingerprint'] !== ($input['fingerprint'] ?? '')) throw new Problem('Výkaz se změnil. Načtěte jej znovu.', 409);
        $reason = trim($input['reason'] ?? '');
        if ($reason === '' || strlen($reason) > 4000 || str_contains($reason, "\0")) throw new Problem('Uveďte důvod úpravy.');
        return [$actor, $person, $sheet];
    }

    public function saveWork(array $input): array
    {
        $actor = $this->accounts->actor('manager');
        $person = $this->directory->person($actor, (int) ($input['employee'] ?? 0), true);
        if ($person['id'] === $actor['id'] || !$person['attendance_enabled']) {
            throw new Problem('Úsek může uložit vedoucí jiného účtu s evidovanou docházkou.', 403);
        }
        $month = $input['month'] ?? '';
        $sheet = $this->sheet($person, $month);
        if ($sheet['fingerprint'] !== ($input['fingerprint'] ?? '')) {
            throw new Problem('Výkaz se změnil. Otevřete úpravu znovu.', 409);
        }
        $index = $input['row'] ?? null;
        $row = is_int($index) ? ($sheet['rows'][$index] ?? null) : null;
        if ($index !== null && (!$row || $row['kind'] !== 'PRACE' || ($row['generated_holiday'] ?? false))) {
            throw new Problem('Vyberte platný pracovní úsek.');
        }
        $reason = trim($input['reason'] ?? '');
        if ($reason === '' || strlen($reason) > 4000 || str_contains($reason, "\0")) {
            throw new Problem('Zadejte důvod do 4000 bajtů.');
        }
        $zone = new \DateTimeZone('Europe/Prague');
        $date = $input['day'] ?? '';
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if (!$day || $day->format('Y-m-d') !== $date || $day->format('Y-m') !== $month
            || $date < $person['starts'] || ($person['ends'] !== null && $date > $person['ends'])
            || ($row !== null && $row['day'] !== $date)) {
            throw new Problem('Vyberte den evidence zaměstnance ve zvoleném měsíci.');
        }
        $kind = $input['kind'] ?? 'PRACE';
        $account = $this->catalog->accounts()[$kind] ?? null;
        if (!$account || !$account['active'] || $account['behavior'] === 'calendar' || ($row !== null && $kind !== 'PRACE')) {
            throw new Problem('Vyberte platné konto pro tento záznam.');
        }
        $oldStart = $row ? new DateTimeImmutable($row['from']) : null;
        $oldEnd = $row ? new DateTimeImmutable($row['to']) : null;
        if ($account['behavior'] === 'shift_absence') {
            $start = $day;
            $end = $day->modify('+1 day');
            $this->models->chargeableShifts($person['id'], $start, $end);
        } else {
            $start = self::correctionTime($input['from'] ?? '', $day, $oldStart);
            $end = self::correctionTime($input['to'] ?? '', $day, $oldEnd, allowMidnight: true);
            if ($end <= $start) throw new Problem('Konec musí následovat po začátku.');
        }
        if ($row !== null && $start == $oldStart && $end == $oldEnd) {
            throw new Problem('Upravte začátek nebo konec úseku.');
        }
        if ($kind === 'PRACE') {
            foreach ($sheet['rows'] as $otherIndex => $other) {
                if ($otherIndex !== $index && $other['kind'] === 'PRACE' && !($other['generated_holiday'] ?? false)
                    && new DateTimeImmutable($other['from']) < $end && new DateTimeImmutable($other['to']) > $start) {
                    throw new Problem('Upravený čas se překrývá s jiným pracovním úsekem.');
                }
            }
            $this->requireFreeOfAbsences($sheet, $start, $end);
        }
        if ($account['behavior'] === 'work') {
            if ($end > new DateTimeImmutable()) throw new Problem('Práci lze zapsat až po skončení úseku.');
            foreach ($sheet['sessions'] as $session) {
                if ($session['service'] && new DateTimeImmutable($session['from']) < $end
                    && ($session['to'] === null || new DateTimeImmutable($session['to']) > $start)) {
                    throw new Problem('Úprava zasahuje do servisní relace.');
                }
            }
        }
        $request = new AbsenceRequest($this->em->getReference(Employee::class, $person['id']), $kind, $start, $end, $reason);
        $request->requiresProof = $account['requires_proof'];
        if ($account['behavior'] === 'timed_absence') {
            if ($end > new DateTimeImmutable()) throw new Problem('Budoucí nepřítomnost zadejte jako žádost.');
            $request->needsActual = true;
            $request->actualFrom = $start;
            $request->actualTo = $end;
        }
        $this->requests->requireCompatible($request);
        $request->replacesFrom = $oldStart;
        $request->replacesTo = $oldEnd;
        $request->decide('approved', $this->em->getReference(Employee::class, $actor['id']),
            $row === null ? 'Ručně přidáno ve výkazu.' : 'Upraveno ve výkazu.');
        $this->em->persist($request);
        $this->em->flush();
        $this->audit->audit($actor['id'], $row === null ? 'attendance-manual' : 'attendance-correction', [
            'request' => $request->id, 'employee' => $person['id'], 'day' => $date, 'kind' => $kind, 'reason' => $reason,
            'before' => $row === null ? null : ['from' => $row['from'], 'to' => $row['to']],
            'after' => ['from' => $start->format(DATE_ATOM), 'to' => $end->format(DATE_ATOM)],
        ]);
        return ['ok' => true];
    }

    private static function correctionTime(string $time, DateTimeImmutable $day, ?DateTimeImmutable $original, bool $allowMidnight = false): DateTimeImmutable
    {
        $time = strlen($time) === 5 ? $time.':00' : $time;
        if ($allowMidnight && $time === '24:00:00') {
            return $day->modify('+1 day');
        }
        if ($original?->setTimezone($day->getTimezone())->format('Y-m-d H:i:s') === $day->format('Y-m-d').' '.$time) {
            return $original;
        }
        $value = $day->format('Y-m-d').' '.$time;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $day->getTimezone());
        if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $value) {
            throw new Problem('Zadejte platný čas ve formátu HH:MM nebo HH:MM:SS.');
        }
        return $parsed;
    }
}
