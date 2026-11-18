<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Dochazka\Audit\AuditTrail;
use Dochazka\Http\Problem;
use Dochazka\Identity\Accounts;
use Dochazka\Identity\Passkeys;
use Dochazka\Staff\Directory;

final class ServiceSessions
{
    public function __construct(
        private Connection $db,
        private EntityManagerInterface $em,
        private Accounts $accounts,
        private Passkeys $passkeys,
        private Directory $directory,
        private AuditTrail $audit,
    ) {}

    public function issue(int $device, array $input): array
    {
        $sid = $input['sid'] ?? '';
        $session = $input['session'] ?? '';
        if (!is_string($sid) || !preg_match('/^S-1-5-21-(?:\d+-){3}\d{1,10}$/D', $sid) || strlen($sid) > 185
            || !is_string($session) || !preg_match('/^\d{1,19}\.0x[0-9a-f]{1,16}$/D', $session)) {
            throw new Problem('Neplatná identifikace relace.');
        }
        $row = $this->lookup($device, $sid, $session);
        if (!$row || !$row['active']) {
            throw new Problem('Přihlášení ještě není v centrále nebo relace skončila. Zkuste to po odeslání docházky.', 409);
        }
        $code = bin2hex(random_bytes(32));
        $this->db->executeStatement('DELETE FROM service_session_link WHERE expires_at <= now()');
        $this->db->executeStatement(
            "INSERT INTO service_session_link (sid, device, session_key, code_hash, expires_at)
             VALUES (?, ?, ?, ?, now() + interval '10 minutes')
             ON CONFLICT (sid, device, session_key) DO UPDATE
             SET code_hash = EXCLUDED.code_hash, expires_at = EXCLUDED.expires_at",
            [$row['sid'], $device, $session, hash('sha256', $code)],
        );
        return ['code' => $code];
    }

    public function details(array $input): array
    {
        $ticket = $this->ticket($this->codeHash($input));
        return [
            'station' => $ticket['station'],
            'from' => (new \DateTimeImmutable($ticket['begins']))->format(DATE_ATOM),
        ];
    }

    public function passkeyOptions(array $input): object
    {
        $hash = $this->codeHash($input);
        $this->ticket($hash);
        return $this->passkeys->authenticationOptions($hash);
    }

    public function verify(#[\SensitiveParameter] array $input): array
    {
        $hash = $this->codeHash($input);
        $this->ticket($hash);
        // Keep password rate limits even when verification fails.
        $passwordActor = isset($input['passkey']) ? null : $this->accounts->verifyPasswordLogin($input);
        $version = $passwordActor?->sessionVersion;
        return $this->em->wrapInTransaction(function () use ($input, $hash, $passwordActor, $version): array {
            $this->db->executeStatement('SELECT pg_advisory_xact_lock(718312)');
            $actor = $passwordActor ?? $this->passkeys->verifyAuthentication($input['passkey'], $hash);
            if ($passwordActor) {
                $this->em->refresh($actor);
                if ($actor->sessionVersion !== $version) {
                    throw new Problem('Ověřte se znovu.', 401);
                }
            }
            $this->accounts->requireActive($actor);
            if (!in_array('admin', $actor->roles, true)) {
                throw new Problem('Označení servisní relace vyžaduje účet správce.', 403);
            }
            $row = $this->ticket($hash);
            $this->change($row, $actor->id, true);
            $this->db->delete('service_session_link', ['code_hash' => $hash]);
            return ['ok' => true];
        });
    }

    public function review(array $input): array
    {
        $actor = $this->accounts->actor('admin');
        $person = $this->directory->person($actor, (int) ($input['employee'] ?? 0));
        $device = $input['device'] ?? null;
        $session = $input['session'] ?? null;
        $service = $input['service'] ?? null;
        if (!is_int($device) || !is_string($session) || strlen($session) > 180 || !is_bool($service)) {
            throw new Problem('Neplatná identifikace relace.');
        }
        $row = $person['sid'] === null ? false : $this->lookup($device, $person['sid'], $session, false);
        if (!$row) {
            throw new Problem('Přihlášení nebylo nalezeno.', 404);
        }
        $this->change($row, $actor['id'], $service);
        return ['ok' => true];
    }

    private function lookup(int $device, string $sid, string $session, bool $requireActiveDevice = true): array|false
    {
        return $this->db->fetchAssociative(
            "SELECT ss.*, NOT EXISTS (
                 SELECT 1 FROM signal s WHERE (s.sid, s.device, s.session_key) = (ss.sid, ss.device, ss.session_key)
                 AND s.kind IN ('logout', 'gap')
             ) AS active
             FROM signal_session ss JOIN device d ON d.id = ss.device
             WHERE ss.device = ? AND ss.sid = ? AND ss.session_key = ?
               AND EXISTS (SELECT 1 FROM signal s WHERE (s.sid, s.device, s.session_key) = (ss.sid, ss.device, ss.session_key)
                   AND s.kind = 'login')".($requireActiveDevice ? ' AND NOT d.revoked' : ''),
            [$device, $sid, $session],
        );
    }

    private function codeHash(array $input): string
    {
        $code = $input['code'] ?? '';
        if (!is_string($code) || !preg_match('/^[a-f0-9]{64}$/D', $code)) {
            throw new Problem('Odkaz pro servisní přihlášení je neplatný.', 404);
        }
        return hash('sha256', $code);
    }

    private function ticket(string $hash): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT ss.sid, ss.device, ss.session_key, ss.service_at, ss.first_event AS begins, d.name AS station
             FROM service_session_link l JOIN signal_session ss USING (sid, device, session_key)
             JOIN device d ON d.id = l.device
             WHERE l.code_hash = ? AND l.expires_at > now() AND NOT d.revoked',
            [$hash],
        );
        if (!$row) {
            throw new Problem('Odkaz vypršel nebo už byl použit.', 404);
        }
        return $row;
    }

    private function change(array $row, int $actor, bool $service): void
    {
        if (($row['service_at'] !== null) === $service) {
            return;
        }
        $key = ['sid' => $row['sid'], 'device' => (int) $row['device'], 'session_key' => $row['session_key']];
        $this->db->update('signal_session', [
            'service_by' => $service ? $actor : null,
            'service_at' => $service ? (new \DateTimeImmutable())->format(DATE_ATOM) : null,
        ], $key);
        $this->audit->audit($actor, $service ? 'service-session-mark' : 'service-session-unmark', $key);
    }
}
