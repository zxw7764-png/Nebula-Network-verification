# -*- coding: utf-8 -*-
"""
Nebula UI · 登录窗口
------------------------------------------------------------------------------
无边框 440×420 自绘窗口，后台线程 init（不卡 UI、无「透明窗口」问题）
  · init 下发登录方式 → 输入框标签联动（password / username_code / code）
  · 启动流水线（后台线程依次执行）：
    自身完整性校验 → 维护提示（状态栏）→ 自动更新（强制更新自动装，
    失败进「重试更新」模式）→ 版本提示 → 弹窗/立即公告
  · 登录异步提交（后台线程），结果轮询 + 完整错误码翻译表
  · 凭证持久化 credentials.ini（登录成功保存，下次启动回填）
  · 输入交互：Ctrl+V 过滤控制字符 64 上限 / Backspace / Return / Tab / Escape
"""
from __future__ import annotations

import configparser
import os
import sys
import threading
import time

import pygame

from ..client import Client, UpdateState
from . import drawing, theme


def _set_window_icon():
    """设置窗口/任务栏图标（PyInstaller 打包后从 _MEIPASS 解包目录读取）"""
    try:
        base = getattr(sys, "_MEIPASS", os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))))
        icon = pygame.image.load(os.path.join(base, "nebula", "assets", "logo.png"))
        pygame.display.set_icon(icon)
    except Exception:
        pass  # 图标缺失不影响启动


# ── 错误码翻译表 ────────────────────────────────────────────────────────────
_ERROR_TEXT = {
    -1: "网络连接失败，请检查网络",
    -2: "数据校验失败，请检查密钥配置",
    -3: "服务器响应异常",
    1001: "参数错误",
    1002: "未登录或令牌无效",
    1003: "令牌已过期",
    1004: "权限不足",
    2001: "用户名或密码错误",
    2002: "账号已被封禁",
    2003: "账号被锁定",
    2004: "账号已过期，需激活",
    3001: "卡密不存在",
    3002: "卡密已被使用",
    3003: "卡密已作废",
    3004: "卡密已过期",
    3005: "激活码尚未绑定账号",
    3006: "激活码已绑定其他账号",
    3007: "用户名已被注册",
    4001: "设备数量已达上限",
    4002: "设备未绑定",
    4003: "缺少机器码",
    4004: "设备已被拉黑",
    4005: "设备指纹异常",
    4006: "异地登录已拦截",
    5001: "请求过于频繁，请稍后再试",
    5002: "签名校验失败",
    5003: "请求已过期",
    5004: "重复请求",
    6001: "版本过低，需强制更新",
    6002: "服务器维护中",
    9999: "服务器内部错误",
}


def translate_login_error(code: int, server_msg: str) -> str:
    """服务端消息优先，否则按错误码兜底。"""
    if server_msg:
        return server_msg
    return _ERROR_TEXT.get(code, "登录失败")


# ── 凭证持久化 ──────────────────────────────────────────────────────────────

def _ini_path() -> str:
    if getattr(sys, "frozen", False):
        # 打包成 exe：凭证放 exe 同目录（单文件运行时的临时解压目录会被清空，不可用）
        return os.path.join(os.path.dirname(os.path.abspath(sys.executable)),
                            "credentials.ini")
    return os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                        "credentials.ini")


def save_credentials(account: str, secret: str, code_only: bool) -> None:
    """保存登录凭证（读-改-写，保留 [window] 等其他节）。"""
    try:
        ini = configparser.ConfigParser()
        ini.read(_ini_path(), encoding="utf-8")
        if "credentials" not in ini:
            ini.add_section("credentials")
        ini.set("credentials", "account", account)
        ini.set("credentials", "secret", secret)
        ini.set("credentials", "code_only", "1" if code_only else "0")
        with open(_ini_path(), "w", encoding="utf-8") as f:
            ini.write(f)
    except Exception:
        pass


def load_credentials():
    try:
        ini = configparser.ConfigParser()
        ini.read(_ini_path(), encoding="utf-8")
        sec = ini["credentials"]
        return sec.get("account", ""), sec.get("secret", ""), sec.getint("code_only", 0) != 0
    except Exception:
        return "", "", False


def load_window_pos(key: str):
    """读取保存的窗口位置，返回 (x, y) 或 None（无记录/损坏）。"""
    try:
        ini = configparser.ConfigParser()
        ini.read(_ini_path(), encoding="utf-8")
        if ini.has_section("window") and ini.has_option("window", key) \
                and ini.has_option("window", key + "_y"):
            return int(ini.get("window", key)), int(ini.get("window", key + "_y"))
    except Exception:
        pass
    return None


def save_window_pos(key: str, x: int, y: int) -> None:
    """保存窗口位置（读-改-写，保留 [credentials] 等其他节）。"""
    try:
        ini = configparser.ConfigParser()
        ini.read(_ini_path(), encoding="utf-8")
        if not ini.has_section("window"):
            ini.add_section("window")
        ini.set("window", key, str(int(x)))
        ini.set("window", key + "_y", str(int(y)))
        with open(_ini_path(), "w", encoding="utf-8") as f:
            ini.write(f)
    except Exception:
        pass


def place_window(window, ini_key: str, width: int, height: int) -> None:
    """窗口定位：上次位置仍可见则恢复，否则在当前显示器工作区居中。"""
    pos = load_window_pos(ini_key)
    if pos and pos[0] > -20000 and drawing.point_on_screen(*pos):
        drawing.move_window(*pos)
    else:
        drawing.center_on_screen(width, height)


def keep_window_pos(ini_key: str) -> None:
    """关窗前保存当前位置（最小化时坐标是 -32000 哨兵值，跳过）。"""
    rect = drawing.get_window_rect()
    if rect != (0, 0, 0, 0) and rect[0] > -20000:
        save_window_pos(ini_key, rect[0], rect[1])


def _get_clipboard_text() -> str:
    """剪贴板文本（Ctrl+V 用）：Win32 直读，失败返回空。

    说明：不用 pygame.scrap——pygame 2.6.1 的 scrap 模块无 get_text 接口，
    且 Windows 下读其他程序复制的文本不可靠。ctypes 调用必须显式声明
    c_void_p 返回类型，否则 64 位下 HANDLE 被截断、GlobalLock 必然失败。"""
    try:
        import ctypes
        user32 = ctypes.windll.user32
        kernel32 = ctypes.windll.kernel32
        user32.OpenClipboard.argtypes = [ctypes.c_void_p]
        user32.OpenClipboard.restype = ctypes.c_int
        user32.CloseClipboard.restype = ctypes.c_int
        user32.IsClipboardFormatAvailable.argtypes = [ctypes.c_uint]
        user32.IsClipboardFormatAvailable.restype = ctypes.c_int
        user32.GetClipboardData.argtypes = [ctypes.c_uint]
        user32.GetClipboardData.restype = ctypes.c_void_p
        kernel32.GlobalLock.argtypes = [ctypes.c_void_p]
        kernel32.GlobalLock.restype = ctypes.c_void_p
        kernel32.GlobalUnlock.argtypes = [ctypes.c_void_p]
        kernel32.GlobalUnlock.restype = ctypes.c_int

        CF_UNICODETEXT = 13
        if not user32.IsClipboardFormatAvailable(CF_UNICODETEXT):
            return ""                       # 剪贴板里不是文本（文件/图片等）
        opened = False
        for _ in range(8):                  # 剪贴板可能被其他程序短暂占用
            if user32.OpenClipboard(None):
                opened = True
                break
            time.sleep(0.02)
        if not opened:
            return ""
        try:
            h = user32.GetClipboardData(CF_UNICODETEXT)
            if not h:
                return ""
            p = kernel32.GlobalLock(h)
            if not p:
                return ""
            try:
                return ctypes.wstring_at(p) or ""
            finally:
                kernel32.GlobalUnlock(h)
        finally:
            user32.CloseClipboard()
    except Exception:
        return ""


def _set_clipboard_text(text: str) -> bool:
    """写剪贴板文本（Ctrl+C/X 用）：Win32 直写，成功返回 True。

    与读取同样的 ctypes 铁律：HANDLE 显式 c_void_p，GlobalAlloc/GlobalLock
    声明完整 argtypes/restype，64 位下才可靠。"""
    if not text:
        return False
    try:
        import ctypes
        user32 = ctypes.windll.user32
        kernel32 = ctypes.windll.kernel32
        user32.OpenClipboard.argtypes = [ctypes.c_void_p]
        user32.OpenClipboard.restype = ctypes.c_int
        user32.EmptyClipboard.restype = ctypes.c_int
        user32.SetClipboardData.argtypes = [ctypes.c_uint, ctypes.c_void_p]
        user32.SetClipboardData.restype = ctypes.c_void_p
        user32.CloseClipboard.restype = ctypes.c_int
        kernel32.GlobalAlloc.argtypes = [ctypes.c_uint, ctypes.c_size_t]
        kernel32.GlobalAlloc.restype = ctypes.c_void_p
        kernel32.GlobalLock.argtypes = [ctypes.c_void_p]
        kernel32.GlobalLock.restype = ctypes.c_void_p
        kernel32.GlobalUnlock.argtypes = [ctypes.c_void_p]
        kernel32.GlobalUnlock.restype = ctypes.c_int

        CF_UNICODETEXT = 13
        GMEM_MOVEABLE = 0x0002
        buf = text.encode("utf-16-le") + b"\x00\x00"
        h = kernel32.GlobalAlloc(GMEM_MOVEABLE, len(buf))
        if not h:
            return False
        p = kernel32.GlobalLock(h)
        if not p:
            kernel32.GlobalFree(h)
            return False
        try:
            ctypes.memmove(p, buf, len(buf))
        finally:
            kernel32.GlobalUnlock(h)
        opened = False
        for _ in range(8):
            if user32.OpenClipboard(None):
                opened = True
                break
            time.sleep(0.02)
        if not opened:
            kernel32.GlobalFree(h)
            return False
        try:
            user32.EmptyClipboard()
            # SetClipboardData 成功后系统接管 h，不得再 GlobalFree
            return bool(user32.SetClipboardData(CF_UNICODETEXT, h))
        finally:
            user32.CloseClipboard()
    except Exception:
        return False


class LoginWindow:
    """登录窗口（阻塞 Run，返回是否登录成功）。"""

    def __init__(self):
        self.client: Client | None = None
        self.login_result = None       # 登录成功后的 LoginResult
        self.username_input = ""       # 第一个框（用户名 或 卡密）
        self.password_input = ""       # 第二个框（密码 或 激活码）

        self._reset_state()

    def _reset_state(self):
        self.finished = False
        self.result = False
        self.user_focused = True
        self.pass_focused = False
        self.hover_login = False
        self.hover_cancel = False
        self.hover_close = False
        self.hover_min = False
        self.status = ""
        self.status_error = False
        self._lock = threading.Lock()

        self.user_label = "用户名"
        self.secret_label = "密码"
        self.code_only = False

        self.notice_text = ""
        self.init_ok = False
        self.init_result = None

        # 启动流水线状态：强制更新失败 → 重试模式；
        # 更新已就绪 / 自校验失败 / 强制更新拦截 → 关窗退出进程
        self.update_retry_mode = False
        self.update_applied = False
        self.integrity_failed = False
        self.version_blocked = False

        self._init_busy = False
        self._init_done = False
        self._init_thread: threading.Thread | None = None
        self._login_busy = False
        self._login_done = False
        self._login_thread: threading.Thread | None = None

    # ── 状态（GIL 下赋值原子）───────────────────────────────────────────────
    def _set_status(self, text: str, error: bool):
        self.status = text
        self.status_error = error

    # ── 主入口 ───────────────────────────────────────────────────────────────
    def Run(self) -> bool:
        # 高 DPI 感知：必须在 pygame.init() 之前调用，
        # 否则 Windows 会按 DPI 比例整窗拉伸（窗口过大）且坐标错乱
        drawing.enable_windows_dpi_awareness()
        pygame.init()
        # 开启按键重复：长按退格/字符键持续触发 KEYDOWN（pygame 默认不重复，
        # 导致输入框删除内容只能一个一个按）
        pygame.key.set_repeat(450, 35)
        pygame.display.set_caption("Nebula Login")
        # 图标必须在 set_mode 之前设置：否则窗口先以 pygame 默认图标（蛇 logo）
        # 创建，任务栏会先闪一下默认图标再变成我们的 logo
        _set_window_icon()
        window = pygame.display.set_mode((theme.kLoginWidth, theme.kLoginHeight), pygame.NOFRAME)
        place_window(window, "login", theme.kLoginWidth, theme.kLoginHeight)

        self._reset_state()

        # 加载上次保存的凭证
        acc, sec, code_only_local = load_credentials()
        if acc:
            self.username_input = acc
            self.password_input = sec
            self.code_only = code_only_local
            self.user_label = "卡密" if code_only_local else "用户名"
            self.secret_label = "" if code_only_local else "密码"
            if not code_only_local and sec:
                self.pass_focused = True

        # SDK 客户端：主线程构造（纯本地），init 放后台线程
        machine_id = self._stable_machine_id()
        self.client = Client(machine_id=machine_id, os_info="Windows", client_ver="1.0.3")

        self._set_status("正在连接服务器...", False)
        self._init_busy = True
        self._init_thread = threading.Thread(target=self._init_worker, daemon=True)
        self._init_thread.start()

        clock = pygame.time.Clock()
        while not self.finished:
            for event in pygame.event.get():
                self._handle_event(event, window)

            self._poll_init_result()
            self._poll_login_result()
            self._render(window)
            pygame.display.flip()
            clock.tick(30)

        # 清理：等后台线程结束
        if self._init_thread:
            self._init_thread.join(timeout=5)
        if self._login_thread:
            self._login_thread.join(timeout=5)
        keep_window_pos("login")     # 记住窗口位置（含更新/校验等所有退出路径）
        pygame.display.quit()
        return self.result

    @staticmethod
    def _stable_machine_id() -> str:
        from ..client import get_stable_machine_id
        return get_stable_machine_id()

    # ── init 后台线程（启动流水线：自校验 → 维护 → 更新 → 版本 → 公告）───────
    def _init_worker(self):
        ir = self.client.init()
        self.init_result = ir
        if not ir.ok:
            self._set_status("初始化失败，请检查网络连接", True)
            self._finish_init()
            return

        # ① 自身完整性自校验：服务端登记了 hash/size 才校验；
        #    不一致弹窗提示，用户确认后关窗退出（防篡改）
        if not self.client.enforce_self_integrity():
            self.integrity_failed = True
            self._finish_init()
            return

        # ② 维护模式：状态栏文字提示（登录请求仍会被服务端以 6002 拒绝）
        if ir.maintain:
            self._set_status("服务器维护中" + (f"：{ir.maintain_msg}" if ir.maintain_msg else ""),
                             True)
        else:
            self._set_status("正在检查更新...", False)

        # ③ 自动更新：强制更新 → 自动下载、替换并重启（进程退出）；
        #    更新失败且属强制更新 → 进入「重试更新」模式（登录按钮变为重试）
        up = self.client.auto_update()
        if up.state == UpdateState.Applied:
            self.update_applied = True      # 替换脚本已启动，进程即将退出并重启
            self._finish_init()
            return
        if up.state == UpdateState.Failed and ir.version.force_update:
            self.update_retry_mode = True
            self._set_status(f"自动更新失败：{up.msg}（点击「重试更新」重试）", True)
            self._finish_init()
            return

        # ④ 版本提示：可选更新提醒；强制更新兜底拦截（返回 False 中止）
        if not self.client.version_alert():
            self.version_blocked = True
            self._finish_init()
            return

        # ⑤ 公告：弹窗公告（type=2，每次登录提示）
        #    + 立即公告（type=3，看过即不再显示，已读记录存本地）
        #    一次拉取共享给 popup/flash，避免重复网络请求
        try:
            notices = self.client.get_notices()
        except Exception:
            notices = []
        for step in (self.client.popup_notices, self.client.flash_notices):
            try:
                step(notices)
            except Exception:
                pass

        self._set_status("", False)
        self.init_ok = True
        self._finish_init()

    def _finish_init(self):
        self._init_busy = False
        self._init_done = True

    # ── init 结果轮询（登录方式联动）────────────────────────────────────────
    def _poll_init_result(self):
        if not self._init_done:
            return
        self._init_done = False

        # 更新已就绪（进程即将退出重启）/ 自校验失败 / 强制更新拦截 → 关窗退出
        if self.update_applied or self.integrity_failed or self.version_blocked:
            self.finished = True
            return

        if not self.init_ok or not self.init_result:
            return

        # 登录方式 → 输入框标签、必填项与内容清理（三种方式互斥）
        ir = self.init_result
        method = ir.login_spec.method
        old_secret_label = self.secret_label
        old_code_only = self.code_only

        if method == "code":
            if not old_code_only:
                self.username_input = ""
                self.password_input = ""
                self.user_focused = True
                self.pass_focused = False
            self.user_label = "卡密"
            self.secret_label = ""
            self.code_only = True
        elif method == "username_code":
            if old_code_only:
                self.username_input = ""
                self.password_input = ""
            elif old_secret_label != "激活码":
                self.password_input = ""
            self.user_label = "用户名"
            self.secret_label = "激活码"
            self.code_only = False
        else:  # password
            if old_code_only:
                self.username_input = ""
                self.password_input = ""
            elif old_secret_label != "密码":
                self.password_input = ""
            self.user_label = "用户名"
            self.secret_label = "密码"
            self.code_only = False

        # 公告（init 已解析 notice_text）
        if ir.notice_text:
            self.notice_text = ir.notice_text

    # ── 登录结果轮询 ─────────────────────────────────────────────────────────
    def _poll_login_result(self):
        if not self._login_done:
            return
        self._login_done = False
        if self._login_thread:
            self._login_thread.join(timeout=2)
            self._login_thread = None
        self._login_busy = False

        lr = self.login_result
        if lr and lr.ok:
            self.result = True
            self.finished = True
            self._set_status("登录成功！", False)
            save_credentials(self.username_input,
                             "" if self.code_only else self.password_input,
                             self.code_only)
            return

        code = lr.code if lr else -1
        msg = lr.msg if lr else ""
        self._set_status(translate_login_error(code, msg), True)

    # ── 事件处理 ─────────────────────────────────────────────────────────────
    def _handle_event(self, event, window):
        if event.type == pygame.QUIT:
            self._cancel()
        elif event.type == pygame.MOUSEMOTION:
            mx, my = pygame.mouse.get_pos()
            w = window.get_width()
            self.hover_close = drawing.close_btn_rect(w).collidepoint((mx, my))
            self.hover_min = drawing.min_btn_rect(w).collidepoint((mx, my))
            self.hover_login = theme.LOGIN_BTN_RECT.collidepoint((mx, my))
            self.hover_cancel = theme.CANCEL_BTN_RECT.collidepoint((mx, my))
        elif event.type == pygame.MOUSEBUTTONDOWN and event.button == 1:
            self._handle_click(pygame.mouse.get_pos(), window)
        elif event.type == pygame.KEYDOWN:
            self._handle_keydown(event)

    def _handle_click(self, mouse, window):
        w = window.get_width()
        if drawing.close_btn_rect(w).collidepoint(mouse):
            self._cancel()
        elif drawing.min_btn_rect(w).collidepoint(mouse):
            drawing.minimize_window()
        elif mouse[1] < theme.kTitleBarHeight:
            # 无边框拖拽：交给系统处理（Windows 接管直到鼠标松开）
            drawing.begin_system_drag()
        elif theme.USER_INPUT_RECT.collidepoint(mouse):
            self.user_focused = True
            self.pass_focused = False
        elif theme.PASS_INPUT_RECT.collidepoint(mouse):
            if not self.code_only:      # 卡密直登时第二个框不显示，不响应点击
                self.pass_focused = True
                self.user_focused = False
        elif theme.LOGIN_BTN_RECT.collidepoint(mouse):
            self._submit()
        elif theme.CANCEL_BTN_RECT.collidepoint(mouse):
            self._cancel()

    def _handle_keydown(self, event):
        mods = pygame.key.get_mods()
        ctrl = mods & (pygame.KMOD_LCTRL | pygame.KMOD_RCTRL)

        if ctrl and event.key == pygame.K_v:
            # Ctrl+V：剪贴板 → 当前聚焦输入框（过滤控制字符，64 上限）
            text = _get_clipboard_text()
            if not text:
                return
            clean = "".join(c for c in text if ord(c) >= 32)
            target = self.username_input if (self.code_only or self.user_focused) else self.password_input
            if len(target) >= 64:
                return
            clean = clean[:64 - len(target)]
            target += clean
            if self.code_only or self.user_focused:
                self.username_input = target
            else:
                self.password_input = target
            self._set_status("", False)
            return

        if event.key == pygame.K_BACKSPACE:
            target = self.username_input if (self.code_only or self.user_focused) else self.password_input
            target = target[:-1]
            if self.code_only or self.user_focused:
                self.username_input = target
            else:
                self.password_input = target
            return

        if ctrl and event.key == pygame.K_c:
            # Ctrl+C：复制当前聚焦输入框内容
            text = self.username_input if (self.code_only or self.user_focused) else self.password_input
            if text:
                _set_clipboard_text(text)
            return

        if ctrl and event.key == pygame.K_x:
            # Ctrl+X：剪切当前聚焦输入框内容
            text = self.username_input if (self.code_only or self.user_focused) else self.password_input
            if text:
                _set_clipboard_text(text)
                if self.code_only or self.user_focused:
                    self.username_input = ""
                else:
                    self.password_input = ""
                self._set_status("", False)
            return

        if event.key in (pygame.K_RETURN, pygame.K_KP_ENTER):
            # 回车（含小键盘）提交登录
            self._submit()
            return

        if event.key == pygame.K_TAB:
            if not self.code_only:
                self.user_focused, self.pass_focused = self.pass_focused, self.user_focused
            return

        if event.key == pygame.K_ESCAPE:
            self._cancel()
            return

        # 普通字符输入（过滤控制字符；Ctrl 组合键不当作文本）
        if ctrl or not event.unicode:
            return
        cp = ord(event.unicode[0]) if event.unicode else 0
        if cp < 32:
            return
        target = self.username_input if (self.code_only or self.user_focused) else self.password_input
        if len(target) >= 64:
            return
        target += event.unicode
        if self.code_only or self.user_focused:
            self.username_input = target
        else:
            self.password_input = target
        self._set_status("", False)

    # ── 提交 ─────────────────────────────────────────────────────────────────
    def _submit(self):
        if self._login_busy:        # 登录进行中，忽略重复提交
            return
        if self._init_busy:         # init 尚未完成，不允许登录
            self._set_status("正在初始化，请稍候...", False)
            return
        if self.update_retry_mode:  # 强制更新失败 → 按钮即「重试更新」
            self._retry_update()
            return
        if not self.client:
            self._set_status("初始化失败，请重启程序", True)
            return
        if not self.init_ok:        # init 已结束但失败（网络/校验）
            self._set_status("初始化失败，请检查网络后重启程序", True)
            return

        # 按 init 下发的登录方式校验必填项
        secret = self.username_input if self.code_only else self.password_input
        if not self.username_input:
            self._set_status("卡密不能为空" if self.code_only else "用户名不能为空", True)
            return
        if not self.code_only and not secret:
            self._set_status(f"{self.secret_label}不能为空", True)
            return

        self._set_status("正在登录...", False)
        self._login_busy = True
        self._login_done = False

        account, credential = self.username_input, secret
        if self._login_thread:
            self._login_thread.join(timeout=2)
        self._login_thread = threading.Thread(
            target=self._login_worker, args=(account, credential), daemon=True)
        self._login_thread.start()

    def _retry_update(self):
        """更新失败 → 重新 init 并重试更新（重新走一遍启动流水线）。"""
        if self._init_busy:
            return
        self.update_retry_mode = False
        self._set_status("正在重试更新...", False)
        self._init_busy = True
        self._init_done = False
        if self._init_thread:
            self._init_thread.join(timeout=2)
        self._init_thread = threading.Thread(target=self._init_worker, daemon=True)
        self._init_thread.start()

    def _login_worker(self, account: str, credential: str):
        self.login_result = self.client.login(account, credential)
        self._login_done = True

    def _cancel(self):
        self.finished = True
        self.result = False

    # ── 渲染 ─────────────────────────────────────────────────────────────────
    def _render(self, window):
        drawing.draw_background(window)

        # 公告框（外框 8px 圆角 kInputBorder + 内框 7px kNoticeBg + 文本）
        drawing.rounded_rect(window, theme.NOTICE_RECT, 8, theme.kInputBorder)
        drawing.rounded_rect(window, theme.NOTICE_RECT.inflate(-2, -2), 7, theme.kNoticeBg)
        if self.notice_text:
            margin = 12
            for i, line in enumerate(self.notice_text.split("\n")[:3]):
                drawing.draw_text(window, line, theme.NOTICE_RECT.left + margin,
                                  theme.NOTICE_RECT.top + margin + i * 16, 13, theme.kMuted)

        # 输入框标签
        drawing.draw_text(window, self.user_label, theme.USER_INPUT_RECT.left,
                          theme.USER_INPUT_RECT.top + theme.LABEL_DY, 14, theme.kMuted)
        if not self.code_only:
            drawing.draw_text(window, self.secret_label, theme.PASS_INPUT_RECT.left,
                              theme.PASS_INPUT_RECT.top + theme.LABEL_DY, 14, theme.kMuted)

        # 光标 500ms 闪烁
        cursor_visible = (pygame.time.get_ticks() // 500) % 2 == 0

        self._draw_input_box(window, theme.USER_INPUT_RECT, self.username_input,
                             self.user_focused, cursor_visible)

        # 密码显示为圆点 ●（卡密直登时隐藏第二个框）
        if not self.code_only:
            mask = "\u25cf" * len(self.password_input)
            self._draw_input_box(window, theme.PASS_INPUT_RECT, mask,
                                 self.pass_focused, cursor_visible)

        # 状态提示
        status_color = theme.kError if self.status_error else theme.kAccent
        drawing.draw_text(window, self.status, theme.STATUS_POS[0], theme.STATUS_POS[1], 13, status_color)

        # 按钮（init 未完成时置灰；更新重试模式下主按钮变「重试更新」）
        btn_ready = (not self._init_busy) and (self.init_ok or self.update_retry_mode)
        login_color = (theme.kAccentBright if self.hover_login else theme.kAccent) if btn_ready else theme.kButtonIdle
        drawing.rounded_rect(window, theme.LOGIN_BTN_RECT, 8, login_color)
        drawing.draw_centered_text(window, "重试更新" if self.update_retry_mode else "登 录",
                                   theme.LOGIN_BTN_RECT, 15, theme.kText, True)

        drawing.rounded_rect(window, theme.CANCEL_BTN_RECT, 8,
                             theme.kButtonHover if self.hover_cancel else theme.kButtonIdle)
        drawing.draw_centered_text(window, "取 消", theme.CANCEL_BTN_RECT, 15, theme.kText, True)

        # 自绘标题栏（最后绘制，置于最上层）
        drawing.draw_title_bar(window, "Nebula Login", self.hover_min, self.hover_close)

    @staticmethod
    def _draw_input_box(window, rect: pygame.Rect, text: str,
                        focused: bool, cursor_visible: bool):
        """输入框：边框/内框/文本垂直居中/闪烁光标。"""
        border = theme.kAccent if focused else theme.kInputBorder
        drawing.rounded_rect(window, rect, 8, border)
        drawing.rounded_rect(window, rect.inflate(-2, -2), 7, theme.kInputBg)

        # 文本（左侧 12px 内边距，垂直居中）
        t = drawing.font(15).render(text, True, theme.kText)
        window.blit(t, (rect.left + 12, rect.centery - t.get_height() // 2))

        # 闪烁光标（1.5 × 高-16，y=rect.top+8，跟随文本宽度）
        if focused and cursor_visible:
            cursor_x = rect.left + 14 + t.get_width()
            pygame.draw.rect(window, theme.kAccent,
                             pygame.Rect(cursor_x, rect.top + 8, 2, rect.height - 16))
