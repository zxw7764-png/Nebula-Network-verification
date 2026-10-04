# Nebula SDK 客户端加固指南（壳标记 · 代码混淆 · 反调试 · 反虚拟机）

> 📚 本文属 Nebula 文档中心，主索引见 [../README.md](../README.md)；
> 配套文档：[C++ SDK 接入文档](SDK.md) · [API 接口](../docs/API.md)

> **一句话结论：全部默认关闭。** 不定义任何宏时，`nebula/protect/` 下的加固代码几乎不编译进目标文件，
> 行为 / 协议 / 性能与**不加加固的版本完全一致**。想开启，只需在工程预处理器里加一行
> `NEBULA_HARDEN=1`（或按需单独开某一项）。
>
> 本文是**给接入方看的操作手册**：每一项怎么开、开在哪、开完怎么验证、误报怎么收场。

---

## 0. 30 秒上手

### 0.1 开关一览

| 开关宏 | 默认 | 作用 | 对应文件能力 |
| --- | --- | --- | --- |
| `NEBULA_PROTECT_LEVEL` | `0` | 运行时防护等级：`1` 基础 / `2` 标准 / `3` 严格 | 反调试、反虚拟机/沙箱、API 劫持、代码补丁自检 |
| `NEBULA_OBF_STRINGS` | `0` | 核心代码混淆 | 字符串编译期加密、间接调用、不透明谓词 |
| `NEBULA_SHELL_ENABLE` | `0` | 壳标记（VMProtect / Themida · WinLicense / 自定义） | 在核心函数里插入壳的标记，让加壳工具保护这段代码 |
| `NEBULA_RUNTIME_DIVERSE` | `0` | 运行时多样性 | `SecureString` 每次启动随机密钥、不透明谓词每次启动随机形态（`=0` 时退化为固定密钥 + 固定谓词，零开销） |

辅助宏：

| 宏 | 默认 | 说明 |
| --- | --- | --- |
| `NEBULA_HARDEN` | `0` | **一键全开**：等于 `LEVEL=3` + 混淆 + 壳标记 + 运行时多样性 |
| `NEBULA_PROTECT_ACTION` | `1` | 命中后的动作：`0` 只记录 / `1` 回调上报 / `2` 降级 / `3` 弹窗退出 |
| `NEBULA_TIMING_THRESHOLD_MS` | `50.0` | 时序异常阈值（毫秒） |
| `NEBULA_QUIET` | — | 定义后关闭"加固未启用"的编译期提醒 |
| `NEBULA_SHELL_NO_AUTOLINK` | — | 定义后不自动链接壳的 .lib（自己手动加） |

### 0.2 三种打开方式

**方式 A：一键全开（发布版推荐）**

```
项目属性 → C/C++ → 预处理器 → 预处理器定义，加一项：
NEBULA_HARDEN=1
```

**方式 B：在包含 SDK 之前定义**

```cpp
#define NEBULA_HARDEN 1        // 必须在 #include 之前
#include "nebula_sdk.hpp"
```

**方式 C：只开其中一两项（例如先只上反调试）**

```cpp
#define NEBULA_PROTECT_LEVEL 2 // 只要运行时防护
#include "nebula_sdk.hpp"      // 混淆与壳标记保持关闭
```

### 0.3 怎么知道有没有生效

- 编译时：三项全关会在输出窗口打印一条提醒
  （`Nebula SDK: 客户端加固当前【未启用】(默认)…`），开了就没了。不想看提醒就定义 `NEBULA_QUIET=1`。
- 运行时：`enableProtection()` 会返回一份 `Report`；也可以打开 DebugView 看
  `[Nebula] 环境正常（风险分 0）` 这类输出。

### 0.4 推荐组合

| 场景 | 建议 |
| --- | --- |
| 开发 / 自测 | **全关**（默认），方便断点调试 |
| 内测发版 | `NEBULA_PROTECT_LEVEL=1`（基础反调试，误报极低）+ 混淆 |
| 正式发版 | `NEBULA_HARDEN=1`，动作设 `2`（降级）或 `3`（退出） |
| 强对抗（有人专门破） | `NEBULA_HARDEN=1` + VMProtect 虚拟化核心函数 + Obfuscator-LLVM 再叠一层 |

> ⚠️ **自己调试的时候记得关掉。** 开着 `LEVEL>=1` 在 VS 里按 F5 会被自己写的检测命中（这是正常的，
> 说明它工作正常）。

---

## 1. ① 壳标记（VMProtect / Themida / 自定义壳）

### 1.1 它做了什么

`nebula_protect.hpp` 在 SDK 的**核心函数**里插入了统一的标记宏。开启后：

- 装了 **VMProtect** → 标记展开为 `VMProtectBeginVirtualization/Mutation/Ultra` + `VMProtectEnd`，
  加壳时 VMProtect 会把这段代码**虚拟化/变异**；
- 装了 **Themida / WinLicense** → 展开为 `VM_START / VM_END / MUTATE_*`；
- 什么都没装 / 没开启 → 展开为**空宏**，对代码零影响。

**SDK 已经内置标记的位置**（开箱即用，不用自己动手）：

| 位置 | 强度 | 原因 |
| --- | --- | --- |
| `Client::post()` 的加密 + 签名段 | `MUTATE`（变异） | 每次请求都走，用虚拟化太慢 |
| `Client::loginAndGuard()` 的内置登录成功/失败判定分支 | `VM`（虚拟化） | 调用频率低，是"登录授权判定"的核心；模板需被接入层实际调用才会实例化产码 |

> ⚠️ `Client::checkOffline()`（ES256 验签 + 机器码/会话绑定）**没有任何壳标记**，
> 旧版本文档曾误写为 VM 标记位置；需要保护它请按 §1.2 自己加 `NEBULA_MARK_VM_BEGIN/END`。

### 1.2 建议你自己再标哪些函数

接在自己的业务里（用同一套宏即可）：

```cpp
std::string MyApp::verifyLicense() {
    NEBULA_MARK_VM_BEGIN();          // 虚拟化：最强，只给最关键的函数
    auto gr = client_->checkOffline(ticket, token);
    NEBULA_MARK_VM_END();

    NEBULA_MARK_MUTATE_BEGIN();      // 变异：性能好，适合常调用的函数
    updateUiState(gr.ok);
    NEBULA_MARK_MUTATE_END();
    return gr.msg;
}
```

| 宏 | 强度 | 用在哪 |
| --- | --- | --- |
| `NEBULA_MARK_ULTRA_BEGIN/END()` | 最强最慢 | 全程序只标 1~2 处，例如"卡密校验总入口" |
| `NEBULA_MARK_VM_BEGIN/END()` | 强（虚拟化） | 授权判定、密钥派生、关键常量比较 |
| `NEBULA_MARK_MUTATE_BEGIN/END()` | 中（只变异指令） | 高频函数（网络收发、状态机） |
| `NEBULA_MARK_SCOPE_BEGIN/END()` | 仅划范围 | Themida 这类按区域处理的壳 |

### 1.3 怎么让壳真的生效（VMProtect 为例）

1. 打开 `NEBULA_SHELL_ENABLE=1`，编译出 `MyApp.exe`（带标记）。
2. VMProtect 里打开该 exe → 左侧 `Functions` 会**自动列出所有带标记的函数**。
3. 勾选需要的项 → 设置 `Compilation Type`：`Virtualization` / `Mutation` / `Ultra`。
4. 需要"防补丁"就开 `Options → Check image CRC`（对应 `VMProtectIsValidImageCRC()`）。
5. 点 `Compile` 生成加壳后的 exe。
6. ⚠️ 加壳后的 exe **哈希变了**，如果后台「版本管理」登记了 `self_file_hash`，
   必须把加壳后 exe 的哈希/大小**重新登记**，否则会被 SDK 的完整性自校验拦下。

Themida / WinLicense：同样道理，打开 exe 后在 `Protection Options` 里会看到
`VM_START / MUTATE_*` 标记出来的区域，勾上 `Virtualize` / `Mutate` 即可。

#### 1.3.1 本项目实测的 VMProtect 壳配置（NebulaUILoader.exe / Nebula.dll）

> 本工程（Nebula Menu）实际跑通的配置，可直接照抄。
> 前提：工程预处理器已定义 `NEBULA_HARDEN=1`（其中已包含 `NEBULA_SHELL_ENABLE=1`）。

**① 保护范围：只勾两处，不要全量虚拟化**

| 函数 | 类型 | 原因 |
| --- | --- | --- |
| `Client::loginAndGuard<...>` | `Virtualization` | 登录成功/失败判定，调用频率低，是最关键的授权分支 |
| `Client::post()` | `Mutation` | 每次网络请求都走，虚拟化太慢，变异足够 |

> `loginAndGuard` 是**模板 + `NEBULA_NOINLINE`**：接入层不调用它就**不会实例化、不产码**，
> VMProtect 的 `Functions` 列表里也看不到这个函数，更不会出现 `VMProtectBeginVirtualization`。

**② 选项设置**

| 分类 | 选项 | 取值 |
| --- | --- | --- |
| 虚拟机 | 版本 / 实例 | 默认 |
| 虚拟机 | 复杂性 | `100%`（体积或启动耗时吃不消就降到 `50%`） |
| 文件 | 内存保护 | 是 |
| 文件 | 导入保护信息 | **否**（必须） |
| 文件 | 资源保护 | 是（开完必须实测，见 ③） |
| 文件 | 压缩输出的文件 | 是 |
| 文件 | 输出文件 | `Nebula.vmp.dll`（加壳后改名覆盖 `x64\Release\Nebula.dll`） |
| 检测 | 调试器 | **否** |
| 检测 | 虚拟机工具 | **否** |
| 附加 | 分段 | `.???` |
| 附加 | 移除调试信息 | 是 |
| 附加 | 移除重定位信息 | **否**（DLL 必须保留） |
| 附加 | Shadow Stack Compatible | 否 |

**③ 三个必须知道的坑**

1. **导入保护信息 = 否**：SDK 依赖 WinHTTP / bcrypt 等系统 DLL，一旦加密导入表，
   网络请求与加密一起失效，表现就是**「登录永远失败」**，且没有任何报错线索。
2. **调试器 / 虚拟机检测 = 否**：这两项命中时壳是**直接终止进程**（不是弹提示），
   用户侧看到的就是游戏崩溃；再叠加 VBS / Hyper-V / 云电脑 / 网吧环境的大量误报，得不偿失。
3. **资源保护 / 内存保护开完要实测**：本工程把「注入成功音效」（`IDR_INJECT_SOUND`）与
   MDI 字体都以 `RCDATA` 内嵌在 `Nebula.dll` 里，若出现无声或字体乱码，先关掉这两项复测定位。

**④ 加壳顺序（别搞反）**

```
1) 备份未加壳的 Nebula.dll（排查线上崩溃要用）
2) VMProtect 打开 Nebula.dll → 勾 ① 的两处 → 按 ② 设好选项 → Compile 产出 Nebula.vmp.dll
3) 用 Nebula.vmp.dll 覆盖 x64\Release\Nebula.dll
4) 只重新编译 NebulaUILoader（它的 PostBuildEvent 会把 Nebula.dll 嵌成 RCDATA 资源）
   —— 不要再单独编译 Payload，否则会把刚加壳的 dll 覆盖回未加壳版本
5) 对 NebulaUILoader.exe 重复加壳（选项同 ②），产物另存为 *.vmp.exe
```

⚠️ 加壳后**哈希与大小都变了**：若后台「版本管理」登记过 `self_file_hash`，
必须**重新登记**加壳后 exe 的哈希/大小，否则 `enforceSelfIntegrity()` 会以
「程序文件校验失败」把用户挡在登录之外。

⚠️ **虚拟化范围不宜过大**：本项目实测，范围放大后 Loader 的 `.text` 从约 868 KB 膨胀到约 33 MB
（≈38 倍），dll 同理（约 640 KB → 约 28 MB）。若成品体积出现这种量级，说明虚拟化范围过大，
请回到 ① ——只勾那两个函数。

### 1.4 宏名对不上怎么办（Enigma / Obsidium / 老版本 Themida）

有些壳没有统一的标记头文件，或宏名不一样（如 `SECURE_BEGIN/SECURE_END`）。
用**自定义 HOOK** 接管：

```cpp
#define NEBULA_SHELL_FORCE_HOOK 1
#define NEBULA_SHELL_HOOK_VM_BEGIN()     SECURE_BEGIN
#define NEBULA_SHELL_HOOK_VM_END()       SECURE_END
#define NEBULA_SHELL_HOOK_MUTATE_BEGIN() MUTATE_BEGIN
#define NEBULA_SHELL_HOOK_MUTATE_END()   MUTATE_END
#define NEBULA_SHELL_ENABLE 1
#include "nebula_sdk.hpp"
```

Enigma Protector 这类**只能靠 GUI 选函数**的壳：`NEBULA_SHELL_ENABLE` 可以不开，
反正标记是空的；直接在 Enigma 里按函数名勾选 `Client::post`、`loginAndGuard` 等即可。

### 1.5 加壳的坑（务必看）

| 坑 | 说明 |
| --- | --- |
| **别保护 IAT / 导入表完整性** | SDK 依赖 WinHTTP + bcrypt 的系统 DLL。若壳开了"导入表保护/加密"且配置不当，会出现网络请求失败、加密失败 —— 表现为"登录永远失败"，很难查。 |
| **别把整个 exe 全虚拟化** | 只虚拟化标记出来的核心函数。全量虚拟化会让启动慢十倍、内存暴涨。 |
| **杀软误报** | 加壳 + 反调试组合极易被国产杀软报毒。发布前务必过一遍主流杀软与云端沙箱，否则用户装了就被删。 |
| **标记段里尽量别放 `return`** | 个别壳版本对"标记区内提前返回"支持不好（虚化后行为异常）。若要严格，把 `BEGIN/END` 收紧到不含 `return` 的语句块。 |
| **保留一个未加壳版本** | 用来排查线上崩溃（加壳后崩溃栈基本没意义）。 |
| **不要把 `NEBULA_MARK_*` 用在 `return` 之后** | 标记必须成对且在同一函数体内，且 `BEGIN` 要能走到对应的 `END`（多个提前返回时，每个出口都要补 `END`）。SDK 内部已按此写；自己加标记时注意。 |

### 1.6 报 C3861「找不到标识符 VMProtectBegin…/VMProtectEnd」怎么修

这是**接入配置问题**，不是 SDK bug。逐条排查：

| 症状 | 原因 | 修法 |
| --- | --- | --- |
| 报 `"VMProtectBeginVirtualization": 找不到标识符` | 开了 `NEBULA_SHELL_ENABLE=1` 但工程「附加包含目录」里没有 `VMProtectSDK.h` 所在目录 | 把 `sdk/vmp`（含 `VMProtectSDK.h` + `.lib`）加进 **附加包含目录**；链接器「附加库目录」也要指向它 |
| 立刻 fatal：`无法打开包括文件 "VMProtectSDK.h"` | 同上，包含目录缺失 | 同上 |
| LNK1104 `无法打开文件 VMProtectSDK64.lib` | 头文件找到了，但链接器找不到 `.lib` | 链接器 → 常规 → **附加库目录** 加 `sdk/vmp`；或定义 `NEBULA_SHELL_NO_AUTOLINK` 后自己手动加入该 `.lib` |
| 报 `NEBULA_MARK_VM_BEGIN` 本身找不到 | 该文件用了标记宏但没有（直接/间接）包含 `protect/shell.hpp` | 只需 `#include "nebula_sdk.hpp"`，或至少 `#include "nebula/protect/shell.hpp"` |

**SDK 2 个已知陷阱（v3.0.0 已修，旧版请升级）**：
- `nebula/client/client.hpp` 用了标记宏却没自包含 `protect/shell.hpp` → 单独引用该头时报 `NEBULA_MARK_*` 未定义。
- `protect/shell.hpp` 曾在 `namespace nebula::protect` **内部** include 壳 SDK 头，
  导致 VMProtect 的全局 C 函数被裹进命名空间，宏在 `nebula::client` 里展开就找不到 →
  已改为**全局作用域 include**。

**自定义壳（Enigma / 魔改 Themida）**：如果宏名对不上，用 §1.4 的 `NEBULA_SHELL_FORCE_HOOK`
接管即可，此时不需要 `VMProtectSDK.h`，也就不会遇到上述链接问题。


---

## 2. ② 核心代码混淆

### 2.1 字符串加密（`NEBULA_STR` / `NEBULA_WSTR`）

开启 `NEBULA_OBF_STRINGS=1` 后，明文字符串在**编译期**被拆成密文写进 `.rdata`，运行时按需解密：

```cpp
// 开启后：exe 里搜不到 http://api.example.com/api/index.php 这种明文
auto url  = NEBULA_STR("http://api.example.com/api/index.php");
auto note = NEBULA_WSTR(L"Nebula 安全提示");

// 关闭时：等价于 std::string(s) / std::wstring(s)，零开销
```

**规则**：

- 只能传**字面量**，不能传变量、`std::string` 或宏拼接结果；
- 每个字面量用 `__LINE__ + __COUNTER__` 生成独立密钥，同一句话在两处出现也是两份密文；
- 它防的是 `strings` / IDA 字符串窗口的**顺手一搜**，不是密码学强度（`enc` 数组本身就是密钥线性变换）。
  想更彻底：把密钥换成运行时值（`nebula::obf::runtimeNoise()` 之类）或直接上壳的字符串加密
  （VMProtect 的 `VMProtectDecryptStringA`）。

**接入方配置区已默认接好**：`nebula/client/config.hpp` 顶部 `namespace nebula::cfg` 的
`kApiUrl / kAppKey`（以及 `kRespSignPubKey / kTlsCertSha256`）本来就是 `NEBULA_STR("...")` 包着的，
你只替换引号里的字符串即可 —— **不要把 `NEBULA_STR(...)` 拆掉**。
3.1 起通信密钥由 ECDH 握手临时协商，exe 里已没有任何对称密钥可搜；
`kAesKey / kSignSalt` 已随 3.1 移除，无需任何占位。

**发布前自查（值得花 30 秒）**：拿编译好的 exe 搜一下自己的密钥

```bat
findstr /C:"4c7623658c80afe3" 你的程序.exe
```

搜不到 = 混淆生效；搜到 = 没生效（多半是这个字符串没写成 `NEBULA_STR(...)`，
或者工程预处理器里没定义 `NEBULA_OBF_STRINGS=1`）。

### 2.2 间接调用（打散调用图）

```cpp
// 敏感调用不走"直接调用"，编译器无法内联、静态分析看不到调用关系
nebula::obf::vcall(MyApp::checkCard, code);
auto r = nebula::obf::vcallR(SomeClass::verify, arg1, arg2);
```

### 2.3 不透明谓词 / 虚假分支

```cpp
if (!NEBULA_OPAQUE_TRUE() && NEBULA_OPAQUE_TRUE()) {
    NEBULA_DEAD_BRANCH();     // 永远走不到，但静态分析看不出
}
```

> 老实说：这几个谓词在 `/O2` 下**未必**被保留，它只是免费的一层。真正的控制流保护请交给
> 壳（VMProtect 的 Mutation）或 **Obfuscator-LLVM**（`-fla -bcf -sub -sobf`）。

### 2.4 编译期建议

- 发布版用 `/O2 /GL`（+ `/LTCG`），让优化把间接调用和谓词搅得更乱；
- **不要**用 `/Ob0`、不要开 `/RTC1`、不要用 `/ZI`（这些会把调试检查与调试信息留在包里）；
- 发布包**不要带 `.pdb`**；
- 更狠：Clang + Obfuscator-LLVM 编一遍，再叠壳。

### 2.5 运行时多样性（`NEBULA_RUNTIME_DIVERSE`）

> **一句话**：让敏感数据的**内存形态**和判定代码的**反汇编形态**随每次进程启动而改变，
> 破解者无法用固定的字符串特征或固定的字节码特征做批量匹配 / 一键绕过。

| 取值 | 行为 |
| --- | --- |
| `0`（默认） | `SecureString` 用固定编译期密钥（仍防 SSO 静态区明文），不透明谓词为固定恒真/恒假（零开销、可复现） |
| `1` | `SecureString` 每次启动用 `runtimeRandByte()` 生成的随机密钥逐字节混淆；不透明谓词每次启动取不同随机操作数，但表达式恒等于 true/false |

**它干什么**：

1. **`SecureString` 随机密钥**：同一 exe 每次启动，`cfg` 静态区里 `kAppKey` 的密文形态都不同
   （见 [`nebula/core/secure_string.hpp`](nebula/core/secure_string.hpp) 的 `SecureString`），拆包者无法用固定密文反查明文。
2. **不透明谓词随机形态**：`obf::opaqueTrue/False` 每次启动装配不同形态的恒真/恒假表达式，
   关闭时退化为固定 `return true; / return false;`。合并进 `if (!OBF_OPAQUE_TRUE() && ...)` 干扰分支。

**怎么开**（三选一，等价）：

```cpp
#define NEBULA_HARDEN 1              // 方式 A：一键全开，自动带上多样性
#define NEBULA_RUNTIME_DIVERSE 1     // 方式 B：只开多样性、别的不动
#include "nebula_sdk.hpp"
```

**要不要开**：发布版建议开（成本只有每次启动多一次随机源初始化，量级可忽略）；开发 / 自测建议关，
保持可复现、方便断点。关闭时多样性相关代码为**零开销**。

> 说明：真随机源（`runtimeRandByte`）用「高分辨率性能计数器 + 进程 PID + 时钟纳秒」做种子，
> 只依赖标准库 + Windows，不写可执行内存，不与壳 / DEP / 杀软冲突。

---

## 3. ③ 运行时防护（反调试 / 反虚拟机沙箱 / 自检）

### 3.1 三个等级各查什么

| 检查项 | 等级 | 权重 | 误报风险 |
| --- | --- | --- | --- |
| `IsDebuggerPresent` | 1 | 100（铁证） | 无 |
| `PEB.BeingDebugged` | 1 | 100（铁证） | 无 |
| `PEB.NtGlobalFlag` 调试堆标志 | 1 | 45 | 极低 |
| 进程堆 `ForceFlags` 异常 | 1 | 35 | 极低 |
| `CheckRemoteDebuggerPresent` | 2 | 100（铁证） | 无 |
| `ProcessDebugPort` / `DebugObjectHandle` | 2 | 100（铁证） | 无 |
| `ProcessDebugFlags == 0` | 2 | 80（铁证） | 无 |
| 线程硬件断点 `Dr0-Dr7` | 2 | 70 | 低（少数反外挂/杀软会设置） |
| 调试/逆向工具进程（x64dbg、IDA、Cheat Engine…） | 2 | 60 | 低 |
| 调试器窗口（OllyDbg、x64dbg、System Informer…） | 3 | 60 | 低 |
| 关键代码执行时序异常 | 3 | 30 | 中（慢机器/降频会偏慢，故阈值给得很宽松） |
| frida 等注入框架模块 | 2 | 70 | 无 |
| 关键 API 首字节被改（inline hook） | 2 | 20 / 45（≥2 个） | 中（杀软/监控软件 hook 属正常） |
| 受保护代码段被改写（`guardCode`） | 2 | 90 | 无 |
| `CPUID` hypervisor 位 | 1 | 30 | 高（**Hyper-V/WSL2/VBS/云电脑都会置位**，所以只计分不单独判定） |
| 注册表虚拟机痕迹 | 1 | 35 | 无 |
| BIOS/主板厂商字段 | 1 | 40 | 低（云主机也会命中，属于期望行为） |
| 网卡 MAC OUI 属虚拟网卡 | 1 | 45 | 低 |
| 虚拟机增强工具进程 | 2 | 30 | 无 |
| 虚拟机/沙箱模块（SbieDll、Cuckoo 等） | 2 | 30 / 60 | 无 |
| WDAG 隔离环境账号 | 2 | 60 | 无（这就是沙箱） |
| 虚拟机驱动文件痕迹 | 3 | 25 | 无 |
| 机器配置异常偏低 | 3 | 15 | **中**（老电脑/轻量服务器会命中） |
| 开机时间 < 5 分钟 | 3 | 20 | **中**（自动化沙箱特征，但用户刚开机也会命中） |

**判定规则**：

- `debugged`：命中任一"铁证"（100 分档）**或**弱线索累计 ≥ 90 分；
- `virtualized`：虚拟机线索累计 ≥ 60 分（所以单独一个 CPUID 位不会误判）；
- `sandboxed`：命中沙箱特征（Sandboxie / Cuckoo / WDAG）；
- `hooked`：命中注入模块或 ≥2 个关键 API 被改写；
- `clean`：以上全部没命中。

### 3.2 怎么用

**方式 A：SDK 自动（推荐）**

开了 `NEBULA_PROTECT_LEVEL>=1` 后，`Client::init()` 会**自动先自检再连服务端**。
默认策略 `action=1`（只回调上报），不会打断正常用户。

```cpp
auto c = nebula::createDefaultClient(machineId, "Windows", "1.0.1");

// 想在启动时就巡检、并把结果上报到自己的服务器：
c->setProtectAction(2);                    // 命中即"降级"（拒绝后续 init/业务）
c->setProtectCallback([](const nebula::protect::Report& r) {
    OutputDebugStringA(r.summary().c_str());
    // sendToMyServer(r.score, r.detail());   // ← 上报服务端，便于运营侧观察
    // 也可以直接去看 nebula.protect.Report 的字段自行处理
});
auto r = c->enableProtection(0 /*沿用编译期等级*/, 5000 /*每 5 秒后台巡检*/);
if (!r.clean) { /* 按 r.debugged / r.virtualized / r.sandboxed / r.hooked 自行处理 */ }
```

**方式 B：完全手动**

```cpp
nebula::protect::setEnabled(true);
nebula::protect::setLevel(2);                       // 运行时降级（不能超过编译期上限）
nebula::protect::setCallback(myReporter);
nebula::protect::Report r = nebula::protect::scan();
if (!r.clean) {
    log("%s｜%s", r.summary().c_str(), r.detail().c_str());
}
```

### 3.3 `Report` 字段

| 字段 | 说明 |
| --- | --- |
| `clean` | 是否一切正常 |
| `debugged` / `virtualized` / `sandboxed` / `hooked` | 四个分类结论 |
| `score` | 累计风险分（建议直接上报服务端，用于观察趋势） |
| `flags` | 命中位掩码（`F_*` 常量，可精确判断命中了哪一项） |
| `reasons` | 命中的具体项（中文，可直接打日志/上报） |
| `summary()` / `detail()` | 一行摘要 / 明细字符串 |
| `at` | 检测时间（Unix 秒） |

### 3.4 动作策略（`NEBULA_PROTECT_ACTION`）

| 值 | 行为 | 建议 |
| --- | --- | --- |
| `0` | 只记录（什么都不做，`enforce()` 返回 true） | 灰度观察期 |
| `1` | **回调上报**（默认）。有回调就调，没回调就什么都不做 | 内测 / 上线初期 |
| `2` | 回调 + 置「降级」态：`init()` 会中止，`protect::degraded()` 为真 | 正式版推荐 |
| `3` | 回调 + 弹窗提示 + `ExitProcess(0xE0000001)` | 只对"铁证类"命中才用，慎用 |

> **强烈建议不要一上来就用 3。** 云电脑、虚拟机里的真实付费用户、杀软的 hook 都会造成误伤。
> 先用 `1` 收集一段时间，看命中的都是谁，再决定要不要收紧。

### 3.4.1 setProtectAction 会「隐式启用检测」（重要）

`Client::setProtectAction(act)` 只要传入 `act>0`，就会同时把检测等级提到编译期上限
并打开 `enabled` 开关。**不要只调它而不调其它**——它本身就是"开启检测 + 设处置"一行到位：

```cpp
c->setProtectAction(3);   // 等级提到 NEBULA_PROTECT_LEVEL 上限 + 启用扫描 + 铁证弹窗退出
```

（老版本只 `setAction`，会让扫描被 `enabled()=false` 短路、检测根本没执行——已修复。）

### 3.4.2 宽松 / 严格策略（防误伤：加速器 / 隐身 VM）

默认**宽松策略**，避免把"挂加速器产生的 hook、跑在 VM/沙箱"的正常用户误拦：

- 只有**真实调试铁证（`debugged`）**才按 `action>=2` 处置；
- `hook / VM / 沙箱` 等**疑似环境**只回调记录、不退出、不降级。

```cpp
// 想恢复旧行为（任何异常都按 action 处置，含 VM 拦截）：
nebula::protect::setSuspiciousPolicy(true);
```

### 3.4.3 虚拟机识别的兜底检测（抓"隐身 VM"）

针对 `SMBIOS.reflectHost`（反射厂商/型号/序列号后仍伪装成真机）的 VMware：

- 在查 `SystemManufacturer` / `SystemProductName` 之外，**又补了 `BIOSVendor` 与 `BIOSVersion`**。
  这两个字段 reflectHost 默认**不反射**，隐身 VMware 的 BIOS 版本前缀仍带 `VMW`（如 `VMW71.00V.0`），可被兜底命中；
- `vmDriverFile` 新增 `vmci.sys` / `vmxnet.sys` / `vmx_svga.sys` / `vmmemctl.sys` / `vsock.sys`。

```cpp
if (nebula::protect::setSuspiciousPolicy(true)) // 严格模式：让隐身 VM 也直接拦截（谨慎）
    c->setProtectAction(3);
```

### 3.5 代码补丁自检（`guardCode`）

把关键函数的地址与长度登记进去，之后每次 `scan()` 都会比对哈希：

```cpp
// 程序启动时登记一次（长度填函数大概的字节数，宁可小一点）
nebula::protect::guardCode((const void*)&MyApp::checkLicense, 256);

// 之后 scan() 会自动带上 F_TAMPER 判定；
// 也可以单独查：
bool ok = nebula::protect::verifyGuardedCode();
```

### 3.6 后台巡检

`enableProtection(0, 5000)` 会起一个后台线程每 5 秒查一次（<1 秒会被钳到 1 秒）。
它在进程退出前需要停止 —— `Client` 析构里已经自动调用 `stopWatchdog()`，
所以**正常创建/销毁 Client 就不用管**。手写用法：

```cpp
nebula::protect::startWatchdog(5000, myCallback);
nebula::protect::stopWatchdog();
```

### 3.7 误报怎么收场

| 现象 | 处理 |
| --- | --- |
| 虚拟机/VPS 用户体验受影响 | `setLevel(1)` 降级；或 `setAction(1)` 只收集不处置 |
| 开机时间 / 低配检出错杀 | 用 `flags` 精确判断，忽略 `F_ENV_WEAK` 类命中 |
| 杀软 hook 被当成劫持 | 忽略"只有 1 个 API 被改"（权重 20，不构成 `hooked`） |
| 想临时全关 | `nebula::protect::setEnabled(false)` |

---

## 4. 与服务端风控的关系（重要）

**客户端加固只能"抬高成本"，永远不能"保证安全"。**

真正做判定与处置的必须是服务端：

- 请求侧风控：`lib/Guard.php`（web / shop / agent / 后台四端已接入）；
- 协议侧：`app_key` + `aes_key` + 会话盐 + 时间窗 + nonce 去重；
- 业务侧：卡密绑定、设备数上限、离线宽限票据。

推荐做法：客户端把 `Report` 摘要（不是全部细节）随业务请求上报给你自己的服务端日志，
运营侧看"有多少用户的 `score` 异常"，而不是让客户端自己决定封号。

```cpp
c->setProtectCallback([](const nebula::protect::Report& r) {
    // 只上报脱敏后的结论，别把检测细节全发出去（等于给破解者做校验清单）
    reportToServer(r.score, r.debugged, r.virtualized, r.sandboxed, r.hooked);
});
```

---

## 5. 兼容性 / 常见问题

| 问题 | 答案 |
| --- | --- |
| 会不会影响协议、加解密、老客户端？ | **不会。** 加固只影响客户端自身，协议一个字节都没动；服务端不需要任何改动。 |
| 不开启时有没有性能损耗？ | 没有。所有检测代码都在 `#if` 里，全关时几乎不编译进目标文件。 |
| 需要什么编译环境？ | C++17（SDK 本来就要求），MSVC 2017+；**建议加 `/utf-8`**（本文件注释是中文）。 |
| 需要额外头文件/库吗？ | 不开启加固时不需要。开启壳标记后需要壳自带的 SDK 头 + lib（会自动链接，可用 `NEBULA_SHELL_NO_AUTOLINK` 关掉自动链接）。 |
| 加固代码放哪？ | 加固实现位于 `sdk/nebula/protect/`（`shell.hpp` / `obfuscate.hpp` / `runtime.hpp`），由 `nebula_sdk.hpp` 自动包含。整个 `nebula/` 目录需随伞头一起拷进工程。 |
| 只改一个文件行不行？ | 行。`NEBULA_HARDEN=1` 写在工程预处理器里，源码不用动。 |
| 云端沙箱/VPS 用户怎么办？ | 见 3.7；建议对虚拟化类命中只上报不处置。 |
| 加壳后登录一直失败？ | 90% 是壳保护了 IAT/导入表，或虚拟化了 `Client::post`。把范围收小、关掉导入表保护试试。 |
| 加壳后提示"客户端被篡改"？ | 加壳改变了 exe 哈希 → 去后台「版本管理」重新登记哈希与大小。 |

---

## 6. 一页速查

```cpp
// ============ 1. 工程预处理器定义（不改代码就能开） ============
// NEBULA_HARDEN=1                 一键全开（等级3 + 混淆 + 壳标记）
// NEBULA_PROTECT_LEVEL=1|2|3      只要运行时防护
// NEBULA_OBF_STRINGS=1            只要字符串混淆
// NEBULA_SHELL_ENABLE=1           只要壳标记
// NEBULA_RUNTIME_DIVERSE=1        只要运行时多样性（SecureString 随机密钥 + 谓词随机形态）
// NEBULA_PROTECT_ACTION=0|1|2|3   命中后的动作（默认 1 = 只回调上报）

// ============ 2. main() 里启动 ============
auto c = nebula::createDefaultClient();
c->setProtectAction(2);                                  // 命中即降级
c->setProtectCallback([](const nebula::protect::Report& r){
    OutputDebugStringA((r.summary() + " | " + r.detail()).c_str());
});
c->enableProtection(0, 5000);                            // 自检 + 每 5 秒巡检
nebula::protect::guardCode((const void*)&MyVerifier, 256); // 可选：补丁自检

// ============ 3. 业务代码里打壳标记 ============
NEBULA_MARK_VM_BEGIN();  /* 最关键的一段 */  NEBULA_MARK_VM_END();
auto s = NEBULA_STR("敏感字符串");           // 混淆后的字面量
```

---

