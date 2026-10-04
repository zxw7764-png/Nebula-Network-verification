# -*- coding: utf-8 -*-
"""
Nebula SDK · 加密原语
------------------------------------------------------------------------------
加密原语规范（与服务端实现一致）：

  AES-256-CBC   key = sha256(aes_key)[:32]（原始摘要取前 32 字节）
  报文格式      base64( iv[16] + ciphertext )
  签名          HMAC-SHA256( data + "|" + timestamp + "|" + nonce, salt ) 的 hex
  比较          恒定时间比较（hmac.compare_digest）
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
from typing import Optional, Tuple

from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.primitives import padding as _cpadding


class NebulaError(Exception):
    """SDK 本地错误（网络 / 验签 / 解密失败等）。code < 0 为本地错误码。

    extra：服务端业务错误响应里除 code/msg/time/data 外的顶层标记
    （如 need_relogin / kick / need_activate），供调用方按需读取。"""

    def __init__(self, code: int, msg: str, extra: dict | None = None):
        super().__init__(msg)
        self.code = code
        self.msg = msg
        self.extra: dict = dict(extra) if extra else {}


def derive_key(aes_key: str) -> bytes:
    """归一化密钥到 32 字节：sha256(aes_key) 原始摘要前 32 字节。"""
    return hashlib.sha256(aes_key.encode("utf-8")).digest()[:32]


def aes256_cbc_encrypt(key32: bytes, iv16: bytes, plain: bytes) -> bytes:
    """AES-256-CBC 加密（PKCS7 填充，与服务端 openssl_encrypt 对齐）。"""
    padder = _cpadding.PKCS7(128).padder()
    padded = padder.update(plain) + padder.finalize()
    enc = Cipher(algorithms.AES(key32), modes.CBC(iv16)).encryptor()
    return enc.update(padded) + enc.finalize()


def aes256_cbc_decrypt(key32: bytes, iv16: bytes, cipher: bytes) -> bytes:
    """AES-256-CBC 解密 + 去 PKCS7 填充；失败抛 NebulaError(-2)。"""
    try:
        dec = Cipher(algorithms.AES(key32), modes.CBC(iv16)).decryptor()
        padded = dec.update(cipher) + dec.finalize()
        unpadder = _cpadding.PKCS7(128).unpadder()
        return unpadder.update(padded) + unpadder.finalize()
    except Exception as e:
        raise NebulaError(-2, f"解密失败: {e}") from e


def encrypt_b64(plain: bytes, aes_key: str) -> str:
    """报文加密：base64( iv[16] + ciphertext )，每次随机 IV。"""
    iv = os.urandom(16)
    return base64.b64encode(iv + aes256_cbc_encrypt(derive_key(aes_key), iv, plain)).decode("ascii")


def decrypt_b64(b64: str, aes_key: str) -> bytes:
    """报文解密：base64 -> 前 16 字节 IV + 密文。"""
    raw = base64.b64decode(b64, validate=True)
    if len(raw) <= 16:
        raise NebulaError(-2, "密文长度异常")
    return aes256_cbc_decrypt(derive_key(aes_key), raw[:16], raw[16:])


def sign_hex(data: str, timestamp: int, nonce: str, salt: str) -> str:
    """HMAC-SHA256( data|timestamp|nonce, salt ) 的 hex（服务端 signWithSalt 同构）。"""
    msg = f"{data}|{timestamp}|{nonce}".encode("utf-8")
    return hmac.new(salt.encode("utf-8"), msg, hashlib.sha256).hexdigest()


def sha256_hex(s: str) -> str:
    return hashlib.sha256(s.encode("utf-8")).hexdigest()


def sha256_bytes(s: str) -> bytes:
    return hashlib.sha256(s.encode("utf-8")).digest()


def constant_time_equals(a: str, b: str) -> bool:
    return hmac.compare_digest(a.encode("utf-8"), b.encode("utf-8"))


def random_hex(n_bytes: int) -> str:
    return os.urandom(n_bytes).hex()


def json_encode(obj) -> bytes:
    """紧凑 JSON（不转义中文/斜杠，与 PHP JSON_UNESCAPED_* 对齐）。"""
    return json.dumps(obj, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
