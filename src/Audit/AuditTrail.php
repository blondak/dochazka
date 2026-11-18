<?php
declare(strict_types=1);

namespace Dochazka\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Dochazka\Staff\Employee;

final class AuditTrail
{
    public function __construct(private EntityManagerInterface $em) {}

    public function audit(int $who,string $action,array $details): void
    {
        $this->em->persist(new AuditEntry($this->em->getReference(Employee::class, $who), $action, $details));
    }
}
