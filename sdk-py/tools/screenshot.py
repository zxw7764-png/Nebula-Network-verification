# -*- coding: utf-8 -*-
"""离屏渲染生成 UI 截图（不开真实窗口）。"""
import sys
sys.path.insert(0, r"D:/phpstudy_pro/WWW/login/nebula-py")

import pygame
pygame.init()

from nebula.ui import theme
from nebula.ui.login_window import LoginWindow
from nebula.ui.main_window import MainWindow
from nebula.ui.drawing import draw_background, draw_title_bar, draw_centered_text, rounded_rect
from nebula.client import LoginResult, UserInfo

# ── 登录窗截图（模拟 init 完成、已输入状态）─────────────────────────────────
lw = LoginWindow()
surf = pygame.Surface((theme.kLoginWidth, theme.kLoginHeight))
lw.user_label, lw.secret_label = "用户名", "密码"
lw.username_input = "pytest_sdk"
lw.password_input = "pytest123"
lw.notice_text = "欢迎公告：欢迎使用 Nebula 验证系统\n本站已接入设备绑定与心跳保活"
lw.init_ok = True
lw.status = "初始化完成，请登录"
lw.status_error = False
lw.hover_login = True          # 展示登录按钮 hover 态(kAccentBright)
lw._render(surf)
pygame.image.save(surf, r"D:/phpstudy_pro/WWW/login/nebula-py/_shot_login.png")

# ── 登录窗截图 2：卡密直登模式 + init 未完成(按钮置灰)───────────────────────
surf2 = pygame.Surface((theme.kLoginWidth, theme.kLoginHeight))
lw2 = LoginWindow()
lw2.code_only = True
lw2.user_label, lw2.secret_label = "卡密", ""
lw2.username_input = "NEB-XXXX-XXXX-XXXX"
lw2.notice_text = ""
lw2.init_ok = False
lw2.status = "正在连接服务器..."
lw2.status_error = False
lw2._render(surf2)
pygame.image.save(surf2, r"D:/phpstudy_pro/WWW/login/nebula-py/_shot_login_code.png")

# ── 主窗截图 ─────────────────────────────────────────────────────────────────
mw = MainWindow()
msurf = pygame.Surface((theme.kMainWidth, theme.kMainHeight))
mw.username = "pytest_sdk"
mw.login = LoginResult(
    ok=True, token="x" * 32,
    user=UserInfo(user_id=20, username="pytest_sdk", vip_expire=-1,
                  vip_text="永久", points=100, max_devices=3),
)
mw._render(msurf)
pygame.image.save(msurf, r"D:/phpstudy_pro/WWW/login/nebula-py/_shot_main.png")

# ── 主窗截图 2：被踢下线态 ───────────────────────────────────────────────────
mw2 = MainWindow()
msurf2 = pygame.Surface((theme.kMainWidth, theme.kMainHeight))
mw2.username = "pytest_sdk"
mw2.login = mw.login
mw2.kicked = True
mw2._kick_noted = True
mw2._kick_msg = "您的账号已在其他设备登录"
mw2._render(msurf2)
pygame.image.save(msurf2, r"D:/phpstudy_pro/WWW/login/nebula-py/_shot_main_kicked.png")

# ── 登录窗截图 3：更新重试模式（主按钮变「重试更新」）───────────────────────
surf3 = pygame.Surface((theme.kLoginWidth, theme.kLoginHeight))
lw3 = LoginWindow()
lw3.user_label, lw3.secret_label = "用户名", "密码"
lw3.username_input = "pytest_sdk"
lw3.notice_text = "欢迎公告：欢迎使用 Nebula 验证系统"
lw3.init_ok = False
lw3.update_retry_mode = True
lw3.status = "自动更新失败：下载失败（点击「重试更新」重试）"
lw3.status_error = True
lw3.hover_login = True
lw3._render(surf3)
pygame.image.save(surf3, r"D:/phpstudy_pro/WWW/login/nebula-py/_shot_login_retry.png")

print("SCREENSHOTS_OK")
