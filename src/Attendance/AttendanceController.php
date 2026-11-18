<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use Apitte\Core\Annotation\Controller\{Path,Method,OpenApi};
use Apitte\Core\Http\{ApiRequest,ApiResponse};
use Apitte\Core\UI\Controller\IController;
use Dochazka\Http\JsonEndpoint;
use Nepada\SecurityAnnotations\Annotations\Role;
use Nepada\SecurityAnnotations\Annotations\LoggedIn;

#[Path('/api/v1')]
final class AttendanceController implements IController
{
    public function __construct(private JsonEndpoint $json, private Timesheets $service) {}

    #[Path('/sheet'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: sheet
summary: "Zobrazit vlastní výkaz, výkaz člena týmu nebo výkaz pro účtárnu"
tags: [Evidence]
requestBody: {$ref: '#/components/requestBodies/Sheet'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/Sheet'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn]
    public function sheet(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->view($input), readOnly: true);
    }

    #[Path('/approve'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: approve
summary: "Vedoucí potvrdí zkontrolovanou verzi výkazu"
tags: [Evidence]
requestBody: {$ref: '#/components/requestBodies/Approve'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('manager')]
    public function approve(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->confirm($input));
    }
}
