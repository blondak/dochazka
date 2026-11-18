<?php

declare(strict_types=1);

namespace Dochazka\Payroll;

use DateTimeImmutable;
use Dochazka\Staff\Employee;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'export')]
class PayrollExport
{
    #[ORM\Column(type: 'datetimetz_immutable')]
    public DateTimeImmutable $created;

    public function __construct(
        #[ORM\Id, ORM\Column(type: 'text')]
        public string $month,
        #[ORM\Id, ORM\Column]
        public int $version,
        #[ORM\Column(type: 'text')]
        public string $content,
        #[ORM\Column(type: 'text')]
        public string $fingerprint,
        #[ORM\ManyToOne, ORM\JoinColumn(name: 'creator', nullable: false)]
        public Employee $creator,
    ) {
        $this->created = new DateTimeImmutable();
    }
}
