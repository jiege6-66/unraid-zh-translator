#!/bin/bash
# 构建 dist/unraid.zh.translator.plg —— 把 src/ 下的插件文件内联进单个 .plg
#
# 用法: ./build.sh [版本号]
set -euo pipefail
cd "$(dirname "$0")"

NAME="unraid.zh.translator"
AUTHOR="${AUTHOR:-YG}"
VERSION="${1:-$(cat VERSION 2>/dev/null || date +%Y.%m.%d)}"
# 自动更新用的 .plg 地址：放到你自己的仓库后改这里或传环境变量
PLUGIN_URL="${PLUGIN_URL:-https://raw.githubusercontent.com/jiege6-66/unraid-zh-translator/main/${NAME}.plg}"
SRC="src/usr/local/emhttp/plugins/${NAME}"
OUT="dist/${NAME}.plg"
TITLE="插件中文化 (Unraid Store 中文翻译)"

if [ ! -d "$SRC" ]; then
  echo "缺少源码目录: $SRC" >&2
  exit 1
fi

mkdir -p dist
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

cat > "$TMP" <<PLGHEAD
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name "${NAME}">
<!ENTITY author "${AUTHOR}">
<!ENTITY version "${VERSION}">
<!ENTITY launch "Settings/${NAME}">
<!ENTITY plugdir "/boot/config/plugins/&name;">
<!ENTITY pluginURL "${PLUGIN_URL}">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" launch="&launch;" pluginURL="&pluginURL;" icon="language">

<!-- ${TITLE} -->
<DESCRIPTION>
把 Community Applications（应用/插件商店）与原生「插件」页面里的英文文案自动翻译为简体中文。
译文按「文本内容哈希」缓存：插件未更新则复用旧译文、不调用大模型；文案变化（插件更新）时只重译改动的那几段。
支持任何 OpenAI 兼容接口（OpenAI / DeepSeek / Moonshot / 智谱 / one-api 中转 / 本地 Ollama、LM Studio 等）。
纯前端替换，不修改任何宿主机文件，卸载即恢复英文。
</DESCRIPTION>
<CHANGES>
### ${VERSION}
- 应用商店（Community Applications）与已安装插件页面的英文文案自动译为中文
- 译文按内容哈希缓存：插件未更新则复用译文，文案变化只重译改动片段
- 支持任何 OpenAI 兼容接口（含本地 Ollama / LM Studio）
- 设置页可测试连通性、同步应用源、查看覆盖率与日志
</CHANGES>

<FILE Run="/bin/bash">
<INLINE>
# 覆盖式安装：先清掉本体与历史副本，避免插件列表里出现两个同名插件
rm -rf /usr/local/emhttp/plugins/&name;
for d in /usr/local/emhttp/plugins/*unraid.zh.translator*; do
  [ -e "\$d" ] || continue
  if [ "\$d" = "/usr/local/emhttp/plugins/&name;" ]; then continue; fi
  echo "清理旧插件目录: \$d"
  rm -rf "\$d"
done
for f in /boot/config/plugins/*unraid.zh.translator*.plg; do
  [ -e "\$f" ] || continue
  if [ "\$f" = "/boot/config/plugins/&name;.plg" ]; then continue; fi
  echo "清理旧 .plg: \$f"
  mv -f "\$f" "\$f.removed" 2>/dev/null || rm -f "\$f"
done
mkdir -p /usr/local/emhttp/plugins/&name;
mkdir -p &plugdir;
mkdir -p &plugdir;/logs;
chmod 0770 &plugdir; &plugdir;/logs 2>/dev/null
[ -w &plugdir; ] || echo "警告：&plugdir; 不可写（/boot 只读或已满），设置将无法保存"
echo "安装 &name; ${VERSION}"
</INLINE>
</FILE>
PLGHEAD

# 逐个内联文件，保持目录结构
while IFS= read -r -d '' file; do
  rel="${file#"$SRC"/}"
  target="/usr/local/emhttp/plugins/${NAME}/${rel}"
  mode="0644"
  case "$rel" in
    *.php) mode="0755" ;;
    *.page) mode="0755" ;;
    *.sh) mode="0755" ;;
  esac
  if grep -q ']]>' "$file"; then
    echo "错误: $file 含 CDATA 终止序列 ]]>，请改写后重试" >&2
    exit 1
  fi
  # 注意：不能用 $(cat ...) —— 命令替换会吃掉文件末尾换行
  {
    printf '<FILE Name="%s" Mode="%s"><INLINE><![CDATA[' "$target" "$mode"
    cat "$file"
    printf ']]></INLINE></FILE>\n'
  } >> "$TMP"
done < <(find "$SRC" -type f -print0 | sort -z)

cat >> "$TMP" <<'PLGTAIL'

<FILE Run="/bin/bash">
<INLINE>
# 权限与运行目录
chmod -R a+r /usr/local/emhttp/plugins/unraid.zh.translator
find /usr/local/emhttp/plugins/unraid.zh.translator -type d -exec chmod 0775 {} \;
chmod 0755 /usr/local/emhttp/plugins/unraid.zh.translator/scripts/worker.php 2>/dev/null
mkdir -p /usr/local/emhttp/plugins/unraid.zh.translator/var
chmod 0775 /usr/local/emhttp/plugins/unraid.zh.translator/var
# 默认配置（不存在才写，避免覆盖用户设置）
CFG=/boot/config/plugins/unraid.zh.translator/translator.cfg
if [ ! -f "$CFG" ]; then
  /usr/bin/php -r 'require "/usr/local/emhttp/plugins/unraid.zh.translator/include/util.php"; require "/usr/local/emhttp/plugins/unraid.zh.translator/include/config.php"; UZT\Config::write(UZT\Config::DEFAULTS);' 2>/dev/null || true
fi
# 首次安装提示
echo
echo "----------------------------------------------------------"
echo " 插件中文化已安装。"
echo " 1. 打开 设置 → 插件中文化，填写大模型 API（或本地 Ollama）"
echo " 2. 点「测试连接」→「同步应用源」→「翻译待译队列」"
echo " 3. 打开 应用(Apps) / 插件(Plugins) 页面即可看到中文"
echo "----------------------------------------------------------"
echo
</INLINE>
</FILE>
</PLUGIN>
PLGTAIL

mv "$TMP" "$OUT"
# 发布件复制到仓库根目录；测试/CI 用 PUBLISH=0 跳过，避免覆盖线上版本
if [ "${PUBLISH:-1}" = "1" ]; then
  cp -f "$OUT" "${NAME}.plg"
else
  echo "（PUBLISH=0：不更新仓库根目录的发布件）"
fi
trap - EXIT

# 自检：XML 必须能解析（历史上踩过 && 裸 & 把包写坏的坑）
python3 - "$OUT" <<'PYCHK' || { echo "错误: $OUT 不是合法 XML（常见原因：安装脚本里出现裸 & 或 &&）" >&2; exit 1; }
import sys, xml.dom.minidom as m
d = m.parse(sys.argv[1])
files = [f for f in d.getElementsByTagName('FILE') if f.getAttribute('Name')]
assert files, '没有内联任何文件'
PYCHK
echo "已生成: $OUT (版本 $VERSION, $(wc -c < "$OUT") 字节, $(grep -c '<FILE ' "$OUT") 个文件)"
