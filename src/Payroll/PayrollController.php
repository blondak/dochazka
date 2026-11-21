<?php
declare(strict_types=1);

namespace Dochazka\Payroll;

use Apitte\Core\Annotation\Controller\{Path,Method,OpenApi};
use Apitte\Core\Http\{ApiRequest,ApiResponse};
use Apitte\Core\UI\Controller\IController;
use Dochazka\Http\JsonEndpoint;
use Nepada\SecurityAnnotations\Annotations\Role;
use Nepada\SecurityAnnotations\Annotations\LoggedIn;

#[Path('/api/v1')]
final class PayrollController implements IController
{
    public function __construct(private JsonEndpoint $json, private Exports $service) {}

    #[Path('/payroll'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: payroll
summary: "Účetní zkontroluje připravenost měsíce"
tags: [Mzdy]
requestBody: {$ref: '#/components/requestBodies/Close'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/Close'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('payroll')]
    public function payroll(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->overview($input), readOnly: true);
    }

    #[Path('/close'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: close
summary: "Účetní uloží neměnnou verzi schváleného období"
tags: [Mzdy]
requestBody: {$ref: '#/components/requestBodies/Close'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/Close'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('payroll')]
    public function close(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->close($input), transaction: false);
    }

    #[Path('/download'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: download
summary: "Účetní stáhne zvolenou verzi jako text CSV v poli csv"
tags: [Mzdy]
requestBody: {$ref: '#/components/requestBodies/Download'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/Download'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('payroll')]
    public function download(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->download($input), readOnly: true);
    }
}
