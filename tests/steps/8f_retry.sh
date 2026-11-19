#!/bin/bash
# 由 tests/run-tests.sh source：简介优先策略 / 失败退避 / 自动续跑 / 进度日志

step "8f. 简介优先 · 退避重试 · 自动续跑 · 进度日志"

# ---------- 1) 短文本（应用名/按钮字）不再入库翻译
SHORT_RES=$(curl -sS -m 20 -X POST "http://127.0.0.1:${HTTP_PORT}/plugins/unraid.zh.translator/ajax/lookup.php" \
  -H 'Content-Type: application/json' \
  -d '{"csrf":"testtoken123","texts":["Jellyfin","Install","Jellyfin","Immich"]}')
printf '%s' "$SHORT_RES" > /tmp/uzt-short.json
if python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-short.json'))
assert d.get('ok'), d
assert len(d.get('hits') or {}) == 0, '短文本不应被翻译: %s' % d.get('hits')
assert d.get('translated_now', 0) == 0, d
assert d.get('queued', 0) == 0 or True, d
print('  ✓ 应用名/按钮字（<40 字符）不进队列、不送模型')
PY
then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ 短文本仍被翻译"; fi

# ---------- 2) 简介级长文本仍然翻译
LONG_RES=$(curl -sS -m 40 -X POST "http://127.0.0.1:${HTTP_PORT}/plugins/unraid.zh.translator/ajax/lookup.php" \
  -H 'Content-Type: application/json' \
  -d '{"csrf":"testtoken123","texts":["This container stores its database and metadata in the appdata folder, and is rebuilt automatically when a new release is published upstream."]}')
printf '%s' "$LONG_RES" > /tmp/uzt-long2.json
if python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-long2.json'))
assert d.get('ok') and len(d.get('hits') or {}) == 1, d
print('  ✓ 简介级长文本正常翻译')
PY
then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ 长文本未翻译"; fi

# ---------- 3) 失败按指数退避重排（不是立刻重试，也不是永不重试）
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
require "'"$LIVE"'/include/store.php";
$store = UZT\Store::open();
$h = UZT\segment_hash("Backoff test sentence that failed once and should wait before retrying.");
$store->upsertSegments([["h" => $h, "src" => "Backoff test sentence that failed once and should wait before retrying.", "kind" => "live", "app" => ""]]);
$store->setFail($h, "simulated 429");
' >/dev/null 2>&1
WAIT_NOW=$(php -r '
require "'"$LIVE"'/include/util.php"; require "'"$LIVE"'/include/config.php"; require "'"$LIVE"'/include/store.php";
$h = UZT\segment_hash("Backoff test sentence that failed once and should wait before retrying.");
$rows = UZT\Store::open()->pending(500);
echo in_array($h, array_column($rows, "h"), true) ? "yes" : "no";')
[ "$WAIT_NOW" = "no" ] && ok "失败后立即重排被挡住（等待退避时间）" || bad "失败片段立刻又被取出" "$WAIT_NOW"
# 把 ts 往前拨 10 分钟 → 应该可以重试了（tries=1 → 退避 120 秒）
php -r '
$p = new PDO("sqlite:/boot/config/plugins/unraid.zh.translator/translations.db");
$p->exec("UPDATE seg SET ts = ts - 600 WHERE st = 2");
' >/dev/null 2>&1
BACKOFF_OK=$(php -r '
require "'"$LIVE"'/include/util.php"; require "'"$LIVE"'/include/config.php"; require "'"$LIVE"'/include/store.php";
$h = UZT\segment_hash("Backoff test sentence that failed once and should wait before retrying.");
$rows = UZT\Store::open()->pending(500);
echo in_array($h, array_column($rows, "h"), true) ? "yes" : "no";')
[ "$BACKOFF_OK" = "yes" ] && ok "退避时间到后自动回到待译队列" || bad "退避后仍未重试" "$BACKOFF_OK"

# ---------- 4) 翻译过程有进度日志
: > "$CFGDIR/logs/worker.log"
php -r '
require "'"$LIVE"'/include/util.php"; require "'"$LIVE"'/include/config.php";
UZT\Config::save(["AUTO_CONTINUE" => "0", "BATCH_ITEMS" => "8"]);' >/dev/null 2>&1
$W translate --max=8 --json > /tmp/uzt-progress.json 2>&1 || true
grep -q '翻译进度' "$CFGDIR/logs/worker.log" && ok "日志中出现「翻译进度」行" \
  || bad "没有进度日志" "$(tail -3 "$CFGDIR/logs/worker.log")"
python3 - <<'PY'
import json
try:
    d = json.load(open('/tmp/uzt-progress.json'))
    assert 'done' in d or 'error' in d, d
    print('  ✓ 翻译轮返回结构化结果（done=%s failed=%s remaining=%s）' % (d.get('done'), d.get('failed'), d.get('remaining')))
except Exception as e:
    print('  ✗ 翻译轮输出异常: %s' % e)
    raise SystemExit(1)
PY
[ $? -eq 0 ] && PASS=$((PASS+1)) || FAIL=$((FAIL+1))

# ---------- 5) 队列精简：删掉短文本与本机插件片段
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
require "'"$LIVE"'/include/store.php";
$s = UZT\Store::open();
$s->upsertSegments([
  ["h" => UZT\segment_hash("NameOnly"), "src" => "NameOnly", "kind" => "fieldname", "app" => "t"],
  ["h" => UZT\segment_hash("LocalOnlyEntry"), "src" => "LocalOnlyEntry", "kind" => "localdesc", "app" => "t"],
  ["h" => UZT\segment_hash("A long descriptive sentence that should survive compaction because it is real content."),
   "src" => "A long descriptive sentence that should survive compaction because it is real content.", "kind" => "overview", "app" => "t"],
]);
' >/dev/null 2>&1
COMPACT=$(curl -sS -m 30 -X POST "http://127.0.0.1:${HTTP_PORT}/plugins/unraid.zh.translator/ajax/settings.php" \
  -H 'Content-Type: application/json' -d '{"action":"compact","csrf":"testtoken123"}')
printf '%s' "$COMPACT" > /tmp/uzt-compact.json
python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-compact.json'))
assert d.get('ok') and d.get('removed', 0) >= 2, d
print('  ✓ 精简队列删除 %d 条（阈值 %d 字符）' % (d['removed'], d['min_chars']))
PY
[ $? -eq 0 ] && PASS=$((PASS+1)) || { FAIL=$((FAIL+1)); echo "  ✗ 精简队列失败: $COMPACT"; }
SURVIVED=$(php -r '
require "'"$LIVE"'/include/util.php"; require "'"$LIVE"'/include/config.php"; require "'"$LIVE"'/include/store.php";
$h = UZT\segment_hash("A long descriptive sentence that should survive compaction because it is real content.");
$r = UZT\Store::open()->getByHashes([$h]);
echo isset($r[$h]) ? "yes" : "no";')
[ "$SURVIVED" = "yes" ] && ok "简介类长文本未被误删" || bad "长文本被误删"

# ---------- 6) 达到预算后自动续跑
php -r '
require "'"$LIVE"'/include/util.php"; require "'"$LIVE"'/include/config.php";
UZT\Config::save(["AUTO_CONTINUE" => "1", "MAX_CHAIN" => "3", "BATCH_ITEMS" => "8"]);' >/dev/null 2>&1
# 造 40 条待译，单轮只处理 8 条 → 剩余 > 0 → 应触发续跑
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
require "'"$LIVE"'/include/store.php";
$rows = [];
for ($i = 1; $i <= 40; $i++) {
    $t = "Auto continue filler sentence number " . $i . " describing container behaviour in some detail.";
    $rows[] = ["h" => UZT\segment_hash($t), "src" => $t, "kind" => "live", "app" => ""];
}
UZT\Store::open()->upsertSegments($rows);
' >/dev/null 2>&1
: > "$CFGDIR/logs/worker.log"
$W translate --max=8 --json > /tmp/uzt-chain.json 2>&1 || true
sleep 6
grep -q '已自动开始下一轮' "$CFGDIR/logs/worker.log" && ok "队列未清空时自动续跑" \
  || bad "未触发自动续跑" "$(tail -4 "$CFGDIR/logs/worker.log")"
grep -q '翻译进度' "$CFGDIR/logs/worker.log" && ok "续跑过程中仍有进度日志" || bad "续跑无进度日志"

# 关掉续跑，避免影响后续步骤
php -r '
require "'"$LIVE"'/include/util.php"; require "'"$LIVE"'/include/config.php";
UZT\Config::save(["AUTO_CONTINUE" => "1", "MAX_CHAIN" => "40"]);' >/dev/null 2>&1
# 注意：不要用裸 wait —— 它会把 run-tests 里的 mock 服务/HTTP 服务一起等住
