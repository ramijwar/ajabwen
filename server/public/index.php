<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/auth.php';
require dirname(__DIR__).'/schedule.php';
require dirname(__DIR__).'/services.php';
require dirname(__DIR__).'/uploads.php';
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');header('Cache-Control: no-store');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';
if(!str_starts_with($path,'/api/')) fail(404,'المسار غير موجود');
$path=substr($path,4);$method=$_SERVER['REQUEST_METHOD'];
startSession();
if(!in_array($method,['GET','HEAD'],true))csrf();
if($path==='/health'&&$method==='GET'){query('SELECT id FROM categories LIMIT 1');respond(['ok'=>true]);}
uploadRoute($path,$method);authRoute($path,$method);serviceRoute($path,$method);fail(404,'المسار غير موجود');
