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
    public function __construct(
        private JsonEndpoint $json,
        private Timesheets $service,
        private DailyAttendance $daily,
        private MergePolicy $mergePolicy,
    ) {}

    #[Path('/daily-attendance'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: dailyAttendance
summary: "Vedoucí zobrazí dnešní stav docházky svého týmu"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/DailyAttendance'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
NEON)]
    #[LoggedIn, Role('manager')]
    public function dailyAttendance(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->daily->view(...), readOnly: true, maxBytes: 1024);
    }

    #[Path('/merge-work-rows'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: mergeWorkRows
summary: "Vedoucí potvrdí spojení pracovních úseků v jednom dni"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [employee, month, fingerprint, rows]
                properties:
                    employee: {type: integer}
                    month: {type: string}
                    fingerprint: {type: string}
                    rows: {type: array, minItems: 2, uniqueItems: true, items: {type: integer, minimum: 0}}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('manager')]
    public function merge(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->service->merge(...), maxBytes: 8192);
    }

    #[Path('/attendance-merge-settings'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: attendanceMergeSettings
summary: "Správce načte dnešní limit automatického spojování"
tags: [Správa]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/AttendanceMergeLimit'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
NEON)]
    #[LoggedIn, Role('admin')]
    public function mergeSettings(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->mergePolicy->settings(...), readOnly: true, maxBytes: 1024);
    }

    #[Path('/attendance-merge-limit'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: attendanceMergeLimit
summary: "Správce nastaví limit automatického spojování s účinností ode dneška"
tags: [Správa]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema: {$ref: '#/components/schemas/AttendanceMergeLimit'}
responses:
    '200': {$ref: '#/components/responses/AttendanceMergeLimit'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('admin')]
    public function mergeLimit(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->mergePolicy->change(...), maxBytes: 1024);
    }

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
