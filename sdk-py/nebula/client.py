# -*- coding: utf-8 -*-
"""
Nebula SDK · Client（流程与业务接口）
------------------------------------------------------------------------------
init / login / heartbeat / logout，
登录成功响应中的 feature_key 自动透传（lr.feature_key）。

机器码：Windows MachineGuid 的 sha256 前 8 字节 hex（重装系统才变化，
保证设备绑定稳定）。

内置能力：
  · 自身完整性自校验（enforce_self_integrity：服务端登记 hash/size 才校验）
  · 自动更新（auto_update：检测 → 下载 → hash/size 校验 → cmd 替换脚本 → 重启）
  · 弹窗公告（popup_notices，type=2 每次提示）
  · 立即公告（flash_notices，type=3 看过即不再显示，已读记录存本地）
  · 版本 / 维护 / 下线提示（version_alert / maintain_alert / kick_alert）
  · 内置提示默认用系统弹窗，set_ui_handler 可替换为接入方自定义 UI 回调
"""
from __future__ import annotations

import enum
import hashlib
import os
import platform
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
from dataclasses import dataclass, field
from typing import Callable, Dict, List, Optional

from . import config
from .crypto import NebulaError, random_hex
from .device_fp import collect_fingerprint_json
from .envelope import Envelope


# ── 结果类型 ------------------------------------------------------------------

@dataclass
class UserInfo:
    user_id: int = 0
    username: str = ""
    nickname: str = ""
    vip_expire: int = 0
    vip_text: str = ""
    points: int = 0
    max_devices: int = 0
    group_id: int = 0
    status: int = 1


@dataclass
class LoginSpec:
    """init 下发的登录方式规格。"""
    method: str = "password"       # password / username_code / code
    label: str = "用户名 + 密码"
    need_username: bool = True
    need_password: bool = True
    need_code: bool = False
    fields: List[str] = field(default_factory=lambda: ["username", "password"])


@dataclass
class VersionInfo:
    client_ver: str = ""
    latest: str = ""
    min_ver: str = ""
    need_update: bool = False
    force_update: bool = False
    update_url: str = ""
    changelog: str = ""
    file_hash: str = ""            # 更新包哈希（64=SHA256 / 32=MD5，自动校验用）
    file_size: int = 0             # 更新包字节数
    self_file_hash: str = ""       # 自身完整性哈希（仅最新版下发）
    self_file_size: int = 0


@dataclass
class InitResult:
    ok: bool = False
    code: int = -1
    msg: str = ""
    site_name: str = ""
    software_name: str = ""
    heartbeat_interval: int = 60
    maintain: bool = False
    maintain_msg: str = ""
    register_enable: bool = True
    login_spec: LoginSpec = field(default_factory=LoginSpec)
    notices: List[Dict] = field(default_factory=list)
    notice_text: str = ""          # 标题：内容 多行文本
    version: VersionInfo = field(default_factory=VersionInfo)
    grace_public_key: str = ""
    grace_enable: bool = False


@dataclass
class LoginResult:
    ok: bool = False
    code: int = -1
    msg: str = ""
    token: str = ""
    expire_at: int = 0
    ttl: int = 0
    login_method: str = ""
    feature_key: str = ""          # 功能密钥（仅登录成功后下发，空 = 未启用）
    account_created: bool = False
    user: UserInfo = field(default_factory=UserInfo)
    vip_valid: bool = False
    vip_msg: str = ""
    auto_bound: bool = False
    bound_count: int = 0


@dataclass
class HeartbeatInfo:
    online: bool = True
    code: int = 0
    msg: str = ""
    remain: int = 0
    remain_text: str = ""
    vip_expire: int = 0
    kick: bool = False             # 后台踢出（status=3）
    force_offline: bool = False    # 顶号下线（status=2）
    need_relogin: bool = False     # 会话失效
    need_activate: bool = False    # 过期待激活
    next_interval: int = 60


# ── 机器码 --------------------------------------------------------------------

def get_stable_machine_id() -> str:
    """Windows MachineGuid 摘要：sha256 前 8 字节 hex。"""
    try:
        import winreg
        with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"SOFTWARE\Microsoft\Cryptography") as k:
            guid, _ = winreg.QueryValueEx(k, "MachineGuid")
            if guid:
                return hashlib.sha256(str(guid).encode("utf-8")).hexdigest()[:16]
    except Exception:
        pass
    # 非 Windows / 取不到：用 MAC 地址派生（稳定可用）
    node = uuid_getnode()
    return hashlib.sha256(node.encode("utf-8")).hexdigest()[:16]


def uuid_getnode() -> str:
    import uuid
    return f"{platform.system()}|{uuid.getnode()}"


# ── 内置提示弹窗（可用 set_ui_handler 整体替换）───────────────────────────────

MB_ICONERROR = 0x10
MB_ICONWARNING = 0x30
MB_ICONINFORMATION = 0x40
_MB_EXTRA = 0x00010000 | 0x00040000   # MB_SETFOREGROUND | MB_TOPMOST（确保盖过无边框窗口）


def message_box(message: str, title: str, icon: int = MB_ICONINFORMATION) -> None:
    """系统弹窗（阻塞直至用户确认）；非 Windows 或调用失败时静默跳过。"""
    try:
        import ctypes
        ctypes.windll.user32.MessageBoxW(0, str(message), str(title), int(icon) | _MB_EXTRA)
    except Exception:
        pass


def kick_text(code: int, msg: str = "") -> str:
    """下线提示文案：服务端 msg 优先（可区分顶号 / 管理员强制下线 / 会话过期），
    为空时按业务码兜底：1002 会话失效 / 2004 账号过期 / 4002 设备已解绑。"""
    if msg:
        return msg
    return {
        1002: "账号已在其他设备登录",
        2004: "账号已过期，请激活后再登录",
        4002: "当前设备已被解绑",
    }.get(code, "登录状态已失效")


# ── 自身完整性自校验（防篡改）────────────────────────────────────────────────

def verify_self_integrity(self_file_hash: str = "", self_file_size: int = 0) -> str:
    """计算自身程序文件（sys.executable，打包后即 exe）的哈希与字节数，
    与服务端「版本管理」登记值比对。返回空串 = 通过；非空 = 拒绝原因（可直接展示）。

    服务端规则：仅当客户端版本号 == 最新已发布版本时才下发；未登记（哈希空且
    大小 <= 0）时跳过校验，不会误拦。⚠ 重新打包发版前必须在后台重新登记。"""
    if not self_file_hash and self_file_size <= 0:
        return ""                                   # 服务端未登记 → 跳过
    exe = sys.executable or ""
    if not exe or not os.path.exists(exe):
        return "无法定位程序文件"
    if self_file_hash:
        algo = hashlib.sha256() if len(self_file_hash) == 64 else hashlib.md5()
        try:
            with open(exe, "rb") as f:
                for chunk in iter(lambda: f.read(1 << 20), b""):
                    algo.update(chunk)
            local = algo.hexdigest()
        except OSError:
            return "无法读取程序文件，完整性校验失败"
        if local.lower() != self_file_hash.lower():
            return "程序文件已被修改，请从官方渠道重新下载"
    if self_file_size > 0:
        try:
            size = os.path.getsize(exe)
        except OSError:
            size = -1
        if size >= 0 and size != self_file_size:
            return "程序文件已被修改，请从官方渠道重新下载"
    return ""


# ── 自动更新：状态 / 结果 / 下载 / 校验 / 替换脚本 ────────────────────────────

class UpdateState(enum.IntEnum):
    """自动更新各阶段的结果状态。"""
    Disabled = 0        # 未启用（auto_update_enable = False）
    NoUpdate = 1        # 已是最新版本
    NeedConfirm = 2     # 可选更新：已提示，等用户确认后再装
    Downloaded = 3      # 仅下载完成（download_update 终态）
    Applied = 4         # 替换脚本已启动，进程即将退出并重启
    Failed = 5          # 任一步失败（见 msg）


@dataclass
class UpdateResult:
    state: UpdateState = UpdateState.NoUpdate
    msg: str = ""                  # 面向用户的说明（成功或失败原因）
    version: str = ""              # 目标版本号
    new_file: str = ""             # 下载到的临时文件路径（Downloaded / Applied 有效）
    verify_hash: str = ""          # 期望哈希（交 apply_downloaded_update 用）
    verify_size: int = 0           # 期望字节数
    force: bool = False            # 是否强制更新

    def ok(self) -> bool:
        return self.state in (UpdateState.NoUpdate, UpdateState.Downloaded, UpdateState.Applied)

    def updated(self) -> bool:
        return self.state in (UpdateState.Downloaded, UpdateState.Applied)


def _self_dir() -> str:
    """程序所在目录（打包后 = exe 目录；开发态 = 解释器目录）。"""
    return os.path.dirname(os.path.abspath(sys.executable)) if sys.executable else os.getcwd()


def _remove_quiet(path: str) -> None:
    try:
        os.remove(path)
    except OSError:
        pass


def _file_name_from_url(url: str) -> str:
    """从 URL 提取文件名（去 query/路径，过滤非法字符），取不到回落 update.bin。"""
    p = url.split("?", 1)[0].split("#", 1)[0]
    p = p.replace("\\", "/").rsplit("/", 1)[-1]
    for ch in ':*?"<>|':
        p = p.replace(ch, "_")
    if not p or p in (".", ".."):
        p = "update.bin"
    return p


def _download_to_file(url: str, dest: str) -> str:
    """下载 URL 到本地文件。成功返回空串，失败返回中文原因。"""
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "Nebula-Client"})
        with urllib.request.urlopen(req, timeout=config.kTimeoutSeconds) as resp:
            body = resp.read()
    except urllib.error.HTTPError as e:
        return f"下载失败：服务器返回 HTTP {e.code}"
    except Exception:
        return "下载失败：无法连接更新服务器"
    if not body:
        return "下载失败：更新包为空"
    try:
        with open(dest, "wb") as f:
            f.write(body)
    except OSError:
        return "下载失败：无法写入临时文件（可能没有目录写权限）"
    return ""


def _file_hash_hex(path: str, use_sha256: bool) -> str:
    algo = hashlib.sha256() if use_sha256 else hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            algo.update(chunk)
    return algo.hexdigest()


def _verify_downloaded(path: str, expect_hash: str, expect_size: int) -> str:
    """校验下载文件：哈希（64=SHA256 / 32=MD5）+ 大小，任一不符即失败。
    哈希为空视为不可信 → 直接失败（不给中间人投毒通道）。成功返回空串。"""
    if not expect_hash:
        return "更新包未通过校验：服务端未登记文件哈希，已拒绝安装"
    try:
        local = _file_hash_hex(path, len(expect_hash) == 64)
    except OSError:
        return "更新包校验失败：无法读取下载的文件"
    if local.lower() != expect_hash.lower():
        return "更新包校验失败：文件哈希与服务器登记值不一致（可能被篡改或下载不完整）"
    if expect_size > 0:
        try:
            size = os.path.getsize(path)
        except OSError:
            size = -1
        if size >= 0 and size != expect_size:
            return "更新包校验失败：文件大小与服务器登记值不一致"
    return ""


def _spawn_replacer(new_file: str, exe_path: str) -> str:
    """生成并启动替换脚本（cmd）。成功返回空串，失败返回原因。

    脚本逻辑（等本进程退出后执行）：
      :wait   tasklist 轮询本 PID，最多等 ~60 秒
      :swap   move /Y 新文件 → exe，start 启动新版，del 脚本自身
    """
    pid = os.getpid()
    new_bs = new_file.replace("/", "\\")
    dst_bs = exe_path.replace("/", "\\")
    script = (
        "@echo off\r\n"
        "setlocal enableextensions\r\n"
        f"set \"NEW={new_bs}\"\r\n"
        f"set \"DST={dst_bs}\"\r\n"
        f"set \"PID={pid}\"\r\n"
        "set /a N=0\r\n"
        ":wait\r\n"
        "set /a N+=1\r\n"
        "if %N% GTR 60 goto :swap\r\n"
        "tasklist /FI \"PID eq %PID%\" 2>NUL | find /I \"%PID%\" >NUL\r\n"
        "if not errorlevel 1 (\r\n"
        "  ping -n 2 127.0.0.1 >NUL\r\n"
        "  goto :wait\r\n"
        ")\r\n"
        ":swap\r\n"
        "move /Y \"%NEW%\" \"%DST%\" >NUL 2>&1\r\n"
        "if errorlevel 1 goto :fail\r\n"
        "start \"\" \"%DST%\"\r\n"
        "goto :cleanup\r\n"
        ":fail\r\n"
        "echo update failed: cannot replace exe, new file kept at %NEW%\r\n"
        ":cleanup\r\n"
        "del \"%~f0\" >NUL 2>&1\r\n"
        "exit /b\r\n"
    )
    script_path = os.path.join(_self_dir(), f"nebula_upd_{random_hex(6)}.bat")
    try:
        # mbcs = 系统 ANSI 代码页（中文系统即 GBK），保证 cmd 能正确读取含中文的路径
        with open(script_path, "w", encoding="mbcs", errors="replace", newline="") as f:
            f.write(script)
    except OSError:
        return "无法创建更新脚本（可能没有目录写权限）"
    try:
        subprocess.Popen(
            ["cmd.exe", "/c", script_path],
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
            close_fds=True)
    except Exception:
        _remove_quiet(script_path)
        return "启动更新程序失败（可能被杀毒软件拦截）"
    return ""


# ── 立即公告（type=3）本地已读记录 ───────────────────────────────────────────
# 存储位置：%APPDATA%\NebulaSDK\notices_<app_key>.txt（取不到 APPDATA 回落程序目录）
# 文件格式：每行 "<公告ID> <标记时间戳>"，
#           30 天前的旧记录读取时自动清理（避免无限增长）。

_NOTICE_READ_MAX_AGE = 30 * 86400


def _notice_read_path(app_key: str) -> str:
    base = os.environ.get("APPDATA") or _self_dir()
    directory = os.path.join(base, "NebulaSDK")
    try:
        os.makedirs(directory, exist_ok=True)
    except OSError:
        pass
    return os.path.join(directory, f"notices_{app_key}.txt")


def _load_notice_reads(path: str) -> Dict[int, int]:
    reads: Dict[int, int] = {}
    cutoff = int(time.time()) - _NOTICE_READ_MAX_AGE
    try:
        with open(path, "r", encoding="utf-8", errors="ignore") as f:
            for line in f:
                parts = line.split()
                if len(parts) != 2:
                    continue
                try:
                    nid, ts = int(parts[0]), int(parts[1])
                except ValueError:
                    continue
                if nid > 0 and ts >= cutoff:
                    reads[nid] = ts
    except OSError:
        pass
    return reads


def _save_notice_reads(path: str, reads: Dict[int, int]) -> None:
    try:
        with open(path, "w", encoding="utf-8", newline="") as f:
            for nid, ts in reads.items():
                f.write(f"{nid} {ts}\r\n")
    except OSError:
        pass


def _mark_notice_read(app_key: str, notice_id: int) -> None:
    path = _notice_read_path(app_key)
    reads = _load_notice_reads(path)
    reads[notice_id] = int(time.time())     # 已存在则刷新时间戳（dict 覆盖）
    _save_notice_reads(path, reads)


# ── Client --------------------------------------------------------------------

class Client:
    """Nebula 网络验证客户端（Python 版）。

    生命周期：init() 成功后才能 login()；登录成功后
    start_heartbeat() 保活，logout() 主动登出。
    """

    def __init__(self, machine_id: str = "", os_info: str = "", client_ver: str = ""):
        self.machine_id = machine_id or get_stable_machine_id()
        self.os_info = os_info or platform.platform()
        self.client_ver = client_ver or config.kClientVersion
        self._env = Envelope()
        self._env.machine_id = self.machine_id   # 3.1 握手 mhash 用
        self._login_method = "password"
        self.last_init: Optional[InitResult] = None
        self.last_login: Optional[LoginResult] = None

        # 内置提示：默认系统弹窗；set_ui_handler 后改走接入方回调 fn(kind, message)
        self.ui_handler: Optional[Callable[[str, str], None]] = None

        # 自动更新策略（建议 init 前设置）
        self.auto_update_enable = True      # 总开关（False 时 auto_update 返回 Disabled）
        self.auto_update_optional = False   # True = 可选更新也自动安装（默认仅提示）
        self.allow_insecure_update = False  # True = 允许 http 更新地址（默认仅 https）

        self._hb_thread: Optional[threading.Thread] = None
        self._hb_stop = threading.Event()
        self._hb_interval = 60

    # -- init ------------------------------------------------------------------

    def init(self) -> InitResult:
        """初始化：拉取配置 / 公告 / 版本 / 会话密钥。失败返回 ok=False。"""
        r = InitResult()
        r.code, r.msg = -1, "网络错误"
        try:
            data, _ = self._env.send("init", {
                "machine_id": self.machine_id,
                "client_ver": self.client_ver,
            }, use_session=False)
        except NebulaError as e:
            r.code, r.msg = e.code, e.msg
            self.last_init = r
            return r

        r.code = 0
        r.ok = True
        r.msg = "ok"
        r.site_name = str(data.get("site_name", ""))
        sw = data.get("software") or {}
        r.software_name = str(sw.get("name", ""))
        r.heartbeat_interval = int(data.get("heartbeat_interval", 60))
        r.maintain = bool(data.get("maintain_mode", False))
        r.maintain_msg = str(data.get("maintain_msg", ""))
        r.register_enable = bool(data.get("register_enable", True))

        spec = data.get("login") or {}
        r.login_spec = LoginSpec(
            method=str(spec.get("method", "password")),
            label=str(spec.get("label", "")),
            need_username=bool(spec.get("need_username", True)),
            need_password=bool(spec.get("need_password", True)),
            need_code=bool(spec.get("need_code", False)),
            fields=list(spec.get("fields") or []),
        )
        r.notices = list(data.get("notices") or [])
        r.notice_text = self._build_notice_text(r.notices)

        v = data.get("version") or {}
        r.version = VersionInfo(
            client_ver=str(v.get("client_ver", "")),
            latest=str(v.get("latest", "")),
            min_ver=str(v.get("min", "")),
            need_update=bool(v.get("need_update", False)),
            force_update=bool(v.get("force_update", False)),
            update_url=str(v.get("update_url", "")),
            changelog=str(v.get("changelog", "")),
            file_hash=str(v.get("file_hash", "")),
            file_size=int(v.get("file_size", 0)),
            self_file_hash=str(v.get("self_file_hash", "")),
            self_file_size=int(v.get("self_file_size", 0)),
        )

        grace = data.get("grace") or {}
        r.grace_enable = bool(grace.get("enable", False))
        r.grace_public_key = str(grace.get("public_key", ""))

        # 3.1：会话由 ECDH 握手建立（Envelope 懒握手），init 不再下发会话密钥
        self._login_method = r.login_spec.method
        self._hb_interval = r.heartbeat_interval
        self.last_init = r
        return r

    @staticmethod
    def _build_notice_text(notices: List[Dict]) -> str:
        """公告列表 → 多行文本（标题：内容）。"""
        lines = []
        for n in notices:
            title = str(n.get("title", ""))
            content = str(n.get("content", ""))
            line = content if not title else f"{title}：{content}"
            if line:
                lines.append(line)
        return "\n".join(lines)

    # -- 内置提示（默认弹中文窗；set_ui_handler 后改走回调）────────────────────

    def set_ui_handler(self, handler: Optional[Callable[[str, str], None]]) -> None:
        """设置自定义提示回调 fn(kind, message)（kind: popup/flash/version/update/
        maintain/kick/integrity）；设置后所有内置弹窗改走回调，不再弹系统窗。"""
        self.ui_handler = handler

    def ui_alert(self, kind: str, message: str, title: str = "",
                 icon: int = MB_ICONINFORMATION) -> None:
        """内置提示：有 handler 走 handler(kind, message)，否则系统弹窗。"""
        if self.ui_handler:
            try:
                self.ui_handler(kind, message)
            except Exception:
                pass
            return
        message_box(message, title or "Nebula", icon)

    def alert_title(self, suffix: str = "") -> str:
        """弹窗标题：优先用 init 下发的软件名（如「XXX菜单 - 公告」），
        未取到（init 前弹窗 / 服务端未下发）时回退「Nebula<suffix>」。
        suffix 带前导空格，如 " 公告"。"""
        name = self.last_init.software_name if self.last_init else ""
        if not name:
            return f"Nebula{suffix}" if suffix else "Nebula"
        return f"{name} - {suffix}" if suffix else name

    def version_alert(self) -> bool:
        """init 成功后调用：版本过期 / 发现新版本提示。
        强制更新时返回 False（应中止登录）；否则 True。"""
        ir = self.last_init
        if not ir:
            return True
        if ir.version.force_update:
            self.ui_alert("version",
                          f"当前版本过低（{self.client_ver}），请升级到 {ir.version.latest} 后使用。",
                          self.alert_title(" 版本更新"), MB_ICONWARNING)
            return False
        if ir.version.need_update:
            self.ui_alert("version",
                          f"发现新版本 {ir.version.latest}，建议尽快升级。",
                          self.alert_title(" 版本更新"), MB_ICONINFORMATION)
        return True

    def maintain_alert(self) -> None:
        """init 成功后调用：维护模式提示（登录仍由服务端 6002 兜底拦截）。"""
        self.ui_alert("maintain", "服务器维护中，请稍后再试。",
                      self.alert_title(" 公告"), MB_ICONWARNING)

    def kick_alert(self, server_msg: str = "", code: int = 0) -> None:
        """被踢 / 顶号 / 需重新登录时调用（可在心跳回调线程内）。"""
        self.ui_alert("kick", kick_text(code, server_msg),
                      self.alert_title(" 下线通知"), MB_ICONWARNING)

    def enforce_self_integrity(self) -> bool:
        """一站式自身完整性校验（使用最近一次 init 的结果）；
        失败弹提示并返回 False（调用方应立即退出）。"""
        v = self.last_init.version if self.last_init else VersionInfo()
        reason = verify_self_integrity(v.self_file_hash, v.self_file_size)
        if not reason:
            return True
        self.ui_alert("integrity", reason, self.alert_title(" 安全校验"), MB_ICONERROR)
        return False

    # -- 公告（弹窗 type=2 / 立即 type=3 / 列表 type=4）────────────────────────

    def get_notices(self, notice_id: int = 0) -> List[Dict]:
        """拉取公告（notice 接口，id=0 表示全部）。失败返回空列表。"""
        try:
            data, _ = self._env.send("notice", {"id": notice_id}, use_session=True)
        except NebulaError:
            return []
        return self.parse_notice_list(data)

    @staticmethod
    def parse_notice_list(data) -> List[Dict]:
        """notice / init 响应（解密后 dict 或列表）→ 规范化公告列表
        [{id, title, content, type, type_text}]。"""
        items: List = []
        if isinstance(data, dict):
            items = list(data.get("list") or data.get("notices") or [])
        elif isinstance(data, list):
            items = list(data)
        out: List[Dict] = []
        for n in items:
            if isinstance(n, dict):
                try:
                    out.append({
                        "id": int(n.get("id", 0)),
                        "title": str(n.get("title", "")),
                        "content": str(n.get("content", "")),
                        "type": int(n.get("type", 1)),
                        "type_text": str(n.get("type_text", "")),
                    })
                except (TypeError, ValueError):
                    continue
        return out

    @staticmethod
    def notice_text(notice: Dict) -> str:
        """公告展示文本：标题 + 空行 + 内容；无标题时只显示内容。"""
        t = str(notice.get("title", ""))
        c = str(notice.get("content", ""))
        if not t:
            return c
        return f"{t}\n\n{c}" if c else t

    def popup_notices(self, notices: List[Dict] | None = None) -> List[Dict]:
        """弹窗公告（type=2）：拉取并逐条提示（无已读机制，每次都会提示）。
        notices 可传预取的公告列表（与 flash_notices 共享一次网络请求）。"""
        src = self.get_notices() if notices is None else notices
        out = [n for n in src if n["type"] == 2]
        for n in out:
            self.ui_alert("popup", self.notice_text(n),
                          self.alert_title(" 公告"), MB_ICONINFORMATION)
        return out

    def fetch_flash_notices(self, notices: List[Dict] | None = None) -> List[Dict]:
        """只拉取**未读**的立即公告（type=3，不弹窗、不标记）；
        自行展示后调 mark_notice_read(id)。"""
        reads = _load_notice_reads(_notice_read_path(config.kAppKey))
        src = self.get_notices() if notices is None else notices
        return [n for n in src if n["type"] == 3 and n["id"] not in reads]

    def is_notice_read(self, notice_id: int) -> bool:
        return notice_id in _load_notice_reads(_notice_read_path(config.kAppKey))

    def mark_notice_read(self, notice_id: int) -> None:
        """标记某条立即公告为已读（已存在则刷新时间戳）。"""
        _mark_notice_read(config.kAppKey, notice_id)

    def clear_notice_reads(self) -> None:
        """清空本地已读记录（全部立即公告会重新下发显示）。"""
        _remove_quiet(_notice_read_path(config.kAppKey))

    def flash_notices(self, notices: List[Dict] | None = None) -> List[Dict]:
        """一行内置：拉取未读立即公告 → 逐条提示（默认弹窗）→ 标记已读。"""
        out = self.fetch_flash_notices(notices)
        for n in out:
            self.ui_alert("flash", self.notice_text(n),
                          self.alert_title(" 公告"), MB_ICONINFORMATION)
            self.mark_notice_read(n["id"])
        return out

    # -- 自动更新（检测 → 下载 → 校验 → 自替换 → 重启）─────────────────────────

    def download_update(self) -> UpdateResult:
        """只下载更新包（不替换、不退出），由接入方自行决定何时安装。
        强制更新与可选更新都会下载（受 auto_update_enable 控制）。"""
        r = UpdateResult()
        if not self.auto_update_enable:
            r.state = UpdateState.Disabled
            r.msg = "自动更新未启用（auto_update_enable = False）"
            return r
        ir = self.last_init
        if not ir or not ir.ok:
            r.state = UpdateState.Failed
            r.msg = "尚未完成初始化，无法执行更新"
            return r
        v = ir.version
        r.version, r.verify_hash, r.verify_size = v.latest, v.file_hash, v.file_size
        r.force = v.force_update

        if not v.need_update:
            r.state = UpdateState.NoUpdate
            r.msg = "已是最新版本"
            return r
        if not v.update_url:
            r.state = UpdateState.Failed
            r.msg = "服务器未提供更新包下载地址，请到官网手动下载"
            return r
        # 更新地址默认只接受 https（http 需显式打开 allow_insecure_update）
        if not self.allow_insecure_update and not v.update_url.lower().startswith("https://"):
            r.state = UpdateState.Failed
            r.msg = "更新地址不是 https，已拒绝下载（如需允许请设置 allow_insecure_update = True）"
            return r

        # 落到程序同目录，确保 move 不跨卷（跨卷 move 非原子且更慢）
        dest = os.path.join(_self_dir(),
                            f"nebula_upd_{random_hex(6)}_{_file_name_from_url(v.update_url)}")
        err = _download_to_file(v.update_url, dest)
        if err:
            r.state = UpdateState.Failed
            r.msg = err
            return r
        err = _verify_downloaded(dest, v.file_hash, v.file_size)
        if err:
            _remove_quiet(dest)
            r.state = UpdateState.Failed
            r.msg = err
            return r

        r.state = UpdateState.Downloaded
        r.new_file = dest
        r.msg = f"更新包已下载并校验通过（版本 {v.latest}）"
        return r

    def auto_update(self, exit_when_applied: bool = True) -> UpdateResult:
        """全自动更新：检测 → 下载 → 校验 → 生成替换脚本 → **本进程退出并重启**。

        建议在启动时（init 之后、登录之前）调用一次::

            up = client.auto_update()
            if up.state == UpdateState.Applied: ...   # 即将重启
            if up.state == UpdateState.NeedConfirm: ...  # 可选更新，已提示

        策略：强制更新 → 自动下载、替换并重启；可选更新 → 默认只提示
        （NeedConfirm），auto_update_optional = True 时同样自动处理。

        :param exit_when_applied: 完成替换后是否立即退出本进程（默认 True；
                                  测试时可传 False，此时只生成脚本并返回 Applied）
        """
        r = self.download_update()
        if r.state != UpdateState.Downloaded:
            return r

        if not r.force and not self.auto_update_optional:
            # 可选更新且未开启自动安装：保留已下载文件，提示用户确认
            r.state = UpdateState.NeedConfirm
            self.ui_alert("update",
                          f"发现新版本 {r.version}，已下载完成，重启后生效。",
                          self.alert_title(" 版本更新"), MB_ICONINFORMATION)
            return r

        err = self._apply_update_impl(r.new_file, r.verify_hash, r.verify_size,
                                      exit_when_applied)
        if err:
            r.state = UpdateState.Failed
            r.msg = err
            return r
        r.state = UpdateState.Applied
        r.msg = "更新已就绪，程序即将重启完成升级"
        return r

    def apply_downloaded_update(self, r: UpdateResult,
                                exit_when_applied: bool = True) -> str:
        """手动对已下载的更新包执行替换并重启（配合 download_update 使用）。
        返回失败原因；空串 = 成功（调用方随即会退出）。"""
        return self._apply_update_impl(r.new_file, r.verify_hash, r.verify_size,
                                       exit_when_applied)

    def _apply_update_impl(self, new_file: str, expect_hash: str, expect_size: int,
                           exit_now: bool) -> str:
        exe = sys.executable or ""
        if not exe:
            return "无法定位当前程序文件"
        if not new_file or not os.path.exists(new_file):
            return "更新文件不存在"
        # 替换前再校验一次（防 TOCTOU：下载完成到替换之间被掉包）
        if expect_hash:
            err = _verify_downloaded(new_file, expect_hash, expect_size)
            if err:
                _remove_quiet(new_file)
                return err
        err = _spawn_replacer(new_file, exe)
        if err:
            return err
        if exit_now:
            os._exit(0)     # 立即退出让出 exe 文件占用，替换脚本接管（等价 ExitProcess）
        return ""

    # -- login -----------------------------------------------------------------

    def login(self, account: str, secret: str) -> LoginResult:
        """按 init 下发的登录方式组装字段（password / username_code / code）。"""
        r = LoginResult()
        method = self._login_method

        if method == "code":
            payload: Dict[str, str] = {"code": secret}     # 卡密直登：第一个框的输入即卡密
        elif method == "username_code":
            payload = {"username": account, "code": secret}
        else:  # password
            payload = {"username": account, "password": secret}

        payload.update({
            "machine_id": self.machine_id,
            "device_name": platform.node() or "未知设备",
            "os_info": self.os_info,
            "client_ver": self.client_ver,
        })

        # 设备指纹（多硬件组件）：服务端据此做加权校验/虚拟机识别；
        # 无有效组件时为空串，不上报（与服务端「未上报跳过」语义一致）
        fp = collect_fingerprint_json()
        if fp:
            payload["device_fp"] = fp

        try:
            data, _ = self._env.send("login", payload, use_session=True)
        except NebulaError as e:
            r.code, r.msg = e.code, e.msg
            self.last_login = r
            return r

        r.ok = True
        r.token = str(data.get("token", ""))
        r.expire_at = int(data.get("expire_at", 0))
        r.ttl = int(data.get("ttl", 0))
        r.login_method = str(data.get("login_method", method))
        r.feature_key = str(data.get("feature_key", ""))     # 功能密钥：登录成功才下发
        r.account_created = bool(data.get("account_created", False))
        u = data.get("user") or {}
        r.user = UserInfo(
            user_id=int(u.get("user_id", 0)),
            username=str(u.get("username", "")),
            nickname=str(u.get("nickname", "")),
            vip_expire=int(u.get("vip_expire", 0)),
            vip_text=str(u.get("vip_text", "")),
            points=int(u.get("points", 0)),
            max_devices=int(u.get("max_devices", 0)),
            group_id=int(u.get("group_id", 0)),
            status=int(u.get("status", 1)),
        )
        vip = data.get("vip") or {}
        r.vip_valid = bool(vip.get("valid", False))
        r.vip_msg = str(vip.get("msg", ""))
        dev = data.get("device") or {}
        r.auto_bound = bool(dev.get("auto_bound", False))
        r.bound_count = int(dev.get("bound_count", 0))
        self.last_login = r
        return r

    # -- heartbeat -------------------------------------------------------------

    def heartbeat_once(self, token: str) -> HeartbeatInfo:
        hb = HeartbeatInfo()
        try:
            data, _ = self._env.send("heartbeat", {
                "token": token, "machine_id": self.machine_id,
            }, use_session=True)
        except NebulaError as e:
            hb.code, hb.msg = e.code, e.msg
            # 服务端把「会话失效」以业务错误码返回（后台踢出 status=3 / 顶号 /
            # 过期 / 解绑等，见 Session::validate），响应顶层可能带
            # need_relogin / kick 标记；再按码值兜底。否则心跳线程不会
            # break，UI 永远感知不到已被踢下线。
            ex = getattr(e, "extra", None) or {}
            hb.kick = bool(ex.get("kick", False))
            hb.force_offline = bool(ex.get("force_offline", False))
            hb.need_relogin = bool(ex.get("need_relogin", False))
            hb.need_activate = bool(ex.get("need_activate", False))
            if e.code in (1002, 1003, 2002, 2004, 4002, 4005):
                hb.need_relogin = True
            return hb
        hb.code = int(data.get("code", 0))
        hb.online = bool(data.get("online", True))
        hb.remain = int(data.get("remain", 0))
        hb.remain_text = str(data.get("remain_text", ""))
        hb.vip_expire = int(data.get("vip_expire", 0))
        hb.next_interval = int(data.get("next_interval", self._hb_interval))
        st = int(data.get("status", 0))
        hb.kick = st == 3
        hb.force_offline = st == 2
        hb.need_relogin = st in (1, 4) or bool(data.get("need_relogin", False))
        hb.need_activate = st == 5 or bool(data.get("need_activate", False))
        return hb

    def start_heartbeat(self, token: str, callback: Callable[[HeartbeatInfo], None],
                        interval: int = 0) -> None:
        """SDK 内部心跳线程（不要在回调里操作 UI）。"""
        self.stop_heartbeat()
        self._hb_stop.clear()
        iv = interval or self._hb_interval

        def loop():
            while not self._hb_stop.wait(iv):
                hb = self.heartbeat_once(token)
                try:
                    callback(hb)
                except Exception:
                    pass
                if hb.kick or hb.force_offline or hb.need_relogin:
                    break

        self._hb_thread = threading.Thread(target=loop, daemon=True)
        self._hb_thread.start()

    def stop_heartbeat(self) -> None:
        self._hb_stop.set()
        if self._hb_thread and self._hb_thread.is_alive():
            self._hb_thread.join(timeout=3)
        self._hb_thread = None

    # -- logout ----------------------------------------------------------------

    def logout(self, token: str) -> bool:
        try:
            data, _ = self._env.send("logout", {
                "token": token, "machine_id": self.machine_id,
            }, use_session=True)
            self._env.clear_session()
            return int(data.get("code", -1)) == 0 or True
        except NebulaError:
            return False
