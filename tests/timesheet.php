<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Dochazka\Attendance\Timesheet;
date_default_timezone_set('Europe/Prague');
function check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
function signal(string $kind,string $at,int $pc=1): array {
    return ['kind'=>$kind,'happened'=>$at,'device'=>$pc,'session_key'=>'boot.session'];
}
function absence(string $kind,string $from,string $to): array {
    return ['kind'=>$kind,'begins'=>$from,'ends'=>$to,'status'=>'approved'];
}
$signals=[signal('login','2026-11-02 08:00'),signal('login','2026-11-02 09:00',2),
    signal('logout','2026-11-02 12:00',2),signal('logout','2026-11-02 16:00')];
$r=Timesheet::build('2026-11',$signals,[absence('LEKAR','2026-11-02 10:00','2026-11-02 11:00')]);
check($r['seconds']===7*3600 && count($r['rows'])===3,'doctor and overlap');
$r=Timesheet::build('2026-11',$signals,[absence('DOVOLENA','2026-11-02','2026-11-03')]);
check(count($r['issues'])===1 && $r['vacation_conflict'],'vacation conflict');
$r=Timesheet::build('2026-10',[signal('login','2026-10-24 23:00+02'),signal('logout','2026-10-25 03:00+01')],[]);
check($r['seconds']===18000 && count($r['rows'])===2,'DST and midnight');
$r = Timesheet::build('2026-10', [
    signal('login', '2026-10-25 02:45+02'), signal('logout', '2026-10-25 02:50+02'),
    signal('login', '2026-10-25 02:10+01'), signal('logout', '2026-10-25 02:20+01'),
], []);
check($r['seconds'] === 900 && str_ends_with($r['rows'][0]['from'], '+02:00'), 'chronological order in repeated hour');
$r=Timesheet::build('2026-11',[signal('login','2026-11-02 08:00'),signal('sleep','2026-11-02 09:00')],[]);
check($r['seconds']===3600 && count($r['issues'])===1,'incomplete sleep does not accrue hours');
$signals = [
    signal('login', '2026-10-31 21:00'),
    signal('sleep', '2026-10-31 23:00'),
    signal('resume', '2026-11-01 08:00'),
    signal('logout', '2026-11-01 12:00'),
];
$october = Timesheet::build('2026-10', $signals, []);
$november = Timesheet::build('2026-11', $signals, []);
check($october['seconds'] === 7200 && !$october['issues'], 'work before sleep');
check($november['seconds'] === 14400 && !$november['issues'], 'work after resume');
$r = Timesheet::build('2026-11', [signal('login', '2026-11-01 09:00'), signal('gap', '2026-11-01 10:00'),
    signal('logout', '2026-11-01 17:00')], []);
check(!$r['seconds'] && $r['issues'] !== [], 'uncertain interval crossing journal gap');
$r = Timesheet::build('2026-11', [signal('login', '2026-11-01 22:00'), signal('logout', '2026-11-02 05:00')], [],
    new DateTimeImmutable('2026-11-02 00:00'), new DateTimeImmutable('2026-12-01 00:00'));
check($r['seconds'] === 18000 && count($r['rows']) === 1, 'night shift clipped to employment start');
$restart = [
    signal('login', '2026-11-02 08:00'), signal('logout', '2026-11-02 09:00'),
    signal('login', '2026-11-02 09:05'), signal('logout', '2026-11-02 10:00'),
];
$limits = ['2026-11-02' => 5, '2026-11-03' => 5];
$r = Timesheet::build('2026-11', $restart, [], mergeLimits: $limits);
check($r['seconds'] === 7200 && count($r['rows']) === 1, 'restart merged at the exact limit');
$r = Timesheet::build('2026-11', $restart, [], mergeLimits: ['2026-11-02' => 4]);
check($r['seconds'] === 6900 && count($r['rows']) === 2, 'gap larger than limit');
$justOver = $restart;
$justOver[2]['happened'] = '2026-11-02 09:05:00.100';
$r = Timesheet::build('2026-11', $justOver, [], mergeLimits: $limits);
check(count($r['rows']) === 2, 'subsecond excess of exact limit');
$r = Timesheet::build('2026-11', $restart, [absence('PRESTAVKA', '2026-11-02 09:01', '2026-11-02 09:02')], mergeLimits: $limits);
check($r['seconds'] === 6900 && count($r['rows']) === 2, 'explicit break prevents automatic merge');
$blocked = [[strtotime('2026-11-02 09:01'), strtotime('2026-11-02 09:02')]];
$r = Timesheet::build('2026-11', $restart, [], mergeLimits: $limits, serviceRanges: $blocked);
check($r['seconds'] === 6900 && count($r['rows']) === 2, 'service session prevents automatic merge');
$r = Timesheet::build('2026-11', [
    signal('login', '2026-11-02 23:55'), signal('logout', '2026-11-03 00:00'),
    signal('login', '2026-11-03 00:04'), signal('logout', '2026-11-03 00:09'),
], [], mergeLimits: $limits);
check($r['seconds'] === 600, 'restart at midnight is not merged across days');
$rule = ['enabled' => true, 'minutes' => 30, 'preferred' => 240, 'deadline' => 360, 'effective_on' => '2026-01-01'];
$policies = ['break_rules' => ['PRACE' => [$rule]]];
$signals = [signal('login', '2026-11-02 09:10'), signal('logout', '2026-11-02 17:40')];
$r = Timesheet::build('2026-11', $signals, [], policies: $policies);
check($r['seconds'] === 28800 && str_contains($r['breaks'][0]['from'], 'T13:10:'), 'break follows actual arrival');
$late = Timesheet::build('2026-11', $signals, [absence('PRESTAVKA','2026-11-02 16:00','2026-11-02 16:30')], policies: $policies);
check($late['seconds'] === 28800 && $late['breaks'][0]['source'] === 'recorded' && $late['unresolved_breaks'] === 1,
    'a late recorded break is flagged without deducting another break');
$long = [signal('login', '2026-11-02 06:00'), signal('logout', '2026-11-02 20:00')];
$r = Timesheet::build('2026-11', $long, [], policies: $policies);
check(count($r['breaks']) === 2 && $r['seconds'] === 13 * 3600, 'long work receives a second break');
$r = Timesheet::build('2026-11', [signal('login','2026-11-02 06:00'),signal('logout','2026-11-02 12:00')], [], policies:$policies);
check($r['seconds'] === 21600 && empty($r['breaks']), 'exactly six hours');
$overnight = [signal('login','2026-11-02 20:00'),signal('logout','2026-11-03 04:30')];
$r = Timesheet::build('2026-11', $overnight, [], policies:$policies);
check($r['seconds'] === 28800 && str_contains($r['breaks'][0]['from'], 'T00:00:'), 'a midnight within a shift is not a break');
$round = \Dochazka\Attendance\DurationRules::rounded(...);
check($round(28020,['mode'=>'down','minutes'=>30]) === 27000, 'rounding down');
check($round(28020,['mode'=>'up','minutes'=>30]) === 28800, 'rounding up');
check($round(27900,['mode'=>'nearest','minutes'=>30]) === 28800, 'nearest increments at the halfway point');
check($round(1,['mode'=>'off','minutes'=>30]) === 1, 'no rounding');
echo "Timesheet OK\n";
