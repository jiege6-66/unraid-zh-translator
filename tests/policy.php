<?php
/**
 * 策略单测：哪些文本值得翻译、队列精简的边界。
 * 这些规则直接决定「会不会去翻应用名」和「会不会白烧额度」，
 * 之前就在这里踩过 PHP 把 bool 和数字比较的坑。
 *
 * 运行：php tests/policy.php
 */
declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/unraid.zh.translator/include/translator.php';

$fail = 0;
$check = static function (string $name, bool $cond, string $extra = '') use (&$fail): void {
    if ($cond) {
        echo "  ✓ {$name}\n";
    } else {
        echo "  ✗ {$name} {$extra}\n";
        $fail++;
    }
};

echo "— 页面即时采集策略（LIVE_MIN_CHARS=40 或 ≥6 个英文单词）—\n";
$m = new ReflectionMethod('UZT\Translator', 'qualifiesAsLive');
$m->setAccessible(true);
$qual = static fn(string $s): bool => (bool)$m->invoke(null, \UZT\normalize_text($s));

foreach ([
    'Jellyfin', 'Immich', 'Install', 'Settings', 'Sonarr', 'Radarr',
    'Media Servers', 'amd64', 'latest', 'OK', 'GPU',
] as $short) {
    $check('不翻译：' . $short, !$qual($short));
}
foreach ([
    'This container stores its database and metadata in the appdata folder, and is rebuilt automatically.',
    'Requires a PostgreSQL database and a Redis instance, both included in this template.',
    'Adds a dashboard tile for your Vast.ai host machines.',
    'Jellyfin is a free software media system that puts you in control of managing and streaming your media.',
] as $long) {
    $check('要翻译：' . mb_substr($long, 0, 40) . '…', $qual($long));
}

echo "— 队列精简（阈值 40 字符 / 只翻商店时清掉本机插件）—\n";
$cfgFile = \UZT\Config::file();
$backup = is_file($cfgFile) ? file_get_contents($cfgFile) : null;
\UZT\Config::save(['SCOPE' => 'store']);
\UZT\Store::open()->wipe();

$rows = [
    ['h' => \UZT\segment_hash('NameOnly'), 'src' => 'NameOnly', 'kind' => 'fieldname', 'app' => 't'],
    ['h' => \UZT\segment_hash('LocalPluginOnly'), 'src' => 'LocalPluginOnly', 'kind' => 'localdesc', 'app' => 't'],
    ['h' => \UZT\segment_hash('LongDesc'), 'src' => 'A long descriptive sentence that should survive compaction because it is real content.', 'kind' => 'overview', 'app' => 't'],
    ['h' => \UZT\segment_hash('DoneShort'), 'src' => 'Done', 'kind' => 'fieldname', 'app' => 't'],
];
\UZT\Store::open()->upsertSegments($rows);
\UZT\Store::open()->setDone([['h' => \UZT\segment_hash('DoneShort'), 'dst' => '完成']]);

$res = \UZT\Translator::compact();
$check('精简掉 2 条（短文本 + 本机插件）', ($res['removed'] ?? 0) === 2, json_encode($res, JSON_UNESCAPED_UNICODE));

$left = \UZT\Store::open()->getByHashes(array_column($rows, 'h'));
$check('长简介保留', isset($left[\UZT\segment_hash('LongDesc')]));
$check('短标签被清理', !isset($left[\UZT\segment_hash('NameOnly')]));
$check('本机插件片段被清理', !isset($left[\UZT\segment_hash('LocalPluginOnly')]));
$check('已翻译的片段不受影响', isset($left[\UZT\segment_hash('DoneShort')]));

// 还原配置与数据
if ($backup !== null) {
    @file_put_contents($cfgFile, $backup);
} else {
    \UZT\Config::reset();
}
\UZT\Store::open()->wipe();

echo $fail === 0 ? "\n策略检查全部通过\n" : "\n{$fail} 项策略检查失败\n";
exit($fail === 0 ? 0 : 1);
