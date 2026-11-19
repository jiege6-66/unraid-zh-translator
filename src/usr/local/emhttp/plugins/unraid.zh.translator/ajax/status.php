<?php
/**
 * GET /plugins/unraid.zh.translator/ajax/status.php
 * 返回统计、队列、worker 状态、最近日志，供设置页轮询。
 */
declare(strict_types=1);

namespace UZT;

require_once __DIR__ . '/../include/translator.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $store = Store::open();
    // 轻量模式：只要 rev / 队列 / 运行状态，供页面高频轮询
    if (!empty($_GET['light'])) {
        json_response([
            'ok' => true,
            'light' => true,
            'revision' => $store->revision(),
            'pending' => $store->countPending(),
            'worker_running' => Translator::workerRunning(),
        ]);
    }
    $logTail = '';
    $lf = log_file();
    if (is_readable($lf)) {
        $lines = file($lf) ?: [];
        $logTail = implode('', array_slice($lines, -40));
    }
    json_response([
        'ok' => true,
        'stats' => $store->stats(),
        'kinds' => $store->kindStats(),
        'estimate' => Translator::estimate(),
        'worker_running' => Translator::workerRunning(),
        'config' => [
            'ENABLED' => Config::get('ENABLED'),
            'SCOPE' => Config::get('SCOPE'),
            'API_KEY_SET' => Config::get('API_KEY') !== '' ? '1' : '0',
            'MODEL' => Config::get('MODEL'),
            'API_BASE' => Config::get('API_BASE'),
            'SYNC_ON_LOOKUP' => Config::get('SYNC_ON_LOOKUP'),
        ],
        'meta' => [
            'synced_at' => $store->getMeta('synced_at'),
            'feed_updated' => $store->getMeta('feed_updated'),
            'feed_apps' => $store->getMeta('feed_apps'),
            'plugins' => $store->getMeta('plugins'),
        ],
        'env' => [
            'php_cli' => php_cli_binary(),
            'php_sapi' => PHP_SAPI,
            'php_fpm_binary' => PHP_BINARY,
            'log_dir' => dirname(log_file()),
            'log_writable' => is_writable(dirname(log_file())),
            'boot_writable' => is_writable(cfg_dir()),
            'var_dir' => var_dir(),
            'model_avg_ms' => (int)$store->getMeta('llm_avg_ms', '0'),
            'policy_ver' => $store->getMeta('policy_ver'),
            'feed_source' => $store->getMeta('feed_source'),
            'feed_age' => (int)$store->getMeta('feed_age', '0'),
            'feed_from_local' => $store->getMeta('feed_from_local') === '1',
            'ca_local' => (static function (): array {
                $l = SourceFeed::localFeed();
                return $l ? ['file' => $l['file'], 'age' => time() - $l['mtime']] : [];
            })(),
        ],
        'failures' => $store->failureSummary(3),
        'recent' => $store->recentEvents(8),
        'log' => $logTail,
        'now' => time(),
    ]);
} catch (\Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
