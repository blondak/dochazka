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

    #[Path('/holiday-control'), Method('POST'), LoggedIn, Role('manager')]
    #[OpenApi(<<<'NEON'
operationId: holidayControl
summary: "Náhled nebo potvrzení kontrolního běhu svátků a firemního volna"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [from, to]
                properties:
                    employee: {type: integer}
                    from: {type: string, format: date}
                    to: {type: string, format: date}
                    token: {type: string}
responses:
    '200':
        description: Změny a důvody přeskočení svátků a firemního volna. Jeden den zaměstnance může mít více důvodů.
        content:
            application/json:
                schema:
                    type: object
                    required: [added, changed, removed, skipped]
                    properties:
                        added: {type: integer}
                        changed: {type: integer}
                        removed: {type: integer}
                        token: {type: string, description: Otisk pro potvrzení náhledu}
                        skipped:
                            type: object
                            required: [missing_model, without_shift, occupied, unchanged]
                            properties:
                                missing_model: {type: integer}
                                without_shift: {type: integer}
                                occupied: {type: integer}
                                unchanged: {type: integer}
NEON)]
    public function holidayControl(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->service->holidayControl(...));
    }

    #[Path('/daily-attendance'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: dailyAttendance
summary: "Vedoucí zobrazí stav docházky svého týmu pro vybraný den"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    content:
        application/json:
            schema:
                type: object
                properties:
                    day: {type: string, format: date, description: "Den v časovém pásmu Europe/Prague. Při vynechání se použije dnešek."}
responses:
    '200': {$ref: '#/components/responses/DailyAttendance'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '422': {$ref: '#/components/responses/Error422'}
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

    #[Path('/save-work-row'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: saveWorkRow
summary: "Vedoucí zapíše konto do výkazu nebo upraví pracovní úsek. Zápis se ihned schválí a uloží se důvod."
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [employee, month, fingerprint, day, reason]
                properties:
                    employee: {type: integer}
                    month: {type: string}
                    fingerprint: {type: string}
                    day: {type: string, format: date}
                    row: {type: integer, minimum: 0, description: "Index upravovaného úseku. Při vložení se vynechá."}
                    kind: {type: string, default: PRACE, description: "Aktivní konto. Úprava existujícího úseku používá PRACE."}
                    from: {type: string, description: "Povinné pro časové konto.", example: '08:05'}
                    to: {type: string, description: "Povinné pro časové konto. Pro konec dne použijte 24:00.", example: '16:00'}
                    reason: {type: string, minLength: 1}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('manager')]
    public function saveWork(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->service->saveWork(...), maxBytes: 8192);
    }

    #[Path('/attendance-break'), Method('POST'), LoggedIn, Role('manager')]
    #[OpenApi(<<<'NEON'
operationId: adjustBreak
summary: "Opravit nebo doložit přestávku"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [employee, month, fingerprint, reason]
                properties:
                    employee: {type: integer}
                    month: {type: string}
                    fingerprint: {type: string}
                    reason: {type: string}
                    day: {type: string, format: date}
                    mode: {type: string, enum: [record, none, ack, auto]}
                    id: {type: integer}
                    from: {type: string}
                    to: {type: string}
responses:
    '200': {$ref: '#/components/responses/Activate'}
NEON)]
    public function adjustBreak(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->service->adjustBreak(...));
    }

    #[Path('/adjust-recognized-work'), Method('POST'), LoggedIn, Role('manager')]
    #[OpenApi(<<<'NEON'
operationId: adjustDuration
summary: "Upravit uznanou délku úseku"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    required: true
    content:
        application/json:
            schema:
                type: object
                required: [employee, month, fingerprint, reason]
                properties:
                    employee: {type: integer}
                    month: {type: string}
                    fingerprint: {type: string}
                    reason: {type: string}
                    row: {type: integer}
                    seconds: {type: [integer, 'null']}
                    stale: {type: boolean}
responses:
    '200': {$ref: '#/components/responses/Activate'}
NEON)]
    public function adjustDuration(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->service->adjustDuration(...));
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
