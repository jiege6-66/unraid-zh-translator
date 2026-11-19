<?php
/**
 * unraid.zh.translator — 通用工具
 *
 * 文本规范化在这里定义，必须与 js/store-zh.js 内 uztNorm() 完全一致，
 * 否则浏览器端取到的键会与数据库里的 hash 对不上。
 */
declare(strict_types=1);

namespace UZT;

const PLUGIN   = 'unraid.zh.translator';
const VERSION  = '2026.11.19';

/** 插件在 webGui 中的根目录（tmpfs，随 .plg 安装重建） */
function plugin_dir(): string
{
    static $d = null;
    if ($d === null) {
        $guess = __DIR__ . '/..';
        $d = realpath($guess) ?: $guess;
    }
    return $d;
}

/** 持久化目录（U 盘 /boot，重启后仍在） */
function cfg_dir(): string
{
    return '/boot/config/plugins/' . PLUGIN;
}

/** 运行期目录：优先 RAM，避免折磨 U 盘 */
function var_dir(): string
{
    $d = '/usr/local/emhttp/plugins/' . PLUGIN . '/var';
    if (is_dir($d) && is_writable($d)) {
        return $d;
    }
    $fallback = cfg_dir() . '/var';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0770, true);
    }
    return $fallback;
}

function log_file(): string
{
    return cfg_dir() . '/logs/worker.log';
}

/** 只保留最近 N 行，防止日志把 U 盘写满 */
function log_rotate(string $file, int $maxBytes = 1048576): void
{
    clearstatcache(true, $file);
    if (!is_file($file) || filesize($file) < $maxBytes) {
        return;
    }
    $data = file($file) ?: [];
    $keep = array_slice($data, -2000);
    @file_put_contents($file, implode('', $keep), LOCK_EX);
}

function jlog(string $msg, string $level = 'INFO', bool $echo = false): void
{
    $dir = dirname(log_file());
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    log_rotate(log_file());
    $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $msg);
    @file_put_contents(log_file(), $line, FILE_APPEND | LOCK_EX);
    if ($echo) {
        echo $line;
    }
}

/**
 * 规范化：统一换行 / 折叠行内空白 / 去首尾空白 / 丢弃空行。
 *
 * 空白字符集用**显式码点**定义，不用 trim()/\s —— 否则 PHP 的 trim()（只认 ASCII）
 * 与 JS 的 \s（认全部 Unicode 空白）会算出不同的键，译文永远命中不了。
 * 与 js/uzt-norm.js 的 norm() 必须逐字符一致；tests/norm_parity.mjs 会对照。
 */
const WS_INLINE = '[\t\x0B\f \x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

function normalize_text(string $s): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $out = [];
    foreach (explode("\n", $s) as $line) {
        $line = preg_replace('/' . WS_INLINE . '+/u', ' ', $line) ?? $line;
        $line = preg_replace('/^ +| +$/u', '', $line) ?? $line;
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return implode("\n", $out);
}

function segment_hash(string $normalized): string
{
    return sha1($normalized);
}

function has_cjk(string $s): bool
{
    return preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $s) === 1;
}

function has_letter(string $s): bool
{
    return preg_match('/[A-Za-z]/', $s) === 1;
}

/**
 * 不值得翻译的串：URL、路径、文件名、版本号、环境变量风格常量、标识符/镜像名。
 * 与 js/uzt-norm.js 的 looksUntranslatable() 保持一致。
 */
function looks_untranslatable(string $s): bool
{
    $t = trim($s);
    if ($t === '') {
        return true;
    }
    if (preg_match('#^(https?://|ftp://|//|/[\w./-]*$|[a-z][a-z0-9+.-]*://)#i', $t)) {
        return true;
    }
    if (preg_match('#^[\w./-]+\.(json|xml|ya?ml|js|css|html?|php|sh|png|jpe?g|gif|svg|webp|md|txt|log|db|cfg|ini|plg|txz|tar|gz|zip)$#i', $t)) {
        return true;
    }
    if (preg_match('#^(v?\d+(\.\d+)*[a-z0-9.+-]*|\d+(\.\d+)?\s*(kb|mb|gb|tb|ms|s|min|h|%))$#i', $t)) {
        return true;
    }
    if (preg_match('/^[A-Z0-9]{2,}$/', $t)) {
        return true; // PUID / TZ / AMD64 之类的常量
    }
    // 去掉句末标点后判断："Requires:" / "Change log:" 这类标签应当翻译
    $probe = preg_replace('/[\s:：,，。.；;]+$/u', '', $t) ?: $t;
    if (!preg_match('/\s/', $probe)) {
        // 单词：含标识符字符或数字的按标识符处理；过短的也不值得翻
        if (preg_match('#[_/:.+=]|\d#', $probe)) {
            return true;
        }
        if (mb_strlen($probe, 'UTF-8') < 5) {
            return true;
        }
    }
    return !has_letter($t);
}

/** 是否值得作为一条待译文本：长度合适、含英文、不含中文 */
function is_translatable(string $raw, int $minChars = 5, int $maxChars = 20000): bool
{
    $n = normalize_text($raw);
    $len = mb_strlen($n, 'UTF-8');
    if ($len < $minChars || $len > $maxChars) {
        return false;
    }
    if (has_cjk($n)) {
        return false;
    }
    return !looks_untranslatable($n);
}

function atomic_write(string $file, string $data, int $mode = 0660): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
        return false;
    }
    $tmp = $file . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function json_response(array $payload, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** CSRF 校验：Unraid 在 /var/local/emhttp/var.ini 里提供 csrf_token */
function csrf_token(): string
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $t = '';
    if (is_readable('/var/local/emhttp/var.ini')) {
        $var = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
        $t = (string)($var['csrf_token'] ?? '');
    }
    return $t;
}

function check_csrf(?string $sent): bool
{
    $have = csrf_token();
    if ($have === '') {
        // 读不到 Unraid 的 var.ini 时无法验证，一律拒绝（避免静默放行写操作）
        jlog('CSRF: 无法读取 /var/local/emhttp/var.ini 中的 csrf_token，请求已拒绝', 'WARN');
        return false;
    }
    return is_string($sent) && $sent !== '' && hash_equals($have, $sent);
}

/**
 * 找到真正的 PHP CLI 可执行文件。
 *
 * 关键：php-fpm 进程里的 PHP_BINARY 是 /usr/sbin/php-fpm，直接拿它去跑脚本
 * 只会打印 FPM 的 usage —— 必须探测出真正能执行 PHP 代码的 CLI 二进制。
 */
function php_cli_binary(): string
{
    static $found = null;
    if ($found !== null) {
        return $found;
    }
    $custom = '';
    if (class_exists('\\UZT\\Config')) {
        $custom = trim((string)Config::get('PHP_CLI'));
    }
    $probe = static function (string $bin): ?bool {
        if (!function_exists('shell_exec')) {
            return null;   // 无法探测时只能信任文件名
        }
        $out = @shell_exec(escapeshellarg($bin) . ' -r ' . escapeshellarg('echo PHP_SAPI;') . ' 2>/dev/null');
        if ($out === null) {
            return null;
        }
        return trim((string)$out) === 'cli';
    };
    if ($custom !== '' && is_file($custom) && is_executable($custom)) {
        $okCustom = $probe($custom);
        if ($okCustom === true || $okCustom === null) {
            $found = $custom;
            return $found;
        }
        jlog('手填的 PHP CLI 不能执行 PHP 代码（很像 php-fpm/php-cgi），已改为自动探测: ' . $custom, 'WARN');
    }
    $cands = [];
    if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
        $cands[] = PHP_BINARY;
    }
    $cands[] = '/usr/bin/php';
    if (defined('PHP_BINDIR')) {
        $cands[] = PHP_BINDIR . '/php';
    }
    $cands[] = '/usr/local/bin/php';
    foreach (glob('/usr/bin/php[0-9]*') ?: [] as $g) {
        $cands[] = $g;
    }
    $seen = [];
    foreach ($cands as $c) {
        if (isset($seen[$c]) || !is_file($c) || !is_executable($c)) {
            continue;
        }
        $seen[$c] = true;
        if (preg_match('/php-fpm|php-cgi/i', basename($c))) {
            continue;   // 这两个不是 CLI
        }
        $okBin = $probe($c);
        if ($okBin === null) {
            $found = $c;    // shell_exec 被禁用：只能信任文件名判断
            return $found;
        }
        if ($okBin === true) {
            $found = $c;
            return $found;
        }
    }
    jlog('找不到可用的 PHP CLI（尝试过：' . implode(', ', array_keys($seen)) . '），后台任务无法启动', 'ERROR');
    $found = '';
    return $found;
}

/**
 * 运行 CLI 命令（后台），用于按钮触发 worker。
 *
 * 参数用数组传入并逐个 escapeshellarg —— 不要把外部字符串拼进 shell。
 * 启动后会等 worker 写出第一行日志，确认它真的跑起来了；否则明确报错，
 * 而不是让界面显示"已启动"却什么都没发生。
 *
 * @param array<int,string> $argv
 * @return array{ok:bool,cmd?:string,error?:string,php?:string}
 */
function spawn_worker(array $argv): array
{
    $php = php_cli_binary();
    if ($php === '') {
        return ['ok' => false, 'error' => '找不到 PHP CLI 可执行文件（可在设置里手动填写 PHP CLI 路径）'];
    }
    $worker = plugin_dir() . '/scripts/worker.php';
    if (!is_file($worker)) {
        return ['ok' => false, 'error' => 'worker.php 缺失: ' . $worker, 'php' => $php];
    }
    $logDir = dirname(log_file());
    if (!is_dir($logDir) || !is_writable($logDir)) {
        return ['ok' => false, 'error' => '日志目录不可写: ' . $logDir . '（检查 /boot 是否只读或已满）', 'php' => $php];
    }
    $cmd = implode(' ', array_map('escapeshellarg', array_merge(
        [$php, '-d', 'memory_limit=1024M', '-d', 'display_errors=0', $worker],
        array_values($argv)
    )));
    $cmd .= ' >> ' . escapeshellarg(log_file()) . ' 2>&1 &';

    clearstatcache(true, log_file());
    $before = @filesize(log_file()) ?: 0;
    @exec($cmd, $out, $rc);

    // 等 worker 落第一行日志（最多 2 秒）：确认真的启动成功
    $deadline = microtime(true) + 2.0;
    while (microtime(true) < $deadline) {
        clearstatcache(true, log_file());
        if ((@filesize(log_file()) ?: 0) > $before) {
            return ['ok' => true, 'cmd' => $cmd, 'php' => $php];
        }
        usleep(100000);
    }
    jlog('后台任务未能启动（2 秒内无日志输出）: ' . $cmd, 'ERROR');
    return [
        'ok' => false,
        'cmd' => $cmd,
        'php' => $php,
        'error' => '后台任务未能启动：' . basename($php) . ' 没有产生任何输出，请查看日志或手动执行 php ' . $worker . ' stats',
    ];
}

/** 固定的锁文件路径：不随「谁在跑」变化，避免 web 与 cron 各锁各的 */
function lock_file(): string
{
    $candidates = ['/var/lock/' . PLUGIN . '.lock', sys_get_temp_dir() . '/' . PLUGIN . '.lock'];
    foreach ($candidates as $dir) {
        $d = dirname($dir);
        if (is_dir($d) && is_writable($d)) {
            return $dir;
        }
    }
    return $candidates[0];
}

function human_age(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 90) {
        return $seconds . ' 秒';
    }
    if ($seconds < 5400) {
        return (string)round($seconds / 60) . ' 分钟';
    }
    if ($seconds < 172800) {
        return (string)round($seconds / 3600) . ' 小时';
    }
    return (string)round($seconds / 86400) . ' 天';
}

function human_bytes(int $n): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($n >= 1024 && $i < 3) {
        $n = (int)round($n / 1024);
        $i++;
    }
    return $n . $u[$i];
}
