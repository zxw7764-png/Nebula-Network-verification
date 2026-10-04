# -*- coding: utf-8 -*-
"""
Nebula SDK · 通信信封（协议唯一实现点）—— Nebula 3.1
------------------------------------------------------------------------------
协议规范（与服务端 lib/Handshake.php 严格对齐）：

  ① handshake（明文 JSON，无对称信封）
      请求  { app_key, eph_pub(65B点b64), nc(16B b64), ts, mhash }
      响应  { proto:31, sid, eph_pub, ns, ts_s, sign(ES256), sig_kid, sig_algo }
            sign 对象 = sid|eph_pub|ns|nc|ts_s（绑定双方 nonce，防重放/防 MITM 换钥）

  ② 派生会话密钥
      shared = ECDH(P-256) X 坐标（32 字节大端）
      sk_enc = HKDF-SHA256(shared, salt=nc‖ns, info="nebula31-enc")
      sk_mac = HKDF-SHA256(shared, salt=nc‖ns, info="nebula31-mac")
      sk_enc_rsp = HKDF-SHA256(sk_enc, salt=零, info="nebula31-enc-rsp")  ← 响应方向专用
      （2026-10-03 审计修复：请求/响应密钥分离，消除 GCM nonce 跨方向重用）

  ③ 业务请求（每个 action，含 init）
      { proto:31, sid, seq, t, data, mac, app_key }
        data = base64( iv[12] + AES-256-GCM(业务JSON) + tag[16] )
        iv   = iv_prefix(4, = sha256(nc‖ns)[:4]) ‖ seq 大端(8)  —— seq 严格递增
        mac  = hex(HMAC(sk_mac, sid|seq|t|sha256(data)))
      响应  { proto:31, sid, data(GCM), sig(ES256), sig_kid, sig_algo, code }
            sig = 服务端长期私钥对 data|sid 签名（pin 公钥验签防伪造服务器）

  安全性质：客户端零静态对称机密；内存中只有当次会话的临时密钥。
  会话失效（服务端 5002/过期/重放）自动重握手并重试一次。
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import json
import time
import urllib.error
import urllib.request
from typing import Any, Dict, Optional, Tuple

from . import config
from .crypto import NebulaError, json_encode


# ---------------------------------------------------------------------------
# 3.1 密码学原语（ECDH P-256 / HKDF / AES-256-GCM）
# ---------------------------------------------------------------------------

def _b64e(b: bytes) -> str:
    return base64.b64encode(b).decode("ascii")


def _b64d(s: str) -> bytes:
    return base64.b64decode(s, validate=True)


def _ecdh_keypair():
    """生成 P-256 临时密钥对 → (私钥对象, 65 字节非压缩公钥点)。"""
    from cryptography.hazmat.primitives.asymmetric import ec
    priv = ec.generate_private_key(ec.SECP256R1())
    pub = priv.public_key().public_bytes(
        _ser_encoding(), _ser_format())
    return priv, pub


def _ser_format():
    from cryptography.hazmat.primitives.serialization import PublicFormat
    return PublicFormat.UncompressedPoint


def _ser_encoding():
    from cryptography.hazmat.primitives.serialization import Encoding
    return Encoding.X962


def _ecdh_shared(priv, peer_point: bytes) -> bytes:
    """ECDH 共享密钥（X 坐标 32 字节大端，与 openssl_pkey_derive 一致）。"""
    from cryptography.hazmat.primitives.asymmetric import ec
    peer = ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(), peer_point)
    return priv.exchange(ec.ECDH(), peer)


def _hkdf_sha256(ikm: bytes, salt: bytes, info: bytes, length: int = 32) -> bytes:
    """HKDF-SHA256（RFC 5869，extract-then-expand）。salt 为空时按 RFC 用全零盐。"""
    from cryptography.hazmat.primitives import hashes
    from cryptography.hazmat.primitives.kdf.hkdf import HKDF
    return HKDF(algorithm=hashes.SHA256(), length=length,
                salt=salt if salt else None, info=info).derive(ikm)


def _aesgcm_encrypt(key32: bytes, iv12: bytes, plain: bytes) -> bytes:
    """AES-256-GCM 加密 → 密文 + tag(16) 拼接。"""
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM
    return AESGCM(key32).encrypt(iv12, plain, None)


def _aesgcm_decrypt(key32: bytes, iv12: bytes, ct_tag: bytes) -> bytes:
    """AES-256-GCM 解密（认证失败抛 InvalidTag）。"""
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM
    return AESGCM(key32).decrypt(iv12, ct_tag, None)


def _verify_es256(sig_raw: bytes, message: bytes, pub_pem: str) -> None:
    """ES256 验签（DER 或 r|s 64 字节两种格式都识别）。失败抛 NebulaError(-2)。"""
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import ec, utils as autils
    try:
        pub = serialization.load_pem_public_key(pub_pem.encode("utf-8"))
        if len(sig_raw) == 64:
            r, s = int.from_bytes(sig_raw[:32], "big"), int.from_bytes(sig_raw[32:], "big")
            pub.verify(autils.encode_dss_signature(r, s), message, ec.ECDSA(hashes.SHA256()))
        else:
            pub.verify(sig_raw, message, ec.ECDSA(hashes.SHA256()))
    except Exception as e:
        raise NebulaError(-2, f"响应签名校验失败（服务器伪造或被篡改）: {e}") from e


# ---------------------------------------------------------------------------
# 信封会话
# ---------------------------------------------------------------------------

class Envelope:
    """3.1 ECDH 会话状态：懒握手、seq 单调、会话失效自动重握手。"""

    def __init__(self):
        self.machine_id = ""        # 握手 mhash 用（由 Client 注入）
        # 会话状态
        self._sid = ""
        self._sk_enc = b""
        self._sk_mac = b""
        self._sk_enc_rsp = b""
        self._iv_prefix = b""
        self._seq = 0
        self._clock_offset = 0      # 服务端时钟 - 本地时钟（秒）

    # -- 状态 ----------------------------------------------------------------

    @property
    def active(self) -> bool:
        return bool(self._sid)

    def clear(self) -> None:
        """会话失效/重握手前清空（密钥及时擦除语义）。"""
        self._sid = ""
        self._sk_enc = b""
        self._sk_mac = b""
        self._sk_enc_rsp = b""
        self._iv_prefix = b""
        self._seq = 0

    def set_session(self, kid: str, skey: str) -> None:
        """3.0 遗留接口：3.1 会话由 handshake 建立，此处空实现保持兼容。"""

    def clear_session(self) -> None:
        """兼容旧调用（logout 时清会话）：等价 clear()。"""
        self.clear()

    # -- HTTP ----------------------------------------------------------------

    @staticmethod
    def _post(url: str, body: bytes) -> Dict[str, Any]:
        req_obj = urllib.request.Request(
            url, data=body, method="POST",
            headers={"Content-Type": "application/json",
                     "User-Agent": "NebulaPy/" + config.kClientVersion},
        )
        # 明确不走系统代理（客户端程序直连服务器）
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        try:
            with opener.open(req_obj, timeout=config.kTimeoutSeconds) as resp:
                raw = resp.read()
        except urllib.error.HTTPError as e:
            raise NebulaError(-3, f"HTTP {e.code}") from e
        except urllib.error.URLError as e:
            raise NebulaError(-1, f"网络连接失败: {getattr(e, 'reason', e)}") from e
        except OSError as e:
            raise NebulaError(-1, f"网络连接失败: {e}") from e
        try:
            return json.loads(raw.decode("utf-8"))
        except Exception as e:
            raise NebulaError(-2, f"响应不是 JSON: {e}") from e

    @staticmethod
    def _action_url(action: str) -> str:
        url = config.kApiUrl
        return url + ("&" if "?" in url else "?") + "action=" + action

    # -- 握手 ----------------------------------------------------------------

    def handshake(self) -> None:
        """ECDH P-256 握手：验服务端长期私钥签名后派生会话密钥。"""
        import os
        pub = config.kRespSignPubKey.strip()
        if not pub:
            raise NebulaError(-2, "未配置响应签名公钥(kRespSignPubKey)，拒绝连接")

        priv, point = _ecdh_keypair()
        nc = os.urandom(16)
        mhash = hashlib.sha256((self.machine_id or "").encode("utf-8")).hexdigest()

        resp = self._post(self._action_url("handshake"), json_encode({
            "app_key": config.kAppKey,
            "eph_pub": _b64e(point),
            "nc": _b64e(nc),
            "ts": int(time.time()),
            "mhash": mhash,
        }))

        code = int(resp.get("code", -1))
        if code != 0:
            raise NebulaError(code, str(resp.get("msg", "握手失败")))
        # 服务端把握手数据包在 data 层（{code,msg,data:{proto:31,...}}）
        hs = resp.get("data") if isinstance(resp.get("data"), dict) else resp
        if int(hs.get("proto", 0)) != 31:
            raise NebulaError(-6, "服务端握手响应协议版本异常")

        sid = str(hs.get("sid", ""))
        server_eph_b64 = str(hs.get("eph_pub", ""))
        ns_b64 = str(hs.get("ns", ""))
        sign_b64 = str(hs.get("sign", ""))
        ts_s = int(hs.get("ts_s", 0))
        if len(sid) != 32 or not server_eph_b64 or not ns_b64 or not sign_b64:
            raise NebulaError(-2, "握手响应字段缺失")

        # ① 验签（防伪造服务器 / MITM 换钥）—— 必须先于密钥派生
        signed = f"{sid}|{server_eph_b64}|{ns_b64}|{_b64e(nc)}|{ts_s}".encode("utf-8")
        try:
            sig = _b64d(sign_b64)
        except Exception as e:
            raise NebulaError(-2, f"握手签名解码失败: {e}") from e
        _verify_es256(sig, signed, pub)

        # ② 派生会话密钥
        try:
            peer_point = _b64d(server_eph_b64)
            ns = _b64d(ns_b64)
        except Exception as e:
            raise NebulaError(-2, f"握手响应编码错误: {e}") from e
        if len(peer_point) != 65 or peer_point[0] != 0x04 or len(ns) != 16:
            raise NebulaError(-2, "握手响应公钥/nonce 格式错误")
        shared = _ecdh_shared(priv, peer_point)
        salt = nc + ns
        self._sk_enc = _hkdf_sha256(shared, salt, b"nebula31-enc")
        self._sk_mac = _hkdf_sha256(shared, salt, b"nebula31-mac")
        # 响应方向独立加密密钥（2026-10-03 审计修复）：与服务端
        # hash_hkdf('sha256', sk_enc, 32, 'nebula31-enc-rsp') 同款派生
        self._sk_enc_rsp = _hkdf_sha256(self._sk_enc, b"", b"nebula31-enc-rsp")
        self._iv_prefix = hashlib.sha256(salt).digest()[:4]
        self._sid = sid
        self._seq = 0
        self._clock_offset = ts_s - int(time.time())

    # -- 业务请求 ------------------------------------------------------------

    def _build_envelope(self, payload: Dict[str, Any]) -> Dict[str, Any]:
        self._seq += 1
        seq = self._seq
        iv = self._iv_prefix + seq.to_bytes(8, "big")
        data = _b64e(iv + _aesgcm_encrypt(self._sk_enc, iv, json_encode(payload)))
        t = int(time.time()) + self._clock_offset
        mac = hmac.new(
            self._sk_mac,
            f"{self._sid}|{seq}|{t}|{hashlib.sha256(data.encode('ascii')).hexdigest()}".encode("ascii"),
            hashlib.sha256,
        ).hexdigest()
        return {
            "proto": 31,
            "sid": self._sid,
            "seq": seq,
            "t": t,
            "data": data,
            "mac": mac,
            "app_key": config.kAppKey,
        }

    def _open_response(self, envelope: Dict[str, Any]) -> Tuple[Dict[str, Any], Dict[str, Any]]:
        """拆 3.1 响应：验 ES256 → GCM 解密。返回 (业务 data 字典, 明文响应)。"""
        if int(envelope.get("proto", 0)) != 31 or not isinstance(envelope.get("data"), str) \
                or not envelope.get("data"):
            # 明文错误响应（握手前出错/会话失效等）：直接透传
            extra = {k: v for k, v in envelope.items()
                     if k not in ("code", "msg", "time", "data",
                                  "sig", "sig_algo", "sig_kid")}
            raise NebulaError(int(envelope.get("code", -2)),
                              str(envelope.get("msg", "响应不是 3.1 信封")), extra)

        pub = config.kRespSignPubKey.strip()
        if not pub:
            raise NebulaError(-2, "未配置响应签名公钥(kRespSignPubKey)，拒绝连接")

        data_b64 = str(envelope["data"])
        sig = envelope.get("sig")
        if isinstance(sig, str) and sig:
            try:
                sig_raw = _b64d(sig)
            except Exception as e:
                raise NebulaError(-2, f"响应签名解码失败: {e}") from e
            _verify_es256(sig_raw, f"{data_b64}|{self._sid}".encode("ascii"), pub)
        elif envelope.get("sig_kid"):
            raise NebulaError(-2, "响应缺少签名(sig)")

        raw = _b64d(data_b64)
        if len(raw) < 12 + 16 + 1:
            raise NebulaError(-2, "响应密文过短")
        # 响应方向独立密钥 + 用本地当前 seq 自算 IV（不信包内 IV，防响应重放，
        # 与 C++/C# SDK 行为对齐，2026-10-03 审计修复）
        iv = self._iv_prefix + self._seq.to_bytes(8, "big")
        try:
            plain = _aesgcm_decrypt(self._sk_enc_rsp, iv, raw[12:])
        except Exception as e:
            raise NebulaError(-2, f"响应解密失败（GCM 认证未通过）: {e}") from e
        try:
            parsed = json.loads(plain.decode("utf-8"))
        except Exception as e:
            raise NebulaError(-2, f"业务响应 JSON 解析失败: {e}") from e

        biz_code = int(parsed.get("code", 0))
        if biz_code != 0:
            extra = {k: v for k, v in parsed.items()
                     if k not in ("code", "msg", "time", "data")}
            raise NebulaError(biz_code, str(parsed.get("msg", f"业务错误 {biz_code}")), extra)
        return (parsed.get("data") or {}), parsed

    def send(self, action: str, payload: Dict[str, Any], use_session: bool = True) \
            -> Tuple[Dict[str, Any], Dict[str, Any]]:
        """发送业务请求并解密。懒握手；非 3.1 信封响应自动重握手重试一次。

        use_session 参数为 3.0 遗留签名，3.1 下所有请求都走会话，保留仅为兼容。
        """
        last_err: Optional[NebulaError] = None
        for attempt in range(2):
            if not self.active:
                self.handshake()
            envelope = self._build_envelope(payload)
            resp = self._post(self._action_url(action), json_encode(envelope))
            try:
                return self._open_response(resp)
            except NebulaError as e:
                # 会话失效/信封异常 → 清会话重握手再试一次；业务错误不重试
                if e.code in (5002, 5004) or int(resp.get("proto", 0)) != 31:
                    self.clear()
                    last_err = e
                    continue
                raise
        raise last_err or NebulaError(-2, "3.1 会话错误")
