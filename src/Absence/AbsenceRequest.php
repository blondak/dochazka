<?php

declare(strict_types=1);

namespace Dochazka\Absence;

use DateTimeImmutable;
use Dochazka\Staff\Employee;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'request')]
class AbsenceRequest
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column(type: 'bigint')]
    public ?int $id = null;

    #[ORM\Column(name: 'merged_work')]
    public bool $mergedWork = false;

    #[ORM\Column(type: 'text')]
    public string $status = 'pending';

    #[ORM\ManyToOne, ORM\JoinColumn(name: 'reviewer')]
    public ?Employee $reviewer = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $reason = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    public ?DateTimeImmutable $decided = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(name: 'employee', nullable: false)]
        public Employee $employee,
        #[ORM\Column(type: 'text')]
        public string $kind,
        #[ORM\Column(type: 'datetimetz_immutable')]
        public DateTimeImmutable $begins,
        #[ORM\Column(type: 'datetimetz_immutable')]
        public DateTimeImmutable $ends,
        #[ORM\Column(type: 'text')]
        public string $note,
    ) {}

    public function decide(string $status, Employee $reviewer, string $reason): void
    {
        $this->status = $status;
        $this->reviewer = $reviewer;
        $this->reason = $reason;
        $this->decided = new DateTimeImmutable();
    }
}
