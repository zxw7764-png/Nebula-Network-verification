# -*- coding: utf-8 -*-
"""
Nebula SDK · 功能密钥数据包（可选）
------------------------------------------------------------------------------
格式规范见服务端 docs/API.md 2.18。

「下发解密密钥，不下发验证结果」：
  接入方把核心数据用 feature_key 加密后随程序分发；服务端仅在 login 成功后
  下发 feature_key。patch 掉登录判定也拿不到密钥，密文数据永远解不开。

数据包格式 v1（encrypt-then-MAC）:
    NF1.<b64( iv[16] + AES-256-CBC(plain) )>.<hex( HMAC-SHA256 )>
      aesKey = SHA256(feature_key + "|nebula-feature-aes")   域分离派生
      macKey = SHA256(feature_key + "|nebula-feature-mac")
      HMAC 对象 = "NF1." + 第一段原文；先验签后解密。
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import os
from typing import Optional, Tuple

from .crypto import aes256_cbc_decrypt, aes256_cbc_encrypt, constant_time_equals


def derive_aes_key(feature_key: str) -> bytes:
    return hashlib.sha256((feature_key + "|nebula-feature-aes").encode("utf-8")).digest()


def derive_mac_key(feature_key: str) -> bytes:
    return hashlib.sha256((feature_key + "|nebula-feature-mac").encode("utf-8")).digest()


def _hmac_hex(mac_key: bytes, obj: str) -> str:
    return hmac.new(mac_key, obj.encode("utf-8"), hashlib.sha256).hexdigest()


def seal(plain: bytes, feature_key: str) -> str:
    """加密核心数据为功能数据包（开发期使用）。失败返回空串。"""
    if not feature_key or not plain:
        return ""
    iv = os.urandom(16)
    blob = iv + aes256_cbc_encrypt(derive_aes_key(feature_key), iv, plain)  # 协议: b64(iv[16]+cipher)
    p1 = base64.b64encode(blob).decode("ascii")
    mac = _hmac_hex(derive_mac_key(feature_key), "NF1." + p1)
    return "NF1." + p1 + "." + mac


def open_pack(pack: str, feature_key: str) -> Tuple[Optional[bytes], str]:
    """解开功能数据包（运行期使用；login 成功拿到 lr.feature_key 后调用）。

    返回 (明文 or None, 失败原因)。篡改 / 密钥错误均被先验签拒绝。
    """
    if len(pack) < 10 or not feature_key:
        return None, "数据包或功能密钥为空"
    if not pack.startswith("NF1."):
        return None, "数据包版本不匹配"
    rest = pack[4:]
    d1 = rest.find(".")
    if d1 < 0 or rest.find(".", d1 + 1) >= 0:
        return None, "数据包格式错误"
    p1, mac = rest[:d1], rest[d1 + 1:]

    # ① 先验签后解密（encrypt-then-MAC）
    expected = _hmac_hex(derive_mac_key(feature_key), "NF1." + p1)
    if not mac or not constant_time_equals(expected, mac):
        return None, "数据包校验失败（被篡改或功能密钥错误）"

    # ② 解密（blob 自带前置 IV）
    try:
        raw = base64.b64decode(p1, validate=True)
        if len(raw) <= 16:
            return None, "数据包解密失败"
        out = aes256_cbc_decrypt(derive_aes_key(feature_key), raw[:16], raw[16:])
        return out, ""
    except Exception:
        return None, "数据包解密失败"
