Themida / WinLicense SDK 目录
==============================

请从 Themida 安装目录复制以下文件到此目录：

  SecureEngineSDK.h
  SecureEngineSDK64.lib
  SecureEngineSDK32.lib（可选）

典型路径：
  C:\Program Files\IDM Computer Solutions\Themida\SDK\

自动探测：nebula_protect.hpp 会通过 __has_include("SecureEngineSDK.h") 检测到。

启用方式：
  1. 定义 NEBULA_SHELL_ENABLE=1
  2. 编译
  3. 用 Themida 加壳
