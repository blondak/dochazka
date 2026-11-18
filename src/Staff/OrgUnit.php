<?php
declare(strict_types=1);

namespace Dochazka\Staff;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'org_unit')]
class OrgUnit
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'text')] public string $name,
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'parent_id', nullable: true)]
        public ?self $parent = null,
    ) {}
}
