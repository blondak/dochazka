<?php
declare(strict_types=1);

namespace Dochazka\Absence;

use Apitte\Core\Annotation\Controller\{Path,Method,OpenApi};
use Apitte\Core\Http\{ApiRequest,ApiResponse};
use Apitte\Core\UI\Controller\IController;
use Dochazka\Http\JsonEndpoint;
use Nepada\SecurityAnnotations\Annotations\Role;
use Nepada\SecurityAnnotations\Annotations\LoggedIn;

#[Path('/api/v1')]
final class AbsenceController implements IController
{
    public function __construct(private JsonEndpoint $json, private Requests $service) {}

    #[Path('/request'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: request
summary: "Podat žádost. Celosměnová konta používají první a poslední kalendářní den, časová konta místní čas v Europe/Prague."
tags: [Evidence]
requestBody: {$ref: '#/components/requestBodies/Request'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/Employee'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn]
    public function request(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->submit($input));
    }

    #[Path('/request-preview'), Method('POST'), LoggedIn]
    #[OpenApi(<<<'NEON'
operationId: requestPreview
summary: "Spočítat čerpání žádosti před podáním"
tags: [Evidence]
requestBody: {$ref: '#/components/requestBodies/Request'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/RequestPreview'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function preview(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->service->preview(...), readOnly: true, maxBytes: 8192);
    }

    #[Path('/decide'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: decide
summary: "Vedoucí rozhodne o žádosti ve svém týmu. Zamítnutí a odvolání schválení vyžadují důvod."
tags: [Evidence]
requestBody: {$ref: '#/components/requestBodies/Decide'}
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
    public function decide(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->decide($input));
    }

    #[Path('/request-actual'), Method('POST'), LoggedIn]
    #[OpenApi(<<<'NEON'
operationId: requestActual
summary: "Doplnit skutečný interval nebo vrátit podklady k opravě"
tags: [Evidence]
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
                    id: {type: integer}
                    from: {type: string}
                    to: {type: string}
                    reason: {type: string}
                    return: {type: boolean}
                    photo: {type: string}
responses:
    '200': {$ref: '#/components/responses/Activate'}
NEON)]
    public function actual(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->service->actual(...), maxBytes: 7340032);
    }

    #[Path('/request-vacation-consent'), Method('POST'), LoggedIn]
    #[OpenApi(<<<'NEON'
operationId: requestVacationConsent
summary: "Souhlas zaměstnance s částečným čerpáním dovolené"
tags: [Evidence]
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
requestBody: {$ref: '#/components/requestBodies/Invite'}
responses:
    '200': {$ref: '#/components/responses/Activate'}
NEON)]
    public function vacationConsent(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, $this->service->consent(...));
    }

    #[Path('/request-proof'), Method('POST')]
    #[OpenApi(<<<'NEON'
operationId: requestProof
summary: "Zobrazit fotografii propustky. Přístup má zaměstnanec nebo vedoucí jeho týmu."
tags: [Evidence]
requestBody: {$ref: '#/components/requestBodies/Invite'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/MedicalProof'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '404': {description: Příloha není dostupná.}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    #[LoggedIn]
    public function proof(ApiRequest $request,ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->proof($input));
    }

    #[Path('/request-proof-upload'), Method('POST'), LoggedIn]
    #[OpenApi(<<<'NEON'
operationId: requestProofUpload
summary: "Přiložit povinný doklad po skončení schválené žádosti"
tags: [Evidence]
requestBody: {$ref: '#/components/requestBodies/MedicalProofUpload'}
parameters: [{$ref: '#/components/parameters/WebHeader'}]
security: [{session: []}]
responses:
    '200': {$ref: '#/components/responses/Activate'}
    '401': {$ref: '#/components/responses/Error401'}
    '403': {$ref: '#/components/responses/Error403'}
    '404': {description: Žádost není dostupná.}
    '409': {$ref: '#/components/responses/Error409'}
    '422': {$ref: '#/components/responses/Error422'}
NEON)]
    public function uploadProof(ApiRequest $request, ApiResponse $response): ApiResponse
    {
        return $this->json->respond($request, $response, fn(array $input) => $this->service->uploadProof($input), maxBytes: 7340032);
    }
}
