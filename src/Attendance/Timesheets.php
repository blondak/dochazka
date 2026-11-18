<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DateTimeImmutable;
use Dochazka\Absence\AbsenceRequest;
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
    ) {}

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
        $requests = $start >= $end ? [] : $this->db->fetchAllAssociative(
            'SELECT r.*, EXISTS(SELECT 1 FROM medical_proof p WHERE p.request_id=r.id) AS has_proof FROM request r WHERE employee=? AND begins < ? AND ends > ? ORDER BY begins,id',
            [$person['id'],$end->format(DATE_ATOM),$start->format(DATE_ATOM)]);
        foreach ($requests as &$request) {
            if (!$request['merged_work']) unset($request['merged_work']);
        }
        unset($request);
        $sessions = array_values(array_filter($sessions, static fn(array $session) => $session['from'] !== null));
        $serviceRanges = [];
        foreach ($sessions as $session) {
            if ($session['service']) {
                $serviceRanges[] = [strtotime($session['from']), $session['to'] === null ? PHP_INT_MAX : strtotime($session['to'])];
            }
        }
        $sheet = Timesheet::build($month, $signals, $requests, $start, $end,
            $this->mergePolicy->limits($start, $end), $serviceRanges);
        $relevant = array_values(array_filter($signals,static fn($s) => strtotime($s['happened']) >= $start->getTimestamp() && strtotime($s['happened']) < $end->getTimestamp()));
        $hash = hash('sha256',json_encode([$person['number'],$sheet,$requests,$relevant],JSON_THROW_ON_ERROR));
        $approval = $this->db->fetchAssociative('SELECT * FROM approval WHERE employee=? AND month=?',[$person['id'],$month]);
        $missingProofs = count(array_filter($requests, static fn(array $request): bool =>
            $request['kind'] === 'LEKAR' && $request['status'] === 'approved' && !$request['has_proof']
        ));
        return $sheet + [
            'sessions' => $sessions,
            'requests' => $requests,
            'missing_proofs' => $missingProofs,
            'fingerprint' => $hash,
            'approval' => $approval,
            'approved' => $missingProofs === 0 && $approval && $approval['fingerprint'] === $hash,
        ];
    }

    private function approve(array $actor,array $person,array $input): void
    {
        $sheet = $this->sheet($person,$input['month']);
        if ($sheet['fingerprint'] !== ($input['fingerprint'] ?? '') || $sheet['pending'])
            throw new Problem('Výkaz se změnil nebo obsahuje čekající žádost.',409);
        if ($sheet['missing_proofs'] > 0) {
            throw new Problem('Ke schválené návštěvě lékaře chybí propustka.', 409);
        }
        $note = trim($input['note'] ?? '');
        if ($sheet['vacation_conflict']) throw new Problem('Nejdříve opravte překryv s dovolenou.', 409);
        if ($sheet['issues'] && !$note) throw new Problem('Doplňte opravu a popište vyřešení nesrovnalostí.');
        $approval = $this->em->find(Approval::class, ['employee' => $person['id'], 'month' => $input['month']]);
        if (!$approval) {
            $approval = new Approval($this->em->getReference(Employee::class, $person['id']), $input['month']);
            $this->em->persist($approval);
        }
        $approval->confirm($this->em->getReference(Employee::class, $actor['id']), $sheet['fingerprint'], $note);
        $this->audit->audit($actor['id'],'approve',['employee'=>$person['id'],'month'=>$input['month'],'fingerprint'=>$sheet['fingerprint'],'note'=>$note]);
    }

    public function view(array $i): array
    {
        $actor=$this->accounts->actor();
        $person=$this->directory->person($actor,(int)($i['employee'] ?? $actor['id']));
        if ($person['id'] === $actor['id'] && !$actor['attendance_enabled']) {
            throw new Problem('Tomuto účtu se docházka nezpracovává.', 403);
        }
        $sheet = $this->sheet($person, $i['month'] ?? date('Y-m'));
        foreach ($sheet['requests'] as $index => $request) {
            foreach (['begins', 'ends', 'decided'] as $field) {
                if ($request[$field] !== null) {
                    $sheet['requests'][$index][$field] = (new DateTimeImmutable($request[$field]))->format(DATE_RFC3339_EXTENDED);
                }
            }
        }
        if ($sheet['approval']) {
            $sheet['approval']['approved'] = (new DateTimeImmutable($sheet['approval']['approved']))->format(DATE_RFC3339_EXTENDED);
        }
        if ($person['id'] !== $actor['id'] && !Directory::manages($actor, $person['team'])) {
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
            if (!$row || $row['kind'] !== 'PRACE') {
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
        foreach ($sheet['requests'] as $request) {
            if ($request['status'] === 'approved' && $request['kind'] !== 'PRACE'
                && new DateTimeImmutable($request['begins']) < $end && new DateTimeImmutable($request['ends']) > $start) {
                throw new Problem('Úseky nelze spojit přes schválenou přestávku nebo nepřítomnost.');
            }
        }
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
}
