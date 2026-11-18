<?php

declare(strict_types=1);

namespace Dochazka\Device;

use Attribute;
use Dochazka\Http\Problem;

#[Attribute(Attribute::TARGET_METHOD)]
final class DomainMachine
{
    public const PRINCIPAL = 'dochazka.machinePrincipal';

    public static function principal(array $server): string
    {
        // Set by the authenticated FastCGI gateway. HTTP headers have an HTTP_ prefix.
        $principal = $server['DOCHAZKA_MACHINE_PRINCIPAL'] ?? '';
        if (!is_string($principal) || strlen($principal) > 254 ||
            !preg_match('/^[A-Z0-9][A-Z0-9-]{0,62}\$@[A-Z0-9][A-Z0-9.-]*$/iD', $principal)) {
            throw new Problem('Je vyžadováno doménové ověření stanice.', 403);
        }
        return strtoupper($principal);
    }
}
