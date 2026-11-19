/**
 * unraid.zh.translator — 文本规范化（浏览器端）
 *
 * 必须与 PHP 侧 UZT\normalize_text() 逐字符一致，否则浏览器算出的键
 * 会与数据库里的 sha1 对不上，译文永远命中不了。
 * tests/norm_parity.mjs 会对两边的实现做随机对照。
 */
(function (root) {
    'use strict';

    // 与 PHP UZT\WS_INLINE 完全相同的显式空白集（不要用 \s / trim 的默认语义）
    var WS_INLINE = /[\t\v\f \u0085\u00A0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000\uFEFF]+/g;

    /** 统一换行 / 折叠行内空白 / 去首尾空白 / 丢弃空行 */
    function norm(s) {
        if (typeof s !== 'string') { return ''; }
        s = s.replace(/\r\n?/g, '\n');
        var lines = s.split('\n');
        var out = [];
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].replace(WS_INLINE, ' ').replace(/^ +| +$/g, '');
            if (line !== '') { out.push(line); }
        }
        return out.join('\n');
    }

    function hasCJK(s) {
        return /[\u3400-\u9FFF\uF900-\uFAFF]/.test(s);
    }

    function hasLetter(s) {
        return /[A-Za-z]/.test(s);
    }

    /** 不值得翻译：URL、路径、文件名、版本号、全大写常量、标识符/镜像名 */
    function looksUntranslatable(s) {
        var t = String(s).trim();
        if (!t) { return true; }
        if (/^(https?:\/\/|ftp:\/\/|\/\/|\/[\w./-]*$|[a-z][a-z0-9+.-]*:\/\/)/i.test(t)) { return true; }
        if (/^[\w./-]+\.(json|xml|ya?ml|js|css|html?|php|sh|png|jpe?g|gif|svg|webp|md|txt|log|db|cfg|ini|plg|txz|tar|gz|zip)$/i.test(t)) { return true; }
        if (/^(v?\d+(\.\d+)*[a-z0-9.+-]*|\d+(\.\d+)?\s*(kb|mb|gb|tb|ms|s|min|h|%))$/i.test(t)) { return true; }
        if (/^[A-Z0-9]{2,}$/.test(t)) { return true; }
        // 去掉句末标点后判断："Requires:" / "Change log:" 这类标签应当翻译
        var probe = t.replace(/[\s:：,，。.；;]+$/, '') || t;
        if (!/\s/.test(probe)) {
            if (/[_/:.+=]|\d/.test(probe)) { return true; }
            if (probe.length < 5) { return true; }
        }
        return !hasLetter(t);
    }

    /**
     * @param {string} raw 原始文本
     * @param {{min?:number,max?:number}} [opts]
     * @returns {string} 规范化后的键；不值得翻译时返回空串
     */
    function isCandidate(raw, opts) {
        var min = (opts && opts.min) || 5;
        var max = (opts && opts.max) || 12000;
        var n = norm(raw);
        if (n.length < min || n.length > max) { return ''; }
        if (hasCJK(n)) { return ''; }
        if (looksUntranslatable(n)) { return ''; }
        return n;
    }

    root.UZTNorm = {
        norm: norm,
        hasCJK: hasCJK,
        hasLetter: hasLetter,
        looksUntranslatable: looksUntranslatable,
        isCandidate: isCandidate
    };
})(typeof window !== 'undefined' ? window : globalThis);
