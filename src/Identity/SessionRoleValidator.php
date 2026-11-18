<?php

declare(strict_types=1);

namespace Dochazka\Identity;

use Dochazka\Http\Problem;
use Nepada\SecurityAnnotations\AccessValidators\AccessValidator;
use Nepada\SecurityAnnotations\Annotations\Role;
use Nette\Security\Role as SecurityRole;

/** @implements AccessValidator<Role> */
final class SessionRoleValidator implements AccessValidator
{
    public function __construct(private Accounts $accounts) {}

    public function getSupportedAnnotationName(): string
    {
        return Role::class;
    }

    public function validateAccess(object $annotation): void
    {
        $allowed = array_map(
            static fn(string|SecurityRole $role): string => is_string($role) ? $role : $role->getRoleId(),
            $annotation->roles,
        );
        if (array_intersect($this->accounts->actor()['roles'], $allowed) === []) {
            throw new Problem('Přístup zamítnut.', 403);
        }
    }
}
