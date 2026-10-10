# 此目录存放版本发布数据
#
# 每个版本一个子目录：
#   releases/
#   ├── 1.3.0/
#   │   ├── manifest.json     版本元数据
#   │   ├── update.zip         更新包（ZIP 格式）
#   │   └── update.zip.sig     Ed25519 数字签名
#   └── 1.4.0/
#       ├── manifest.json
#       ├── update.zip
#       └── update.zip.sig
#
# manifest.json 由 api/version.php 读取并返回给客户端。
# update.zip 由 VersionManager 下载到客户端 storage/updates/packages/。
#
# 发布流程：
#   1. 修改代码
#   2. 修改 version.php
#   3. 编写 Migration
#   4. 编写 manifest.json
#   5. 打包 ZIP
#   6. 生成 SHA-256
#   7. 生成 Ed25519 签名
#   8. 上传到此目录
