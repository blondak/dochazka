<?php
declare(strict_types=1);

namespace Dochazka\Identity;

use Apitte\Core\Annotation\Controller\{Path,Method,OpenApi};
use Apitte\Core\Http\{ApiRequest,ApiResponse};
use Apitte\Core\UI\Controller\IController;
use Dochazka\Http\JsonEndpoint;
use Nepada\SecurityAnnotations\Annotations\LoggedIn;
use Dochazka\Http\PublicEndpoint;

#[Path('/api/v1')]
final class IdentityController implements IController
{
    public function __construct(private JsonEndpoint $json, private Accounts $service) {}

    #[Path('/login'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: login
summary: "Přihlásit se a získat cookie relace"
tags: [Přístup]
requestBody: {$ref: '#/components/requestBodies/Login'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
responses:
    '200': {$ref: '#/components/responses/Login'}
    '429':
        description: "Limit pokusů o přihlášení na 15 minut."
        content: {application/json: {schema: {$ref: '#/components/schemas/Error'}}}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[PublicEndpoint]
    public function login(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->login($input), transaction: false);
    }

    #[Path('/activate'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: activate
summary: "Nastavit heslo jednorázovou pozvánkou"
tags: [Přístup]
requestBody: {$ref: '#/components/requestBodies/Activate'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[PublicEndpoint]
    public function activate(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->activate($input));
    }

    #[Path('/me'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: me
summary: "Zobrazit vlastní účet"
tags: [Přístup]
requestBody: {$ref: '#/components/requestBodies/Devices'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/Login'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn]
    public function me(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->actor());
    }

    #[Path('/logout'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: logout
summary: "Ukončit vlastní relaci"
tags: [Přístup]
requestBody: {$ref: '#/components/requestBodies/Devices'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn]
    public function logout(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->logout());
    }

    #[Path('/change-password'), Method('POST'), LoggedIn]
    #[OpenApi(<<<'NEON'
operationId: changePassword
summary: "Změnit heslo přihlášeného uživatele"
tags: [Přístup]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [current_password, new_password]
                properties:
                    current_password: {type: string, writeOnly: true}
                    new_password: {type: string, writeOnly: true}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function changePassword(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->changePassword($input));
    }
}
