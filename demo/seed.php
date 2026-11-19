<?php
/**
 * 演示用种子数据：把 demo/store.html 里出现的英文写成「人工中文译文」，
 * 让浏览器截图能直观看到替换效果（真实使用时这些译文来自大模型）。
 *
 * 用法: php demo/seed.php
 */
declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/unraid.zh.translator/include/util.php';
require_once '/usr/local/emhttp/plugins/unraid.zh.translator/include/config.php';
require_once '/usr/local/emhttp/plugins/unraid.zh.translator/include/store.php';

use UZT\Store;

/** 英文 => 中文（人工翻译，用于演示） */
const DEMO = [
    // 侧边栏分类
    'Media Applications' => '媒体应用',
    'Media Servers' => '媒体服务器',
    'Tools / Utilities' => '工具 / 实用程序',
    'Downloaders' => '下载工具',
    'Home Automation' => '家庭自动化',
    'Backup' => '备份',
    'Security' => '安全',
    'Network Services' => '网络服务',
    'Productivity' => '效率工具',

    // Jellyfin
    'Jellyfin is a free software media system that puts you in control of managing and streaming your media.'
        => 'Jellyfin 是一套自由软件的媒体系统，让你完全掌控媒体的管理与串流。',
    'It is an alternative to the proprietary Emby and Plex, to provide media from a dedicated server to end-user devices.'
        => '它是闭源 Emby 与 Plex 的替代方案，把媒体从专用服务器推送到各种终端设备。',
    'This container requires host networking to work correctly.'
        => '此容器需要主机网络（host）模式才能正常工作。',
    'Use the WebUI link to finish the first-run wizard, then add your libraries.'
        => '点击 Web 界面链接完成首次配置向导，然后添加你的媒体库。',
    'A valid media library. amd64 or arm64.' => '需要一个可用的媒体库。支持 amd64 或 arm64。',
    'Where Jellyfin stores its database, metadata and transcode cache. Put this on your cache pool.'
        => 'Jellyfin 存放数据库、元数据与转码缓存的位置。建议放在缓存池上。',
    'App Data' => '应用数据',
    'User ID of the user that will own the media files.' => '拥有这些媒体文件的用户 ID。',

    // Duplicati
    'Duplicati is a backup client that securely stores encrypted, incremental, compressed backups on cloud storage services and remote file servers.'
        => 'Duplicati 是一款备份客户端，可把加密、增量、压缩后的备份安全地存放到云存储服务与远程文件服务器上。',
    'A backup destination: S3, WebDAV, SFTP or a mounted share.' => '一个备份目标：S3、WebDAV、SFTP 或已挂载的共享目录。',

    // Vast.ai Monitor（插件）
    'Adds a dashboard tile for your Vast.ai host machines.' => '为你的 Vast.ai 主机增加一块仪表盘磁贴。',
    "Shows each machine's rental state, live hourly earnings and GPU temperature with colour thresholds."
        => '显示每台机器的出租状态、实时时薪收益，以及带颜色阈值的 GPU 温度。',
    'Latest stable release from the author.' => '作者发布的最新稳定版。',

    // Immich
    'Immich is a self-hosted photo and video backup solution that works like a private Google Photos.'
        => 'Immich 是一套自托管的照片与视频备份方案，用起来就像私有的 Google 相册。',
    'Machine learning features require an Intel, NVIDIA or AMD GPU for the best experience.'
        => '机器学习相关功能建议搭配 Intel、NVIDIA 或 AMD 显卡以获得最佳体验。',
    'Requires a PostgreSQL database and a Redis instance, both included in this template.'
        => '需要 PostgreSQL 数据库与 Redis 实例，本模板已内置。',

    // Frigate
    'Frigate is an NVR with realtime local object detection for IP cameras.' => 'Frigate 是一套网络录像机（NVR），可在本地对 IP 摄像头画面做实时目标检测。',
    'A Coral USB Accelerator or an Intel iGPU is strongly recommended for detection.' => '强烈建议使用 Coral USB 加速器或 Intel 核显来做检测。',

    // 原生插件页
    'Unraid Connect lets you manage your server remotely and keeps a flash backup in the cloud.'
        => 'Unraid Connect 可以远程管理你的服务器，并把 U 盘备份保存到云端。',
    'Fixes an issue where the dashboard would not refresh after changing the theme.' => '修复了切换主题后仪表盘不刷新的问题。',
    'Adds a widget that shows array temperatures and fan speeds.' => '新增一个显示阵列温度与风扇转速的小部件。',

    'Requires:' => '需要：',
    'Change log:' => '更新日志：',

    // 通用 UI 片段
    'Sort by' => '排序方式',
    'Categories' => '分类',
    'Description' => '描述',
    'Version' => '版本',
    'Plugin' => '插件',
    'Official' => '官方',
    'Show more' => '显示更多',
    'Change log' => '更新日志',
    'Install' => '安装',
    'Settings' => '设置',
];

$store = Store::open();
$rows = [];
$done = [];
foreach (DEMO as $en => $zh) {
    $norm = \UZT\normalize_text($en);
    $h = \UZT\segment_hash($norm);
    $rows[] = ['h' => $h, 'src' => $norm, 'kind' => 'demo', 'app' => 'demo'];
    $done[] = ['h' => $h, 'dst' => $zh];
}
$up = $store->upsertSegments($rows);
$n = $store->setDone($done);
$store->flush();

printf("演示词条：新增 %d，命中 %d，写入译文 %d 条\n", $up['added'], $up['existed'], $n);
