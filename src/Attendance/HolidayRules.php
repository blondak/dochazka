<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use DateTimeImmutable as Date;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Dochazka\Audit\AuditTrail;
use Dochazka\Http\Problem;

final class HolidayRules
{
    public function __construct(private Connection $db, private AuditTrail $audit, private Catalog $catalog) {}

    public function listing(): array
    {
        return $this->db->createQueryBuilder()->select('*')->from('holiday_rule')
            ->orderBy('code')->addOrderBy('valid_from', 'DESC')->fetchAllAssociative();
    }

    public function remove(int $actor, array $input): array
    {
        $id = $input['id'] ?? null;
        if (!is_int($id) || $id < 1) throw new Problem('Vyberte pravidlo svátku.');
        $before = $this->db->createQueryBuilder()->select('*')->from('holiday_rule')
            ->where('id = :id')->setParameter('id', $id)->fetchAssociative();
        if (!$before) throw new Problem('Pravidlo svátku nebylo nalezeno.', 404);

        $today = (new Date('today', new DateTimeZone('Europe/Prague')))->format('Y-m-d');
        if ($before['valid_to'] !== null && $before['valid_to'] <= $today) {
            return ['ok' => true];
        }
        if ($before['valid_from'] > $today) {
            $this->db->delete('holiday_rule', ['id' => $id]);
            $after = null;
        } else {
            $this->db->update('holiday_rule', ['valid_to' => $today], ['id' => $id]);
            $after = array_replace($before, ['valid_to' => $today]);
        }
        $this->audit->audit($actor, 'calendar-holiday-remove', ['id' => $id, 'before' => $before, 'after' => $after]);
        return ['ok' => true];
    }

    public function save(int $actor, array $input): array
    {
        $id = $input['id'] ?? null;
        $before = null;
        if ($id !== null) {
            $before = is_int($id) ? $this->db->createQueryBuilder()->select('*')->from('holiday_rule')
                ->where('id = :id')->setParameter('id', $id)->fetchAssociative() : false;
            if (!$before) throw new Problem('Pravidlo svátku nebylo nalezeno.', 404);
        }
        $code = $input['code'] ?? '';
        $name = trim($input['name'] ?? '');
        if (!is_string($code) || !preg_match('/^[A-Z][A-Z0-9_]{0,39}$/D', $code) || $name === '' || mb_strlen($name) > 160 || str_contains($name, "\0")) {
            throw new Problem('Zadejte název a kód svátku tvořený velkými písmeny bez diakritiky, číslicemi nebo podtržítkem.');
        }
        if ($before && $before['code'] !== $code) throw new Problem('Kód uloženého svátku nelze změnit.');
        $from = WorkModels::day($input['valid_from'] ?? '')->format('Y-m-d');
        $to = isset($input['valid_to']) ? WorkModels::day($input['valid_to'])->format('Y-m-d') : null;
        if ($to !== null && $to < $from) throw new Problem('Konec platnosti předchází začátku.');
        $month = $input['month'] ?? null;
        $day = $input['day'] ?? null;
        $offset = $input['easter_offset'] ?? null;
        if ($offset === null) {
            if (!is_int($month) || !is_int($day) || !checkdate($month, $day, 2000)) throw new Problem('Zadejte platný den a měsíc.');
        } elseif ($month !== null || $day !== null || !is_int($offset) || abs($offset) > 366) {
            throw new Problem('Pohyblivý svátek vyžaduje pouze posun od Velikonoc v rozsahu −366 až 366 dní.');
        }
        $overlap = $this->db->createQueryBuilder()->select('id')->from('holiday_rule')
            ->where('code = :code', 'id <> :id', '(valid_to IS NULL OR valid_to >= :first)')
            ->setParameter('code', $code)->setParameter('id', $id ?? 0)->setParameter('first', $from)->setMaxResults(1);
        if ($to !== null) $overlap->andWhere('valid_from <= :last')->setParameter('last', $to);
        if ($overlap->fetchOne()) throw new Problem('Období platnosti téhož svátku se překrývají.', 409);
        $account = isset($input['account']) ? $this->catalog->activeCode($input['account']) : null;
        $rule = ['account' => $account, 'code' => $code, 'name' => $name, 'valid_from' => $from, 'valid_to' => $to, 'month' => $month, 'day' => $day, 'easter_offset' => $offset];
        if ($account !== null) {
            $overrides = $this->db->createQueryBuilder()->select('*')->from('holiday_rule')
                ->where('id <> :id', 'account <> :account', '(valid_to IS NULL OR valid_to >= :from)')
                ->setParameter('id', $id ?? 0)->setParameter('account', $account)->setParameter('from', $from);
            if ($to !== null) $overrides->andWhere('valid_from <= :to')->setParameter('to', $to);
            foreach ($overrides->fetchAllAssociative() as $other) {
                if ($month !== null && $other['month'] !== null && ($month !== $other['month'] || $day !== $other['day'])) continue;
                $first = WorkModels::day(max($from, $other['valid_from']));
                $last = WorkModels::day(min($to ?? '9999-12-31', $other['valid_to'] ?? '9999-12-31'));
                self::occurrences([$rule, $other], $first, $last);
            }
        }
        $custom = $this->db->createQueryBuilder()->select('day')->from('attendance_calendar_day')
            ->where('day >= :first')->setParameter('first', $from);
        if ($to !== null) $custom->andWhere('day <= :last')->setParameter('last', $to);
        foreach ($custom->fetchFirstColumn() as $customDay) {
            $date = WorkModels::day($customDay);
            if (self::occurrences([$rule], $date, $date)) throw new Problem("Na $customDay již připadá firemní den volna.", 409);
        }
        if ($id === null) {
            $this->db->insert('holiday_rule', $rule);
            $id = (int) $this->db->lastInsertId();
        } else {
            $this->db->update('holiday_rule', $rule, ['id' => $id]);
        }
        $this->audit->audit($actor, 'calendar-holiday', ['id' => $id, 'before' => $before, 'after' => $rule]);
        return ['id' => $id];
    }

    /** List the names occurring in the inclusive date interval, once per calendar day. */
    public function dates(Date $first, Date $last): array
    {
        $rules = $this->db->createQueryBuilder()->select('*')->from('holiday_rule')
            ->where('valid_from <= :last', '(valid_to IS NULL OR valid_to >= :first)')
            ->setParameter('first', $first->format('Y-m-d'))->setParameter('last', $last->format('Y-m-d'))
            ->orderBy('code')->fetchAllAssociative();
        return self::occurrences($rules, $first, $last);
    }

    private static function occurrences(array $rules, Date $first, Date $last): array
    {
        $days = [];
        $from = $first->format('Y-m-d');
        $to = $last->format('Y-m-d');
        // Large Easter offsets may fall in the adjacent civil year.
        for ($year = max(1, (int) $first->format('Y') - 1); $year <= min(9999, (int) $last->format('Y') + 1); $year++) {
            $easter = self::easterSunday($first, $year);
            foreach ($rules as $rule) {
                if ($rule['easter_offset'] !== null) {
                    $date = $easter->modify(sprintf('%+d days', $rule['easter_offset']))->format('Y-m-d');
                } else {
                    if (!checkdate($rule['month'], $rule['day'], $year)) continue;
                    $date = sprintf('%04d-%02d-%02d', $year, $rule['month'], $rule['day']);
                }
                if ($date < $from || $date > $to || $date < $rule['valid_from'] || ($rule['valid_to'] !== null && $date > $rule['valid_to'])) continue;
                $entry = $days[$date] ?? ['labels' => [], 'account' => null];
                if ($entry['account'] !== null && $rule['account'] !== null && $entry['account'] !== $rule['account']) {
                    throw new Problem("Na $date připadají dva svátky s různými výslovně zvolenými konty.", 409);
                }
                $entry['labels'][] = $rule['name'];
                $entry['account'] ??= $rule['account'];
                $days[$date] = $entry;
            }
        }
        return array_map(static fn(array $entry) => ['name' => implode(' / ', array_unique($entry['labels'])), 'account' => $entry['account']], $days);
    }

    private static function easterSunday(Date $date, int $year): Date
    {
        // Gregorian Easter Sunday, using the Meeus/Jones/Butcher algorithm.
        $cycle = $year % 19;
        $century = intdiv($year, 100);
        $leap = intdiv($century, 4);
        $moon = (19 * $cycle + $century - $leap - intdiv($century - intdiv($century + 8, 25) + 1, 3) + 15) % 30;
        $yearInCentury = $year % 100;
        $weekday = (32 + 2 * ($century % 4) + 2 * intdiv($yearInCentury, 4) - $moon - ($yearInCentury % 4)) % 7;
        $march = $moon + $weekday - 7 * intdiv($cycle + 11 * $moon + 22 * $weekday, 451) + 114;
        return $date->setDate($year, intdiv($march, 31), $march % 31 + 1);
    }
}
