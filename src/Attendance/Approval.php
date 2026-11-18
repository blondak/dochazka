<?php

declare(strict_types=1);

namespace Dochazka\Attendance;

use DateTimeImmutable;
use Dochazka\Staff\Employee;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'approval')]
class Approval
{
    #[ORM\ManyToOne, ORM\JoinColumn(name: 'reviewer', nullable: false)]
    public Employee $reviewer;

    #[ORM\Column(type: 'text')]
    public string $fingerprint;

    #[ORM\Column(type: 'text')]
    public string $note;

    #[ORM\Column(type: 'datetimetz_immutable')]
    public DateTimeImmutable $approved;

    public function __construct(
        #[ORM\Id, ORM\ManyToOne, ORM\JoinColumn(name: 'employee', nullable: false)]
        public Employee $employee,
        #[ORM\Id, ORM\Column(type: 'text')]
        public string $month,
    ) {}

    public function confirm(Employee $reviewer, string $fingerprint, string $note): void
    {
        $this->reviewer = $reviewer;
        $this->fingerprint = $fingerprint;
        $this->note = $note;
        $this->approved = new DateTimeImmutable();
    }
}
