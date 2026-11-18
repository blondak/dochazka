<?php

declare(strict_types=1);

namespace Dochazka\Identity;

use Apitte\Core\Annotation\Controller\{Method, OpenApi, Path};
use Apitte\Core\Http\{ApiRequest, ApiResponse};
use Apitte\Core\UI\Controller\IController;
use Dochazka\Http\{JsonEndpoint, PublicEndpoint};
use Nepada\SecurityAnnotations\Annotations\LoggedIn;

#[Path('/api/v1')]
final class PasskeyController implements IController
{
    public function __construct(private JsonEndpoint $json, private Passkeys $passkeys) {}

    #[Path('/passkey-status'), Method('POST'), PublicEndpoint]
    #[OpenApi(<<<'NEON'
operationId: passkeyStatus
summary: "Zjistit dostupnost passkeys"
tags: [Přístup]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
requestBody: {$ref: '#/components/requestBodies/Devices'}
responses:
    '200':
        description: "Úspěšná odpověď"
        content:
            application/json:
                schema:
                    type: object
                    properties: {available: {type: boolean}}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function status(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn() => $this->passkeys->status(), transaction: false);
    }

    #[Path('/passkey-create-options'), Method('POST'), LoggedIn]
    #[OpenApi(<<<'NEON'
operationId: passkeyCreateOptions
summary: "Získat výzvu k založení passkey"
tags: [Přístup]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [password, label]
                properties:
                    password: {type: string, writeOnly: true}
                    label: {type: string}
responses:
    '200':
        description: "Úspěšná odpověď"
        content:
            application/json:
                schema:
                    type: object
                    properties: {publicKey: {type: object}}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function createOptions(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->passkeys->registrationOptions($input));
    }

    #[Path('/passkey-create'), Method('POST'), LoggedIn]
    #[OpenApi(<<<'NEON'
operationId: passkeyCreate
summary: "Uložit ověřenou passkey"
tags: [Přístup]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [clientDataJSON, attestationObject]
                properties:
                    clientDataJSON: {type: string}
                    attestationObject: {type: string}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function create(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->passkeys->register($input));
    }

    #[Path('/passkeys'), Method('POST'), LoggedIn]
    #[OpenApi(<<<'NEON'
operationId: passkeys
summary: "Zobrazit passkeys vlastního účtu"
tags: [Přístup]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody: {$ref: '#/components/requestBodies/Devices'}
responses:
    '200':
        description: "Úspěšná odpověď"
        content:
            application/json:
                schema:
                    type: object
                    properties: {keys: {type: array, items: {type: object, properties: {id: {type: string}, label: {type: string}, created: {type: string, format: date-time}}}}}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function keys(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn() => $this->passkeys->listing(), transaction: false);
    }

    #[Path('/passkey-remove'), Method('POST'), LoggedIn]
    #[OpenApi(<<<'NEON'
operationId: passkeyRemove
summary: "Odebrat vlastní passkey"
tags: [Přístup]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [id]
                properties:
                    id: {type: string}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function remove(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->passkeys->remove($input));
    }

    #[Path('/passkey-login-options'), Method('POST'), PublicEndpoint]
    #[OpenApi(<<<'NEON'
operationId: passkeyLoginOptions
summary: "Získat výzvu k přihlášení passkey"
tags: [Přístup]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
requestBody: {$ref: '#/components/requestBodies/Devices'}
responses:
    '200':
        description: "Úspěšná odpověď"
        content:
            application/json:
                schema:
                    type: object
                    properties: {publicKey: {type: object}}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function loginOptions(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn() => $this->passkeys->authenticationOptions(), transaction: false);
    }

    #[Path('/passkey-login'), Method('POST'), PublicEndpoint]
    #[OpenApi(<<<'NEON'
operationId: passkeyLogin
summary: "Ověřit passkey a zahájit relaci"
tags: [Přístup]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [id, clientDataJSON, authenticatorData, signature, userHandle]
                properties:
                    id: {type: string}
                    clientDataJSON: {type: string}
                    authenticatorData: {type: string}
                    signature: {type: string}
                    userHandle: {type: string}
responses:
    '200': {$ref: '#/components/responses/Login'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function login(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->passkeys->authenticate($input));
    }
}
