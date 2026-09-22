<?php
declare(strict_types=1);
function startSession(): void { ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1'); session_name('ajabwen_session'); session_set_cookie_params(['httponly'=>true,'secure'=>(getenv('APP_ENV')?:'production')!=='development','samesite'=>'Lax','path'=>'/']); session_start(); if(isset($_SESSION['last']) && time()-$_SESSION['last']>86400) { $_SESSION=[]; session_regenerate_id(true); } $_SESSION['last']=time(); $_SESSION['csrf']??=bin2hex(random_bytes(32)); }
function currentUser(): ?array { if(empty($_SESSION['uid'])) return null; $u=query('SELECT id,phone,full_name,birth_date,avatar,role FROM users WHERE id=?',[$_SESSION['uid']])->fetch(); return $u?:null; }
function requireUser(bool $admin=false): array { $u=currentUser(); if(!$u) fail(401,'سجل الدخول أولًا'); if($admin&&$u['role']!=='admin') fail(403,'هذه العملية للمدير فقط'); return $u; }
function csrf(): void { if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail(419,'انتهت جلسة الحماية. أعد تحميل الصفحة'); }
function fullName(array $v): string { $s=text($v,'full_name',150,true); if(count(preg_split('/\s+/u',$s))<3) fail(422,'أدخل الاسم الثلاثي'); return $s; }
function birthDate(array $v): ?string { $s=text($v,'birth_date',10); if($s==='') return null; $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s); if(!$d||$d->format('Y-m-d')!==$s||$s>date('Y-m-d')||$s<'1900-01-01') fail(422,'تاريخ الميلاد غير صالح'); return $s; }
function authRoute(string $path,string $method): void {
 if($path==='/session'&&$method==='GET') respond(['user'=>currentUser(),'csrf'=>$_SESSION['csrf']]);
 if(in_array($path,['/login','/register'],true)&&$method==='POST') {
  rateLimit('auth-ip:'.($_SERVER['REMOTE_ADDR']??''),40); $v=body(); $p=phone(text($v,'phone',30,true),true); rateLimit('auth-phone:'.$p,12); $pw=text($v,'password',128,true);
  if($path==='/register') { if(strlen($pw)<10) fail(422,'كلمة المرور يجب أن تكون 10 محارف على الأقل'); $name=fullName($v); $birth=birthDate($v); if(query('SELECT id FROM users WHERE phone=?',[$p])->fetch()) fail(409,'تعذر إنشاء الحساب بهذا الرقم'); query('INSERT INTO users(phone,password_hash,full_name,birth_date) VALUES(?,?,?,?)',[$p,password_hash($pw,PASSWORD_DEFAULT),$name,$birth]); $id=(int)db()->lastInsertId(); }
  else { $u=query('SELECT * FROM users WHERE phone=?',[$p])->fetch(); if(!$u||!password_verify($pw,$u['password_hash'])) fail(401,'رقم الهاتف أو كلمة المرور غير صحيح'); $id=(int)$u['id']; }
  session_regenerate_id(true); $_SESSION['uid']=$id; $_SESSION['csrf']=bin2hex(random_bytes(32)); respond(['user'=>currentUser(),'csrf'=>$_SESSION['csrf']]);
 }
 if($path==='/logout'&&$method==='POST') { $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); respond(['user'=>null,'csrf'=>$_SESSION['csrf']]); }
 if($path==='/profile'&&$method==='PATCH') { $u=requireUser(); $v=body(); $name=fullName($v); $birth=birthDate($v); query('UPDATE users SET full_name=?,birth_date=? WHERE id=?',[$name,$birth,$u['id']]); respond(['user'=>currentUser()]); }
}
