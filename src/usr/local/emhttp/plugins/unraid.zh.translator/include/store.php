<?php
/**
 * unraid.zh.translator — 翻译缓存
 *
 * 缓存以「规范化文本的 sha1」为主键：
 *   - 同一段英文（即使出现在不同应用里）只翻译一次；
 *   - 插件/应用更新导致文案改变 => hash 改变 => 自动重译；
 *   - 文案没变 => hash 命中 => 直接复用，不调用大模型。
 *
 * 主后端 SQLite（增量写入，对 U 盘友好）；若 PHP 未编译 pdo_sqlite，
 * 退化到 var（RAM）里的 JSON 存储并在 flush() 时镜像到 /boot。
 */
declare(strict_types=1);

namespace UZT;

final class Store
{
    private const SCHEMA = [
        'CREATE TABLE IF NOT EXISTS seg (
            h     TEXT PRIMARY KEY,
            src   TEXT NOT NULL,
            kind  TEXT NOT NULL DEFAULT "",
            app   TEXT NOT NULL DEFAULT "",
            dst   TEXT,
            st    INTEGER NOT NULL DEFAULT 0,
            tries INTEGER NOT NULL DEFAULT 0,
            err   TEXT NOT NULL DEFAULT "",
            ts    INTEGER NOT NULL DEFAULT 0
        )',
        'CREATE INDEX IF NOT EXISTS seg_st ON seg(st, ts)',
        'CREATE INDEX IF NOT EXISTS seg_app ON seg(app)',
        'CREATE TABLE IF NOT EXISTS app (
            key   TEXT PRIMARY KEY,
            name  TEXT NOT NULL DEFAULT "",
            kind  TEXT NOT NULL DEFAULT "",
            ver   TEXT NOT NULL DEFAULT "",
            nseg  INTEGER NOT NULL DEFAULT 0,
            ndone INTEGER NOT NULL DEFAULT 0,
            ts    INTEGER NOT NULL DEFAULT 0
        )',
        'CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT "")',
        'CREATE TABLE IF NOT EXISTS hist (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts INTEGER NOT NULL,
            ev TEXT NOT NULL,
            n  INTEGER NOT NULL DEFAULT 0
        )',
    ];

    /** 失败退避：2^tries × 60 秒，最长 6 小时；超过 MAX_TRIES 才彻底放弃 */
    private static function retryDelay(int $tries): int
    {
        return (int)min(21600, 60 * (2 ** max(1, min(10, $tries))));
    }

    private static ?Store $inst = null;

    private ?\PDO $pdo = null;
    private array $mem = ['seg' => [], 'app' => [], 'meta' => []];
    private bool $dirty = false;
    private string $backend = 'json';

    public function backend(): string
    {
        return $this->backend;
    }

    public static function dbFile(): string
    {
        return cfg_dir() . '/translations.db';
    }

    public static function memFile(): string
    {
        return var_dir() . '/store.json';
    }

    public static function open(): Store
    {
        if (self::$inst instanceof Store) {
            return self::$inst;
        }
        $s = new Store();
        $dir = cfg_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (class_exists('\PDO') && in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            try {
                $s->pdo = $s->openSqlite();
                $s->backend = 'sqlite';
                self::$inst = $s;
                return $s;
            } catch (\Throwable $e) {
                // 库文件损坏（掉电/中断）：挪到一边后重建，译文可重新生成
                $db = self::dbFile();
                if (is_file($db) && !is_file($db . '.corrupt')) {
                    jlog('SQLite 打开失败，隔离旧库并重建: ' . $e->getMessage(), 'WARN');
                    @rename($db, $db . '.corrupt');
                    @unlink($db . '-journal');
                    @unlink($db . '.corrupt');
                    @unlink($db . '-wal');
                    try {
                        $s->pdo = $s->openSqlite();
                        $s->backend = 'sqlite';
                        self::$inst = $s;
                        return $s;
                    } catch (\Throwable $e2) {
                        jlog('重建 SQLite 仍失败，退化到 JSON 存储: ' . $e2->getMessage(), 'WARN');
                    }
                } else {
                    jlog('SQLite 不可用，退化到 JSON 存储: ' . $e->getMessage(), 'WARN');
                }
            }
        }
        $s->loadMem();
        self::$inst = $s;
        return $s;
    }

    private function openSqlite(): \PDO
    {
        $pdo = new \PDO('sqlite:' . self::dbFile());
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        // 词库放在 U 盘（可能掉电）：用回滚日志 + 完整同步，掉电后能自动回滚，
        // 而不是像 MEMORY 日志那样留下半写的库（只能整库重建 = 全部重译）。
        $pdo->exec('PRAGMA journal_mode=DELETE');
        $pdo->exec('PRAGMA synchronous=FULL');
        $pdo->exec('PRAGMA busy_timeout=10000');
        foreach (self::SCHEMA as $sql) {
            $pdo->exec($sql);
        }
        return $pdo;
    }

    // ---------------------------------------------------------------- JSON 后端

    private function loadMem(): void
    {
        $file = self::memFile();
        $data = null;
        if (is_readable($file)) {
            $data = json_decode((string)file_get_contents($file), true);
        }
        if (!is_array($data) && is_readable(cfg_dir() . '/store.json')) {
            $data = json_decode((string)file_get_contents(cfg_dir() . '/store.json'), true);
        }
        if (is_array($data)) {
            $this->mem = [
                'seg'  => is_array($data['seg'] ?? null) ? $data['seg'] : [],
                'app'  => is_array($data['app'] ?? null) ? $data['app'] : [],
                'meta' => is_array($data['meta'] ?? null) ? $data['meta'] : [],
            ];
        }
    }

    public function flush(): void
    {
        if ($this->backend !== 'json' || !$this->dirty) {
            return;
        }
        $json = json_encode($this->mem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        atomic_write(self::memFile(), $json);
        atomic_write(cfg_dir() . '/store.json', $json);
        $this->dirty = false;
    }

    public function close(): void
    {
        $this->flush();
        $this->pdo = null;
    }

    // ---------------------------------------------------------------- 写入

    /**
     * SQLite 在多标签页/后台任务并发写入时可能短暂 BUSY，这里做有限重试。
     * @param callable():mixed $fn
     */
    private function withRetry(callable $fn)
    {
        $attempt = 0;
        while (true) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $busy = stripos($e->getMessage(), 'locked') !== false
                    || stripos($e->getMessage(), 'busy') !== false;
                if (!$busy || $attempt >= 3) {
                    throw $e;
                }
                $attempt++;
                usleep(120000 * $attempt);
            }
        }
    }

    /**
     * @param array<int,array{h:string,src:string,kind:string,app:string}> $rows
     * @param bool $insertOnly true 时只做 INSERT OR IGNORE（lookup 路径用，避免对已存在行空写）
     */
    public function upsertSegments(array $rows, bool $insertOnly = false): array
    {
        $added = 0;
        $existed = 0;
        if ($this->backend === 'sqlite') {
            if (!$rows) {
                return ['added' => 0, 'existed' => 0];
            }
            $this->withRetry(function () use ($rows, $insertOnly, &$added, &$existed): void {
                $iAdded = 0;
                $iExisted = 0;
                $this->pdo->beginTransaction();
                try {
                    $now = time();
                    $ins = $this->pdo->prepare(
                        'INSERT OR IGNORE INTO seg (h, src, kind, app, dst, st, ts) VALUES (?, ?, ?, ?, NULL, 0, ?)'
                    );
                    if ($insertOnly) {
                        foreach ($rows as $r) {
                            $ins->execute([$r['h'], $r['src'], (string)($r['kind'] ?? ''), (string)($r['app'] ?? ''), $now]);
                            $iAdded += $ins->rowCount();
                        }
                    } else {
                        $sel = $this->pdo->prepare('SELECT 1 FROM seg WHERE h = ?');
                        $upd = $this->pdo->prepare('UPDATE seg SET kind = ?, app = ? WHERE h = ? AND (kind <> ? OR app <> ?)');
                        foreach ($rows as $r) {
                            $h = $r['h'];
                            $kind = (string)($r['kind'] ?? '');
                            $app = (string)($r['app'] ?? '');
                            $sel->execute([$h]);
                            if ($sel->fetchColumn()) {
                                $upd->execute([$kind, $app, $h, $kind, $app]);
                                $iExisted++;
                            } else {
                                $ins->execute([$h, $r['src'], $kind, $app, $now]);
                                $iAdded += $ins->rowCount();
                            }
                        }
                    }
                    $this->pdo->commit();
                    $added = $iAdded;
                    $existed = $iExisted;
                } catch (\Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }
            });
            if ($insertOnly) {
                $existed = count($rows) - $added;
            }
            return ['added' => $added, 'existed' => $existed];
        }
        foreach ($rows as $r) {
            $h = $r['h'];
            if (isset($this->mem['seg'][$h])) {
                $existed++;
                continue;
            }
            $this->mem['seg'][$h] = [
                'src' => $r['src'], 'kind' => (string)($r['kind'] ?? ''),
                'app' => (string)($r['app'] ?? ''), 'dst' => null, 'st' => 0,
                'tries' => 0, 'err' => '', 'ts' => time(),
            ];
            $added++;
            $this->dirty = true;
        }
        return ['added' => $added, 'existed' => $existed];
    }

    /** @param array<int,array{h:string,dst:string}> $rows */
    public function setDone(array $rows): int
    {
        if (!$rows) {
            return 0;
        }
        $n = 0;
        if ($this->backend === 'sqlite') {
            $this->withRetry(function () use ($rows, &$n): void {
                $iN = 0;
                $this->pdo->beginTransaction();
                try {
                    $st = $this->pdo->prepare('UPDATE seg SET dst = ?, st = 1, err = "", ts = ? WHERE h = ?');
                    $now = time();
                    foreach ($rows as $r) {
                        $st->execute([$r['dst'], $now, $r['h']]);
                        $iN += $st->rowCount();
                    }
                    $this->pdo->commit();
                    $n = $iN;
                } catch (\Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }
            });
        } else {
            foreach ($rows as $r) {
                if (!isset($this->mem['seg'][$r['h']])) {
                    continue;
                }
                $this->mem['seg'][$r['h']]['dst'] = $r['dst'];
                $this->mem['seg'][$r['h']]['st'] = 1;
                $this->mem['seg'][$r['h']]['err'] = '';
                $this->mem['seg'][$r['h']]['ts'] = time();
                $n++;
            }
            $this->dirty = $n > 0;
        }
        if ($n > 0) {
            $this->bumpRevision();
        }
        return $n;
    }

    public function setFail(string $h, string $err): void
    {
        $err = mb_substr($err, 0, 400);
        if ($this->backend === 'sqlite') {
            $st = $this->pdo->prepare('UPDATE seg SET st = 2, tries = tries + 1, err = ?, ts = ? WHERE h = ?');
            $st->execute([$err, time(), $h]);
            return;
        }
        if (isset($this->mem['seg'][$h])) {
            $s = &$this->mem['seg'][$h];
            $s['st'] = 2;
            $s['tries'] = (int)$s['tries'] + 1;
            $s['err'] = $err;
            $s['ts'] = time();
            $this->dirty = true;
        }
    }

    // ---------------------------------------------------------------- 读取

    /** @return array<string,array{src:string,dst:?string,st:int,tries:int,err:string}> */
    public function getByHashes(array $hashes): array
    {
        $out = [];
        if (!$hashes) {
            return $out;
        }
        if ($this->backend === 'sqlite') {
            foreach (array_chunk($hashes, 400) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = $this->pdo->prepare("SELECT h, src, dst, st, tries, err FROM seg WHERE h IN ($ph)");
                $st->execute($chunk);
                foreach ($st->fetchAll() as $row) {
                    $out[$row['h']] = $row;
                }
            }
            return $out;
        }
        foreach ($hashes as $h) {
            if (isset($this->mem['seg'][$h])) {
                $out[$h] = ['h' => $h] + $this->mem['seg'][$h];
            }
        }
        return $out;
    }

    /** @return array<int,array{h:string,src:string,kind:string,app:string,tries:int}> */
    public function pending(int $limit = 200): array
    {
        $limit = max(1, min(5000, $limit));
        if ($this->backend === 'sqlite') {
            $maxTries = max(1, Config::int('MAX_TRIES', 8));
            $rows = $this->pdo->query(
                'SELECT h, src, kind, app, tries, st, ts FROM seg
                 WHERE st = 0 OR st = 2 ORDER BY ts ASC LIMIT ' . max(200, $limit * 4)
            )->fetchAll();
            $out = [];
            $now = time();
            foreach ($rows as $r) {
                if ((int)$r['st'] === 0) {
                    $out[] = $r;
                } elseif ((int)$r['tries'] < $maxTries && $now - (int)$r['ts'] >= self::retryDelay((int)$r['tries'])) {
                    $out[] = $r;
                }
                if (count($out) >= $limit) {
                    break;
                }
            }
            return $out;
        }
        $rows = [];
        $maxTries = max(1, Config::int('MAX_TRIES', 8));
        foreach ($this->mem['seg'] as $h => $s) {
            $retryable = $s['st'] === 2
                && (int)$s['tries'] < $maxTries
                && time() - (int)$s['ts'] >= self::retryDelay((int)$s['tries']);
            if ($s['st'] === 0 || $retryable) {
                $rows[] = ['h' => $h, 'src' => $s['src'], 'kind' => $s['kind'], 'app' => $s['app'], 'tries' => (int)$s['tries']];
            }
        }
        usort($rows, fn($a, $b) => ($this->mem['seg'][$a['h']]['ts'] ?? 0) <=> ($this->mem['seg'][$b['h']]['ts'] ?? 0));
        return array_slice($rows, 0, $limit);
    }

    public function countPending(): int
    {
        if ($this->backend === 'sqlite') {
            $maxTries = max(1, Config::int('MAX_TRIES', 8));
            $pending = (int)$this->pdo->query('SELECT COUNT(*) FROM seg WHERE st = 0')->fetchColumn();
            $failed = (int)$this->pdo->query('SELECT COUNT(*) FROM seg WHERE st = 2 AND tries < ' . $maxTries)->fetchColumn();
            return $pending + $failed;
        }
        $n = 0;
        $maxTries = max(1, Config::int('MAX_TRIES', 8));
        foreach ($this->mem['seg'] as $s) {
            if ($s['st'] === 0 || ($s['st'] === 2 && (int)$s['tries'] < $maxTries)) {
                $n++;
            }
        }
        return $n;
    }

    public function stats(): array
    {
        if ($this->backend === 'sqlite') {
            $q = fn(string $sql) => (int)$this->pdo->query($sql)->fetchColumn();
            $total = $q('SELECT COUNT(*) FROM seg');
            $done = $q('SELECT COUNT(*) FROM seg WHERE st = 1');
            $fail = $q('SELECT COUNT(*) FROM seg WHERE st = 2');
            $chars = (int)($this->pdo->query("SELECT COALESCE(SUM(LENGTH(src)),0) FROM seg WHERE st = 1")->fetchColumn() ?: 0);
            $apps = $q('SELECT COUNT(*) FROM app');
            $last = (string)($this->pdo->query("SELECT COALESCE(MAX(ts),0) FROM seg WHERE st = 1")->fetchColumn() ?: 0);
        } else {
            $total = count($this->mem['seg']);
            $done = 0;
            $fail = 0;
            $chars = 0;
            $last = 0;
            foreach ($this->mem['seg'] as $s) {
                if ($s['st'] === 1) {
                    $done++;
                    $chars += mb_strlen($s['src'], 'UTF-8');
                    $last = max($last, (int)$s['ts']);
                } elseif ($s['st'] === 2) {
                    $fail++;
                }
            }
            $apps = count($this->mem['app']);
        }
        return [
            'backend'  => $this->backend,
            'total'    => $total,
            'done'     => $done,
            'pending'  => $this->countPending(),
            'failed'   => $fail,
            'src_chars' => $chars,
            'apps'     => $apps,
            'last_done' => (int)$last,
            'revision' => $this->revision(),
            'db_bytes' => $this->backend === 'sqlite' && is_file(self::dbFile()) ? (int)filesize(self::dbFile()) : (is_file(self::memFile()) ? (int)filesize(self::memFile()) : 0),
        ];
    }

    // ---------------------------------------------------------------- meta / app

    public function revision(): string
    {
        if ($this->backend === 'sqlite') {
            $st = $this->pdo->prepare('SELECT v FROM meta WHERE k = ?');
            $st->execute(['rev']);
            return (string)($st->fetchColumn() ?: '0');
        }
        return (string)($this->mem['meta']['rev'] ?? '0');
    }

    public function setMeta(string $k, string $v): void
    {
        if ($this->backend === 'sqlite') {
            $st = $this->pdo->prepare('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
            $st->execute([$k, $v]);
            return;
        }
        $this->mem['meta'][$k] = $v;
        $this->dirty = true;
    }

    public function getMeta(string $k, string $default = ''): string
    {
        if ($this->backend === 'sqlite') {
            $st = $this->pdo->prepare('SELECT v FROM meta WHERE k = ?');
            $st->execute([$k]);
            $v = $st->fetchColumn();
            return $v === false ? $default : (string)$v;
        }
        return (string)($this->mem['meta'][$k] ?? $default);
    }

    private function bumpRevision(): void
    {
        $rev = (int)$this->revision() + 1;
        $this->setMeta('rev', (string)$rev);
    }

    /** @param array<int,array{key:string,name:string,kind:string,ver:string,nseg:int,ndone:int}> $apps */
    public function setApps(array $apps): void
    {
        if (!$apps) {
            return;
        }
        if ($this->backend === 'sqlite') {
            $this->pdo->beginTransaction();
            $st = $this->pdo->prepare(
                'INSERT INTO app (key, name, kind, ver, nseg, ndone, ts) VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT(key) DO UPDATE SET name = excluded.name, kind = excluded.kind,
                 ver = excluded.ver, nseg = excluded.nseg, ndone = excluded.ndone, ts = excluded.ts'
            );
            $now = time();
            foreach ($apps as $a) {
                $st->execute([$a['key'], $a['name'], $a['kind'], $a['ver'], $a['nseg'], $a['ndone'], $now]);
            }
            $this->pdo->commit();
            return;
        }
        foreach ($apps as $a) {
            $this->mem['app'][$a['key']] = $a + ['ts' => time()];
        }
        $this->dirty = true;
    }

    public function event(string $ev, int $n = 0): void
    {
        if ($this->backend === 'sqlite') {
            $st = $this->pdo->prepare('INSERT INTO hist (ts, ev, n) VALUES (?, ?, ?)');
            $st->execute([time(), mb_substr($ev, 0, 200), $n]);
            $this->pdo->exec('DELETE FROM hist WHERE id < (SELECT MAX(id) - 200 FROM hist)');
            return;
        }
        $this->dirty = true;
    }

    public function recentEvents(int $limit = 12): array
    {
        if ($this->backend !== 'sqlite') {
            return [];
        }
        $st = $this->pdo->prepare('SELECT ts, ev, n FROM hist ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
        $st->execute();
        return $st->fetchAll();
    }

    /** 失败原因聚合（给日志/界面看，最近原因优先） */
    public function failureSummary(int $limit = 3): array
    {
        $out = [];
        if ($this->backend === 'sqlite') {
            $rows = $this->pdo->query(
                'SELECT err, COUNT(*) AS n FROM seg WHERE st = 2 GROUP BY err ORDER BY n DESC LIMIT ' . max(1, $limit)
            )->fetchAll();
            foreach ($rows as $r) {
                $out[] = ['err' => (string)($r['err'] ?: '未知'), 'n' => (int)$r['n']];
            }
        }
        return $out;
    }

    /**
     * 精简队列：删掉未完成、且不符合「只看简介类内容」策略的片段。
     * - 短文本（< $minChars）多为名称/标签，不需要翻译；
     * - 只翻译商店时，顺带清掉本机插件描述。
     */
    public function compactQueue(int $minChars, bool $dropLocalPlugins): int
    {
        if ($this->backend === 'sqlite') {
            // 注意：SQLite 里 INTEGER 与 TEXT 比较时「整数恒小于文本」，
            // 所以数字条件不能靠 PDO 的 ? 绑定（默认按字符串传），必须内联。
            $min = max(0, (int)$minChars);
            if ($dropLocalPlugins) {
                $sql = 'DELETE FROM seg WHERE st <> 1 AND (LENGTH(src) < ' . $min . ' OR kind IN ("localdesc","localchg"))';
            } else {
                $sql = 'DELETE FROM seg WHERE st <> 1 AND LENGTH(src) < ' . $min;
            }
            return (int)$this->pdo->exec($sql);
        }
        $n = 0;
        foreach ($this->mem['seg'] as $h => $s) {
            if ($s['st'] === 1) {
                continue;
            }
            if (mb_strlen((string)$s['src'], 'UTF-8') < $minChars
                || ($dropLocalPlugins && in_array((string)$s['kind'], ['localdesc', 'localchg'], true))) {
                unset($this->mem['seg'][$h]);
                $n++;
            }
        }
        $this->dirty = $n > 0;
        return $n;
    }

    /** 删除所有未完成片段（已翻译的保留） */
    public function dropPending(): int
    {
        if ($this->backend === 'sqlite') {
            return (int)$this->pdo->exec('DELETE FROM seg WHERE st <> 1');
        }
        $n = 0;
        foreach ($this->mem['seg'] as $h => $s) {
            if ($s['st'] !== 1) {
                unset($this->mem['seg'][$h]);
                $n++;
            }
        }
        $this->dirty = $n > 0;
        return $n;
    }

    /** 把所有失败片段放回待译队列（用户修好 Key/网络后需要） */
    public function resetFailed(): int
    {
        if ($this->backend === 'sqlite') {
            $n = $this->pdo->exec('UPDATE seg SET st = 0, tries = 0, err = "" WHERE st = 2');
            return (int)$n;
        }
        $n = 0;
        foreach ($this->mem['seg'] as $h => $s) {
            if ($s['st'] === 2) {
                $this->mem['seg'][$h]['st'] = 0;
                $this->mem['seg'][$h]['tries'] = 0;
                $this->mem['seg'][$h]['err'] = '';
                $n++;
            }
        }
        $this->dirty = $n > 0;
        return $n;
    }

    /** 待译队列的条数与字符数（SQL 聚合，避免把整表读进内存） */
    public function pendingAggregate(): array
    {
        if ($this->backend === 'sqlite') {
            $maxTries = max(1, Config::int('MAX_TRIES', 8));
            $row = $this->pdo->query(
                'SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(src)),0) AS ch
                 FROM seg WHERE st = 0 OR (st = 2 AND tries < ' . $maxTries . ')'
            )->fetch();
            return ['count' => (int)($row['n'] ?? 0), 'chars' => (int)($row['ch'] ?? 0)];
        }
        $n = 0;
        $ch = 0;
        $maxTries = max(1, Config::int('MAX_TRIES', 8));
        foreach ($this->mem['seg'] as $s) {
            if ($s['st'] === 0 || ($s['st'] === 2 && (int)$s['tries'] < $maxTries)) {
                $n++;
                $ch += mb_strlen((string)$s['src'], 'UTF-8');
            }
        }
        return ['count' => $n, 'chars' => $ch];
    }

    /** 指定 kind 的全部 hash（清理时用来保护非应用源词条） */
    public function hashesByKind(array $kinds): array
    {
        if (!$kinds) {
            return [];
        }
        if ($this->backend === 'sqlite') {
            $ph = implode(',', array_fill(0, count($kinds), '?'));
            $st = $this->pdo->prepare("SELECT h FROM seg WHERE kind IN ($ph)");
            $st->execute(array_values($kinds));
            return $st->fetchAll(\PDO::FETCH_COLUMN);
        }
        $out = [];
        foreach ($this->mem['seg'] as $h => $s) {
            if (in_array((string)$s['kind'], $kinds, true)) {
                $out[] = $h;
            }
        }
        return $out;
    }

    /** 按片段类型统计，用于设置页展示覆盖率 */
    public function kindStats(): array
    {
        $out = [];
        if ($this->backend === 'sqlite') {
            $rows = $this->pdo->query(
                'SELECT kind, COUNT(*) AS total, SUM(CASE WHEN st = 1 THEN 1 ELSE 0 END) AS done
                 FROM seg GROUP BY kind ORDER BY total DESC'
            )->fetchAll();
            foreach ($rows as $r) {
                $out[] = ['kind' => (string)$r['kind'], 'total' => (int)$r['total'], 'done' => (int)$r['done']];
            }
            return $out;
        }
        $agg = [];
        foreach ($this->mem['seg'] as $s) {
            $k = $s['kind'] ?: 'live';
            $agg[$k] ??= ['kind' => $k, 'total' => 0, 'done' => 0];
            $agg[$k]['total']++;
            if ($s['st'] === 1) {
                $agg[$k]['done']++;
            }
        }
        usort($agg, fn($a, $b) => $b['total'] <=> $a['total']);
        return array_values($agg);
    }

    /** 清空全部翻译（保留队列结构） */
    public function wipe(): void
    {
        if ($this->backend === 'sqlite') {
            $this->pdo->exec('DELETE FROM seg');
            $this->pdo->exec('DELETE FROM app');
            $this->pdo->exec('DELETE FROM hist');
            $this->setMeta('rev', '0');
            return;
        }
        $this->mem = ['seg' => [], 'app' => [], 'meta' => ['rev' => '0']];
        $this->dirty = true;
        $this->flush();
    }

    /** 删除不在 $keepHashes 里的历史词条（商店下架/改名后清理） */
    public function prune(array $keepHashes): int
    {
        $keep = array_fill_keys($keepHashes, true);
        if ($this->backend === 'sqlite') {
            $n = 0;
            $this->pdo->beginTransaction();
            $del = $this->pdo->prepare('DELETE FROM seg WHERE h = ?');
            $rows = $this->pdo->query('SELECT h FROM seg')->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($rows as $h) {
                if (!isset($keep[$h])) {
                    $del->execute([$h]);
                    $n += $del->rowCount();
                }
            }
            $this->pdo->commit();
            return $n;
        }
        $n = 0;
        foreach (array_keys($this->mem['seg']) as $h) {
            if (!isset($keep[$h])) {
                unset($this->mem['seg'][$h]);
                $n++;
            }
        }
        $this->dirty = $n > 0;
        return $n;
    }
}
