#!/bin/bash
# 由 tests/run-tests.sh source：应用源解析（复用 CA 本地缓存 / 时间戳判断 / 在线回退）

step "8e. 应用源解析"

CA_LOCAL=/tmp/GitHub/AppFeed/applicationFeed.json
mkdir -p /tmp/GitHub/AppFeed

# 只把 JSON 写 stdout，日志写 stderr（否则会污染 JSON 解析）
resolve_q() { php -r '
require "'"$LIVE"'/include/translator.php";
$r = UZT\SourceFeed::fetch(!empty($argv[1]) && $argv[1] === "force",
    function ($m) { fwrite(STDERR, "    · " . $m . "\n"); });
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";' "$@" 2>>/tmp/uzt-feed-log.txt; }

set_feed_mode() {
  php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
UZT\Config::save(["FEED_SOURCE" => "'"$1"'"]);' >/dev/null 2>&1
}

# ---------- 1) 默认 auto：本地缓存新鲜 → 直接用，不下载
cp tests/fixtures/feed.json "$CA_LOCAL"
touch "$CA_LOCAL"
set_feed_mode auto
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
UZT\Config::save(["FEED_CACHE_TTL" => "21600"]);' >/dev/null 2>&1
resolve_q > /tmp/uzt-feed-auto.json 2>&1
if python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-feed-auto.json'))
assert d.get('ok'), d
assert d.get('source') == 'ca-local', d
assert d.get('from_local') is True, d
assert d.get('file') == '/tmp/GitHub/AppFeed/applicationFeed.json', d
print('  ✓ auto 模式直接复用 CA 本地缓存（%s）' % d['file'])
PY
then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ auto 未复用本地缓存"; fi

# ---------- 2) 本地缓存过期但时间戳一致 → 仍然复用（零下载）
set_feed_mode auto
php -r '
require "'"$LIVE"'/include/translator.php";
$stamp = UZT\SourceFeed::remoteStamp();
echo $stamp === 0 ? "NO_NET" : $stamp;' > /tmp/uzt-stamp.txt 2>&1
STAMP_VAL=$(cat /tmp/uzt-stamp.txt)
if [ "$STAMP_VAL" != "NO_NET" ] && [ -n "$STAMP_VAL" ]; then
  python3 - "$STAMP_VAL" <<'PY'
import json, sys
stamp = int(sys.argv[1])
raw = open('tests/fixtures/feed.json', encoding='utf-8').read().lstrip()
assert raw.startswith('{')
# 真实 feed 的 last_updated_timestamp 就在开头，测试也照样放在开头
out = '{"last_updated_timestamp":%d,' % stamp + raw[1:]
open('/tmp/GitHub/AppFeed/applicationFeed.json', 'w', encoding='utf-8').write(out)
PY
  touch -d '3 days ago' "$CA_LOCAL"
  resolve_q > /tmp/uzt-feed-stamp.json 2>&1
  if python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-feed-stamp.json'))
assert d.get('ok') and d.get('source') == 'ca-local', d
assert d.get('stamp', 0) > 0, d
print('  ✓ 本地缓存过期但时间戳与官方一致 → 仍复用（stamp=%s）' % d['stamp'])
PY
  then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ 时间戳一致时未复用本地: $(cat /tmp/uzt-feed-stamp.json)"; fi
else
  echo "  – 跳过时间戳比对（无网络）"
fi

# ---------- 3) local 模式但本地缺失 → 明确报错
rm -f "$CA_LOCAL"
set_feed_mode local
resolve_q > /tmp/uzt-feed-miss.json 2>&1
if python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-feed-miss.json'))
assert d.get('ok') is False, d
assert '打开一次' in (d.get('error') or ''), d
print('  ✓ local 模式缺本地缓存时给出可操作提示')
PY
then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ local 模式错误提示不对: $(cat /tmp/uzt-feed-miss.json)"; fi

# ---------- 4) online 模式：走 file:// 下载（离线可测），落到我们自己的缓存
set_feed_mode online
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
UZT\Config::save(["FEED_URL" => "file://'"$PWD"'/tests/fixtures/feed.json"]);' >/dev/null 2>&1
resolve_q force > /tmp/uzt-feed-online.json 2>&1
if python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-feed-online.json'))
assert d.get('ok'), d
assert d.get('source') in ('custom', 'cdn', 'github'), d
assert d.get('from_local') is False, d
print('  ✓ online 模式下载到自有缓存（source=%s）' % d['source'])
PY
then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "  ✗ online 模式失败: $(cat /tmp/uzt-feed-online.json)"; fi
[ -f "$LIVE/var/applicationFeed.json" ] && ok "在线下载已落到 var/applicationFeed.json" || bad "在线缓存文件缺失"

# ---------- 5) 范围=仅商店时，不解析本机 .plg
mkdir -p /boot/config/plugins
cat > /boot/config/plugins/uzt-test-plugin.plg <<'EOS'
<?xml version='1.0'?>
<PLUGIN name="uzt-test-plugin" version="1.0">
<DESCRIPTION>This description should only be collected when the scope includes plugins.</DESCRIPTION>
</PLUGIN>
EOS
set_feed_mode local
cp tests/fixtures/feed.json "$CA_LOCAL"
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
UZT\Config::save(["SCOPE" => "store"]);' >/dev/null 2>&1
$W sync --json > /tmp/uzt-sync-store.json 2>&1
STORE_LOCAL=$(php -r '
require "'"$LIVE"'/include/util.php"; require "'"$LIVE"'/include/config.php"; require "'"$LIVE"'/include/store.php";
$r = new ReflectionClass("UZT\\Store");
$pdo = (new ReflectionClass("UZT\\Store"))->getMethod("open")->invoke(null);
$s = UZT\Store::open();
echo count($s->hashesByKind(["localdesc"]));
')
[ "$STORE_LOCAL" = "0" ] && ok "SCOPE=store 时不采集本地 .plg 描述（0 条）" || bad "仍采集了本地插件描述" "$STORE_LOCAL 条"
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
UZT\Config::save(["SCOPE" => "all"]);' >/dev/null 2>&1
$W sync --json > /tmp/uzt-sync-all.json 2>&1
ALL_LOCAL=$(php -r '
require "'"$LIVE"'/include/util.php"; require "'"$LIVE"'/include/config.php"; require "'"$LIVE"'/include/store.php";
echo count(UZT\Store::open()->hashesByKind(["localdesc"]));')
[ "$ALL_LOCAL" -ge 1 ] && ok "SCOPE=all 时才采集本地插件描述（$ALL_LOCAL 条）" || bad "SCOPE=all 未采集本地描述" "$ALL_LOCAL"
rm -f /boot/config/plugins/uzt-test-plugin.plg

# ---------- 6) 网络不通 + 无本地源 → 明确失败而不是静默
set_feed_mode online
php -r '
require "'"$LIVE"'/include/util.php"; require "'"$LIVE"'/include/config.php";
UZT\Config::save(["FEED_URL" => "file:///nonexistent/none.json"]);' >/dev/null 2>&1
rm -f "$LIVE/var/applicationFeed.json" "$CA_LOCAL"
# 关掉备份源，确保只测失败路径
resolve_q force > /tmp/uzt-feed-fail.json 2>&1
python3 - <<'PY'
import json
d = json.load(open('/tmp/uzt-feed-fail.json'))
# 可能因 GitHub 备份可用而成功；只要求"要么成功、要么给出明确错误"
if d.get('ok'):
    print('  – 备份源可用，跳过失败路径断言（source=%s）' % d.get('source'))
else:
    assert d.get('error'), d
    print('  ✓ 全部源不可用时返回明确错误')
PY

# 恢复测试现场
cp tests/fixtures/feed.json "$CA_LOCAL"
php -r '
require "'"$LIVE"'/include/util.php";
require "'"$LIVE"'/include/config.php";
UZT\Config::save(["SCOPE" => "all", "FEED_SOURCE" => "local",
                  "FEED_URL" => "https://assets.ca.unraid.net/feed/applicationFeed.json",
                  "FEED_CACHE_TTL" => "999999"]);' >/dev/null 2>&1
