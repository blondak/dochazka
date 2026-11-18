<?php

declare(strict_types=1);

namespace Dochazka\Absence;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'medical_proof')]
class ProofAttachment
{
    public function __construct(
        #[ORM\Id, ORM\OneToOne, ORM\JoinColumn(name: 'request_id', nullable: false)]
        public AbsenceRequest $request,
        #[ORM\Column(name: 'jpeg_base64', type: 'text')]
        public string $jpeg,
    ) {}
}
