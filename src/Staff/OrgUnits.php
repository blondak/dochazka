<?php
declare(strict_types=1);

namespace Dochazka\Staff;

use Doctrine\ORM\EntityManagerInterface;
use Dochazka\Audit\AuditTrail;
use Dochazka\Http\Problem;

final class OrgUnits
{
    public function __construct(private EntityManagerInterface $em, private AuditTrail $audit) {}

    public function catalog(?int $root = null): array
    {
        $query = $this->em->getConnection()->createQueryBuilder()
            ->select('id', 'parent_id', 'name', 'path')->from('org_unit_tree')->orderBy('path')->addOrderBy('id');
        if ($root !== null) {
            $query->where(':root = ANY(ancestors)')->setParameter('root', $root);
        }
        return array_column($query->fetchAllAssociative(), null, 'id');
    }

    public function managed(array $actor): array
    {
        return in_array('manager', $actor['roles'], true) ? $this->catalog($actor['org_unit_id']) : [];
    }

    public function path(int $id): string
    {
        return $this->em->getConnection()->createQueryBuilder()->select('path')->from('org_unit_tree')
            ->where('id = :id')->setParameter('id', $id)->fetchOne();
    }

    public function requireUnit(mixed $id): OrgUnit
    {
        $unit = is_int($id) ? $this->em->find(OrgUnit::class, $id) : null;
        return $unit ?? throw new Problem('Vyberte existující organizační jednotku.');
    }

    public function adminUnit(): OrgUnit
    {
        $unit = $this->em->getRepository(OrgUnit::class)->findOneBy(['name' => 'IT', 'parent' => null]);
        if ($unit === null) {
            $unit = new OrgUnit('IT');
            $this->em->persist($unit);
        }
        return $unit;
    }

    public function save(int $actor, array $input): array
    {
        $name = trim($input['name'] ?? '');
        if ($name === '' || strlen($name) > 200 || !array_key_exists('parent_id', $input)) {
            throw new Problem('Zadejte název do 200 bajtů a určete umístění jednotky.');
        }
        $parent = $input['parent_id'] === null ? null : $this->requireUnit($input['parent_id']);
        $unit = isset($input['id']) ? $this->requireUnit($input['id']) : new OrgUnit($name);
        if ($unit->id !== null && $parent && isset($this->catalog($unit->id)[$parent->id])) {
            throw new Problem('Jednotku nelze přesunout do vlastní větve.', 409);
        }
        $previous = $unit->id === null ? null : ['name' => $unit->name, 'parent_id' => $unit->parent?->id];
        $unit->name = $name;
        $unit->parent = $parent;
        $this->em->persist($unit);
        $this->em->flush();
        $this->audit->audit($actor, 'save_org_unit', ['id' => $unit->id, 'previous' => $previous, 'name' => $name, 'parent_id' => $parent?->id]);
        return ['id' => $unit->id];
    }

    public function delete(int $actor, array $input): array
    {
        $unit = $this->requireUnit($input['id'] ?? null);
        if ($this->em->getRepository(OrgUnit::class)->count(['parent' => $unit])
            || $this->em->getRepository(Employee::class)->count(['orgUnit' => $unit])) {
            throw new Problem('Smazat lze pouze jednotku bez zaměstnanců a podřízených jednotek.', 409);
        }
        $this->audit->audit($actor, 'delete_org_unit', ['id' => $unit->id, 'path' => $this->path($unit->id)]);
        $this->em->remove($unit);
        return ['ok' => true];
    }
}
