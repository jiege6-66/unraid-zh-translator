<?php
/**
 * unraid.zh.translator — 编排层
 *
 * 三个入口：
 *   lookup()          浏览器把页面上看到的英文批量送来，命中就返回中文，缺失就按需翻译；
 *   translatePending() 后台把队列里的待译片段翻完（受时间预算约束，可被 cron 反复调用）；
 *   sync()            重新抓取应用源、重算 hash、把「变了的文案」排进队列。
 */
declare(strict_types=1);

namespace UZT;

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/source_feed.php';
require_once __DIR__ . '/source_local.php';

final class Translator
{
    public static function store(): Store
    {
        return Store::open();
    }

    // ------------------------------------------------------------------ 锁

    public static function lockFile(): string
    {
        return lock_file();
    }

    /**
     * @return array{ok:bool,handle:resource|null,error?:string,busy?:bool}
     */
    public static function tryLock(): array
    {
        $path = self::lockFile();
        $f = @fopen($path, 'c');
        if (!$f) {
            return ['ok' => false, 'handle' => null, 'error' => '无法创建锁文件: ' . $path];
        }
        if (!flock($f, LOCK_EX | LOCK_NB)) {
            fclose($f);
            return ['ok' => false, 'handle' => null, 'busy' => true, 'error' => '已有同步/翻译任务在运行'];
        }
        ftruncate($f, 0);
        fwrite($f, (string)getmypid());
        fflush($f);
        return ['ok' => true, 'handle' => $f];
    }

    public static function unlock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function workerRunning(): bool
    {
        $f = @fopen(self::lockFile(), 'c');
        if (!$f) {
            return false;
        }
        $free = flock($f, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($f, LOCK_UN);
            fclose($f);
            return false;
        }
        fclose($f);
        return true;
    }

    // ------------------------------------------------------------------ 页面查询

    /**
     * @param array<int,string> $texts 浏览器送来的原始文本（已尽力规范化，服务端仍再规范一次）
     * @return array{hits:array<string,string>,queued:int,translated_now:int,failed:int,rev:string,error?:string}
     */
    public static function lookup(array $texts, ?bool $allowSync = null): array
    {
        $allowSync ??= Config::bool('SYNC_ON_LOOKUP');
        $store = self::store();
        $rev0 = $store->revision();

        // 1) 收集请求（浏览器已规范化，这里再做一次，保证幂等）
        $reqs = [];
        foreach ($texts as $t) {
            if (!is_string($t)) {
                continue;
            }
            $norm = normalize_text($t);
            $len = mb_strlen($norm, 'UTF-8');
            if ($len < 5 || $len > 20000) {
                continue;
            }
            if (has_cjk($norm) || looks_untranslatable($norm)) {
                continue;
            }
            $reqs[$norm] = true;
            if (count($reqs) >= 300) {
                break;
            }
            // 单条请求最多入库 400 个片段（多行文本会按行展开）
            if (count($reqs) * 2 >= 800) {
                break;
            }
        }
        if (!$reqs) {
            return ['hits' => [], 'queued' => $store->countPending(), 'translated_now' => 0, 'failed' => 0, 'rev' => $rev0];
        }

        // 2) 候选 = 请求本身（只读探测） + 多行文本的每一行（可入库）
        $probe = [];     // norm => hash
        $cand = [];      // norm => hash，可能会入库
        $lines = [];     // norm => array<int,string>
        foreach ($reqs as $norm => $_) {
            $probe[$norm] = segment_hash($norm);
            if (count($cand) >= 400) {
                break;
            }
            if (str_contains($norm, "\n")) {
                $parts = [];
                foreach (explode("\n", $norm) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $parts[] = $line;
                    if (is_translatable($line)) {
                        $cand[$line] = segment_hash($line);
                        $probe[$line] = $cand[$line];
                    }
                }
                $lines[$norm] = $parts;
            } else {
                $cand[$norm] = $probe[$norm];
            }
        }

        // 3) 查缓存
        $rows = $store->getByHashes(array_values($probe));
        $resolved = [];   // norm => 译文
        $writeback = [];
        foreach ($rows as $h => $row) {
            if ((int)$row['st'] === 1 && is_string($row['dst']) && $row['dst'] !== '') {
                $resolved[$row['src']] = $row['dst'];
                $writeback[$row['src']] = true;
            }
        }

        // 4) 缺失的片段入库并（可选）立即翻译
        $miss = [];
        foreach ($cand as $norm => $h) {
            if (isset($resolved[$norm])) {
                continue;
            }
            $row = $rows[$h] ?? null;
            // 新出现的文本：只收「一段话以上」的简介类内容。
            // 应用名、分类短标签、按钮文字等不进队列（已经在库里的仍可命中）。
            if ($row === null && !self::qualifiesAsLive($norm)) {
                continue;
            }
            if ($row && (int)$row['st'] === 2 && (int)$row['tries'] >= 3) {
                continue; // 连续失败，不再打扰模型
            }
            $miss[$norm] = $h;
        }

        $translatedNow = 0;
        $failed = 0;
        $error = '';
        if ($miss) {
            $rowsIn = [];
            foreach ($miss as $norm => $h) {
                $rowsIn[] = ['h' => $h, 'src' => $norm, 'kind' => 'live', 'app' => ''];
            }
            $store->upsertSegments($rowsIn, true);   // insertOnly：命中行不做空写

            $chars = 0;
            foreach ($miss as $norm => $_) {
                $chars += mb_strlen($norm, 'UTF-8');
            }
            $budget = max(0, Config::int('SYNC_MAX_CHARS', 4000));
            // 滚动小时预算：防止某个页面/脚本把额度刷爆（超出只入队，由后台任务处理）
            $hourKey = 'budget_' . date('YmdH');
            $hourlyCap = max(0, Config::int('HOURLY_CHAR_BUDGET', 120000));
            $hourlyUsed = (int)$store->getMeta($hourKey, '0');
            $withinBudget = $hourlyCap === 0 || ($hourlyUsed + $chars) <= $hourlyCap;
            if ($allowSync && Config::bool('AUTO_NEW') && $chars <= $budget && count($miss) <= 40 && $withinBudget) {
                $store->setMeta($hourKey, (string)($hourlyUsed + $chars));
                $llm = new Llm();
                if ($llm->ready()) {
                    $items = [];
                    foreach ($miss as $norm => $h) {
                        $items[] = ['h' => $h, 'src' => $norm];
                    }
                    $res = $llm->translateBatch($items);
                    $doneRows = [];
                    foreach ($res['hits'] as $h => $dst) {
                        $doneRows[] = ['h' => $h, 'dst' => $dst];
                    }
                    if ($doneRows) {
                        $store->setDone($doneRows);
                        $translatedNow = count($doneRows);
                        foreach ($miss as $norm => $h) {
                            if (isset($res['hits'][$h])) {
                                $resolved[$norm] = $res['hits'][$h];
                            }
                        }
                        jlog(sprintf('按需翻译 %d 条（%d 字符）', $translatedNow, $chars));
                    }
                    foreach ($res['errors'] as $h => $err) {
                        if (!isset($res['hits'][$h])) {
                            $store->setFail($h, (string)$err);
                            $failed++;
                            if ($error === '') {
                                $error = (string)$err;
                            }
                        }
                    }
                } else {
                    $error = '尚未配置大模型接口';
                }
            }
        }

        // 5) 组装结果：整段命中，或「逐行全部命中则拼接」
        $hits = [];
        foreach ($reqs as $norm => $_) {
            if (isset($resolved[$norm])) {
                $hits[$norm] = $resolved[$norm];
                continue;
            }
            if (!isset($lines[$norm])) {
                continue;
            }
            $out = [];
            $translatable = 0;
            $ok = 0;
            foreach ($lines[$norm] as $line) {
                $need = is_translatable($line);
                if ($need) {
                    $translatable++;
                }
                if (isset($resolved[$line])) {
                    $out[] = $resolved[$line];
                    if ($need) {
                        $ok++;
                    }
                } else {
                    $out[] = $line;
                }
            }
            if ($translatable > 0 && $ok === $translatable) {
                $hits[$norm] = implode("\n", $out);
            }
        }

        return [
            'hits' => $hits,
            'queued' => $store->countPending(),
            'translated_now' => $translatedNow,
            'failed' => $failed,
            'rev' => $store->revision(),
            'asked' => count($reqs),
            'error' => $error,
        ];
    }

    /**
     * 是否够格作为「页面即时采集」的新文本：
     * 长度达到 LIVE_MIN_CHARS，或至少有 6 个英文单词（覆盖较短的整句）。
     */
    private static function qualifiesAsLive(string $norm): bool
    {
        $min = max(0, Config::int('LIVE_MIN_CHARS', 40));
        if (mb_strlen($norm, 'UTF-8') >= $min) {
            return true;
        }
        // 注意：不要把 preg_match_all 的结果转成 bool 再和 6 比较 ——
        // PHP 会把数字也转成 bool，"true >= 6" 恒为 true。
        return preg_match_all('/[A-Za-z][A-Za-z0-9\'-]{1,}/', $norm) >= 6;
    }

    // ------------------------------------------------------------------ 后台翻译

    /**
     * @return array{done:int,failed:int,remaining:int,busy?:bool,error?:string,seconds:float}
     */
    public static function translatePending(int $budgetSeconds = 300, int $maxSegments = 2000, int $chain = 0): array
    {
        $t0 = microtime(true);
        $lock = self::tryLock();
        if (!$lock['ok']) {
            return [
                'done' => 0, 'failed' => 0, 'remaining' => self::store()->countPending(),
                'busy' => !empty($lock['busy']), 'error' => (string)($lock['error'] ?? ''),
                'seconds' => 0.0,
            ];
        }
        $out = ['done' => 0, 'failed' => 0, 'remaining' => 0, 'seconds' => 0.0, 'chain' => $chain];
        $llm = null;
        try {
            $llm = new Llm();
            $store = self::store();
            if (!$llm->ready()) {
                $out['error'] = '尚未配置大模型接口（设置 → 插件中文化 → 大模型）';
                $out['remaining'] = $store->countPending();
                return $out;
            }
            $budgetSeconds = max(10, $budgetSeconds);
            $processed = 0;
            $batchNo = 0;
            $lastLog = 0.0;
            while ($processed < $maxSegments) {
                $elapsed = microtime(true) - $t0;
                if ($elapsed >= $budgetSeconds) {
                    jlog(sprintf('达到单次运行时间预算（%d 秒），队列还剩 %d 条，准备自动续跑',
                        $budgetSeconds, $store->countPending()));
                    break;
                }
                $rows = $store->pending(min(200, $maxSegments - $processed));
                if (!$rows) {
                    break;
                }
                $batchStart = microtime(true);
                $items = [];
                foreach ($rows as $r) {
                    $items[] = ['h' => $r['h'], 'src' => $r['src']];
                }
                $res = $llm->translateBatch($items);
                $doneRows = [];
                foreach ($res['hits'] as $h => $dst) {
                    $doneRows[] = ['h' => $h, 'dst' => $dst];
                }
                if ($doneRows) {
                    $store->setDone($doneRows);
                    $out['done'] += count($doneRows);
                }
                foreach ($res['errors'] as $h => $err) {
                    if (isset($res['hits'][$h])) {
                        continue;   // 同一批里既有译文又有错误时，以译文为准
                    }
                    $store->setFail($h, (string)$err);
                    $out['failed']++;
                }
                $processed += count($rows);
                $batchNo++;

                // —— 进度日志：每批一行，最快 5 秒一行，避免刷屏
                if (microtime(true) - $lastLog >= 5 || $batchNo === 1) {
                    $lastLog = microtime(true);
                    $spent = $lastLog - $t0;
                    $rate = $out['done'] > 0 ? $out['done'] / max(0.1, $spent) : 0.0;   // 条/秒
                    $left = $store->countPending();
                    $msg = sprintf(
                        '翻译进度：本轮完成 %d，失败 %d，剩余 %d，速度 %.1f 条/分，最近一批 %d 条 %.1fs',
                        $out['done'], $out['failed'], $left, $rate * 60,
                        count($rows), microtime(true) - $batchStart
                    );
                    if ($rate > 0 && $left > 0) {
                        $msg .= '，按此速度还需 ' . human_age((int)round($left / $rate));
                    }
                    jlog($msg);
                }

                if (!$doneRows && $res['errors']) {
                    jlog('整批翻译失败，中断本轮：' . reset($res['errors']), 'WARN');
                    break;
                }
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
            $out['remaining'] = $store->countPending();
            if ($llm->calls > 0) {
                $store->setMeta('llm_req_last', (string)$llm->calls);
                $store->setMeta('llm_ms_last', (string)(int)round($llm->msTotal));
                $store->setMeta('llm_avg_ms', (string)(int)round($llm->msTotal / $llm->calls));
            }
            $store->event(sprintf('translate done=%d failed=%d left=%d', $out['done'], $out['failed'], $out['remaining']), $out['done']);
        } finally {
            self::unlock($lock['handle']);
            self::store()->flush();
        }

        $out['seconds'] = round(microtime(true) - $t0, 2);
        $summary = sprintf('翻译轮结束：完成 %d，失败 %d，剩余 %d，用时 %.1fs', $out['done'], $out['failed'], $out['remaining'], $out['seconds']);
        $fails = self::store()->failureSummary(3);
        if ($fails) {
            $parts = [];
            foreach ($fails as $f) {
                $parts[] = sprintf('%s ×%d', mb_substr($f['err'], 0, 80), $f['n']);
            }
            $summary .= '；失败原因：' . implode('；', $parts);
        }
        jlog($summary);
        if ($fails) {
            $out['failures'] = $fails;
        }

        // —— 自动续跑（锁已释放；新进程会重新抢锁）
        $out['continuing'] = false;
        $madeProgress = ($out['done'] > 0 || $out['failed'] === 0);
        if ($out['remaining'] > 0 && Config::bool('AUTO_CONTINUE')) {
            $maxChain = max(1, Config::int('MAX_CHAIN', 40));
            if (!$madeProgress) {
                jlog('本轮没有任何进展（全部失败），已停止自动续跑，请检查模型地址/Key/额度', 'WARN');
            } elseif ($chain >= $maxChain) {
                jlog('已达到自动续跑链长上限（' . $maxChain . '），停止；下次 cron/按钮会继续', 'WARN');
            } elseif (self::spawnTranslateChain($chain + 1, $budgetSeconds)) {
                $out['continuing'] = true;
                jlog(sprintf('队列还剩 %d 条，已自动开始下一轮（链 #%d）', $out['remaining'], $chain + 1));
            } else {
                jlog('自动续跑失败（无法启动子进程），请手动点「翻译待译队列」', 'WARN');
            }
        }
        return $out;
    }

    /** 后台再起一个 translate 进程（自动续跑） */
    private static function spawnTranslateChain(int $chain, int $budgetSeconds): bool
    {
        $php = php_cli_binary();
        if ($php === '') {
            return false;
        }
        $worker = plugin_dir() . '/scripts/worker.php';
        if (!is_file($worker)) {
            return false;
        }
        $cmd = implode(' ', array_map('escapeshellarg', [
            $php, '-d', 'memory_limit=1024M', '-d', 'display_errors=0', $worker,
            'translate', '--seconds=' . $budgetSeconds, '--chain=' . $chain,
        ]));
        $cmd .= ' >> ' . escapeshellarg(log_file()) . ' 2>&1 &';
        @exec($cmd);
        return true;
    }

    /** 清空所有未完成的片段（改成纯按需翻译；已译的保留） */
    public static function dropPending(): array
    {
        $n = self::store()->dropPending();
        self::store()->event('drop-pending -' . $n, $n);
        jlog(sprintf('清空未译队列：删除 %d 条（保留已译 %d 条）', $n, self::store()->stats()['done']));
        return ['ok' => true, 'removed' => $n];
    }

    /** 精简队列：删掉未完成且不符合「只看简介」策略的片段 */
    public static function compact(?bool $dropLocal = null): array
    {
        $min = max(0, Config::int('LIVE_MIN_CHARS', 40));
        $dropLocal ??= (Config::get('SCOPE') === 'store');
        $n = self::store()->compactQueue($min, $dropLocal);
        self::store()->event('compact -' . $n, $n);
        jlog(sprintf('精简队列：删除 %d 条短文本/本机插件片段（阈值 %d 字符，缩放本机插件=%s）',
            $n, $min, $dropLocal ? '是' : '否'));
        return ['ok' => true, 'removed' => $n, 'min_chars' => $min, 'drop_local' => $dropLocal];
    }

    // ------------------------------------------------------------------ 同步

    /**
     * @return array{ok:bool,added:int,existed:int,total:int,apps:int,error?:string,meta?:array,ms:int}
     */
    public static function sync(bool $forceFetch = false, ?callable $log = null, ?bool $includeLocal = null): array
    {
        $t0 = microtime(true);
        // 只翻译商店时，没必要去解析已安装插件的 .plg
        $includeLocal ??= (Config::get('SCOPE') !== 'store');
        $say = function (string $m) use ($log) {
            jlog($m);
            if ($log) {
                $log($m);
            }
        };
        $lock = self::tryLock();
        if (!$lock['ok']) {
            return ['ok' => false, 'added' => 0, 'existed' => 0, 'total' => 0, 'apps' => 0, 'error' => (string)($lock['error'] ?? '无法启动'), 'ms' => 0];
        }
        try {
            $say('开始同步应用源…');
            $fetched = SourceFeed::fetch($forceFetch, $say);
            if (!$fetched['ok']) {
                return ['ok' => false, 'added' => 0, 'existed' => 0, 'total' => 0, 'apps' => 0, 'error' => (string)($fetched['error'] ?? '下载失败'), 'ms' => (int)round((microtime(true) - $t0) * 1000)];
            }
            $parsed = SourceFeed::extract($fetched['file'], $say);
            if (!$parsed['ok']) {
                return ['ok' => false, 'added' => 0, 'existed' => 0, 'total' => 0, 'apps' => 0, 'error' => (string)($parsed['error'] ?? '解析失败'), 'ms' => (int)round((microtime(true) - $t0) * 1000)];
            }
            $store = self::store();
            $up = $store->upsertSegments($parsed['segments']);
            $say(sprintf('商店片段：新增 %d，已存在 %d', $up['added'], $up['existed']));

            if ($includeLocal) {
                $local = SourceLocal::extract();
                if ($local['ok'] && $local['segments']) {
                    $up2 = $store->upsertSegments($local['segments']);
                    $say(sprintf('本地插件片段：新增 %d，已存在 %d', $up2['added'], $up2['existed']));
                    $up['added'] += $up2['added'];
                    $up['existed'] += $up2['existed'];
                    $store->setApps($local['apps']);
                }
            }

            $store->setApps($parsed['apps']);
            foreach ($parsed['meta'] as $k => $v) {
                $store->setMeta($k, (string)$v);
            }
            $store->setMeta('synced_at', (string)time());
            $store->setMeta('feed_source', (string)($fetched['source'] ?? ''));
            $store->setMeta('feed_stamp', (string)($fetched['stamp'] ?? 0));
            $store->setMeta('feed_age', (string)($fetched['age'] ?? 0));
            $store->setMeta('feed_from_local', !empty($fetched['from_local']) ? '1' : '0');
            $store->setMeta('feed_file', (string)($fetched['file'] ?? ''));
            $store->setMeta('feed_file_ts', (string)(int)($parsed['meta']['feed_ts'] ?? time()));
            $store->event(sprintf('sync +%d / =%d', $up['added'], $up['existed']), $up['added']);
            $stats = $store->stats();
            $say(sprintf('同步完成：词条总数 %d，已完成 %d，待译 %d', $stats['total'], $stats['done'], $stats['pending']));
            return [
                'ok' => true,
                'added' => (int)$up['added'],
                'existed' => (int)$up['existed'],
                'total' => (int)$stats['total'],
                'apps' => (int)$stats['apps'],
                'meta' => $parsed['meta'],
                'ms' => (int)round((microtime(true) - $t0) * 1000),
            ];
        } finally {
            self::unlock($lock['handle']);
            self::store()->flush();
        }
    }

    /** 清理已经不在应用源里的历史词条 */
    public static function prune(): array
    {
        $parsed = SourceFeed::extract(null);
        if (!$parsed['ok']) {
            return ['ok' => false, 'removed' => 0, 'error' => $parsed['error'] ?? '解析失败'];
        }
        $keep = array_column($parsed['segments'], 'h');
        $local = SourceLocal::extract();
        if ($local['ok']) {
            $keep = array_merge($keep, array_column($local['segments'], 'h'));
        }
        // 页面按需采集（live）、后台实测等词条不属于应用源，一律保留
        $keep = array_merge($keep, self::store()->hashesByKind(['live', 'demo', 'legacy']));
        $n = self::store()->prune($keep);
        self::store()->event('prune', $n);
        jlog('清理过期词条: ' . $n);
        return ['ok' => true, 'removed' => $n, 'kept' => count($keep)];
    }

    /** 估算：全量翻译需要多少字符 / 请求 */
    public static function estimate(): array
    {
        $stats = self::store()->stats();
        $agg = self::store()->pendingAggregate();
        $pendingCount = (int)$agg['count'];
        $pendingChars = (int)$agg['chars'];
        $batch = max(1, Config::int('BATCH_ITEMS', 48));
        $requests = (int)ceil($pendingCount / $batch);
        $avg = (int)self::store()->getMeta('llm_avg_ms', '0');
        return [
            'pending' => $pendingCount,
            'pending_chars' => $pendingChars,
            'requests' => $requests,
            'done_chars' => $stats['src_chars'],
            'avg_req_ms' => $avg,
            'eta_seconds' => $avg > 0 ? (int)round($requests * $avg / 1000) : 0,
            'last_run' => [
                'requests' => (int)self::store()->getMeta('llm_req_last', '0'),
                'ms' => (int)self::store()->getMeta('llm_ms_last', '0'),
            ],
        ];
    }
}
