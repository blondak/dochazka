<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use Apitte\Core\Annotation\Controller\{Path,Method,OpenApi};
use Apitte\Core\Http\{ApiRequest,ApiResponse};
use Apitte\Core\UI\Controller\IController;
use Dochazka\Http\JsonEndpoint;
use Nepada\SecurityAnnotations\Annotations\Role;
use Nepada\SecurityAnnotations\Annotations\LoggedIn;

use Dochazka\Identity\Accounts;
use Dochazka\Staff\Directory;

#[Path('/api/v1')]
final class CatalogController implements IController
{
    public function __construct(
        private JsonEndpoint $json,
        private Accounts $accounts,
        private Directory $directory,
        private Catalog $catalog,
        private WorkModels $models,
        private HolidayEntries $holidayEntries,
        private AttendanceCalendar $calendar,
        private HolidayRules $holidays,
    ) {}

    #[Path('/attendance-accounts'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: attendanceAccounts
summary: "Seznam kont docházky"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/AttendanceAccounts'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn]
    public function attendanceAccounts(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn() => $this->catalog->listing(in_array('admin', $this->accounts->actor()['roles'], true)), readOnly: true);
    }

    #[Path('/attendance-account'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: saveAttendanceAccount
summary: "Uložit konto a případně novou verzi mzdového kódu"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody: {$ref: '#/components/requestBodies/SaveAttendanceAccount'}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('admin')]
    public function saveAttendanceAccount(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, function (array $input): array {
            $actor = $this->accounts->actor('admin')['id'];
            $result = $this->catalog->save($actor, $input);
            if ($input['code'] === 'PRACE' && isset($input['break_rule'])) {
                $this->holidayEntries->reconcileBreakRule($input['break_rule']['effective_on'], $actor);
            }
            return $result;
        });
    }

    #[Path('/work-models'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: workModels
summary: "Seznam pracovních modelů a jejich verzí"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/WorkModels'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('admin')]
    public function workModels(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn() => $this->models->listing(), readOnly: true);
    }

    #[Path('/employee-model-choices'), Method('POST'), LoggedIn, Role('admin')]
    #[OpenApi(<<<'NEON'
operationId: employeeModelChoices
summary: "Pracovní modely a historie přiřazení pro dialog zaměstnance"
tags: [Správa]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody:
    content:
        application/json:
            schema:
                type: object
                properties:
                    employee: {type: integer, minimum: 1}
responses:
    '200': {description: Modely a přiřazení vybraného zaměstnance}
NEON)]
    public function modelChoices(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, function (array $input): array {
            $actor = $this->accounts->actor('admin');
            $employee = isset($input['employee']) ? $this->directory->person($actor, (int) $input['employee']) : null;
            return ['models' => $this->models->listing(), 'assignments' => $employee ? $this->models->assignments($employee['id']) : []];
        }, readOnly: true);
    }

    #[Path('/work-model-change-preview'), Method('POST'), LoggedIn, Role('admin')]
    #[OpenApi(<<<'NEON'
operationId: workModelChangePreview
summary: "Náhled dopadů změny rozvrhu nebo přiřazení"
tags: [Správa]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {description: Dotčené osoby a měsíce, žádosti a otisk náhledu}
NEON)]
    public function changePreview(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, function (array $input): array {
            $actor = $this->accounts->actor('admin');
            if (isset($input['employee'])) {
                $input['person'] = $this->directory->person($actor, (int) $input['employee']);
                if (isset($input['attendance_start'])) $input['person']['starts'] = WorkModels::day($input['attendance_start'])->format('Y-m-d');
                if (array_key_exists('attendance_end', $input)) $input['person']['ends'] = $input['attendance_end'] === null
                    ? null : WorkModels::day($input['attendance_end'])->format('Y-m-d');
            }
            return $this->models->preview($actor['id'], $input);
        });
    }

    #[Path('/work-model'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: saveWorkModel
summary: "Uložit novou verzi pracovního modelu"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody: {$ref: '#/components/requestBodies/SaveWorkModel'}
responses:
    '200': {$ref: '#/components/responses/Employee'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('admin')]
    public function saveWorkModel(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, function (array $input): array {
            $actor = $this->accounts->actor('admin')['id'];
            $result = $this->models->save($actor, $input);
            $this->holidayEntries->reconcileExisting($result['impact'], $actor);
            return ['id' => $result['id']];
        });
    }

    #[Path('/employee-work-model'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: assignWorkModel
summary: "Přiřadit pracovní model zaměstnanci"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody: {$ref: '#/components/requestBodies/AssignWorkModel'}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('admin')]
    public function assignWorkModel(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->assign(...));
    }

    #[Path('/calendar-settings'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: calendarSettings
summary: "Nastavení českých svátků a jednotlivých firemních dnů pracovního klidu"
tags: [Správa]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/CalendarSettings'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '404': {$ref: '#/components/responses/Error404'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('admin')]
    public function calendarSettings(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn() => $this->calendar->settings(), readOnly: true);
    }

    #[Path('/calendar-holiday-account'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: calendarHolidayAccount
summary: "Nastavit výchozí konto pro svátky bez vlastního konta od zvoleného data"
tags: [Správa]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody: {$ref: '#/components/requestBodies/HolidayAccount'}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '404': {$ref: '#/components/responses/Error404'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('admin')]
    public function calendarHolidayAccount(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->calendar->setHolidayAccount($this->accounts->actor('admin')['id'], $input));
    }

    #[Path('/calendar-holiday'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: saveHolidayRule
summary: "Přidat nebo upravit datované pravidlo svátku a jeho volitelné konto"
tags: [Správa]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody: {$ref: '#/components/requestBodies/HolidayRule'}
responses:
    '200': {$ref: '#/components/responses/Employee'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '404': {$ref: '#/components/responses/Error404'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('admin')]
    public function saveHolidayRule(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->holidays->save($this->accounts->actor('admin')['id'], $input));
    }

    #[Path('/calendar-holiday-remove'), Method('POST'), LoggedIn, Role('admin')]
    #[OpenApi(<<<'NEON'
operationId: removeHolidayRule
summary: "Odebrat budoucí pravidlo svátku nebo ukončit jeho platnost dneškem včetně"
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
                properties:
                    id: {type: integer, minimum: 1}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '404': {$ref: '#/components/responses/Error404'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function removeHolidayRule(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->holidays->remove($this->accounts->actor('admin')['id'], $input));
    }

    #[Path('/calendar-day'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: calendarDay
summary: "Uložit nebo odebrat firemní den pracovního klidu"
tags: [Správa]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody: {$ref: '#/components/requestBodies/SaveCalendarDay'}
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '404': {$ref: '#/components/responses/Error404'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn, Role('admin')]
    public function calendarDay(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->calendar->setDay($this->accounts->actor('admin')['id'], $input));
    }

    private function assign(array $input): array
    {
        $actor = $this->accounts->actor('admin');
        $person = $this->directory->person($actor, (int) ($input['employee'] ?? 0));
        $result = $this->models->assign($actor['id'], $person, $input);
        $this->holidayEntries->reconcileExisting($result['impact'], $actor['id']);
        return ['ok' => true];
    }
}
