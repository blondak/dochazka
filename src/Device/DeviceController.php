<?php
declare(strict_types=1);

namespace Dochazka\Device;

use Apitte\Core\Annotation\Controller\{Path,Method,OpenApi};
use Apitte\Core\Http\{ApiRequest,ApiResponse};
use Apitte\Core\UI\Controller\IController;
use Dochazka\Http\JsonEndpoint;
use Nepada\SecurityAnnotations\Annotations\Role;
use Nepada\SecurityAnnotations\Annotations\LoggedIn;
use Dochazka\Http\PublicEndpoint;
use Dochazka\Http\ApiAccessMiddleware;

#[Path('/api/v1')]
final class DeviceController implements IController
{
    public function __construct(private JsonEndpoint $json, private Devices $service) {}

    #[Path('/register'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: register
summary: "Správce vydá hodinový registrační kód stanice"
tags: [Správa]
requestBody: {$ref: '#/components/requestBodies/Register'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/Invite'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('admin')]
    public function register(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->register($input));
    }

    #[Path('/revoke'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: revoke
summary: "Správce odebere stanici přístup"
tags: [Správa]
requestBody: {$ref: '#/components/requestBodies/Invite'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('admin')]
    public function revoke(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->revoke($input));
    }

    #[Path('/devices'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: devices
summary: "Správce zobrazí spojení stanic a výpadky sběru"
tags: [Správa]
requestBody: {$ref: '#/components/requestBodies/Devices'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/Devices'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('admin')]
    public function devices(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->listing());
    }

    #[Path('/enroll'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: enroll
summary: "Vyměnit jednorázový kód za přístup stanice"
tags: [Zařízení]
requestBody: {$ref: '#/components/requestBodies/Enroll'}
responses:
    '200': {$ref: '#/components/responses/Enroll'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[PublicEndpoint(web: false)]
    public function enroll(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->enroll($input));
    }

    #[Path('/domain-enroll'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: domainEnroll
summary: "Doménově ověřená stanice získá vlastní token. Opakovaná registrace nahradí předchozí token."
description: "Dostupné přes interní bránu GPO s ověřením Kerberos a členství účtu počítače v povolené skupině AD."
tags: [Zařízení]
security: [{kerberos: []}]
requestBody:
    required: true
    content:
        application/json:
            schema: {type: object, additionalProperties: false}
responses:
    '200': {$ref: '#/components/responses/Enroll'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[DomainMachine]
    public function domainEnroll(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, function (array $input) use ($request): array {
            if ($input !== []) {
                throw new \Dochazka\Http\Problem('Registrace očekává prázdný objekt.');
            }
            return $this->service->enrollDomain($request->getAttribute(DomainMachine::PRINCIPAL));
        });
    }

    #[Path('/events'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: events
summary: "Odeslat dávku nebo potvrdit spojení. Stejné ID a obsah lze doručit opakovaně. Neznámý SID vrátí unmapped."
tags: [Zařízení]
requestBody: {$ref: '#/components/requestBodies/Events'}
security: [{device: []}]
responses:
    '200': {$ref: '#/components/responses/Events'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[DeviceToken]
    public function events(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->accept($request->getAttribute(ApiAccessMiddleware::DEVICE_ID), $input));
    }
}
