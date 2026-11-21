<?php
declare(strict_types=1);

namespace Dochazka\Staff;

use Apitte\Core\Annotation\Controller\{Path,Method,OpenApi};
use Apitte\Core\Http\{ApiRequest,ApiResponse};
use Apitte\Core\UI\Controller\IController;
use Dochazka\Http\JsonEndpoint;
use Dochazka\Identity\Accounts;
use Nepada\SecurityAnnotations\Annotations\Role;
use Nepada\SecurityAnnotations\Annotations\LoggedIn;

#[Path('/api/v1')]
final class OrgUnitController implements IController
{
    public function __construct(private JsonEndpoint $json, private OrgUnits $units, private Accounts $accounts) {}

    #[Path('/org-units'), Method('POST'), LoggedIn, Role('admin', 'hr')]
    #[OpenApi(<<<'NEON'
operationId: orgUnitUnits
summary: "Organizační jednotky"
tags: [Správa]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200':
        description: Úspěch
        content:
            application/json:
                schema:
                    type: array
                    items:
                        type: object
                        required: [id, name, parent_id, path]
                        properties:
                            id: {type: integer}
                            name: {type: string}
                            parent_id: {type: [integer, 'null']}
                            path: {type: string}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function units(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn() => array_values($this->units->catalog()), readOnly: true);
    }

    #[Path('/org-unit'), Method('POST'), LoggedIn, Role('admin')]
    #[OpenApi(<<<'NEON'
operationId: orgUnitSave
summary: "Vytvořit, přejmenovat nebo přesunout jednotku"
tags: [Správa]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [name, parent_id]
                properties:
                    id: {type: integer}
                    name: {type: string}
                    parent_id: {type: [integer, 'null']}
responses:
    '200':
        description: Úspěch
        content:
            application/json:
                schema:
                    type: object
                    properties: {id: {type: integer}}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function save(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->units->save($this->accounts->actor('admin')['id'], $input));
    }

    #[Path('/org-unit-delete'), Method('POST'), LoggedIn, Role('admin')]
    #[OpenApi(<<<'NEON'
operationId: orgUnitDelete
summary: "Smazat prázdnou jednotku"
tags: [Správa]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [id]
                properties: {id: {type: integer}}
responses:
    '200':
        description: Úspěch
        content:
            application/json:
                schema:
                    type: object
                    properties: {ok: {type: boolean}}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function delete(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->units->delete($this->accounts->actor('admin')['id'], $input));
    }
}
