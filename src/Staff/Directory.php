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

    public function reviewers(array $person, string $first, string $last): array
    {
        return $this->em->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT m.id,m.name FROM org_unit_tree u JOIN employee m ON m.org_unit_id=ANY(u.ancestors)
            WHERE u.id=? AND m.id<>? AND m.roles @> '["manager"]' AND m.credentials_ready AND m.archived_on IS NULL
                AND m.starts <= ? AND (m.ends IS NULL OR m.ends >= ?) ORDER BY m.name,m.id
            SQL, [$person['org_unit_id'],$person['id'],$first,$last]);
    }

    public function covers(array $actor): array
    {
        return $this->em->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT c.id,c.starts,c.ends,c.manager,c.substitute,m.name AS manager_name,s.name AS substitute_name,
                c.starts <= CURRENT_DATE AND s.org_unit_id=ANY(u.ancestors)
                AND m.roles @> '["manager"]' AND s.roles @> '["manager"]'
                AND m.archived_on IS NULL AND s.archived_on IS NULL AND m.starts <= CURRENT_DATE AND s.starts <= CURRENT_DATE
                AND (m.ends IS NULL OR m.ends >= CURRENT_DATE) AND (s.ends IS NULL OR s.ends >= CURRENT_DATE) AS active,
                m.org_unit_id
            FROM manager_cover c JOIN employee m ON m.id=c.manager JOIN employee s ON s.id=c.substitute
            JOIN org_unit_tree u ON u.id=m.org_unit_id
            WHERE (c.manager=? OR c.substitute=?) AND NOT c.revoked AND c.ends >= CURRENT_DATE ORDER BY c.starts,c.id
            SQL, [$actor['id'],$actor['id']]);
    }

    public function setCover(array $input): array
    {
        $actor = $this->accounts->actor('manager');
        $db = $this->em->getConnection();
        if (isset($input['revoke'])) {
            if (!$db->executeStatement('UPDATE manager_cover SET revoked=true WHERE id=? AND manager=? AND NOT revoked',
                [(int)$input['revoke'],$actor['id']])) throw new Problem('Zástup není dostupný.',404);
            $this->audit->audit($actor['id'], 'cover-revoked', ['id'=>(int)$input['revoke']]);
            return ['ok'=>true];
        }
        $first = self::date($input['starts'] ?? '')->format('Y-m-d');
        $last = self::date($input['ends'] ?? '')->format('Y-m-d');
        if ($first < date('Y-m-d') || $last < $first) throw new Problem('Zadejte dnešní nebo budoucí období zástupu.');
        $substitute = $input['substitute'] ?? null;
        if (!in_array($substitute, array_column($this->reviewers($actor,$first,$last),'id'), true)) {
            throw new Problem('Zvolte aktivního vedoucího své nebo nadřazené jednotky.',403);
        }
        if ($db->fetchOne('SELECT 1 FROM manager_cover WHERE NOT revoked AND starts <= ? AND ends >= ?
            AND (manager IN (?,?) OR substitute=?)', [$last,$first,$actor['id'],$substitute,$actor['id']])) {
            throw new Problem('V období je již domluven kolidující zástup.',409);
        }
        $db->insert('manager_cover',['manager'=>$actor['id'],'substitute'=>$substitute,'starts'=>$first,'ends'=>$last]);
        $this->audit->audit($actor['id'],'cover-assigned', compact('substitute','first','last'));
        return ['ok'=>true];
    }

    public function managedUnits(array $actor): array
    {
        return array_keys($this->units->managed($actor));
    }

    public function roster(string $month, ?array $units = null): array
    {
        $start = \Dochazka\Attendance\Timesheet::month($month);
        $end = $start->modify('+1 month');
        $effectiveEnd = 'LEAST(e.ends, e.archived_on)';
        $query = $this->em->getConnection()->createQueryBuilder()
            ->select('e.org_unit_id', 'e.id', 'e.name', 'e.number', 'e.sid', 'e.starts', "$effectiveEnd AS ends")
            ->from('employee', 'e')
            ->where('e.attendance_enabled', 'e.starts < :end')
            ->andWhere("($effectiveEnd IS NULL OR $effectiveEnd >= GREATEST(:start, e.starts))")
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('e.number');
        if ($units !== null) {
            $query->andWhere('e.org_unit_id IN (:units)')->setParameter('units', $units, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        }
        return $query->fetchAllAssociative();
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
            if ($e->id && in_array('manager', $actor['roles'], true) && $e->orgUnit->id !== ($i['org_unit_id'] ?? null)) {
                $managed = $this->units->managed($actor);
                if (!isset($managed[$e->orgUnit->id]) && isset($managed[$i['org_unit_id']])) {
                    throw new Problem('Přesun do vlastní schvalovací působnosti musí provést jiný personalista nebo správce.', 403);
                }
            }
            if ($e->id && array_diff($e->roles, ['employee']) &&
                (($i['starts'] ?? '') !== $e->starts->format('Y-m-d') || (($i['ends'] ?? null) ?: null) !== $e->ends?->format('Y-m-d'))) {
                throw new Problem('Dobu přístupu účtu s rozšířeným oprávněním mění správce.', 403);
            }
            $roles = $e->id ? $e->roles : ['employee'];
            if ((isset($i['roles']) && $i['roles'] !== $roles) ||
                (!empty($i['login']) && $i['login'] !== ($e->login ?? null)) ||
                (!empty($i['sid']) && $i['sid'] !== $e->sid)) {
                throw new Problem('Role a přihlašovací údaje spravuje správce.', 403);
            }
            $i['roles'] = $roles;
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
