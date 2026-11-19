<?php
/**
 * GET /plugins/unraid.zh.translator/config.php.js
 * 给前端注入运行时配置（含 CSRF token），只在已登录的 WebUI 环境里被访问。
 */
declare(strict_types=1);

namespace UZT;

require_once __DIR__ . '/../include/translator.php';

// 只允许同源页面取用（CSRF token 不应当被跨站 <script> 拿到）
$site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
    http_response_code(403);
    header('Content-Type: application/javascript; charset=utf-8');
    echo "/* forbidden: cross-site */\n";
    exit;
}
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($site === '' && $ref !== '') {
    $host = parse_url($ref, PHP_URL_HOST);
    $self = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== null && $self !== '' && strcasecmp($host, (string)parse_url('http://' . $self, PHP_URL_HOST)) !== 0) {
        http_response_code(403);
        header('Content-Type: application/javascript; charset=utf-8');
        echo "/* forbidden: cross-origin referer */\n";
        exit;
    }
}

$cfg = Config::all();
$base = '/plugins/unraid.zh.translator';
$payload = [
    'endpoint' => $base . '/ajax',
    'csrf' => csrf_token(),
    'enabled' => Config::bool('ENABLED'),
    'scope' => $cfg['SCOPE'],
    'autoNew' => Config::bool('AUTO_NEW'),
    'syncLookup' => Config::bool('SYNC_ON_LOOKUP'),
    'version' => VERSION,
    'debug' => Config::bool('DEBUG'),
];

if (!headers_sent()) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}
echo 'window.__UZT__ = ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n";
