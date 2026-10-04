# -*- coding: utf-8 -*-
"""Nebula SDK (Python) 统一入口。"""
from .config import (  # noqa: F401
    kApiUrl, kAppKey, kRespSignPubKey, kClientVersion,
)
from .crypto import NebulaError  # noqa: F401
from .client import (  # noqa: F401
    Client, InitResult, LoginResult, LoginSpec, UserInfo,
    HeartbeatInfo, VersionInfo, get_stable_machine_id,
)
from . import feature  # noqa: F401


def create_default_client(machine_id: str = "", os_info: str = "", client_ver: str = "") -> Client:
    """默认客户端工厂函数。"""
    return Client(machine_id, os_info, client_ver)
