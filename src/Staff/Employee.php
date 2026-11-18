<?php
declare(strict_types=1);

namespace Dochazka\Staff;


use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'employee')]
class Employee
{
    public const ROLES = ['employee', 'manager', 'payroll', 'admin'];

    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    public ?int $id = null;
    #[ORM\Column(type: 'text', unique: true, nullable: true)] public ?string $number = null;
    #[ORM\Column(type: 'text')] public string $name;
    #[ORM\Column(type: 'text', unique: true)] public string $login;
    #[ORM\Column(name: 'password_hash', type: 'text')] public string $passwordHash;
    #[ORM\Column(name: 'session_version')] public int $sessionVersion = 1;
    #[ORM\Column(name: 'credentials_ready')] public bool $credentialsReady = false;
    /** @var list<string> */
    #[ORM\Column(type: 'jsonb')] public array $roles = [];
    #[ORM\Column(name: 'attendance_enabled')] public bool $attendanceEnabled = true;
    #[ORM\Column(name: 'archived_on', type: 'date_immutable', nullable: true)]
    public ?DateTimeImmutable $archivedOn = null;
    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(name: 'org_unit_id', nullable: false)]
    public OrgUnit $orgUnit;
    #[ORM\Column(type: 'text', unique: true, nullable: true)] public ?string $sid = null;
    #[ORM\Column(type: 'date_immutable')] public DateTimeImmutable $starts;
    #[ORM\Column(type: 'date_immutable', nullable: true)] public ?DateTimeImmutable $ends = null;
    #[ORM\Column(type: 'text', unique: true, nullable: true)] public ?string $invitation = null;
    #[ORM\Column(name: 'invite_until', type: 'datetimetz_immutable', nullable: true)]
    public ?DateTimeImmutable $inviteUntil = null;

    public static function administrator(string $login, string $name, #[\SensitiveParameter] string $password, OrgUnit $unit): self
    {
        $employee = new self;
        $employee->login = $login;
        $employee->name = $name;
        $employee->roles = ['admin'];
        $employee->attendanceEnabled = false;
        $employee->orgUnit = $unit;
        $employee->passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $employee->credentialsReady = true;
        $employee->starts = new DateTimeImmutable('today');
        return $employee;
    }

    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'roles' => $this->roles,
            'attendance_enabled' => $this->attendanceEnabled,
            'archived_on' => $this->archivedOn?->format('Y-m-d'),
            'org_unit_id' => $this->orgUnit->id,
            'number' => $this->number,
            'sid' => $this->sid,
        ];
    }

    public function attendanceEnd(): ?DateTimeImmutable
    {
        if ($this->archivedOn !== null && ($this->ends === null || $this->archivedOn < $this->ends)) {
            return $this->archivedOn;
        }
        return $this->ends;
    }
}
