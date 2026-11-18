<?php
declare(strict_types=1);

namespace Dochazka\Http;


final class Problem extends \RuntimeException
{
    public function __construct(string $message, int $status = 422) { parent::__construct($message,$status); }
}
