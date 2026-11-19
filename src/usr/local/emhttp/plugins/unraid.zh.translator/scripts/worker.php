<?php
/**
 * unraid.zh.translator — 命令行 worker
 *
 * 用法：
 *   php worker.php sync [--force]      重新抓取应用源并排队（文案变了 = 自动重译）
 *   php worker.php translate [--seconds=300] [--max=2000]
 *   php worker.php local               只预热已安装 .plg 的描述
 *   php worker.php prune               清理已下架应用的历史词条
 *   php worker.php all                 同步 + 翻译（cron 默认用这个）
 *   php worker.php cron                给 cron 用：源过期就同步，然后把队列翻完
 *   php worker.php stats [--json]
 *   php worker.php test                测试大模型连通性
 */
declare(strict_types=1);

namespace UZT;

ini_set('memory_limit', '1024M');
@set_time_limit(0);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../include/translator.php';

// 任何退出路径都保证 JSON 后端落盘
register_shutdown_function(static function (): void {
    try {
        Store::open()->flush();
    } catch (\Throwable $e) {
        // 退出阶段不再抛错
    }
});

function uzt_cli_opt(string $name, ?string $default = null): ?string
{
    global $argv;
    foreach ($argv ?? [] as $a) {
        if (str_starts_with($a, '--' . $name . '=')) {
            return substr($a, strlen($name) + 3);
        }
        if ($a === '--' . $name) {
            return '1';
        }
    }
    return $default;
}

function uzt_cli_has(string $name): bool
{
    return uzt_cli_opt($name) !== null;
}

$cmd = $argv[1] ?? 'help';
$json = uzt_cli_has('json');

// 进度既写日志又往 stdout 打：stdout 已被 spawn_worker 重定向到同一个日志文件时
// 会产生重复行，所以只在终端里才回显。
$isTty = function_exists('stream_isatty') ? @stream_isatty(STDOUT) : false;
$progress = ($isTty && !$json)
    ? static function (string $m): void {
        echo '  · ' . $m . "\n";
    }
    : null;

// 第一行就落日志：便于设置页确认后台任务真的启动了
jlog(sprintf('worker 启动 pid=%d 命令=%s php=%s',
    getmypid(),
    implode(' ', array_slice($argv, 1)),
    PHP_BINARY !== '' ? PHP_BINARY : '?'), 'INFO', false);

switch ($cmd) {
    case 'sync':
        $res = Translator::sync(uzt_cli_has('force'), $progress,
            uzt_cli_has('no-local') ? false : (Config::get('SCOPE') !== 'store'));
        uzt_emit($res, $json);
        exit($res['ok'] ? 0 : 1);

    case 'local':
        $local = SourceLocal::extract();
        if (!$local['ok']) {
            uzt_emit(['ok' => false, 'error' => $local['error'] ?? '读取失败'], $json);
            exit(1);
        }
        $up = Store::open()->upsertSegments($local['segments']);
        Store::open()->setApps($local['apps']);
        Store::open()->flush();
        uzt_emit(['ok' => true, 'added' => $up['added'], 'existed' => $up['existed'], 'plugins' => count($local['apps'])], $json);

        break;

    case 'translate':
        $res = Translator::translatePending(
            (int)(uzt_cli_opt('seconds', '300') ?? 300),
            (int)(uzt_cli_opt('max', '5000') ?? 5000),
            (int)(uzt_cli_opt('chain', '0') ?? 0)
        );
        uzt_emit($res, $json);
        exit(isset($res['error']) ? 2 : 0);

    case 'all':
        $s = Translator::sync(uzt_cli_has('force'), $progress);
        if (!$s['ok']) {
            uzt_emit($s, $json);
            exit(1);
        }
        $t = Translator::translatePending((int)(uzt_cli_opt('seconds', '600') ?? 600), 20000);
        uzt_emit(['ok' => true, 'sync' => $s, 'translate' => $t], $json);
        break;

    case 'cron':
        $store = Store::open();
        $last = (int)$store->getMeta('synced_at', '0');
        $need = (time() - $last) > max(3600, Config::int('FEED_CACHE_TTL', 21600));
        if ($need) {
            $s = Translator::sync(false, $progress);
            if (!$s['ok']) {
                jlog('cron 同步失败: ' . (string)($s['error'] ?? ''), 'WARN');
            }
        }
        $t = Translator::translatePending(max(60, Config::int('WORKER_SECONDS', 300)), 20000);
        uzt_emit(['ok' => true, 'synced' => $need, 'translate' => $t], $json);
        break;

    case 'compact':
        uzt_emit(Translator::compact(), $json);
        break;

    case 'retry-failed':
        $n = Store::open()->resetFailed();
        Store::open()->flush();
        uzt_emit(['ok' => true, 'reset' => $n], $json);
        break;

    case 'prune':
        uzt_emit(Translator::prune(), $json);
        break;

    case 'test':
        $llm = new Llm();
        uzt_emit($llm->test(), $json);
        break;

    case 'stats':
    default:
        $store = Store::open();
        $payload = [
            'ok' => true,
            'config' => [
                'model' => Config::get('MODEL'),
                'base' => Config::get('API_BASE'),
                'key_set' => Config::get('API_KEY') !== '',
                'enabled' => Config::bool('ENABLED'),
            ],
            'stats' => $store->stats(),
            'estimate' => Translator::estimate(),
            'worker_running' => Translator::workerRunning(),
            'meta' => [
                'synced_at' => $store->getMeta('synced_at'),
                'feed_updated' => $store->getMeta('feed_updated'),
                'feed_apps' => $store->getMeta('feed_apps'),
                'plugins' => $store->getMeta('plugins'),
            ],
            'recent' => $store->recentEvents(10),
        ];
        if ($cmd === 'help' && !$json) {
            echo "unraid.zh.translator worker\n";
            echo "用法: php worker.php <sync|translate|local|prune|all|cron|stats|test|retry-failed|compact> [--json] [--force] [--seconds=N]\n";
            break;
        }
        uzt_emit($payload, true);
        break;
}

function uzt_emit(array $payload, bool $json = true): void
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    echo json_encode($payload, $json ? ($flags | JSON_PRETTY_PRINT) : $flags) . "\n";
}
