<?php
declare(strict_types=1);

namespace Dochazka\Absence;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dochazka\Attendance\Catalog;
use Dochazka\Attendance\WorkModels;
use Dochazka\Staff\Directory;
use Dochazka\Staff\Employee;
use Dochazka\Identity\Accounts;
use Dochazka\Audit\AuditTrail;
use Dochazka\Http\Problem;

final class Requests
{
    public function __construct(
        private EntityManagerInterface $em,
        private Directory $directory,
        private Catalog $catalog,
        private WorkModels $models,
        private \Dochazka\Attendance\HolidayEntries $holidayEntries,
        private \Dochazka\Staff\OrgUnits $units,
        private Accounts $accounts,
        private AuditTrail $audit,
    ) {}

    public function submit(array $input): array
    {
        [$actor, $person, $account, $start, $end, $ranges, $side] = $this->validateRequest($input);
        if (isset($input['photo'])) {
            throw new Problem('Doklad nahrajte ke schválené žádosti po jejím skončení.');
        }
        $request = new AbsenceRequest(
            $this->em->getReference(Employee::class, $person['id']),
            $account['code'],
            $start,
            $end,
            trim($input['note'] ?? ''),
        );
        $request->requiresProof = $account['requires_proof'];
        $request->needsActual = $account['behavior'] === 'timed_absence';
        if ($side !== null) {
            $request->vacationSeconds = self::seconds($ranges);
            $request->vacationSide = $side;
            $request->vacationEstimate = self::formatIntervals($start, $ranges);
            $request->vacationConsent = $actor['id'] === $person['id'] && ($input['consent'] ?? false) === true;
            if ($actor['id'] === $person['id'] && !$request->vacationConsent) {
                throw new Problem('Potvrďte souhlas s částečným čerpáním dovolené.');
            }
        }
        $this->em->persist($request);
        $this->em->flush();
        $this->audit->audit($actor['id'], 'request', ['id' => $request->id]);
        return ['id' => $request->id];
    }

    public function preview(array $input): array
    {
        [, , $account, $start, , $ranges, $side] = $this->validateRequest($input);
        return [
            'seconds' => self::seconds($ranges),
            'days' => $account['behavior'] === 'shift_absence'
                ? array_values(array_unique(array_map(static fn(array $part) => $start->setTimestamp($part[0])->format('Y-m-d'), $ranges)))
                : [],
        ] + ($side === null ? [] : ['intervals' => self::formatIntervals($start, $ranges)]);
    }

    private function validateRequest(array $input): array
    {
        $actor = $this->accounts->actor();
        $employee = (int) ($input['employee'] ?? $actor['id']);
        $person = $this->directory->person($actor, $employee, review: $employee !== $actor['id']);
        if (!$person['attendance_enabled']) {
            throw new Problem('Tomuto účtu se docházka nezpracovává.', 409);
        }
        $account = $this->catalog->accounts()[$input['kind'] ?? ''] ?? null;
        if (!$account || !$account['active'] || $account['behavior'] === 'calendar') throw new Problem('Vyberte aktivní konto pro žádost.');
        $allDay = $account['behavior'] === 'shift_absence';
        $format = $allDay ? 'Y-m-d' : 'Y-m-d\TH:i';
        $parse = static function (string $value) use ($format): DateTimeImmutable {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new \DateTimeZone('Europe/Prague'));
            if (!$date || $date->format($format) !== $value || $date->format('Y') === '0000') {
                throw new Problem('Neplatné datum nebo čas.');
            }
            return $date;
        };
        $start = $parse($input['from'] ?? '');
        $end = $parse($input['to'] ?? '');
        if ($allDay) $end = $end->modify('+1 day');
        if ($end <= $start) throw new Problem('Konec musí následovat po začátku.');
        if ($start < WorkModels::day($person['starts']) || ($person['ends'] && $end > WorkModels::day($person['ends'])->modify('+1 day'))) {
            throw new Problem('Celá žádost musí spadat do období zaměstnání.');
        }
        $ranges = [[$start->getTimestamp(), $end->getTimestamp()]];
        if ($allDay) {
            if ($start->diff($end)->days > 366) throw new Problem('Jedna žádost může zahrnovat nejvýše 366 dnů.');
            $ranges = $this->models->chargeableShifts($employee, $start, $end);
        }
        $side = $input['vacation_side'] ?? null;
        if ($side !== null) {
            if ($account['code'] !== 'DOVOLENA' || $start->modify('+1 day') != $end || !in_array($side, ['start','end'], true)) {
                throw new Problem('Částečnou dovolenou zadejte pro jeden den.');
            }
            $ranges = self::vacationRanges($ranges, $side, $input['vacation_seconds'] ?? null);
        }
        return [$actor, $person, $account, $start, $end, $ranges, $side];
    }

    private static function formatIntervals(DateTimeImmutable $date, array $ranges): array
    {
        return array_map(static fn(array $part): array => ['from' => $date->setTimestamp($part[0])->format(DATE_ATOM),
            'to' => $date->setTimestamp($part[1])->format(DATE_ATOM)], $ranges);
    }

    private static function seconds(array $ranges): int
    {
        return array_sum(array_map(static fn(array $part): int => $part[1] - $part[0], $ranges));
    }

    private static function vacationRanges(array $ranges, string $side, mixed $seconds): array
    {
        $total = self::seconds($ranges);
        $minimum = intdiv($total + 1, 2);
        $seconds ??= $minimum;
        if (!is_int($seconds) || $seconds < $minimum || $seconds > $total || $total === 0) {
            throw new Problem('Zadejte nejméně polovinu a nejvýše celou čistou směnu.');
        }
        $parts = [];
        foreach ($side === 'end' ? array_reverse($ranges) : $ranges as [$from, $to]) {
            $take = min($seconds, $to - $from);
            $parts[] = $side === 'end' ? [$to - $take, $to] : [$from, $from + $take];
            $seconds -= $take;
            if ($seconds === 0) break;
        }
        return $side === 'end' ? array_reverse($parts) : $parts;
    }

    public function consent(array $input): array
    {
        $actor = $this->accounts->actor();
        $request = $this->em->find(AbsenceRequest::class, (int) ($input['id'] ?? 0));
        if (!$request || $request->employee->id !== $actor['id']) throw new Problem('Žádost není dostupná.', 404);
        if ($request->vacationSeconds === null || $request->status !== 'pending' || !$actor['attendance_enabled']) {
            throw new Problem('Tuto žádost již nelze potvrdit.', 409);
        }
        $request->vacationConsent = true;
        $this->audit->audit($actor['id'], 'vacation-consent', ['request' => $request->id, 'seconds' => $request->vacationSeconds, 'side' => $request->vacationSide]);
        return ['ok' => true];
    }

    public function actual(array $input): array
    {
        $actor = $this->accounts->actor();
        $request = $this->em->find(AbsenceRequest::class, (int) ($input['id'] ?? 0));
        if (!$request) throw new Problem('Žádost není dostupná.', 404);
        $owner = $request->employee->id === $actor['id'];
        $person = $this->directory->person($actor, $request->employee->id, !$owner);
        if (!$request->needsActual || $request->status !== 'approved' || !$person['attendance_enabled']) {
            throw new Problem('Skutečnost lze doplnit ke schválené časové žádosti.', 409);
        }
        $reason = trim($input['reason'] ?? '');
        if ((!$owner && $reason === '') || strlen($reason) > 4000) throw new Problem('Uveďte důvod změny.');
        $before = ['from' => $request->actualFrom?->format(DATE_ATOM), 'to' => $request->actualTo?->format(DATE_ATOM),
            'returned' => $request->actualReturned];
        if (($input['return'] ?? false) === true) {
            if ($owner) throw new Problem('Žádost může vrátit vedoucí.', 403);
            $request->actualReturned = true;
        } else {
            $zone = new \DateTimeZone('Europe/Prague');
            $parse = static function (string $value) use ($zone): DateTimeImmutable {
                $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $zone);
                if (!$time || $time->format('Y-m-d\TH:i') !== $value) throw new Problem('Zkontrolujte skutečné datum a čas.');
                return $time;
            };
            $from = $parse($input['from'] ?? '');
            $to = $parse($input['to'] ?? '');
            if ($from >= $to || $to > new DateTimeImmutable()
                || $from < $request->begins->setTimezone($zone)->setTime(0, 0)
                || $to > $request->ends->setTimezone($zone)->modify('-1 microsecond')->setTime(0, 0)->modify('+1 day')) {
                throw new Problem('Skutečný interval musí být ukončený a spadat do dnů schválené žádosti.');
            }
            if ($request->actualReturned && $owner && $request->requiresProof && isset($input['photo'])) {
                $proof = $this->em->find(ProofAttachment::class, $request->id);
                if ($proof) $proof->jpeg = MedicalProof::normalize($input['photo']);
                else $this->em->persist(new ProofAttachment($request, MedicalProof::normalize($input['photo'])));
            } elseif (isset($input['photo'])) {
                if (!$owner || !$request->requiresProof) throw new Problem('Doklad může přiložit zaměstnanec.', 403);
                if (!$this->em->find(ProofAttachment::class, $request->id)) {
                    $this->em->persist(new ProofAttachment($request, MedicalProof::normalize($input['photo'])));
                }
            }
            $request->actualFrom = $from;
            $request->actualTo = $to;
            $request->actualReturned = false;
        }
        $this->audit->audit($actor['id'], $request->actualReturned ? 'request-actual-return' : 'request-actual',
            ['request' => $request->id, 'reason' => $reason, 'before' => $before, 'from' => $request->actualFrom?->format(DATE_ATOM),
                'to' => $request->actualTo?->format(DATE_ATOM), 'proof' => isset($input['photo'])]);
        return ['ok' => true];
    }

    public function uploadProof(array $input): array
    {
        $actor = $this->accounts->actor();
        $id = (int) ($input['id'] ?? 0);
        $request = $this->em->find(AbsenceRequest::class, $id);
        if (!$request || $request->employee->id !== $actor['id'] || ($request->generatedHoliday && !$this->holidayEntries->proofVisible($id))) {
            throw new Problem('Žádost není dostupná.', 404);
        }
        if (!$actor['attendance_enabled']) {
            throw new Problem('Tomuto účtu se docházka nezpracovává.', 409);
        }
        if (!$request->requiresProof || $request->status !== 'approved') {
            throw new Problem('Doklad lze přiložit ke schválené žádosti, která ho vyžaduje.', 409);
        }
        if (($request->actualTo ?? $request->ends) > new DateTimeImmutable()) {
            throw new Problem('Doklad nahrajte po skončení žádosti.', 409);
        }
        if ($this->em->find(ProofAttachment::class, $id)) {
            throw new Problem('Propustka už byla nahrána.', 409);
        }
        $jpeg = MedicalProof::normalize($input['photo'] ?? '');
        $this->em->persist(new ProofAttachment($request, $jpeg));
        $this->audit->audit($actor['id'], 'upload_medical_proof', ['request' => $id]);
        return ['ok' => true];
    }

    public function proof(array $input): array
    {
        $actor = $this->accounts->actor();
        $id = (int) ($input['id'] ?? 0);
        $request = $this->em->find(AbsenceRequest::class, $id);
        if (!$request || ($actor['id'] !== $request->employee->id &&
            !isset($this->units->managed($actor)[$request->employee->orgUnit->id]))
            || ($request->generatedHoliday && !$this->holidayEntries->proofVisible($id))) {
            throw new Problem('Příloha není dostupná.', 404);
        }
        $proof = $this->em->find(ProofAttachment::class, $id);
        if (!$proof) {
            throw new Problem('Příloha není dostupná.', 404);
        }
        $this->audit->audit($actor['id'], 'view_medical_proof', ['request' => $id]);
        return ['jpeg' => $proof->jpeg];
    }

    public function decide(array $i): array
    {
        $actor=$this->accounts->actor('manager');
        $who=$actor['id'];
        $r = $this->em->find(AbsenceRequest::class, (int) ($i['id'] ?? 0));
        if (!$r) throw new Problem('Žádost nenalezena.',404);
        $this->directory->person($actor, $r->employee->id, true);
        if ($r->employee->id === $who) {
            throw new Problem('O vlastní žádosti musí rozhodnout jiný vedoucí týmu.', 403);
        }
        if (!$r->employee->attendanceEnabled) {
            throw new Problem('Tomuto účtu se docházka nezpracovává.', 409);
        }
        if ($r->generatedHoliday) throw new Problem('O automatickém zápisu rozhoduje kalendář.', 409);
        if ($r->replacesFrom !== null) {
            throw new Problem('Opravu lze změnit další úpravou přímo ve výkazu.', 409);
        }
        $status=$i['status'] ?? ''; $reason=trim($i['reason'] ?? '');
        if (!in_array($status,['approved','rejected'],true) || ($status==='rejected' && !$reason)) throw new Problem('Chybí rozhodnutí nebo důvod.');
        if ($r->status !== 'pending' && !($r->status === 'approved' && $status === 'rejected')) {
            throw new Problem('Žádost už byla vyřízena.', 409);
        }
        if ($status === 'approved') {
            if ($r->vacationSeconds !== null) {
                if (!$r->vacationConsent) throw new Problem('Čeká na souhlas zaměstnance.', 409);
                self::vacationRanges($this->models->chargeableShifts($r->employee->id, $r->begins, $r->ends), $r->vacationSide, $r->vacationSeconds);
            }
            $this->requireCompatible($r);
        }
        $r->decide($status, $this->em->getReference(Employee::class, $who), $reason);
        $this->audit->audit($who, 'decision', ['id' => $r->id, 'status' => $status, 'reason' => $reason]);
        return ['ok'=>true];
    }
    public function requireCompatible(AbsenceRequest $request): void
    {
        if (in_array($request->kind, ['PRACE', 'PRESTAVKA'], true)) return;
        $accounts = $this->catalog->accounts();
        $db = $this->em->getConnection();
        $others = $db->createQueryBuilder()->select('kind', 'begins', 'ends', 'vacation_seconds')->from('request')
            ->where('employee = :employee', 'NOT generated_holiday', 'id <> :id', "status = 'approved'", 'begins < :end', 'ends > :start')
            ->andWhere("kind NOT IN ('PRACE', 'PRESTAVKA')")
            ->setParameter('employee', $request->employee->id)->setParameter('id', $request->id ?? 0)
            ->setParameter('start', $request->begins->format(DATE_ATOM))->setParameter('end', $request->ends->format(DATE_ATOM))
            ->fetchAllAssociative();
        if (!$others) return;
        $zone = new \DateTimeZone('Europe/Prague');
        $start = $request->begins->setTimezone($zone);
        $end = $request->ends->setTimezone($zone);
        $schedule = $this->models->schedule($request->employee->id, $start, $end);
        $effective = static function (string $kind, DateTimeImmutable $from, DateTimeImmutable $to) use ($accounts, $schedule, $start, $end): array {
            $a = max($from, $start)->getTimestamp();
            $b = min($to, $end)->getTimestamp();
            if (Catalog::behavior($kind, $accounts) !== 'shift_absence') return [[$a, $b]];
            $day = $start->setTimestamp($a)->setTime(0, 0);
            $ranges = [];
            while ($day->getTimestamp() < $b) {
                $next = $day->modify('+1 day');
                foreach ($schedule[$day->format('Y-m-d')] ?? [[$day->getTimestamp(), $next->getTimestamp()]] as [$x, $y]) {
                    if (max($a, $x) < min($b, $y)) $ranges[] = [max($a, $x), min($b, $y)];
                }
                $day = $next;
            }
            return $ranges;
        };
        $ranges = $effective($request->kind, $start, $end);
        foreach ($others as $other) {
            if ($request->vacationSeconds !== null || $other['vacation_seconds'] !== null) {
                if ($request->kind === 'DOVOLENA' && $other['kind'] === 'DOVOLENA') {
                    throw new Problem('Pro tento den již existuje schválená dovolená.', 409);
                }
                continue;
            }
            $otherRanges = $effective($other['kind'], new DateTimeImmutable($other['begins']), new DateTimeImmutable($other['ends']));
            foreach ($ranges as [$from, $to]) {
                if (array_any($otherRanges, static fn(array $part) => $part[0] < $to && $part[1] > $from)) {
                    throw new Problem('Žádost se překrývá s jiným schváleným kontem.', 409);
                }
            }
        }
    }
}
