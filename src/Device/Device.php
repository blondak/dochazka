<?php

declare(strict_types=1);

namespace Dochazka\Device;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'device')]
class Device
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'text', unique: true, nullable: true)]
    public ?string $credential = null;

    #[ORM\Column(name: 'domain_principal', type: 'text', unique: true, nullable: true)]
    public ?string $domainPrincipal = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    public ?DateTimeImmutable $seen = null;

    #[ORM\Column]
    public bool $revoked = false;

    public function __construct(
        #[ORM\Column(type: 'text')]
        public string $name,
        #[ORM\Column(type: 'text', unique: true, nullable: true)]
        public ?string $enrollment,
        #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
        public ?DateTimeImmutable $expires,
    ) {}

    public function enroll(string $credential): void
    {
        $this->enrollment = null;
        $this->credential = $credential;
        $this->seen = new DateTimeImmutable();
    }

    public function revoke(): void
    {
        $this->revoked = true;
        $this->enrollment = null;
        $this->credential = null;
    }
}
