<?php
declare(strict_types=1);

namespace Dochazka\Attendance;

use DateTimeImmutable as Date;
use Doctrine\DBAL\Connection;
use Dochazka\Audit\AuditTrail;
use Dochazka\Http\Problem;

final class AttendanceCalendar
{
    public function __construct(private Connection $db, private AuditTrail $audit, private HolidayRules $holidays, private Catalog $catalog) {}

    public function settings(): array
    {
        return [
            'holidays' => $this->holidays->listing(),
            'holiday_accounts' => $this->db->createQueryBuilder()->select('effective_on', 'account')
                ->from('attendance_holiday_account')->orderBy('effective_on', 'DESC')->fetchAllAssociative(),
            'days' => $this->db->createQueryBuilder()->select('day', 'name', 'account')
                ->from('attendance_calendar_day')->orderBy('day', 'DESC')->fetchAllAssociative(),
        ];
    }

    public function setHolidayAccount(int $actor, array $input): array
    {
        $day = WorkModels::day($input['effective_on'] ?? '')->format('Y-m-d');
        $account = $this->catalog->activeCode($input['account'] ?? null);
        $prior = $this->db->createQueryBuilder()->select('account')->from('attendance_holiday_account')
            ->where('effective_on = :day')->setParameter('day', $day)->fetchOne();
        if ($prior === false) {
            $this->db->insert('attendance_holiday_account', ['account' => $account, 'effective_on' => $day]);
        } else {
            $this->db->update('attendance_holiday_account', ['account' => $account], ['effective_on' => $day]);
        }
        $this->audit->audit($actor, 'holiday-account', ['effective_on' => $day, 'before' => $prior ?: null, 'after' => $account]);
        return ['ok' => true];
    }

    public function setDay(int $actor, array $input): array
    {
        $date = WorkModels::day($input['day'] ?? '');
        $day = $date->format('Y-m-d');
        $prior = $this->db->createQueryBuilder()->select('name', 'account')->from('attendance_calendar_day')
            ->where('day = :day')->setParameter('day', $day)->fetchAssociative();
        $after = null;
        if (($input['remove'] ?? false) === true) {
            if (!$prior) throw new Problem('Firemní den již neexistuje.', 404);
            $this->db->delete('attendance_calendar_day', ['day' => $day]);
        } else {
            $name = trim($input['name'] ?? '');
            if ($name === '' || mb_strlen($name) > 160 || str_contains($name, "\0")) throw new Problem('Zadejte název dne do 160 znaků.');
            if ($this->holidays->dates($date, $date)) throw new Problem('Vybrané datum je již českým svátkem.', 409);
            $after = ['name' => $name, 'account' => $this->catalog->activeCode($input['account'] ?? null)];
            if ($prior) {
                $this->db->update('attendance_calendar_day', $after, ['day' => $day]);
            } else {
                $this->db->insert('attendance_calendar_day', ['day' => $day] + $after);
            }
        }
        $this->audit->audit($actor, 'calendar-day', ['day' => $day, 'before' => $prior ?: null, 'after' => $after]);
        return ['ok' => true];
    }

    public function days(Date $from, Date $until): array
    {
        $lastDay = $until->modify('-1 microsecond')->format('Y-m-d');
        $defined = $this->db->createQueryBuilder()->select('day', 'name', 'account')->from('attendance_calendar_day')
            ->where('day BETWEEN :first AND :last')->setParameter('first', $from->format('Y-m-d'))
            ->setParameter('last', $lastDay)->fetchAllAssociativeIndexed();
        $versions = $this->db->createQueryBuilder()->select('effective_on', 'account')->from('attendance_holiday_account')
            ->where('effective_on <= :last')->setParameter('last', $lastDay)->orderBy('effective_on', 'DESC')->fetchAllKeyValue();
        foreach ($this->holidays->dates($from, $until->modify('-1 microsecond')) as $day => $holiday) {
            if ($holiday['account'] !== null) {
                $defined[$day] = $holiday;
                continue;
            }
            foreach ($versions as $effective => $account) {
                if ($effective <= $day) {
                    $defined[$day] = ['name' => $holiday['name'], 'account' => $account];
                    break;
                }
            }
        }
        ksort($defined);
        return $defined;
    }
}
