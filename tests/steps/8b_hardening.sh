#!/bin/bash
# 由 tests/run-tests.sh source 执行（复用其变量与 ok/bad/PASS/FAIL）
# 覆盖审计发现的加固项：长文本分片、品牌名、密钥清除、Cron 白名单、configjs 跨站

step "8b. 分片 / 品牌名 / 密钥 / 加固"

# ---------- S1：长文本被切成多片、分片落进不同批次时，译文必须完整
LONG=$(python3 -c "
print(' '.join('Sentence number %d explains how the container behaves under sustained load.' % i for i in range(1, 120)))")
LEN=${#LONG}
python3 - "$LONG" > /tmp/uzt-long.json <<'PY'
import json, sys
print(json.dumps({"csrf": "testtoken123", "texts": [sys.argv[1]]}))
PY
RESP=$(curl -sS -m 120 -X POST "http://127.0.0.1:${HTTP_PORT}/plugins/unraid.zh.translator/ajax/lookup.php" \
  -H 'Content-Type: application/json' --data-binary @/tmp/uzt-long.json)
printf '%s' "$RESP" > /tmp/uzt-long-resp.json
if python3 - "$LEN" <<'PY'
import json, sys
src_len = int(sys.argv[1])
d = json.load(open('/tmp/uzt-long-resp.json'))
hits = d.get('hits') or {}
assert d.get('ok') and hits, '没有返回译文: %s' % str(d)[:300]
k, v = list(hits.items())[0]
assert 'explains how the container' in v, '译文丢失内容'
assert 'Sentence number 1 ' in v, '缺少第一片内容（分片译文被后面批次覆盖）'
assert 'Sentence number 119 ' in v, '缺少最后一片内容'
assert v.count('中文：') >= 2, '应至少包含两片译文，实际 %d' % v.count('中文：')
assert len(v) >= src_len * 0.9, '译文长度异常（疑似截断）: %d vs %d' % (len(v), src_len)
print('  ✓ %d 字符长文本跨批翻译完整（%d 片）' % (src_len, v.count('中文：')))
PY
then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ 长文本分片翻译不完整"; fi

# ---------- M2：单词型品牌名原样返回必须算成功，不能重试到永久失败
ID_PORT=$(freeport)
MOCK_PORT=$ID_PORT MOCK_MODE=identity MOCK_LOG=/tmp/uzt-mock-id.jsonl setsid python3 tests/mock_llm.py \
  >/tmp/uzt-mock-id.out 2>&1 &
ID_PID=$!
sleep 1.2
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
require "'"$LIVE"'/include/store.php";
UZT\Config::save(["API_BASE" => "http://127.0.0.1:'"$ID_PORT"'/v1", "API_KEY" => "sk-mock", "MODEL" => "mock-model"]);
$h = UZT\segment_hash("Grafana");
UZT\Store::open()->upsertSegments([["h" => $h, "src" => "Grafana", "kind" => "fieldname", "app" => "test"]]);
' >/dev/null 2>&1
IDRES=$($W translate --json 2>&1)
if echo "$IDRES" | python3 -c "
import json,sys
d=json.load(sys.stdin)
assert d['failed']==0, d
print('  ✓ 品牌名单词原样返回被判定为成功')
"; then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ 品牌名触发重试/失败: $IDRES"; fi
VAL=$(php -r '
require "'"$LIVE"'/include/util.php"; require "'"$LIVE"'/include/config.php"; require "'"$LIVE"'/include/store.php";
$h = UZT\segment_hash("Grafana");
$r = UZT\Store::open()->getByHashes([$h]);
echo $r[$h]["dst"] ?? "";
')
[ "$VAL" = "Grafana" ] && ok "品牌名词条已出队并保留原文" || bad "品牌名词条状态" "得到 \"$VAL\""
kill $ID_PID 2>/dev/null

# ---------- M11：清除已保存的密钥必须真的清掉
curl -sS -m 20 -X POST "http://127.0.0.1:${HTTP_PORT}/plugins/unraid.zh.translator/ajax/settings.php" \
  -H 'Content-Type: application/json' \
  -d '{"action":"save","csrf":"testtoken123","clear_key":true,"config":{"MODEL":"mock-model"}}' >/dev/null
if grep -q 'API_KEY=""' "$CFGDIR/translator.cfg"; then ok "清空密钥已真正落盘"; else bad "密钥未被清除" "$(grep API_KEY "$CFGDIR/translator.cfg")"; fi

# ---------- M8：Cron 只接受 5 段白名单（换行不得写进 crontab）
python3 - > /tmp/uzt-cron.json <<'PY'
import json
print(json.dumps({"action": "save", "csrf": "testtoken123",
                  "config": {"MODEL": "mock-model", "CRON": "* * *\n* *\n*"}}))
PY
CRONRES=$(curl -sS -m 20 -X POST "http://127.0.0.1:${HTTP_PORT}/plugins/unraid.zh.translator/ajax/settings.php" \
  -H 'Content-Type: application/json' --data-binary @/tmp/uzt-cron.json)
printf '%s' "$CRONRES" > /tmp/uzt-cron-resp.json
if python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-cron-resp.json'))
assert any('Cron' in e for e in d.get('errors', [])), d
print('  ✓ 含换行的 Cron 被拒绝')
PY
then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ Cron 校验未生效: $CRONRES"; fi
if grep -q 'CRON=""' "$CFGDIR/translator.cfg"; then ok "非法 Cron 未落盘"; else bad "非法 Cron 落盘了" "$(grep CRON= "$CFGDIR/translator.cfg")"; fi

# ---------- M12：configjs.php 不得跨站可读
CODE_X=$(curl -sS -m 10 -o /dev/null -w '%{http_code}' -H 'Sec-Fetch-Site: cross-site' \
  "http://127.0.0.1:${HTTP_PORT}/plugins/unraid.zh.translator/ajax/configjs.php")
CODE_S=$(curl -sS -m 10 -o /dev/null -w '%{http_code}' -H 'Sec-Fetch-Site: same-origin' \
  "http://127.0.0.1:${HTTP_PORT}/plugins/unraid.zh.translator/ajax/configjs.php")
[ "$CODE_X" = "403" ] && ok "跨站读取 configjs 被拒绝 (403)" || bad "configjs 跨站" "HTTP $CODE_X"
[ "$CODE_S" = "200" ] && ok "同源读取 configjs 正常 (200)" || bad "configjs 同源" "HTTP $CODE_S"

# ---------- 恢复后续步骤要用的配置
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
UZT\Config::save(["API_BASE" => "http://127.0.0.1:'"$MOCK_PORT"'/v1", "API_KEY" => "sk-mock",
                  "MODEL" => "mock-model", "SYNC_MAX_CHARS" => "20000"]);
' >/dev/null 2>&1
