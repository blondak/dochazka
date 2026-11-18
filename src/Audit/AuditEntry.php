<?php

declare(strict_types=1);

namespace Dochazka\Audit;

use DateTimeImmutable;
use Dochazka\Staff\Employee;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'audit')]
class AuditEntry
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column(type: 'bigint')]
    public ?int $id = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    public DateTimeImmutable $created;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(name: 'actor')]
        public ?Employee $actor,
        #[ORM\Column(type: 'text')]
        public string $action,
        #[ORM\Column(type: 'jsonb')]
        public array $details,
    ) {
        $this->created = new DateTimeImmutable();
    }
}
