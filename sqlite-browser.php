<?php
/**
 * ============================================================================
 *  متصفّح قواعد بيانات SQLite — ملف واحد · للقراءة فقط (Read-Only)
 * ============================================================================
 *
 *  ما الذي يفعله هذا السكربت؟
 *   1) يبحث تلقائياً في المجلد والمجلدات الفرعية عن ملفات قواعد بيانات SQLite.
 *   2) يفتحها للقراءة فقط — لا يمكنه تعديل أي بيانات إطلاقاً.
 *   3) يصفّح كل الجداول والعروض (Views) والبيانات والمخطط (Schema).
 *   4) بحث داخل الجدول، ترتيب، ترقيم صفحات، تصدير CSV/JSON، عرض BLOB.
 *
 *  كيف تُشغّله؟
 *   • من المتصفح: ارفعه إلى مجلد على السيرفر ثم افتحه:
 *        https://example.com/sqlite-browser.php
 *   • من سطر الأوامر:
 *        php sqlite-browser.php --list
 *        php sqlite-browser.php --tables  backend/data/main.db
 *        php sqlite-browser.php --rows    backend/data/main.db users --limit=20
 *        php sqlite-browser.php --schema  backend/data/main.db
 *        php sqlite-browser.php --sql     backend/data/main.db "SELECT * FROM users LIMIT 5"
 *
 *  طبقات الحماية من الكتابة (أربع طبقات مستقلة):
 *   1) فتح الملف بوضع القراءة فقط على مستوى SQLite:  file:...?mode=ro
 *   2) فتحه بصلاحية READONLY على مستوى PDO:          PDO::SQLITE_OPEN_READONLY
 *   3) تفعيل  PRAGMA query_only = ON  على كل اتصال.
 *   4) السكربت نفسه لا يُنفّذ إلا جُمل SELECT/PRAGMA للقراءة، وأي استعلام
 *      يكتبه المستخدم يمرّ على فاحص يرفض كل شيء غير القراءة.
 *   كما أن المسار مقبول فقط إذا كان داخل مجلدات البحث المحددة أدناه وتحقّق
 *   منه بتوقيع HMAC، فلا يمكن استخدامه لقراءة ملف عشوائي خارج النطاق.
 *
 *  ملاحظة شفافة عن قواعد WAL:
 *   ملف القاعدة (.db) وملف اليوميات (-wal) لا يتغيّر فيهما أي بايت إطلاقاً عند
 *   القراءة (تم التحقق بمقارنة البصمة SHA-256 قبل وبعد). الشيء الوحيد الذي قد
 *   يتحدّث هو "الطابع الزمني" لملف الفهرس المؤقت (-shm) — وهو ملف ذاكرة مشترك
 *   متطاير يُعاد بناؤه تلقائياً ولا يحتوي على أي بيانات. هذا سلوك SQLite نفسه
 *   مع أي قارئ (بما فيه أداة sqlite3 الرسمية)، وهو ثمن قراءة بيانات WAL الحديثة
 *   بشكل صحيح. إن لم يكن ذلك مقبولاً بيئتك، فحوّل القاعدة إلى وضع DELETE من
 *   تطبيقك، أو اجعل المجلد غير قابل للكتابة فيُفتح الملف كـ"لقطة ثابتة".
 *
 *  المتطلبات: PHP 7.4+ مع pdo_sqlite (يفضّل 8.0+).
 * ============================================================================
 */

/* ===========================================================================
 * 1) الإعدادات — عدّل ما تشاء هنا
 * =========================================================================== */

/**
 * تعريف إعداد مع السماح بتجاوزه.
 * يمكنك إنشاء ملف config.php صغير يُعرّف هذه الثوابت ثم يستدعي هذا الملف،
 * أو تعديل القيم أدناه مباشرة.
 */
function sb_define(string $name, $value): void
{
    if (!defined($name)) {
        define($name, $value);
    }
}

/** المجلدات التي يُبحث فيها عن قواعد البيانات (يُبحث في كل مجلداتها الفرعية). */
sb_define('SB_SCAN_ROOTS', [__DIR__]);

/** أقصى عمق للمجلدات الفرعية (0 = المجلد نفسه فقط، 1 = مستوى فرعي واحد، ...). */
sb_define('SB_SCAN_MAX_DEPTH', 8);

/** مجلدات يتم تجاهلها دائماً أثناء البحث (بحروف صغيرة). */
sb_define('SB_SKIP_DIRS', [
    'node_modules', '.git', '.svn', '.hg', '.idea', '.vscode',
    'vendor', 'bower_components', '__pycache__', '.next', '.nuxt', 'dist', 'build',
]);

/** الامتدادات التي تُعتبر قاعدة بيانات محتملة. */
sb_define('SB_DB_EXTENSIONS', [
    'db', 'db3', 'sqlite', 'sqlite3', 'sqlite2', 's3db', 'sq3', 'db2', 'sl3', 'sdb',
]);

/** امتدادات "غامضة" تُفحص محتوياتها للتأكد من ترويسة SQLite. */
sb_define('SB_MAYBE_EXTENSIONS', ['dat', 'data', 'store', 'database', 'sqlitedb', '']);

/** هل نفحص الملفات بلا امتداد معروف بالاعتماد على ترويسة الملف؟ (أدق وأبطأ قليلاً) */
sb_define('SB_SCAN_BY_CONTENT', true);

/** حدود لحماية السيرفر من المجلدات الضخمة. */
sb_define('SB_SCAN_MAX_FILES', 60000);   // أقصى عدد ملفات يتم فحصها
sb_define('SB_SCAN_MAX_DIRS', 20000);    // أقصى عدد مجلدات يتم دخولها
sb_define('SB_SCAN_MAX_DBS', 500);       // أقصى عدد قواعد بيانات تُعرض
sb_define('SB_SCAN_MAX_PROBE_BYTES', 2147483648); // لا نفحص ترويسة ملف أكبر من 2GB

/** هل نتبع الروابط الرمزية (symlinks)؟ اتركها false لتجنّب الحلقات المتكررة. */
sb_define('SB_FOLLOW_SYMLINKS', false);

/** فتح قواعد البيانات المكتشفة في القائمة لعرض عدد جداولها (إن كان عددها صغيراً). */
sb_define('SB_PROBE_LIMIT', 25);

/** مدة صلاحية نتيجة البحث بالثواني (تُسرع التنقّل بين الصفحات). */
sb_define('SB_SCAN_CACHE_TTL', 20);

/** كلمة مرور اختيارية لفتح الأداة. اتركها '' للتعطيل (غير منصوح به على سيرفر عام). */
sb_define('SB_ACCESS_PASSWORD', '');

/** ملح التوقيع — غيّره إلى قيمة عشوائية خاصة بك (يُستخدم لحماية الروابط وكلمة المرور). */
sb_define('SB_HMAC_SALT', 'b7f3c1a9e42d4f68a05c9d21e7b64f3a8d1c50e92b7a46f3c8d01e5b92a7f4c6');

/** عدد الصفوف الافتراضي في الصفحة، وأقصى عدد مسموح. */
sb_define('SB_PAGE_SIZE', 50);
sb_define('SB_MAX_PAGE_SIZE', 1000);

/** أقصى عدد صفوف يُصدَّر في ملف CSV/JSON. */
sb_define('SB_EXPORT_MAX_ROWS', 200000);

/** أقصى عدد أحرف تُعرض في الخانة الواحدة قبل "عرض المزيد". */
sb_define('SB_CELL_MAX_CHARS', 240);

/** أقصى حجم BLOB يُسمح بتنزيله (بايت). */
sb_define('SB_BLOB_MAX_BYTES', 33554432);

/** عرض عدد صفوف كل جدول في قائمة الجداول (قد يبطئ القواعد الضخمة). */
sb_define('SB_SHOW_ROW_COUNTS', true);

/** ميزانية زمنية بالثواني لحساب أعداد الصفوف؛ ما يتجاوزها يظهر كـ "…". */
sb_define('SB_COUNTS_TIME_BUDGET', 4.0);

/** تفعيل لوحة الاستعلام (SELECT فقط، للقراءة). */
sb_define('SB_ALLOW_SQL_CONSOLE', true);

/**
 * السماح بفتح القاعدة بوضع غير "قراءة فقط صارم" إذا فشل الفتح الصارم.
 * اتركها false — فالوضع الصارم هو الضمانة الأساسية لعدم تعديل البيانات.
 */
sb_define('SB_ALLOW_NONRO_FALLBACK', false);

/** عدد الثواني الأقصى لتنفيذ السكربت (0 = بلا تغيير). */
sb_define('SB_TIME_LIMIT', 120);

/* ===========================================================================
 * 2) أدوات مساعدة عامة
 * =========================================================================== */

if (SB_TIME_LIMIT > 0 && function_exists('set_time_limit')) {
    @set_time_limit(SB_TIME_LIMIT);
}

/** تهريب نص للعرض داخل HTML. */
function sb_e($value): string
{
    if ($value === null) {
        return '';
    }
    if (!is_string($value)) {
        $value = is_scalar($value) ? (string) $value : print_r($value, true);
    }
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** تهريب نص للاستخدام داخل خاصية URL (مع ترميز صحيح للعربية). */
function sb_u($value): string
{
    return rawurlencode((string) $value);
}

/** قراءة أول $n بايت من ملف بدون تحميله كاملاً. */
function sb_head_bytes(string $path, int $n = 16): string
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return '';
    }
    $data = @fread($fh, $n);
    @fclose($fh);
    return is_string($data) ? $data : '';
}

/** هل هذا الملف قاعدة بيانات SQLite فعلاً (بحسب الترويسة الرسمية)؟ */
function sb_is_sqlite_file(string $path): bool
{
    if (!@is_file($path) || @filesize($path) === 0) {
        return false;
    }
    return substr(sb_head_bytes($path, 16), 0, 15) === 'SQLite format 3';
}

/** تحويل قيمة إلى نص آمن للعرض مع مراعاة الترميز. */
function sb_text($value): string
{
    if (is_string($value)) {
        return $value;
    }
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_float($value) || is_int($value)) {
        return (string) $value;
    }
    return print_r($value, true);
}

/** تنسيق حجم الملف بصيغة مقروءة. */
function sb_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' بايت';
    }
    $units = ['كيلوبايت', 'ميجابايت', 'جيجابايت', 'تيرابايت'];
    $value = (float) $bytes;
    $i = -1;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$i];
}

/** تنسيق رقم بفواصل الآلاف. */
function sb_num($n): string
{
    if ($n === null || $n === '') {
        return '—';
    }
    return number_format((float) $n, 0, '.', ',');
}

/** تاريخ ووقت مختصر. */
function sb_date(?int $ts): string
{
    return $ts ? date('Y-m-d H:i', $ts) : '—';
}

/** اقتطاع نص طويل بعدد أحرف آمن مع ترميز UTF-8. */
function sb_truncate(string $text, int $max): array
{
    $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($len <= $max) {
        return [$text, false];
    }
    $cut = function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
    return [$cut, true];
}

/** هل القيمة ثنائية (BLOB) وليست نصاً؟ */
function sb_is_binary($value): bool
{
    if (!is_string($value) || $value === '') {
        return false;
    }
    if (strpos($value, "\0") !== false) {
        return true;
    }
    // نص UTF-8 غير صالح ⇒ غالباً بيانات ثنائية
    if (function_exists('mb_check_encoding')) {
        return !mb_check_encoding($value, 'UTF-8');
    }
    return preg_match('//u', $value) === 0;
}

/** توقيع HMAC مختصر لحماية الروابط. */
function sb_sign(string $data): string
{
    return substr(hash_hmac('sha256', $data, SB_HMAC_SALT), 0, 24);
}

/** إنشاء معرّف (Token) آمن لمسار قاعدة بيانات. */
function sb_token(string $path): string
{
    return rtrim(strtr(base64_encode($path), '+/', '-_'), '=') . '.' . sb_sign($path);
}

/** استخراج المسار من المعرّف بعد التحقق من توقيعه. */
function sb_token_path(?string $token): ?string
{
    if (!is_string($token) || $token === '' || substr_count($token, '.') < 1) {
        return null;
    }
    $pos = strrpos($token, '.');
    $payload = substr($token, 0, $pos);
    $sig = substr($token, $pos + 1);
    $path = base64_decode(strtr($payload, '-_', '+/'), true);
    if ($path === false || $path === '') {
        return null;
    }
    if (!hash_equals(sb_sign($path), $sig)) {
        return null;
    }
    return $path;
}

/** المسارات الحقيقية المعتمدة لمجلدات البحث. */
function sb_real_roots(): array
{
    static $roots = null;
    if ($roots !== null) {
        return $roots;
    }
    $roots = [];
    foreach ((array) SB_SCAN_ROOTS as $root) {
        $real = realpath((string) $root);
        if ($real !== false && is_dir($real)) {
            $roots[] = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        } else {
            $roots[] = ['missing' => (string) $root];
        }
    }
    return $roots;
}

/** هل المسار يقع داخل أحد مجلدات البحث المعتمدة؟ */
function sb_within_roots(string $path): bool
{
    $real = realpath($path);
    if ($real === false) {
        return false;
    }
    $real = str_replace('\\', '/', $real);
    foreach (sb_real_roots() as $root) {
        if (!is_string($root)) {
            continue;
        }
        $root = str_replace('\\', '/', rtrim($root, '/')) . '/';
        if (strncmp($real, $root, strlen($root)) === 0) {
            return true;
        }
        // حالة أن يكون المجلد الجذر نفسه هو الملف المطلوب
        if ($real === rtrim($root, '/')) {
            return true;
        }
    }
    return false;
}

/** مسار نسبي للعرض. */
function sb_relative(string $path): string
{
    $real = realpath($path);
    $target = $real === false ? $path : $real;
    $target = str_replace('\\', '/', $target);
    $best = null;
    foreach (sb_real_roots() as $root) {
        if (!is_string($root)) {
            continue;
        }
        $root = str_replace('\\', '/', rtrim($root, '/')) . '/';
        if (strncmp($target, $root, strlen($root)) === 0) {
            $rel = substr($target, strlen($root));
            if ($best === null || strlen($rel) < strlen($best)) {
                $best = $rel;
            }
        }
    }
    return $best !== null ? $best : $target;
}

/* ===========================================================================
 * 3) البحث عن قواعد البيانات في المجلدات الفرعية
 * =========================================================================== */

/** حالة البحث (تُمرّر بالمرجع لجمع الإحصاءات والأخطاء). */
function sb__new_state(): array
{
    return [
        'files' => 0,
        'dirs' => 0,
        'probes' => 0,
        'dbs' => [],
        'errors' => [],
        'visited' => [],
    ];
}

/** فحص ملف واحد وإضافته للقائمة إن كان قاعدة بيانات SQLite. */
function sb__consider(string $path, array &$state): void
{
    if (count($state['dbs']) >= SB_SCAN_MAX_DBS) {
        return;
    }
    $base = strtolower(basename($path));

    // ملفات مساعدة لقواعد WAL لا تُفتح وحدها
    foreach (['-wal', '-shm', '-journal'] as $suffix) {
        if (substr($base, -strlen($suffix)) === $suffix) {
            return;
        }
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $byExt = in_array($ext, SB_DB_EXTENSIONS, true);
    $byMaybe = SB_SCAN_BY_CONTENT && in_array($ext, SB_MAYBE_EXTENSIONS, true);
    if (!$byExt && !$byMaybe) {
        return;
    }

    $size = @filesize($path);
    if ($size === false || $size === 0) {
        return;
    }
    if ($byMaybe && $size > SB_SCAN_MAX_PROBE_BYTES) {
        return;
    }
    // حدّ لعدد الملفات التي تُفحص ترويستها (لحماية السيرفر)
    if (!$byExt) {
        if ($state['probes'] >= SB_SCAN_MAX_FILES) {
            return;
        }
        $state['probes']++;
    }

    $head = sb_head_bytes($path, 16);
    $isSqlite = substr($head, 0, 15) === 'SQLite format 3';

    if (!$isSqlite) {
        // امتداد يوحي بأنه قاعدة بيانات لكنه ليس كذلك ⇒ نسجّله كتنبيه
        if ($byExt) {
            $state['errors'][] = ['path' => $path, 'error' => 'الملف ليس قاعدة بيانات SQLite (ترويسة غير مطابقة)'];
        }
        return;
    }

    $real = realpath($path);
    $key = $real === false ? $path : $real;
    if (isset($state['visited'][$key])) {
        return;
    }
    $state['visited'][$key] = true;

    $header = sb_db_header($key);
    $state['dbs'][] = [
        'path' => $key,
        'rel' => sb_relative($key),
        'id' => sb_token($key),
        'size' => $size,
        'mtime' => @filemtime($key) ?: 0,
        'wal' => $header['wal'] || @file_exists($key . '-wal') || @file_exists($key . '-shm'),
        'wal_files' => @file_exists($key . '-wal') || @file_exists($key . '-shm'),
        'page_size' => $header['page_size'],
        'status' => 'ok',
        'objects' => null,
        'error' => null,
        'snapshot' => false,
    ];
}

/** اجتياز مجلد واحد بشكل تكراري (بدون استثناءات، وبحدود واضحة). */
function sb__walk(string $dir, int $depth, array &$state): void
{
    if ($depth > SB_SCAN_MAX_DEPTH) {
        return;
    }
    if ($state['dirs'] >= SB_SCAN_MAX_DIRS || $state['files'] >= SB_SCAN_MAX_FILES) {
        return;
    }
    if (count($state['dbs']) >= SB_SCAN_MAX_DBS) {
        return;
    }

    $real = realpath($dir);
    if ($real === false) {
        $state['errors'][] = ['path' => $dir, 'error' => 'تعذّر الوصول إلى المجلد'];
        return;
    }
    if (isset($state['visited_dirs'][$real])) {
        return; // منع حلقات الروابط الرمزية
    }
    $state['visited_dirs'][$real] = true;
    $state['dirs']++;

    $items = @scandir($real);
    if ($items === false) {
        $state['errors'][] = ['path' => $real, 'error' => 'لا توجد صلاحية قراءة للمجلد'];
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if ($state['files'] >= SB_SCAN_MAX_FILES || count($state['dbs']) >= SB_SCAN_MAX_DBS) {
            break;
        }
        $path = $real . DIRECTORY_SEPARATOR . $item;

        if (@is_link($path) && !SB_FOLLOW_SYMLINKS) {
            continue;
        }

        if (@is_dir($path)) {
            if (in_array(strtolower($item), SB_SKIP_DIRS, true)) {
                continue;
            }
            sb__walk($path, $depth + 1, $state);
            continue;
        }

        if (@is_file($path)) {
            $state['files']++;
            sb__consider($path, $state);
        }
    }
}

/** فتح كل قاعدة مكتشفة (إن كان عددها صغيراً) لمعرفة عدد الجداول وحالتها. */
function sb__probe(array &$dbs): void
{
    if (count($dbs) === 0 || count($dbs) > SB_PROBE_LIMIT) {
        return;
    }
    if (!sb_sqlite_available()) {
        foreach ($dbs as $i => $db) {
            $dbs[$i]['status'] = 'error';
            $dbs[$i]['error'] = 'امتداد pdo_sqlite غير متوفر';
        }
        return;
    }
    $start = microtime(true);
    foreach ($dbs as $i => $db) {
        if (microtime(true) - $start > SB_COUNTS_TIME_BUDGET + 2) {
            break;
        }
        try {
            [$pdo] = sb_connect($db['path']);
            $row = $pdo->query(
                "SELECT
                    SUM(CASE WHEN type='table' THEN 1 ELSE 0 END) AS t,
                    SUM(CASE WHEN type='view' THEN 1 ELSE 0 END) AS v
                 FROM sqlite_master WHERE name NOT LIKE 'sqlite\\_%' ESCAPE '\\'"
            )->fetch(PDO::FETCH_ASSOC);
            $dbs[$i]['objects'] = (int) ($row['t'] ?? 0) + (int) ($row['v'] ?? 0);
            $dbs[$i]['tables'] = (int) ($row['t'] ?? 0);
            $dbs[$i]['views'] = (int) ($row['v'] ?? 0);
            $dbs[$i]['snapshot'] = sb_snapshot_mode();
        } catch (Throwable $e) {
            $dbs[$i]['status'] = 'error';
            $dbs[$i]['error'] = sb_db_error_label($e);
        }
        sb_disconnect();
    }
}

/** تنفيذ البحث (مع تخزين مؤقت اختياري لتسريع التنقّل). */
function sb_scan(bool $force = false): array
{
    $roots = sb_real_roots();
    $cacheKey = 'sb_scan_' . substr(hash('sha256', json_encode($roots) . '|' . SB_SCAN_MAX_DEPTH), 0, 16) . '.json';
    $cacheFile = null;
    $tmp = function_exists('sys_get_temp_dir') ? @sys_get_temp_dir() : null;
    if ($tmp && @is_writable($tmp)) {
        $cacheFile = rtrim($tmp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cacheKey;
    }

    if (!$force && $cacheFile && @is_file($cacheFile)) {
        $age = time() - (int) @filemtime($cacheFile);
        if ($age >= 0 && $age < SB_SCAN_CACHE_TTL) {
            $raw = @file_get_contents($cacheFile);
            if (is_string($raw) && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data) && isset($data['dbs'])) {
                    $data['cached'] = true;
                    $data['age'] = $age;
                    return $data;
                }
            }
        }
    }

    $t0 = microtime(true);
    $state = sb__new_state();
    $rootErrors = [];

    foreach ($roots as $root) {
        if (!is_string($root)) {
            $rootErrors[] = ['path' => $root['missing'] ?? '؟', 'error' => 'مجلد البحث غير موجود'];
            continue;
        }
        sb__walk(rtrim($root, DIRECTORY_SEPARATOR), 0, $state);
    }

    $dbs = $state['dbs'];
    usort($dbs, static function ($a, $b) {
        return strcmp($a['rel'], $b['rel']);
    });

    $result = [
        'dbs' => $dbs,
        'errors' => array_merge($rootErrors, $state['errors']),
        'stats' => [
            'files' => $state['files'],
            'dirs' => $state['dirs'],
            'probes' => $state['probes'],
            'roots' => $roots,
        ],
        'cached' => false,
        'age' => 0,
        'time' => round(microtime(true) - $t0, 3),
        'truncated' => count($dbs) >= SB_SCAN_MAX_DBS
            || $state['files'] >= SB_SCAN_MAX_FILES
            || $state['dirs'] >= SB_SCAN_MAX_DIRS,
    ];

    sb__probe($result['dbs']);

    if ($cacheFile) {
        $payload = $result;
        $payload['time'] = round(microtime(true) - $t0, 3);
        @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    return $result;
}

/* ===========================================================================
 * 4) الاتصال بالقاعدة — للقراءة فقط
 * =========================================================================== */

/** هل الاتصال الحالي يعمل بوضع "لقطة ثابتة" (immutable)؟ */
function sb_snapshot_mode(): bool
{
    return !empty($GLOBALS['__sb_snapshot']);
}

function sb__set_snapshot_flag(bool $value): void
{
    $GLOBALS['__sb_snapshot'] = $value;
}

/**
 * قراءة ترويسة ملف SQLite مباشرة (100 بايت الأولى).
 * تفيد في معرفة وضع WAL وحجم الصفحة حتى لو تعذّر فتح القاعدة.
 */
function sb_db_header(string $path): array
{
    $head = sb_head_bytes($path, 100);
    $info = [
        'valid' => substr($head, 0, 15) === 'SQLite format 3',
        'write_version' => 0,
        'read_version' => 0,
        'page_size' => 0,
        'wal' => false,
    ];
    if (strlen($head) >= 20) {
        $pageSize = (ord($head[16]) << 8) | ord($head[17]);
        $info['page_size'] = $pageSize === 1 ? 65536 : $pageSize;
        $info['write_version'] = ord($head[18]);
        $info['read_version'] = ord($head[19]);
        // القيمة 2 تعني أن الملف يستخدم WAL
        $info['wal'] = ($info['write_version'] === 2 || $info['read_version'] === 2);
    }
    return $info;
}

/** ترميز المسار ليُستخدم داخل URI الخاص بـ SQLite. */
function sb_uri_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    return str_replace('%2F', '/', rawurlencode($path));
}

/** ترجمة أخطاء SQLite إلى رسالة عربية مفهومة. */
function sb_db_error_label(Throwable $e): string
{
    $msg = $e->getMessage();
    $map = [
        'file is not a database' => 'الملف ليس قاعدة بيانات SQLite صالحة',
        'database disk image is malformed' => 'القاعدة تالفة (ملف غير مكتمل أو متضرر)',
        'unable to open database file' => 'تعذّر فتح الملف (صلاحيات، أو وضع WAL يحتاج صلاحية كتابة على المجلد)',
        'attempt to write a readonly database' => 'القاعدة مفتوحة للقراءة فقط ولا يمكن تنفيذ هذا الأمر',
        'disk I/O error' => 'خطأ إدخال/إخراج (غالباً صلاحيات على المجلد أو وضع WAL)',
        'database is locked' => 'القاعدة مقفلة من عملية أخرى',
        'no such table' => 'الجدول غير موجود',
        'no such column' => 'العمود غير موجود',
        'out of memory' => 'نفدت الذاكرة أثناء تنفيذ الاستعلام',
    ];
    foreach ($map as $needle => $arabic) {
        if (stripos($msg, $needle) !== false) {
            return $arabic;
        }
    }
    return $msg;
}

/** إغلاق الاتصال الحالي إن وُجد. */
function sb_disconnect(): void
{
    unset($GLOBALS['__sb_pdo']);
}

/**
 * فتح قاعدة بيانات للقراءة فقط عبر عدة محاولات آمنة.
 *
 * @return array{0: PDO, 1: string} الاتصال ووصف طريقة الفتح
 */
function sb_connect(string $path): array
{
    if (isset($GLOBALS['__sb_pdo'], $GLOBALS['__sb_pdo_path']) && $GLOBALS['__sb_pdo_path'] === $path) {
        return [$GLOBALS['__sb_pdo'], $GLOBALS['__sb_pdo_mode'] ?? 'read-only'];
    }

    // الترتيب مقصود: نرفض أي مسار خارج مجلدات البحث قبل حتى فحص محتواه
    if (!sb_within_roots($path)) {
        throw new RuntimeException('الملف خارج مجلدات البحث المسموح بها (مرفوض لأسباب أمنية).');
    }
    if (!@is_file($path)) {
        throw new RuntimeException('الملف غير موجود: ' . $path);
    }
    if (!@is_readable($path)) {
        throw new RuntimeException('لا توجد صلاحية قراءة للملف: ' . $path);
    }
    $header = sb_db_header($path);
    if (!$header['valid']) {
        throw new RuntimeException('الملف ليس قاعدة بيانات SQLite (ترويسة غير مطابقة).');
    }

    $walFiles = @file_exists($path . '-wal') || @file_exists($path . '-shm');
    $headerWal = !empty($header['wal']);
    $roFlags = defined('PDO::SQLITE_OPEN_READONLY') ? PDO::SQLITE_OPEN_READONLY : null;

    $attempts = [];

    // 1) أقوى وضع: قراءة فقط على مستوى SQLite وعلى مستوى PDO معاً.
    //    هذا المزيج يمنع حتى ATTACH DATABASE الذي قد يُنشئ ملفات جديدة.
    if ($roFlags !== null) {
        $attempts[] = [
            'dsn' => 'sqlite:file:' . sb_uri_path($path) . '?mode=ro',
            'flags' => $roFlags,
            'label' => 'قراءة فقط (URI mode=ro + SQLITE_OPEN_READONLY)',
            'snapshot' => false,
        ];
    }

    // 2) قراءة فقط عبر URI
    $attempts[] = [
        'dsn' => 'sqlite:file:' . sb_uri_path($path) . '?mode=ro',
        'flags' => null,
        'label' => 'قراءة فقط (URI mode=ro)',
        'snapshot' => false,
    ];

    // 3) قراءة فقط عبر رايات PDO
    if ($roFlags !== null) {
        $attempts[] = [
            'dsn' => 'sqlite:' . $path,
            'flags' => $roFlags,
            'label' => 'قراءة فقط (SQLITE_OPEN_READONLY)',
            'snapshot' => false,
        ];
    }

    // 4) وضع "لقطة ثابتة": يُستخدم فقط عندما لا توجد ملفات WAL خارجية،
    //    لأنه يتجاهل ملف -wal وقد يعيد بيانات قديمة إن وُجد.
    //    يظهر للمستخدم تنبيه واضح عند استخدام هذا الوضع.
    if (!$walFiles) {
        $attempts[] = [
            'dsn' => 'sqlite:file:' . sb_uri_path($path) . '?immutable=1',
            'flags' => $roFlags,
            'label' => 'لقطة ثابتة (immutable) — للقراءة فقط',
            'snapshot' => true,
        ];
    }

    // 5) (اختياري — معطّل افتراضياً) فتح عادي مع الاكتفاء بـ query_only
    if (SB_ALLOW_NONRO_FALLBACK) {
        $attempts[] = [
            'dsn' => 'sqlite:' . $path,
            'flags' => null,
            'label' => 'وضع احتياطي (query_only فقط) — أقل أماناً',
            'snapshot' => false,
        ];
    }

    $lastError = null;
    foreach ($attempts as $attempt) {
        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
            ];
            if ($attempt['flags'] !== null) {
                $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = $attempt['flags'];
            }
            $pdo = new PDO($attempt['dsn'], null, null, $options);

            // طبقة حماية إضافية على مستوى الاتصال نفسه
            $pdo->exec('PRAGMA query_only = ON');
            // التأكد من أن الاتصال لم يغيّر وضع اليوميات (نقرأ فقط)
            $pdo->query('PRAGMA journal_mode')->fetchColumn();

            $GLOBALS['__sb_pdo'] = $pdo;
            $GLOBALS['__sb_pdo_path'] = $path;
            $GLOBALS['__sb_pdo_mode'] = $attempt['label'];
            sb__set_snapshot_flag($attempt['snapshot']);

            return [$pdo, $attempt['label']];
        } catch (Throwable $e) {
            $lastError = $e;
            continue;
        }
    }

    $hint = '';
    if ($headerWal && !$walFiles) {
        $hint = ' — القاعدة مضبوطة على وضع WAL ولا يوجد ملف -wal/-shm بجانبها، وSQLite يحتاج إنشاء ملف -shm للقراءة.'
            . ' الحل: اجعل المجلد قابلاً للكتابة مؤقتاً، أو شغّل على القاعدة أمر wal_checkpoint(TRUNCATE) من تطبيقك،'
            . ' أو حوّل وضع اليوميات إلى DELETE.';
    } elseif ($headerWal && $walFiles) {
        $hint = ' — القاعدة بوضع WAL وملفات -wal/-shm موجودة؛ تأكد من صلاحية القراءة على المجلد كله وليس الملف وحده.';
    }
    if (!@is_writable(dirname($path)) && $hint === '') {
        $hint = ' — المجلد غير قابل للكتابة، وقد يحتاج SQLite لذلك في بعض أوضاع اليوميات.';
    }

    throw new RuntimeException(sb_db_error_label($lastError ?: new RuntimeException('تعذّر فتح القاعدة')) . $hint);
}

/* ===========================================================================
 * 5) قراءة المخطط (Schema) والبيانات الوصفية
 * =========================================================================== */

/** اقتباس معرّف (اسم جدول/عمود) بشكل آمن للاستخدام داخل SQL. */
function sb_quote_id(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

/** كل الكائنات في القاعدة: جداول، عروض، فهارس، مشغّلات. */
function sb_objects(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT type, name, tbl_name, rootpage, sql
         FROM sqlite_master
         ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'view' THEN 1 WHEN 'index' THEN 2 ELSE 3 END, name"
    )->fetchAll(PDO::FETCH_ASSOC);

    // اكتشاف الجداول الافتراضية (FTS وغيرها) وجداول الظل التابعة لها
    $virtual = [];
    foreach ($rows as $row) {
        if ($row['type'] === 'table' && is_string($row['sql'])
            && preg_match('/^\s*CREATE\s+VIRTUAL\s+TABLE/i', $row['sql'])) {
            $virtual[$row['name']] = $row['sql'];
        }
    }
    $shadow = [];
    $suffixes = ['data', 'idx', 'content', 'docsize', 'config', 'segdir', 'segments', 'stat', 'incr', 'deleted'];
    foreach (array_keys($virtual) as $base) {
        foreach ($suffixes as $suffix) {
            $shadow[$base . '_' . $suffix] = $base;
        }
    }

    $tables = $views = $indexes = $triggers = [];
    foreach ($rows as $row) {
        $name = (string) $row['name'];
        $item = [
            'name' => $name,
            'type' => $row['type'],
            'sql' => $row['sql'] === null ? null : (string) $row['sql'],
            'internal' => strncmp($name, 'sqlite_', 7) === 0,
            'virtual' => isset($virtual[$name]),
            'shadow_of' => $shadow[$name] ?? null,
            'tbl_name' => $row['tbl_name'] === null ? null : (string) $row['tbl_name'],
        ];
        switch ($row['type']) {
            case 'table':
                $tables[] = $item;
                break;
            case 'view':
                $views[] = $item;
                break;
            case 'index':
                $indexes[] = $item;
                break;
            case 'trigger':
                $triggers[] = $item;
                break;
        }
    }

    return compact('tables', 'views', 'indexes', 'triggers', 'virtual', 'shadow');
}

/** أعمدة جدول/عرض مع أنواعها وقيودها. */
function sb_columns(PDO $pdo, string $table): array
{
    foreach (['table_xinfo', 'table_info'] as $pragma) {
        try {
            $stmt = $pdo->query('PRAGMA ' . $pragma . '(' . sb_quote_id($table) . ')');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows !== false && count($rows) > 0) {
                $cols = [];
                foreach ($rows as $row) {
                    // table_xinfo يُظهر الأعمدة المخفية (hidden>0) في الجداول الافتراضية
                    if (isset($row['hidden']) && (int) $row['hidden'] > 0) {
                        continue;
                    }
                    $cols[] = [
                        'cid' => (int) ($row['cid'] ?? 0),
                        'name' => (string) ($row['name'] ?? ''),
                        'type' => (string) ($row['type'] ?? ''),
                        'notnull' => (int) ($row['notnull'] ?? 0),
                        'default' => $row['dflt_value'] ?? null,
                        'pk' => (int) ($row['pk'] ?? 0),
                    ];
                }
                if (count($cols) > 0) {
                    return $cols;
                }
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    return [];
}

/** هل الجدول يحتوي على rowid؟ (جداول WITHOUT ROWID لا تحتويه). */
function sb_has_rowid(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . '|' . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $pdo->query('SELECT rowid FROM ' . sb_quote_id($table) . ' LIMIT 1')->fetchColumn();
        $cache[$key] = true;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/** هل الكائن (جدول/عرض) موجود فعلاً في هذه القاعدة؟ */
function sb_object_exists(PDO $pdo, string $name, array $types = ['table', 'view']): bool
{
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $stmt = $pdo->prepare(
        'SELECT 1 FROM sqlite_master WHERE name = ? AND type IN (' . $placeholders . ') LIMIT 1'
    );
    $stmt->execute(array_merge([$name], $types));
    return (bool) $stmt->fetchColumn();
}

/** عدد صفوف جدول/عرض (مع مراعاة شرط البحث). */
function sb_count_rows(PDO $pdo, string $table, string $where = '', array $params = []): ?int
{
    try {
        $sql = 'SELECT COUNT(*) FROM ' . sb_quote_id($table) . ($where !== '' ? ' WHERE ' . $where : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    } catch (Throwable $e) {
        return null;
    }
}

/** فهارس الجدول. */
function sb_indexes(PDO $pdo, string $table): array
{
    $out = [];
    try {
        $list = $pdo->query('PRAGMA index_list(' . sb_quote_id($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $out;
    }
    foreach ($list as $idx) {
        $name = (string) ($idx['name'] ?? '');
        $cols = [];
        try {
            $info = $pdo->query('PRAGMA index_info(' . sb_quote_id($name) . ')')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($info as $c) {
                $cols[] = (string) ($c['name'] ?? ('#' . $c['seqno']));
            }
        } catch (Throwable $e) {
            // فهرس تعبيري — تُقرأ أعمدته من تعريفه
        }
        $out[] = [
            'name' => $name,
            'unique' => !empty($idx['unique']),
            'partial' => !empty($idx['partial']),
            'origin' => (string) ($idx['origin'] ?? ''),
            'columns' => $cols,
        ];
    }
    return $out;
}

/** المفاتيح الأجنبية للجدول. */
function sb_foreign_keys(PDO $pdo, string $table): array
{
    try {
        $rows = $pdo->query('PRAGMA foreign_key_list(' . sb_quote_id($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if (!isset($out[$id])) {
            $out[$id] = [
                'table' => (string) ($row['table'] ?? ''),
                'on_update' => (string) ($row['on_update'] ?? ''),
                'on_delete' => (string) ($row['on_delete'] ?? ''),
                'columns' => [],
                'references' => [],
            ];
        }
        $out[$id]['columns'][] = (string) ($row['from'] ?? '');
        $out[$id]['references'][] = (string) ($row['to'] ?? '');
    }
    return array_values($out);
}

/** معلومات عامة عن القاعدة (كلها أوامر PRAGMA للقراءة فقط). */
function sb_db_info(PDO $pdo, string $path): array
{
    $info = [];
    $simple = [
        'page_size' => 'حجم الصفحة',
        'page_count' => 'عدد الصفحات',
        'freelist_count' => 'الصفحات الحرة',
        'encoding' => 'ترميز النص',
        'journal_mode' => 'وضع اليوميات',
        'auto_vacuum' => 'الضغط التلقائي',
        'user_version' => 'إصدار المستخدم',
        'schema_version' => 'إصدار المخطط',
        'application_id' => 'معرّف التطبيق',
        'data_version' => 'إصدار البيانات',
        'locking_mode' => 'وضع القفل',
        'synchronous' => 'المزامنة',
        'cache_size' => 'حجم الذاكرة المخبئية',
        'temp_store' => 'تخزين المؤقت',
        'foreign_keys' => 'فحص المفاتيح الأجنبية',
    ];
    foreach ($simple as $pragma => $label) {
        try {
            $value = $pdo->query('PRAGMA ' . $pragma)->fetchColumn();
            $info[$pragma] = ['label' => $label, 'value' => $value === false ? '—' : $value];
        } catch (Throwable $e) {
            $info[$pragma] = ['label' => $label, 'value' => '—'];
        }
    }
    $info['__file'] = [
        'label' => 'حجم الملف',
        'value' => sb_size((int) @filesize($path)),
    ];
    $info['__mtime'] = [
        'label' => 'آخر تعديل للملف',
        'value' => sb_date(@filemtime($path) ?: null),
    ];
    $info['__path'] = ['label' => 'المسار الكامل', 'value' => $path];
    try {
        $info['__sqlite'] = ['label' => 'إصدار SQLite', 'value' => $pdo->query('SELECT sqlite_version()')->fetchColumn()];
    } catch (Throwable $e) {
        $info['__sqlite'] = ['label' => 'إصدار SQLite', 'value' => '—'];
    }
    return $info;
}

/* ===========================================================================
 * 6) بناء استعلامات آمنة (لا تدخل للمستخدم في أسماء الجداول/الأعمدة)
 * =========================================================================== */

/** تهيئة مصفوفة شروط البحث داخل الجدول. */
function sb_escape_like(string $term): string
{
    return addcslashes($term, '\\%_');
}

/**
 * بناء استعلام SELECT آمن لجدول معيّن.
 *
 * @param array  $cols      قائمة الأعمدة (من sb_columns)
 * @param string $orderBy   عمود الترتيب (يُتحقق من وجوده)
 * @param string $dir       ASC أو DESC
 * @param string $search    نص البحث (يُمرّر كمعامل مرتبط)
 */
function sb_build_select(string $table, array $cols, bool $hasRowid, ?string $orderBy, string $dir, string $search, int $limit, int $offset): array
{
    $names = [];
    foreach ($cols as $c) {
        $names[] = $c['name'];
    }
    $useRowid = $hasRowid && !in_array('sb__rid', $names, true);
    $select = [];
    if ($useRowid) {
        $select[] = 'rowid AS sb__rid';
    }
    if (count($names) > 0) {
        foreach ($names as $n) {
            $select[] = sb_quote_id($n);
        }
    } else {
        $select[] = '*';
    }
    $selectSql = implode(', ', $select);

    $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

    // التحقق من عمود الترتيب: يجب أن يكون موجوداً فعلاً
    $orderSql = '';
    if ($orderBy !== null && $orderBy !== '') {
        if ($orderBy === 'sb__rid' && $useRowid) {
            $orderSql = 'ORDER BY rowid ' . $dir;
        } elseif (in_array($orderBy, $names, true)) {
            $orderSql = 'ORDER BY ' . sb_quote_id($orderBy) . ' ' . $dir;
        }
    }
    if ($orderSql === '') {
        if ($useRowid) {
            $orderSql = 'ORDER BY rowid ASC';
        } elseif (count($names) > 0) {
            $orderSql = 'ORDER BY ' . sb_quote_id($names[0]) . ' ASC';
        }
    }

    $where = '';
    $params = [];
    if ($search !== '' && count($names) > 0) {
        $like = '%' . sb_escape_like($search) . '%';
        $parts = [];
        foreach ($names as $n) {
            $parts[] = 'CAST(' . sb_quote_id($n) . ' AS TEXT) LIKE ? ESCAPE \'\\\'';
        }
        $where = '(' . implode(' OR ', $parts) . ')';
        $params = array_fill(0, count($names), $like);
    }

    $from = sb_quote_id($table);
    $base = 'SELECT ' . $selectSql . ' FROM ' . $from . ($where !== '' ? ' WHERE ' . $where : '');

    return [
        'sql' => $base . ' ' . $orderSql . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
        'count_sql' => 'SELECT COUNT(*) FROM ' . $from . ($where !== '' ? ' WHERE ' . $where : ''),
        'params' => $params,
        'order' => $orderSql,
        'use_rowid' => $useRowid,
        'columns' => $names,
    ];
}

/** إزالة النصوص المقتبسة من استعلام للتحقق من كلماته المفتاحية فقط. */
function sb_strip_literals(string $sql): string
{
    $out = '';
    $len = strlen($sql);
    $i = 0;
    while ($i < $len) {
        $ch = $sql[$i];
        if ($ch === "'" || $ch === '"' || $ch === '`' || $ch === '[') {
            $close = $ch === '[' ? ']' : $ch;
            $i++;
            while ($i < $len) {
                if ($sql[$i] === $close) {
                    if ($close !== ']' && isset($sql[$i + 1]) && $sql[$i + 1] === $close) {
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $i++;
            }
            $out .= ' "" ';
            continue;
        }
        $out .= $ch;
        $i++;
    }
    return $out;
}

/**
 * فاحص الاستعلامات: يسمح بالقراءة فقط.
 * @return string|null رسالة الخطأ، أو null إذا كان الاستعلام مقبولاً
 */
function sb_check_readonly_sql(string $sql): ?string
{
    // إزالة التعليقات
    $clean = preg_replace('!/\*.*?\*/!s', ' ', $sql);
    $clean = preg_replace('/--[\r\n].*/', ' ', (string) $clean);
    $clean = preg_replace('/--[^\r\n]*/', ' ', (string) $clean);
    $clean = trim((string) $clean);
    if ($clean === '') {
        return 'الاستعلام فارغ.';
    }
    $clean = rtrim($clean, "; \t\r\n");
    if (strpos($clean, ';') !== false) {
        return 'يُسمح بجملة واحدة فقط (بدون فواصل منقوطة في المنتصف).';
    }

    $stripped = sb_strip_literals($clean);

    // حظر صارم: ATTACH/DETACH قد تُنشئ ملفات قواعد بيانات جديدة على القرص،
    // لذلك تُرفض حتى لو وردت داخل نص مقتبس (احتياط إضافي فوق حماية الاتصال).
    if (preg_match('/\b(attach|detach)\b/i', $clean)) {
        return 'مرفوض: أوامر ATTACH / DETACH ممنوعة تماماً، لأنها قد تفتح أو تُنشئ ملفات قواعد بيانات أخرى.';
    }

    if (!preg_match('/^\s*(select|with|pragma|explain)\b/i', $stripped, $m)) {
        return 'يُسمح فقط بالاستعلامات التي تبدأ بـ SELECT أو WITH أو PRAGMA أو EXPLAIN.';
    }
    $keyword = strtolower($m[1]);

    $forbidden = [
        'insert', 'update', 'delete', 'drop', 'create', 'alter', 'attach', 'detach',
        'vacuum', 'reindex', 'analyze', 'truncate', 'begin', 'commit', 'rollback',
        'savepoint', 'release', 'load_extension', 'restore', 'backup',
    ];
    if (preg_match('/\b(' . implode('|', $forbidden) . ')\b/i', $stripped, $hit)) {
        return 'مرفوض: الاستعلام يحتوي الكلمة "' . strtoupper($hit[1]) . '". الأداة للقراءة فقط.'
            . ' إذا كانت اسماً لعمود أو جدول فضعها بين علامتي اقتباس مزدوجتين مثل "delete".';
    }

    if ($keyword === 'pragma') {
        if (strpos($stripped, '=') !== false) {
            return 'مرفوض: لا يُسمح بتغيير إعدادات PRAGMA (يُسمح بقراءتها فقط).';
        }
        if (!preg_match('/^\s*pragma\s+([a-z_0-9]+)/i', $stripped, $pm)) {
            return 'صيغة PRAGMA غير مفهومة.';
        }
        $name = strtolower($pm[1]);
        $blocked = ['wal_checkpoint', 'incremental_vacuum', 'optimize', 'shrink_memory', 'cell_size_check'];
        if (in_array($name, $blocked, true)) {
            return 'مرفوض: الأمر PRAGMA ' . $name . ' قد يعدّل البيانات.';
        }
    }

    return null;
}

/* ===========================================================================
 * 7) الحماية بكلمة مرور (اختيارية)
 * =========================================================================== */

function sb_auth_value(): string
{
    return hash_hmac('sha256', 'sb-auth-v1', SB_HMAC_SALT . '|' . SB_ACCESS_PASSWORD);
}

function sb_auth_enabled(): bool
{
    return SB_ACCESS_PASSWORD !== '';
}

function sb_is_authenticated(): bool
{
    if (!sb_auth_enabled()) {
        return true;
    }
    $cookie = $_COOKIE['sb_auth'] ?? '';
    return is_string($cookie) && $cookie !== '' && hash_equals(sb_auth_value(), $cookie);
}

function sb_send_header(string $header): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    @header($header);
}

function sb_set_cookie(string $name, string $value, int $ttl): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $options = [
        'expires' => $ttl > 0 ? time() + $ttl : time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        @setcookie($name, $value, $options);
    } else {
        @setcookie($name, $value, $options['expires'], $options['path']);
    }
}

/* ===========================================================================
 * 8) أدوات العرض
 * =========================================================================== */

/** اسم السكربت الحالي لبناء الروابط. */
function sb_script(): string
{
    $name = $_SERVER['SCRIPT_NAME'] ?? '';
    if (!is_string($name) || $name === '') {
        $name = basename(__FILE__);
    }
    return $name;
}

/** بناء رابط مع دمج المعاملات (مع الحفاظ على السياق الحالي). */
function sb_url(array $params = [], array $drop = []): string
{
    $query = $_GET;
    if (!is_array($query)) {
        $query = [];
    }
    foreach ($drop as $key) {
        unset($query[$key]);
    }
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    return $qs === '' ? sb_script() : sb_script() . '?' . $qs;
}

/** قيمة خانة (Parameter) من GET كنص. */
function sb_param(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    if (is_array($value)) {
        return $default;
    }
    return is_string($value) ? $value : (string) $value;
}

function sb_param_int(string $key, int $default): int
{
    $value = $_GET[$key] ?? null;
    if (is_array($value) || $value === null || $value === '') {
        return $default;
    }
    return (int) $value;
}

/** شارة صغيرة. */
function sb_badge(string $text, string $kind = 'muted'): string
{
    return '<span class="badge b-' . sb_e($kind) . '">' . sb_e($text) . '</span>';
}

/** عرض قيمة خلية بشكل آمن مع التعامل مع NULL و BLOB والنصوص الطويلة. */
function sb_cell_html($value, bool $full = false): string
{
    if ($value === null) {
        return '<span class="v-null">NULL</span>';
    }
    if (is_int($value) || is_float($value)) {
        return '<span class="v-num">' . sb_e((string) $value) . '</span>';
    }
    $text = sb_text($value);

    if (sb_is_binary($text)) {
        $len = strlen($text);
        $preview = strtoupper(bin2hex(substr($text, 0, $full ? 512 : 16)));
        $hex = chunk_split($preview, 2, ' ');
        $html = '<span class="v-blob">BLOB · ' . sb_e(sb_size($len)) . '</span>'
            . '<code class="hex">' . sb_e(trim($hex)) . ($len > ($full ? 512 : 16) ? ' …' : '') . '</code>';
        return $html;
    }

    if ($text === '') {
        return '<span class="v-empty">(فارغ)</span>';
    }

    $isNumeric = is_numeric($text);
    [$shown, $truncated] = sb_truncate($text, $full ? 4000 : SB_CELL_MAX_CHARS);
    $cls = 'v-text' . ($isNumeric ? ' v-num' : '');
    $html = '<span class="' . $cls . '" dir="auto">' . nl2br(sb_e($shown), false) . '</span>';
    if ($truncated) {
        $html .= ' <button type="button" class="more" data-more>عرض المزيد</button>';
        $html .= '<span class="fulltext" hidden dir="auto">' . nl2br(sb_e($text), false) . '</span>';
    }
    return $html;
}

/**
 * عرض مجموعة نتائج كجدول HTML.
 *
 * @param string[] $columns أسماء الأعمدة
 * @param array[]  $rows    الصفوف (مصفوفات ترابطية)
 * @param array    $opts    خيارات: types, sortable, order, dir, params, offset, rowid
 */
function sb_render_grid(array $columns, array $rows, array $opts = []): string
{
    $types = $opts['types'] ?? [];
    $sortable = !empty($opts['sortable']);
    $order = $opts['order'] ?? '';
    $dir = strtolower($opts['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $offset = (int) ($opts['offset'] ?? 0);
    $showRowid = !empty($opts['rowid']);
    $rowLink = $opts['row_link'] ?? null;

    if (count($columns) === 0 && count($rows) > 0) {
        $columns = array_keys((array) $rows[0]);
    }
    if (count($columns) === 0) {
        return '<p class="empty">لا توجد أعمدة لعرضها.</p>';
    }

    ob_start();
    ?>
    <div class="grid-wrap">
      <table class="grid">
        <thead>
          <tr>
            <th class="c-rownum">#</th>
            <?php if ($showRowid): ?><th class="c-rowid">rowid</th><?php endif; ?>
            <?php foreach ($columns as $col):
                $type = isset($types[$col]) ? (string) $types[$col] : '';
                $isOrdered = $sortable && $order === $col;
                $nextDir = $isOrdered && $dir === 'asc' ? 'desc' : 'asc';
            ?>
              <th class="c-col<?= $isOrdered ? ' ordered' : '' ?>">
                <?php if ($sortable): ?>
                  <a class="sortlink" href="<?= sb_e(sb_url(['o' => $col, 'd' => $nextDir, 'pg' => 1], $opts['drop_params'] ?? [])) ?>">
                    <?= sb_e($col) ?><span class="arrow"><?= $isOrdered ? ($dir === 'asc' ? '▲' : '▼') : '↕' ?></span>
                  </a>
                <?php else: ?>
                  <?= sb_e($col) ?>
                <?php endif; ?>
                <?php if ($type !== ''): ?><span class="ctype"><?= sb_e($type) ?></span><?php endif; ?>
              </th>
            <?php endforeach; ?>
            <?php if ($rowLink !== null): ?><th class="c-act">إجراء</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php if (count($rows) === 0): ?>
          <tr><td class="empty" colspan="<?= count($columns) + 2 + ($showRowid ? 1 : 0) + ($rowLink !== null ? 1 : 0) ?>">لا توجد صفوف مطابقة.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $i => $row):
            $num = $offset + $i + 1;
        ?>
          <tr>
            <td class="c-rownum"><?= sb_num($num) ?></td>
            <?php if ($showRowid): ?>
              <td class="c-rowid v-num"><?= sb_e(sb_text($row['sb__rid'] ?? '—')) ?></td>
            <?php endif; ?>
            <?php foreach ($columns as $col):
                $value = array_key_exists($col, (array) $row) ? $row[$col] : null;
            ?>
              <td class="cell<?= is_numeric($value) ? ' num' : '' ?>" dir="auto">
                <?= sb_cell_html($value) ?>
              </td>
            <?php endforeach; ?>
            <?php if ($rowLink !== null): ?>
              <td class="c-act">
                <a class="btn btn-xs" href="<?= sb_e(sb_url(array_merge($rowLink, ['r' => $offset + $i]), $opts['drop_params'] ?? [])) ?>">عرض الصف</a>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** شريط التنقّل بين الصفحات. */
function sb_render_pagination(int $total, int $page, int $perPage, array $params = []): string
{
    $pages = max(1, (int) ceil($total / max(1, $perPage)));
    $page = max(1, min($page, $pages));
    $from = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
    $to = min($total, $page * $perPage);

    $window = 3;
    $start = max(1, $page - $window);
    $end = min($pages, $page + $window);

    ob_start();
    ?>
    <div class="pager">
      <span class="pager-info">عرض <?= sb_num($from) ?>–<?= sb_num($to) ?> من <?= sb_num($total) ?> صف · صفحة <?= sb_num($page) ?> / <?= sb_num($pages) ?></span>
      <span class="pager-btns">
        <a class="btn btn-xs<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= sb_e(sb_url(array_merge($params, ['pg' => 1]))) ?>">الأولى</a>
        <a class="btn btn-xs<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= sb_e(sb_url(array_merge($params, ['pg' => max(1, $page - 1)]))) ?>">السابق</a>
        <?php for ($p = $start; $p <= $end; $p++): ?>
          <a class="btn btn-xs<?= $p === $page ? ' active' : '' ?>" href="<?= sb_e(sb_url(array_merge($params, ['pg' => $p]))) ?>"><?= $p ?></a>
        <?php endfor; ?>
        <a class="btn btn-xs<?= $page >= $pages ? ' disabled' : '' ?>" href="<?= sb_e(sb_url(array_merge($params, ['pg' => min($pages, $page + 1)]))) ?>">التالي</a>
        <a class="btn btn-xs<?= $page >= $pages ? ' disabled' : '' ?>" href="<?= sb_e(sb_url(array_merge($params, ['pg' => $pages]))) ?>">الأخيرة</a>
      </span>
      <form class="pager-size" method="get" action="<?= sb_e(sb_script()) ?>">
        <?php foreach ($_GET as $k => $v): if ($k === 'ps' || is_array($v)) { continue; } ?>
          <input type="hidden" name="<?= sb_e((string) $k) ?>" value="<?= sb_e((string) $v) ?>">
        <?php endforeach; ?>
        <label>لكل صفحة
          <select name="ps" onchange="this.form.submit()">
            <?php foreach ([20, 50, 100, 250, 500, 1000] as $size): ?>
              <option value="<?= $size ?>"<?= $size === $perPage ? ' selected' : '' ?>><?= $size ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
    </div>
    <?php
    return (string) ob_get_clean();
}

/* ===========================================================================
 * 9) التنسيق (CSS) والواجهة
 * =========================================================================== */

function sb_css(): string
{
    return <<<'CSS'
*,*::before,*::after{box-sizing:border-box}
:root{
  --bg:#0e1116;--bg2:#12161d;--panel:#161b24;--panel2:#1b2230;--line:#252d3a;
  --line2:#2f3948;--text:#e7ebf0;--muted:#96a2b4;--dim:#6d7a8c;
  --accent:#4aa3ff;--accent2:#2b7fd4;--ok:#3fb950;--warn:#d9a038;--err:#f0616d;--blob:#b98cf0;
  --mono:ui-monospace,SFMono-Regular,"SF Mono","Cascadia Mono",Consolas,"Liberation Mono",monospace;
  --sans:"Segoe UI",Tahoma,"Noto Sans Arabic","Noto Kufi Arabic",Arial,system-ui,sans-serif;
  --radius:10px;
}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:var(--sans);font-size:14px;line-height:1.65}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
code,pre,kbd,.mono{font-family:var(--mono)}
.app{display:flex;align-items:flex-start;min-height:100vh}
/* ---------- الشريط الجانبي ---------- */
.sidebar{width:345px;flex:0 0 345px;background:var(--bg2);border-inline-end:1px solid var(--line);
  position:sticky;top:0;height:100vh;display:flex;flex-direction:column;overflow:hidden}
.sb-head{padding:14px 16px 10px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,#171d27,#12161d)}
.sb-title{display:flex;align-items:center;gap:8px;font-size:16px;font-weight:700;letter-spacing:.2px}
.sb-title .dot{width:9px;height:9px;border-radius:50%;background:var(--ok);box-shadow:0 0 0 3px rgba(63,185,80,.16)}
.sb-sub{color:var(--muted);font-size:11.5px;margin-top:3px}
.sb-tools{padding:10px 12px;border-bottom:1px solid var(--line);display:flex;flex-direction:column;gap:8px}
.sb-list{overflow:auto;flex:1;padding:6px 8px 20px}
.sb-empty{color:var(--muted);padding:18px 12px;text-align:center;font-size:13px}
.dbitem{display:block;padding:9px 10px;border:1px solid transparent;border-radius:8px;margin-bottom:5px;color:var(--text)}
.dbitem:hover{background:var(--panel);border-color:var(--line);text-decoration:none}
.dbitem.active{background:var(--panel2);border-color:var(--accent2);box-shadow:inset 0 0 0 1px rgba(74,163,255,.18)}
.dbitem .n{font-weight:600;font-size:13px;word-break:break-all;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.dbitem .p{color:var(--dim);font-size:11px;font-family:var(--mono);word-break:break-all;direction:ltr;text-align:left;margin-top:2px}
.dbitem .m{color:var(--muted);font-size:11px;margin-top:4px;display:flex;gap:8px;flex-wrap:wrap}
.sb-foot{padding:9px 12px;border-top:1px solid var(--line);color:var(--dim);font-size:11px;display:flex;justify-content:space-between;gap:8px}
/* ---------- المحتوى ---------- */
.main{flex:1;min-width:0;display:flex;flex-direction:column}
.topbar{position:sticky;top:0;z-index:20;background:rgba(14,17,22,.94);backdrop-filter:blur(6px);
  border-bottom:1px solid var(--line);padding:10px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.crumb{font-size:13px;color:var(--muted);min-width:0;flex:1}
.crumb b{color:var(--text)}
.crumb .path{font-family:var(--mono);font-size:11.5px;color:var(--dim);direction:ltr;display:inline-block}
.tabs{display:flex;gap:4px;padding:10px 20px 0;border-bottom:1px solid var(--line);flex-wrap:wrap;background:var(--bg)}
.tab{padding:7px 13px;border:1px solid transparent;border-bottom:none;border-radius:8px 8px 0 0;color:var(--muted);font-size:13px;white-space:nowrap}
.tab:hover{color:var(--text);background:var(--panel)}
.tab.active{color:var(--text);background:var(--panel);border-color:var(--line);font-weight:600}
.content{padding:18px 20px 60px;flex:1}
h2{font-size:17px;margin:0 0 12px}
h3{font-size:14.5px;margin:22px 0 9px;color:var(--text)}
h3:first-child{margin-top:0}
p.lead{color:var(--muted);margin:0 0 14px}
/* ---------- عناصر عامة ---------- */
.badge{display:inline-block;padding:1px 7px;border-radius:20px;font-size:10.5px;font-weight:600;border:1px solid var(--line2);color:var(--muted);background:var(--panel);white-space:nowrap}
.b-ok{color:#8ce0a0;border-color:#255c33;background:#12261a}
.b-warn{color:#f0cd85;border-color:#5d4a1f;background:#241d0f}
.b-err{color:#ffb3ba;border-color:#6a2a30;background:#2a1417}
.b-info{color:#a8d4ff;border-color:#264d75;background:#101f2e}
.b-blob{color:#d6c2ff;border-color:#4a3768;background:#1d1729}
.b-muted{color:var(--muted)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;border:1px solid var(--line2);
  background:var(--panel);color:var(--text);font-size:12.5px;font-family:inherit;cursor:pointer;line-height:1.4}
.btn:hover{background:var(--panel2);border-color:#3b4759;text-decoration:none}
.btn-xs{padding:3px 8px;font-size:11.5px;border-radius:6px}
.btn-primary{background:var(--accent2);border-color:var(--accent2);color:#fff}
.btn-primary:hover{background:#3a8fe0;border-color:#3a8fe0}
.btn.disabled{opacity:.4;pointer-events:none}
.btn.active{background:var(--accent2);border-color:var(--accent2);color:#fff}
.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:14px 16px;margin-bottom:16px}
.card-h{font-weight:700;font-size:13.5px;margin-bottom:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.card-h .sp{flex:1}
.grid2{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.stat{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:12px 14px}
.stat .k{color:var(--muted);font-size:11.5px}
.stat .v{font-size:20px;font-weight:700;margin-top:2px;font-family:var(--mono)}
.stat .s{color:var(--dim);font-size:11px;margin-top:2px}
.alert{border:1px solid var(--line2);border-radius:var(--radius);padding:10px 13px;margin-bottom:14px;font-size:13px}
.alert-warn{border-color:#5d4a1f;background:#221c0e;color:#f3dca6}
.alert-err{border-color:#6a2a30;background:#261315;color:#ffc2c7}
.alert-info{border-color:#264d75;background:#0f1c29;color:#b9d9f7}
.alert-ok{border-color:#255c33;background:#101f15;color:#b6e6c3}
.alert b{color:inherit}
.kv{width:100%;border-collapse:collapse;font-size:13px}
.kv th{text-align:start;color:var(--muted);font-weight:500;padding:6px 10px 6px 0;width:1%;white-space:nowrap;border-bottom:1px solid var(--line)}
.kv td{padding:6px 0;border-bottom:1px solid var(--line);word-break:break-word}
.kv tr:last-child th,.kv tr:last-child td{border-bottom:none}
.kv .mono{font-family:var(--mono);font-size:12px;direction:ltr;display:inline-block}
pre.sql{background:#0b0e13;border:1px solid var(--line);border-radius:8px;padding:11px 13px;overflow:auto;
  font-size:12px;line-height:1.6;direction:ltr;text-align:left;color:#cfe3ff;margin:0}
input[type=text],input[type=password],input[type=search],select,textarea{
  background:#0b0e13;border:1px solid var(--line2);color:var(--text);border-radius:8px;padding:7px 10px;
  font-family:inherit;font-size:13px;width:100%}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent2);box-shadow:0 0 0 3px rgba(74,163,255,.14)}
textarea{font-family:var(--mono);font-size:12.5px;direction:ltr;text-align:left;resize:vertical}
label.lbl{display:block;color:var(--muted);font-size:11.5px;margin-bottom:4px}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.row.tight{gap:6px}
.spacer{flex:1}
.tools{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px}
.tools .field{min-width:180px}
/* ---------- جدول البيانات ---------- */
.grid-wrap{overflow:auto;max-height:calc(100vh - 250px);border:1px solid var(--line);border-radius:var(--radius);background:var(--panel)}
table.grid{border-collapse:separate;border-spacing:0;width:100%;font-size:12.5px}
table.grid thead th{position:sticky;top:0;z-index:5;background:#1a212c;border-bottom:1px solid var(--line2);
  padding:8px 10px;text-align:start;font-weight:600;white-space:nowrap;color:#dfe6ef}
table.grid thead th .ctype{display:block;font-family:var(--mono);font-size:10px;color:var(--dim);font-weight:400;margin-top:1px}
table.grid thead th.ordered{background:#20303f;color:#fff}
table.grid thead th .arrow{color:var(--accent);margin-inline-start:5px;font-size:10px}
table.grid thead th .sortlink{color:inherit}
table.grid tbody td{padding:7px 10px;border-bottom:1px solid #1d2430;vertical-align:top;max-width:520px;word-break:break-word}
table.grid tbody tr:nth-child(even){background:rgba(255,255,255,.017)}
table.grid tbody tr:hover{background:#1c2532}
table.grid tbody tr:last-child td{border-bottom:none}
.c-rownum,.c-rowid{color:var(--dim);font-family:var(--mono);font-size:11px;white-space:nowrap;background:#141922}
.c-rowid{color:#8fb7dd}
.c-act{white-space:nowrap;text-align:center}
td.num{text-align:start}
.v-num{font-family:var(--mono);color:#a9d8ff}
.v-null{color:var(--dim);font-style:italic;font-size:11.5px;border:1px dashed var(--line2);padding:0 5px;border-radius:5px}
.v-empty{color:var(--dim);font-size:11.5px}
.v-blob{color:var(--blob);font-family:var(--mono);font-size:11px;margin-inline-end:6px}
.hex{color:#8b98a9;font-size:10.5px;direction:ltr;display:inline-block;letter-spacing:.3px}
.more{background:none;border:1px solid var(--line2);color:var(--accent);border-radius:5px;font-size:10.5px;
  padding:0 5px;cursor:pointer;font-family:inherit;margin-inline-start:4px}
.more:hover{background:var(--panel2)}
.fulltext{display:block;margin-top:6px;padding:8px 10px;background:#0b0e13;border:1px solid var(--line);border-radius:8px;white-space:pre-wrap}
td.empty,.empty{color:var(--muted);text-align:center;padding:22px}
.pager{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:12px;color:var(--muted);font-size:12px}
.pager-info{font-family:var(--mono);font-size:11.5px}
.pager-btns{display:flex;gap:4px;flex-wrap:wrap}
.pager-size{display:flex;align-items:center;gap:6px;margin-inline-start:auto}
.pager-size select{width:auto;padding:4px 8px;font-size:12px}
/* ---------- تفاصيل صف ---------- */
.rowdetail{width:100%;border-collapse:collapse;font-size:13px}
.rowdetail th{width:1%;text-align:start;padding:8px 12px 8px 0;color:var(--muted);font-weight:500;
  border-bottom:1px solid var(--line);white-space:nowrap;vertical-align:top}
.rowdetail th .ctype{font-family:var(--mono);font-size:10px;color:var(--dim);display:block}
.rowdetail td{padding:8px 0;border-bottom:1px solid var(--line);word-break:break-word}
.rowdetail tr:last-child th,.rowdetail tr:last-child td{border-bottom:none}
/* ---------- صفحة الدخول ---------- */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:
  radial-gradient(1000px 500px at 50% -10%,#1a2432,#0e1116)}
.login{width:100%;max-width:390px;background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:26px}
.login h1{font-size:18px;margin:0 0 6px}
.login p{color:var(--muted);font-size:12.5px;margin:0 0 18px}
.login .btn{width:100%;justify-content:center;padding:9px}
/* ---------- استجابة ---------- */
@media (max-width:980px){
  .app{flex-direction:column}
  .sidebar{width:100%;flex:none;height:auto;position:static;border-inline-end:none;border-bottom:1px solid var(--line)}
  .sb-list{max-height:38vh}
  .grid-wrap{max-height:none}
  .content{padding:14px 12px 50px}
  .topbar,.tabs{padding-inline:12px}
}
@media print{.sidebar,.topbar,.tabs,.pager,.tools{display:none}.grid-wrap{max-height:none;overflow:visible}}
CSS;
}

function sb_js(): string
{
    return <<<'JS'
(function(){
  // تصفية قائمة قواعد البيانات
  var f = document.getElementById('dbfilter');
  if (f) {
    f.addEventListener('input', function(){
      var q = this.value.trim().toLowerCase();
      document.querySelectorAll('.dbitem').forEach(function(el){
        var hay = (el.getAttribute('data-search') || '').toLowerCase();
        el.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
      });
      var empty = document.getElementById('dblist-empty');
      if (empty) empty.style.display = document.querySelectorAll('.dbitem:not([style*="none"])').length ? 'none' : '';
    });
  }
  // عرض المزيد للنصوص الطويلة
  document.addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('[data-more]') : null;
    if (!b) return;
    var full = b.parentNode.querySelector('.fulltext');
    if (!full) return;
    var show = full.hasAttribute('hidden');
    if (show) { full.removeAttribute('hidden'); b.textContent = 'إخفاء'; }
    else { full.setAttribute('hidden',''); b.textContent = 'عرض المزيد'; }
  });
  // نسخ قيمة
  document.addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('[data-copy]') : null;
    if (!b) return;
    var t = b.getAttribute('data-copy');
    if (navigator.clipboard) { navigator.clipboard.writeText(t).then(function(){ b.textContent = 'تم النسخ ✓'; setTimeout(function(){ b.textContent='نسخ'; }, 1200); }); }
  });
  // طي/فتح البطاقات
  document.addEventListener('click', function(e){
    var h = e.target.closest ? e.target.closest('[data-toggle]') : null;
    if (!h) return;
    var el = document.getElementById(h.getAttribute('data-toggle'));
    if (!el) return;
    var hidden = el.hasAttribute('hidden');
    if (hidden) { el.removeAttribute('hidden'); h.classList.add('open'); } else { el.setAttribute('hidden',''); h.classList.remove('open'); }
  });
})();
JS;
}

/* ===========================================================================
 * 10) هيكل الصفحة
 * =========================================================================== */

/** قائمة التبويبات الرئيسية. */
function sb_tabs(): array
{
    $tabs = [
        'overview' => 'نظرة عامة',
        'tables' => 'الجداول',
        'schema' => 'البنية (Schema)',
        'info' => 'معلومات القاعدة',
    ];
    if (SB_ALLOW_SQL_CONSOLE) {
        $tabs['sql'] = 'استعلام للقراءة';
    }
    return $tabs;
}

function sb_layout_open(array $ctx): void
{
    $title = 'متصفح SQLite — للقراءة فقط';
    if (!empty($ctx['db']['rel'])) {
        $title = basename($ctx['db']['rel']) . ' — ' . $title;
    }
    $view = $ctx['view'] ?? '';
    $activeTab = in_array($view, ['data', 'row', 'tables'], true) ? 'tables' : ($view ?: 'overview');
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<title><?= sb_e($title) ?></title>
<style><?= sb_css() ?></style>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="sb-head">
      <div class="sb-title"><span class="dot"></span> متصفح SQLite</div>
      <div class="sb-sub">وضع القراءة فقط — لا يمكن تعديل أي بيانات</div>
    </div>
    <div class="sb-tools">
      <input type="search" id="dbfilter" placeholder="ابحث في قواعد البيانات المكتشفة…" autocomplete="off">
      <div class="row tight">
        <a class="btn btn-xs" href="<?= sb_e(sb_url(['rescan' => 1], ['export', 'blob', 'integrity', 'r'])) ?>">↻ إعادة البحث</a>
        <span class="spacer"></span>
        <span class="badge b-info"><?= sb_num(count($ctx['scan']['dbs'])) ?> قاعدة</span>
      </div>
    </div>
    <div class="sb-list" id="dblist">
      <?php if (count($ctx['scan']['dbs']) === 0): ?>
        <div class="sb-empty" id="dblist-empty">لم يُعثر على قواعد بيانات SQLite<br>داخل مجلدات البحث.</div>
      <?php else: ?>
        <div class="sb-empty" id="dblist-empty" style="display:none">لا نتائج مطابقة للبحث.</div>
        <?php foreach ($ctx['scan']['dbs'] as $db):
            $active = isset($ctx['db']['id']) && $db['id'] === $ctx['db']['id'];
            $searchKey = strtolower($db['rel'] . ' ' . basename($db['rel']));
        ?>
          <a class="dbitem<?= $active ? ' active' : '' ?>"
             data-search="<?= sb_e($searchKey) ?>"
             href="<?= sb_e(sb_url(['db' => $db['id'], 'view' => 'overview'], ['t', 'o', 'd', 'q', 'pg', 'r', 'export', 'blob', 'sqlq', 'runsql', 'integrity', 'rescan'])) ?>">
            <span class="n">
              <?= sb_e(basename($db['rel'])) ?>
              <?php if ($db['status'] !== 'ok'): ?><?= sb_badge('تعذّر الفتح', 'err') ?><?php endif; ?>
              <?php if (!empty($db['wal'])): ?><?= sb_badge('WAL', 'info') ?><?php endif; ?>
            </span>
            <span class="p"><?= sb_e($db['rel']) ?></span>
            <span class="m">
              <span><?= sb_e(sb_size((int) $db['size'])) ?></span>
              <?php if ($db['objects'] !== null): ?><span><?= sb_num($db['objects']) ?> كائن</span><?php endif; ?>
              <?php if ($db['status'] !== 'ok' && !empty($db['error'])): ?>
                <span title="<?= sb_e($db['error']) ?>">⚠</span>
              <?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="sb-foot">
      <span>فُحص <?= sb_num($ctx['scan']['stats']['files']) ?> ملف في <?= sb_num($ctx['scan']['stats']['dirs']) ?> مجلد</span>
      <span><?= sb_e(number_format((float) $ctx['scan']['time'], 2)) ?> ث</span>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <div class="crumb">
        <?php if (!empty($ctx['db'])): ?>
          <b><?= sb_e(basename($ctx['db']['rel'])) ?></b>
          <span class="path"><?= sb_e($ctx['db']['rel']) ?></span>
        <?php else: ?>
          <b>اختر قاعدة بيانات</b> من القائمة الجانبية
        <?php endif; ?>
      </div>
      <?= sb_badge('قراءة فقط', 'ok') ?>
      <?php if (!empty($ctx['snapshot'])): ?><?= sb_badge('وضع لقطة ثابتة', 'warn') ?><?php endif; ?>
      <?php if (sb_auth_enabled()): ?>
        <a class="btn btn-xs" href="<?= sb_e(sb_url(['logout' => 1])) ?>">خروج</a>
      <?php endif; ?>
    </div>

    <?php if (!empty($ctx['db']) && empty($ctx['error'])): ?>
      <nav class="tabs">
        <?php foreach (sb_tabs() as $key => $label): ?>
          <a class="tab<?= $activeTab === $key ? ' active' : '' ?>"
             href="<?= sb_e(sb_url(['view' => $key], ['t', 'o', 'd', 'q', 'pg', 'r', 'export', 'blob', 'sqlq', 'runsql', 'integrity', 'rescan'])) ?>"><?= sb_e($label) ?></a>
        <?php endforeach; ?>
        <?php if ($ctx['table'] !== ''): ?>
          <span class="tab active" style="border-style:dashed">البيانات: <?= sb_e($ctx['table']) ?></span>
        <?php endif; ?>
      </nav>
    <?php endif; ?>

    <main class="content">
    <?php
}

function sb_layout_close(): void
{
    ?>
    </main>
    <div style="padding:0 20px 26px;color:var(--dim);font-size:11.5px;border-top:1px solid var(--line);margin-top:auto">
      متصفح SQLite للقراءة فقط · PHP <?= sb_e(PHP_VERSION) ?> · SQLite <?= sb_e(sb_sqlite_version()) ?> ·
      الاتصال: <?= sb_e($GLOBALS['__sb_pdo_mode'] ?? '—') ?>
    </div>
  </div>
</div>
<script><?= sb_js() ?></script>
</body>
</html>
    <?php
}

function sb_sqlite_version(): string
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    try {
        $pdo = new PDO('sqlite::memory:');
        $v = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
    } catch (Throwable $e) {
        $v = defined('SQLITE_VERSION') ? SQLITE_VERSION : 'غير معروف';
    }
    return $v;
}

/** قراءة آمنة لعلم PRAGMA (بدون استثناءات). */
function sb_pragma_flag(PDO $pdo, string $name): ?int
{
    try {
        $value = $pdo->query('PRAGMA ' . $name)->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    } catch (Throwable $e) {
        return null;
    }
}

/** صندوق تنبيه. */
function sb_alert(string $message, string $kind = 'info', string $title = ''): string
{
    $html = '<div class="alert alert-' . sb_e($kind) . '">';
    if ($title !== '') {
        $html .= '<b>' . sb_e($title) . '</b> ';
    }
    $html .= sb_e($message) . '</div>';
    return $html;
}

/* ===========================================================================
 * 11) الواجهات (Views)
 * =========================================================================== */

function sb_view_home(array $ctx): void
{
    $scan = $ctx['scan'];
    ?>
    <h2>قواعد بيانات SQLite المكتشفة</h2>
    <p class="lead">
      يبحث هذا السكربت تلقائياً في المجلدات الفرعية عن ملفات SQLite ويفتحها
      <b>للقراءة فقط</b>. اختر قاعدة من القائمة الجانبية لتصفّح جداولها وبياناتها.
    </p>

    <?= sb_requirements_card() ?>

    <div class="grid2" style="margin-bottom:16px">
      <div class="stat"><div class="k">قواعد البيانات المكتشفة</div><div class="v"><?= sb_num(count($scan['dbs'])) ?></div>
        <div class="s">الحد الأقصى <?= sb_num(SB_SCAN_MAX_DBS) ?></div></div>
      <div class="stat"><div class="k">الملفات التي فُحصت</div><div class="v"><?= sb_num($scan['stats']['files']) ?></div>
        <div class="s">في <?= sb_num($scan['stats']['dirs']) ?> مجلد · عمق <?= (int) SB_SCAN_MAX_DEPTH ?></div></div>
      <div class="stat"><div class="k">زمن البحث</div><div class="v"><?= sb_e(number_format((float) $scan['time'], 2)) ?></div>
        <div class="s">ثانية<?= $scan['cached'] ? ' (نتيجة مخزّنة)' : '' ?></div></div>
      <div class="stat"><div class="k">مجلدات البحث</div><div class="v"><?= sb_num(count((array) SB_SCAN_ROOTS)) ?></div>
        <div class="s mono" style="direction:ltr;text-align:left"><?= sb_e(implode(' , ', array_map(static function ($r) { return is_string($r) ? $r : ($r['missing'] ?? '؟'); }, sb_real_roots()))) ?></div></div>
    </div>

    <?php if (!empty($scan['truncated'])): ?>
      <?= sb_alert('تم بلوغ أحد حدود البحث (عدد الملفات أو المجلدات أو قواعد البيانات)، فقد تكون بعض القواعد غير معروضة. ارفع القيم في إعدادات SB_SCAN_MAX_* داخل الملف.', 'warn', 'تنبيه:') ?>
    <?php endif; ?>

    <?php if (count($scan['dbs']) === 0): ?>
      <div class="card">
        <div class="card-h">لم يتم العثور على قواعد بيانات</div>
        <p style="margin-top:0">تحقّق من التالي:</p>
        <ul style="color:var(--muted);margin:0;padding-inline-start:20px">
          <li>أن مجلد البحث <code>SB_SCAN_ROOTS</code> في بداية الملف يشير إلى المجلد الصحيح (حالياً: مجلد السكربت).</li>
          <li>أن امتداد الملف ضمن <code>SB_DB_EXTENSIONS</code>، أو فعّل <code>SB_SCAN_BY_CONTENT</code> للفحص عبر ترويسة الملف.</li>
          <li>أن العمق <code>SB_SCAN_MAX_DEPTH</code> كافٍ للوصول إلى المجلدات البعيدة.</li>
          <li>أن المجلد غير موجود في قائمة التجاهل <code>SB_SKIP_DIRS</code>.</li>
          <li>أن صلاحيات القراءة تسمح للسيرفر بالوصول إلى المجلدات.</li>
        </ul>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="card-h">القواعد المتاحة <span class="sp"></span>
          <a class="btn btn-xs" href="<?= sb_e(sb_url(['rescan' => 1])) ?>">↻ بحث جديد</a>
        </div>
        <div class="grid-wrap" style="max-height:none">
          <table class="grid">
            <thead><tr>
              <th>الملف</th><th>المسار النسبي</th><th>الحجم</th><th>الكائنات</th><th>آخر تعديل</th><th>الحالة</th><th class="c-act">فتح</th>
            </tr></thead>
            <tbody>
            <?php foreach ($scan['dbs'] as $db): ?>
              <tr>
                <td><b><?= sb_e(basename($db['rel'])) ?></b>
                  <?php if (!empty($db['wal'])): ?> <?= sb_badge('WAL', 'info') ?><?php endif; ?>
                </td>
                <td><span class="mono" style="font-size:11.5px;direction:ltr;display:inline-block"><?= sb_e($db['rel']) ?></span></td>
                <td class="v-num"><?= sb_e(sb_size((int) $db['size'])) ?></td>
                <td class="v-num"><?= $db['objects'] === null ? '—' : sb_num($db['objects']) ?></td>
                <td class="v-num"><?= sb_e(sb_date((int) $db['mtime'])) ?></td>
                <td>
                  <?php if ($db['status'] === 'ok'): ?><?= sb_badge('سليمة', 'ok') ?>
                  <?php else: ?><?= sb_badge('خطأ', 'err') ?> <span style="color:var(--muted);font-size:11.5px"><?= sb_e($db['error']) ?></span><?php endif; ?>
                </td>
                <td class="c-act">
                  <?php if ($db['status'] === 'ok'): ?>
                    <a class="btn btn-xs btn-primary" href="<?= sb_e(sb_url(['db' => $db['id'], 'view' => 'overview'], ['t', 'q', 'pg', 'r', 'export', 'blob', 'rescan'])) ?>">تصفّح</a>
                  <?php else: ?>
                    <span class="badge b-muted">غير متاحة</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if (count($scan['errors']) > 0): ?>
      <div class="card">
        <div class="card-h" data-toggle="scan-errors" style="cursor:pointer">
          ملفات بامتداد قاعدة بيانات لكنها ليست SQLite (<?= count($scan['errors']) ?>) <span class="badge b-muted">اضغط للعرض</span>
        </div>
        <div id="scan-errors" hidden>
          <table class="kv">
            <?php foreach (array_slice($scan['errors'], 0, 60) as $err): ?>
              <tr><th class="mono" style="direction:ltr"><?= sb_e(sb_relative($err['path'])) ?></th><td style="color:var(--muted)"><?= sb_e($err['error']) ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>
    <?php endif; ?>
    <?php
}

function sb_requirements_card(): string
{
    $missing = [];
    if (!class_exists('PDO')) {
        $missing[] = 'PDO';
    }
    if (!in_array('sqlite', class_exists('PDO') ? PDO::getAvailableDrivers() : [], true)) {
        $missing[] = 'pdo_sqlite';
    }
    ob_start();
    ?>
    <div class="card">
      <div class="card-h">
        حالة التشغيل
        <?php if (count($missing) === 0): ?><?= sb_badge('كل المتطلبات متوفرة', 'ok') ?><?php else: ?><?= sb_badge('متطلبات ناقصة', 'err') ?><?php endif; ?>
      </div>
      <table class="kv">
        <tr><th>إصدار PHP</th><td><?= sb_e(PHP_VERSION) ?> <span style="color:var(--dim)">(المطلوب 7.4 أو أحدث)</span></td></tr>
        <tr><th>امتداد pdo_sqlite</th><td><?= in_array('sqlite', class_exists('PDO') ? PDO::getAvailableDrivers() : [], true) ? sb_badge('مثبّت', 'ok') : sb_badge('غير مثبّت', 'err') ?></td></tr>
        <tr><th>إصدار SQLite</th><td><?= sb_e(sb_sqlite_version()) ?></td></tr>
        <tr><th>mbstring (لعرض العربية)</th><td><?= extension_loaded('mbstring') ? sb_badge('متوفر', 'ok') : sb_badge('غير متوفر — قد يتأثر اقتطاع النصوص', 'warn') ?></td></tr>
        <tr><th>الحماية بكلمة مرور</th><td><?= sb_auth_enabled() ? sb_badge('مفعّلة', 'ok') : sb_badge('معطّلة — ضع قيمة في SB_ACCESS_PASSWORD إن كان الموقع عاماً', 'warn') ?></td></tr>
      </table>
      <?php if (count($missing) > 0): ?>
        <div class="alert alert-err" style="margin-top:12px;margin-bottom:0">
          <b>ناقص:</b> <?= sb_e(implode('، ', $missing)) ?> — فعّل الامتداد من إعدادات PHP في لوحة التحكم
          (في cPanel: Select PHP Version → Extensions → pdo_sqlite).
        </div>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** حساب أعداد الصفوف ضمن ميزانية زمنية. */
function sb_counts(PDO $pdo, array $names): array
{
    $counts = [];
    $skipped = false;
    $start = microtime(true);
    foreach ($names as $name) {
        if (microtime(true) - $start > SB_COUNTS_TIME_BUDGET) {
            $skipped = true;
            $counts[$name] = null;
            continue;
        }
        $counts[$name] = sb_count_rows($pdo, $name);
    }
    return ['counts' => $counts, 'skipped' => $skipped];
}

function sb_view_overview(array $ctx): void
{
    $pdo = $ctx['pdo'];
    $objects = $ctx['objects'];
    $info = sb_db_info($pdo, $ctx['db']['path']);
    $userTables = array_values(array_filter($objects['tables'], static function ($t) {
        return !$t['internal'] && $t['shadow_of'] === null;
    }));
    ?>
    <h2>نظرة عامة</h2>

    <?php if (!empty($ctx['snapshot'])): ?>
      <?= sb_alert('تعذّر فتح هذه القاعدة بوضع القراءة الصارم، ففُتحت كـ"لقطة ثابتة" (immutable). القراءة آمنة تماماً ولا تعدّل شيئاً، لكن إن كان تطبيقك يكتب في القاعدة لحظة التصفّح فقد ترى بيانات قديمة قليلاً.', 'warn', 'وضع اللقطة:') ?>
    <?php endif; ?>

    <div class="grid2" style="margin-bottom:16px">
      <div class="stat"><div class="k">الجداول</div><div class="v"><?= sb_num(count($objects['tables'])) ?></div>
        <div class="s"><?= sb_num(count($userTables)) ?> جدول مستخدم</div></div>
      <div class="stat"><div class="k">العروض (Views)</div><div class="v"><?= sb_num(count($objects['views'])) ?></div>
        <div class="s">يمكن تصفّحها كالجداول</div></div>
      <div class="stat"><div class="k">الفهارس</div><div class="v"><?= sb_num(count($objects['indexes'])) ?></div>
        <div class="s">Indexes</div></div>
      <div class="stat"><div class="k">المشغّلات</div><div class="v"><?= sb_num(count($objects['triggers'])) ?></div>
        <div class="s">Triggers</div></div>
      <div class="stat"><div class="k">حجم الملف</div><div class="v" style="font-size:16px"><?= sb_e(sb_size((int) $ctx['db']['size'])) ?></div>
        <div class="s">آخر تعديل: <?= sb_e(sb_date((int) $ctx['db']['mtime'])) ?></div></div>
      <div class="stat"><div class="k">وضع اليوميات</div><div class="v" style="font-size:16px"><?= sb_e(strtoupper((string) ($info['journal_mode']['value'] ?? '—'))) ?></div>
        <div class="s">الترميز: <?= sb_e($info['encoding']['value'] ?? '—') ?></div></div>
    </div>

    <div class="card">
      <div class="card-h">حالة الاتصال
        <span class="sp"></span>
        <?= sb_badge('لا يمكن التعديل', 'ok') ?>
      </div>
      <table class="kv">
        <tr><th>طريقة الفتح</th><td><span class="mono"><?= sb_e($GLOBALS['__sb_pdo_mode'] ?? '—') ?></span></td></tr>
        <tr><th>PRAGMA query_only</th><td><?= sb_pragma_flag($pdo, 'query_only') === 1 ? sb_badge('مفعّل (ON)', 'ok') : sb_badge('غير مفعّل', 'err') ?></td></tr>
        <tr><th>المسار الكامل</th><td><span class="mono" style="direction:ltr"><?= sb_e($ctx['db']['path']) ?></span></td></tr>
        <tr><th>إصدار SQLite</th><td><?= sb_e($info['__sqlite']['value']) ?></td></tr>
      </table>
      <div class="row" style="margin-top:12px">
        <a class="btn" href="<?= sb_e(sb_url(['view' => 'tables'])) ?>">تصفّح الجداول والبيانات ←</a>
        <a class="btn" href="<?= sb_e(sb_url(['view' => 'schema'])) ?>">عرض البنية الكاملة</a>
        <a class="btn" href="<?= sb_e(sb_url(['view' => 'info', 'integrity' => 1])) ?>">فحص سلامة القاعدة</a>
      </div>
    </div>

    <div class="card">
      <div class="card-h">الجداول الرئيسية <span class="sp"></span>
        <a class="btn btn-xs" href="<?= sb_e(sb_url(['view' => 'tables'])) ?>">عرض الكل</a>
      </div>
      <?php if (count($userTables) === 0): ?>
        <p class="empty">لا توجد جداول في هذه القاعدة.</p>
      <?php else: ?>
        <?php $c = sb_counts($pdo, array_slice(array_column($userTables, 'name'), 0, 12)); ?>
        <div class="grid-wrap" style="max-height:none">
          <table class="grid">
            <thead><tr><th>الجدول</th><th>الأعمدة</th><th>الصفوف</th><th class="c-act">تصفّح</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($userTables, 0, 12) as $t):
                $n = $c['counts'][$t['name']] ?? null;
            ?>
              <tr>
                <td><b><?= sb_e($t['name']) ?></b>
                  <?php if ($t['virtual']): ?><?= sb_badge('افتراضي', 'blob') ?><?php endif; ?>
                </td>
                <td class="v-num"><?= sb_num(count(sb_columns($pdo, $t['name']))) ?></td>
                <td class="v-num"><?= $n === null ? '—' : sb_num($n) ?></td>
                <td class="c-act"><a class="btn btn-xs btn-primary" href="<?= sb_e(sb_url(['view' => 'data', 't' => $t['name'], 'pg' => 1], ['o', 'd', 'q', 'r', 'export', 'blob'])) ?>">فتح</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

function sb_view_tables(array $ctx): void
{
    $pdo = $ctx['pdo'];
    $objects = $ctx['objects'];
    $tables = $objects['tables'];
    $views = $objects['views'];
    $showCounts = sb_param('counts', SB_SHOW_ROW_COUNTS ? '1' : '0') !== '0';
    $names = array_merge(array_column($tables, 'name'), array_column($views, 'name'));
    $counts = $showCounts ? sb_counts($pdo, $names) : ['counts' => [], 'skipped' => false];
    ?>
    <h2>الجداول والعروض</h2>
    <div class="row" style="margin-bottom:14px">
      <span class="badge b-muted"><?= sb_num(count($tables)) ?> جدول</span>
      <span class="badge b-muted"><?= sb_num(count($views)) ?> عرض</span>
      <span class="spacer"></span>
      <?php if ($showCounts): ?>
        <a class="btn btn-xs" href="<?= sb_e(sb_url(['counts' => 0])) ?>">إخفاء أعداد الصفوف (أسرع)</a>
      <?php else: ?>
        <a class="btn btn-xs" href="<?= sb_e(sb_url(['counts' => 1])) ?>">إظهار أعداد الصفوف</a>
      <?php endif; ?>
    </div>

    <?php if ($counts['skipped']): ?>
      <?= sb_alert('تم تخطي حساب عدد الصفوف لبعض الكائنات لتجاوز الميزانية الزمنية (' . SB_COUNTS_TIME_BUDGET . ' ثانية). يمكنك تعديل SB_COUNTS_TIME_BUDGET أو إخفاء الأعداد.', 'warn') ?>
    <?php endif; ?>

    <?php if (count($tables) === 0 && count($views) === 0): ?>
      <?= sb_alert('هذه القاعدة فارغة — لا تحتوي على أي جدول أو عرض.', 'info') ?>
    <?php endif; ?>

    <?php foreach ([['الجداول', $tables, 'table'], ['العروض (Views)', $views, 'view']] as $group):
        [$label, $items, $kind] = $group;
        if (count($items) === 0) {
            continue;
        }
    ?>
      <h3><?= sb_e($label) ?> (<?= count($items) ?>)</h3>
      <div class="grid-wrap" style="max-height:none">
        <table class="grid">
          <thead><tr>
            <th>الاسم</th><th>النوع</th><th>الأعمدة</th><th>الصفوف</th><th class="c-act">إجراءات</th>
          </tr></thead>
          <tbody>
          <?php foreach ($items as $item):
              $name = $item['name'];
              $ncols = count(sb_columns($pdo, $name));
              $cnt = $counts['counts'][$name] ?? null;
              $isShadow = $item['shadow_of'] !== null;
          ?>
            <tr<?= $isShadow ? ' style="opacity:.62"' : '' ?>>
              <td>
                <b><?= sb_e($name) ?></b>
                <?php if ($item['internal']): ?><?= sb_badge('داخلي', 'muted') ?><?php endif; ?>
                <?php if ($item['virtual']): ?><?= sb_badge('افتراضي', 'blob') ?><?php endif; ?>
                <?php if ($isShadow): ?><?= sb_badge('ظلّ لـ ' . $item['shadow_of'], 'muted') ?><?php endif; ?>
              </td>
              <td><span class="badge b-<?= $kind === 'view' ? 'info' : 'muted' ?>"><?= $kind === 'view' ? 'عرض' : 'جدول' ?></span></td>
              <td class="v-num"><?= sb_num($ncols) ?></td>
              <td class="v-num"><?= $cnt === null ? '—' : sb_num($cnt) ?></td>
              <td class="c-act">
                <span class="row tight" style="justify-content:center">
                  <a class="btn btn-xs btn-primary" href="<?= sb_e(sb_url(['view' => 'data', 't' => $name, 'pg' => 1], ['o', 'd', 'q', 'r', 'export', 'blob'])) ?>">البيانات</a>
                  <a class="btn btn-xs" href="<?= sb_e(sb_url(['view' => 'schema', 't' => $name])) ?>">البنية</a>
                  <a class="btn btn-xs" href="<?= sb_e(sb_url(['view' => 'data', 't' => $name, 'export' => 'csv'], ['o', 'd', 'q', 'pg', 'r', 'blob'])) ?>">CSV</a>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
    <?php
}

/**
 * تجهيز استعلام جدول مع الترتيب والبحث والترقيم.
 * تُستخدم في عرض البيانات وفي التصدير لضمان تطابق النتائج.
 */
function sb_prepare_table_query(PDO $pdo, string $table, int $perPage, int $page, string $q, ?string $orderBy, string $dir): array
{
    $cols = sb_columns($pdo, $table);
    $hasRowid = sb_has_rowid($pdo, $table);
    $types = [];
    foreach ($cols as $c) {
        $types[$c['name']] = $c['type'];
    }

    $perPage = max(1, min($perPage, SB_MAX_PAGE_SIZE));
    $page = max(1, $page);

    $built = sb_build_select($table, $cols, $hasRowid, $orderBy, $dir, $q, $perPage, ($page - 1) * $perPage);

    $total = null;
    $error = null;
    try {
        $cs = $pdo->prepare($built['count_sql']);
        $cs->execute($built['params']);
        $value = $cs->fetchColumn();
        $total = $value === false || $value === null ? 0 : (int) $value;
    } catch (Throwable $e) {
        $error = sb_db_error_label($e);
    }

    $pages = max(1, (int) ceil(((int) $total) / $perPage));
    if ($page > $pages) {
        $page = $pages;
        $built = sb_build_select($table, $cols, $hasRowid, $orderBy, $dir, $q, $perPage, ($page - 1) * $perPage);
    }

    $rows = [];
    if ($error === null) {
        try {
            $st = $pdo->prepare($built['sql']);
            $st->execute($built['params']);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $error = sb_db_error_label($e);
        }
    }

    // استخراج عمود الترتيب الفعلي المستخدم
    $effectiveOrder = '';
    if (preg_match('/ORDER BY (?:rowid|"((?:[^"]|"")*)")/u', $built['order'], $m)) {
        $effectiveOrder = isset($m[1]) && $m[1] !== '' ? str_replace('""', '"', $m[1]) : 'sb__rid';
    }
    $effectiveDir = stripos($built['order'], ' DESC') !== false ? 'desc' : 'asc';

    return [
        'cols' => $cols,
        'types' => $types,
        'has_rowid' => $hasRowid,
        'built' => $built,
        'total' => (int) $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
        'rows' => $rows,
        'error' => $error,
        'order' => $effectiveOrder,
        'dir' => $effectiveDir,
    ];
}

function sb_view_data(array $ctx): void
{
    $pdo = $ctx['pdo'];
    $table = $ctx['table'];

    if (!sb_object_exists($pdo, $table)) {
        echo sb_alert('لا يوجد جدول أو عرض بهذا الاسم في هذه القاعدة: ' . $table, 'err', 'غير موجود:');
        sb_view_tables($ctx);
        return;
    }

    $perPage = sb_param_int('ps', SB_PAGE_SIZE);
    if ($perPage < 1 || $perPage > SB_MAX_PAGE_SIZE) {
        $perPage = SB_PAGE_SIZE;
    }
    $page = sb_param_int('pg', 1);
    $q = sb_param('q');
    $o = sb_param('o');
    $d = sb_param('d', 'asc');

    $r = sb_prepare_table_query($pdo, $table, $perPage, $page, $q, $o !== '' ? $o : null, $d);

    // ملاحظة: عمود rowid يُعرض في خانة مستقلة داخل sb_render_grid
    // ولا يظهر ضمن $columns، لذلك لا حاجة لحذفه من الصفوف هنا.
    $columns = $r['built']['columns'];
    $rows = $r['rows'];

    $objectSql = null;
    foreach ($ctx['objects']['tables'] as $t) {
        if ($t['name'] === $table) {
            $objectSql = $t['sql'];
            break;
        }
    }
    if ($objectSql === null) {
        foreach ($ctx['objects']['views'] as $v) {
            if ($v['name'] === $table) {
                $objectSql = $v['sql'];
                break;
            }
        }
    }
    ?>
    <div class="row" style="margin-bottom:12px">
      <h2 style="margin:0">البيانات: <?= sb_e($table) ?></h2>
      <span class="badge b-info"><?= sb_num($r['total']) ?> صف</span>
      <span class="badge b-muted"><?= sb_num(count($columns)) ?> عمود</span>
      <?php if ($r['has_rowid']): ?><span class="badge b-muted">rowid متاح</span><?php else: ?><span class="badge b-warn">WITHOUT ROWID</span><?php endif; ?>
    </div>

    <?php if ($r['error'] !== null): ?>
      <?= sb_alert($r['error'], 'err', 'تعذّر قراءة البيانات:') ?>
    <?php endif; ?>

    <form class="tools" method="get" action="<?= sb_e(sb_script()) ?>">
      <?php foreach ($_GET as $k => $v):
          if (is_array($v) || in_array((string) $k, ['q', 'pg'], true)) {
              continue;
          } ?>
        <input type="hidden" name="<?= sb_e((string) $k) ?>" value="<?= sb_e((string) $v) ?>">
      <?php endforeach; ?>
      <div class="field" style="flex:1;min-width:240px">
        <label class="lbl" for="q">بحث داخل كل أعمدة الجدول (LIKE %…%)</label>
        <input type="search" id="q" name="q" value="<?= sb_e($q) ?>" placeholder="اكتب كلمة للبحث…">
      </div>
      <button class="btn btn-primary" type="submit">بحث</button>
      <?php if ($q !== ''): ?>
        <a class="btn" href="<?= sb_e(sb_url([], ['q', 'pg'])) ?>">إلغاء البحث</a>
      <?php endif; ?>
      <span class="spacer"></span>
      <a class="btn" href="<?= sb_e(sb_url(['export' => 'csv'], ['r', 'blob', 'integrity', 'rescan', 'pg', 'ps'])) ?>">⬇ تصدير CSV</a>
      <a class="btn" href="<?= sb_e(sb_url(['export' => 'json'], ['r', 'blob', 'integrity', 'rescan', 'pg', 'ps'])) ?>">⬇ تصدير JSON</a>
    </form>

    <?php
    $dropParams = ['r', 'export', 'blob', 'integrity', 'rescan', 'runsql', 'sqlq'];
    echo sb_render_grid($columns, $rows, [
        'types' => $r['types'],
        'sortable' => true,
        'order' => $r['order'],
        'dir' => $r['dir'],
        'offset' => ($r['page'] - 1) * $r['per_page'],
        'rowid' => $r['built']['use_rowid'],
        'row_link' => ['view' => 'row', 't' => $table, 'o' => $r['order'], 'd' => $r['dir'], 'q' => $q, 'ps' => $r['per_page']],
        'drop_params' => $dropParams,
    ]);
    ?>

    <?= sb_render_pagination($r['total'], $r['page'], $r['per_page'], ['view' => 'data', 't' => $table, 'o' => $r['order'] ?: null, 'd' => $r['dir'], 'q' => $q ?: null]) ?>

    <div class="card" style="margin-top:18px">
      <div class="card-h" data-toggle="struct-box" style="cursor:pointer">بنية الأعمدة (<?= count($r['cols']) ?>) <span class="badge b-muted">اضغط للعرض</span></div>
      <div id="struct-box" hidden>
        <?= sb_columns_table($pdo, $table, $r['cols']) ?>
      </div>
    </div>

    <?php if ($objectSql !== null && $objectSql !== ''): ?>
      <div class="card">
        <div class="card-h" data-toggle="ddl-box" style="cursor:pointer">تعريف SQL (DDL) <span class="badge b-muted">اضغط للعرض</span></div>
        <div id="ddl-box" hidden><pre class="sql"><?= sb_e($objectSql) ?></pre></div>
      </div>
    <?php endif; ?>
    <?php
}

/** جدول يصف أعمدة كائن مع الفهارس والمفاتيح الأجنبية. */
function sb_columns_table(PDO $pdo, string $table, array $cols): string
{
    ob_start();
    ?>
    <div class="grid-wrap" style="max-height:none">
      <table class="grid">
        <thead><tr><th>#</th><th>العمود</th><th>النوع</th><th>NOT NULL</th><th>المفتاح الأساسي</th><th>القيمة الافتراضية</th></tr></thead>
        <tbody>
        <?php foreach ($cols as $c): ?>
          <tr>
            <td class="c-rownum"><?= (int) $c['cid'] ?></td>
            <td><b><?= sb_e($c['name']) ?></b></td>
            <td><span class="badge b-info mono"><?= sb_e($c['type'] === '' ? 'ANY' : $c['type']) ?></span></td>
            <td><?= $c['notnull'] ? sb_badge('NOT NULL', 'warn') : '<span style="color:var(--dim)">—</span>' ?></td>
            <td><?= $c['pk'] > 0 ? sb_badge('PK' . ($c['pk'] > 1 ? ' #' . $c['pk'] : ''), 'ok') : '<span style="color:var(--dim)">—</span>' ?></td>
            <td class="mono" style="font-size:11.5px;direction:ltr"><?= $c['default'] === null ? '<span style="color:var(--dim)">NULL</span>' : sb_e(sb_text($c['default'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (count($cols) === 0): ?>
          <tr><td class="empty" colspan="6">تعذّر قراءة الأعمدة.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    $indexes = sb_indexes($pdo, $table);
    $fks = sb_foreign_keys($pdo, $table);
    if (count($indexes) > 0):
    ?>
      <h3 style="margin-top:16px">الفهارس (<?= count($indexes) ?>)</h3>
      <table class="kv">
        <?php foreach ($indexes as $idx): ?>
          <tr>
            <th class="mono" style="direction:ltr"><?= sb_e($idx['name']) ?></th>
            <td>
              <?php if ($idx['unique']): ?><?= sb_badge('UNIQUE', 'ok') ?><?php endif; ?>
              <?php if ($idx['partial']): ?><?= sb_badge('جزئي', 'warn') ?><?php endif; ?>
              <?php if ($idx['origin'] !== ''): ?><span class="badge b-muted"><?= sb_e($idx['origin']) ?></span><?php endif; ?>
              <span class="mono" style="font-size:11.5px;direction:ltr">(<?= sb_e(implode(', ', $idx['columns'])) ?>)</span>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <?php if (count($fks) > 0): ?>
      <h3 style="margin-top:16px">المفاتيح الأجنبية (<?= count($fks) ?>)</h3>
      <table class="kv">
        <?php foreach ($fks as $fk): ?>
          <tr>
            <th class="mono" style="direction:ltr"><?= sb_e(implode(', ', $fk['columns'])) ?></th>
            <td>
              ← <b><?= sb_e($fk['table']) ?></b>
              <span class="mono" style="direction:ltr">(<?= sb_e(implode(', ', $fk['references'])) ?>)</span>
              <?php if ($fk['on_delete'] !== '' && $fk['on_delete'] !== 'NO ACTION'): ?><?= sb_badge('ON DELETE ' . $fk['on_delete'], 'info') ?><?php endif; ?>
              <?php if ($fk['on_update'] !== '' && $fk['on_update'] !== 'NO ACTION'): ?><?= sb_badge('ON UPDATE ' . $fk['on_update'], 'info') ?><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif;
    return (string) ob_get_clean();
}

function sb_view_row(array $ctx): void
{
    $pdo = $ctx['pdo'];
    $table = $ctx['table'];
    if (!sb_object_exists($pdo, $table)) {
        echo sb_alert('الجدول غير موجود: ' . $table, 'err');
        return;
    }
    $perPage = sb_param_int('ps', SB_PAGE_SIZE);
    $q = sb_param('q');
    $o = sb_param('o');
    $d = sb_param('d', 'asc');
    $index = max(0, sb_param_int('r', 0));

    // نحتاج الحد الأقصى للترقيم، لذا نستعلم بحدّ كبير لهذا السياق فقط
    $probe = sb_prepare_table_query($pdo, $table, 1, 1, $q, $o !== '' ? $o : null, $d);
    $total = $probe['total'];

    $built = sb_build_select($table, $probe['cols'], $probe['has_rowid'], $o !== '' ? $o : null, $d, $q, 1, $index);
    $row = null;
    $error = null;
    try {
        $st = $pdo->prepare($built['sql']);
        $st->execute($built['params']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = sb_db_error_label($e);
    }
    ?>
    <div class="row" style="margin-bottom:12px">
      <h2 style="margin:0">تفاصيل الصف</h2>
      <span class="badge b-muted"><?= sb_e($table) ?></span>
      <?php if ($row): ?><span class="badge b-info">الصف رقم <?= sb_num($index + 1) ?> من <?= sb_num($total) ?></span><?php endif; ?>
      <span class="spacer"></span>
      <a class="btn btn-xs" href="<?= sb_e(sb_url(['view' => 'data', 't' => $table, 'pg' => max(1, intdiv($index, max(1, $perPage)) + 1)], ['r', 'export', 'blob'])) ?>">← رجوع للبيانات</a>
      <?php if ($row && $index > 0): ?>
        <a class="btn btn-xs" href="<?= sb_e(sb_url(['r' => $index - 1])) ?>">السابق</a>
      <?php endif; ?>
      <?php if ($row && $index + 1 < $total): ?>
        <a class="btn btn-xs" href="<?= sb_e(sb_url(['r' => $index + 1])) ?>">التالي</a>
      <?php endif; ?>
    </div>

    <?php if ($error !== null): ?>
      <?= sb_alert($error, 'err') ?>
    <?php elseif (!$row): ?>
      <?= sb_alert('لا يوجد صف بهذا الرقم (قد يكون الفهرس خارج النطاق بعد تغيير البحث أو الترتيب).', 'warn') ?>
    <?php else: ?>
      <div class="card">
        <table class="rowdetail">
          <?php foreach ($probe['cols'] as $c):
              $name = $c['name'];
              $value = array_key_exists($name, $row) ? $row[$name] : null;
              $isBlob = sb_is_binary($value);
          ?>
            <tr>
              <th>
                <?= sb_e($name) ?>
                <span class="ctype"><?= sb_e($c['type'] === '' ? 'ANY' : $c['type']) ?><?= $c['pk'] ? ' · PK' : '' ?><?= $c['notnull'] ? ' · NOT NULL' : '' ?></span>
              </th>
              <td dir="auto">
                <?= sb_cell_html($value, true) ?>
                <?php if ($isBlob): ?>
                  <a class="btn btn-xs" style="margin-inline-start:8px"
                     href="<?= sb_e(sb_url(['blob' => $name, 'r' => $index])) ?>">⬇ تنزيل الملف الثنائي</a>
                <?php elseif ($value !== null && !is_int($value) && !is_float($value)): ?>
                  <button type="button" class="more" data-copy="<?= sb_e(sb_text($value)) ?>">نسخ</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($built['use_rowid'] && isset($row['sb__rid'])): ?>
            <tr><th>rowid<span class="ctype">معرف الصف الداخلي</span></th><td class="v-num"><?= sb_e(sb_text($row['sb__rid'])) ?></td></tr>
          <?php endif; ?>
        </table>
      </div>
    <?php endif;
}

function sb_view_schema(array $ctx): void
{
    $pdo = $ctx['pdo'];
    $objects = $ctx['objects'];
    $focus = $ctx['table'];

    if ($focus !== '' && sb_object_exists($pdo, $focus, ['table', 'view'])):
        $sql = null;
        foreach (array_merge($objects['tables'], $objects['views']) as $o) {
            if ($o['name'] === $focus) {
                $sql = $o['sql'];
                break;
            }
        }
        ?>
        <h2>بنية: <?= sb_e($focus) ?></h2>
        <div class="row" style="margin-bottom:12px">
          <a class="btn btn-xs" href="<?= sb_e(sb_url(['view' => 'schema'], ['t'])) ?>">← كل الكائنات</a>
          <a class="btn btn-xs btn-primary" href="<?= sb_e(sb_url(['view' => 'data', 't' => $focus, 'pg' => 1], ['o', 'd', 'q', 'r', 'export', 'blob'])) ?>">تصفّح البيانات</a>
        </div>
        <div class="card">
          <div class="card-h">تعريف SQL</div>
          <pre class="sql"><?= sb_e($sql === null ? '-- لا يوجد تعريف محفوظ (ربما جدول داخلي)' : $sql) ?></pre>
        </div>
        <div class="card">
          <div class="card-h">التفاصيل</div>
          <?= sb_columns_table($pdo, $focus, sb_columns($pdo, $focus)) ?>
        </div>
        <?php
        return;
    endif;
    ?>
    <h2>البنية الكاملة للقاعدة</h2>
    <p class="lead">كل الكائنات المحفوظة في <code>sqlite_master</code> مع تعريفها (DDL) وتفاصيل أعمدتها. اضغط على أي عنصر لفتحه.</p>

    <div class="card">
      <div class="card-h">الجداول (<?= count($objects['tables']) ?>)</div>
      <?php foreach ($objects['tables'] as $t): ?>
        <details<?= $t['shadow_of'] !== null ? ' style="opacity:.65"' : '' ?>>
          <summary style="cursor:pointer;padding:6px 0">
            <b><?= sb_e($t['name']) ?></b>
            <span class="badge b-muted"><?= sb_num(count(sb_columns($pdo, $t['name']))) ?> عمود</span>
            <?php if ($t['internal']): ?><?= sb_badge('داخلي', 'muted') ?><?php endif; ?>
            <?php if ($t['virtual']): ?><?= sb_badge('افتراضي', 'blob') ?><?php endif; ?>
            <?php if ($t['shadow_of'] !== null): ?><?= sb_badge('ظلّ لـ ' . $t['shadow_of'], 'muted') ?><?php endif; ?>
            <a class="btn btn-xs" style="margin-inline-start:6px" href="<?= sb_e(sb_url(['view' => 'data', 't' => $t['name'], 'pg' => 1], ['o', 'd', 'q', 'r', 'export', 'blob'])) ?>">البيانات</a>
          </summary>
          <div style="padding:8px 0 14px">
            <pre class="sql"><?= sb_e($t['sql'] ?? '-- لا يوجد تعريف') ?></pre>
            <?= sb_columns_table($pdo, $t['name'], sb_columns($pdo, $t['name'])) ?>
          </div>
        </details>
      <?php endforeach; ?>
      <?php if (count($objects['tables']) === 0): ?><p class="empty">لا توجد جداول.</p><?php endif; ?>
    </div>

    <div class="card">
      <div class="card-h">العروض — Views (<?= count($objects['views']) ?>)</div>
      <?php foreach ($objects['views'] as $v): ?>
        <details>
          <summary style="cursor:pointer;padding:6px 0">
            <b><?= sb_e($v['name']) ?></b>
            <span class="badge b-muted"><?= sb_num(count(sb_columns($pdo, $v['name']))) ?> عمود</span>
            <a class="btn btn-xs" style="margin-inline-start:6px" href="<?= sb_e(sb_url(['view' => 'data', 't' => $v['name'], 'pg' => 1], ['o', 'd', 'q', 'r', 'export', 'blob'])) ?>">البيانات</a>
          </summary>
          <div style="padding:8px 0 14px"><pre class="sql"><?= sb_e($v['sql'] ?? '-- لا يوجد تعريف') ?></pre></div>
        </details>
      <?php endforeach; ?>
      <?php if (count($objects['views']) === 0): ?><p class="empty" style="padding:8px">لا توجد عروض.</p><?php endif; ?>
    </div>

    <div class="card">
      <div class="card-h">الفهارس — Indexes (<?= count($objects['indexes']) ?>)</div>
      <?php if (count($objects['indexes']) === 0): ?><p class="empty" style="padding:8px">لا توجد فهارس.</p><?php else: ?>
        <table class="kv">
          <?php foreach ($objects['indexes'] as $ix): ?>
            <tr>
              <th class="mono" style="direction:ltr"><?= sb_e($ix['name']) ?></th>
              <td>
                <span class="badge b-muted">على <?= sb_e($ix['tbl_name'] ?? '') ?></span>
                <?php if ($ix['sql']): ?><pre class="sql" style="margin-top:6px"><?= sb_e($ix['sql']) ?></pre><?php else: ?><span style="color:var(--dim)">فهرس تلقائي (قيود)</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-h">المشغّلات — Triggers (<?= count($objects['triggers']) ?>)</div>
      <?php if (count($objects['triggers']) === 0): ?><p class="empty" style="padding:8px">لا توجد مشغّلات.</p><?php else: ?>
        <?php foreach ($objects['triggers'] as $tr): ?>
          <details>
            <summary style="cursor:pointer;padding:6px 0"><b><?= sb_e($tr['name']) ?></b>
              <span class="badge b-muted">على <?= sb_e($tr['tbl_name'] ?? '') ?></span></summary>
            <pre class="sql" style="margin:6px 0 12px"><?= sb_e($tr['sql'] ?? '') ?></pre>
          </details>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php
}

function sb_view_info(array $ctx): void
{
    $pdo = $ctx['pdo'];
    $path = $ctx['db']['path'];
    $info = sb_db_info($pdo, $path);
    $doIntegrity = sb_param('integrity') === '1';
    ?>
    <h2>معلومات القاعدة</h2>

    <?php if (!empty($ctx['snapshot'])): ?>
      <?= sb_alert('الاتصال يعمل بوضع "لقطة ثابتة" (immutable) لأن الفتح الصارم للقراءة فقط لم ينجح. لا يعدّل هذا الوضع أي بيانات.', 'warn') ?>
    <?php endif; ?>

    <div class="card">
      <div class="card-h">الملف والاتصال</div>
      <table class="kv">
        <tr><th>المسار الكامل</th><td><span class="mono" style="direction:ltr"><?= sb_e($path) ?></span></td></tr>
        <tr><th>المسار النسبي</th><td><span class="mono" style="direction:ltr"><?= sb_e($ctx['db']['rel']) ?></span></td></tr>
        <tr><th>الحجم</th><td><?= sb_e(sb_size((int) $ctx['db']['size'])) ?> <span style="color:var(--dim)">(<?= sb_num($ctx['db']['size']) ?> بايت)</span></td></tr>
        <tr><th>آخر تعديل</th><td><?= sb_e(sb_date((int) $ctx['db']['mtime'])) ?></td></tr>
        <tr><th>قابل للقراءة</th><td><?= @is_readable($path) ? sb_badge('نعم', 'ok') : sb_badge('لا', 'err') ?></td></tr>
        <tr><th>قابل للكتابة (للسيرفر)</th><td><?= @is_writable($path) ? sb_badge('نعم — لكن الأداة تفتحه للقراءة فقط', 'warn') : sb_badge('لا', 'ok') ?></td></tr>
        <tr><th>ملفات WAL مرافقة</th><td>
          <?php
          $companions = [];
          foreach (['-wal', '-shm', '-journal'] as $suffix) {
              if (@file_exists($path . $suffix)) {
                  $companions[] = $suffix . ' (' . sb_size((int) @filesize($path . $suffix)) . ')';
              }
          }
          echo $companions ? '<span class="mono">' . sb_e(implode(' ، ', $companions)) . '</span>' : '<span style="color:var(--dim)">لا يوجد</span>';
          ?>
        </td></tr>
        <tr><th>طريقة الفتح</th><td><span class="mono"><?= sb_e($GLOBALS['__sb_pdo_mode'] ?? '—') ?></span></td></tr>
        <tr><th>query_only</th><td><?= sb_pragma_flag($pdo, 'query_only') === 1 ? sb_badge('ON', 'ok') : sb_badge('OFF', 'err') ?></td></tr>
      </table>
      <?php if (@file_exists($path . '-shm') || @file_exists($path . '-wal')): ?>
        <div class="alert alert-info" style="margin:12px 0 0">
          <b>معلومة عن وضع WAL:</b> قراءة قاعدة بوضع WAL قد تُحدّث الطابع الزمني لملف
          <code>-shm</code> (فهرس الذاكرة المشترك المتطاير)، وهو سلوك SQLite مع أي قارئ.
          أما ملف القاعدة وملف <code>-wal</code> فلا يتغيّر فيهما أي بايت —
          ويمكن التحقق من ذلك بمقارنة بصمة SHA-256 قبل القراءة وبعدها.
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-h">إعدادات القاعدة (PRAGMA — للقراءة)</div>
      <table class="kv">
        <?php foreach ($info as $key => $item):
            if (strpos((string) $key, '__') === 0) { continue; } ?>
          <tr><th><?= sb_e($item['label']) ?> <span class="mono" style="color:var(--dim)"><?= sb_e((string) $key) ?></span></th>
              <td class="mono"><?= sb_e(sb_text($item['value'])) ?></td></tr>
        <?php endforeach; ?>
        <tr><th>إصدار SQLite</th><td class="mono"><?= sb_e(sb_text($info['__sqlite']['value'])) ?></td></tr>
      </table>
    </div>

    <div class="card">
      <div class="card-h">فحص السلامة
        <span class="sp"></span>
        <?php if (!$doIntegrity): ?>
          <a class="btn btn-xs" href="<?= sb_e(sb_url(['integrity' => 1])) ?>">تشغيل integrity_check</a>
          <a class="btn btn-xs" href="<?= sb_e(sb_url(['integrity' => '2'])) ?>">quick_check (أسرع)</a>
        <?php endif; ?>
      </div>
      <?php if ($doIntegrity):
          $mode = sb_param('integrity') === '2' ? 'quick_check' : 'integrity_check';
          $started = microtime(true);
          $result = [];
          $error = null;
          try {
              $result = $pdo->query('PRAGMA ' . $mode)->fetchAll(PDO::FETCH_COLUMN);
          } catch (Throwable $e) {
              $error = sb_db_error_label($e);
          }
          $elapsed = round(microtime(true) - $started, 2);
      ?>
        <?php if ($error !== null): ?>
          <?= sb_alert($error, 'err') ?>
        <?php else: ?>
          <?= sb_alert($result === ['ok'] || (count($result) === 1 && strtolower((string) $result[0]) === 'ok')
              ? 'القاعدة سليمة — لا توجد أخطاء في البنية (' . $elapsed . ' ثانية).'
              : 'نتيجة الفحص تحتوي على ملاحظات (' . count($result) . ' سطر).', $result === ['ok'] || (count($result) === 1 && strtolower((string) $result[0]) === 'ok') ? 'ok' : 'err') ?>
          <?php if (count($result) > 1): ?>
            <pre class="sql"><?= sb_e(implode("\n", array_slice(array_map('strval', $result), 0, 60))) ?></pre>
          <?php endif; ?>
        <?php endif; ?>
        <a class="btn btn-xs" href="<?= sb_e(sb_url([], ['integrity'])) ?>">إخفاء النتيجة</a>
      <?php else: ?>
        <p style="color:var(--muted);margin:0">الفحص يقرأ كل صفحات القاعدة للتأكد من سلامتها. قد يستغرق وقتاً في القواعد الكبيرة، لذا يُشغَّل عند الطلب فقط.</p>
      <?php endif; ?>
    </div>

    <details class="card">
      <summary class="card-h" style="cursor:pointer;margin-bottom:0">خيارات ترجمة SQLite (compile_options)</summary>
      <div style="margin-top:10px">
        <?php
        $options = [];
        try {
            $options = $pdo->query('PRAGMA compile_options')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $options = [];
        }
        ?>
        <?php if (count($options) === 0): ?>
          <p style="color:var(--muted)">غير متاحة.</p>
        <?php else: ?>
          <div class="row tight">
            <?php foreach ($options as $opt): ?><span class="badge b-muted mono"><?= sb_e((string) $opt) ?></span><?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </details>
    <?php
}

/** أسماء أعمدة نتيجة استعلام (محاولة عبر getColumnMeta ثم الصف الأول). */
function sb_statement_columns(PDOStatement $st, array $firstRow = []): array
{
    $names = [];
    try {
        $count = $st->columnCount();
        for ($i = 0; $i < $count; $i++) {
            $meta = @$st->getColumnMeta($i);
            if (is_array($meta) && isset($meta['name']) && $meta['name'] !== '') {
                $names[] = (string) $meta['name'];
            }
        }
    } catch (Throwable $e) {
        $names = [];
    }
    if (count($names) === 0 && count($firstRow) > 0) {
        $names = array_keys($firstRow);
    }
    return $names;
}

function sb_view_sql(array $ctx): void
{
    if (!SB_ALLOW_SQL_CONSOLE) {
        echo sb_alert('لوحة الاستعلام معطّلة في الإعدادات (SB_ALLOW_SQL_CONSOLE).', 'info');
        return;
    }
    $pdo = $ctx['pdo'];
    $sql = sb_param('sqlq');
    $run = sb_param('runsql') === '1' && $sql !== '';
    $error = null;
    $rows = [];
    $columns = [];
    $elapsed = null;
    $affectedInfo = null;

    if ($run) {
        $error = sb_check_readonly_sql($sql);
        if ($error === null) {
            $started = microtime(true);
            try {
                // ملاحظة أمنية: نستخدم prepare/execute وليس exec، لأن exec
                // في pdo_sqlite قد يُنفّذ أكثر من جملة، بينما prepare يتجاهل ما بعد الأولى.
                $st = $pdo->prepare($sql);
                $st->execute();
                $first = $st->fetch(PDO::FETCH_ASSOC);
                $columns = sb_statement_columns($st, is_array($first) ? $first : []);
                if (is_array($first)) {
                    $rows[] = $first;
                }
                while (count($rows) < 2000) {
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if ($row === false) {
                        break;
                    }
                    $rows[] = $row;
                }
                $elapsed = round((microtime(true) - $started) * 1000, 1);
                if (count($rows) >= 2000) {
                    $affectedInfo = 'تم إيقاف العرض عند 2000 صف — صدّر النتيجة للحصول على كل الصفوف.';
                }
            } catch (Throwable $e) {
                $error = sb_db_error_label($e);
            }
        }
    }

    $examples = [
        'SELECT name FROM sqlite_master WHERE type = \'table\'',
        'SELECT COUNT(*) AS n FROM ' . (isset($ctx['objects']['tables'][0]['name']) ? sb_quote_id($ctx['objects']['tables'][0]['name']) : 'users'),
        'PRAGMA table_info(' . (isset($ctx['objects']['tables'][0]['name']) ? sb_quote_id($ctx['objects']['tables'][0]['name']) : 'users') . ')',
        'SELECT * FROM ' . (isset($ctx['objects']['tables'][0]['name']) ? sb_quote_id($ctx['objects']['tables'][0]['name']) : 'users') . ' LIMIT 20',
    ];
    ?>
    <h2>استعلام للقراءة فقط</h2>
    <p class="lead">
      يُسمح فقط بجُمل <code>SELECT</code> و<code>WITH</code> و<code>EXPLAIN</code> و<code>PRAGMA</code> للقراءة.
      أي أمر كتابة (INSERT/UPDATE/DELETE/DROP/ATTACH/…) مرفوض، والاتصال نفسه مفتوح للقراءة فقط على مستوى الملف.
    </p>

    <form class="card" method="get" action="<?= sb_e(sb_script()) ?>">
      <?php foreach ($_GET as $k => $v):
          if (is_array($v) || in_array((string) $k, ['sqlq', 'runsql', 'export'], true)) {
              continue;
          } ?>
        <input type="hidden" name="<?= sb_e((string) $k) ?>" value="<?= sb_e((string) $v) ?>">
      <?php endforeach; ?>
      <input type="hidden" name="runsql" value="1">
      <label class="lbl" for="sqlq">اكتب استعلام القراءة هنا (جملة واحدة، بحد أقصى 2000 حرف)</label>
      <textarea id="sqlq" name="sqlq" rows="6" maxlength="2000" spellcheck="false" placeholder="SELECT * FROM users LIMIT 50"><?= sb_e($sql) ?></textarea>
      <div class="row" style="margin-top:10px">
        <button class="btn btn-primary" type="submit">▶ تنفيذ (للقراءة)</button>
        <?php if ($run && $error === null && count($rows) > 0): ?>
          <a class="btn" href="<?= sb_e(sb_url(['export' => 'csv'], ['r', 'blob', 'integrity', 'rescan', 'pg'])) ?>">⬇ تصدير النتيجة CSV</a>
          <a class="btn" href="<?= sb_e(sb_url(['export' => 'json'], ['r', 'blob', 'integrity', 'rescan', 'pg'])) ?>">⬇ JSON</a>
        <?php endif; ?>
        <span class="spacer"></span>
        <?php if ($elapsed !== null): ?><span class="badge b-ok">زمن التنفيذ: <?= sb_e((string) $elapsed) ?> مللي ثانية</span><?php endif; ?>
      </div>
      <div class="row tight" style="margin-top:10px">
        <span style="color:var(--dim);font-size:11.5px">أمثلة:</span>
        <?php foreach ($examples as $ex): ?>
          <a class="btn btn-xs" href="<?= sb_e(sb_url(['sqlq' => $ex, 'runsql' => 1], ['view', 't', 'export', 'r', 'blob'])) ?>">تنفيذ</a>
        <?php endforeach; ?>
      </div>
      <?php foreach ($examples as $i => $ex): ?>
        <code style="display:block;color:var(--dim);font-size:11px;direction:ltr;text-align:left;margin-top:4px"><?= $i + 1 ?>) <?= sb_e($ex) ?></code>
      <?php endforeach; ?>
    </form>

    <?php if ($error !== null): ?>
      <?= sb_alert($error, 'err', 'مرفوض أو فشل:') ?>
    <?php elseif ($run): ?>
      <div class="row" style="margin-bottom:10px">
        <span class="badge b-ok"><?= sb_num(count($rows)) ?> صف</span>
        <span class="badge b-muted"><?= sb_num(count($columns)) ?> عمود</span>
        <?php if ($affectedInfo): ?><?= sb_badge($affectedInfo, 'warn') ?><?php endif; ?>
      </div>
      <?= sb_render_grid($columns, $rows, ['sortable' => false]) ?>
      <?php if (count($rows) === 0): ?>
        <p class="lead" style="margin-top:10px">لم يُعد الاستعلام أي صفوف (أو أنه أمر PRAGMA لا يُخرج نتيجة).</p>
      <?php endif; ?>
    <?php endif;
}

/* ===========================================================================
 * 12) التصدير وتنزيل القيم الثنائية (كلها قراءة فقط)
 * =========================================================================== */

function sb_redirect(string $url): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        @header('Location: ' . $url, true, 302);
    }
    exit;
}

function sb_safe_filename(string $name): string
{
    $name = preg_replace('/[^\x20-\x7e]+/u', '_', $name);
    $name = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) $name);
    $name = trim((string) $name, '_.');
    return $name === '' ? 'export' : substr($name, 0, 90);
}

/** تحويل قيمة للتصدير: NULL ⇒ فارغ، والبيانات الثنائية ⇒ Base64 ببادئة واضحة. */
function sb_export_value($value): string
{
    if ($value === null) {
        return '';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    $text = sb_text($value);
    if (sb_is_binary($text)) {
        return 'base64:' . base64_encode($text);
    }
    return $text;
}

function sb_csv_line(array $fields): string
{
    $out = [];
    foreach ($fields as $field) {
        $field = (string) $field;
        if (strpbrk($field, ",\"\r\n") !== false) {
            $field = '"' . str_replace('"', '""', $field) . '"';
        }
        $out[] = $field;
    }
    return implode(',', $out) . "\r\n";
}

function sb_flush_buffers(): void
{
    // نُنزّل المخازن حتى المستوى الذي بدأ عنده السكربت فقط،
    // حتى لا نُتلف مخازن خارجية (إطار عمل أو اختبار).
    $base = isset($GLOBALS['__sb_ob_base']) ? (int) $GLOBALS['__sb_ob_base'] : 0;
    while (ob_get_level() > $base) {
        if (@ob_end_flush() === false) {
            break;
        }
    }
}

function sb_download_headers(string $filename, string $mime, ?int $length = null): void
{
    sb_flush_buffers();
    sb_send_header('Content-Type: ' . $mime . '; charset=UTF-8');
    sb_send_header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    sb_send_header('Cache-Control: no-store, no-cache, must-revalidate');
    sb_send_header('Pragma: no-cache');
    sb_send_header('X-Content-Type-Options: nosniff');
    if ($length !== null) {
        sb_send_header('Content-Length: ' . $length);
    }
}

/** صف CSV من مصفوفة ترابطية حسب ترتيب الأعمدة. */
function sb_export_row(array $columns, array $row): array
{
    $line = [];
    foreach ($columns as $column) {
        $line[] = sb_export_value(array_key_exists($column, $row) ? $row[$column] : null);
    }
    return $line;
}

/** صف JSON من مصفوفة ترابطية حسب ترتيب الأعمدة. */
function sb_json_row(array $columns, array $row): array
{
    $clean = [];
    foreach ($columns as $column) {
        $clean[$column] = sb_json_value(array_key_exists($column, $row) ? $row[$column] : null);
    }
    return $clean;
}

/**
 * بثّ نتائج استعلام إلى CSV أو JSON صفّاً بصف (بدون تحميلها كلها في الذاكرة).
 * ملاحظة: القيم الثنائية (BLOB) تُصدَّر بصيغة Base64 مع بادئة "base64:".
 */
function sb_stream_result(PDO $pdo, string $sql, array $params, array $columns, string $format, string $basename): void
{
    $isCsv = $format === 'csv';
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
    sb_download_headers(sb_safe_filename($basename) . ($isCsv ? '.csv' : '.json'), $isCsv ? 'text/csv' : 'application/json');

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $first = $st->fetch(PDO::FETCH_ASSOC);
    if (count($columns) === 0) {
        $columns = sb_statement_columns($st, is_array($first) ? $first : []);
    }

    $emitted = 0;
    if ($isCsv) {
        echo "\xEF\xBB\xBF"; // BOM حتى يقرأ Excel العربية بشكل صحيح
        echo sb_csv_line($columns);
        if (is_array($first)) {
            echo sb_csv_line(sb_export_row($columns, $first));
            $emitted++;
        }
        while ($emitted < SB_EXPORT_MAX_ROWS) {
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                break;
            }
            echo sb_csv_line(sb_export_row($columns, $row));
            $emitted++;
            if ($emitted % 500 === 0) {
                @flush();
            }
        }
    } else {
        echo "[\n";
        if (is_array($first)) {
            echo json_encode(sb_json_row($columns, $first), $flags);
            $emitted++;
        }
        while ($emitted < SB_EXPORT_MAX_ROWS) {
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                break;
            }
            echo ($emitted > 0 ? ",\n" : '') . json_encode(sb_json_row($columns, $row), $flags);
            $emitted++;
            if ($emitted % 500 === 0) {
                @flush();
            }
        }
        echo $emitted > 0 ? "\n]\n" : "]\n";
    }
    @flush();
}

/** قيمة JSON مع تمييز البيانات الثنائية. */
function sb_json_value($value)
{
    if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
        return $value;
    }
    $text = sb_text($value);
    if (sb_is_binary($text)) {
        return ['__blob_base64' => base64_encode($text), '__bytes' => strlen($text)];
    }
    return $text;
}

/** معالجة طلب التصدير. تُرجع true إن تم التعامل معه. */
function sb_handle_export(array $ctx): bool
{
    $format = sb_param('export');
    if ($format !== 'csv' && $format !== 'json') {
        return false;
    }
    if ($ctx['pdo'] === null) {
        return false;
    }
    $pdo = $ctx['pdo'];
    $dbBase = pathinfo($ctx['db']['rel'], PATHINFO_FILENAME);

    // تصدير نتيجة استعلام المستخدم
    if (SB_ALLOW_SQL_CONSOLE && sb_param('sqlq') !== '' && (sb_param('view') === 'sql' || $ctx['table'] === '')) {
        $sql = sb_param('sqlq');
        $problem = sb_check_readonly_sql($sql);
        if ($problem !== null) {
            sb_layout_open($ctx);
            echo sb_alert($problem, 'err', 'لا يمكن التصدير:');
            sb_view_sql($ctx);
            sb_layout_close();
            return true;
        }
        sb_stream_result($pdo, $sql, [], [], $format, $dbBase . '_query');
        return true;
    }

    // تصدير جدول/عرض مع مراعاة البحث والترتيب الحاليين
    $table = $ctx['table'];
    if ($table === '' || !sb_object_exists($pdo, $table)) {
        return false;
    }
    $cols = sb_columns($pdo, $table);
    $names = array_column($cols, 'name');
    $built = sb_build_select(
        $table,
        $cols,
        false,                       // بدون rowid في التصدير
        sb_param('o') !== '' ? sb_param('o') : null,
        sb_param('d', 'asc'),
        sb_param('q'),
        SB_EXPORT_MAX_ROWS,
        0
    );
    sb_stream_result($pdo, $built['sql'], $built['params'], $names, $format, $dbBase . '_' . $table);
    return true;
}

/** تنزيل قيمة عمود ثنائية (BLOB) لصف معيّن. */
function sb_handle_blob(array $ctx): bool
{
    $column = sb_param('blob');
    if ($column === '' || $ctx['pdo'] === null || $ctx['table'] === '') {
        return false;
    }
    $pdo = $ctx['pdo'];
    $table = $ctx['table'];
    if (!sb_object_exists($pdo, $table)) {
        return false;
    }
    $cols = sb_columns($pdo, $table);
    $valid = false;
    foreach ($cols as $c) {
        if ($c['name'] === $column) {
            $valid = true;
            break;
        }
    }
    if (!$valid) {
        return false;
    }

    $index = max(0, sb_param_int('r', 0));
    $built = sb_build_select($table, $cols, sb_has_rowid($pdo, $table), sb_param('o') !== '' ? sb_param('o') : null, sb_param('d', 'asc'), sb_param('q'), 1, $index);
    try {
        $st = $pdo->prepare($built['sql']);
        $st->execute($built['params']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = false;
    }
    if (!is_array($row) || !array_key_exists($column, $row) || $row[$column] === null) {
        sb_layout_open($ctx);
        echo sb_alert('لا توجد قيمة ثنائية لهذا العمود في الصف المطلوب.', 'warn', 'تنبيه:');
        sb_layout_close();
        return true;
    }

    $value = $row[$column];
    $length = strlen(sb_text($value));
    if ($length > SB_BLOB_MAX_BYTES) {
        sb_layout_open($ctx);
        echo sb_alert('حجم القيمة (' . sb_size($length) . ') يتجاوز الحد المسموح للتنزيل (' . sb_size(SB_BLOB_MAX_BYTES) . ').', 'warn');
        sb_layout_close();
        return true;
    }

    $ext = sb_is_binary($value) ? 'bin' : 'txt';
    $filename = sb_safe_filename($table . '_row' . ($index + 1) . '_' . $column) . '.' . $ext;
    sb_download_headers($filename, sb_is_binary($value) ? 'application/octet-stream' : 'text/plain', $length);
    echo sb_text($value);
    @flush();
    return true;
}

/* ===========================================================================
 * 13) صفحة الدخول
 * =========================================================================== */

function sb_render_login(?string $error = null): void
{
    $error = $error ?? ($GLOBALS['__sb_login_error'] ?? null);
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>دخول — متصفح SQLite</title>
<style><?= sb_css() ?></style>
</head>
<body>
<div class="login-wrap">
  <form class="login" method="post" action="<?= sb_e(sb_url([], ['logout'])) ?>">
    <h1>🔒 متصفح SQLite</h1>
    <p>أداة للقراءة فقط لتصفّح قواعد بيانات SQLite. أدخل كلمة المرور للمتابعة.</p>
    <?php if ($error): ?>
      <div class="alert alert-err"><?= sb_e($error) ?></div>
    <?php endif; ?>
    <label class="lbl" for="password">كلمة المرور</label>
    <input type="password" id="password" name="password" autofocus autocomplete="current-password" required>
    <input type="hidden" name="sb_action" value="login">
    <div style="height:14px"></div>
    <button class="btn btn-primary" type="submit">دخول</button>
  </form>
</div>
</body>
</html>
    <?php
}

/* ===========================================================================
 * 14) الموجّه الرئيسي (Web)
 * =========================================================================== */

function sb_security_headers(): void
{
    sb_send_header('X-Content-Type-Options: nosniff');
    sb_send_header('X-Frame-Options: DENY');
    sb_send_header('Referrer-Policy: no-referrer');
    sb_send_header('X-Robots-Tag: noindex, nofollow');
    sb_send_header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
}

function sb_sqlite_available(): bool
{
    return class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
}

function sb_empty_scan(): array
{
    return [
        'dbs' => [], 'errors' => [],
        'stats' => ['files' => 0, 'dirs' => 0, 'probes' => 0, 'roots' => sb_real_roots()],
        'cached' => false, 'age' => 0, 'time' => 0.0, 'truncated' => false,
    ];
}

function sb_main(): void
{
    if (PHP_SAPI === 'cli') {
        exit(sb_cli(isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : []));
    }

    // ضمان ترميز UTF-8 حتى لو كان إعداد default_charset مختلفاً على الاستضافة
    @ini_set('default_charset', 'UTF-8');
    sb_send_header('Content-Type: text/html; charset=UTF-8');

    sb_security_headers();
    $GLOBALS['__sb_ob_base'] = ob_get_level();

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    // الدخول والخروج (قبل أي إخراج حتى نستطيع ضبط الكوكيز)
    if ($method === 'POST' && sb_auth_enabled() && (($_POST['sb_action'] ?? '') === 'login')) {
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        if (hash_equals(SB_ACCESS_PASSWORD, $password)) {
            sb_set_cookie('sb_auth', sb_auth_value(), 60 * 60 * 12);
            sb_redirect(sb_url([], ['logout']));
        }
        $GLOBALS['__sb_login_error'] = 'كلمة المرور غير صحيحة.';
    }
    if (isset($_GET['logout'])) {
        sb_set_cookie('sb_auth', '', 0);
        sb_redirect(sb_url([], ['logout']));
    }
    if (!sb_is_authenticated()) {
        sb_render_login();
        return;
    }

    $driverOk = sb_sqlite_available();
    $scan = $driverOk ? sb_scan(isset($_GET['rescan'])) : sb_empty_scan();

    $ctx = [
        'scan' => $scan,
        'db' => null,
        'pdo' => null,
        'error' => null,
        'view' => 'home',
        'table' => '',
        'snapshot' => false,
        'objects' => null,
    ];

    if (!$driverOk) {
        $ctx['error'] = 'امتداد pdo_sqlite غير مثبّت في PHP، فلا يمكن قراءة قواعد SQLite.';
    }

    // تحديد القاعدة المطلوبة عبر معرّف موقّع
    $token = sb_param('db');
    if ($token !== '' && $driverOk) {
        $path = sb_token_path($token);
        if ($path === null) {
            $ctx['error'] = 'معرّف القاعدة غير صالح (توقيع غير مطابق). اختر قاعدة من القائمة الجانبية.';
        } else {
            foreach ($scan['dbs'] as $entry) {
                if ($entry['path'] === $path) {
                    $ctx['db'] = $entry;
                    break;
                }
            }
            if ($ctx['db'] === null) {
                $real = realpath($path);
                if ($real !== false && sb_within_roots($real)) {
                    $ctx['db'] = [
                        'path' => $real,
                        'rel' => sb_relative($real),
                        'id' => sb_token($real),
                        'size' => (int) @filesize($real),
                        'mtime' => (int) @filemtime($real),
                        'wal' => sb_db_header($real)['wal'],
                        'wal_files' => @file_exists($real . '-wal'),
                        'page_size' => sb_db_header($real)['page_size'],
                        'status' => 'ok',
                        'objects' => null,
                        'error' => null,
                        'snapshot' => false,
                    ];
                } else {
                    $ctx['error'] = 'القاعدة المطلوبة غير متاحة أو خارج مجلدات البحث المسموح بها.';
                }
            }
            if ($ctx['db'] !== null && $ctx['error'] === null) {
                try {
                    [$pdo] = sb_connect($ctx['db']['path']);
                    $ctx['pdo'] = $pdo;
                    $ctx['snapshot'] = sb_snapshot_mode();
                    $ctx['objects'] = sb_objects($pdo);
                } catch (Throwable $e) {
                    $ctx['error'] = sb_db_error_label($e);
                    $ctx['pdo'] = null;
                }
            }
        }
    }

    // تحديد الواجهة المطلوبة
    $table = sb_param('t');
    $ctx['table'] = $table;
    $view = sb_param('view');
    $allowedViews = ['overview', 'tables', 'data', 'row', 'schema', 'info', 'sql'];
    if ($ctx['pdo'] === null) {
        $view = 'home';
    } elseif ($view === '' || !in_array($view, $allowedViews, true)) {
        $view = $table !== '' ? 'data' : 'overview';
    }
    if ($view === 'data' && $table === '') {
        $view = 'tables';
    }
    if ($view === 'row' && $table === '') {
        $view = 'tables';
    }
    if ($view === 'sql' && !SB_ALLOW_SQL_CONSOLE) {
        $view = 'overview';
    }
    $ctx['view'] = $view;

    // مخرجات خاصة (تنزيل/تصدير) تُنفّذ قبل إخراج أي HTML
    if ($ctx['pdo'] !== null) {
        if (sb_handle_export($ctx)) {
            return;
        }
        if (sb_handle_blob($ctx)) {
            return;
        }
    }

    sb_layout_open($ctx);
    if ($ctx['error'] !== null) {
        echo sb_alert($ctx['error'], 'err', 'خطأ:');
    }
    switch ($view) {
        case 'overview':
            sb_view_overview($ctx);
            break;
        case 'tables':
            sb_view_tables($ctx);
            break;
        case 'data':
            sb_view_data($ctx);
            break;
        case 'row':
            sb_view_row($ctx);
            break;
        case 'schema':
            sb_view_schema($ctx);
            break;
        case 'info':
            sb_view_info($ctx);
            break;
        case 'sql':
            sb_view_sql($ctx);
            break;
        default:
            sb_view_home($ctx);
    }
    sb_layout_close();
}

/* ===========================================================================
 * 15) وضع سطر الأوامر (CLI)
 * =========================================================================== */

/** مجرى الأخطاء في وضع الطرفية (STDERR غير معرّف خارج CLI). */
function sb_stderr()
{
    if (defined('STDERR')) {
        return STDERR;
    }
    $handle = @fopen('php://stderr', 'wb');
    return $handle === false ? @fopen('php://output', 'wb') : $handle;
}

function sb_cli_usage(): string
{
    return <<<TXT
متصفح SQLite — للقراءة فقط (وضع سطر الأوامر)

الاستخدام:
  php sqlite-browser.php --list
  php sqlite-browser.php --info    <مسار-القاعدة>
  php sqlite-browser.php --tables  <مسار-القاعدة>
  php sqlite-browser.php --schema  <مسار-القاعدة> [اسم-الجدول]
  php sqlite-browser.php --rows    <مسار-القاعدة> <الجدول> [--limit=N | --limit N] [--search=نص] [--order=عمود] [--desc]
  php sqlite-browser.php --sql     <مسار-القاعدة> "SELECT ..."
  php sqlite-browser.php --count   <مسار-القاعدة> <الجدول>

ملاحظات:
  • المسار يجب أن يكون داخل مجلدات البحث المعرفة في SB_SCAN_ROOTS (افتراضياً مجلد السكربت).
  • كل الأوامر للقراءة فقط؛ لا يمكن تنفيذ أي أمر كتابة.
  • بدون وسائط يُشغَّل السكربت كصفحة ويب (CLI هنا يحتاج وسيطاً واحداً على الأقل).

TXT;
}

/** تحويل وسيط مسار من CLI إلى مسار مطلق والتحقق منه. */
function sb_cli_resolve(string $arg): string
{
    $path = $arg;
    if ($path === '') {
        throw new RuntimeException('لم يُحدَّد مسار قاعدة البيانات.');
    }
    $isAbsolute = preg_match('#^([a-zA-Z]:[\\\\/]|/|\\\\\\\\)#', $path) === 1;
    if (!$isAbsolute) {
        $path = rtrim((string) getcwd(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }
    $real = realpath($path);
    if ($real === false) {
        throw new RuntimeException('الملف غير موجود: ' . $arg);
    }
    if (!sb_within_roots($real)) {
        throw new RuntimeException('المسار خارج مجلدات البحث المسموح بها (SB_SCAN_ROOTS): ' . $real);
    }
    return $real;
}

/** تنسيق قيمة للعرض في الطرفية. */
function sb_cli_cell($value, int $width = 40): string
{
    if ($value === null) {
        return 'NULL';
    }
    $text = sb_text($value);
    if (sb_is_binary($text)) {
        return '[BLOB ' . strlen($text) . ' bytes]';
    }
    [$short, $cut] = sb_truncate(preg_replace('/[\r\n\t]+/u', ' ', $text) ?? $text, $width);
    return $short . ($cut ? '…' : '');
}

function sb_cli(array $argv): int
{
    $args = array_values(array_slice($argv, 1));
    if (count($args) === 0) {
        echo sb_cli_usage();
        return 1;
    }
    $cmd = $args[0];
    $rest = array_slice($args, 1);

    if (!sb_sqlite_available()) {
        fwrite(sb_stderr(), "خطأ: امتداد pdo_sqlite غير متوفر في PHP.\n");
        return 2;
    }

    try {
        switch ($cmd) {
            case '-h':
            case '--help':
                echo sb_cli_usage();
                return 0;

            case '--list':
                $scan = sb_scan(in_array('--fresh', $rest, true));
                if (count($scan['dbs']) === 0) {
                    echo "لم يُعثر على قواعد بيانات SQLite في مجلدات البحث.\n";
                    foreach ($scan['errors'] as $e) {
                        echo "  تنبيه: " . $e['path'] . ' — ' . $e['error'] . "\n";
                    }
                    return 0;
                }
                printf("%-3s %-62s %10s %8s %s\n", '#', 'المسار النسبي', 'الحجم', 'الكائنات', 'الحالة');
                echo str_repeat('-', 100) . "\n";
                foreach ($scan['dbs'] as $i => $db) {
                    printf(
                        "%-3d %-62s %10s %8s %s\n",
                        $i + 1,
                        mb_strimwidth($db['rel'], 0, 62, '…', 'UTF-8'),
                        sb_size((int) $db['size']),
                        $db['objects'] === null ? '—' : (string) $db['objects'],
                        $db['status'] === 'ok' ? ($db['wal'] ? 'سليمة (WAL)' : 'سليمة') : ('خطأ: ' . $db['error'])
                    );
                }
                echo "\nفُحص {$scan['stats']['files']} ملف في {$scan['stats']['dirs']} مجلد خلال {$scan['time']} ثانية.\n";
                return 0;

            case '--info':
            case '--tables':
            case '--schema':
            case '--rows':
            case '--sql':
            case '--count':
                if (count($rest) === 0) {
                    fwrite(sb_stderr(), "خطأ: هذا الأمر يحتاج مسار قاعدة البيانات.\n\n" . sb_cli_usage());
                    return 1;
                }
                $path = sb_cli_resolve((string) $rest[0]);
                [$pdo] = sb_connect($path);
                echo "# القاعدة: $path\n";
                echo '# وضع الاتصال: ' . ($GLOBALS['__sb_pdo_mode'] ?? '?') . (sb_snapshot_mode() ? ' (لقطة ثابتة)' : '') . "\n";

                if ($cmd === '--info') {
                    foreach (sb_db_info($pdo, $path) as $key => $item) {
                        if (strpos((string) $key, '__') === 0) {
                            continue;
                        }
                        printf("  %-28s %s\n", $item['label'] . " ($key)", sb_text($item['value']));
                    }
                    $objects = sb_objects($pdo);
                    printf("  %-28s %d\n", 'الجداول', count($objects['tables']));
                    printf("  %-28s %d\n", 'العروض', count($objects['views']));
                    printf("  %-28s %d\n", 'الفهارس', count($objects['indexes']));
                    printf("  %-28s %d\n", 'المشغّلات', count($objects['triggers']));
                    return 0;
                }

                $objects = sb_objects($pdo);

                if ($cmd === '--tables') {
                    $names = array_merge(array_column($objects['tables'], 'name'), array_column($objects['views'], 'name'));
                    $counts = sb_counts($pdo, $names);
                    foreach ($objects['tables'] as $t) {
                        $n = $counts['counts'][$t['name']] ?? null;
                        printf("  [جدول] %-40s %6s أعمدة %10s صف%s\n", $t['name'], (string) count(sb_columns($pdo, $t['name'])), $n === null ? '—' : (string) $n, $t['virtual'] ? '  (افتراضي)' : '');
                    }
                    foreach ($objects['views'] as $v) {
                        printf("  [عرض ] %-40s %6s أعمدة %10s صف\n", $v['name'], (string) count(sb_columns($pdo, $v['name'])), (string) ($counts['counts'][$v['name']] ?? '—'));
                    }
                    return 0;
                }

                if ($cmd === '--schema') {
                    $filter = isset($rest[1]) ? (string) $rest[1] : '';
                    foreach (array_merge($objects['tables'], $objects['views'], $objects['indexes'], $objects['triggers']) as $o) {
                        if ($filter !== '' && $o['name'] !== $filter) {
                            continue;
                        }
                        echo "-- [{$o['type']}] {$o['name']}\n";
                        echo ($o['sql'] ?? '-- لا يوجد تعريف') . ";\n\n";
                        if ($o['type'] === 'table' || $o['type'] === 'view') {
                            foreach (sb_columns($pdo, $o['name']) as $c) {
                                printf("     %-28s %-16s %s%s\n", $c['name'], $c['type'] === '' ? 'ANY' : $c['type'], $c['notnull'] ? 'NOT NULL ' : '         ', $c['pk'] ? 'PK' : '');
                            }
                            echo "\n";
                        }
                    }
                    return 0;
                }

                if ($cmd === '--count') {
                    $table = (string) ($rest[1] ?? '');
                    if ($table === '' || !sb_object_exists($pdo, $table)) {
                        fwrite(sb_stderr(), "خطأ: الجدول غير موجود.\n");
                        return 1;
                    }
                    $n = sb_count_rows($pdo, $table);
                    echo ($n === null ? 'تعذّر الحساب' : (string) $n) . "\n";
                    return 0;
                }

                if ($cmd === '--sql') {
                    $sql = (string) ($rest[1] ?? '');
                    $problem = sb_check_readonly_sql($sql);
                    if ($problem !== null) {
                        fwrite(sb_stderr(), "مرفوض: $problem\n");
                        return 3;
                    }
                    $st = $pdo->prepare($sql);
                    $st->execute();
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                    if (count($rows) === 0) {
                        echo "(لا توجد صفوف)\n";
                        return 0;
                    }
                    $columns = array_keys($rows[0]);
                    echo implode("\t", $columns) . "\n";
                    foreach ($rows as $row) {
                        $line = [];
                        foreach ($columns as $c) {
                            $line[] = sb_cli_cell($row[$c] ?? null, 60);
                        }
                        echo implode("\t", $line) . "\n";
                    }
                    echo '(' . count($rows) . " صف)\n";
                    return 0;
                }

                // --rows
                $table = (string) ($rest[1] ?? '');
                if ($table === '' || !sb_object_exists($pdo, $table)) {
                    fwrite(sb_stderr(), "خطأ: الجدول غير موجود: $table\n");
                    return 1;
                }
                $limit = 25;
                $search = '';
                $order = '';
                $dir = 'asc';
                // تُقبل الصيغتان: --limit=20  و  --limit 20
                $opts = array_slice($rest, 2);
                for ($i = 0, $n = count($opts); $i < $n; $i++) {
                    $opt = (string) $opts[$i];
                    if ($opt === '--desc') {
                        $dir = 'desc';
                        continue;
                    }
                    $key = null;
                    $value = null;
                    if (preg_match('/^--(limit|search|order)=(.*)$/u', $opt, $m)) {
                        $key = $m[1];
                        $value = $m[2];
                    } elseif (in_array($opt, ['--limit', '--search', '--order'], true) && isset($opts[$i + 1])) {
                        $key = substr($opt, 2);
                        $value = (string) $opts[$i + 1];
                        $i++;
                    }
                    if ($key === 'limit') {
                        $limit = max(1, min((int) $value, SB_MAX_PAGE_SIZE));
                    } elseif ($key === 'search') {
                        $search = (string) $value;
                    } elseif ($key === 'order') {
                        $order = (string) $value;
                    }
                }
                $r = sb_prepare_table_query($pdo, $table, $limit, 1, $search, $order !== '' ? $order : null, $dir);
                if ($r['error'] !== null) {
                    fwrite(sb_stderr(), 'خطأ: ' . $r['error'] . "\n");
                    return 1;
                }
                $columns = $r['built']['columns'];
                echo '# إجمالي الصفوف: ' . sb_num($r['total']) . ' · المعروض: ' . count($r['rows']) . "\n";
                echo implode("\t", $columns) . "\n";
                foreach ($r['rows'] as $row) {
                    $line = [];
                    foreach ($columns as $c) {
                        $line[] = sb_cli_cell($row[$c] ?? null, 40);
                    }
                    echo implode("\t", $line) . "\n";
                }
                return 0;
        }

        fwrite(sb_stderr(), "أمر غير معروف: $cmd\n\n" . sb_cli_usage());
        return 1;
    } catch (Throwable $e) {
        fwrite(sb_stderr(), 'خطأ: ' . $e->getMessage() . "\n");
        return 1;
    }
}

/* ===========================================================================
 * 16) نقطة التشغيل
 * =========================================================================== */

if (!defined('SQLITE_BROWSER_NO_RUN')) {
    sb_main();
}
