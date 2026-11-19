/**
 * 「值得翻译」判定一致性测试：PHP UZT\is_translatable() 与 JS UZTNorm.isCandidate()
 * 必须给出相同结论，否则会出现「后端存了但前端不查」或反之的浪费。
 *
 * 运行：node tests/rules_parity.mjs
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const root = path.resolve(import.meta.dirname, '..');
const pluginDir = path.join(root, 'src/usr/local/emhttp/plugins/unraid.zh.translator');

const CASES = [
    ['Requires:', true],
    ['Change log:', true],
    ['Change log: ', true],
    ['Video', true],
    ['Music', true],
    ['Tools', true],
    ['Media Applications', true],
    ['Jellyfin is a free software media system.', true],
    ['This container requires host networking to work correctly.', true],
    ['OK', false],
    ['GPU', false],
    ['PUID', false],
    ['TZ', false],
    ['amd64', false],
    ['v1.2.3', false],
    ['latest', true],
    ['lscr.io/linuxserver/jellyfin:latest', false],
    ['https://example.com/docs', false],
    ['/mnt/user/appdata', false],
    ['docker-compose.yml', false],
    ['这是中文', false],
    ['这是中文 English mixed', false],
    ['[IP]:[PORT:8096]', false],
    ['--cap-add=NET_ADMIN', false],
    ['2026-09-24', false],
    ['', false],
    ['   ', false],
    ['nginx', true],
    ['Plex', false],  // 4 字符的品牌名，按设计跳过
];

// ---- JS
const sandbox = { globalThis: null };
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(path.join(pluginDir, 'js/uzt-norm.js'), 'utf8'), sandbox);
const js = (s) => sandbox.UZTNorm.isCandidate(s, { min: 5 }) !== '';

// ---- PHP
const php = `
require ${JSON.stringify(path.join(pluginDir, 'include/util.php'))};
$in = json_decode(stream_get_contents(STDIN), true);
$out = [];
foreach ($in as $s) { $out[] = UZT\\is_translatable((string)$s, 5) ? true : false; }
echo json_encode($out);
`;
const phpRes = JSON.parse(execFileSync('php', ['-r', php], {
    input: JSON.stringify(CASES.map((c) => c[0])),
    encoding: 'utf8'
}));

let fail = 0;
CASES.forEach(([s, expect], i) => {
    const a = js(s);
    const b = phpRes[i];
    const okJs = a === expect;
    const okPhp = b === expect;
    const okSame = a === b;
    if (!okJs || !okPhp || !okSame) {
        fail++;
        console.log(`✗ ${JSON.stringify(s)}`);
        console.log(`    期望=${expect}  JS=${a}  PHP=${b}${okSame ? '' : '  ← 两侧不一致'}`);
    }
});
console.log(fail === 0
    ? `✓ 判定规则一致（${CASES.length} 个用例，PHP 与 JS 结论相同且符合预期）`
    : `✗ ${fail}/${CASES.length} 个用例不符合预期`);
process.exit(fail === 0 ? 0 : 1);
