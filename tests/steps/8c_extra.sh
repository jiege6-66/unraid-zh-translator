#!/bin/bash
# 由 tests/run-tests.sh source：预算 / 失败重试 / 词库日志模式

step "8c. 预算 / 失败重试 / 词库安全"

# ---------- M4：每小时预算超限后，同步翻译应停止、只入队
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
UZT\Config::save(["HOURLY_CHAR_BUDGET" => "1", "SYNC_MAX_CHARS" => "20000"]);
' >/dev/null 2>&1
python3 - > /tmp/uzt-budget-req.json <<'PY'
import json
print(json.dumps({"csrf": "testtoken123",
                  "texts": ["Budget guarded sentence that should be queued rather than translated."]}))
PY
curl -sS -m 30 -X POST "http://127.0.0.1:${HTTP_PORT}/plugins/unraid.zh.translator/ajax/lookup.php" \
  -H 'Content-Type: application/json' --data-binary @/tmp/uzt-budget-req.json > /tmp/uzt-budget.json
if python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-budget.json'))
assert d.get('ok'), d
assert d.get('translated_now', 0) == 0, '超出预算仍同步翻译了: %s' % d
assert d.get('queued', 0) > 0, '超出预算应入队: %s' % d
print('  ✓ 超出每小时预算后只入队、不即时调用模型')
PY
then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ 预算未生效"; fi

# ---------- M3：失败片段可被一键放回队列
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
require "'"$LIVE"'/include/store.php";
$h = UZT\segment_hash("A permanently failing sentence used by the retry test.");
UZT\Store::open()->upsertSegments([["h" => $h, "src" => "A permanently failing sentence used by the retry test.", "kind" => "live", "app" => ""]]);
UZT\Store::open()->setFail($h, "simulated failure");
' >/dev/null 2>&1
RETRY=$(curl -sS -m 20 -X POST "http://127.0.0.1:${HTTP_PORT}/plugins/unraid.zh.translator/ajax/settings.php" \
  -H 'Content-Type: application/json' -d '{"action":"retry-failed","csrf":"testtoken123"}')
printf '%s' "$RETRY" > /tmp/uzt-retry.json
if python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-retry.json'))
assert d.get('ok') and d.get('reset', 0) >= 1, d
print('  ✓ 失败片段可一键放回队列（%d 条）' % d['reset'])
PY
then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ retry-failed 接口异常: $RETRY"; fi

# ---------- S4：词库使用崩溃安全的日志模式
JOUR=$(php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
require "'"$LIVE"'/include/store.php";
UZT\Store::open()->stats();
$p = new PDO("sqlite:" . UZT\Store::dbFile());
$j = strtolower((string)$p->query("PRAGMA journal_mode")->fetchColumn());
$y = (int)$p->query("PRAGMA synchronous")->fetchColumn();
echo $j . "/" . $y;
')
case "$JOUR" in
  delete/2|delete/1|wal/2) ok "词库日志模式为崩溃安全（$JOUR）" ;;
  *) bad "词库日志模式不安全" "$JOUR" ;;
esac

# ---------- 恢复后续步骤要用的配置
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
UZT\Config::save(["API_BASE" => "http://127.0.0.1:'"$MOCK_PORT"'/v1", "API_KEY" => "sk-mock",
                  "MODEL" => "mock-model", "SYNC_MAX_CHARS" => "20000",
                  "HOURLY_CHAR_BUDGET" => "120000"]);
' >/dev/null 2>&1
