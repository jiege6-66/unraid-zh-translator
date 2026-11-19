<?php
/**
 * POST /plugins/unraid.zh.translator/ajax/lookup.php
 * body: {"texts":["..."],"csrf":"..."}
 * 返回：{"ok":true,"hits":{原文:译文},"queued":N,"translated_now":N,"rev":"..."}
 */
declare(strict_types=1);

namespace UZT;

require_once __DIR__ . '/../include/translator.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if (!Config::bool('ENABLED')) {
        json_response(['ok' => true, 'hits' => [], 'queued' => 0, 'translated_now' => 0, 'rev' => '0', 'disabled' => true]);
    }
    $body = read_json_body();
    $csrf = $body['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!check_csrf(is_string($csrf) ? $csrf : null)) {
        json_response(['ok' => false, 'error' => 'CSRF 校验失败，请刷新页面'], 403);
    }
    $texts = $body['texts'] ?? [];
    if (!is_array($texts)) {
        json_response(['ok' => false, 'error' => 'texts 必须是数组'], 400);
    }
    $allowSync = isset($body['sync']) ? (bool)$body['sync'] : null;
    $res = Translator::lookup($texts, $allowSync);
    json_response(['ok' => true] + $res);
} catch (\Throwable $e) {
    jlog('lookup 异常: ' . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
