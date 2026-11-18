<?php
declare(strict_types=1);

namespace Dochazka\Absence;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
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
        private Accounts $accounts,
        private AuditTrail $audit,
    ) {}

    public function submit(array $i): array
    {
        $actor=$this->accounts->actor();
        $who=$actor['id'];
        $employee = (int) ($i['employee'] ?? $who);
        $person = $this->directory->person($actor, $employee, review: $employee !== $who);
        if (!$person['attendance_enabled']) {
            throw new Problem('Tomuto účtu se docházka nezpracovává.', 409);
        }
        return $this->request($person,$i,$who);
    }

    private function request(array $person,array $i,int $who): array
    {
        $kind=$i['kind'] ?? '';
        if (!in_array($kind,['PRACE','PRESTAVKA','LEKAR','DOVOLENA'],true)) throw new Problem('Neplatný druh.');
        $allDay=$kind==='DOVOLENA';
        $format=$allDay?'Y-m-d':'Y-m-d\TH:i';
        $parse=static function(string $s) use($format) {
            $d=\DateTimeImmutable::createFromFormat('!'.$format,$s);
            if (!$d || $d->format($format)!==$s) throw new Problem('Neplatné datum nebo čas.');
            return $d;
        };
        $a=$parse($i['from'] ?? ''); $b=$parse($i['to'] ?? '');
        if ($allDay) $b=$b->modify('+1 day');
        if ($b<=$a) throw new Problem('Konec musí následovat po začátku.');
        $starts = new DateTimeImmutable($person['starts'], $a->getTimezone());
        $ends = $person['ends'] !== null ? (new DateTimeImmutable($person['ends'], $a->getTimezone()))->modify('+1 day') : null;
        if ($a < $starts || ($ends !== null && $b > $ends)) {
            throw new Problem('Celá žádost musí spadat do období zaměstnání.');
        }
        if (isset($i['photo'])) {
            throw new Problem('Propustku nahrajte ke schválené žádosti po návštěvě lékaře.');
        }
        $request = new AbsenceRequest(
            $this->em->getReference(Employee::class, $person['id']),
            $kind,
            $a,
            $b,
            trim($i['note'] ?? ''),
        );
        $this->em->persist($request);
        $this->em->flush();
        $this->audit->audit($who, 'request', ['id' => $request->id]);
        return ['id' => $request->id];
    }

    public function uploadProof(array $input): array
    {
        $actor = $this->accounts->actor();
        $id = (int) ($input['id'] ?? 0);
        $request = $this->em->find(AbsenceRequest::class, $id);
        if (!$request || $request->employee->id !== $actor['id']) {
            throw new Problem('Žádost není dostupná.', 404);
        }
        if (!$actor['attendance_enabled']) {
            throw new Problem('Tomuto účtu se docházka nezpracovává.', 409);
        }
        if ($request->kind !== 'LEKAR' || $request->status !== 'approved') {
            throw new Problem('Propustku lze přiložit ke schválené návštěvě lékaře.', 409);
        }
        if ($request->ends > new DateTimeImmutable()) {
            throw new Problem('Propustku nahrajte po skončení návštěvy lékaře.', 409);
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
            !Directory::manages($actor, $request->employee->team))) {
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
        $status=$i['status'] ?? ''; $reason=trim($i['reason'] ?? '');
        if (!in_array($status,['approved','rejected'],true) || ($status==='rejected' && !$reason)) throw new Problem('Chybí rozhodnutí nebo důvod.');
        if ($r->status !== 'pending' && !($r->status === 'approved' && $status === 'rejected')) {
            throw new Problem('Žádost už byla vyřízena.', 409);
        }
        $r->decide($status, $this->em->getReference(Employee::class, $who), $reason);
        $this->audit->audit($who, 'decision', ['id' => $r->id, 'status' => $status, 'reason' => $reason]);
        return ['ok'=>true];
    }
}
