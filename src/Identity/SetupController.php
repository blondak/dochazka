<?php

declare(strict_types=1);

namespace Dochazka\Identity;

use Apitte\Core\Annotation\Controller\{Path, Method, OpenApi};
use Apitte\Core\Http\{ApiRequest, ApiResponse};
use Apitte\Core\UI\Controller\IController;
use Dochazka\Http\{JsonEndpoint, PublicEndpoint};

#[Path('/api/v1')]
final class SetupController implements IController
{
    public function __construct(private JsonEndpoint $json, private InitialSetup $setup) {}

    #[Path('/setup-status'), Method('POST'), PublicEndpoint]
    #[OpenApi(<<<'NEON'
operationId: setupStatus
summary: "Zjistit dostupnost prvního nastavení"
tags: [Přístup]
requestBody: {$ref: '#/components/requestBodies/Devices'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
responses:
    '200':
        description: "Dostupnost průvodce"
        content:
            application/json:
                schema:
                    type: object
                    properties: {available: {type: boolean}}
NEON)]
    public function status(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn() => $this->setup->status(), transaction: false);
    }

    #[Path('/setup'), Method('POST'), PublicEndpoint]
    #[OpenApi(<<<'NEON'
operationId: setupCreate
summary: "Založit prvního správce pomocí instalačního kódu"
tags: [Přístup]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [token, name, login, password]
                properties:
                    token: {type: string, writeOnly: true}
                    name: {type: string}
                    login: {type: string}
                    password: {type: string, writeOnly: true}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function create(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->setup->create($input));
    }
}
