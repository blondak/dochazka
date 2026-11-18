<?php

declare(strict_types=1);

namespace Dochazka\Identity;

use Nepada\SecurityAnnotations\AccessValidators\AccessValidator;
use Nepada\SecurityAnnotations\Annotations\LoggedIn;

/** @implements AccessValidator<LoggedIn> */
final class SessionRequiredValidator implements AccessValidator
{
    public function __construct(private Accounts $accounts) {}

    public function getSupportedAnnotationName(): string
    {
        return LoggedIn::class;
    }

    public function validateAccess(object $annotation): void
    {
        $this->accounts->actor();
    }
}
