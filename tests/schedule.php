<?php
declare(strict_types=1);
require dirname(__DIR__).'/server/schedule.php';
function check(mixed $got,mixed $expected,string $name): void {if($got!==$expected){fwrite(STDERR,"FAIL: $name\n");exit(1);}echo "PASS: $name\n";}
function at(string $time): DateTimeImmutable{return new DateTimeImmutable($time,new DateTimeZone('Asia/Damascus'));}
$s=['schedule'=>[['day'=>1,'start'=>'09:00','end'=>'17:00']],'supports_duty'=>1];
check(serviceStatus($s,at('2026-09-21 09:00'))['status'],'open','opening boundary inclusive');
check(serviceStatus($s,at('2026-09-21 17:00'))['status'],'closed','closing boundary exclusive');
check(serviceStatus($s,at('2026-09-22 10:00'))['status'],'closed','different day');
$n=['schedule'=>[['day'=>6,'start'=>'22:00','end'=>'02:00']]];
check(serviceStatus($n,at('2026-09-26 23:00'))['status'],'open','overnight starts Saturday');
check(serviceStatus($n,at('2026-09-27 01:59'))['status'],'open','overnight wraps week');
check(serviceStatus($n,at('2026-09-27 02:00'))['status'],'closed','overnight ending boundary');
check(serviceStatus(['schedule'=>[]],at('2026-09-21 10:00'))['status'],'unknown','no schedule is unknown');
$o=$s+['override_status'=>'closed','override_until'=>'2026-09-21T12:00:00+03:00'];
check(serviceStatus($o,at('2026-09-21 10:00'))['status'],'closed','manual closure overrides schedule');
check(serviceStatus($o,at('2026-09-21 12:00'))['status'],'open','expired override returns to schedule');
$d=$s+['duty_until'=>'2026-09-22T12:00:00+03:00'];
check(serviceStatus($d,at('2026-09-21 10:00'))['on_duty'],true,'active duty');
check(serviceStatus($d,at('2026-09-22 12:00'))['on_duty'],false,'duty expires');
check(serviceStatus(['supports_duty'=>0,'duty_until'=>'2026-09-22T12:00:00+03:00'],at('2026-09-21 10:00'))['on_duty'],false,'duty unsupported');
check(serviceStatus($s,new DateTimeImmutable('2026-09-21T06:00:00Z'))['status'],'open','UTC normalized to Damascus');
check(serviceStatus(['schedule'=>'[{"day":1,"start":"09:00","end":"17:00"}]'],at('2026-09-21 10:00'))['status'],'open','database JSON schedule');
