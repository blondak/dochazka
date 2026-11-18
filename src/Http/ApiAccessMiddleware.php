<?php

declare(strict_types=1);

namespace Dochazka\Http;

use Apitte\Core\Http\RequestAttributes;
use Contributte\Middlewares\IMiddleware;
use Dochazka\Device\Devices;
use Dochazka\Device\DeviceToken;
use Dochazka\Device\DomainMachine;
use LogicException;
use Nepada\SecurityAnnotations\Annotations\LoggedIn;
use Nepada\SecurityAnnotations\Annotations\Role;
use Nepada\SecurityAnnotations\RequirementsChecker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;

final class ApiAccessMiddleware implements IMiddleware
{
    public const DEVICE_ID = 'dochazka.deviceId';

    public function __construct(private RequirementsChecker $requirements, private Devices $devices) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
    {
        $handler = $request->getAttribute(RequestAttributes::ATTR_ENDPOINT)->getHandler();
        $method = new ReflectionMethod($handler->getClass(), $handler->getMethod());
        $public = $method->getAttributes(PublicEndpoint::class);
        $device = $method->getAttributes(DeviceToken::class) !== [];
        $machine = $method->getAttributes(DomainMachine::class) !== [];
        $session = $method->getAttributes(LoggedIn::class) !== [] || $method->getAttributes(Role::class) !== [];
        if ((int) ($public !== []) + (int) $device + (int) $machine + (int) $session !== 1) {
            throw new LogicException('API endpoint must declare exactly one authentication mode.');
        }

        try {
            if ($device) {
                $deviceId = $this->devices->authenticate($request->getHeaderLine('Authorization'));
                $request = $request->withAttribute(self::DEVICE_ID, $deviceId);
            } elseif ($machine) {
                $request = $request->withAttribute(DomainMachine::PRINCIPAL, DomainMachine::principal($request->getServerParams()));
            } elseif ($session || $public[0]->newInstance()->web) {
                if ($request->getHeaderLine('X-Dochazka') !== 'web') {
                    throw new Problem('Neplatný požadavek.', 403);
                }
                $this->requirements->protectElement($method);
            }
        } catch (Problem $error) {
            $response->getBody()->write(json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            return $response->withStatus($error->getCode())
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store');
        }

        return $next($request, $response);
    }
}
