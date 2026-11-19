#!/bin/bash
# unraid.zh.translator —— 一键回归测试
#
# 会做这些事（全部在本机真实路径上跑，不动外部网络）：
#   1. php -l 全量语法检查
#   2. build.sh 打包 → 解析 .plg → 还原到 /usr/local/emhttp/... → 逐字节比对
#   3. 规范化一致性（PHP vs JS）
#   4. .page 渲染冒烟
#   5. 假大模型 + 真 worker：同步 → 翻译 → 幂等 → 增量重译
#   6. 真 HTTP 端点：lookup / status / CSRF / 多段拼接
#   7. jsdom 前端替换测试
#
# 用法: tests/run-tests.sh
set -uo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
PLUGIN=unraid.zh.translator
LIVE="/usr/local/emhttp/plugins/$PLUGIN"
CFGDIR="/boot/config/plugins/$PLUGIN"
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1 ${2:-}"; }
step(){ echo; echo "──────── $1 ────────"; }
freeport() { python3 - <<'PY'
import socket
s=socket.socket(); s.bind(('127.0.0.1',0)); print(s.getsockname()[1]); s.close()
PY
}

step "0. 核心链路（临时目录，无需 root）"
if php tests/core.php >/tmp/uzt-core.log 2>&1; then
  ok "$(grep -oE '通过 [0-9]+ 项' /tmp/uzt-core.log | head -1) 核心断言通过"
else
  bad "核心链路" "$(tail -5 /tmp/uzt-core.log)"
fi

step "1. PHP 语法检查"
while IFS= read -r f; do
  if php -l "$f" >/dev/null 2>&1; then ok "php -l ${f#src/usr/local/emhttp/plugins/$PLUGIN/}"; else bad "php -l $f" "$(php -l "$f" 2>&1|head -2)"; fi
done < <(find src -name '*.php' -o -name '*.page' | sort)
for f in src/usr/local/emhttp/plugins/$PLUGIN/js/*.js; do
  if node --check "$f" >/dev/null 2>&1; then ok "node --check $(basename "$f")"; else bad "node --check $f"; fi
done

step "2. 打包 / 安装 / 一致性"
if PUBLISH=0 ./build.sh 0.0.0-test >/dev/null 2>&1; then ok "build.sh 生成 dist/$PLUGIN.plg（测试版本，不动发布件）"; else bad "build.sh"; fi
python3 - <<'PY' && ok ".plg 是合法 XML 且含 16 个数据文件" || bad ".plg XML 结构"
import xml.dom.minidom as m, sys
d=m.parse('dist/unraid.zh.translator.plg')
files=[f for f in d.getElementsByTagName('FILE') if f.getAttribute('Name')]
assert len(files)>=16, len(files)
PY
if python3 tests/install_plg.py "dist/$PLUGIN.plg" >/tmp/uzt-install.log 2>&1; then ok "安装脚本执行成功"; else bad "install_plg.py" "$(tail -3 /tmp/uzt-install.log)"; fi
DIFF=0
while IFS= read -r f; do
  rel="${f#src/usr/local/emhttp/plugins/$PLUGIN/}"
  cmp -s "$f" "$LIVE/$rel" || { DIFF=1; echo "      差异: $rel"; }
done < <(find "src/usr/local/emhttp/plugins/$PLUGIN" -type f | sort)
[ $DIFF -eq 0 ] && ok "安装后的 16 个文件与源码逐字节一致" || bad "安装内容与源码不一致"

step "3. PHP ⇄ JS 一致性"
if node tests/norm_parity.mjs; then ok "文本规范化一致"; else bad "文本规范化不一致"; fi
if node tests/rules_parity.mjs; then ok "可译判定一致"; else bad "可译判定不一致"; fi

step "4. 策略与页面渲染"
if php tests/policy.php >/tmp/uzt-policy.log 2>&1; then ok "翻译策略与队列精简规则正确"; else bad "策略检查" "$(tail -5 /tmp/uzt-policy.log)"; fi
if php tests/render_page.php >/tmp/uzt-render.log 2>&1; then ok "设置页/注入页/configjs 渲染正常"; else bad "渲染检查" "$(tail -5 /tmp/uzt-render.log)"; fi

step "5. 环境准备"
mkdir -p "$CFGDIR/logs" "$LIVE/var" /var/local/emhttp
printf 'csrf_token="testtoken123"\nversion="7.2.0"\n' > /var/local/emhttp/var.ini
rm -f "$CFGDIR/translations.db"* "$CFGDIR/translator.cfg" "$CFGDIR"/logs/*.log
rm -rf "$LIVE/var"; mkdir -p "$LIVE/var"
mkdir -p /tmp/GitHub/AppFeed
cp tests/fixtures/feed.json /tmp/GitHub/AppFeed/applicationFeed.json   # 模拟 CA 的本地缓存
rm -f "$LIVE/var/applicationFeed.json"
MOCK_PORT=$(freeport); HTTP_PORT=$(freeport)
echo "  假大模型: 127.0.0.1:$MOCK_PORT   测试 Web: 127.0.0.1:$HTTP_PORT"
export MOCK_PORT MOCK_LOG=/tmp/uzt-mock-llm.jsonl MOCK_MODE=ok
rm -f "$MOCK_LOG"
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
UZT\Config::save([
  "API_BASE"=>"http://127.0.0.1:'"$MOCK_PORT"'/v1",
  "API_KEY"=>"sk-mock-test",
  "MODEL"=>"mock-model",
  "FEED_CACHE_TTL"=>"999999",
  "FEED_SOURCE"=>"local",
  "BATCH_ITEMS"=>"16",
  "SYNC_MAX_CHARS"=>"20000",
  "DEBUG"=>"1",
]);
' && ok "已写入测试配置"
python3 tests/mock_llm.py >/tmp/uzt-mock.out 2>&1 &
MOCK_PID=$!
php -S "127.0.0.1:$HTTP_PORT" -t /usr/local/emhttp >/tmp/uzt-php-srv.log 2>&1 &
HTTP_PID=$!
trap 'kill $MOCK_PID $HTTP_PID 2>/dev/null' EXIT
sleep 1.2

W="php $LIVE/scripts/worker.php"
j() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1"; }
calls() { [ -f "$MOCK_LOG" ] && wc -l < "$MOCK_LOG" || echo 0; }

step "6. 同步 → 翻译 → 幂等"
$W sync --json >/tmp/s1.json 2>&1
A=$(j "d['added']" </tmp/s1.json)
[ "$A" = "18" ] && ok "首次同步抽出 18 条片段" || bad "首次同步片段数" "期望 18，实际 $A"

$W translate --json >/tmp/t1.json 2>&1
D=$(j "d['done']" </tmp/t1.json); C=$(calls)
[ "$D" = "18" ] && ok "首次翻译完成 18 条" || bad "首次翻译条数" "实际 $D"
[ "$C" -ge 1 ] && ok "调用了 $C 次大模型（批量合并）" || bad "未调用大模型"

$W translate --json >/tmp/t2.json 2>&1
D2=$(j "d['done']" </tmp/t2.json); C2=$(calls)
[ "$D2" = "0" ] && ok "重复翻译不再产生译文（全部命中缓存）" || bad "重复翻译仍有 $D2 条"
[ "$C2" = "$C" ] && ok "重复翻译 0 次模型调用（缓存复用生效）" || bad "模型调用增加" "$C->$C2"

$W sync --json >/tmp/s2.json 2>&1
A2=$(j "d['added']" </tmp/s2.json); E2=$(j "d['existed']" </tmp/s2.json)
[ "$A2" = "0" ] && [ "$E2" = "18" ] && ok "重复同步：新增 0 / 命中 18" || bad "重复同步" "added=$A2 existed=$E2"

step "7. 插件更新 → 只重译变更片段"
cp /tmp/feed-updated.json /tmp/GitHub/AppFeed/applicationFeed.json 2>/dev/null || python3 - <<'PY'
import json
d=json.load(open('tests/fixtures/feed.json'))
jf=d['applist'][0]
jf['Overview']=jf['Overview'].replace(
 "It is an alternative to the proprietary Emby and Plex, to provide media from a dedicated server to end-user devices.",
 "It is an alternative to the proprietary Emby and Plex, streaming media from a dedicated server to any device."
)+"\n\n    Hardware transcoding requires an Intel CPU with Quick Sync or a supported GPU."
json.dump(d,open('/tmp/feed-updated.json','w'),ensure_ascii=False,indent=2)
PY
[ -f /tmp/feed-updated.json ] && cp /tmp/feed-updated.json /tmp/GitHub/AppFeed/applicationFeed.json
: > "$MOCK_LOG"
$W sync --json >/tmp/s3.json 2>&1
A3=$(j "d['added']" </tmp/s3.json)
$W translate --json >/tmp/t3.json 2>&1
D3=$(j "d['done']" </tmp/t3.json)
SENT=$(python3 -c "
import json
rows=[json.loads(l) for l in open('$MOCK_LOG')]
print(sum(r['count'] for r in rows))
")
[ "$A3" = "2" ] && ok "更新后只新增 2 条待译片段" || bad "更新后新增片段数" "实际 $A3"
[ "$D3" = "2" ] && ok "只重译了 2 条" || bad "重译条数" "实际 $D3"
[ "$SENT" = "2" ] && ok "发送给大模型的只有这 2 条（其余 18 条复用译文）" || bad "发送条数" "实际 $SENT"
python3 - <<PY
import json
rows=[json.loads(l) for l in open('$MOCK_LOG')]
srcs=[s for r in rows for s in r['srcs']]
assert not any('Jellyfin is a free software media system' in s for s in srcs), '旧文案被重复翻译'
assert any('streaming media from a dedicated server' in s for s in srcs), '变更文案未送译'
print('  ✓ 送译内容校验通过（旧文案未重复翻译）')
PY
[ $? -eq 0 ] && PASS=$((PASS+1)) || { FAIL=$((FAIL+1)); echo "  ✗ 送译内容校验失败"; }

step "8. HTTP 端点"
API="http://127.0.0.1:$HTTP_PORT/plugins/$PLUGIN/ajax"
H=$(curl -sS -m 20 -X POST "$API/lookup.php" -H 'Content-Type: application/json' \
  -d '{"csrf":"testtoken123","texts":["Media Applications","A valid media library. amd64 or arm64."]}')
echo "$H" | python3 -c "
import json,sys
d=json.load(sys.stdin)
assert d['ok'] and len(d['hits'])==2 and d['translated_now']==0, d
print('  ✓ lookup 命中缓存（2 条，0 次即时翻译）')
" || bad "lookup 命中缓存"
N=$(curl -sS -m 30 -X POST "$API/lookup.php" -H 'Content-Type: application/json' \
  -d '{"csrf":"testtoken123","texts":["An unseen sentence that only exists on the live page right now."]}')
echo "$N" | python3 -c "
import json,sys
d=json.load(sys.stdin)
assert d['ok'] and len(d['hits'])==1 and d['translated_now']==1, d
print('  ✓ 未见过的新文案被即时翻译')
" || bad "按需即时翻译"
CODE=$(curl -sS -m 10 -o /dev/null -w '%{http_code}' -X POST "$API/lookup.php" -H 'Content-Type: application/json' -d '{"csrf":"bad","texts":["Hello world test string"]}')
[ "$CODE" = "403" ] && ok "错误 CSRF 被拒绝 (403)" || bad "CSRF 校验" "HTTP $CODE"
M=$(curl -sS -m 20 -X POST "$API/lookup.php" -H 'Content-Type: application/json' -d '{"csrf":"testtoken123","texts":["Media Applications\nVideo"]}')
echo "$M" | python3 -c "
import json,sys
d=json.load(sys.stdin)
k=list(d['hits'].values())
assert k and '\n' in k[0], d
print('  ✓ 多段文本按行拼接返回')
" || bad "多段拼接"
ST=$(curl -sS -m 10 "$API/status.php")
echo "$ST" | python3 -c "
import json,sys
d=json.load(sys.stdin)
assert d['ok'] and d['stats']['backend']=='sqlite' and d['stats']['done']>0 and len(d['kinds'])>0, d['stats']
print('  ✓ status 返回统计与分类明细 (backend=%s, done=%d)' % (d['stats']['backend'], d['stats']['done']))
" || bad "status 接口"
CJ=$(curl -sS -m 10 "http://127.0.0.1:$HTTP_PORT/plugins/$PLUGIN/ajax/configjs.php")
echo "$CJ" | grep -q 'window.__UZT__' && ok "configjs.php 正常输出" || bad "configjs.php" "$(echo "$CJ" | head -2)"

source "$(dirname "$0")/steps/8b_hardening.sh"

source "$(dirname "$0")/steps/8c_extra.sh"
source "$(dirname "$0")/steps/8d_spawn.sh"
source "$(dirname "$0")/steps/8e_feed.sh"
source "$(dirname "$0")/steps/8f_retry.sh"

step "9. 清理过期词条（保留页面采集的 live 词条）"
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
require "'"$LIVE"'/include/store.php";
$h = UZT\segment_hash(UZT\normalize_text("A sentence from an app that no longer exists in the feed."));
UZT\Store::open()->upsertSegments([["h"=>$h,"src"=>UZT\normalize_text("A sentence from an app that no longer exists in the feed."),"kind"=>"overview","app"=>"gone"]]);
UZT\Store::open()->setDone([["h"=>$h,"dst"=>"来自已下架应用的一句话。"]]);
UZT\Store::open()->flush();
echo "";
' >/dev/null
curl -sS -m 20 -X POST "http://127.0.0.1:$HTTP_PORT/plugins/$PLUGIN/ajax/lookup.php" -H 'Content-Type: application/json' \
  -d '{"csrf":"testtoken123","texts":["Live captured sentence that only exists in the browser page."]}' >/dev/null
$W prune --json >/tmp/p1.json 2>&1
REM=$(j "d['removed']" </tmp/p1.json)
LIVE_OK=$(php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
require "'"$LIVE"'/include/store.php";
$h = UZT\segment_hash(UZT\normalize_text("Live captured sentence that only exists in the browser page."));
$rows = UZT\Store::open()->getByHashes([$h]);
echo isset($rows[$h]) ? "yes" : "no";
')
FAKE=$(php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
require "'"$LIVE"'/include/store.php";
$h = UZT\segment_hash(UZT\normalize_text("A sentence from an app that no longer exists in the feed."));
$rows = UZT\Store::open()->getByHashes([$h]);
echo isset($rows[$h]) ? "yes" : "no";
')
[ "$REM" -ge 1 ] && ok "清理掉 $REM 条已下架词条" || bad "清理条数" "实际 $REM"
[ "$LIVE_OK" = "yes" ] && ok "页面按需采集的 live 词条被保留" || bad "live 词条被误删"
[ "$FAKE" = "no" ] && ok "已下架应用的自造词条被删除" || bad "过期词条未删除"

step "10. 前端替换 (jsdom)"
if node tests/dom.test.mjs >/tmp/uzt-dom.log 2>&1; then
  ok "$(grep -oE '结果：[0-9]+ 通过' /tmp/uzt-dom.log | head -1 | grep -oE '[0-9]+') 项前端断言通过"
else
  bad "dom 测试" "$(grep -c '✗' /tmp/uzt-dom.log) 项失败"
fi

echo
echo "════════════════════════════════════════"
echo " 通过 $PASS 项 / 失败 $FAIL 项"
echo "════════════════════════════════════════"
[ "$FAIL" -eq 0 ] && echo "全部测试通过 ✅" || echo "存在失败项 ❌"
exit $([ "$FAIL" -eq 0 ] && echo 0 || echo 1)
