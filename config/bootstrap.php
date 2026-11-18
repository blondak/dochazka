<?php
declare(strict_types=1);
use Nette\Bootstrap\Configurator;
$root = dirname(__DIR__);
require_once $root.'/vendor/autoload.php';
date_default_timezone_set('Europe/Prague');
$config = new Configurator;
$config->setDebugMode(false)->setTempDirectory($root.'/var');
$config->addStaticParameters(['projectRoot'=>$root,'apiRevision'=>implode('',array_map(static fn($file) => hash_file('sha256',$file),glob($root.'/src/*/*Controller.php'))).hash_file('sha256',__DIR__.'/app.neon')]);
$config->addDynamicParameters([
    'setupToken' => (string) getenv('SETUP_TOKEN'),
    'passkeyOrigin' => (string) getenv('PASSKEY_ORIGIN'),
    'gatewayRealm' => (string) getenv('GATEWAY_ENROLL_REALM'),
    'gatewayKeyFile' => (string) getenv('GATEWAY_ENROLL_KEY_FILE'),
    'database' => [
        'host' => getenv('PGHOST') ?: 'db',
        'port' => (int) (getenv('PGPORT') ?: 5432),
        'name' => getenv('PGDATABASE') ?: 'dochazka',
        'user' => getenv('PGUSER') ?: 'dochazka',
        'password' => (string) getenv('PGPASSWORD'),
    ],
]);
return $config->addConfig(__DIR__.'/app.neon')->createContainer();
