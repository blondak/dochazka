<?php
declare(strict_types=1);

namespace Dochazka\Staff;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dochazka\Http\Problem;
use Dochazka\Identity\Accounts;
use Dochazka\Audit\AuditTrail;

final class Directory
{
    public function __construct(private EntityManagerInterface $em, private Accounts $accounts, private AuditTrail $audit) {}

    public static function manages(array $actor, string $team): bool
    {
        return in_array('manager', $actor['roles'], true) && $actor['team'] === $team;
    }

    public function person(array $actor,int $id,bool $review=false): array
    {
        $p = $this->em->find(Employee::class, $id);
        if (!$p) throw new Problem('Osoba nenalezena.',404);
        $canReadAll = array_intersect(['payroll', 'admin'], $actor['roles']) !== [];
        if (!self::manages($actor, $p->team) && ($review || ($actor['id'] !== $id && !$canReadAll))) {
            throw new Problem('Přístup zamítnut.', 403);
        }
        return $p->summary() + ['starts' => $p->starts->format('Y-m-d'), 'ends' => $p->attendanceEnd()?->format('Y-m-d')];
    }

    public function people(): array
    {
        $actor=$this->accounts->actor();
        $admin = in_array('admin', $actor['roles'], true);
        $criteria = match (true) {
            $admin || in_array('payroll', $actor['roles'], true) => [],
            in_array('manager', $actor['roles'], true) => ['team' => $actor['team']],
            default => null,
        };
        if ($criteria === null) {
            unset($actor['sid']);
            return [$actor + ['archived_on' => null]];
        }
        $employees = $this->em->getRepository(Employee::class)->findBy($criteria, ['name' => 'ASC']);
        return array_map(static function (Employee $employee) use ($admin): array {
            $person = $employee->summary();
            if ($admin) {
                return $person + [
                    'starts' => $employee->starts->format('Y-m-d'),
                    'ends' => $employee->ends?->format('Y-m-d'),
                    'login' => $employee->login,
                ];
            }
            unset($person['sid']);
            return $person;
        }, $employees);
    }

    private static function field(array $i,string $key): string
    {
        $s=trim($i[$key] ?? '');
        if ($s==='' || strlen($s)>200) throw new Problem('Zkontrolujte pole '.$key.'.');
        return $s;
    }

    public function savePerson(array $i): array
    {
        $who=$this->accounts->actor('admin')['id'];
        $e=empty($i['id']) ? new Employee : $this->em->find(Employee::class,(int)$i['id']);
        if (!$e) throw new Problem('Osoba nenalezena.');
        $sid=strtoupper(trim($i['sid'] ?? '')) ?: null;
        if ($e->sid && $e->sid!==$sid) throw new Problem('Přiřazený SID nelze přepsat bez migrace historie.');
        if ($sid && !preg_match('/^S-1-5-21-(?:\d+-){3}\d+$/D',$sid)) throw new Problem('Neplatný doménový SID.');
        $roles = $i['roles'] ?? null;
        if (!is_array($roles) || !array_is_list($roles) || !$roles ||
            count($roles) > count(Employee::ROLES) || array_filter($roles, static fn($role) => !in_array($role, Employee::ROLES, true))) {
            throw new Problem('Vyberte alespoň jednu platnou roli.');
        }
        $normalizedRoles = array_values(array_intersect(Employee::ROLES, $roles));
        if (count($normalizedRoles) !== count($roles)) {
            throw new Problem('Každou roli lze vybrat jednou.');
        }
        $attendanceEnabled = $i['attendance_enabled'] ?? null;
        if (!is_bool($attendanceEnabled)) {
            throw new Problem('Určete, zda se má zpracovávat docházka.');
        }
        $number = $attendanceEnabled || array_key_exists('number', $i) ? trim($i['number'] ?? '') : ($e->number ?? '');
        if ($number === '' && $attendanceEnabled) {
            throw new Problem('Při zpracování docházky vyplňte osobní číslo.');
        }
        if (strlen($number) > 200) {
            throw new Problem('Osobní číslo smí mít nejvýše 200 bajtů.');
        }
        EmployeeNumber::validate($number);
        $starts = self::date(self::field($i, 'starts'));
        $ends = empty($i['ends']) ? null : self::date($i['ends']);
        if ($ends !== null && $ends < $starts) {
            throw new Problem('Konec evidence předchází začátku.');
        }
        if (!in_array('admin', $normalizedRoles, true) || $starts > new DateTimeImmutable('today') || $ends !== null) {
            $this->requireOtherAdmin($e);
        }
        $login = self::field($i, 'login');
        $team = self::field($i, 'team');
        if ($e->id !== null && ($e->roles !== $normalizedRoles || $e->login !== $login || $e->team !== $team
            || $e->starts != $starts || $e->ends != $ends || $e->attendanceEnabled !== $attendanceEnabled)) {
            ++$e->sessionVersion;
        }
        $e->sid = $sid;
        $e->roles = $normalizedRoles;
        $e->attendanceEnabled = $attendanceEnabled;
        $e->login = $login;
        $e->team = $team;
        $e->name = self::field($i, 'name');
        $e->number = $number === '' ? null : $number;
        $e->starts = $starts;
        $e->ends = $ends;
        if (!$e->id) $e->passwordHash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
        $this->em->persist($e); $this->em->flush();
        $this->audit->audit($who, 'employee', [
            'employee' => $e->id, 'roles' => $e->roles, 'team' => $e->team, 'attendance_enabled' => $e->attendanceEnabled,
        ]);
        return ['id'=>$e->id];
    }

    public function archive(array $input): array
    {
        $who = $this->accounts->actor('admin')['id'];
        $archived = $input['archived'] ?? null;
        if (!is_bool($archived)) {
            throw new Problem('Určete stav archivace.');
        }
        $employee = $this->em->find(Employee::class, (int) ($input['id'] ?? 0));
        if (!$employee) {
            throw new Problem('Osoba nenalezena.', 404);
        }
        if ($archived !== ($employee->archivedOn !== null)) {
            if ($archived) {
                $this->requireOtherAdmin($employee);
                $employee->invitation = null;
                $employee->inviteUntil = null;
            }
            $employee->archivedOn = $archived ? new DateTimeImmutable('today') : null;
            ++$employee->sessionVersion;
            $this->audit->audit($who, $archived ? 'archive_employee' : 'restore_employee', ['employee' => $employee->id]);
        }
        return ['ok' => true];
    }

    private function requireOtherAdmin(Employee $employee): void
    {
        if ($employee->id === null || $employee->archivedOn !== null || !in_array('admin', $employee->roles, true)) {
            return;
        }
        $other = $this->em->getConnection()->createQueryBuilder()
            ->select('id')
            ->from('employee')
            ->where('id <> :id')
            ->andWhere('roles @> :roles', 'starts <= CURRENT_DATE', 'ends IS NULL', 'archived_on IS NULL', 'credentials_ready')
            ->setParameter('id', $employee->id)
            ->setParameter('roles', '["admin"]')
            ->setMaxResults(1)
            ->fetchOne();
        if (!$other) {
            throw new Problem('Další správce bez konce evidence musí mít aktivní účet a nastavené přihlášení.', 409);
        }
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value || $date->format('Y') === '0000') {
            throw new Problem('Zadejte platné kalendářní datum.');
        }
        return $date;
    }

    public function invite(array $i): array
    {
        $who=$this->accounts->actor('admin')['id'];
        $code=bin2hex(random_bytes(24));
        $employee = $this->em->find(Employee::class, (int) $i['id']);
        if (!$employee) {
            throw new Problem('Osoba nenalezena.');
        }
        if ($employee->archivedOn !== null) {
            throw new Problem('Pro vydání pozvánky nejdříve zrušte archivaci.', 409);
        }
        $employee->invitation = hash('sha256', $code);
        $employee->inviteUntil = new DateTimeImmutable('+48 hours');
        $this->audit->audit($who, 'invite', ['employee' => $employee->id]);
        return compact('code');
    }
}
