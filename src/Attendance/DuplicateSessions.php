<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Dochazka\Audit\AuditTrail;
use Dochazka\Http\Problem;
use Dochazka\Identity\Accounts;

final class DuplicateSessions
{
    public function __construct(private Connection $db, private Accounts $accounts, private AuditTrail $audit) {}

    public function preview(array $input): array
    {
        $this->accounts->actor('admin');
        return $this->find($input);
    }

    public function apply(array $input): array
    {
        $actor = $this->accounts->actor('admin');
        $tokens = $input['tokens'] ?? null;
        $reason = trim($input['reason'] ?? '');
        if (!is_array($tokens) || !array_is_list($tokens) || !$tokens || $reason === '' || strlen($reason) > 2000) {
            throw new Problem('Vyberte relace a uveďte důvod změny.');
        }
        $current = array_column($this->find($input), null, 'token');
        foreach ($tokens as $token) {
            if (!is_string($token) || !isset($current[$token]) || $current[$token]['state'] === 'incomplete') {
                throw new Problem('Náhled už neodpovídá evidenci. Načtěte jej znovu.', 409);
            }
            $row = $current[$token];
            unset($current[$token]);
            $restore = $row['state'] === 'suppressed';
            $this->db->executeStatement('UPDATE signal_session SET duplicate_of = ?
                WHERE device = ? AND session_key = ? AND sid = (SELECT sid FROM employee WHERE id = ?)',
                [$restore ? null : $row['kept'], $row['device'], $row['session'], $row['employee']]);
            $this->audit->audit($actor['id'], $restore ? 'duplicate-session-restore' : 'duplicate-session-suppress', [
                'employee'=>$row['employee'], 'device'=>$row['device'], 'session'=>$row['session'],
                'kept'=>$row['kept'], 'reason'=>$reason,
            ]);
        }
        return ['changed'=>count($tokens)];
    }

    private function find(array $input): array
    {
        $from = WorkModels::day($input['from'] ?? '');
        $to = WorkModels::day($input['to'] ?? '');
        if ($to < $from || $from->diff($to)->days > 30 || $to >= new DateTimeImmutable('today', $from->getTimezone())) {
            throw new Problem('Vyberte nejvýše 31 dokončených dnů.');
        }
        $params = ['start'=>$from->format(DATE_ATOM), 'end'=>$to->modify('+1 day')->format(DATE_ATOM)];
        $filter = '';
        if (array_key_exists('employee', $input)) {
            if (!is_int($input['employee']) || $input['employee'] < 1) throw new Problem('Neplatná osoba.');
            $params['employee'] = $input['employee'];
            $filter = 'AND e.id = :employee';
        }
        $records = $this->db->executeQuery(<<<SQL
            SELECT e.id AS employee, e.name, d.id AS device, d.name AS station, ss.session_key AS session,
                ss.first_event, ss.duplicate_of, ss.service_at, stats.events, stats.closed,
                split_part(ss.session_key, '.', 1) AS boot,
                (ss.first_event AT TIME ZONE 'Europe/Prague')::date AS day
            FROM signal_session ss JOIN employee e ON e.sid=ss.sid JOIN device d ON d.id=ss.device
            JOIN LATERAL (
                SELECT count(*) AS events, bool_or(s.kind IN ('logout','gap')) AS closed,
                    max(s.happened) FILTER (WHERE s.kind='logout') AS logout,
                    min(s.happened) FILTER (WHERE s.kind='login') AS login
                FROM signal s WHERE (s.sid,s.device,s.session_key)=(ss.sid,ss.device,ss.session_key)
            ) stats ON stats.login=ss.first_event
            WHERE ss.first_event >= :start AND ss.first_event < :end $filter
            ORDER BY employee,device,boot,day,ss.first_event,ss.session_key
            SQL, $params);
        $result = $group = [];
        $previous = null;
        foreach ($records->iterateAssociative() as $row) {
            $time = new DateTimeImmutable($row['first_event']);
            $at = (int) $time->format('Uu');
            $key = [$row['employee'], $row['device'], $row['boot'], $row['day']];
            if ($previous !== null && ($key !== $previous || $at - $last > 1000000)) {
                array_push($result, ...self::classify($group));
                $group = [];
            }
            $row['first_event'] = $time->setTimezone($from->getTimezone())->format('Y-m-d\TH:i:s.uP');
            if ($row['logout'] !== null) {
                $row['logout'] = (new DateTimeImmutable($row['logout']))->setTimezone($from->getTimezone())->format(DATE_ATOM);
            }
            $group[] = $row;
            $previous = $key;
            $last = $at;
        }
        array_push($result, ...self::classify($group));
        return $result;
    }

    private static function classify(array $group): array
    {
        $kept = null;
        if (count($group) === 2) {
            [$a, $b] = $group;
            // Only Windows logon IDs from the same known boot can form a pair.
            if (preg_match('/^[0-9]{1,19}\.0x[0-9a-f]{1,16}$/D', $a['session'])
                && preg_match('/^[0-9]{1,19}\.0x[0-9a-f]{1,16}$/D', $b['session'])
                && $a['service_at'] === null && $b['service_at'] === null
                && $a['duplicate_of'] === null && $b['duplicate_of'] === null
                && min($a['events'], $b['events']) === 1) {
                // Keep the better documented session, breaking ties by timestamp and key.
                $kept = $b['events'] > $a['events'] ? $b : $a;
            }
        }

        $result = [];
        foreach ($group as $row) {
            if ($row['service_at'] !== null || $row['closed'] || $row['session'] === ($kept['session'] ?? null)) continue;
            $state = $row['duplicate_of'] !== null ? 'suppressed' : ($kept ? 'candidate' : 'incomplete');
            $item = [
                'state'=>$state, 'employee'=>$row['employee'], 'name'=>$row['name'],
                'device'=>$row['device'], 'station'=>$row['station'], 'session'=>$row['session'],
                'from'=>$row['first_event'], 'kept'=>$row['duplicate_of'] ?? $kept['session'] ?? null,
                'kept_from'=>$kept['first_event'] ?? null, 'kept_events'=>$kept['events'] ?? null,
                'kept_to'=>$kept['logout'] ?? null,
            ];
            $item['token'] = hash('sha256', json_encode($item, JSON_THROW_ON_ERROR));
            $result[] = $item;
        }
        return $result;
    }
}
