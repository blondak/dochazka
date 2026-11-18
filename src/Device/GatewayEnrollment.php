<?php
declare(strict_types=1);

namespace Dochazka\Device;

use Doctrine\DBAL\Connection;
use Dochazka\Http\Problem;

final class GatewayEnrollment
{
    private const WINDOW = 120;

    public function __construct(private Connection $db, private string $realm, private string $keyFile) {}

    /** @return array{principal: string, timestamp: int, nonce: string} */
    public function verify(#[\SensitiveParameter] string $authorization): array
    {
        if ($this->realm === '' || $this->keyFile === '' || strlen($authorization) > 380 ||
            !preg_match('/^Dochazka-Gateway ([A-Z0-9][A-Z0-9-]{0,62}\$@[A-Z0-9][A-Z0-9.-]*):([0-9]{1,11}):([a-f0-9]{32}):([a-f0-9]{64})$/D', $authorization, $matches)) {
            throw self::denied();
        }
        [, $principal, $timestamp, $nonce, $signature] = $matches;
        if (!str_ends_with($principal, '@' . strtoupper($this->realm)) || abs(time() - (int) $timestamp) > self::WINDOW) {
            throw self::denied();
        }
        $key = trim((string) @file_get_contents($this->keyFile, length: 128));
        if (!preg_match('/^[a-f0-9]{64}$/D', $key) ||
            !hash_equals(hash_hmac('sha256', "dochazka-gateway-v1\n$timestamp\n$nonce\n$principal", hex2bin($key)), $signature)) {
            throw self::denied();
        }
        return ['principal' => $principal, 'timestamp' => (int) $timestamp, 'nonce' => $nonce];
    }

    /** @param array{principal: string, timestamp: int, nonce: string} $claim */
    public function consume(array $claim): void
    {
        $now = time();
        if (abs($now - $claim['timestamp']) > self::WINDOW) {
            throw self::denied();
        }
        $this->db->createQueryBuilder()
            ->delete('gateway_enrollment_nonce')
            ->where('expires < :now')
            ->setParameter('now', $now)
            ->executeStatement();
        if (!$this->db->executeStatement(
            'INSERT INTO gateway_enrollment_nonce(nonce, expires) VALUES(?, ?) ON CONFLICT DO NOTHING',
            [$claim['nonce'], $claim['timestamp'] + self::WINDOW],
        )) {
            throw self::denied();
        }
    }

    private static function denied(): Problem
    {
        return new Problem('Požadavek registrační brány nebylo možné ověřit.', 403);
    }
}
