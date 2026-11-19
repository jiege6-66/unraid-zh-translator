#!/bin/bash
# 由 tests/run-tests.sh source：后台任务启动链路（php-fpm 里 PHP_BINARY 不是 CLI 的坑）

step "8d. 后台任务启动链路"

FAKE_FPM=/tmp/uzt-fake-fpm
cat > "$FAKE_FPM" <<'EOS'
#!/bin/sh
echo "Usage: php-fpm [-n] [-e] [-h] [-i] [-m] [-v] [-t] [-p <prefix>]"
exit 0
EOS
chmod +x "$FAKE_FPM"

FAKE_CLI=/tmp/uzt-fake-cli
cat > "$FAKE_CLI" <<'EOS'
#!/bin/sh
case "$*" in *PHP_SAPI*) echo cli ;; esac
exit 0
EOS
chmod +x "$FAKE_CLI"

PHPQ() {
  php -r '
require "'"$LIVE"'/include/translator.php";
$a = array_slice($argv, 1);
if (($a[0] ?? "") === "cli") {
    echo UZT\php_cli_binary();
} elseif (($a[0] ?? "") === "spawn") {
    echo json_encode(UZT\spawn_worker(array_slice($a, 1)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}' "$@"
}

# ---------- 1) 能探测出真正的 CLI（而不是 php-fpm）
CLI_BIN=$(PHPQ cli)
if [ -n "$CLI_BIN" ] && [ -x "$CLI_BIN" ] && [ "$("$CLI_BIN" -r 'echo PHP_SAPI;' 2>/dev/null)" = "cli" ]; then
  ok "探测到 PHP CLI：$CLI_BIN（SAPI=cli）"
else
  bad "PHP CLI 探测" "$CLI_BIN"
fi
case "$(basename "$CLI_BIN")" in
  *fpm*) bad "PHP CLI 探测选中了 FPM 二进制" "$CLI_BIN" ;;
  *) ok "没有把 php-fpm 当成 CLI" ;;
esac

# ---------- 2) PHP_CLI 指向 FPM 时自动跳过，回落到真 CLI
php -r '
require "'"$LIVE"'/include/util.php"; require "'"$LIVE"'/include/config.php";
UZT\Config::save(["PHP_CLI" => "'"$FAKE_FPM"'"]);
' >/dev/null 2>&1
CLI2=$(PHPQ cli)
if [ "$CLI2" != "$FAKE_FPM" ] && [ -n "$CLI2" ]; then
  ok "PHP_CLI 配成 FPM 二进制时会被识破并回落（$CLI2）"
else
  bad "FPM 指纹识别" "返回了 $CLI2"
fi

# ---------- 3) 启动后没有任何输出 → 必须报失败（而不是假装成功）
php -r '
require "'"$LIVE"'/include/util.php"; require "'"$LIVE"'/include/config.php";
UZT\Config::save(["PHP_CLI" => "'"$FAKE_CLI"'"]);
' >/dev/null 2>&1
SILENT=$(PHPQ spawn stats)
printf '%s' "$SILENT" > /tmp/uzt-spawn-silent.json
if python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-spawn-silent.json'))
assert d.get('ok') is False, d
assert '未能启动' in (d.get('error') or ''), d
print('  ✓ 后台任务无输出时被判定为启动失败')
PY
then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ 未检测到启动失败: $SILENT"; fi

# ---------- 4) 正常启动：返回 ok 且日志出现 worker 启动行
php -r '
require "'"$LIVE"'/include/util.php"; require "'"$LIVE"'/include/config.php";
UZT\Config::save(["PHP_CLI" => ""]);
' >/dev/null 2>&1
: > "$CFGDIR/logs/worker.log"
GOOD=$(PHPQ spawn stats)
printf '%s' "$GOOD" > /tmp/uzt-spawn-good.json
if python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-spawn-good.json'))
assert d.get('ok') is True, d
print('  ✓ 后台任务启动成功（php=%s）' % d.get('php'))
PY
then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ 正常启动失败: $GOOD"; fi
sleep 0.6
grep -q 'worker 启动' "$CFGDIR/logs/worker.log" && ok "日志中出现 worker 启动记录" \
  || bad "日志缺少 worker 启动记录" "$(tail -3 "$CFGDIR/logs/worker.log")"

# ---------- 5) settings.php 的 sync 动作（用户点按钮的真实路径）
SYNC_RES=$(curl -sS -m 20 -X POST "http://127.0.0.1:${HTTP_PORT}/plugins/unraid.zh.translator/ajax/settings.php" \
  -H 'Content-Type: application/json' -d '{"action":"sync","csrf":"testtoken123"}')
printf '%s' "$SYNC_RES" > /tmp/uzt-sync-spawn.json
if python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-sync-spawn.json'))
assert d.get('ok') is True, d
assert d.get('spawned', {}).get('ok') is True, d
print('  ✓ 「同步应用源」按钮能真正拉起后台任务')
PY
then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ 同步按钮未拉起任务: $SYNC_RES"; fi

rm -f "$FAKE_FPM" "$FAKE_CLI"
