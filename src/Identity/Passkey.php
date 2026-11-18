<?php

declare(strict_types=1);

namespace Dochazka\Identity;

use DateTimeImmutable;
use Dochazka\Staff\Employee;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'passkey')]
class Passkey
{
    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    public DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Id, ORM\Column(name: 'credential_id', type: 'text')]
        public string $id,
        #[ORM\ManyToOne, ORM\JoinColumn(name: 'employee_id', nullable: false, onDelete: 'CASCADE')]
        public Employee $employee,
        #[ORM\Column(name: 'public_key', type: 'text')]
        public string $publicKey,
        #[ORM\Column(name: 'signature_counter', type: 'bigint', nullable: true)]
        public ?int $signatureCounter,
        #[ORM\Column(type: 'text')]
        public string $label,
    ) {
        $this->createdAt = new DateTimeImmutable();
    }
}
