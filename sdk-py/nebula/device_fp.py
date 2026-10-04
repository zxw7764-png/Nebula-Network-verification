"""设备指纹采集（device_fp）。

与服务端 lib/DeviceFp.php 组件权重表一致：board / cpu / disk / bios / mac / gpu。
- 硬件序列号类组件取原始值 MD5 后前 16 位 hex（服务端只做等值/相似度比较）
- mac 上报裸地址（保留分隔符均可，服务端会归一化），供虚拟机 OUI 识别
- 过滤 OEM 占位值（"Default string" 之类会让不同机器得到相同组件）
- 一个有效组件都没有时返回空串（此时登录请求不带 device_fp 字段）

采集通过一次 PowerShell(CIM) 调用完成，进程内懒执行并缓存。
非 Windows 平台仅上报 mac（uuid.getnode()）。
"""

from __future__ import annotations

import hashlib
import re
import subprocess
import sys
import uuid
from typing import Dict, Optional

__all__ = ["collect_fingerprint_json"]

_CACHED: Optional[str] = None

# OEM 占位值关键词（小写包含匹配即视为无效）
_PLACEHOLDERS = (
    "default", "to be filled", "not specified", "system serial",
    "chassis", "unknown", "none", "0123456789", "0000000000",
)


def _value_ok(value: str) -> bool:
    """过滤过短值与 OEM 占位值。"""
    if len(value) < 4:
        return False
    low = value.lower()
    if any(p in low for p in _PLACEHOLDERS):
        return False
    return True


def _hash(raw: str) -> str:
    """MD5 原始值 → 前 16 位 hex（与服务端/C++ SDK 一致）。"""
    return hashlib.md5(raw.encode("utf-8", "replace")).hexdigest()[:16]


def _ps_query() -> Dict[str, str]:
    """一次 PowerShell 调用取全部 CIM 属性，返回 {组件: 原始值}。"""
    script = (
        "$ErrorActionPreference='SilentlyContinue';"
        "(Get-CimInstance Win32_BaseBoard).SerialNumber;"
        "(Get-CimInstance Win32_Processor).ProcessorId;"
        "(Get-CimInstance Win32_DiskDrive -Property SerialNumber | "
        "Select-Object -First 1).SerialNumber;"
        "(Get-CimInstance Win32_BIOS).SerialNumber;"
        "(Get-CimInstance Win32_VideoController | Select-Object -First 1).Name"
    )
    try:
        out = subprocess.run(
            ["powershell", "-NoProfile", "-NonInteractive", "-Command", script],
            capture_output=True, text=True, timeout=8, creationflags=0x08000000,
        ).stdout or ""
    except Exception:
        return {}
    # 按行切，剔除空行与 PowerShell 横幅
    lines = [ln.strip() for ln in out.splitlines() if ln.strip()]
    if len(lines) < 5:
        return {}
    return {"board": lines[0], "cpu": lines[1], "disk": lines[2],
            "bios": lines[3], "gpu": " ".join(lines[4:])}


def _primary_mac() -> str:
    """主网卡 MAC（裸地址）。Windows 走 CIM 物理网卡查询；其他平台 uuid.getnode()。"""
    if sys.platform == "win32":
        try:
            out = subprocess.run(
                ["powershell", "-NoProfile", "-NonInteractive", "-Command",
                 "(Get-CimInstance Win32_NetworkAdapter -Filter "
                 "\"PhysicalAdapter=true AND NetEnabled=true\" | "
                 "Select-Object -First 1).MACAddress"],
                capture_output=True, text=True, timeout=8, creationflags=0x08000000,
            ).stdout or ""
            mac = out.strip()
            if re.fullmatch(r"([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}", mac):
                return mac.upper()
        except Exception:
            pass
        return ""
    node = uuid.getnode()
    mac = f"{node:012x}"
    # 多播/本地位全 1 说明拿到的不是真实网卡
    if (node >> 40) & 0x03:
        return ""
    return ":".join(mac[i:i + 2] for i in range(0, 12, 2)).upper()


def collect_fingerprint_json() -> str:
    """返回 device_fp JSON 字符串；无有效组件时返回空串。进程内缓存。"""
    global _CACHED
    if _CACHED is not None:
        return _CACHED

    comps: Dict[str, str] = {}
    if sys.platform == "win32":
        raw = _ps_query()
        for key in ("board", "cpu", "disk", "bios", "gpu"):
            v = raw.get(key, "").strip()
            if v and _value_ok(v):
                comps[key] = _hash(v)

    mac = _primary_mac()
    if mac:
        comps["mac"] = re.sub(r"[^0-9a-f]", "", mac.lower())  # 裸 hex，服务端归一化一致

    if not comps:
        _CACHED = ""
        return ""

    import json
    _CACHED = json.dumps(comps, separators=(",", ":"), ensure_ascii=False)
    return _CACHED
