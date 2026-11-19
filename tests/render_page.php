<?php
/**
 * 页面渲染冒烟测试：模拟 emhttp 的 include 方式渲染 .page 文件，
 * 捕获 PHP 警告/致命错误，并检查关键 DOM 是否齐全。
 *
 * 运行：php tests/render_page.php
 */
declare(strict_types=1);

$pluginDir = '/usr/local/emhttp/plugins/unraid.zh.translator';
if (!is_dir($pluginDir)) {
    fwrite(STDERR, "请先安装插件到 {$pluginDir}\n");
    exit(1);
}

$fail = 0;
$check = static function (string $name, bool $cond, string $extra = '') use (&$fail): void {
    if ($cond) {
        echo "  ✓ {$name}\n";
    } else {
        echo "  ✗ {$name} {$extra}\n";
        $fail++;
    }
};

// emhttp 会提供 $var（含 csrf_token）；这里照抄一份
$var = ['csrf_token' => 'testtoken123', 'version' => '7.2.0', 'NAME' => 'Tower'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/Settings/unraid.zh.translator';

echo "— 渲染设置页 unraid.zh.translator.page —\n";
ob_start();
$err = '';
set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$err): bool {
    $err .= sprintf("%s: %s (%s:%d)\n", $no, $str, basename($file), $line);
    return true;
});
try {
    include $pluginDir . '/unraid.zh.translator.page';
} catch (\Throwable $e) {
    $err .= 'EXCEPTION: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
restore_error_handler();
$html = (string)ob_get_clean();

$check('无 PHP 错误/警告', $err === '', $err);
$check('输出长度合理', strlen($html) > 8000, (string)strlen($html));
$check('包含 CSRF token', str_contains($html, 'testtoken123'));
$check('包含统计卡片', str_contains($html, 'id="s-done"') && str_contains($html, '已翻译片段'));
$check('包含大模型配置项', str_contains($html, 'id="API_BASE"') && str_contains($html, 'id="MODEL"'));
$check('包含任务按钮', str_contains($html, 'data-act="sync"') && str_contains($html, 'data-act="translate"'));
$check('包含日志面板', str_contains($html, 'id="log-box"'));
$check('API Key 未被渲染进 HTML', !str_contains($html, (string)\UZT\Config::get('API_KEY')) || \UZT\Config::get('API_KEY') === '');

echo "— 渲染注入页 store-zh-inject.page —\n";
echo "  (注入页依赖 UZT\\Config，单独渲染)\n";
ob_start();
$err2 = '';
set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$err2): bool {
    $err2 .= sprintf("%s: %s (%s:%d)\n", $no, $str, basename($file), $line);
    return true;
});
try {
    include $pluginDir . '/store-zh-inject.page';
} catch (\Throwable $e) {
    $err2 .= 'EXCEPTION: ' . $e->getMessage() . "\n";
}
restore_error_handler();
$inject = (string)ob_get_clean();

$check('注入页无错误', $err2 === '', $err2);
$enabled = \UZT\Config::bool('ENABLED');
if ($enabled) {
    $check('注入页输出了归一化脚本', str_contains($inject, 'js/uzt-norm.js'));
    $check('注入页输出了主脚本', str_contains($inject, 'js/store-zh.js'));
    $check('注入页输出了配置接口', str_contains($inject, 'ajax/configjs.php'));
} else {
    $check('停用时注入页不输出脚本', trim($inject) === '', $inject);
}

echo "— configjs.php —\n";
ob_start();
include $pluginDir . '/ajax/configjs.php';
$js = (string)ob_get_clean();
$check('configjs 是合法 JS 赋值', (bool)preg_match('/^window\.__UZT__ = \{.*\};$/s', trim($js)), substr($js, 0, 120));
$parsed = null;
if (preg_match('/^window\.__UZT__ = (\{.*\});$/s', trim($js), $m)) {
    $parsed = json_decode($m[1], true);
}
$check('configjs 含 csrf', is_array($parsed) && ($parsed['csrf'] ?? '') === 'testtoken123');
$check('configjs 不含 API Key', is_array($parsed) && !array_key_exists('API_KEY', $parsed));

echo $fail === 0 ? "\n全部渲染检查通过\n" : "\n{$fail} 项渲染检查失败\n";
exit($fail === 0 ? 0 : 1);
