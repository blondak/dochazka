<?php
declare(strict_types=1);

namespace Dochazka\Staff;

use Dochazka\Http\Problem;

final class EmployeeNumber
{
    public static function validate(string $number): void
    {
        if (preg_match('/[\p{Cc}]|^[\p{Z}\p{Cf}]*[=+\-@＝＋－＠]/u', $number)) {
            throw new Problem('Osobní číslo obsahuje řídicí znak nebo začíná znakem vzorce pro tabulkový procesor.');
        }
    }
}
