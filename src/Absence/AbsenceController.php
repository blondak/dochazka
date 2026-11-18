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
summary: "Podat žádost nebo opravu. Dovolená používá první a poslední kalendářní den, ostatní druhy místní čas v Europe/Prague."
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
summary: "Přiložit fotografii po skončení schválené návštěvy lékaře"
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
