#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Nebula 客户端 API 契约测试
============================================================
运行：
  python tests/contract/test_core.py [-v]

依赖：
  - numpy 无；仅 cryptography（venv 自带）
  - 本地服务端 http://127.0.0.1（Apache + MySQL）
  - 测试软件 app_key / 响应公钥（见 config.json）

覆盖（对应 docs/API.md §2）：
  handshake 验签/参数错误、信封防重放、notice 软件隔离、init 契约、
  login 错误/成功、userinfo/devices/logout 的 machine_id 强制、version/online。
"""
import json
import os
import sys
import time
import traceback

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from nbtest import NebulaClient, NebulaError, load_resp_pub

CFG = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "config.json"), encoding="utf-8"))
API = CFG["api_base"]
APP_KEY = CFG["app_key"]
MACHINE = CFG["machine_id"]
PUB_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), CFG["resp_sign_keys_file"])
VERBOSE = "-v" in sys.argv

RESULTS = []


def check(name, fn):
    try:
        detail = fn()
        RESULTS.append((name, True, detail))
        if VERBOSE:
            print(f"  PASS  {name}: {detail}")
    except AssertionError as e:
        RESULTS.append((name, False, f"断言失败: {e}"))
        print(f"  FAIL  {name}: {e}")
    except NebulaError as e:
        RESULTS.append((name, False, f"协议错误 {e.code}: {e.msg}"))
        print(f"  FAIL  {name}: {e.code} {e.msg}")
    except Exception as e:
        RESULTS.append((name, False, f"异常: {type(e).__name__}: {e}"))
        print(f"  FAIL  {name}: {type(e).__name__}: {e}")
        if VERBOSE:
            traceback.print_exc()


def ok(resp, extra=""):
    assert resp.get("code") == 0, f"期望 code=0 实际={resp.get('code')} msg={resp.get('msg')} {extra}"


def err(resp, code):
    assert resp.get("code") == code, f"期望 code={code} 实际={resp.get('code')} msg={resp.get('msg')}"


def new_client():
    pub = load_resp_pub(PUB_FILE)
    assert pub, "无法从 resp_sign_keys.php 读取公钥"
    return NebulaClient(API, APP_KEY, pub, MACHINE)


# 主会话：业务类用例共享一次握手（服务端 hs31 限流 10 次/分/IP，握手昂贵，尽量复用）
MAIN = new_client()
MAIN.handshake()


# ==================================================================
# 用例
# ==================================================================

def t_handshake_ok():
    c = new_client()
    c.handshake()
    assert c.sid and len(c.sid) == 32, "sid 非法"
    return f"sid={c.sid[:8]}… 验签通过"

def t_handshake_bad_appkey():
    c = new_client()
    c.app_key = "SWINVALID000"
    try:
        c.handshake()
        raise AssertionError("无效 app_key 不应握手成功")
    except NebulaError as e:
        assert e.code == 1004, f"期望 1004 实际 {e.code}"
        return "无效 app_key → 1004"

def t_handshake_bad_ts():
    c = new_client()
    try:
        c.handshake(ts=int(time.time()) - 9999)
        raise AssertionError("超窗时间戳不应通过")
    except NebulaError as e:
        assert e.code == 5003, f"期望 5003 实际 {e.code}"
        return "超窗时间戳 → 5003"

def t_replay_envelope_rejected():
    c = MAIN
    r1 = c.request("notice", {"id": 0})
    ok(r1)
    # 重放同一信封：手工构造第二份相同 seq 的请求
    import base64, hashlib, hmac as hm, json as j
    seq2, t2 = c.seq, int(time.time()) + c.ts_offset
    payload = j.dumps({"id": 0}, ensure_ascii=False, separators=(",", ":")).encode()
    iv = c.iv_prefix + seq2.to_bytes(8, "big")
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM
    tag_ct = AESGCM(c.sk_enc).encrypt(iv, payload, None)
    data = base64.b64encode(iv + tag_ct).decode()
    mac = hm.new(c.sk_mac, f"{c.sid}|{seq2}|{t2}|{hashlib.sha256(data.encode()).hexdigest()}".encode(), hashlib.sha256).hexdigest()
    resp = c._post(c._action_url("notice"), json.dumps({"proto": 31, "app_key": APP_KEY, "sid": c.sid, "seq": seq2, "t": t2, "data": data, "mac": mac}).encode())
    assert resp.get("code") == 5004, f"seq 重放应返回 5004，实际: {resp}"
    return "seq 重放 → 5004 拒绝"

def t_notice_plain():
    c = new_client()
    r = c.plain("notice", {"id": 0})
    ok(r)
    d = r.get("data", {})
    assert "list" in d and "total" in d, "notice 响应缺 list/total"
    for n in d["list"]:
        assert n.get("id") and n.get("title") is not None, "公告缺 id/title"
    return f"notice 明文 OK，共 {d['total']} 条"

def t_notice_single_missing():
    c = new_client()
    r = c.plain("notice", {"id": 99999999})
    err(r, 1001)
    return "不存在的公告 id → 1001（软件隔离/不存在）"

def t_init_contract():
    c = MAIN
    r = c.request("init", {"client_ver": CFG["client_version"], "machine_id": MACHINE, "device_name": CFG.get("device_name", ""), "os_info": CFG.get("os_info", "")})
    ok(r)
    d = r.get("data", {})
    for f in ["server_time", "app_key", "software", "heartbeat_interval", "login", "device_fp"]:
        assert f in d, f"init 响应缺字段 {f}"
    assert d["app_key"] == APP_KEY, "app_key 回显不一致"
    return f"init 契约字段齐全 login={d.get('login', {}).get('method')}"

def t_login_wrong_pwd():
    c = MAIN
    r = c.request("login", {"username": "no_such_user_zzz", "password": "WrongPass123",
                            "machine_id": MACHINE, "device_name": CFG.get("device_name", ""),
                            "os_info": CFG.get("os_info", ""), "client_ver": CFG["client_version"]})
    err(r, 2001)
    return "错误凭据 → 2001"

def t_register_login_flow():
    name = f"contract_{int(time.time())}"
    pwd = "Zxw@12345"
    c = MAIN
    # 注册
    r = c.request("register", {"username": name, "password": pwd, "machine_id": MACHINE})
    ok(r)
    # 新注册账号默认未激活 → 登录应 2004（契约：需激活码才能登录）
    r = c.request("login", {"username": name, "password": pwd, "machine_id": MACHINE,
                             "device_name": CFG.get("device_name", ""), "os_info": CFG.get("os_info", ""),
                             "client_ver": CFG["client_version"]})
    assert r.get("code") == 2004, f"未激活账号登录应 2004，实际 {r}"

    code = CFG.get("activation_code", "")
    if not code:
        return f"注册成功；未激活登录 → 2004 契约通过（未配置 activation_code，跳过已激活段）"
    # 激活（username+password 直绑）→ 登录 → 业务链路
    r = c.request("activate", {"username": name, "password": pwd, "code": code, "machine_id": MACHINE})
    ok(r)
    r = c.request("login", {"username": name, "password": pwd, "machine_id": MACHINE,
                             "device_name": CFG.get("device_name", ""), "os_info": CFG.get("os_info", ""),
                             "client_ver": CFG["client_version"]})
    ok(r)
    d = r.get("data", {})
    token = d.get("token", "")
    assert token, "登录响应缺 token"
    assert d.get("user", {}).get("username") == name, "user.username 不一致"
    # userinfo / devices（machine_id 强制）
    r = c.request("userinfo", {"token": token, "machine_id": MACHINE})
    ok(r)
    assert r["data"]["user"]["username"] == name, "userinfo 用户名不一致"
    r = c.request("devices", {"token": token, "machine_id": MACHINE})
    ok(r)
    # logout → token 失效
    r = c.request("logout", {"token": token, "machine_id": MACHINE})
    ok(r)
    assert r["data"].get("logout") is True, "logout 未返回 logout=true"
    r = c.request("heartbeat", {"token": token, "machine_id": MACHINE})
    assert r.get("code") != 0, "登出后心跳不应成功"
    return f"注册→2004→激活→登录→userinfo→devices→logout 全链路 OK（用户 {name}）"

def t_logout_no_machine_rejected():
    c = MAIN
    r = c.request("logout", {"token": "f" * 32})
    assert r.get("code") != 0, "缺 machine_id 的 logout 不应成功（2.65.34 契约）"
    return "logout 缺 machine_id → 拒绝"

def t_logout_invalid_token():
    c = MAIN
    r = c.request("logout", {"token": "f" * 32, "machine_id": MACHINE})
    assert r.get("code") != 0, "无效 token logout 不应成功"
    assert r.get("data", {}).get("need_relogin") is True, "应携带 need_relogin"
    return "无效 token logout → 报错 + need_relogin"

def t_version_check():
    c = new_client()
    r = c.plain("version", {"version": "0.0.1", "channel": "stable"})
    ok(r)
    d = r.get("data", {})
    for f in ["latest", "min", "need_update", "force_update", "download_url", "file_hash", "file_size"]:
        assert f in d, f"version 响应缺字段 {f}"
    assert d.get("need_update") is not False, "0.0.1 应 need_update"
    latest = d.get("latest", "")
    assert latest, "latest 为空"
    return f"version 契约 OK latest={latest} needs_update={d.get('need_update')} force={d.get('force_update')}"

def t_online():
    c = new_client()
    r = c.plain("online", {})
    ok(r)
    d = r.get("data", {})
    assert "online" in d or "count" in d, "online 响应缺字段"
    return f"online OK {d}"


TESTS = [
    ("handshake 成功+验签", t_handshake_ok),
    ("handshake 无效 app_key → 1004", t_handshake_bad_appkey),
    ("handshake 超窗时间戳 → 5003", t_handshake_bad_ts),
    ("信封 seq 重放 → 5004", t_replay_envelope_rejected),
    ("notice 明文列表（软件隔离）", t_notice_plain),
    ("notice 单条不存在 → 1001", t_notice_single_missing),
    ("init 契约字段", t_init_contract),
    ("login 错误密码 → 2001", t_login_wrong_pwd),
    ("注册→登录→业务→登出 全链路", t_register_login_flow),
    ("logout 缺 machine_id → 拒绝", t_logout_no_machine_rejected),
    ("logout 无效 token → 报错", t_logout_invalid_token),
    ("version 契约字段", t_version_check),
    ("online 在线数", t_online),
]


def main():
    print(f"Nebula 客户端 API 契约测试  目标: {API}")
    print(f"测试软件 app_key: {APP_KEY}\n")
    for name, fn in TESTS:
        check(name, fn)
    print("\n" + "=" * 60)
    passed = sum(1 for _, ok_, _ in RESULTS if ok_)
    for name, ok_, detail in RESULTS:
        tag = "PASS" if ok_ else "FAIL"
        print(f"  [{tag}] {name}")
        if not ok_ and not VERBOSE:
            print(f"         {detail}")
    print("=" * 60)
    print(f"结果: {passed}/{len(RESULTS)} 通过")
    sys.exit(0 if passed == len(RESULTS) else 1)


if __name__ == "__main__":
    main()