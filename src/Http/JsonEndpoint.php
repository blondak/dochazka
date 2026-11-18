<?php
declare(strict_types=1);

namespace Dochazka\Http;

use Apitte\Core\Http\{ApiRequest,ApiResponse};
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class JsonEndpoint
{
    public function __construct(private EntityManagerInterface $em) {}

    public function respond(ApiRequest $req, ApiResponse $res, callable $handler, bool $transaction = true, int $maxBytes = 524288, bool $readOnly = false): ApiResponse
    {
        try {
            if (strtolower(trim(explode(';',$req->getHeaderLine('Content-Type'))[0]))!=='application/json') throw new Problem('Očekáván JSON.',415);
            $raw=$req->getBody()->read($maxBytes + 1);
            if (strlen($raw)>$maxBytes) throw new Problem('Příliš velký požadavek.',413);
            $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) throw new Problem('Očekáván objekt.');
            if ($readOnly) {
                $result = $this->em->getConnection()->transactional(function ($db) use ($handler, $data) {
                    $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                    return $handler($data);
                });
            } elseif ($transaction) {
                $result=$this->em->wrapInTransaction(function(EntityManagerInterface $em) use ($handler,$data) {
                    $em->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(718312)');
                    return $handler($data);
                });
            } else {
                $result=$handler($data);
            }
        } catch (Problem $e) {
            $res=$res->withStatus($e->getCode()); $result=['error'=>$e->getMessage()];
        } catch (ConstraintViolationException $e) {
            $res=$res->withStatus(409); $result=['error'=>'Údaj již existuje nebo je v rozporu s evidencí.'];
        } catch (\JsonException|\TypeError|\DateMalformedStringException $e) {
            $res=$res->withStatus(422); $result=['error'=>'Neplatné vstupní údaje.'];
        }
        $res->getBody()->write(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type','application/json')->withHeader('Cache-Control','no-store');
    }
}
