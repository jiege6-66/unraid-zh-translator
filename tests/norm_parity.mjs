/**
 * 规范化一致性测试：PHP UZT\normalize_text() 与 JS UZTNorm.norm() 必须逐字符相同。
 * 不一致会导致浏览器算出的键与数据库 sha1 对不上，译文永远命中不了。
 *
 * 运行：node tests/norm_parity.mjs
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const root = path.resolve(import.meta.dirname, '..');
const pluginDir = path.join(root, 'src/usr/local/emhttp/plugins/unraid.zh.translator');
const cases = JSON.parse(fs.readFileSync(path.join(root, 'tests/fixtures/norm_cases.json'), 'utf8')).cases;

// ---- JS 侧
const sandbox = { globalThis: null, window: undefined };
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(path.join(pluginDir, 'js/uzt-norm.js'), 'utf8'), sandbox);
const jsNorm = (s) => sandbox.UZTNorm.norm(s);

// ---- PHP 侧
const php = `
require ${JSON.stringify(path.join(pluginDir, 'include/util.php'))};
$raw = stream_get_contents(STDIN);
$in = json_decode($raw, true);
$out = [];
foreach ($in as $s) { $out[] = UZT\\normalize_text((string)$s); }
echo json_encode($out, JSON_UNESCAPED_UNICODE);
`;
const phpOut = execFileSync('php', ['-r', php], { input: JSON.stringify(cases), encoding: 'utf8' });
const phpNorms = JSON.parse(phpOut);

let fail = 0;
cases.forEach((input, i) => {
    const a = jsNorm(input);
    const b = phpNorms[i];
    if (a !== b) {
        fail++;
        console.log(`✗ case #${i}: ${JSON.stringify(input)}`);
        console.log(`    JS : ${JSON.stringify(a)}`);
        console.log(`    PHP: ${JSON.stringify(b)}`);
    }
});
if (fail === 0) {
    console.log(`✓ 规范化一致（${cases.length} 个用例，JS 与 PHP 输出完全相同）`);
} else {
    console.log(`✗ ${fail}/${cases.length} 个用例不一致`);
}
process.exit(fail === 0 ? 0 : 1);
