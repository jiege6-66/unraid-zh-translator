/**
 * unraid.zh.translator — 前端注入脚本（全局加载）
 *
 * 思路：不侵入 Community Applications / dynamix 的源码，只做「文本替换」：
 *   1. 扫描页面上可见的英文文本节点与 title/placeholder 等属性；
 *   2. 批量 POST 给本插件后端，命中翻译缓存就直接替换；
 *   3. 没命中的条目后端会入库并按需调用大模型翻译，回填后再次扫描；
 *   4. MutationObserver 监听 SPA 重新渲染，新出现的英文同样处理。
 *
 * 因为缓存键是「规范化文本」，所以：
 *   - 插件没更新 → 文案不变 → 直接复用译文，零模型调用；
 *   - 插件更新了 → 文案变化 → 新键 → 自动重新翻译。
 */
(function () {
    'use strict';

    var CFG = window.__UZT__;
    if (!CFG || !CFG.enabled) { return; }
    if (window.__UZT_RUNNING__) { return; }
    window.__UZT_RUNNING__ = true;

    // ---------------------------------------------------------------- 常量
    var MIN_LEN = 5;              // 更短的串基本是标识符，性价比低
    var MAX_LEN = 12000;
    var BATCH_TEXTS = 48;         // 单次请求最多文本条数
    var BATCH_CHARS = 8000;       // 单次请求最多字符数
    var DEBOUNCE_MS = 220;
    var RETRY_MS = [2500, 5000, 9000, 15000, 25000, 40000];

    var SKIP_SELECTOR = [
        'script', 'style', 'noscript', 'pre', 'code', 'kbd', 'samp', 'var',
        'textarea', 'svg', 'math', 'canvas',
        '.CodeMirror', '.ace_editor', '[contenteditable="true"]',
        '[data-uzt-skip]', '[translate="no"]', '.uzt-ui'
    ].join(',');

    var ATTRS = ['title', 'placeholder', 'alt', 'aria-label'];

    // 应用商店（Community Applications / Apps）
    var STORE_RE = /(^|\/)(Apps|Tools\/CommunityApplications|Settings\/CommunityApplications)(\/|$|\?)/i;
    // 原生插件页 / Docker 页：只有把范围放大时才处理
    var PLUGIN_RE = /(^|\/)(Plugins|Settings\/PluginManager)(\/|$|\?)/i;
    var DOCKER_RE = /(^|\/)(Docker|Settings\/Docker)(\/|$|\?)/i;

    // ---------------------------------------------------------------- 状态
    var enabled = true;
    var dict = Object.create(null);      // 原文 => 译文
    var asked = Object.create(null);     // 已询问过的原文，避免重复
    var applying = false;
    var appliedValues = Object.create(null);   // 我们写过的文本，重扫时跳过
    var originals = new Map();                 // 文本节点 -> 原始英文
    var attrOriginals = new Map();             // 元素 -> {属性: 原始值}
    var lang = 'zh';                           // zh | en（右上角切换按钮）
    var pill = null;
    var retry = 0;
    var timer = null;
    var queuedSeen = 0;
    var stats = { collected: 0, applied: 0, requests: 0, lastError: '' };

    // ---------------------------------------------------------------- 工具

    var N = window.UZTNorm;
    if (!N) {
        if (window.console) { console.warn('[uzt] js/uzt-norm.js 未加载，跳过翻译'); }
        return;
    }
    var norm = N.norm;
    var hasCJK = N.hasCJK;
    var looksUntranslatable = N.looksUntranslatable;

    function isCandidate(raw) {
        return N.isCandidate(raw, { min: MIN_LEN, max: MAX_LEN });
    }

    function debounce(fn, ms) {
        var t = null;
        return function () {
            clearTimeout(t);
            t = setTimeout(fn, ms);
        };
    }

    function onScope() {
        try {
            if (localStorage.getItem('uzt:force') === '1') { return true; }
            if (localStorage.getItem('uzt:off') === '1') { return false; }
        } catch (e) { /* 隐私模式 */ }
        var scope = CFG.scope || 'store';
        var path = location.pathname + location.search;
        var inStore = STORE_RE.test(path) || !!document.querySelector(
            '#ca-app, .ca-app, #community-applications, [data-ca-app], .ca-dashboard'
        );
        if (inStore) { return true; }
        if (scope === 'store') { return false; }
        if (PLUGIN_RE.test(path)) { return true; }
        return scope === 'all' && DOCKER_RE.test(path);
    }

    // ---------------------------------------------------------------- 采集

    function collectFrom(root, bucket) {
        if (!root) { return; }
        var walker;
        try {
            walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
                acceptNode: function (node) {
                    var p = node.parentElement;
                    if (!p) { return NodeFilter.FILTER_REJECT; }
                    if (p.closest(SKIP_SELECTOR)) { return NodeFilter.FILTER_REJECT; }
                    return NodeFilter.FILTER_ACCEPT;
                }
            });
        } catch (e) { return; }

        var node;
        while ((node = walker.nextNode())) {
            var value = node.nodeValue || '';
            if (appliedValues[value]) { continue; }
            if (hasCJK(value)) {
                // 混合语言块（宿主已部分汉化）：只补译其中不含中文的行
                if (value.length >= 8 && value.indexOf('\n') !== -1) {
                    var blk = makeBlock(node, value);
                    if (blk) {
                        for (var bi = 0; bi < blk.items.length; bi++) {
                            if (asked[blk.items[bi].key]) { continue; }
                            bucket.texts.push(blk.items[bi]);
                        }
                    }
                }
                continue;
            }
            var key = isCandidate(value);
            if (!key) { continue; }
            if (dict[key] !== undefined) { applyTextNode(node, key); continue; }
            if (asked[key]) { continue; }
            bucket.texts.push({ node: node, key: key });
        }

        var els = root.querySelectorAll ? root.querySelectorAll(ATTRS.map(function (a) { return '[' + a + ']'; }).join(',')) : [];
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            if (el.closest(SKIP_SELECTOR)) { continue; }
            for (var a = 0; a < ATTRS.length; a++) {
                var val = el.getAttribute(ATTRS[a]);
                if (!val) { continue; }
                var k = isCandidate(val);
                if (!k) { continue; }
                if (dict[k] !== undefined) { el.setAttribute(ATTRS[a], dict[k]); continue; }
                if (asked[k]) { continue; }
                bucket.attrs.push({ el: el, attr: ATTRS[a], key: k });
            }
        }
    }

    /**
     * 混合语言块：把含中文的多行文本按行拆开，只把纯英文行送去翻译，
     * 全部行都拿到译文后再整体重写（行数与内容位置保持不变）。
     */
    function makeBlock(node, value) {
        var lines = value.split('\n');
        if (lines.length < 2) { return null; }
        var items = [];
        var block = { node: node, lines: lines, resolved: {}, need: 0 };
        for (var i = 0; i < lines.length; i++) {
            var k = isCandidate(lines[i]);
            if (!k) { continue; }
            block.need++;
            if (dict[k] !== undefined) {
                block.resolved[i] = dict[k];
                continue;
            }
            items.push({ node: null, key: k, block: block, idx: i });
        }
        if (!block.need || !items.length) { return null; }
        return { block: block, items: items };
    }

    function finishBlock(block) {
        var have = 0;
        for (var i = 0; i < block.lines.length; i++) {
            if (block.resolved[i] !== undefined) { have++; }
        }
        if (have < block.need) { return; }
        var out = [];
        for (var j = 0; j < block.lines.length; j++) {
            out.push(block.resolved[j] !== undefined ? block.resolved[j] : block.lines[j]);
        }
        var text = out.join('\n');
        if (text === block.node.nodeValue) { return; }
        appliedValues[text] = 1;
        applying = true;
        block.node.nodeValue = text;
        applying = false;
        stats.applied++;
    }

    function applyTextNode(node, key) {
        var dst = dict[key];
        if (dst === undefined || dst === '') { return; }
        if (node.nodeValue === dst) { return; }
        if (!originals.has(node)) { originals.set(node, node.nodeValue); }
        applying = true;
        node.nodeValue = dst;
        applying = false;
        stats.applied++;
    }

    function applyAttr(item) {
        var dst = dict[item.key];
        if (dst === undefined || dst === '') { return; }
        if (!attrOriginals.has(item.el)) { attrOriginals.set(item.el, Object.create(null)); }
        var bag = attrOriginals.get(item.el);
        if (bag[item.attr] === undefined) { bag[item.attr] = item.el.getAttribute(item.attr); }
        applying = true;
        item.el.setAttribute(item.attr, dst);
        applying = false;
        stats.applied++;
    }

    /** 一键回到英文原文 */
    function restoreOriginal() {
        applying = true;
        originals.forEach(function (orig, node) {
            try { node.nodeValue = orig; } catch (e) { /* 节点已移除 */ }
        });
        attrOriginals.forEach(function (bag, el) {
            Object.keys(bag).forEach(function (attr) {
                try { el.setAttribute(attr, bag[attr]); } catch (e) { }
            });
        });
        applying = false;
        originals.clear();
        attrOriginals.clear();
        document.documentElement.removeAttribute('data-uzt-active');
    }

    function setLang(next, persist) {
        lang = (next === 'en') ? 'en' : 'zh';
        if (persist !== false) {
            try { localStorage.setItem('uzt:lang', lang); } catch (e) { }
        }
        if (lang === 'en') {
            restoreOriginal();
        } else {
            asked = Object.create(null);
            scan(document.body);
        }
        updatePill();
    }

    // ---------------------------------------------------------------- 切换按钮

    function updatePill() {
        if (!pill) { return; }
        var zh = pill.querySelector('[data-lang="zh"]');
        var en = pill.querySelector('[data-lang="en"]');
        if (!zh || !en) { return; }
        zh.classList.toggle('on', lang === 'zh');
        en.classList.toggle('on', lang === 'en');
        pill.title = '中英文切换（Alt+T）';
    }

    function buildPill() {
        if (pill || !document.body) { return; }
        pill = document.createElement('div');
        pill.className = 'uzt-ui';
        pill.id = 'uzt-lang-switch';
        pill.innerHTML = '<span data-lang="zh">中</span><span data-lang="en">EN</span>';
        var css = document.createElement('style');
        css.className = 'uzt-ui';
        css.textContent = [
            '#uzt-lang-switch{position:fixed;right:18px;bottom:18px;z-index:2147483000;display:flex;',
            'background:rgba(0,0,0,.62);border:1px solid rgba(255,255,255,.18);border-radius:999px;',
            'padding:2px;box-shadow:0 2px 10px rgba(0,0,0,.35);font:600 12px/1 -apple-system,Segoe UI,Roboto,sans-serif;',
            'cursor:pointer;user-select:none;opacity:.72;transition:opacity .15s}',
            '#uzt-lang-switch:hover{opacity:1}',
            '#uzt-lang-switch span{display:block;padding:6px 10px;border-radius:999px;color:#c9c9c9}',
            '#uzt-lang-switch span.on{background:#ff8c2f;color:#fff}',
            '#uzt-lang-switch[data-busy="1"]::after{content:"";width:6px;height:6px;margin:4px 6px 0 2px;',
            'border-radius:50%;background:#ff8c2f;animation:uzt-pulse 1s ease-in-out infinite}',
            '@keyframes uzt-pulse{0%,100%{opacity:.25}50%{opacity:1}}'
        ].join('');
        document.head.appendChild(css);
        pill.addEventListener('click', function (ev) {
            var t = ev.target && ev.target.getAttribute ? ev.target.getAttribute('data-lang') : null;
            setLang(t || (lang === 'zh' ? 'en' : 'zh'));
        });
        document.body.appendChild(pill);
        updatePill();
    }

    // ---------------------------------------------------------------- 通信

    function chunk(list, maxItems, maxChars) {
        var out = [];
        var cur = [];
        var chars = 0;
        for (var i = 0; i < list.length; i++) {
            var len = list[i].key.length;
            if (cur.length && (cur.length >= maxItems || chars + len > maxChars)) {
                out.push(cur); cur = []; chars = 0;
            }
            cur.push(list[i]);
            chars += len;
        }
        if (cur.length) { out.push(cur); }
        return out;
    }

    function post(action, payload) {
        var body = JSON.stringify(payload || {});
        var headers = { 'Content-Type': 'application/json' };
        if (CFG.csrf) { headers['X-CSRF-Token'] = CFG.csrf; }
        var opts = {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: body
        };
        // 服务端同步翻译可能很慢，给个上限，避免请求永远挂着
        var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var timer = null;
        if (ctrl) {
            opts.signal = ctrl.signal;
            timer = setTimeout(function () { try { ctrl.abort(); } catch (e) { } }, 150000);
        }
        return fetch(CFG.endpoint + '/' + action + '.php', opts).then(function (r) {
            return r.json();
        }).finally(function () {
            if (timer) { clearTimeout(timer); }
        });
    }

    function sendBatch(items, isRetry) {
        if (!items.length) { return Promise.resolve(); }
        var texts = [];
        var seen = Object.create(null);
        for (var i = 0; i < items.length; i++) {
            if (seen[items[i].key]) { continue; }
            seen[items[i].key] = 1;
            texts.push(items[i].key);
        }
        stats.requests++;
        for (var q = 0; q < items.length; q++) { asked[items[q].key] = 1; }
        if (pill) { pill.setAttribute('data-busy', '1'); }
        return post('lookup', {
            texts: texts,
            csrf: CFG.csrf || '',
            sync: isRetry ? false : undefined
        }).then(function (res) {
            if (!res || res.ok !== true) {
                stats.lastError = (res && res.error) || 'lookup 失败';
                for (var z = 0; z < items.length; z++) { delete asked[items[z].key]; }
                return;
            }
            var hits = res.hits || {};
            for (var k in hits) {
                if (Object.prototype.hasOwnProperty.call(hits, k)) {
                    dict[k] = hits[k];
                }
            }
            for (var j = 0; j < items.length; j++) {
                var it = items[j];
                if (it.node) {
                    applyTextNode(it.node, it.key);
                } else if (it.el) {
                    applyAttr(it);
                } else if (it.block) {
                    it.block.resolved[it.idx] = dict[it.key];
                    finishBlock(it.block);
                }
            }
            queuedSeen = res.queued || 0;
            if (res.error) { stats.lastError = res.error; }
            if (queuedSeen > 0 && Object.keys(hits).length < texts.length) {
                scheduleRetry();
            }
        }).then(function (r) {
            if (pill) { pill.removeAttribute('data-busy'); }
            return r;
        }).catch(function (e) {
            stats.lastError = String(e && e.message || e);
            if (pill) { pill.removeAttribute('data-busy'); }
            // 请求失败：允许下次重新尝试同一批文本
            for (var z = 0; z < items.length; z++) { delete asked[items[z].key]; }
        });
    }

    function scheduleRetry() {
        if (timer || retry >= RETRY_MS.length) { return; }
        var wait = RETRY_MS[retry++];
        timer = setTimeout(function () {
            timer = null;
            if (document.hidden) { retry--; scheduleRetry(); return; }
            asked = Object.create(null);
            scan(document.body);
        }, wait);
    }

    var scan = debounce(function (root) {
        if (!enabled || lang !== 'zh' || !onScope()) { return; }
        var bucket = { texts: [], attrs: [] };
        collectFrom(root || document.body, bucket);
        var items = bucket.texts.concat(bucket.attrs);
        if (!items.length) { return; }
        stats.collected += items.length;
        var isRetryPass = retry > 0;
        var batches = chunk(items, BATCH_TEXTS, BATCH_CHARS);
        var chain = Promise.resolve();
        batches.forEach(function (b) {
            chain = chain.then(function () { return sendBatch(b, isRetryPass); });
        });
        return chain;
    }, DEBOUNCE_MS);

    // ---------------------------------------------------------------- 启动

    function startObserver() {
        var mo = new MutationObserver(function (muts) {
            if (applying) { return; }
            var worth = false;
            for (var i = 0; i < muts.length; i++) {
                var m = muts[i];
                if (m.type === 'characterData') {
                    if (m.target && !hasCJK(m.target.nodeValue || '')) {
                        worth = true;
                    }
                } else if (m.addedNodes && m.addedNodes.length) {
                    for (var j = 0; j < m.addedNodes.length; j++) {
                        var n = m.addedNodes[j];
                        if (n.nodeType === 1 || n.nodeType === 3) { worth = true; break; }
                    }
                }
                if (worth) { break; }
            }
            if (worth) { scan(document.body); }
        });
        mo.observe(document.body, { childList: true, subtree: true, characterData: true });
    }

    function boot() {
        if (!onScope()) {
            // 非目标页面：仍留一个 API 供调试
            window.uzt = window.uzt || {};
            return;
        }
        try {
            if (localStorage.getItem('uzt:lang') === 'en') { lang = 'en'; }
        } catch (e) { }
        buildPill();
        if (lang === 'zh') {
            document.documentElement.setAttribute('data-uzt-active', '1');
            scan(document.body);
        } else {
            updatePill();
        }
        document.addEventListener('keydown', function (ev) {
            if (ev.altKey && (ev.key === 't' || ev.key === 'T')) {
                ev.preventDefault();
                setLang(lang === 'zh' ? 'en' : 'zh');
            }
        });
        startObserver();
        // SPA 路由变化
        var lastPath = location.pathname;
        setInterval(function () {
            if (location.pathname !== lastPath) {
                lastPath = location.pathname;
                retry = 0;
                asked = Object.create(null);
                setTimeout(function () { scan(document.body); }, 400);
            }
        }, 1200);
        // 页面重新可见时补一次
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { scan(document.body); }
        });
    }

    window.uzt = {
        version: CFG.version,
        enable: function () { try { localStorage.removeItem('uzt:off'); } catch (e) { } location.reload(); },
        disable: function () { try { localStorage.setItem('uzt:off', '1'); } catch (e) { } location.reload(); },
        forceAllPages: function (v) {
            try { v ? localStorage.setItem('uzt:force', '1') : localStorage.removeItem('uzt:force'); } catch (e) { }
            location.reload();
        },
        rescan: function () { asked = Object.create(null); scan(document.body); },
        setLang: function (l) { setLang(l); },
        lang: function () { return lang; },
        stats: function () { return stats; },
        dict: function () { return dict; },
        config: function () { return CFG; }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
