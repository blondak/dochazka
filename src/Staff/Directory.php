<?php
declare(strict_types=1);

namespace Dochazka\Staff;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dochazka\Http\Problem;
use Dochazka\Identity\Accounts;
use Dochazka\Audit\AuditTrail;
use Dochazka\Attendance\WorkModels;
use Dochazka\Attendance\HolidayEntries;

final class Directory
{
    public function __construct(private EntityManagerInterface $em, private OrgUnits $units, private Accounts $accounts, private AuditTrail $audit, private WorkModels $models, private HolidayEntries $holidayEntries) {}

    public function person(array $actor,int $id,bool $review=false): array
    {
        $p = $this->em->find(Employee::class, $id);
        if (!$p) throw new Problem('Osoba nenalezena.',404);
        $canReadAll = array_intersect(['payroll', 'admin', 'hr'], $actor['roles']) !== [];
        $managed = isset($this->units->managed($actor)[$p->orgUnit->id]);
        if (!$managed && ($review || ($actor['id'] !== $id && !$canReadAll))) {
            throw new Problem('Přístup zamítnut.', 403);
        }
        return $p->summary() + ['managed' => $managed, 'org_unit_path' => $this->units->path($p->orgUnit->id), 'starts' => $p->starts->format('Y-m-d'), 'ends' => $p->attendanceEnd()?->format('Y-m-d')];
    }

    public function people(): array
    {
        $actor=$this->accounts->actor();
        $admin = in_array('admin', $actor['roles'], true);
        $managed = $this->units->managed($actor);
        $criteria = match (true) {
            array_intersect(['admin','hr','payroll'], $actor['roles']) !== [] => [],
            in_array('manager', $actor['roles'], true) => ['orgUnit' => array_keys($managed)],
            default => null,
        };
        if ($criteria === null) {
            unset($actor['sid']);
            return [$actor + ['archived_on' => null]];
        }
        $employees = $this->em->getRepository(Employee::class)->findBy($criteria, ['name' => 'ASC']);
        $units = $criteria === [] ? $this->units->catalog() : $managed;
        return array_map(static function (Employee $employee) use ($admin, $actor, $units, $managed): array {
            $id = $employee->orgUnit->id;
            $person = $employee->summary() + ['org_unit_path' => $units[$id]['path'], 'managed' => isset($managed[$id])];
            if ($admin) {
                return $person + [
                    'starts' => $employee->starts->format('Y-m-d'),
                    'ends' => $employee->ends?->format('Y-m-d'),
                    'login' => $employee->login,
                ];
            }
            if (in_array('hr', $actor['roles'], true)) {
                $person['starts'] = $employee->starts->format('Y-m-d');
                $person['ends'] = $employee->ends?->format('Y-m-d');
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
        $actor = $this->accounts->actor(['admin', 'hr']);
        $who = $actor['id'];
        $e=empty($i['id']) ? new Employee : $this->em->find(Employee::class,(int)$i['id']);
        if (!$e) throw new Problem('Osoba nenalezena.');
        if (!in_array('admin', $actor['roles'], true)) {
            if ($e->id && in_array('manager', $e->roles, true) && $e->orgUnit->id !== ($i['org_unit_id'] ?? null)) {
                throw new Problem('Přesun vedoucího mění jeho oprávnění a provádí ho správce.', 403);
            }
            if ($e->id && array_diff($e->roles, ['employee']) &&
                (($i['starts'] ?? '') !== $e->starts->format('Y-m-d') || (($i['ends'] ?? null) ?: null) !== $e->ends?->format('Y-m-d'))) {
                throw new Problem('Dobu přístupu účtu s rozšířeným oprávněním mění správce.', 403);
            }
            $i['roles'] = $e->id ? $e->roles : ['employee'];
            $i['sid'] = $e->sid;
            $i['login'] = $e->id ? $e->login : 'osoba-'.bin2hex(random_bytes(8));
        }
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
        $unit = $this->units->requireUnit($i['org_unit_id'] ?? null);
        $previousUnit = $e->id === null ? null : $e->orgUnit->id;
        if ($e->id !== null && ($e->roles !== $normalizedRoles || $e->login !== $login || $previousUnit !== $unit->id
            || $e->starts != $starts || $e->ends != $ends || $e->attendanceEnabled !== $attendanceEnabled)) {
            ++$e->sessionVersion;
        }
        $e->sid = $sid;
        $e->roles = $normalizedRoles;
        $e->attendanceEnabled = $attendanceEnabled;
        $e->login = $login;
        $e->orgUnit = $unit;
        $e->name = self::field($i, 'name');
        $e->number = $number === '' ? null : $number;
        $e->starts = $starts;
        $e->ends = $ends;
        if (!$e->id) $e->passwordHash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
        $this->em->persist($e); $this->em->flush();
        $this->audit->audit($who, 'employee', [
            'employee' => $e->id, 'roles' => $e->roles, 'org_unit_id' => $unit->id, 'previous_unit_id' => $previousUnit, 'attendance_enabled' => $e->attendanceEnabled,
        ]);
        if (isset($i['work_model'])) {
            if (!is_array($i['work_model'])) throw new Problem('Zkontrolujte pracovní model.');
            $result = $this->models->assign($who, ['id' => $e->id, 'attendance_enabled' => $e->attendanceEnabled,
                'starts' => $e->starts->format('Y-m-d'), 'ends' => $e->attendanceEnd()?->format('Y-m-d')], $i['work_model']);
            $this->holidayEntries->reconcileExisting($result['impact'], $who);
        }
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
