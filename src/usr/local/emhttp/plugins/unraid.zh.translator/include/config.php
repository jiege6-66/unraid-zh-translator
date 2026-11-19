<?php
/**
 * unraid.zh.translator — 配置读写
 *
 * 配置保存在 U 盘 /boot/config/plugins/unraid.zh.translator/translator.cfg，
 * 格式为 INI，权限 0600（内含 API Key）。
 */
declare(strict_types=1);

namespace UZT;

final class Config
{
    public const FILE = 'translator.cfg';

    /** 默认值 —— 也是 UI 上「恢复默认」的目标 */
    public const DEFAULTS = [
        'ENABLED'        => '1',        // 总开关：是否翻译商店/插件页面
        'SCOPE'          => 'store',    // store | store_plugins | all
        'AUTO_NEW'       => '1',        // 页面上没见过的英文，是否自动翻译
        'SYNC_ON_LOOKUP' => '1',        // lookup 时同步翻译（否则入队等后台）
        'SYNC_MAX_CHARS' => '4000',     // 单次同步翻译的字符上限
        'HOURLY_CHAR_BUDGET' => '120000', // 每小时同步翻译总字符上限（0=不限），超出则只入队
        'TARGET'         => 'zh-CN',
        'API_BASE'       => 'https://api.openai.com/v1',
        'API_KEY'        => '',
        'MODEL'          => 'gpt-4o-mini',
        'JSON_MODE'      => 'auto',     // auto | on | off
        'TEMPERATURE'    => '0.2',
        'BATCH_ITEMS'    => '48',
        'BATCH_CHARS'    => '6000',
        'TIMEOUT'        => '90',
        'RETRIES'        => '2',
        'WORKER_SECONDS' => '300',      // 单次 worker 运行的时间预算
        'FEED_SOURCE'    => 'auto',     // auto（优先复用 CA 本地缓存）| local | online
        'FEED_URL'       => 'https://assets.ca.unraid.net/feed/applicationFeed.json',
        'FEED_CACHE_TTL' => '21600',    // 6 小时，超过则重新下载
        'CRON'           => '',         // 留空=关闭；例：0 4 * * *
        'SKIP_NAMES'     => '1',        // 不翻译应用名称（品牌名）
        'LIVE_MIN_CHARS' => '40',       // 页面即时采集只收「简介级」长文本（约一句话以上）
        'MAX_TRIES'      => '8',        // 单条片段最多尝试次数（失败按指数退避重排）
        'AUTO_CONTINUE'  => '1',        // 达到时间预算且队列未清空时，自动接着跑
        'MAX_CHAIN'      => '40',       // 自动续跑的最大链长（防止无限自启）
        'PHP_CLI'        => '',         // 留空=自动探测；特殊环境可手填 /usr/bin/php
        'DEBUG'          => '0',
    ];

    private static ?array $cache = null;

    public static function file(): string
    {
        return cfg_dir() . '/' . self::FILE;
    }

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $cfg = self::DEFAULTS;
        $file = self::file();
        if (is_readable($file)) {
            $parsed = @parse_ini_file($file, false, INI_SCANNER_RAW);
            if ($parsed === false) {
                jlog('配置文件解析失败（已使用默认值）: ' . $file, 'WARN');
            }
            if (is_array($parsed)) {
                foreach ($parsed as $k => $v) {
                    if (array_key_exists($k, self::DEFAULTS)) {
                        $cfg[$k] = is_string($v) ? $v : (string)$v;
                    }
                }
            }
        }
        self::$cache = $cfg;
        return $cfg;
    }

    public static function get(string $key, ?string $default = null): string
    {
        $all = self::all();
        return $all[$key] ?? ($default ?? (self::DEFAULTS[$key] ?? ''));
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === '' ? $default : (int)$v;
    }

    public static function bool(string $key): bool
    {
        $v = strtolower(trim(self::get($key)));
        return $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on';
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $v = trim(self::get($key));
        return $v === '' ? $default : (float)$v;
    }

    /** 只接受白名单键，非法值回落默认 */
    public static function save(array $input): array
    {
        $new = self::all();
        $errors = [];
        foreach ($input as $k => $v) {
            if (!array_key_exists($k, self::DEFAULTS)) {
                continue;
            }
            if (!is_scalar($v)) {
                continue;
            }
            $v = (string)$v;
            switch ($k) {
                case 'SCOPE':
                    if (!in_array($v, ['store', 'store_plugins', 'all'], true)) {
                        $v = self::DEFAULTS['SCOPE'];
                    }
                    break;
                case 'FEED_SOURCE':
                    if (!in_array($v, ['auto', 'local', 'online'], true)) {
                        $v = self::DEFAULTS['FEED_SOURCE'];
                    }
                    break;
                case 'JSON_MODE':
                    if (!in_array($v, ['auto', 'on', 'off'], true)) {
                        $v = self::DEFAULTS['JSON_MODE'];
                    }
                    break;
                case 'API_BASE':
                    $v = rtrim(trim($v), '/');
                    if ($v !== '' && !preg_match('#^https?://#i', $v)) {
                        $errors[] = 'API 地址必须以 http:// 或 https:// 开头';
                        $v = self::DEFAULTS['API_BASE'];
                    }
                    break;
                case 'TEMPERATURE':
                    $f = (float)$v;
                    if ($f < 0 || $f > 2) {
                        $errors[] = '温度需在 0~2 之间，已回落 0.2';
                        $f = 0.2;
                    }
                    $v = (string)$f;
                    break;
                case 'BATCH_ITEMS':
                case 'BATCH_CHARS':
                case 'TIMEOUT':
                case 'RETRIES':
                case 'WORKER_SECONDS':
                case 'FEED_CACHE_TTL':
                case 'SYNC_MAX_CHARS':
                case 'HOURLY_CHAR_BUDGET':
                case 'LIVE_MIN_CHARS':
                case 'MAX_TRIES':
                case 'MAX_CHAIN':
                    $i = (int)$v;
                    if ($i < 1) {
                        $i = (int)self::DEFAULTS[$k];
                    }
                    if ($k === 'BATCH_ITEMS' && $i > 64) {
                        $i = 64;
                    }
                    if ($k === 'BATCH_CHARS' && $i > 60000) {
                        $i = 60000;
                    }
                    if ($k === 'TIMEOUT' && $i > 600) {
                        $i = 600;
                    }
                    if ($k === 'HOURLY_CHAR_BUDGET' && $i > 10000000) {
                        $i = 10000000;
                    }
                    $v = (string)$i;
                    break;
                case 'CRON':
                    $v = trim(preg_replace('/\s+/', ' ', $v) ?? '');
                    // 只允许 5 段、且每段只含 0-9 * / , - —— 避免把换行等注入 crontab 文件
                    if ($v !== '' && !preg_match('#^[0-9*,/-]+(?: [0-9*,/-]+){4}$#', $v)) {
                        $errors[] = 'Cron 需为 5 段格式（分 时 日 月 周，只含数字 * , / -），已忽略';
                        $v = '';
                    }
                    break;
                case 'API_KEY':
                    $v = trim($v);
                    break;
                case 'ENABLED':
                case 'AUTO_CONTINUE':
                case 'AUTO_NEW':
                case 'SYNC_ON_LOOKUP':
                case 'SKIP_NAMES':
                case 'DEBUG':
                    $v = ($v === '1' || strtolower($v) === 'true') ? '1' : '0';
                    break;
                default:
                    $v = trim($v);
            }
            $new[$k] = $v;
        }
        if (!self::write($new)) {
            $errors[] = '配置写入失败：' . self::file() . ' 不可写（/boot 只读、已满或权限问题），设置未生效';
        }
        self::$cache = null;
        return $errors;
    }

    public static function write(array $cfg): bool
    {
        $lines = ["; unraid.zh.translator 配置 — 由 WebUI 生成，可直接编辑", '; ' . date('c')];
        foreach (self::DEFAULTS as $k => $def) {
            $v = (string)($cfg[$k] ?? $def);
            $v = str_replace(["\r", "\n"], '', $v);
            $v = str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
            $lines[] = $k . '="' . $v . '"';
        }
        return atomic_write(self::file(), implode("\n", $lines) . "\n", 0600);
    }

    /** 给浏览器的配置视图（打码敏感字段） */
    public static function public_view(): array
    {
        $cfg = self::all();
        $key = $cfg['API_KEY'];
        $masked = '';
        if ($key !== '') {
            $masked = mb_strlen($key) <= 8 ? '****' : mb_substr($key, 0, 4) . '****' . mb_substr($key, -4);
        }
        $cfg['API_KEY'] = $masked;
        $cfg['API_KEY_SET'] = $key !== '' ? '1' : '0';
        $cfg['_version'] = VERSION;
        return $cfg;
    }

    public static function reset(): void
    {
        @unlink(self::file());
        self::$cache = null;
    }

    /** 记住 API Key 是否真的被改过：UI 回传掩码时保留原值 */
    public static function mergeMaskedKey(array $input): array
    {
        if (($input['API_KEY'] ?? '') === '__CLEAR__') {
            $input['API_KEY'] = '';       // 用户勾选「清除已保存的密钥」
            return $input;
        }
        unset($input['__CLEAR__']);
        if (isset($input['API_KEY'])) {
            $v = (string)$input['API_KEY'];
            if ($v === '' || str_contains($v, '****')) {
                unset($input['API_KEY']);   // 留空/掩码 = 不修改
            }
        }
        return $input;
    }
}
