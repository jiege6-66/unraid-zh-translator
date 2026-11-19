<?php
/**
 * 核心链路回归（不需要 root、不需要安装到 /usr/local/emhttp）
 *
 * 用「仓库里的源码 + 临时 cfg/var 目录 + 本地假大模型」跑完整后端流程：
 *   同步 → 翻译 → 幂等 → 增量重译 → 长文本分片 → 品牌名 → 退避重试 → 队列精简 → 失败原因
 *
 * 运行：php tests/core.php
 * CI 里也跑这个（见 .github/workflows/ci.yml）。
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pluginDir = getenv('UZT_PLUGIN_DIR') ?: $root . '/src/usr/local/emhttp/plugins/unraid.zh.translator';

$tmp = sys_get_temp_dir() . '/uzt-core-' . getmypid();
foreach (['cfg', 'var', 'lock', 'GitHub/AppFeed'] as $d) {
    @mkdir($tmp . '/' . $d, 0775, true);
}
putenv('UZT_CFG_DIR=' . $tmp . '/cfg');
putenv('UZT_VAR_DIR=' . $tmp . '/var');
putenv('UZT_LOCK_DIR=' . $tmp . '/lock');
putenv('UZT_VAR_INI=' . $tmp . '/var.ini');
file_put_contents($tmp . '/var.ini', "csrf_token=\"ci-token\"\n");

$feedPath = $tmp . '/GitHub/AppFeed/applicationFeed.json';
putenv('UZT_FAKE_CA_FEED=' . $feedPath);
copy($root . '/tests/fixtures/feed.json', $feedPath);

require_once $pluginDir . '/include/translator.php';

use UZT\Config;
use UZT\Llm;
use UZT\SourceFeed;
use UZT\Store;
use UZT\Translator;

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $cond, string $extra = '') use (&$pass, &$fail): void {
    if ($cond) {
        echo "  ✓ {$name}\n";
        $pass++;
    } else {
        echo "  ✗ {$name} {$extra}\n";
        $fail++;
    }
};
$section = static function (string $t): void {
    echo "\n— {$t} —\n";
};

// ---------------------------------------------------------------- 假大模型
$procs = [];
$mockLog = $tmp . '/mock.jsonl';
$modes = ['ok' => null, 'identity' => null];

function uzt_free_port(): int
{
    $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$s) {
        return 0;
    }
    $name = stream_socket_get_name($s, false);
    fclose($s);
    return (int)substr((string)strrchr((string)$name, ':'), 1);
}

function uzt_wait_port(int $port, float $timeout = 8.0): bool
{
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
        if ($fp) {
            fclose($fp);
            return true;
        }
        usleep(100000);
    }
    return false;
}

foreach ($modes as $mode => $_) {
    $port = uzt_free_port();
    $env = [
        'MOCK_PORT' => (string)$port,
        'MOCK_LOG' => $mockLog,
        'MOCK_MODE' => $mode,
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    ];
    $proc = proc_open(
        ['python3', $root . '/tests/mock_llm.py'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $env
    );
    if (!is_resource($proc) || !uzt_wait_port($port)) {
        fwrite(STDERR, "无法启动假大模型（{$mode}），请确认已安装 python3\n");
        exit(2);
    }
    $modes[$mode] = ['port' => $port, 'proc' => $proc, 'pipes' => $pipes];
    $procs[] = $proc;
}
$okPort = $modes['ok']['port'];
$idPort = $modes['identity']['port'];

$cleanup = static function () use (&$procs, $tmp): void {
    foreach ($procs as $p) {
        if (is_resource($p)) {
            @proc_terminate($p);
        }
    }
    foreach ($procs as $p) {
        if (is_resource($p)) {
            @proc_close($p);
        }
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);
};
register_shutdown_function($cleanup);

$baseConfig = [
    'API_BASE' => 'http://127.0.0.1:' . $okPort . '/v1',
    'API_KEY' => 'sk-test',
    'MODEL' => 'mock-model',
    'FEED_SOURCE' => 'local',
    'SCOPE' => 'store',
    'BATCH_ITEMS' => '8',
    'BATCH_CHARS' => '6000',
    'SYNC_MAX_CHARS' => '20000',
    'AUTO_CONTINUE' => '0',
    'HOURLY_CHAR_BUDGET' => '0',
    'FEED_CACHE_TTL' => '999999',
];
Config::save($baseConfig);

// ---------------------------------------------------------------- 同步 / 翻译 / 幂等
$section('同步 → 翻译 → 幂等');
$sync = Translator::sync(true, null);
$check('首次同步抽出 18 条片段', ($sync['added'] ?? -1) === 18, json_encode($sync, JSON_UNESCAPED_UNICODE));
$check('优先复用 CA 本地缓存', Store::open()->getMeta('feed_source') === 'ca-local', Store::open()->getMeta('feed_source'));

$t1 = Translator::translatePending(60, 5000);
$check('首次翻译完成 18 条', ($t1['done'] ?? -1) === 18, json_encode($t1, JSON_UNESCAPED_UNICODE));
$check('无失败', ($t1['failed'] ?? -1) === 0, json_encode($t1, JSON_UNESCAPED_UNICODE));

$callsBefore = is_file($mockLog) ? count(file($mockLog)) : 0;
$t2 = Translator::translatePending(60, 5000);
$callsAfter = is_file($mockLog) ? count(file($mockLog)) : 0;
$check('重复翻译 0 条（全部命中缓存）', ($t2['done'] ?? -1) === 0, json_encode($t2, JSON_UNESCAPED_UNICODE));
$check('重复翻译 0 次模型调用', $callsBefore === $callsAfter, "{$callsBefore} -> {$callsAfter}");

$sync2 = Translator::sync(false, null);
$check('重复同步：新增 0 / 命中 18', ($sync2['added'] ?? -1) === 0 && ($sync2['existed'] ?? -1) === 18, json_encode($sync2, JSON_UNESCAPED_UNICODE));

// ---------------------------------------------------------------- 增量重译
$section('插件更新 → 只重译变更片段');
$feed = json_decode((string)file_get_contents($feedPath), true);
$feed['applist'][0]['Overview'] = str_replace(
    'It is an alternative to the proprietary Emby and Plex, to provide media from a dedicated server to end-user devices.',
    'It is an alternative to the proprietary Emby and Plex, streaming media from a dedicated server to any device.',
    $feed['applist'][0]['Overview']
) . "\n\n    Hardware transcoding requires an Intel CPU with Quick Sync or a supported GPU.";
file_put_contents($feedPath, json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
touch($feedPath);

$logStart = is_file($mockLog) ? count(file($mockLog)) : 0;
$sync3 = Translator::sync(false, null);
$t3 = Translator::translatePending(60, 5000);
$newLines = array_slice(is_file($mockLog) ? file($mockLog) : [], $logStart);
$sentSrcs = [];
foreach ($newLines as $line) {
    $row = json_decode($line, true);
    foreach (($row['srcs'] ?? []) as $s) {
        $sentSrcs[] = $s;
    }
}
$check('更新后只新增 2 条片段', ($sync3['added'] ?? -1) === 2, json_encode($sync3, JSON_UNESCAPED_UNICODE));
$check('只重译 2 条', ($t3['done'] ?? -1) === 2, json_encode($t3, JSON_UNESCAPED_UNICODE));
$check('送译内容只有变更的 2 条', count($sentSrcs) === 2, json_encode($sentSrcs, JSON_UNESCAPED_UNICODE));
$check('旧文案没有被重复送译', !array_filter($sentSrcs, static fn($s) => str_contains($s, 'Jellyfin is a free software')));

// ---------------------------------------------------------------- 长文本分片
$section('长文本跨批分片（不能只剩最后一片）');
$long = implode(' ', array_map(
    static fn(int $i): string => "Sentence number {$i} explains how the container behaves under sustained load.",
    range(1, 119)
));
$look = Translator::lookup([$long], true);
$hit = $look['hits'][\UZT\normalize_text($long)] ?? '';
$check('长文本有译文', $hit !== '', substr(json_encode($look, JSON_UNESCAPED_UNICODE), 0, 200));
$check('包含第一片内容', str_contains($hit, 'Sentence number 1 '));
$check('包含最后一片内容', str_contains($hit, 'Sentence number 119 '));
$check('译文长度接近原文', mb_strlen($hit) >= mb_strlen($long) * 0.9, mb_strlen($hit) . '/' . mb_strlen($long));

// ---------------------------------------------------------------- 采集策略
$section('只翻简介类内容');
$short = Translator::lookup(['Jellyfin', 'Install', 'Immich'], true);
$check('应用名/按钮字不被翻译', count($short['hits']) === 0, json_encode($short, JSON_UNESCAPED_UNICODE));
$shorts = Store::open()->getByHashes([\UZT\segment_hash('Jellyfin')]);
$check('短文本没有入库', empty($shorts));
$longNew = 'This descriptive paragraph explains where the container keeps its database and how it is rebuilt on updates.';
$ln = Translator::lookup([$longNew], true);
$check('简介级长文本照常翻译', count($ln['hits']) === 1, json_encode($ln, JSON_UNESCAPED_UNICODE));

// ---------------------------------------------------------------- 品牌名
$section('品牌名原样返回视为成功');
Config::save(['API_BASE' => 'http://127.0.0.1:' . $idPort . '/v1']);
$h = \UZT\segment_hash('Grafana');
Store::open()->upsertSegments([['h' => $h, 'src' => 'Grafana', 'kind' => 'fieldname', 'app' => 't']]);
$tb = Translator::translatePending(60, 100);
$row = Store::open()->getByHashes([$h])[$h] ?? null;
$check('品牌名没有进入失败状态', ($tb['failed'] ?? -1) === 0, json_encode($tb, JSON_UNESCAPED_UNICODE));
$check('品牌名保留原文并出队', ($row['dst'] ?? '') === 'Grafana' && (int)($row['st'] ?? 0) === 1, json_encode($row, JSON_UNESCAPED_UNICODE));
Config::save(['API_BASE' => 'http://127.0.0.1:' . $okPort . '/v1']);

// ---------------------------------------------------------------- 退避重试
$section('失败按指数退避重排');
$bh = \UZT\segment_hash('Backoff sample sentence that fails once and should wait before retrying.');
Store::open()->upsertSegments([['h' => $bh, 'src' => 'Backoff sample sentence that fails once and should wait before retrying.', 'kind' => 'live', 'app' => '']]);
Store::open()->setFail($bh, 'simulated 429');
$pendingNow = array_column(Store::open()->pending(500), 'h');
$check('刚失败不会立刻重试', !in_array($bh, $pendingNow, true));
$pdo = new PDO('sqlite:' . Store::dbFile());
$pdo->exec('UPDATE seg SET ts = ts - 600 WHERE st = 2');
$pendingLater = array_column(Store::open()->pending(500), 'h');
$check('退避时间到后自动回队列', in_array($bh, $pendingLater, true));

// ---------------------------------------------------------------- 失败原因 + 精简
$section('失败原因聚合 / 队列精简 / 清空未译');
Config::save(['API_BASE' => 'http://127.0.0.1:1/v1']);
$fh = \UZT\segment_hash('A segment that will fail because the endpoint is unreachable.');
Store::open()->upsertSegments([['h' => $fh, 'src' => 'A segment that will fail because the endpoint is unreachable.', 'kind' => 'live', 'app' => '']]);
$tf = Translator::translatePending(30, 50);
$check('失败被记录', ($tf['failed'] ?? 0) >= 1, json_encode($tf, JSON_UNESCAPED_UNICODE));
$summary = Store::open()->failureSummary(3);
$check('失败原因可聚合展示', !empty($summary), json_encode($summary, JSON_UNESCAPED_UNICODE));
Config::save(['API_BASE' => 'http://127.0.0.1:' . $okPort . '/v1']);

Store::open()->upsertSegments([
    ['h' => \UZT\segment_hash('ShortLabel'), 'src' => 'ShortLabel', 'kind' => 'fieldname', 'app' => 't'],
    ['h' => \UZT\segment_hash('LocalPlug'), 'src' => 'LocalPlug', 'kind' => 'localdesc', 'app' => 't'],
    ['h' => \UZT\segment_hash('KeepMe'), 'src' => 'A long descriptive sentence that must survive queue compaction because it is real content.', 'kind' => 'overview', 'app' => 't'],
]);
$comp = Translator::compact();
$left = Store::open()->getByHashes([
    \UZT\segment_hash('ShortLabel'), \UZT\segment_hash('LocalPlug'), \UZT\segment_hash('KeepMe'),
]);
$check('精简删除短文本与本机插件片段', ($comp['removed'] ?? 0) >= 2, json_encode($comp, JSON_UNESCAPED_UNICODE));
$check('长简介保留', isset($left[\UZT\segment_hash('KeepMe')]));

$before = Store::open()->stats()['total'];
$drop = Translator::dropPending();
$after = Store::open()->stats()['total'];
$check('清空未译队列只删未完成项', ($drop['removed'] ?? 0) > 0 && $after < $before, "before={$before} after={$after}");

// ---------------------------------------------------------------- 策略升级 / 慢模型
$section('策略升级自动精简 / 慢模型保护');
Store::open()->setMeta('policy_ver', '1');
$legacy = \UZT\segment_hash('LegacyShortLabel');
Store::open()->upsertSegments([['h' => $legacy, 'src' => 'LegacyShortLabel', 'kind' => 'live', 'app' => '']]);
Translator::sync(false, null);
$legacyLeft = Store::open()->getByHashes([$legacy]);
$check('策略升级后旧「页面误采」短文本被自动清理', !isset($legacyLeft[$legacy]));
$feedShort = Store::open()->getByHashes([\UZT\segment_hash('Video')]);
$check('应用源里的短标签（如分类 Video）不受迁移影响', isset($feedShort[\UZT\segment_hash('Video')]));
$check('策略版本已记录', Store::open()->getMeta('policy_ver') === (string)Translator::POLICY_VERSION, Store::open()->getMeta('policy_ver'));

Store::open()->setMeta('llm_avg_ms', '60000');
$modelCallsBefore = is_file($mockLog) ? count(file($mockLog)) : 0;
$slow = Translator::lookup(['A brand new descriptive sentence that is long enough to qualify for translation.'], true);
$modelCallsAfter = is_file($mockLog) ? count(file($mockLog)) : 0;
$check('慢模型时不做同步翻译', ($slow['translated_now'] ?? -1) === 0, json_encode($slow, JSON_UNESCAPED_UNICODE));
$check('慢模型时返回 slow_model 标记', !empty($slow['slow_model']));
$check('慢模型时零模型调用', $modelCallsBefore === $modelCallsAfter, "{$modelCallsBefore} -> {$modelCallsAfter}");
$check('慢模型时文案进入队列', ($slow['queued'] ?? 0) >= 1, json_encode($slow, JSON_UNESCAPED_UNICODE));
Store::open()->setMeta('llm_avg_ms', '0');

// ---------------------------------------------------------------- 结论
echo "\n════════════════════════════════════════\n";
echo " 通过 {$pass} 项 / 失败 {$fail} 项\n";
echo "════════════════════════════════════════\n";
echo $fail === 0 ? "核心链路全部通过 ✅\n" : "存在失败项 ❌\n";
exit($fail === 0 ? 0 : 1);
