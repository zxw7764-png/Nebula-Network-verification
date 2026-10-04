# -*- coding: utf-8 -*-
"""
NEBULA LOGIN (Python) · 主入口
------------------------------------------------------------------------------
  1. 登录窗口：init + login（内部后台线程，不卡界面）
  2. 登录成功：接管 SDK 会话（心跳 / 登出由主界面负责）
  3. 主界面：正常退出 EXIT_NORMAL；被踢下线 EXIT_KICKED → 回到登录界面重走 init + login

运行：python main.py
"""
from __future__ import annotations

import sys

sys.dont_write_bytecode = True

from nebula.ui import LoginWindow, MainWindow, EXIT_NORMAL, EXIT_KICKED


def main() -> int:
    try:
        while True:
            # 1. 登录窗口：init + login
            login_window = LoginWindow()
            if not login_window.Run():
                return 1

            # 2. 登录成功：接管 SDK 会话
            client = login_window.client
            if not client:
                return 1

            # 3. 主界面：EXIT_KICKED → 回到登录界面重新走 init + login
            #    显示名优先用服务端返回的用户名（卡密直登时输入框里是卡密，不是用户名）
            lr = login_window.login_result
            display_name = lr.user.username if (lr and lr.user.username) else login_window.username_input

            main_window = MainWindow()
            exit_code = main_window.Run(display_name, client, lr)

            if exit_code == EXIT_NORMAL:
                return 0
            # EXIT_KICKED：继续 for 循环回登录界面
    except KeyboardInterrupt:
        return 130
    except Exception as e:
        try:
            import ctypes
            ctypes.windll.user32.MessageBoxW(0, f"程序异常退出：\n{e}", "Nebula", 0x10)
        except Exception:
            print(f"程序异常退出：{e}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
