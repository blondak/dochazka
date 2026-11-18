<?php
declare(strict_types=1);
use Apitte\Core\Application\IApplication;

$container = require dirname(__DIR__).'/config/bootstrap.php';
$container->getByType(IApplication::class)->run();
