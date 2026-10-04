// ============================================================================
// Nebula C# SDK · 接入方配置区 ★★★ 唯一需要修改的文件 ★★★
//
// ── 填写清单 ───────────────────────────────────────────────────────────────
// 以下参数均来自后台「软件管理」页面，直接复制粘贴填入即可。
//
//   ① ApiUrl          API 入口地址（http:// 或 https://）
//                      例：https://yz.baige.fun/api/index.php
//                      注意：必须以 /api/index.php 结尾，不要带多余参数
//
//   ② AppKey          软件标识（由系统随机生成的字母数字组合）
//                      例：SWBFE6879E94DD
//                      每个软件拥有独立 AppKey，不可混用
//
//   ③ RespSignPubKey  响应签名公钥（PEM 格式，必填！）
//                      协议 3.1 通信密钥由 ECDH 握手临时协商，客户端零静态对称机密。
//
//      格式说明：
//      ┌────────────────────────────────────────────────────────┐
//      │ -----BEGIN PUBLIC KEY-----                             │
//      │ MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE...           │  ← base64 编码的公钥数据
//      │ ...                                                      │
//      │ -----END PUBLIC KEY-----                               │
//      └────────────────────────────────────────────────────────┘
//
//      获取方式：
//      1. 后台「软件管理」→「重新生成密钥」→ 复制「响应签名公钥」字段
//      2. 或直接查看服务器 config/grace_keys.php 中的 'public' 字段
//
//      注意事项：
//      · 必须保留 "-----BEGIN/END PUBLIC KEY-----" 两行，不可省略
//      · 多行 base64 内容合并为单行，行首用 `\n` 换行
//      · 密钥更新后必须同步此字段，否则所有请求签名验证失败
//
//      代码格式示例：
//        public const string RespSignPubKey =
//            "-----BEGIN PUBLIC KEY-----\n"+
//            "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEOcJAuC3Q19fcDAP1wkU+3Z9Uhrqa\n"+
//            "SAEgSwTwQhBYcMnrpl8NaLFKGRJwOQtbCLg3tkKuYxMuEd5sP1k5AOGcIQ==\n"+
//            "-----END PUBLIC KEY-----\n";
//
//      ES256（椭圆曲线）单行 base64 示例（无换行）：
//        public const string RespSignPubKey =
//            "-----BEGIN PUBLIC KEY-----\n"+
//            "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE19wi9XDuk7JifokeoyOSkkNJWe723WQJV6rG5CpdKCA7Gu95j8T66q274K/15CqawUHukTRw1QYgaVhy5qYFcg==\n"+
//            "-----END PUBLIC KEY-----\n";
//
//      RS256（RSA 2048）多行 base64 示例（约 300+ 字符）：
//        public const string RespSignPubKey =
//            "-----BEGIN PUBLIC KEY-----\n"+
//            "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0Z3VS5JJcds3xfn/ygWyF8PbnGy0AHB7Mqv\n"+
//            "...（完整 RSA 公钥）\n"+
//            "-----END PUBLIC KEY-----\n";
//
//   ⑥ TlsCertSha256   TLS 证书指纹（可选，仅 HTTPS 生效）
//                      锁定服务器证书，防止中间人攻击
//                      若服务器更换证书，需更新此指纹
//
// ── 安全建议 ───────────────────────────────────────────────────────────────
//   · 生产环境务必配置 RespSignPubKey，防止伪造响应劫持会话
//   · 定期在后台重新生成响应签名密钥并同步本文件
//   · 不要将包含真实密钥的代码提交到公开仓库
//   · 如发生密钥泄露，请立即在后台重新生成密钥并更新本文件
//
// ── 加固功能开关 ──────────────────────────────────────────────────────
//   ★ 一键全开：把 Harden=true 即可全部启用（防护等级3 + 混淆 + 壳标记 + 运行时多样性）
//
//   ProtectLevel        运行时防护等级
//     0  关闭（默认）→ 不做任何检测
//     1  基础        → IsDebuggerPresent / PEB / 堆标志 / CPUID / 注册表 / BIOS
//     2  标准        → + 远程调试器 / NtQuery / 硬件断点 / 工具进程 / 注入检测 / API hook
//     3  严格        → + 调试器窗口 / 时序异常 / 驱动文件 / 低配 / 开机时间
//
//   ProtectAction       命中加固检测后的处置
//     0  只记录
//     1  回调上报（默认）
//     2  降级（拒绝后续 init / 业务）
//     3  弹窗退出
//
//   ObfStrings          代码混淆开关
//     true  → 启用编译期字符串加密 + 间接调用 + 不透明谓词
//     false → 关闭（默认）
//
//   ShellEnable         壳标记开关（VMProtect / Themida / 自定义壳）
//     true  → 启用壳标记（需配合外部加壳工具使用）
//     false → 关闭（默认）
//
//   RuntimeDiverse      运行时多样性
//     true  → SecureString 每次启动随机密钥、不透明谓词每次启动随机形态
//     false → 固定密钥 + 固定谓词（默认）
//
//   ProtectStrictPolicy 疑似环境检测严格度
//     false（默认）→ 宽松模式，疑似环境仅警告，不阻断登录
//     true          → 严格模式，疑似环境直接拒绝登录
//
//   DebugLog            调试日志开关
//     true  → 写入 nebula_debug.log（用于开发和排查问题）
//     false → 关闭日志（生产环境推荐）
//
// ── 常见坑点 ───────────────────────────────────────────────────────────────
//   1. RespSignPubKey 若为空，所有请求会返回"未配置响应签名公钥"错误
//   2. 服务器密钥更新后，RespSignPubKey 必须一并同步
//   3. TlsCertSha256 仅在 HTTPS 时生效；HTTP 请求完全不受约束
// ============================================================================
namespace Nebula.Sdk
{
    public static class SdkConfig
    {
        /// <summary>SDK 版本号，请勿手动修改</summary>
        public const string SdkVersion = "3.1.0";

        /// <summary>① API 入口地址（http:// 或 https://）</summary>
        public const string ApiUrl = "https://yz.baige.fun/api/index.php";

        /// <summary>② 软件标识（app_key）</summary>
        public const string AppKey = "SWBFE6879E94DD";

        /// <summary>③ 响应签名公钥（PEM，必填；服务端「重新生成密钥」后须同步）</summary>
        public const string RespSignPubKey =
    "-----BEGIN PUBLIC KEY-----\n"+
    "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEOcJAuC3Q19fcDAP1wkU+3Z9Uhrqa\n"+
    "SAEgSwTwQhBYcMnrpl8NaLFKGRJwOQtbCLg3tkKuYxMuEd5sP1k5AOGcIQ==\n"+
    "-----END PUBLIC KEY-----\n";

        /// <summary>④ TLS 证书指纹锁定（可选，只对 https:// 生效）</summary>
        public const string TlsCertSha256 = "f31dc7cd4dbed7b9b6034bae7577452a6e50102ff3121775e64698b76f08b80d";

        // ====== 加固功能开关 ======

        /// <summary>★ 一键全开（= 防护等级3 + 混淆 + 壳标记 + 运行时多样性）</summary>
        public const bool Harden = false;

        /// <summary>运行时防护等级：0 关闭 / 1 基础 / 2 标准 / 3 严格</summary>
        public const int ProtectLevel = Harden ? 3 : 0;

        /// <summary>命中加固检测后的处置：0 记录 / 1 回调(默认) / 2 降级 / 3 弹窗退出</summary>
        public const int ProtectAction = 1;

        /// <summary>代码混淆开关：编译期字符串加密 + 间接调用 + 不透明谓词</summary>
        public const bool ObfStrings = Harden;

        /// <summary>壳标记开关（VMProtect / Themida / 自定义壳）</summary>
        public const bool ShellEnable = Harden;

        /// <summary>运行时多样性：SecureString 随机密钥 + 谓词随机形态</summary>
        public const bool RuntimeDiverse = Harden;

        /// <summary>时序异常阈值（毫秒）；越小越灵敏、越容易误报</summary>
        public const double TimingThresholdMs = 50.0;

        /// <summary>疑似环境处置策略（false = 宽松[默认]，true = 严格）</summary>
        public const bool ProtectStrictPolicy = false;

        /// <summary>调试日志开关（写到 exe 同目录 nebula_debug.log；发布置 false）</summary>
        public const bool DebugLog = false;
    }
}
