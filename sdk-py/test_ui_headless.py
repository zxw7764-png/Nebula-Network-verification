# -*- coding: utf-8 -*-
"""headless UI 测试：dummy 显存驱动下真跑 Run() 主循环（init 线程真连本地服务端）。"""
import os
import sys
os.environ["SDL_VIDEODRIVER"] = "dummy"
sys.path.insert(0, r"D:/phpstudy_pro/WWW/login/nebula-py")

import pygame

from nebula.ui.login_window import LoginWindow

lw_holder = {}

# 启动流水线含 3 次网络请求（init + 公告），本地慢时约 3-5 秒：
# 注入点 = init 成功后至少 20 帧，或 10 秒兜底（覆盖失败路径），不能固定第 8 帧
frame = {"n": 0}
orig_get = pygame.event.get
def fake_get():
    frame["n"] += 1
    lw = lw_holder.get("lw")
    if lw and frame["n"] > 20 and (lw.init_ok or frame["n"] > 300):
        return [pygame.event.Event(pygame.QUIT)]
    return orig_get()
pygame.event.get = fake_get

lw = LoginWindow()
lw_holder["lw"] = lw
ok = lw.Run()

print(f"Run returned: {ok} (QUIT 注入应为 False)")
print(f"init_ok: {lw.init_ok}")
print(f"status: {lw.status!r}")
print(f"notice_text: {lw.notice_text!r}")
print(f"user_label/secret_label: {lw.user_label!r}/{lw.secret_label!r}")
print(f"login_spec method: {lw.init_result.login_spec.method if lw.init_result else 'N/A'}")
assert lw.init_ok, "init 应在后台线程成功"
# 标签联动按服务端下发的登录方式断言（本地服务端配置可能变化，不能写死 password）
method = lw.init_result.login_spec.method if lw.init_result else ""
expect = {"password": ("用户名", "密码"),
          "username_code": ("用户名", "激活码"),
          "code": ("卡密", "")}.get(method)
assert expect, f"未知登录方式: {method!r}"
assert (lw.user_label, lw.secret_label) == expect, f"{method} 方式标签联动错误"
print("HEADLESS_UI_OK")
