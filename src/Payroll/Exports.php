<?php
declare(strict_types=1);

namespace Dochazka\Payroll;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Dochazka\Attendance\Timesheets;
use Dochazka\Attendance\Timesheet;
use Dochazka\Http\Problem;
use Dochazka\Audit\AuditTrail;
use Dochazka\Identity\Accounts;
use Dochazka\Staff\Employee;
use Dochazka\Staff\EmployeeNumber;

final class Exports
{
    public function __construct(
        private Connection $db,
        private EntityManagerInterface $em,
        private Timesheets $sheets,
        private AuditTrail $audit,
        private Accounts $accounts,
    ) {}

    private function roster(string $month): array
    {
        $start = Timesheet::month($month);
        $end = $start->modify('+1 month');
        $effectiveEnd = 'LEAST(e.ends, e.archived_on)';
        return $this->db->createQueryBuilder()
            ->select('e.id', 'e.name', 'e.number', 'e.sid', 'e.starts', "$effectiveEnd AS ends")
            ->from('employee', 'e')
            ->where('e.attendance_enabled', 'e.starts < :end')
            ->andWhere("$effectiveEnd IS NULL OR $effectiveEnd >= GREATEST(:start, e.starts)")
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('e.number')
            ->fetchAllAssociative();
    }

    private function payroll(string $month, ?int $closeBy=null): array
    {
        $versions = $this->db->fetchAllAssociative('SELECT version,fingerprint,created FROM export WHERE month=? ORDER BY version DESC',[$month]);
        foreach ($versions as $index => $version) {
            $versions[$index]['created'] = (new \DateTimeImmutable($version['created']))->format(DATE_ATOM);
        }
        $people = $hashes = [];
        $csv = $closeBy !== null ? fopen('php://temp', 'w+') : null;
        if ($csv !== null) {
            fputcsv($csv, ['osobni_cislo','datum','druh','od','do','sekundy'], ';', '"', '', "\r\n");
        }
        foreach ($this->roster($month) as $person) {
            $s = $this->sheets->sheet($person,$month);
            $people[] = ['id'=>$person['id'],'name'=>$person['name'],'approved'=>$s['approved']];
            $hashes[] = [$person['id'],$s['fingerprint']];
            if ($closeBy && !$s['approved']) throw new Problem('Čeká na schválení: '.$person['name'],409);
            if ($csv !== null) {
                EmployeeNumber::validate($person['number']);
                foreach ($s['rows'] as $r) {
                    fputcsv($csv, [$person['number'], $r['day'], $r['payroll_code'], $r['from'], $r['to'], $r['seconds']], ';', '"', '', "\r\n");
                }
            }
        }
        $hash = hash('sha256',json_encode($hashes,JSON_THROW_ON_ERROR));
        $changed = $versions && $versions[0]['fingerprint'] !== $hash;
        if ($closeBy && (!$versions || $changed)) {
            if (!$people && !$versions) throw new Problem('Období nemá zaměstnance.');
            rewind($csv);
            $v = 1 + (int)($versions[0]['version'] ?? 0);
            $content = stream_get_contents($csv);
            fclose($csv);
            $export = new PayrollExport(
                $month, $v, $content, $hash, $this->em->getReference(Employee::class, $closeBy),
            );
            $this->em->persist($export);
            $this->audit->audit($closeBy, 'close', compact('month', 'v', 'hash'));
            array_unshift($versions, ['version' => $v, 'fingerprint' => $hash, 'created' => $export->created->format(DATE_ATOM)]);
            $changed = false;
        } elseif ($csv !== null) {
            fclose($csv);
        }
        return compact('people','versions','changed');
    }

    public function overview(array $i): array
    {
        $this->accounts->actor('payroll');
        return $this->payroll($i['month'] ?? date('Y-m'));
    }

    public function close(array $i): array
    {
        $who=$this->accounts->actor('payroll')['id'];
        return $this->payroll($i['month'] ?? date('Y-m'),$who);
    }

    public function download(array $i): array
    {
        $this->accounts->actor('payroll');
        $export = $this->em->find(PayrollExport::class, [
            'month' => $i['month'] ?? date('Y-m'),
            'version' => (int) ($i['version'] ?? 0),
        ]);
        if (!$export) {
            throw new Problem('Export nenalezen.', 404);
        }
        // Old immutable exports can predate validation of employee numbers.
        $csv = fopen('php://temp', 'w+');
        fwrite($csv, $export->content);
        rewind($csv);
        while (($row = fgetcsv($csv, separator: ';', escape: '')) !== false) {
            EmployeeNumber::validate((string) $row[0]);
        }
        fclose($csv);
        return ['csv' => $export->content];
    }
}
