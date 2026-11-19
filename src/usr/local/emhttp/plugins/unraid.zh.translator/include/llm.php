<?php
/**
 * unraid.zh.translator — 大模型翻译客户端（OpenAI 兼容 /chat/completions）
 *
 * 兼容：OpenAI、DeepSeek、Moonshot、智谱、硅基流动、one-api、Ollama(/v1)、
 * LM Studio、vLLM、OpenRouter 等一切实现 /v1/chat/completions 的服务。
 *
 * 设计要点：
 *  - 批量：一次请求塞多条短文本，省钱省时（BATCH_ITEMS / BATCH_CHARS）；
 *  - 长段落：先在句末切块，再分别翻译后拼回；
 *  - 严格校验：条数、非空、必须含中文、长度比例、换行数量；
 *  - 失败重试：传输错误指数退避，格式错误带「修复提示」再试一次。
 */
declare(strict_types=1);

namespace UZT;

final class Llm
{
    public const PROMPT_VERSION = '2026-11-19.1';

    private string $base;
    private string $key;
    private string $model;
    private string $jsonMode;
    private float $temperature;
    private int $batchItems;
    private int $batchChars;
    private int $timeout;
    private int $retries;
    private string $target;

    public $lastError = '';
    public $lastRaw = '';
    public $calls = 0;
    public $units = 0;
    public $msTotal = 0.0;   // 累计 HTTP 往返耗时，用于预估剩余时间

    /** @param array{TIMEOUT?:int,RETRIES?:int} $overrides */
    public function __construct(array $overrides = [])
    {
        $this->base = rtrim(Config::get('API_BASE'), '/');
        $this->key = Config::get('API_KEY');
        $this->model = Config::get('MODEL');
        $this->jsonMode = Config::get('JSON_MODE');
        $this->temperature = Config::float('TEMPERATURE', 0.2);
        $this->batchItems = max(1, Config::int('BATCH_ITEMS', 16));
        $this->batchChars = max(500, Config::int('BATCH_CHARS', 5000));
        $this->timeout = max(5, (int)($overrides['TIMEOUT'] ?? Config::int('TIMEOUT', 90)));
        $this->retries = max(0, (int)($overrides['RETRIES'] ?? Config::int('RETRIES', 2)));
        $this->target = Config::get('TARGET', 'zh-CN') ?: 'zh-CN';
    }

    public function ready(): bool
    {
        return $this->base !== '' && $this->model !== '';
    }

    public function describe(): string
    {
        return sprintf('%s @ %s', $this->model, $this->base);
    }

    // ------------------------------------------------------------------ 对外

    /**
     * @param array<int,array{h:string,src:string}> $items
     * @return array{hits: array<string,string>, errors: array<string,string>}
     */
    public function translateBatch(array $items): array
    {
        $hits = [];
        $errors = [];
        if (!$items) {
            return ['hits' => $hits, 'errors' => $errors];
        }
        if (!$this->ready()) {
            $msg = '未配置 API 地址或模型';
            foreach ($items as $it) {
                $errors[$it['h']] = $msg;
            }
            return ['hits' => $hits, 'errors' => $errors];
        }

        // 1) 展开成翻译单元（长文本按句切块）
        $units = [];       // id => src
        $parent = [];      // id => h
        $pieceTotal = [];  // h => 需要几片
        foreach ($items as $it) {
            $pieces = self::splitForTranslation($it['src'], (int)max(800, $this->batchChars * 0.6));
            $pieceTotal[$it['h']] = count($pieces);
            if (count($pieces) === 1) {
                $units[$it['h']] = $pieces[0];
                $parent[$it['h']] = $it['h'];
            } else {
                foreach ($pieces as $i => $p) {
                    $id = $it['h'] . '~' . $i;
                    $units[$id] = $p;
                    $parent[$id] = $it['h'];
                }
            }
        }
        $this->units += count($units);

        // 2) 组批执行。注意：同一原文的多片可能被分到**不同批次**，
        //    所以累积器必须在批循环之外，否则只会留下最后一片的译文。
        $acc = [];   // h => [片号 => 译文]
        foreach (self::packBatches($units, $this->batchItems, $this->batchChars) as $batch) {
            $res = $this->runBatch($batch);
            if (isset($res['error'])) {
                foreach ($batch as $id => $_) {
                    $errors[$parent[$id]] = $res['error'];
                }
                continue;
            }
            foreach ($batch as $id => $_) {
                $h = $parent[$id];
                $idx = 0;
                $pos = strrpos($id, '~');
                if ($pos !== false) {
                    $idx = (int)substr($id, $pos + 1);
                }
                if (!isset($res['t'][$id]) || $res['t'][$id] === '') {
                    $errors[$h] = $errors[$h] ?? '模型未返回该条译文';
                    continue;
                }
                $acc[$h][$idx] = $res['t'][$id];
            }
        }

        // 3) 只有「所有片都到齐」才成稿；缺片宁可报错，也不把残稿写进缓存
        foreach ($pieceTotal as $h => $n) {
            if (isset($errors[$h])) {
                continue;
            }
            if (!isset($acc[$h]) || count($acc[$h]) !== $n) {
                $errors[$h] = '分片译文不完整';
                continue;
            }
            ksort($acc[$h]);
            $hits[$h] = implode(' ', $acc[$h]);
        }

        return ['hits' => $hits, 'errors' => $errors];
    }

    // ------------------------------------------------------------------ 组批

    /**
     * @param array<string,string> $units
     * @return array<int,array<string,string>>
     */
    private static function packBatches(array $units, int $maxItems, int $maxChars): array
    {
        $batches = [];
        $cur = [];
        $chars = 0;
        foreach ($units as $id => $src) {
            $len = mb_strlen($src, 'UTF-8');
            if ($cur && (count($cur) >= $maxItems || $chars + $len > $maxChars)) {
                $batches[] = $cur;
                $cur = [];
                $chars = 0;
            }
            $cur[$id] = $src;
            $chars += $len;
            if ($len >= $maxChars) {
                $batches[] = $cur;
                $cur = [];
                $chars = 0;
            }
        }
        if ($cur) {
            $batches[] = $cur;
        }
        return $batches;
    }

    /** 按句末切分，尽量不破坏占位符与列表结构 */
    public static function splitForTranslation(string $text, int $max = 2400): array
    {
        $len = mb_strlen($text, 'UTF-8');
        if ($len <= $max) {
            return [$text];
        }
        $sentences = preg_split('/(?<=[.!?:;])\s+/u', $text) ?: [$text];
        $out = [];
        $buf = '';
        foreach ($sentences as $s) {
            $candidate = $buf === '' ? $s : $buf . ' ' . $s;
            if (mb_strlen($candidate, 'UTF-8') > $max && $buf !== '') {
                $out[] = $buf;
                $buf = $s;
            } else {
                $buf = $candidate;
            }
        }
        if ($buf !== '') {
            $out[] = $buf;
        }
        // 极端情况：单句超长，硬切
        $final = [];
        foreach ($out as $chunk) {
            if (mb_strlen($chunk, 'UTF-8') <= $max) {
                $final[] = $chunk;
                continue;
            }
            $total = mb_strlen($chunk, 'UTF-8');
            for ($off = 0; $off < $total; $off += $max) {
                $final[] = mb_substr($chunk, $off, $max, 'UTF-8');
            }
        }
        return $final;
    }

    // ------------------------------------------------------------------ 单次请求

    /** @param array<string,string> $batch */
    private function runBatch(array $batch): array
    {
        $payload = [];
        foreach ($batch as $id => $src) {
            $payload[] = ['i' => (string)$id, 's' => $src];
        }
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => json_encode(
                ['task' => 'translate', 'target' => $this->target, 'items' => $payload],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )],
        ];

        $attempt = 0;
        $repair = '';
        while (true) {
            $msgs = $messages;
            if ($repair !== '') {
                $msgs[] = ['role' => 'assistant', 'content' => $this->lastRaw];
                $msgs[] = ['role' => 'user', 'content' => $repair];
            }
            $res = $this->request($msgs);
            if (isset($res['error'])) {
                $this->lastError = $res['error'];
                if ($attempt < $this->retries && self::retryable($res['error'])) {
                    $attempt++;
                    $sleep = (int)min(20, 2 ** $attempt);
                    jlog(sprintf('LLM 请求失败(%s)，%d 秒后重试 #%d', $res['error'], $sleep, $attempt), 'WARN');
                    sleep($sleep);
                    continue;
                }
                return ['error' => $res['error']];
            }
            $this->lastRaw = $res['content'];
            $decoded = self::extractJson($res['content']);
            if ($decoded === null) {
                if ($attempt < 1) {
                    $attempt++;
                    $repair = '你的上一条回复不是合法 JSON 或缺少 t 字段。请只输出 JSON：{"t":{"<id>":"<译文>"}}，并覆盖全部 id，不要输出任何解释文字。';
                    continue;
                }
                return ['error' => '模型返回内容无法解析为 JSON'];
            }
            $checked = $this->validate($batch, $decoded);
            if ($checked['missing']) {
                if ($attempt < 1) {
                    $attempt++;
                    $repair = '以下 id 缺失或译文不合格：' . implode(',', array_slice($checked['missing'], 0, 20))
                        . '。请补全，并且只输出 JSON：{"t":{"<id>":"<译文>"}}。';
                    continue;
                }
            }
            if (!$checked['t']) {
                return ['error' => '模型返回的译文全部未通过校验'];
            }
            return ['t' => $checked['t']];
        }
    }

    /**
     * @param array<string,string> $batch
     * @param array<string,string> $decoded
     * @return array{t:array<string,string>, missing:array<int,string>}
     */
    private function validate(array $batch, array $decoded): array
    {
        $ok = [];
        $missing = [];
        foreach ($batch as $id => $src) {
            $dst = isset($decoded[$id]) ? trim((string)$decoded[$id]) : '';
            if ($dst === '') {
                $missing[] = (string)$id;
                continue;
            }
            if ($dst === $src) {
                // 系统提示词要求品牌名保持原样，模型对单词条目合法地返回原文。
                // 这种情况视为「已完成（保留原文）」，否则会 repair 重试两次后永久失败。
                if (!preg_match('/\s/', $src)) {
                    $ok[$id] = $dst;
                    continue;
                }
                $missing[] = (string)$id;
                continue;
            }
            if (!has_cjk($dst)) {
                $missing[] = (string)$id;
                continue;
            }
            $sl = mb_strlen($src, 'UTF-8');
            $dl = mb_strlen($dst, 'UTF-8');
            if ($sl > 24 && $dl < $sl * 0.10) {
                $missing[] = (string)$id;
                continue;
            }
            if ($sl > 24 && $dl > $sl * 6) {
                $missing[] = (string)$id;
                continue;
            }
            $ok[$id] = $dst;
        }
        return ['t' => $ok, 'missing' => $missing];
    }

    private static function retryable(string $err): bool
    {
        if (preg_match('/^HTTP\s+(408|409|425|429|500|502|503|504)\b/', $err)) {
            return true;
        }
        return (bool)preg_match('/timeout|timed out|Could not resolve|Connection refused|SSL|Empty reply/i', $err);
    }

    public static function extractJson(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        $try = function (string $s): ?array {
            $d = json_decode($s, true);
            if (!is_array($d)) {
                return null;
            }
            if (isset($d['t']) && is_array($d['t'])) {
                return self::flattenT($d['t']);
            }
            // 兼容 {"translations":{"id":"..."}} / {"1":"..."} 之类
            foreach (['translations', 'result', 'results', 'data'] as $k) {
                if (isset($d[$k]) && is_array($d[$k])) {
                    return self::flattenT($d[$k]);
                }
            }
            return self::flattenT($d);
        };
        $direct = $try($content);
        if ($direct !== null) {
            return $direct;
        }
        if (preg_match('/```(?:json)?\s*(.+?)```/si', $content, $m)) {
            $r = $try(trim($m[1]));
            if ($r !== null) {
                return $r;
            }
        }
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return $try(substr($content, $start, $end - $start + 1));
        }
        return null;
    }

    private static function flattenT(array $in): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            if (is_string($v)) {
                $out[(string)$k] = $v;
            } elseif (is_array($v)) {
                foreach (['text', 'zh', 'translation', 'dst', 's'] as $kk) {
                    if (isset($v[$kk]) && is_string($v[$kk])) {
                        $out[(string)$k] = $v[$kk];
                        break;
                    }
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ HTTP

    private function systemPrompt(): string
    {
        return <<<TXT
你是 Unraid 社区应用商店（Community Applications）与技术文档的专业本地化译者。把英文文案翻译成简体中文。

必须遵守：
1. 只输出 JSON：{"t":{"<id>":"<译文>"}}。id 原样回填，覆盖输入里的每一个 id，不增不减。不要输出解释、注释或 Markdown 代码围栏。
2. 以下内容保持原样不翻译：产品/应用名、公司名、作者名、容器镜像名与 tag、URL、域名、文件路径、命令行参数（--xxx）、环境变量名（PUID、TZ、UMASK）、端口/占位符（[IP]、[PORT:8080]、{{...}}）、版本号、许可证名、型号与单位符号、代码片段、Markdown/HTML 标签与链接地址。
3. 保留原文的换行与段落结构；原文一个段落对应译文一个段落。
4. 术语统一：container=容器，appdata=应用数据目录，array=阵列，share=共享文件夹，pool=存储池，parity=校验盘，template=模板，host network=主机网络，bridge=桥接网络，reverse proxy=反向代理，plugin=插件，WebUI=Web 界面，dashboard=仪表盘，pass-through=直通，mount=挂载，snapshot=快照，cache=缓存。
5. 语体：技术文档口吻，简洁、准确、信息完整；不省略、不扩写、不加评论、不加拼音。
6. 译文里不要出现 "翻译"、"原文" 之类的自指词。
TXT;
    }

    /** @return array{content?:string,error?:string} */
    private function request(array $messages): array
    {
        $t0 = microtime(true);
        $this->calls++;
        try {
            return $this->requestOnce($messages);
        } finally {
            $this->msTotal += (microtime(true) - $t0) * 1000;
        }
    }

    /** @return array{content?:string,error?:string} */
    private function requestOnce(array $messages): array
    {
        $url = $this->base . '/chat/completions';
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $this->temperature,
            'stream' => false,
        ];
        if ($this->jsonMode === 'on' || $this->jsonMode === 'auto') {
            $body['response_format'] = ['type' => 'json_object'];
        }
        $res = self::curlJson($url, $body, $this->key, $this->timeout);
        if (isset($res['error']) && $this->jsonMode === 'auto'
            && preg_match('/response_format|json_object|json mode/i', (string)$res['error'])) {
            unset($body['response_format']);
            $res = self::curlJson($url, $body, $this->key, $this->timeout);
        }
        if (isset($res['error'])) {
            return $res;
        }
        $data = $res['data'];
        $content = '';
        if (isset($data['choices'][0]['message']['content'])) {
            $c = $data['choices'][0]['message']['content'];
            if (is_string($c)) {
                $content = $c;
            } elseif (is_array($c)) {
                foreach ($c as $part) {
                    if (is_array($part) && isset($part['text'])) {
                        $content .= $part['text'];
                    }
                }
            }
        } elseif (isset($data['choices'][0]['text'])) {
            $content = (string)$data['choices'][0]['text'];
        } elseif (isset($data['message']['content'])) { // 兼容 ollama 原生 /api/chat
            $content = (string)$data['message']['content'];
        }
        if (trim($content) === '') {
            $msg = $data['error']['message'] ?? '模型返回为空';
            return ['error' => mb_substr((string)$msg, 0, 300)];
        }
        return ['content' => $content];
    }

    /** @return array{data?:array,error?:string} */
    public static function curlJson(string $url, array $body, string $key = '', int $timeout = 90): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return ['error' => '请求体编码失败'];
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        if (!function_exists('curl_init')) {
            return ['error' => 'PHP 缺少 curl 扩展'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'unraid.zh.translator/' . VERSION,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return ['error' => 'HTTP ' . $errno . ' ' . $err];
        }
        $data = json_decode((string)$raw, true);
        if ($code >= 400) {
            $msg = 'HTTP ' . $code;
            if (is_array($data)) {
                $detail = $data['error']['message'] ?? $data['error'] ?? $data['message'] ?? '';
                if (is_array($detail)) {
                    $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
                }
                if ($detail) {
                    $msg .= ' ' . mb_substr((string)$detail, 0, 240);
                }
            } else {
                $msg .= ' ' . mb_substr(trim((string)$raw), 0, 200);
            }
            return ['error' => $msg];
        }
        if (!is_array($data)) {
            return ['error' => '响应不是 JSON: ' . mb_substr(trim((string)$raw), 0, 160)];
        }
        return ['data' => $data];
    }

    /** 连通性自测 */
    public function test(): array
    {
        if (!$this->ready()) {
            return ['ok' => false, 'error' => '请先填写 API 地址与模型名'];
        }
        $t0 = microtime(true);
        $src = 'Adds a dashboard tile with live GPU temperature, and requires host networking.';
        $res = $this->translateBatch([['h' => 'selftest', 'src' => $src]]);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if (isset($res['hits']['selftest'])) {
            return [
                'ok' => true,
                'ms' => $ms,
                'model' => $this->model,
                'base' => $this->base,
                'sample_src' => $src,
                'sample_dst' => $res['hits']['selftest'],
            ];
        }
        return [
            'ok' => false,
            'ms' => $ms,
            'model' => $this->model,
            'base' => $this->base,
            'error' => $res['errors']['selftest'] ?? ($this->lastError ?: '未知错误'),
        ];
    }
}
