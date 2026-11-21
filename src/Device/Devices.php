<?php
declare(strict_types=1);

namespace Dochazka\Device;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Dochazka\Identity\Accounts;
use Dochazka\Http\Problem;
use Dochazka\Audit\AuditTrail;
use Dochazka\Audit\AuditEntry;
use Dochazka\Staff\Employee;

final class Devices
{
    public function __construct(
        private Connection $db,
        private EntityManagerInterface $em,
        private Accounts $accounts,
        private AuditTrail $audit,
    ) {}

    public function enroll(array $input): array
    {
        $secret = bin2hex(random_bytes(32));
        $device = $this->em->getRepository(Device::class)->findOneBy([
            'enrollment' => hash('sha256', $input['code'] ?? ''),
            'revoked' => false,
        ]);
        if (!$device || !$device->expires || $device->expires <= new DateTimeImmutable()) {
            throw new Problem('Neplatný registrační kód.', 401);
        }
        $device->enroll(hash('sha256', $secret));
        return ['token'=>$secret];
    }

    public function authenticate(string $authorization): int
    {
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$m)) throw new Problem('Přístup zařízení zamítnut.',401);
        $device = $this->em->getRepository(Device::class)->findOneBy([
            'credential' => hash('sha256', $m[1]),
            'revoked' => false,
        ]);
        if (!$device) throw new Problem('Přístup zařízení odebrán.',401);
        return $device->id;
    }

    public function enrollDomain(string $principal): array
    {
        $device = $this->em->getRepository(Device::class)->findOneBy(['domainPrincipal' => $principal]);
        if ($device?->revoked) {
            throw new Problem('Přístup této stanice byl odebrán.', 403);
        }
        if (!$device) {
            $device = new Device($principal, null, null);
            $device->domainPrincipal = $principal;
            $this->em->persist($device);
        }
        $secret = bin2hex(random_bytes(32));
        $device->enroll(hash('sha256', $secret));
        $this->em->persist(new AuditEntry(null, 'domain-enroll', ['principal' => $principal]));
        return ['token' => $secret];
    }

    public function accept(int $deviceId, #[\SensitiveParameter] string $authorization, array $input): array
    {
        return $this->db->transactional(function () use ($deviceId, $authorization, $input): array {
            // Coordinate with directory changes, while allowing other stations to ingest concurrently.
            $this->db->executeStatement('SELECT pg_advisory_xact_lock_shared(718312)');
            $version = $input['agent_version'] ?? null;
            if (!is_string($version) || strlen($version) > 64 || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $version)) {
                $version = null;
            }
            // Lock just this station and recheck its token after any concurrent rotation or revocation.
            $updated = $this->db->executeStatement(
                'UPDATE device SET seen=CURRENT_TIMESTAMP(0), agent_version=COALESCE(?,agent_version) WHERE id=? AND NOT revoked AND credential=?',
                [$version, $deviceId, hash('sha256', substr($authorization, strlen('Bearer ')))],
            );
            if (!$updated) throw new Problem('Přístup zařízení odebrán.', 401);
            return $this->recordBatch($deviceId, $input);
        });
    }

    private function recordBatch(int $deviceId, array $input): array
    {
        $warning = $input['warning'] ?? null;
        if (is_string($warning) && $warning !== '') {
            $this->db->executeStatement('INSERT INTO device_warning(device,message) VALUES(?,?) ON CONFLICT(device,message) DO UPDATE SET received=now(),acknowledged=NULL',[$deviceId,mb_substr($warning,0,500)]);
        }
        $resolved = $input['resolved_warning'] ?? null;
        if (is_string($resolved) && $resolved !== '' && $resolved !== $warning) {
            $this->db->executeStatement('UPDATE device_warning SET acknowledged=now() WHERE device=? AND message=? AND acknowledged IS NULL', [$deviceId, mb_substr($resolved, 0, 500)]);
        }
        $events = $input['events'] ?? null;
        if (!is_array($events) || !array_is_list($events) || count($events)>250) throw new Problem('Očekávána dávka do 250 událostí.');
        $results = [];
        foreach ($events as $e) {
            $status = 'invalid';
            $id = $e['id'] ?? null;
            if (is_string($id) && preg_match('/^[a-zA-Z0-9._:-]{1,160}$/D',$id) &&
                in_array($e['kind'] ?? '', ['login','logout','sleep','resume','gap'],true) &&
                is_string($e['sid'] ?? null) && strlen($e['sid'])<=185 &&
                preg_match('/^S-1-5-21-(?:[0-9]+-){3}[0-9]+$/Di',$e['sid']) &&
                is_string($e['session'] ?? null) && strlen($e['session'])<=180 && $e['session']!=='' &&
                is_string($e['at'] ?? null) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,7})?(?:Z|[+-]\d\d:\d\d)$/D',$e['at']) &&
                strtotime($e['at'])!==false && !date_parse($e['at'])['warning_count']) {
                $sid = strtoupper($e['sid']);
                $employee = $this->em->getRepository(Employee::class)->findOneBy(['sid' => $sid]);
                $happened = new DateTimeImmutable($e['at']);
                if (!$employee) {
                    $status = 'unmapped';
                } elseif (!$employee->attendanceEnabled || ($employee->archivedOn !== null && $happened >= $employee->archivedOn->modify('+1 day'))) {
                    $status = 'ignored';
                } else {
                    // This insert preserves subsecond timestamps and handles retransmissions atomically.
                    $at = $happened->format('Y-m-d H:i:s.uP');
                    $args = [$deviceId,$id,$e['kind'],$at,$sid,$e['session']];
                    $inserted = $this->db->executeStatement('INSERT INTO signal(device,event_id,kind,happened,sid,session_key) VALUES(?,?,?,?,?,?) ON CONFLICT DO NOTHING',$args);
                    $status = $inserted ? 'accepted' : 'duplicate';
                    if (!$inserted && !$this->db->fetchOne('SELECT 1 FROM signal WHERE device=? AND event_id=? AND kind=? AND happened=? AND sid=? AND session_key=?',$args)) $status='conflict';
                }
            }
            $results[] = compact('id','status');
        }
        return compact('results');
    }

    public function listing(): array
    {
        $this->accounts->actor('admin');
        return $this->db->fetchAllAssociative('SELECT id,name,seen,agent_version,revoked,expires,credential IS NOT NULL AS registered, (SELECT string_agg(message,chr(10) ORDER BY received) FROM device_warning WHERE device=device_table.id AND acknowledged IS NULL) AS warnings FROM device device_table ORDER BY id');
    }

    public function register(array $i): array
    {
        $this->accounts->actor('admin');
        $name=trim($i['name'] ?? '');
        if (!$name || strlen($name)>200) throw new Problem('Zkontrolujte název stanice.');
        $code=bin2hex(random_bytes(24));
        $this->em->persist(new Device($name, hash('sha256', $code), new DateTimeImmutable('+1 hour')));
        return compact('code');
    }

    public function revoke(array $i): array
    {
        $who=$this->accounts->actor('admin')['id'];
        $device = $this->em->find(Device::class, (int) $i['id']);
        $device?->revoke();
        $this->audit->audit($who,'revoke',['device'=>$i['id']]);
        return ['ok'=>true];
    }
}
