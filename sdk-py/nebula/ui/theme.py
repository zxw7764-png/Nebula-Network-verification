# -*- coding: utf-8 -*-
"""
Nebula UI · 主题常量
------------------------------------------------------------------------------
窗口尺寸与主题配色（14 项）。
"""
from __future__ import annotations

import pygame

# ── 窗口尺寸 ─────────────────────────────────────────────────────────────────
kLoginWidth = 440
kLoginHeight = 420
kMainWidth = 800            # 主界面（MainWindow）
kMainHeight = 500
kTitleBarHeight = 36

# ── 主题配色（RGB / RGBA）───────────────────────────────────────────────────
kBgTop = (13, 18, 32)
kBgBottom = (20, 30, 48)
kAccent = (0, 229, 160)
kAccentBright = (0, 255, 190)
kText = (235, 241, 247)
kMuted = (145, 160, 176)
kInputBg = (26, 38, 56)
kNoticeBg = (22, 32, 48)
kInputBorder = (42, 58, 82)
kError = (255, 77, 106)
kTitleBarBg = (15, 21, 37)
kButtonIdle = (52, 66, 84)
kButtonHover = (70, 86, 106)
kCloseHover = (214, 48, 66)


# ── 登录窗布局 ──────────────────────────────────────────────────────────────
kInputLeft = 50
kInputWidth = 340
kInputHeight = 36

NOTICE_RECT = pygame.Rect(kInputLeft, 52, kInputWidth, 60)
USER_INPUT_RECT = pygame.Rect(kInputLeft, 172, kInputWidth, kInputHeight)
PASS_INPUT_RECT = pygame.Rect(kInputLeft, 246, kInputWidth, kInputHeight)
LOGIN_BTN_RECT = pygame.Rect(kInputLeft, 348, 160, 40)
CANCEL_BTN_RECT = pygame.Rect(230, 348, 160, 40)

STATUS_POS = (50, 306)      # 状态提示文字位置
LABEL_DY = -26              # 输入框标签相对框顶的偏移
