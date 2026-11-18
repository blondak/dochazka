<?php

declare(strict_types=1);

namespace Dochazka\Http;

use Apitte\Core\Handler\ServiceHandler;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use Nette\DI\Container;

final class MiddlewareHandler extends ServiceHandler
{
    public function __construct(Container $container, private ApiAccessMiddleware $access)
    {
        parent::__construct($container);
    }

    public function handle(ApiRequest $request, ApiResponse $response): mixed
    {
        return ($this->access)($request, $response, fn(ApiRequest $req, ApiResponse $res) => parent::handle($req, $res));
    }
}
