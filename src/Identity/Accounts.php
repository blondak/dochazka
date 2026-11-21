<?php
declare(strict_types=1);

namespace Dochazka\Identity;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Dochazka\Http\Problem;
use Dochazka\Staff\Employee;
use Dochazka\Staff\OrgUnits;
use Nette\Http\Session;

final class Accounts
{
    public function __construct(
        private Connection $db,
        private OrgUnits $units, private EntityManagerInterface $em,
        private Session $session,
    ) {}

    public function actor(string|array|null $role = null): array
    {
        $auth = $this->session->getSection('auth');
        $id = $auth->get('user');
        $employee = $id === null ? null : $this->em->createQueryBuilder()
            ->select('e.id', 'e.name', 'e.roles', 'IDENTITY(e.orgUnit) AS org_unit_id', 'e.number', 'e.sid', 'e.attendanceEnabled AS attendance_enabled')
            ->from(Employee::class, 'e')
            ->where('e.id = :id', 'e.sessionVersion = :version', 'e.archivedOn IS NULL', 'e.starts <= CURRENT_DATE()')
            ->andWhere('e.ends IS NULL OR e.ends >= CURRENT_DATE()')
            ->setParameter('id', $id)
            ->setParameter('version', $auth->get('version'))
            ->getQuery()
            ->getOneOrNullResult();
        if (!$employee) {
            throw new Problem('Přihlaste se.', 401);
        }
        if ($role !== null && !array_intersect((array) $role, $employee['roles'])) {
            throw new Problem('Přístup zamítnut.', 403);
        }
        return $employee + ['org_unit_path' => $this->units->path($employee['org_unit_id']), 'managed' => in_array('manager', $employee['roles'], true)];
    }

    public function login(array $i): array
    {
        return $this->establishSession($this->verifyPasswordLogin($i));
    }

    public function verifyPasswordLogin(#[\SensitiveParameter] array $i): Employee
    {
        // Expire old buckets periodically even when the supplied login does not exist.
        if (random_int(1, 64) === 1) {
            $this->db->executeStatement("DELETE FROM login_limit WHERE since < now() - interval '15 minutes'");
        }
        $bucket=hash('sha256',($_SERVER['REMOTE_ADDR'] ?? '').'|'.strtolower($i['login'] ?? ''));
        $tries=$this->db->fetchOne("INSERT INTO login_limit VALUES(?,1,now()) ON CONFLICT(bucket) DO UPDATE SET
            attempts=CASE WHEN login_limit.since < now()-interval '15 minutes' THEN 1 ELSE login_limit.attempts+1 END,
            since=CASE WHEN login_limit.since < now()-interval '15 minutes' THEN now() ELSE login_limit.since END RETURNING attempts",[$bucket]);
        if ($tries>20) throw new Problem('Opakujte za 15 minut.',429);
        $employee = $this->em->getRepository(Employee::class)->findOneBy(['login' => $i['login'] ?? '']);
        if (!$employee || !password_verify($i['password'] ?? '', $employee->passwordHash)) {
            throw new Problem('Neplatné přihlášení.', 401);
        }
        $this->db->delete('login_limit',['bucket'=>$bucket]);
        return $employee;
    }

    public function requireActive(Employee $employee): void
    {
        $today = new DateTimeImmutable('today');
        if ($employee->archivedOn !== null || $employee->starts > $today || ($employee->ends && $employee->ends < $today)) {
            throw new Problem('Neplatné přihlášení.', 401);
        }
    }

    public function establishSession(Employee $employee): array
    {
        $this->requireActive($employee);
        if (!$employee->credentialsReady) {
            // Existing accounts prove possession at their first login after migration.
            $this->db->executeStatement(
                'UPDATE employee SET credentials_ready = true WHERE id = ? AND session_version = ?',
                [$employee->id, $employee->sessionVersion],
            );
        }
        $this->session->start();
        $this->session->regenerateId();
        $auth = $this->session->getSection('auth');
        $auth->set('user', $employee->id);
        $auth->set('version', $employee->sessionVersion);
        $auth->setExpiration('8 hours');
        return $this->actor();
    }

    public function verifyOwnPassword(#[\SensitiveParameter] string $password): Employee
    {
        $employee = $this->em->find(Employee::class, $this->actor()['id']);
        if (!$employee || !password_verify($password, $employee->passwordHash)) {
            throw new Problem('Současné heslo nesouhlasí.', 403);
        }
        return $employee;
    }

    public function changePassword(#[\SensitiveParameter] array $input): array
    {
        $employee = $this->verifyOwnPassword($input['current_password'] ?? '');
        $employee->passwordHash = self::newPasswordHash($input['new_password'] ?? '');
        $employee->credentialsReady = true;
        $employee->invitation = null;
        $employee->inviteUntil = null;
        ++$employee->sessionVersion;
        $this->session->getSection('auth')->set('version', $employee->sessionVersion);
        $this->session->regenerateId();
        return ['ok' => true];
    }

    private static function newPasswordHash(#[\SensitiveParameter] string $password): string
    {
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new Problem('Heslo musí mít 12 až 72 bajtů.');
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function activate(array $i): array
    {
        $employee = $this->em->getRepository(Employee::class)->findOneBy([
            'invitation' => hash('sha256', $i['code'] ?? ''),
        ]);
        if (!$employee || $employee->archivedOn !== null || !$employee->inviteUntil || $employee->inviteUntil <= new DateTimeImmutable()) {
            throw new Problem('Pozvánka již není platná.');
        }
        $employee->passwordHash = self::newPasswordHash($i['password'] ?? '');
        $employee->credentialsReady = true;
        $employee->invitation = null;
        $employee->inviteUntil = null;
        ++$employee->sessionVersion;
        $this->em->createQueryBuilder()
            ->delete(Passkey::class, 'p')
            ->where('p.employee = :employee')
            ->setParameter('employee', $employee)
            ->getQuery()
            ->execute();
        return ['ok'=>true];
    }

    public function logout(): array
    {
        $this->actor();
        $this->session->destroy();
        return ['ok'=>true];
    }
}
