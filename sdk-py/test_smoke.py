# -*- coding: utf-8 -*-
"""Nebula Python SDK headless 联调脚本(本地服务端 http://127.0.0.1/api/index.php)"""
import sys
import io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

sys.path.insert(0, r"D:/phpstudy_pro/WWW/login/nebula-py")

import nebula
from nebula import Client, NebulaError, feature

PASS, FAIL = 0, 0

def check(name, cond, detail=""):
    global PASS, FAIL
    mark = "PASS" if cond else "FAIL"
    if cond: PASS += 1
    else: FAIL += 1
    print(f"[{mark}] {name}" + (f"  -- {detail}" if detail else ""))

print("=" * 64)
print("Nebula Python SDK 联调")
print("=" * 64)
print(f"机器码: {nebula.get_stable_machine_id()}")

# 本机机器码在测试库黑名单里(旧测试数据),联调用指定机器码
c = nebula.create_default_client(machine_id="pyt3st0000000000")

# ── ① init ────────────────────────────────────────────────────────────────
ir = c.init()
check("init ok", ir.ok, f"code={ir.code} msg={ir.msg}")
check("init site/software", bool(ir.site_name or ir.software_name),
      f"site={ir.site_name!r} sw={ir.software_name!r}")
check("init 会话密钥下发", c._env.session_kid != "" and c._env.session_salt != "",
      f"kid={c._env.session_kid[:8]}...")
check("init 登录方式", ir.login_spec.method == "password", f"method={ir.login_spec.method}")

# ── ② login 负向(错误密码)────────────────────────────────────────────────
lr_bad = c.login("pytest_sdk", "wrong_password!")
check("login 负向(错误密码)被拒", not lr_bad.ok and lr_bad.code != 0,
      f"code={lr_bad.code} msg={lr_bad.msg}")

# ── ③ login 正向 ──────────────────────────────────────────────────────────
lr = c.login("pytest_sdk", "pytest123")
check("login ok", lr.ok, f"code={lr.code} msg={lr.msg}")
check("login token", lr.ok and len(lr.token) > 8, f"token={lr.token[:12]}...")
check("login user", lr.ok and lr.user.user_id > 0 and lr.user.username == "pytest_sdk",
      f"uid={lr.user.user_id} name={lr.user.username} vip={lr.user.vip_text}")
check("login feature_key 字段存在", lr.ok and "feature_key" in repr(lr), "")
print(f"    feature_key = {lr.feature_key!r}")

# ── ④ feature 密钥包 seal/open 回环(NF1 协议)───────────────────────────
fk = lr.feature_key or "test-feature-key-123"
sealed = feature.seal("核心数据:hello nebula".encode("utf-8"), fk)
check("feature.seal 生成 NF1 包", sealed.startswith("NF1."), sealed[:40] + "...")
opened, err = feature.open_pack(sealed, fk)
check("feature.open 解密回环", opened is not None and b"hello nebula" in opened, err)
opened_bad, err_bad = feature.open_pack(sealed, fk + "x")
check("feature.open 错误密钥被拒", opened_bad is None and "校验失败" in err_bad, err_bad)
tampered = sealed[:-4] + ("0000" if sealed[-4:] != "0000" else "1111")
opened_t, err_t = feature.open_pack(tampered, fk)
check("feature.open 篡改被拒", opened_t is None, err_t)

# ── ⑤ heartbeat ───────────────────────────────────────────────────────────
if lr.ok:
    hb = c.heartbeat_once(lr.token)
    check("heartbeat online", hb.online and hb.code == 0, f"code={hb.code} msg={hb.msg} remain={hb.remain_text}")

    # ── ⑥ logout ───────────────────────────────────────────────────────────
    lo = c.logout(lr.token)
    check("logout", lo, "")

print("=" * 64)
print(f"结果: {PASS} 通过, {FAIL} 失败")
sys.exit(1 if FAIL else 0)
