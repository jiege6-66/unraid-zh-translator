<?php
/**
 * unraid.zh.translator — Community Applications 数据源
 *
 * 从 CA 的应用源（applicationFeed.json，约 22MB / 5000+ 应用）里抽出
 * 真正需要翻译的文案片段：应用简介、所需条件、分类名、模板字段说明、分支说明。
 *
 * 抽出的每条片段都用「规范化文本的 sha1」做键：
 * 文案不变 => 命中缓存；文案变化（插件更新）=> 新键 => 自动重译。
 */
declare(strict_types=1);

namespace UZT;

final class SourceFeed
{
    /** 小于这个字节数视为空文件/半截下载（真正的校验靠 applist 字段和 JSON 解析） */
    private const MIN_FEED_BYTES = 2048;

    /** 片段类型 → 用于统计展示 */
    public const KIND_LABEL = [
        'overview'  => '应用简介',
        'requires'  => '运行要求',
        'cat'       => '分类名称',
        'field'     => '模板字段说明',
        'fieldname' => '模板字段名',
        'branch'    => '分支说明',
        'note'      => '应用备注',
        'localdesc' => '本地插件描述',
        'localchg'  => '本地插件更新日志',
    ];

    /** 我们自己下载后的缓存（在线源才会用） */
    public static function cacheFile(): string
    {
        return var_dir() . '/applicationFeed.json';
    }

    /** 官方 CDN（CA 插件自己用的主源） */
    public const URL_PRIMARY = 'https://assets.ca.unraid.net/feed/applicationFeed.json';
    /** GitHub 备份源（与上面同内容） */
    public const URL_BACKUP = 'https://raw.githubusercontent.com/Squidly271/AppFeed/master/applicationFeed.json';
    /** 只有 37 字节的「源更新时间」戳，用来零成本判断本地缓存是否最新 */
    public const URL_STAMP = 'https://assets.ca.unraid.net/feed/applicationFeed-lastUpdated.json';

    /**
     * CA 插件把下载好的应用源解压到这里（见 CA 的 include/paths.php:
     * 'application-feed-local' => "/tmp/GitHub/AppFeed/applicationFeed.json"）。
     * 直接复用它，既省 22MB 下载，又和商店里显示的内容完全一致。
     */
    public static function localCandidates(): array
    {
        $list = [
            '/tmp/GitHub/AppFeed/applicationFeed.json',
            '/tmp/community.applications/AppFeed/applicationFeed.json',
            '/tmp/community.applications/tempFiles/applicationFeed.json',
            '/tmp/community.applications/applicationFeed.json',
        ];
        foreach (glob('/tmp/*/AppFeed/applicationFeed.json') ?: [] as $g) {
            $list[] = $g;
        }
        return array_values(array_unique(array_filter($list)));
    }

    /** @return array{file:string,mtime:int,size:int,stamp:int}|null 最新的一个本地 CA 缓存 */
    public static function localFeed(): ?array
    {
        $best = null;
        foreach (self::localCandidates() as $f) {
            if (!is_file($f) || filesize($f) < self::MIN_FEED_BYTES) {
                continue;
            }
            $head = (string)@file_get_contents($f, false, null, 0, 65536);
            if (!str_contains($head, '"applist"') && !str_contains($head, '"apps"')) {
                continue;
            }
            clearstatcache(true, $f);
            $mtime = (int)filemtime($f);
            if ($best === null || $mtime > $best['mtime']) {
                $best = [
                    'file' => $f,
                    'mtime' => $mtime,
                    'size' => (int)filesize($f),
                    'stamp' => preg_match('/"last_updated_timestamp"\s*:\s*(\d+)/', $head, $m) ? (int)$m[1] : 0,
                ];
            }
        }
        return $best;
    }

    /** 官方「源更新时间」戳（37 字节，失败返回 0） */
    public static function remoteStamp(int $timeout = 8): int
    {
        $res = self::curlGet(self::URL_STAMP, 4096, $timeout);
        if ($res !== null && preg_match('/"last_updated_timestamp"\s*:\s*(\d+)/', $res, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    private static function curlGet(string $url, int $maxBytes, int $timeout): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'unraid.zh.translator/' . VERSION,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $code !== 200 || strlen($body) > $maxBytes) {
            return null;
        }
        return $body;
    }

    /**
     * 取应用源：优先复用 CA 本地缓存，必要时才下载。
     *
     * FEED_SOURCE=auto  （默认）本地缓存够新就用，否则下载
     * FEED_SOURCE=local 只用 CA 本地缓存（离线/省流量），没有就报错
     * FEED_SOURCE=online 只下载（放到我们的 var 缓存里）
     *
     * @return array{ok:bool,file?:string,error?:string,fresh?:bool,source?:string,stamp?:int,age?:int,from_local?:bool}
     */
    public static function fetch(bool $force = false, ?callable $log = null): array
    {
        $mode = strtolower(trim(Config::get('FEED_SOURCE', 'auto'))) ?: 'auto';
        $ttl = max(600, Config::int('FEED_CACHE_TTL', 21600));
        $say = function (string $m) use ($log): void {
            if ($log) {
                $log($m);
            }
        };
        $local = self::localFeed();
        $ownCache = self::cacheFile();
        $hasOwn = is_file($ownCache) && filesize($ownCache) >= self::MIN_FEED_BYTES;

        // 1) 只用本地
        if ($mode === 'local') {
            if ($local) {
                $say('使用 CA 本地应用源: ' . $local['file']);
                return [
                    'ok' => true, 'file' => $local['file'], 'fresh' => false, 'source' => 'ca-local',
                    'stamp' => $local['stamp'], 'age' => time() - $local['mtime'], 'from_local' => true,
                ];
            }
            return ['ok' => false, 'error' => '未找到 CA 本地应用源：请先在 WebUI 打开一次「应用 / Apps」页面，或把应用源切换为「自动」'];
        }

        // 2) 本地缓存是否最新：拿 37 字节的时间戳比对，避免白下 22MB
        if (!$force && $mode === 'auto' && $local) {
            $age = time() - $local['mtime'];
            if ($age <= $ttl) {
                $say('使用 CA 本地应用源（' . human_age($age) . '前）: ' . $local['file']);
                return [
                    'ok' => true, 'file' => $local['file'], 'fresh' => true, 'source' => 'ca-local',
                    'stamp' => $local['stamp'], 'age' => $age, 'from_local' => true,
                ];
            }
            $remote = self::remoteStamp();
            if ($remote > 0 && $local['stamp'] > 0 && $remote === $local['stamp']) {
                $say('CA 本地应用源已是最新（时间戳一致），无需下载');
                return [
                    'ok' => true, 'file' => $local['file'], 'fresh' => true, 'source' => 'ca-local',
                    'stamp' => $local['stamp'], 'age' => $age, 'from_local' => true,
                ];
            }
        }

        // 3) 下载（官方 CDN → GitHub 备份）
        $urls = array_values(array_unique(array_filter([
            Config::get('FEED_URL'),
            self::URL_PRIMARY,
            self::URL_BACKUP,
        ])));
        foreach ($urls as $url) {
            $res = self::download($url, $ownCache, $say);
            if ($res === null) {
                continue;
            }
            return [
                'ok' => true, 'file' => $ownCache, 'fresh' => true,
                'source' => str_contains($url, 'assets.ca.unraid.net') ? 'cdn' : (str_contains($url, 'githubusercontent') ? 'github' : 'custom'),
                'stamp' => $res, 'age' => 0, 'from_local' => false,
            ];
        }

        // 4) 下载失败：退回可用的旧数据
        if ($local) {
            $say('下载失败，退回 CA 本地应用源');
            return [
                'ok' => true, 'file' => $local['file'], 'fresh' => false, 'source' => 'ca-local',
                'stamp' => $local['stamp'], 'age' => time() - $local['mtime'], 'from_local' => true,
                'stale' => true,
            ];
        }
        if ($hasOwn) {
            $say('下载失败，使用上次的缓存（可能已过期）');
            return [
                'ok' => true, 'file' => $ownCache, 'fresh' => false, 'source' => 'own-cache',
                'stamp' => 0, 'age' => time() - (int)filemtime($ownCache), 'from_local' => false, 'stale' => true,
            ];
        }
        return ['ok' => false, 'error' => '无法获取应用源：本地没有 CA 缓存，在线下载也失败了'];
    }

    /** @return int|null 成功返回源时间戳 */
    private static function download(string $url, string $cache, ?callable $say = null): ?int
    {
        $tmp = $cache . '.part';
        $fp = @fopen($tmp, 'wb');
        if (!$fp) {
            return null;
        }
        if ($say) {
            $say('下载应用源: ' . $url);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_USERAGENT => 'unraid.zh.translator/' . VERSION,
            CURLOPT_ENCODING => '',
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code !== 200 || filesize($tmp) < self::MIN_FEED_BYTES) {
            jlog(sprintf('应用源下载失败 %s => HTTP %d %s', $url, $code, $err), 'WARN');
            @unlink($tmp);
            return null;
        }
        $head = (string)@file_get_contents($tmp, false, null, 0, 65536);
        if (!str_contains($head, '"applist"') && !str_contains($head, '"apps"')) {
            jlog('应用源内容不是预期格式: ' . $url, 'WARN');
            @unlink($tmp);
            return null;
        }
        if (!@rename($tmp, $cache)) {
            @unlink($tmp);
            return null;
        }
        $stamp = preg_match('/"last_updated_timestamp"\s*:\s*(\d+)/', $head, $m) ? (int)$m[1] : 0;
        return $stamp;
    }

    /**
     * 解析应用源并抽出待译片段。
     *
     * @return array{ok:bool,error?:string,segments?:array,apps?:array,meta?:array}
     */
    public static function extract(?string $file = null, ?callable $progress = null): array
    {
        $file = $file ?: self::cacheFile();
        if (!is_file($file)) {
            return ['ok' => false, 'error' => '应用源文件不存在: ' . $file];
        }
        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') {
            return ['ok' => false, 'error' => '应用源为空'];
        }
        $data = json_decode($raw, true);
        unset($raw);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => '应用源 JSON 解析失败'];
        }
        $apps = $data['applist'] ?? ($data['apps'] ?? null);
        if (!is_array($apps)) {
            return ['ok' => false, 'error' => '应用源缺少 applist 字段'];
        }
        if ($progress) {
            $progress(sprintf('解析完成：%d 个应用', count($apps)));
        }

        $segs = [];        // hash => [src, kind, app]
        $appRows = [];
        $skipNames = Config::bool('SKIP_NAMES');

        // ---- 分类树（侧边栏/筛选器里的文字）
        $catNodes = $data['categories'] ?? [];
        $walkCat = function ($nodes) use (&$walkCat, &$segs) {
            if (!is_array($nodes)) {
                return;
            }
            foreach ($nodes as $n) {
                if (!is_array($n)) {
                    continue;
                }
                foreach (['Des', 'Name'] as $f) {
                    if (isset($n[$f]) && is_string($n[$f])) {
                        $h = self::addSeg($segs, $n[$f], 'cat', '');
                        unset($h);
                    }
                }
                if (isset($n['Sub'])) {
                    $walkCat($n['Sub']);
                }
            }
        };
        $walkCat($catNodes);

        // ---- 逐个应用
        $i = 0;
        foreach ($apps as $app) {
            $i++;
            if (!is_array($app)) {
                continue;
            }
            // 应用源里带 Language 标记的条目本身已是本地化版本，跳过
            if (isset($app['Language']) && $app['Language'] !== '') {
                continue;
            }
            if ($progress && $i % 1000 === 0) {
                $progress(sprintf('已处理 %d/%d 个应用，片段 %d', $i, count($apps), count($segs)));
            }
            $key = self::appKey($app);
            $name = (string)($app['Name'] ?? ($app['PluginName'] ?? $key));
            $isPlugin = self::isPlugin($app);
            $ver = (string)($app['pluginVersion'] ?? ($app['Version'] ?? ($app['Branch']['Tag'] ?? '')));
            $nseg = 0;

            $take = function (string $text, string $kind, bool $multi = true) use (&$segs, $key, &$nseg) {
                $parts = $multi ? self::paragraphs($text) : [normalize_text($text)];
                foreach ($parts as $p) {
                    if (self::addSeg($segs, $p, $kind, $key) === null) {
                        continue;
                    }
                    $nseg++;
                }
            };

            if (!$skipNames && isset($app['Name'])) {
                $take((string)$app['Name'], 'overview', false);
            }
            if (isset($app['Overview']) && is_string($app['Overview'])) {
                $take($app['Overview'], 'overview');
            }
            if (isset($app['Requires']) && is_string($app['Requires'])) {
                $take($app['Requires'], 'requires');
            }
            if (isset($app['Branch']['TagDescription']) && is_string($app['Branch']['TagDescription'])) {
                $take($app['Branch']['TagDescription'], 'branch');
            }
            foreach (['CAComment', 'ModeratorComment'] as $f) {
                if (isset($app[$f]) && is_string($app[$f])) {
                    $take($app[$f], 'note');
                }
            }
            if (isset($app['Config']) && is_array($app['Config'])) {
                foreach ($app['Config'] as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $attr = $c['@attributes'] ?? [];
                    if (!is_array($attr)) {
                        continue;
                    }
                    if (isset($attr['Description']) && is_string($attr['Description'])) {
                        $take($attr['Description'], 'field');
                    }
                    if (isset($attr['Name']) && is_string($attr['Name'])) {
                        $take($attr['Name'], 'fieldname', false);
                    }
                }
            }

            $appRows[] = [
                'key' => $key,
                'name' => mb_substr($name, 0, 160),
                'kind' => $isPlugin ? 'plugin' : 'docker',
                'ver' => mb_substr($ver, 0, 60),
                'nseg' => $nseg,
                'ndone' => 0,
            ];
        }

        $rows = [];
        foreach ($segs as $h => $s) {
            $rows[] = ['h' => $h, 'src' => $s[0], 'kind' => $s[1], 'app' => $s[2]];
        }
        $meta = [
            'feed_updated' => (string)($data['last_updated'] ?? ''),
            'feed_apps' => count($apps),
            'feed_repositories' => is_array($data['repositories'] ?? null) ? count($data['repositories']) : 0,
            'feed_ts' => is_file($file) ? (int)filemtime($file) : time(),
        ];
        return ['ok' => true, 'segments' => $rows, 'apps' => $appRows, 'meta' => $meta];
    }

    /** 长文本按空行/换行切成段落 */
    public static function paragraphs(string $text): array
    {
        $norm = normalize_text($text);
        if ($norm === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\n{1,}/u', $norm) ?: [] as $line) {
            $line = trim($line);
            if (is_translatable($line)) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /** @return string|null 返回 hash；不可译或重复时返回 null */
    public static function addSeg(array &$segs, string $text, string $kind, string $app): ?string
    {
        if (!is_translatable($text)) {
            return null;
        }
        $norm = normalize_text($text);
        $h = segment_hash($norm);
        if (isset($segs[$h])) {
            return null;
        }
        $segs[$h] = [$norm, $kind, $app];
        return $h;
    }

    public static function appKey(array $app): string
    {
        foreach (['Repository', 'PluginURL', 'Project', 'Name'] as $f) {
            if (isset($app[$f]) && is_string($app[$f]) && trim($app[$f]) !== '') {
                return mb_substr(trim($app[$f]), 0, 250);
            }
        }
        return 'unknown-' . substr(sha1(json_encode($app) ?: ''), 0, 12);
    }

    public static function isPlugin(array $app): bool
    {
        if (isset($app['Plugin']) && $app['Plugin']) {
            return true;
        }
        $repo = (string)($app['Repository'] ?? '');
        $url = (string)($app['PluginURL'] ?? '');
        return str_ends_with(strtolower($repo), '.plg') || str_ends_with(strtolower($url), '.plg');
    }
}
