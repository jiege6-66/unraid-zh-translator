#!/usr/bin/env python3
"""OpenAI 兼容的假大模型服务，用于回归测试。

行为：
  POST /v1/chat/completions
    - 校验 Bearer 鉴权
    - 解析第二条 messages 里的 {"items":[{"i":..,"s":..}]}
    - 把每次请求（条数、id、原文）以 JSONL 追加到 $MOCK_LOG
    - 返回 {"t": {id: "中文："+原文}}
    额外开关：
      MOCK_MODE=badfirst  第一轮返回非 JSON，验证「修复重试」路径
      MOCK_MODE=error     返回 500，验证退避重试路径
      MOCK_MODE=identity  单词（不含空格）原样返回，验证「品牌名保留原文」算成功
"""
import json
import os
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOG_PATH = os.environ.get('MOCK_LOG', '/tmp/uzt-mock-llm.jsonl')
MODE = os.environ.get('MOCK_MODE', 'ok')
REQUESTS = {'count': 0}
LOCK = threading.Lock()


def record(entry):
    with open(LOG_PATH, 'a', encoding='utf-8') as fh:
        fh.write(json.dumps(entry, ensure_ascii=False) + '\n')


class Handler(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *args):
        pass

    def _send(self, code, payload):
        body = json.dumps(payload, ensure_ascii=False).encode('utf-8')
        self.send_response(code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        if not self.path.rstrip('/').endswith('/chat/completions'):
            self._send(404, {'error': {'message': 'not found: ' + self.path}})
            return
        length = int(self.headers.get('Content-Length') or 0)
        raw = self.rfile.read(length).decode('utf-8')
        auth = self.headers.get('Authorization', '')
        if not auth.startswith('Bearer ') or len(auth) < 12:
            self._send(401, {'error': {'message': 'missing bearer token'}})
            return
        try:
            req = json.loads(raw)
        except json.JSONDecodeError:
            self._send(400, {'error': {'message': 'bad json'}})
            return

        with LOCK:
            REQUESTS['count'] += 1
            n = REQUESTS['count']
        messages = req.get('messages') or []
        user = next((m for m in messages if m.get('role') == 'user'), None)
        items = []
        if user:
            try:
                items = json.loads(user['content']).get('items', [])
            except Exception:
                items = []
        record({
            'n': n,
            'model': req.get('model'),
            'count': len(items),
            'ids': [i.get('i') for i in items],
            'srcs': [i.get('s') for i in items],
            'ts': __import__('time').time(),
        })

        if MODE == 'error':
            self._send(503, {'error': {'message': 'overloaded (mock)'}})
            return
        if MODE == 'badfirst' and n == 1:
            self._send(200, {'choices': [{'message': {'content': '抱歉，我先说两句废话。'}}]})
            return

        out = {}
        for item in items:
            src = item.get('s', '')
            if MODE == 'identity' and ' ' not in src:
                out[item.get('i')] = src          # 品牌名：原样返回
            else:
                out[item.get('i')] = '中文：' + src
        content = json.dumps({'t': out}, ensure_ascii=False)
        self._send(200, {
            'id': 'chatcmpl-mock',
            'object': 'chat.completion',
            'model': req.get('model', 'mock'),
            'choices': [{'index': 0, 'message': {'role': 'assistant', 'content': content},
                         'finish_reason': 'stop'}],
            'usage': {'prompt_tokens': 10, 'completion_tokens': len(content)},
        })


def main():
    port = int(os.environ.get('MOCK_PORT', '0'))
    server = ThreadingHTTPServer(('127.0.0.1', port), Handler)
    print(server.server_address[1], flush=True)
    server.serve_forever()


if __name__ == '__main__':
    main()
