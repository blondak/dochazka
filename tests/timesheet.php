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
echo "Timesheet OK\n";
