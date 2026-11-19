<?php
/**
 * unraid.zh.translator — 已安装插件（原生「插件」页面）文案预热
 *
 * 原生 /Plugins 页面的描述来自 /boot/config/plugins/*.plg 里的
 * <DESCRIPTION> 与 <CHANGES>。这里把它们也抽成片段做预热；
 * 即使漏掉也没关系——页面上的英文会被 lookup 按需补译。
 */
declare(strict_types=1);

namespace UZT;

final class SourceLocal
{
    public static function pluginGlob(): string
    {
        return '/boot/config/plugins/*.plg';
    }

    /**
     * @return array{ok:bool,segments:array,apps:array,meta:array,error?:string}
     */
    public static function extract(): array
    {
        $files = glob(self::pluginGlob()) ?: [];
        sort($files);
        $segs = [];
        $apps = [];
        $seenNames = [];

        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $name = self::attr($raw, 'name') ?: basename($file, '.plg');
            if (isset($seenNames[$name])) {
                continue; // 同一个插件可能同时存在 -dev / 备份文件
            }
            $seenNames[$name] = true;
            $version = self::attr($raw, 'version');
            $nseg = 0;

            $desc = self::block($raw, 'DESCRIPTION');
            foreach (SourceFeed::paragraphs($desc) as $p) {
                if (SourceFeed::addSeg($segs, $p, 'localdesc', $name) !== null) {
                    $nseg++;
                }
            }
            $changes = self::block($raw, 'CHANGES');
            foreach (SourceFeed::paragraphs($changes) as $p) {
                // 更新日志里的版本号行不送翻译
                if (preg_match('/^[\d.\-–\s]+$/', $p)) {
                    continue;
                }
                if (SourceFeed::addSeg($segs, $p, 'localchg', $name) !== null) {
                    $nseg++;
                }
            }
            $apps[] = [
                'key' => 'plg:' . $name,
                'name' => mb_substr((string)$name, 0, 160),
                'kind' => 'local',
                'ver' => mb_substr($version, 0, 60),
                'nseg' => $nseg,
                'ndone' => 0,
            ];
        }

        $rows = [];
        foreach ($segs as $h => $s) {
            $rows[] = ['h' => $h, 'src' => $s[0], 'kind' => $s[1], 'app' => $s[2]];
        }
        return [
            'ok' => true,
            'segments' => $rows,
            'apps' => $apps,
            'meta' => ['plugins' => count($apps), 'files' => count($files)],
        ];
    }

    private static function attr(string $xml, string $attr): string
    {
        if (preg_match('/<PLUGIN\b[^>]*\b' . preg_quote($attr, '/') . '\s*=\s*"([^"]*)"/i', $xml, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }
        return '';
    }

    private static function block(string $xml, string $tag): string
    {
        if (!preg_match('/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '>/is', $xml, $m)) {
            return '';
        }
        $inner = $m[1];
        // 去掉 CDATA 包裹
        if (preg_match('/<!\[CDATA\[(.*?)\]\]>/s', $inner, $c)) {
            $inner = $c[1];
        }
        $inner = preg_replace('#<br\s*/?>#i', "\n", $inner) ?? $inner;
        $inner = strip_tags($inner);
        return html_entity_decode($inner, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
