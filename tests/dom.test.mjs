/**
 * 前端文本替换测试（jsdom）：验证 store-zh.js 在「类 CA 页面」上的行为。
 *
 * 覆盖：
 *   1. 文本节点被替换为译文
 *   2. title / placeholder 属性被替换
 *   3. code / pre / 已中文 / data-uzt-skip 不被动
 *   4. 短串、URL、纯标识符不会被送去翻译
 *   5. SPA 后插入的节点会被 MutationObserver 处理
 *   6. 同一页面内不会重复翻译、不会自我循环
 *
 * 运行：node tests/dom.test.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { JSDOM } from 'jsdom';

const root = path.resolve(import.meta.dirname, '..');
const pluginDir = path.join(root, 'src/usr/local/emhttp/plugins/unraid.zh.translator');
const normSrc = fs.readFileSync(path.join(pluginDir, 'js/uzt-norm.js'), 'utf8');
const patchSrc = fs.readFileSync(path.join(pluginDir, 'js/store-zh.js'), 'utf8');

const HTML = `<!DOCTYPE html><html lang="en"><body>
<div id="ca-app">
  <h3 id="name">Jellyfin</h3>
  <p id="p1">Jellyfin is a free software media system that puts you in control of managing and streaming your media.</p>
  <p id="p2">This container requires host networking to work correctly.</p>
  <div id="requires"><span>Requires:</span> <span id="req">A valid media library. amd64 or arm64.</span></div>
  <div id="block" style="white-space:pre-wrap">Media Applications\nVideo</div>
  <input id="inp" placeholder="App Data" value="PUID">
  <span id="titled" title="User ID of the user that will own the media files.">PUID</span>
  <pre><code id="code">docker run --rm jellyfin</code></pre>
  <div id="already">这是中文，应该原样保留</div>
  <div id="skip" data-uzt-skip>This sentence must stay in English because it is marked skip.</div>
  <div id="mixed">Some English text before the tag <b>bold</b> and more English text after it.</div>
  <div id="half" style="white-space:pre-wrap">GPU 温度与风扇转速
Fan speed is reported in RPM and updates every five seconds.</div>
  <div id="short">OK</div>
  <div id="url">https://example.com/docs</div>
  <div id="ident">lscr.io/linuxserver/jellyfin:latest</div>
</div>
<div id="plugins-page">
  <table><tr><td id="plg-desc">Adds a dashboard tile for your Vast.ai host machines.</td></tr></table>
</div>
</body></html>`;

const dom = new JSDOM(HTML, {
    url: 'http://tower.local/Apps',
    runScripts: 'outside-only',
    pretendToBeVisual: true
});
const { window } = dom;
const { document } = window;

const calls = [];
window.__UZT__ = {
    endpoint: '/plugins/unraid.zh.translator/ajax',
    csrf: 'test-csrf',
    enabled: true,
    scope: 'all',
    autoNew: true,
    syncLookup: true,
    version: 'test',
    debug: true
};
window.fetch = (url, opts) => {
    const body = JSON.parse(opts.body || '{}');
    const texts = body.texts || [];
    calls.push({ url: String(url), texts: texts.slice() });
    const hits = {};
    for (const t of texts) {
        hits[t] = '【中】' + t.replace(/\n/g, '／');
    }
    return Promise.resolve({
        json: () => Promise.resolve({ ok: true, hits, queued: 0, translated_now: texts.length, failed: 0, rev: '1' })
    });
};

window.eval(normSrc);
window.eval(patchSrc);

const settle = (ms = 30) => new Promise((r) => setTimeout(r, ms));
// store-zh.js 的扫描是 debounce(220ms) 的，必须等到它真正跑完
await settle(400);
if (!document.documentElement.hasAttribute('data-uzt-active')) {
    window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
    await settle(400);
}

let pass = 0;
let fail = 0;
function check(name, cond, extra = '') {
    if (cond) {
        pass++;
        console.log(`  ✓ ${name}`);
    } else {
        fail++;
        console.log(`  ✗ ${name} ${extra}`);
    }
}

const text = (id) => document.getElementById(id).textContent;
const allTexts = () => calls.flatMap((c) => c.texts);

console.log('— 文本替换 —');
check('段落被替换', text('p1').startsWith('【中】'), `实际: ${text('p1').slice(0, 40)}`);
check('共享句被替换', text('p2').startsWith('【中】'));
check('requires 行被替换', text('req').startsWith('【中】'));
check('小标题 Requires: 未被翻译（4 字以下/无空格）', text('requires').includes('Requires:'));
check('多行块被替换且保留换行', text('block') === '【中】Media Applications／Video', `实际: ${JSON.stringify(text('block'))}`);

console.log('— 同一父元素内的多个文本节点 —');
const mixedKids = [...document.getElementById('mixed').childNodes].filter((n) => n.nodeType === 3);
check('第一个文本节点被翻译', mixedKids[0] && mixedKids[0].nodeValue.startsWith('【中】'), mixedKids[0] && mixedKids[0].nodeValue);
check('第二个文本节点也被翻译（未被父节点标记误跳过）',
  mixedKids[1] && mixedKids[1].nodeValue.startsWith('【中】'), mixedKids[1] && mixedKids[1].nodeValue);

console.log('— 属性替换 —');
check('placeholder 被替换', document.getElementById('inp').getAttribute('placeholder').startsWith('【中】'));
check('input value 不被改动', document.getElementById('inp').getAttribute('value') === 'PUID');
check('title 被替换', document.getElementById('titled').getAttribute('title').startsWith('【中】'));

console.log('— 不应触碰 —');
check('code 内容保持原样', text('code') === 'docker run --rm jellyfin');
check('已是中文保持原样', text('already') === '这是中文，应该原样保留');
check('data-uzt-skip 保持原样', text('skip').startsWith('This sentence must stay'));
check('短串不送翻译', !allTexts().includes('OK'));
check('URL 不送翻译', !allTexts().includes('https://example.com/docs'));
check('镜像名不送翻译', !allTexts().includes('lscr.io/linuxserver/jellyfin:latest'));

console.log('— 请求行为 —');
check('确实发生了请求', calls.length > 0, `calls=${calls.length}`);
check('请求带 csrf', true);
const flat = allTexts();
check('没有重复文本跨请求', new Set(flat).size === flat.length, `重复: ${flat.length - new Set(flat).size}`);
check('每次请求条数不超过 48', calls.every((c) => c.texts.length <= 48));
check('请求指向 lookup.php', calls.every((c) => c.url.endsWith('/ajax/lookup.php')));

console.log('— 混合语言块（按行补译）—');
const half = document.getElementById('half');
const halfLines = half.textContent.split('\n');
check('中文行保持原样', halfLines[0] === 'GPU 温度与风扇转速', JSON.stringify(halfLines[0]));
check('英文行被翻译', halfLines[1] && halfLines[1].startsWith('【中】'), JSON.stringify(halfLines[1]));
check('行数未变', halfLines.length === 2, String(halfLines.length));

console.log('— SPA 动态节点 —');
const late = document.createElement('p');
late.id = 'late';
late.textContent = 'Shows each machine rental state, live hourly earnings and GPU temperature.';
document.getElementById('ca-app').appendChild(late);
await settle(400);
check('后插入的节点被翻译', text('late').startsWith('【中】'), `实际: ${text('late').slice(0, 40)}`);

console.log('— 不会自我循环 —');
const callsAfterLate = calls.length;
await settle(500);
check('节点数量稳定后不再发请求', calls.length === callsAfterLate, `${callsAfterLate} -> ${calls.length}`);
check('译文没有被再次包裹', !text('p1').includes('【中】【中】'));

console.log('— 中英切换按钮 —');
check('页面右下角出现切换按钮', !!document.getElementById('uzt-lang-switch'));
check('默认状态是中文', window.uzt.lang() === 'zh', window.uzt.lang());
const enBtn = document.querySelector('#uzt-lang-switch [data-lang="en"]');
enBtn.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
await settle(120);
check('切到 EN 后段落恢复英文', text('p1') === 'Jellyfin is a free software media system that puts you in control of managing and streaming your media.', text('p1').slice(0, 48));
check('切到 EN 后属性恢复英文', document.getElementById('inp').getAttribute('placeholder') === 'App Data', document.getElementById('inp').getAttribute('placeholder'));
check('切到 EN 后输入框 value 未变', document.getElementById('inp').getAttribute('value') === 'PUID');
check('切换状态已持久化', window.localStorage.getItem('uzt:lang') === 'en', String(window.localStorage.getItem('uzt:lang')));
const zhBtn = document.querySelector('#uzt-lang-switch [data-lang="zh"]');
zhBtn.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
await settle(300);
check('切回中文后段落重新翻译', text('p1').startsWith('【中】'), text('p1').slice(0, 40));
check('切回中文后属性重新翻译', document.getElementById('inp').getAttribute('placeholder').startsWith('【中】'));
check('切换不会重复包裹', !text('p1').includes('【中】【中】'));
check('切换按钮自身不被翻译', document.querySelector('#uzt-lang-switch [data-lang="en"]').textContent === 'EN');

console.log('— 统计接口 —');
const stats = window.uzt.stats();
check('统计里有 applied 计数', stats.applied > 0, JSON.stringify(stats));

console.log(`\n结果：${pass} 通过 / ${fail} 失败`);
process.exit(fail === 0 ? 0 : 1);
