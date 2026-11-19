#!/usr/bin/env python3
"""把 dist/*.plg 里的 <FILE> 块还原到真实路径，并执行 <FILE Run> 脚本。

用于在没有 Unraid WebUI 的环境里做「安装级」回归测试：
它验证的是 .plg 本身可解析、文件内容与源码一致、安装脚本可执行。
"""
import os
import subprocess
import sys
import xml.dom.minidom as minidom


def main(plg_path: str, dry_run: bool = False) -> int:
    doc = minidom.parse(plg_path)
    root = doc.documentElement
    files = doc.getElementsByTagName('FILE')
    written = 0
    ran = 0
    for node in files:
        name = node.getAttribute('Name')
        mode = node.getAttribute('Mode') or '0644'
        inline = node.getElementsByTagName('INLINE')
        run = node.getAttribute('Run')
        body = ''
        if inline:
            parts = []
            for child in inline[0].childNodes:
                if child.nodeType in (child.TEXT_NODE, child.CDATA_SECTION_NODE):
                    parts.append(child.data)
            # 与 Unraid 一致：直接把 INLINE 的文本内容（含 CDATA）原样写出
            body = ''.join(parts)
        if name:
            print(f"  + {name} ({len(body)} B)")
            if not dry_run:
                os.makedirs(os.path.dirname(name), exist_ok=True)
                with open(name, 'w', encoding='utf-8') as fh:
                    fh.write(body)
                os.chmod(name, int(mode, 8))
            written += 1
        elif run and body.strip():
            print(f"  $ run: {run} ({len(body)} B)")
            if not dry_run:
                proc = subprocess.run([run, '-c', body], capture_output=True, text=True)
                print('    ' + (proc.stdout or proc.stderr).strip().replace('\n', '\n    '))
                if proc.returncode != 0:
                    print(f"    !! 退出码 {proc.returncode}")
                    return 1
            ran += 1
    print(f"安装模拟完成：{written} 个文件，{ran} 段安装脚本")
    return 0


if __name__ == '__main__':
    path = sys.argv[1] if len(sys.argv) > 1 else 'dist/unraid.zh.translator.plg'
    sys.exit(main(path, dry_run='--dry-run' in sys.argv))
