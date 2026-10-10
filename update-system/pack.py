#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
更新包打包工具（Python 版，替代原 pack.php）
============================================================
用法（命令行）：
  python pack.py <版本号> <源目录> [输出路径]

示例：
  python pack.py 1.1.0 ./src ./output/update.zip

功能：
  1. 扫描源目录下所有文件
  2. 自动生成 manifest.json（包含文件列表）
  3. 打包为 ZIP（包含 manifest.json + 所有源文件）
  4. 输出 SHA-256 校验值

打包后的 ZIP 结构：
  update.zip
  ├── manifest.json          <- 自动生成
  ├── version.php
  ├── lib/...
  ├── admin/...
  └── ...

安全机制：
  - 自动排除 config/ 目录（用户配置不被覆盖）
  - 自动排除 storage/ 目录（用户数据不被覆盖）
  - 自动排除 .git、node_modules 等
  - Zip Slip 防护：VersionManager::isSafePath() 在解压时校验
"""

import json
import os
import re
import sys
import time
import zipfile

# --- 与 pack.php 完全一致的配置 ---
DEFAULT_PRODUCT   = 'nebula-verification'
DEFAULT_MIN_VER   = '1.0.0'
DEFAULT_SCHEMA    = 1
DEFAULT_CHANNEL   = 'stable'

EXCLUDE_DIRS  = ['config', 'storage', '.git', 'node_modules', '.catpaw', '.freebuff', '.workbuddy']
EXCLUDE_EXTS  = ['lock', 'log']
# 更新包不携带安装向导：否则解包后站点会重新出现 install/install.php，
# 在缺少 install.lock 时可被重跑安装流程。
EXCLUDE_FILES = ['install/install.php']

USAGE = """更新包打包工具

用法:
  python pack.py <版本号> <源目录> [输出路径]

示例:
  python pack.py 1.1.0 ./update-system ./update.zip

打包完成后会输出:
  - ZIP 文件路径
  - SHA-256 哈希值（填写到后台发布版本时的 SHA-256 字段）

排除规则:
  - config/     用户配置（不被覆盖）
  - storage/    用户数据（不被覆盖）
  - .git/       版本控制
  - *.lock      锁文件
  - install/install.php  安装向导（更新包不携带）
"""


def format_bytes(n):
    if n >= 1073741824:
        return f"{n / 1073741824:.2f} GB"
    if n >= 1048576:
        return f"{n / 1048576:.2f} MB"
    if n >= 1024:
        return f"{n / 1024:.1f} KB"
    return f"{n} B"


def read_version_file(source_dir):
    """从源目录 version.php 提取版本信息（正则与 pack.php 相同），返回 (覆盖字典, build)"""
    overrides = {}
    build = None
    vf = os.path.join(source_dir, 'version.php')
    if os.path.isfile(vf):
        with open(vf, 'r', encoding='utf-8', errors='replace') as f:
            content = f.read()
        patterns = {
            'product':        r"define\('APP_PRODUCT',\s*'([^']+)'\)",
            'version':        r"define\('APP_VERSION',\s*'([^']+)'\)",
            'channel':        r"define\('APP_CHANNEL',\s*'([^']+)'\)",
        }
        ints = {
            'build':        r"define\('APP_BUILD',\s*(\d+)\)",
            'schema_version': r"define\('APP_SCHEMA_VERSION',\s*(\d+)\)",
        }
        for key, pat in patterns.items():
            m = re.search(pat, content)
            if m:
                overrides[key] = m.group(1)
        for key, pat in ints.items():
            m = re.search(pat, content)
            if m:
                if key == 'build':
                    build = int(m.group(1))
                else:
                    overrides[key] = int(m.group(1))
    else:
        # 无 version.php 时 build = 当天日期 + '01'（与 pack.php 的 date('Ymd').'01' 一致）
        build = int(time.strftime('%Y%m%d') + '01')
    return overrides, build


def collect_files(source_dir):
    """递归收集相对路径（正斜杠、已排序），排除规则与 pack.php 一致"""
    files = []
    for root, dirs, names in os.walk(source_dir):
        # 剪枝：相对路径命中排除目录时不再深入
        rel_root = os.path.relpath(root, source_dir).replace('\\', '/')
        keep_dirs = []
        for d in dirs:
            rel = d if rel_root == '.' else rel_root + '/' + d
            if rel in EXCLUDE_DIRS or any(rel.startswith(x + '/') for x in EXCLUDE_DIRS):
                continue
            keep_dirs.append(d)
        dirs[:] = keep_dirs

        for name in names:
            full = os.path.join(root, name)
            if not os.path.isfile(full):
                continue
            rel = os.path.relpath(full, source_dir).replace('\\', '/')
            if rel in EXCLUDE_FILES:
                continue
            ext = os.path.splitext(rel)[1].lstrip('.').lower()
            if ext in EXCLUDE_EXTS:
                continue
            files.append(rel)
    return sorted(files)


def main():
    if len(sys.argv) < 3:
        print(USAGE)
        sys.exit(1)

    version_arg = sys.argv[1]
    source_dir = os.path.abspath(sys.argv[2])
    output_path = sys.argv[3] if len(sys.argv) >= 4 else f"./update_{version_arg}.zip"

    if not os.path.isdir(source_dir):
        print(f"错误：源目录不存在：{source_dir}")
        sys.exit(1)

    # 从 version.php 读取版本信息（存在则覆盖命令行/默认值）
    overrides, build = read_version_file(source_dir)
    product        = overrides.get('product', DEFAULT_PRODUCT)
    version        = overrides.get('version', version_arg)
    channel        = overrides.get('channel', DEFAULT_CHANNEL)
    schema_version = overrides.get('schema_version', DEFAULT_SCHEMA)

    files = collect_files(source_dir)
    if not files:
        print("错误：没有找到需要打包的文件")
        sys.exit(1)

    manifest = {
        'product':        product,
        'version':        version,
        'build':          build,
        'min_version':    DEFAULT_MIN_VER,
        'schema_version': schema_version,
        'channel':        channel,
        'published_at':   time.strftime('%Y-%m-%d %H:%M:%S'),
        'requirements':   {'php': '>=8.0', 'mysql': '>=5.7'},
        'files':          files,
        'release_notes':  [],
    }

    # 打包 ZIP：先写 manifest.json，再写全部文件（DEFLATED 压缩）
    with zipfile.ZipFile(output_path, 'w', zipfile.ZIP_DEFLATED) as zf:
        zf.writestr('manifest.json',
                    json.dumps(manifest, ensure_ascii=False, indent=4))
        for rel in files:
            zf.write(os.path.join(source_dir, rel), rel)

    # 输出结果（SHA-256 分块读取，避免大文件占内存）
    sha = __import__('hashlib').sha256()
    with open(output_path, 'rb') as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b''):
            sha.update(chunk)
    size = os.path.getsize(output_path)

    print()
    print("==========================================")
    print("  更新包打包完成")
    print("==========================================")
    print(f"版本号:     {version}")
    print(f"Build:      {build}")
    print(f"Schema:     {schema_version}")
    print(f"通道:       {channel}")
    print(f"文件数:     {len(files)}")
    print(f"打包大小:   {format_bytes(size)}")
    print(f"输出路径:   {output_path}")
    print(f"SHA-256:    {sha.hexdigest()}")
    print("==========================================")
    print()
    print("下一步：")
    print("  1. 在后台「版本管理」发布新版本")
    print("  2. 填写下载地址、SHA-256")
    print("  3. 上传 ZIP 到下载地址对应的 Web 路径")
    print("  4. 如需数字签名，用 Ed25519 私钥对 ZIP 签名后填写到签名字段")
    print()


if __name__ == '__main__':
    main()
