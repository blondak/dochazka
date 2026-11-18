<?php

declare(strict_types=1);

namespace Dochazka\Http;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class PublicEndpoint
{
    public function __construct(public readonly bool $web = true) {}
}
