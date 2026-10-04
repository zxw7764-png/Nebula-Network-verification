# -*- coding: utf-8 -*-
"""
Nebula UI · 主界面窗口
------------------------------------------------------------------------------
  · 800×500 无边框窗口，登录成功后接管 SDK 会话（start_heartbeat 心跳保活）
  · 收到 kick / force_offline / need_relogin：显示原因，停留 1.5 秒回登录界面
  · 用户主动关闭：stop_heartbeat + logout 正常退出
  · 返回 EXIT_NORMAL（正常）/ EXIT_KICKED（回登录界面重新 init + login）
"""
from __future__ import annotations

import threading

import pygame

from . import drawing, theme
from .login_window import place_window, keep_window_pos
from ..client import kick_text

EXIT_NORMAL = 0    # 用户主动关闭（已 stop_heartbeat + logout）
EXIT_KICKED = 1    # 被踢下线 / 需重新登录（回到登录界面）


class MainWindow:

    def __init__(self):
        self.client = None
        self.login = None
        self.username = ""

        self.finished = False
        self.hover_close = False

        # 心跳线程 → UI 线程 的踢下线通知
        self._kick_lock = threading.Lock()
        self._kick_msg = ""
        self.kicked = False
        self._kick_noted = False
        self._kick_start_ms = 0          # 收到踢下线后停留 1.5 秒再切回登录

    def Run(self, username: str, client, login) -> int:
        self.username = username
        self.client = client
        self.login = login
        self.finished = False
        self.hover_close = False
        self.kicked = False
        self._kick_noted = False
        with self._kick_lock:
            self._kick_msg = ""

        if not self.client or not self.login or not self.login.token:
            return EXIT_KICKED            # 无有效会话，回登录界面

        # 高 DPI 感知（幂等）：必须在 pygame.init() 之前，防止整窗被 DPI 拉伸
        drawing.enable_windows_dpi_awareness()
        pygame.init()
        pygame.display.set_caption("Nebula - 主界面")
        window = pygame.display.set_mode((theme.kMainWidth, theme.kMainHeight), pygame.NOFRAME)
        # 上次位置仍可见则恢复，否则在当前显示器工作区居中
        place_window(window, "main", theme.kMainWidth, theme.kMainHeight)

        # 心跳保活：interval=0 → 使用 init 下发的 heartbeat_interval（默认 60 秒）。
        # 回调在 SDK 心跳线程执行，禁止触碰 pygame，只设置标志位。
        self.client.start_heartbeat(self.login.token, self._on_heartbeat, interval=0)

        clock = pygame.time.Clock()
        while not self.finished:
            for event in pygame.event.get():
                self._handle_event(event, window)

            # 被踢下线：停留 1.5 秒展示原因，然后回登录界面
            if self.kicked:
                if not self._kick_noted:
                    self._kick_noted = True
                    self._kick_start_ms = pygame.time.get_ticks()
                elif pygame.time.get_ticks() - self._kick_start_ms >= 1500:
                    self.finished = True

            self._render(window)
            pygame.display.flip()
            clock.tick(30)

        # 收尾：无论哪种退出都先停心跳
        self.client.stop_heartbeat()
        keep_window_pos("main")      # 记住窗口位置
        pygame.display.quit()

        if self.kicked:
            return EXIT_KICKED            # 旧 token 已失效，交给 main 回登录界面

        self.client.logout(self.login.token)   # 用户主动退出：登出（服务端销毁会话）
        return EXIT_NORMAL

    def _on_heartbeat(self, hb):
        """SDK 心跳线程回调（禁止触碰 UI）。"""
        if hb.kick or hb.need_relogin or hb.force_offline:
            # 下线原因：服务端 msg 优先，为空按业务码兜底
            text = kick_text(hb.code, hb.msg)
            with self._kick_lock:
                if not self._kick_msg:    # 保留第一条原因
                    self._kick_msg = text
            self.kicked = True

    def _handle_event(self, event, window):
        if event.type == pygame.QUIT:
            self.finished = True
        elif event.type == pygame.MOUSEMOTION:
            w = window.get_width()
            self.hover_close = drawing.close_btn_rect(w).collidepoint(pygame.mouse.get_pos())
        elif event.type == pygame.MOUSEBUTTONDOWN and event.button == 1:
            m = pygame.mouse.get_pos()
            w = window.get_width()
            if drawing.close_btn_rect(w).collidepoint(m):
                self.finished = True
            elif m[1] < theme.kTitleBarHeight:
                # 无边框拖拽：交给系统处理（Windows 接管直到鼠标松开：
                # ReleaseCapture + WM_NCLBUTTONDOWN/HTCAPTION）
                drawing.begin_system_drag()

    def _render(self, window):
        drawing.draw_background(window)

        # 欢迎文字
        drawing.draw_centered_text(window, f"欢迎，{self.username}！",
                                   pygame.Rect(0, 120, theme.kMainWidth, 50), 24, theme.kText, True)

        # 用户信息（来自 login 响应）
        user = self.login.user
        vip_text = user.vip_text or ("永久" if user.vip_expire == -1 else "未激活")
        drawing.draw_centered_text(window, f"会员到期：{vip_text}",
                                   pygame.Rect(0, 185, theme.kMainWidth, 35), 16, theme.kMuted)
        drawing.draw_centered_text(window, f"积分：{user.points}    设备上限：{user.max_devices}",
                                   pygame.Rect(0, 222, theme.kMainWidth, 35), 16, theme.kMuted)

        # 被踢下线提示（覆盖在功能区上方）
        if self.kicked:
            with self._kick_lock:
                kick = self._kick_msg
            if not kick:
                kick = "登录状态已失效"
            drawing.draw_centered_text(window, kick,
                                       pygame.Rect(0, 280, theme.kMainWidth, 40), 18, theme.kError, True)
            drawing.draw_centered_text(window, "正在返回登录界面...",
                                       pygame.Rect(0, 322, theme.kMainWidth, 35), 14, theme.kMuted)
        else:
            # 功能区域提示
            drawing.draw_centered_text(window, "[ 主程序功能区 ]",
                                       pygame.Rect(0, 300, theme.kMainWidth, 40), 18, theme.kAccent)

        # 标题栏（最后绘制）
        drawing.draw_title_bar(window, "Nebula - 主界面", False, self.hover_close)
