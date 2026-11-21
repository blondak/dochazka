<?php
declare(strict_types=1);

namespace Dochazka\Staff;

use Apitte\Core\Annotation\Controller\{Path,Method,OpenApi};
use Apitte\Core\Http\{ApiRequest,ApiResponse};
use Apitte\Core\UI\Controller\IController;
use Dochazka\Http\JsonEndpoint;
use Nepada\SecurityAnnotations\Annotations\Role;
use Nepada\SecurityAnnotations\Annotations\LoggedIn;

#[Path('/api/v1')]
final class StaffController implements IController
{
    public function __construct(private JsonEndpoint $json, private Directory $service) {}

    #[Path('/people'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: people
summary: "Zobrazit osoby v rozsahu oprávnění"
tags: [Evidence]
requestBody: {$ref: '#/components/requestBodies/Devices'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/People'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn]
    public function people(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->people(), readOnly: true);
    }

    #[Path('/employee'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: employee
summary: "Správce nebo personalista vytvoří nebo upraví zaměstnance"
tags: [Správa]
requestBody: {$ref: '#/components/requestBodies/Employee'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/Employee'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('admin', 'hr')]
    public function employee(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->savePerson($input));
    }

    #[Path('/employee-archive'), Method('POST'), LoggedIn, Role('admin')]
    #[OpenApi(<<<'NEON'
operationId: employeeArchive
summary: "Správce archivuje zaměstnance nebo zruší archivaci"
tags: [Správa]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [id, archived]
                properties:
                    id: {type: integer}
                    archived: {type: boolean}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function archive(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->archive($input));
    }

    #[Path('/invite'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: invite
summary: "Správce vydá pozvánku s platností 48 hodin"
tags: [Správa]
requestBody: {$ref: '#/components/requestBodies/Invite'}
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
    public function invite(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->invite($input));
    }
}
