#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Nebula 3.1 协议测试客户端（独立实现，走真实 HTTP）
============================================================
仅依赖 cryptography 库，实现：
  - ECDH P-256 握手（客户端临时密钥对 + 服务端 eph_pub 交换）
  - HKDF-SHA256 派生 sk_enc / sk_mac / sk_enc_rsp
  - AES-256-GCM 业务信封加解密（IV = iv_prefix ‖ seq BE）
  - HMAC-SHA256 请求 MAC（绑 sid|seq|t|sha256(data)）
  - ES256 响应验签（pin 响应签名公钥）
"""
import base64
import hashlib
import hmac as hmac_mod
import json
import os
import re
import time
import urllib.request
import urllib.error

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec as ec_asym
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.hkdf import HKDF


def hkdf(key: bytes, length: int, info: bytes, salt: bytes) -> bytes:
    return HKDF(algorithm=hashes.SHA256(), length=length, salt=salt, info=info).derive(key)


def pack_seq(seq: int) -> bytes:
    return seq.to_bytes(8, "big")


class NebulaError(Exception):
    def __init__(self, code, msg, raw=None):
        super().__init__(f"[{code}] {msg}")
        self.code = code
        self.msg = msg
        self.raw = raw or {}


class NebulaClient:
    def __init__(self, api_base, app_key, resp_pub_pem, machine_id="contract-test-0001",
                 device_name="Contract", os_info="Windows", client_version="2.65.35"):
        self.api = api_base.rstrip("/")
        self.app_key = app_key
        self.resp_pub = serialization.load_pem_public_key(resp_pub_pem)
        self.machine_id = machine_id
        self.device_name = device_name
        self.os_info = os_info
        self.client_version = client_version

        self.sid = None
        self.sk_enc = None
        self.sk_mac = None
        self.sk_rsp = None
        self.iv_prefix = None
        self.seq = 0
        self.ts_offset = 0  # 与服务端 ts_s 的偏差校准

    # --------------------------------------------------------------
    # 基础 HTTP
    # --------------------------------------------------------------
    def _post(self, url, data, timeout=10):
        req = urllib.request.Request(url, data=data, method="POST")
        req.add_header("Content-Type", "application/json")
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:
                raw = r.read()
        except urllib.error.HTTPError as e:
            raw = e.read()
        try:
            return json.loads(raw.decode("utf-8"))
        except Exception:
            return {"code": 9999, "msg": "非 JSON 响应: " + raw[:200].decode("utf-8", "replace")}

    def _action_url(self, action):
        sep = "&" if "?" in self.api else "?"
        return self.api + sep + "action=" + action

    # --------------------------------------------------------------
    # 3.1 握手
    # --------------------------------------------------------------
    def handshake(self, nc=None, ts=None, mhash=""):
        self._priv = ec_asym.generate_private_key(ec_asym.SECP256R1())
        pub = self._priv.public_key()
        pn = pub.public_numbers()
        self._eph_pub = b"\x04" + pn.x.to_bytes(32, "big") + pn.y.to_bytes(32, "big")
        nc = nc or os.urandom(16)
        ts = ts if ts is not None else int(time.time())
        resp = self._post(self._action_url("handshake"), json.dumps({
            "app_key": self.app_key,
            "eph_pub": base64.b64encode(self._eph_pub).decode(),
            "nc": base64.b64encode(nc).decode(),
            "ts": ts,
            "mhash": mhash,
        }).encode())
        if resp.get("code"):
            raise NebulaError(resp.get("code", -1), resp.get("msg", "握手失败"), resp)
        d = resp.get("data") or resp
        self.sid = d["sid"]
        ns = base64.b64decode(d["ns"])
        spt = base64.b64decode(d["eph_pub"])
        ts_s = d["ts_s"]

        # 验签：sid|eph_pub_S|ns|nc|ts_s
        signed = f"{self.sid}|{d['eph_pub']}|{d['ns']}|{base64.b64encode(nc).decode()}|{ts_s}".encode()
        self._verify(signed, base64.b64decode(d["sign"]))

        # ECDH 共享密钥
        peer = ec_asym.EllipticCurvePublicKey.from_encoded_point(ec_asym.SECP256R1(), spt)
        shared = self._priv.exchange(ec_asym.ECDH(), peer)
        if len(shared) != 32:
            raise NebulaError(9999, "ECDH 共享密钥长度异常")

        salt = nc + ns
        self.sk_enc = hkdf(shared, 32, b"nebula31-enc", salt)
        self.sk_mac = hkdf(shared, 32, b"nebula31-mac", salt)
        self.iv_prefix = hashlib.sha256(salt).digest()[:4]
        self.ts_offset = ts_s - int(time.time())
        self.seq = 0
        return resp

    # --------------------------------------------------------------
    # 业务请求（信封）
    # --------------------------------------------------------------
    def request(self, action, body: dict, machine_id=None):
        if not self.sid:
            raise NebulaError(9999, "未握手")
        self.seq += 1
        seq = self.seq
        t = int(time.time()) + self.ts_offset
        payload = json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        iv = self.iv_prefix + pack_seq(seq)
        aes = AESGCM(self.sk_enc)
        tag_ct = aes.encrypt(iv, payload, None)
        data = base64.b64encode(iv + tag_ct).decode()
        mac = hmac_mod.new(self.sk_mac, f"{self.sid}|{seq}|{t}|{hashlib.sha256(data.encode()).hexdigest()}".encode(),
                           hashlib.sha256).hexdigest()
        outer = {
            "proto": 31,
            "app_key": self.app_key,
            "sid": self.sid,
            "seq": seq,
            "t": t,
            "data": data,
            "mac": mac,
        }
        raw = self._post(self._action_url(action), json.dumps(outer).encode())
        return self._unwrap(raw)

    def _unwrap(self, raw):
        # 无会话错误（明文）或信封
        if "data" not in raw or "proto" not in raw:
            return raw
        # 验签 data|sid
        signed = f"{raw['data']}|{raw['sid']}".encode()
        self._verify(signed, base64.b64decode(raw["sig"]))
        # 解密（sk_enc_rsp）
        enc = base64.b64decode(raw["data"])
        iv = enc[:12]
        ct, tag = enc[12:-16], enc[-16:]
        if self.sk_rsp is None:
            self.sk_rsp = hkdf(self.sk_enc, 32, b"nebula31-enc-rsp", b"")
        plain = AESGCM(self.sk_rsp).decrypt(iv, ct + tag, None)
        obj = json.loads(plain.decode("utf-8"))
        return obj

    def _verify(self, message: bytes, sig: bytes):
        try:
            self.resp_pub.verify(sig, message, ec_asym.ECDSA(hashes.SHA256()))
        except Exception as e:
            raise NebulaError(5002, f"响应签名校验失败: {e}")

    # --------------------------------------------------------------
    # 公开接口（无需握手 / 明文）
    # --------------------------------------------------------------
    def plain(self, action, body: dict):
        params = dict(body)
        params["app_key"] = self.app_key
        return self._post(self._action_url(action), json.dumps(params).encode())


def load_resp_pub(filepath):
    """从 config/resp_sign_keys.php 提取 public PEM"""
    src = open(filepath, encoding="utf-8").read()
    m = re.search(r"BEGIN PUBLIC KEY-----.*?END PUBLIC KEY-----", src, re.S)
    if not m:
        return None
    # PHP var_export 把换行写成字面 "\\n"（反斜杠+n），需还原为真实换行
    body = m.group(0).replace("\\r", "").replace("\\n", "\n")
    lines = [ln.strip() for ln in body.splitlines() if ln.strip()]
    pem = "-----BEGIN PUBLIC KEY-----\n" + "\n".join(
        ln for ln in lines
        if "BEGIN PUBLIC KEY-----" not in ln and "END PUBLIC KEY-----" not in ln
    ) + "\n-----END PUBLIC KEY-----\n"
    return pem.encode()