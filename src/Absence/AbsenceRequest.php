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

    #[ORM\Column(name: 'generated_holiday')]
    public bool $generatedHoliday = false;

    #[ORM\Column(name: 'requires_proof')]
    public bool $requiresProof = false;

    #[ORM\Column(name: 'replaces_from', type: 'datetimetz_immutable', nullable: true)]
    public ?DateTimeImmutable $replacesFrom = null;

    #[ORM\Column(name: 'replaces_to', type: 'datetimetz_immutable', nullable: true)]
    public ?DateTimeImmutable $replacesTo = null;

    #[ORM\Column(name: 'needs_actual')]
    public bool $needsActual = false;

    #[ORM\Column(name: 'actual_from', type: 'datetimetz_immutable', nullable: true)]
    public ?DateTimeImmutable $actualFrom = null;

    #[ORM\Column(name: 'actual_to', type: 'datetimetz_immutable', nullable: true)]
    public ?DateTimeImmutable $actualTo = null;

    #[ORM\Column(name: 'actual_returned')]
    public bool $actualReturned = false;

    #[ORM\Column(name: 'vacation_seconds', nullable: true)]
    public ?int $vacationSeconds = null;

    #[ORM\Column(name: 'vacation_side', type: 'text', nullable: true)]
    public ?string $vacationSide = null;

    #[ORM\Column(name: 'vacation_estimate', type: 'json', nullable: true)]
    public ?array $vacationEstimate = null;

    #[ORM\Column(name: 'vacation_consent')]
    public bool $vacationConsent = false;

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
