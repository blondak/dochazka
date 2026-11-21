<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use Apitte\Core\Annotation\Controller\{Path, Method, OpenApi};
use Apitte\Core\Http\{ApiRequest, ApiResponse};
use Apitte\Core\UI\Controller\IController;
use Dochazka\Device\DeviceToken;
use Dochazka\Http\{ApiAccessMiddleware, JsonEndpoint, PublicEndpoint};
use Nepada\SecurityAnnotations\Annotations\{LoggedIn, Role};

#[Path('/api/v1')]
final class ServiceSessionController implements IController
{
    public function __construct(private JsonEndpoint $json, private ServiceSessions $sessions, private DuplicateSessions $duplicates) {}

    #[Path('/session-control-preview'), Method('POST'), LoggedIn, Role('admin')]
    #[OpenApi(<<<'NEON'
operationId: sessionControlPreview
summary: "Vyhledat neúplná přihlášení a možné duplicitní relace"
tags: [Zařízení]
security: [{session: []}]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
requestBody:
    required: true
    content:
        application/json:
            schema: {$ref: '#/components/schemas/SessionControlPeriod'}
responses:
    '200':
        description: "Možné duplicity, neúplné a již potlačené relace"
        content:
            application/json:
                schema: {type: array, items: {$ref: '#/components/schemas/SessionControlRow'}}
    '403': {$ref: '#/components/responses/Error403'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function preview(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->duplicates->preview(...), readOnly: true);
    }

    #[Path('/session-control-apply'), Method('POST'), LoggedIn, Role('admin')]
    #[OpenApi(<<<'NEON'
operationId: sessionControlApply
summary: "Potlačit nebo obnovit vybrané relace z ověřeného náhledu"
tags: [Zařízení]
security: [{session: []}]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                allOf:
                    - {$ref: '#/components/schemas/SessionControlPeriod'}
                    - {type: object, required: [tokens, reason], properties: {tokens: {type: array, items: {type: string}}, reason: {type: string}}}
responses:
    '200':
        description: "Počet provedených změn"
        content:
            application/json:
                schema: {type: object, properties: {changed: {type: integer}}}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function apply(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->duplicates->apply(...));
    }

    #[Path('/service-session-link'), Method('POST'), DeviceToken]
    #[OpenApi(<<<'NEON'
operationId: serviceSessionLink
summary: "Zařízení vydá jednorázový odkaz na své aktuální přihlášení"
tags: [Zařízení]
security: [{device: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [sid, session]
                properties:
                    sid: {type: string}
                    session: {type: string}
responses:
    '200':
        description: "Kód odkazu s platností 10 minut"
        content:
            application/json:
                schema: {$ref: '#/components/schemas/ServiceSessionCode'}
    '401': {$ref: '#/components/responses/Error401'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function link(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) =>
            $this->sessions->issue($request->getAttribute(ApiAccessMiddleware::DEVICE_ID), $input), maxBytes: 1024);
    }

    #[Path('/service-session-details'), Method('POST'), PublicEndpoint]
    #[OpenApi(<<<'NEON'
operationId: serviceSessionDetails
summary: "Zobrazit stanici a čas relace pro jednorázový odkaz"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
requestBody: {$ref: '#/components/requestBodies/ServiceSessionCode'}
responses:
    '200':
        description: "Relace uvedená na potvrzovací obrazovce"
        content:
            application/json:
                schema:
                    type: object
                    properties:
                        station: {type: string}
                        from: {type: string, format: date-time}
    '404': {$ref: '#/components/responses/Error404'}
NEON)]
    public function details(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->sessions->details(...), transaction: false, maxBytes: 1024);
    }

    #[Path('/service-session-passkey-options'), Method('POST'), PublicEndpoint]
    #[OpenApi(<<<'NEON'
operationId: serviceSessionPasskeyOptions
summary: "Vytvořit výzvu passkey vázanou na servisní relaci"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
requestBody: {$ref: '#/components/requestBodies/ServiceSessionCode'}
responses:
    '200':
        description: "PublicKeyCredentialRequestOptions"
        content:
            application/json:
                schema: {type: object, properties: {publicKey: {type: object}}}
    '404': {$ref: '#/components/responses/Error404'}
    '409': {$ref: '#/components/responses/Error409'}
NEON)]
    public function passkeyOptions(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->sessions->passkeyOptions(...), transaction: false, maxBytes: 1024);
    }

    #[Path('/service-session-verify'), Method('POST'), PublicEndpoint]
    #[OpenApi(<<<'NEON'
operationId: serviceSessionVerify
summary: "Označit relaci z jednorázového odkazu po ověření účtu správce"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
requestBody: {$ref: '#/components/requestBodies/ServiceSessionVerify'}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '404': {$ref: '#/components/responses/Error404'}
    '409': {$ref: '#/components/responses/Error409'}
    '429': {$ref: '#/components/responses/Error429'}
NEON)]
    public function verify(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->sessions->verify(...), transaction: false, maxBytes: 8192);
    }

    #[Path('/service-session-change'), Method('POST'), LoggedIn, Role('admin')]
    #[OpenApi(<<<'NEON'
operationId: serviceSessionChange
summary: "Správce ve výkazu označí servisní relaci nebo příznak zruší"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [employee, device, session, service]
                properties:
                    employee: {type: integer}
                    device: {type: integer}
                    session: {type: string}
                    service: {type: boolean}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '404': {$ref: '#/components/responses/Error404'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function change(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->sessions->review(...), maxBytes: 2048);
    }
}
