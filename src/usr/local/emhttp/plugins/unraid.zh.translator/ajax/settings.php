<?php
/**
 * POST /plugins/unraid.zh.translator/ajax/settings.php
 * body: {"action":"save|test|sync|translate|prune|wipe|defaults|retry-failed","csrf":"...", ...}
 */
declare(strict_types=1);

namespace UZT;

require_once __DIR__ . '/../include/translator.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $body = read_json_body();
    $csrf = $body['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!check_csrf(is_string($csrf) ? $csrf : null)) {
        json_response(['ok' => false, 'error' => 'CSRF 校验失败，请刷新页面后重试'], 403);
    }
    $action = (string)($body['action'] ?? '');

    switch ($action) {
        case 'save':
            $input = is_array($body['config'] ?? null) ? $body['config'] : [];
            if (!empty($body['clear_key'])) {
                $input['API_KEY'] = '__CLEAR__';
            }
            $input = Config::mergeMaskedKey($input);
            $before = Config::all();
            $errors = Config::save($input);
            $after = Config::all();
            if (($before['CRON'] ?? '') !== ($after['CRON'] ?? '')) {
                self_update_cron($after['CRON']);
            }
            $writeFailed = (bool)array_filter($errors, static fn($e) => str_contains((string)$e, '写入失败'));
            json_response([
                'ok' => !$writeFailed,
                'errors' => $errors,
                'error' => $writeFailed ? '配置未能保存' : '',
                'config' => Config::public_view(),
                'cron_changed' => ($before['CRON'] ?? '') !== ($after['CRON'] ?? ''),
            ]);
            break;

        case 'test':
            // 用短超时、不重试：这个动作在 web 请求里同步执行，不能超过 nginx 的 60s
            $llm = new Llm(['TIMEOUT' => 20, 'RETRIES' => 0]);
            json_response(['ok' => true, 'result' => $llm->test()]);
            break;

        case 'sync':
            $argv = ['sync'];
            if (!empty($body['force'])) {
                $argv[] = '--force';
            }
            $res = spawn_worker($argv);
            json_response(['ok' => (bool)$res['ok'], 'spawned' => $res, 'error' => $res['error'] ?? '']);
            break;

        case 'translate':
            $seconds = (int)($body['seconds'] ?? Config::int('WORKER_SECONDS', 300));
            $res = spawn_worker(['translate', '--seconds=' . max(30, min(3600, $seconds))]);
            json_response(['ok' => (bool)$res['ok'], 'spawned' => $res, 'error' => $res['error'] ?? '']);
            break;

        case 'prune':
            // 需要解析 22MB 应用源，必须放到 CLI（web 进程内存受限）
            $res = spawn_worker(['prune']);
            json_response(['ok' => (bool)$res['ok'], 'spawned' => $res, 'error' => $res['error'] ?? '']);
            break;

        case 'wipe':
            Store::open()->wipe();
            jlog('用户清空了全部译文缓存', 'WARN');
            json_response(['ok' => true, 'stats' => Store::open()->stats()]);
            break;

        case 'drop-pending':
            json_response(Translator::dropPending());
            break;

        case 'compact':
            json_response(Translator::compact((string)($body['scope'] ?? '') === 'all' ? false : null));
            break;

        case 'retry-failed':
            $n = Store::open()->resetFailed();
            Store::open()->flush();
            jlog('用户把 ' . $n . ' 条失败片段放回队列', 'INFO');
            json_response(['ok' => true, 'reset' => $n]);
            break;

        case 'defaults':
            Config::reset();
            json_response(['ok' => true, 'config' => Config::public_view()]);
            break;

        default:
            json_response(['ok' => false, 'error' => '未知动作: ' . $action], 400);
    }
} catch (\Throwable $e) {
    jlog('settings 异常: ' . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}

/** 写入 dynamix 的 cron 目录并重载 cron */
function self_update_cron(string $expr): void
{
    $file = '/boot/config/plugins/dynamix/unraid.zh.translator.cron';
    $expr = trim($expr);
    if ($expr === '') {
        @unlink($file);
    } else {
        $worker = plugin_dir() . '/scripts/worker.php';
        // 先判存在：插件被卸载后残留的 cron 行不会每天报错
        $cmd = '[ -f ' . $worker . ' ] && /usr/bin/php -d memory_limit=1024M ' . $worker . ' cron >/dev/null 2>&1';
        atomic_write($file, "# unraid.zh.translator 定时同步/翻译\n{$expr} {$cmd}\n", 0644);
    }
    if (is_file('/usr/local/sbin/update_cron')) {
        @exec('/usr/local/sbin/update_cron >/dev/null 2>&1');
    }
}
