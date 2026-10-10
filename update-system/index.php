<?php
/**
 * Nebula 版本更新系统 - 官网门户（单页滚动版）
 * ==================================================================
 *   - 未安装 → 跳转安装向导 install/install.php
 *   - 已安装 → 输出官网门户（整站一个页面，向下滚动依次浏览各板块）
 *
 * 结构：本文件按顺序输出首页 Hero → 数据条 → 产品构成 → 核心功能 →
 *       安全防护 → 管理后台 → 门户·发卡 → 自动更新 → 快速接入 →
 *       常见问题 → 联系 CTA，导航与页脚复用 partials/。
 *
 * 每个区块的 data-form 决定背景粒子编队，滚动时自动平滑变形
 * （见 assets/js/story.js）。
 *
 * 管理后台入口（带入口令牌访问，无令牌一律 404）：
 *   admin/login.php?entry=<后台入口令牌>
 *   令牌保存在 config/config.php 的 admin_entry_key。
 */

$root = __DIR__;
$configFile = $root . '/config/config.php';
$lockFile   = $root . '/install/install.lock';

if (!is_file($configFile) || !is_file($lockFile)) {
    // 系统未安装 → 跳转安装向导
    header('Location: install/install.php');
    exit;
}

$PAGE       = 'home';
$PAGE_TITLE = 'Nebula 网络验证 · 软件授权与防护一站式解决方案';
$PAGE_DESC  = 'Nebula 网络验证系统：加密信封通信、多层签名校验、壳级代码保护、自动更新分发，为你的软件提供工业级授权管理。';
$PAGE_FORM  = 0;

require __DIR__ . '/partials/header.php';
?>

<!-- ============ 01 Hero ============ -->
<section class="hero" id="top" data-form="0">
  <span class="ghost-num px-el gn-r" data-speed="-70" aria-hidden="true">01</span>
  <span class="float-tag px-el" data-speed="-30" style="top:22%; left:3%;">ECDH P-256</span>
  <span class="float-tag px-el" data-speed="-52" style="bottom:26%; left:6%;">AES-256-GCM</span>
  <div class="hero-bg" aria-hidden="true"></div>
  <div class="container hero-inner">
    <div class="hero-copy reveal">
      <div class="hero-badge">
        <svg class="icon"><use href="#i-zap"/></svg>
        新一代软件网络验证与授权管理系统
      </div>
      <h1>为你的软件<br>加上<span class="grad-text">工业级验证</span>与防护</h1>
      <p class="hero-desc">
        Nebula 网络验证提供从服务端、C++ / C# / Python SDK 到版本更新系统的一站式解决方案。
        加密信封通信、多层签名校验、壳级代码保护，让破解与盗版无从下手。
      </p>
      <div class="hero-actions">
        <a class="btn btn-primary btn-lg" href="#features">
          查看核心功能 <svg class="icon"><use href="#i-arrow"/></svg>
        </a>
        <a class="btn btn-outline btn-lg" href="#quickstart">
          <svg class="icon"><use href="#i-terminal"/></svg> 快速接入
        </a>
        <a class="btn btn-outline btn-lg" href="javascript:void(0)" onclick="getTrialKey(this)">
          <svg class="icon"><use href="#i-key"/></svg> 免费获取试用授权码
        </a>
      </div>
      <ul class="hero-points">
        <li><svg class="icon"><use href="#i-check"/></svg> Header-Only，一行代码接入</li>
        <li><svg class="icon"><use href="#i-check"/></svg> 多层加密与签名校验</li>
        <li><svg class="icon"><use href="#i-check"/></svg> 安装时授权码激活 · 在线升级门禁</li>
      </ul>
    </div>

    <div class="hero-code reveal delay-1">
      <div class="code-window">
        <div class="code-titlebar">
          <span class="dot dot-r"></span><span class="dot dot-y"></span><span class="dot dot-g"></span>
          <span class="code-file">main.cpp — 完整接入示例</span>
        </div>
<pre class="code-body"><code><span class="cm">// 唯一入口头文件，header-only 无需编译</span>
<span class="kw">#include</span> <span class="str">"nebula_sdk.hpp"</span>

<span class="kw">int</span> <span class="fn">main</span>() {
    <span class="cm">// 创建客户端：机器码留空自动生成；版本号每次发版必须更新</span>
    <span class="kw">auto</span> c = nebula::<span class="fn">createDefaultClient</span>(<span class="str">""</span>, <span class="str">"Windows"</span>, <span class="str">"1.0.3"</span>);

    <span class="cm">// 初始化：自动完成 ECDH 握手，取回登录方式 / 公告 / 版本策略</span>
    <span class="kw">auto</span> ir = c.<span class="fn">init</span>();
    <span class="kw">if</span> (!ir.ok) <span class="kw">return</span> <span class="num">1</span>;

    <span class="cm">// 内置提示：篡改 / 版本过低 / 维护中 自动弹窗，接入方零 UI 代码</span>
    <span class="kw">if</span> (!c.<span class="fn">enforceSelfIntegrity</span>()) <span class="kw">return</span> <span class="num">1</span>;
    <span class="kw">if</span> (!c.<span class="fn">versionAlert</span>())         <span class="kw">return</span> <span class="num">1</span>;
    c.<span class="fn">maintainAlert</span>();

    <span class="cm">// 登录：按服务端下发的 login_method 自动适配 账号密码 / 激活码 / 卡密</span>
    <span class="kw">auto</span> lr = c.<span class="fn">login</span>(account, secret);
    <span class="kw">if</span> (!lr.ok) <span class="kw">return</span> <span class="num">1</span>;

    <span class="cm">// 心跳保活：被踢 / 顶号 / 掉线 时回调，业务端回登录界面</span>
    c.<span class="fn">startHeartbeat</span>(lr.token, [](<span class="kw">int</span> code, <span class="kw">const</span> std::string&amp; msg,
                                       <span class="kw">const</span> nebula::HeartbeatInfo&amp; hb) {
        <span class="kw">if</span> (hb.kick || hb.force_offline || hb.need_relogin) { <span class="cm">/* 回登录界面 */</span> }
    });
}</code></pre>
      </div>
      <div class="hero-chip">
        <svg class="icon"><use href="#i-shield-check"/></svg>
        判定分支自动进入壳虚拟化区域
      </div>
    </div>
  </div>
</section>

<!-- ============ 数据条 ============ -->
<section class="stats" id="stats">
  <div class="container stats-grid">
    <div class="stat reveal"><div class="stat-num"><span id="statUsers" data-count="0">0</span></div><div class="stat-label">独立部署安装量 <span class="live-tag" id="statUsersLive" hidden><i></i>实时更新</span></div></div>
    <div class="stat reveal delay-1"><div class="stat-num"><span data-count="19">0</span> 个</div><div class="stat-label">C++ SDK 模块子头</div></div>
    <div class="stat reveal delay-2"><div class="stat-num"><span data-count="4">0</span> 项</div><div class="stat-label">配置即可完成 C++ SDK 接入</div></div>
    <div class="stat reveal delay-3"><div class="stat-num"><span data-count="4">0</span> 级</div><div class="stat-label">C++ SDK 壳保护强度可选</div></div>
    <div class="stat reveal delay-4"><div class="stat-num"><span data-count="5">0</span> 种</div><div class="stat-label">支付通道开箱即用</div></div>
  </div>
</section>

<!-- ============ 02 产品构成 ============ -->
<section class="section" id="product" data-form="1">
  <span class="ghost-num px-el gn-l" data-speed="-70" aria-hidden="true">02</span>
  <span class="float-tag px-el" data-speed="-30" style="top:12%; right:2%;">PHP 8 + MySQL</span>
  <span class="float-tag px-el" data-speed="-52" style="bottom:14%; right:5%;">Header-Only</span>
  <div class="container">
    <div class="sec-head reveal">
      <span class="sec-tag">产品构成</span>
      <h2>一套完整的<span class="grad-text">验证基础设施</span></h2>
      <p>四个组件各司其职，覆盖验证、防护、管理与分发的全链路需求。</p>
    </div>
    <div class="grid grid-4">
      <div class="card reveal">
        <div class="card-ico"><svg class="icon"><use href="#i-server"/></svg></div>
        <h3>验证服务端</h3>
        <p>核心业务 API：登录验证、卡密校验、心跳保活、公告下发、支付回调。PHP 8 + MySQL 架构，虚拟主机即可部署。</p>
      </div>
      <div class="card reveal delay-1">
        <div class="card-ico"><svg class="icon"><use href="#i-cpu"/></svg></div>
        <h3>官方 SDK</h3>
        <p>C++ Header-Only 设计，19 个模块子头、唯一入口头文件，加密信封、签名验签、防破解保护全部内置；官方 Python / C# SDK 协议同规格，其他语言可依协议文档直接对接。</p>
      </div>
      <div class="card reveal delay-2">
        <div class="card-ico"><svg class="icon"><use href="#i-gauge"/></svg></div>
        <h3>管理后台</h3>
        <p>软件、用户、卡密、版本、公告、订单、代理、设备与会话一站式管理。多管理员分权、两步验证与 CSRF 防护，面板支持一键自更新。</p>
      </div>
      <div class="card reveal delay-3">
        <div class="card-ico"><svg class="icon"><use href="#i-refresh"/></svg></div>
        <h3>版本更新系统</h3>
        <p>独立部署的版本分发服务，专为验证系统自身升级而生：后台一键检测，下载、校验、备份、替换全自动完成；支持更新门禁——部署站凭授权码获取升级包，升级通道仅向授权站点开放。</p>
      </div>
    </div>

    <div class="arch reveal">
      <div class="arch-title">系统架构一览</div>
      <svg class="arch-svg" viewBox="0 0 960 300" role="img" aria-label="Nebula 系统架构图">
        <defs>
          <linearGradient id="ag1" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#22d3ee"/><stop offset="1" stop-color="#818cf8"/>
          </linearGradient>
        </defs>
        <!-- 客户端 -->
        <g class="arch-node">
          <rect x="30" y="95" width="190" height="110" rx="14"/>
          <text x="125" y="128" class="an-t">客户端软件</text>
          <text x="125" y="152" class="an-s">C++ SDK（header-only）</text>
          <text x="125" y="174" class="an-s">壳保护 / 完整性自校验</text>
        </g>
        <!-- 加密通道 -->
        <g>
          <path d="M225 150 H 365" class="arch-link"/>
          <path d="M357 143 l10 7 -10 7" class="arch-link"/>
          <text x="295" y="132" class="an-l">加密信封</text>
          <text x="295" y="176" class="an-l2">AES-GCM + HMAC</text>
        </g>
        <!-- 服务端 API -->
        <g class="arch-node arch-node-core">
          <rect x="370" y="80" width="220" height="140" rx="14"/>
          <text x="480" y="115" class="an-t">验证服务端 API</text>
          <text x="480" y="140" class="an-s">登录 / 卡密 / 心跳 / 公告</text>
          <text x="480" y="162" class="an-s">请求签名验证 + 响应签名</text>
          <text x="480" y="184" class="an-s">支付回调（异步通知）</text>
          <text x="480" y="206" class="an-s">seq 防重放 / 证书指纹锁定</text>
        </g>
        <!-- 到数据库 -->
        <g>
          <path d="M480 225 V 248 M 480 248 H 292" class="arch-link"/>
          <path d="M300 241 l-10 7 10 7" class="arch-link"/>
          <text x="390" y="278" class="an-l">MySQL 业务数据</text>
        </g>
        <g class="arch-node">
          <rect x="72" y="248" width="200" height="40" rx="10"/>
          <text x="172" y="273" class="an-s">用户 / 卡密 / 版本 / 订单</text>
        </g>
        <!-- 到后台 -->
        <g>
          <path d="M590 150 H 690" class="arch-link"/>
          <path d="M682 143 l10 7 -10 7" class="arch-link"/>
          <text x="640" y="132" class="an-l">管理</text>
        </g>
        <g class="arch-node">
          <rect x="695" y="80" width="125" height="140" rx="14"/>
          <text x="757" y="115" class="an-t">管理后台</text>
          <text x="757" y="140" class="an-s">软件 / 用户</text>
          <text x="757" y="162" class="an-s">卡密 / 公告</text>
          <text x="757" y="184" class="an-s">订单 / 版本</text>
        </g>
        <!-- 到更新系统 -->
        <g>
          <path d="M822 150 H 855" class="arch-link"/>
          <path d="M847 143 l10 7 -10 7" class="arch-link"/>
        </g>
        <g class="arch-node">
          <rect x="863" y="80" width="90" height="140" rx="14"/>
          <text x="908" y="115" class="an-t">更新系统</text>
          <text x="908" y="140" class="an-s">版本分发</text>
          <text x="908" y="162" class="an-s">SHA-256</text>
          <text x="908" y="184" class="an-s">自动备份</text>
        </g>
      </svg>
    </div>
  </div>
</section>

<!-- ============ 03 核心功能 ============ -->
<section class="section section-alt" id="features" data-form="2">
  <span class="ghost-num px-el gn-r" data-speed="-70" aria-hidden="true">03</span>
  <span class="float-tag px-el" data-speed="-30" style="top:14%; left:2%;">init()</span>
  <span class="float-tag px-el" data-speed="-52" style="bottom:18%; left:5%;">heartbeat()</span>
  <div class="container">
    <div class="sec-head reveal">
      <span class="sec-tag">核心功能</span>
      <h2>验证业务<span class="grad-text">开箱即用</span></h2>
      <p>从登录到收费、从公告到更新，验证系统该有的能力都已备齐。</p>
    </div>
    <div class="grid grid-3">
      <div class="feature reveal">
        <div class="feature-ico"><svg class="icon"><use href="#i-lock"/></svg></div>
        <div class="feature-body">
          <h3>功能密钥</h3>
          <p>服务端只在登录成功后下发解密密钥：核心数据加密随程序分发，破解者 patch 掉登录也解不开数据。</p>
        </div>
      </div>
      <div class="feature reveal delay-1">
        <div class="feature-ico"><svg class="icon"><use href="#i-key"/></svg></div>
        <div class="feature-body">
          <h3>多方式登录</h3>
          <p>账号密码、卡密直登、账号 + 激活码三种方式；登录方式由服务端下发，客户端自动适配。</p>
        </div>
      </div>
      <div class="feature reveal delay-2">
        <div class="feature-ico"><svg class="icon"><use href="#i-list"/></svg></div>
        <div class="feature-body">
          <h3>卡密系统</h3>
          <p>后台批量生成与管理卡密，时长控制、状态流转、防爆破限制，支持自动发卡对接。</p>
        </div>
      </div>
      <div class="feature reveal">
        <div class="feature-ico"><svg class="icon"><use href="#i-fp"/></svg></div>
        <div class="feature-body">
          <h3>设备绑定</h3>
          <p>设备指纹、设备名与系统信息多维识别，硬件级绑定策略，账号共享一目了然。</p>
        </div>
      </div>
      <div class="feature reveal delay-1">
        <div class="feature-ico"><svg class="icon"><use href="#i-pulse"/></svg></div>
        <div class="feature-body">
          <h3>心跳保活</h3>
          <p>实时在线状态维持，支持异常下线、踢出登录、强制重新登录等多种会话事件。</p>
        </div>
      </div>
      <div class="feature reveal delay-2">
        <div class="feature-ico"><svg class="icon"><use href="#i-clock"/></svg></div>
        <div class="feature-body">
          <h3>离线宽限</h3>
          <p>服务端签发离线宽限票据，客户端本地验签后继续运行；断网与服务波动都不中断用户使用。</p>
        </div>
      </div>
      <div class="feature reveal">
        <div class="feature-ico"><svg class="icon"><use href="#i-bell"/></svg></div>
        <div class="feature-body">
          <h3>公告系统</h3>
          <p>弹窗公告、立即公告、列表公告三种形态，外加维护模式通知，触达及时且不打扰。</p>
        </div>
      </div>
      <div class="feature reveal delay-1">
        <div class="feature-ico"><svg class="icon"><use href="#i-package"/></svg></div>
        <div class="feature-body">
          <h3>版本管理</h3>
          <p>版本登记与对比检测，区分强制更新与可选更新：强制更新自动执行，可选更新仅提示。</p>
        </div>
      </div>
      <div class="feature reveal delay-2">
        <div class="feature-ico"><svg class="icon"><use href="#i-card"/></svg></div>
        <div class="feature-body">
          <h3>支付与自动发卡</h3>
          <p>微信支付官方（Native 扫码 / 微信内 JSAPI）、支付宝、彩虹易支付、码支付、V免签五种通道，个人免签也能接；回调验签后自动开通或续期，订单全程落库。</p>
        </div>
      </div>
      <div class="feature reveal">
        <div class="feature-ico"><svg class="icon"><use href="#i-layers"/></svg></div>
        <div class="feature-body">
          <h3>多软件支持</h3>
          <p>一个后台管理多个软件，每个软件独立密钥、独立配置、独立用户体系，互不干扰。</p>
        </div>
      </div>
      <div class="feature reveal delay-1">
        <div class="feature-ico"><svg class="icon"><use href="#i-code"/></svg></div>
        <div class="feature-body">
          <h3>三端 SDK</h3>
          <p>C++ / Python / C# 三套 SDK 协议同规格：C++ Header-Only 一行 include 接入，Python 改一个 config.py 跑起来，附示例工程与完整接入文档。</p>
        </div>
      </div>
      <div class="feature reveal delay-2">
        <div class="feature-ico"><svg class="icon"><use href="#i-file-check"/></svg></div>
        <div class="feature-body">
          <h3>完整性自校验</h3>
          <p>SDK 启动即校验自身文件哈希，被篡改或二次打包立即拒绝运行，校验数据仅随最新版本下发。</p>
        </div>
      </div>
      <div class="feature reveal">
        <div class="feature-ico"><svg class="icon"><use href="#i-scan"/></svg></div>
        <div class="feature-body">
          <h3>风控防爆破</h3>
          <p>卡密尝试、登录失败、IP 频率多维限流，命中即锁定，配合黑白名单让撞库爆破无从下手。</p>
        </div>
      </div>
      <div class="feature reveal delay-1">
        <div class="feature-ico"><svg class="icon"><use href="#i-eye-off"/></svg></div>
        <div class="feature-body">
          <h3>后台纵深防护</h3>
          <p>管理入口伪装 404、管理员两步验证、会话绑定与 IP 级防爆破，后台多层纵深防御。</p>
        </div>
      </div>
      <div class="feature reveal delay-2">
        <div class="feature-ico"><svg class="icon"><use href="#i-gauge"/></svg></div>
        <div class="feature-body">
          <h3>数据统计概览</h3>
          <p>注册、在线、订单、版本分布一屏总览，另有数据大屏与统计趋势，安装量与 API 调用趋势图表化呈现。</p>
        </div>
      </div>
      <div class="feature reveal">
        <div class="feature-ico"><svg class="icon"><use href="#i-users"/></svg></div>
        <div class="feature-body">
          <h3>代理 · 经销商体系</h3>
          <p>代理注册码、代理充值卡与代理入口，分账单价与充值记录后台可查，适合分销与推广场景。</p>
        </div>
      </div>
      <div class="feature reveal delay-1">
        <div class="feature-ico"><svg class="icon"><use href="#i-monitor"/></svg></div>
        <div class="feature-body">
          <h3>设备与会话管理</h3>
          <p>设备绑定记录、设备封禁与解绑、在线会话列表与一键踢下线，账号共享与设备异常一目了然。</p>
        </div>
      </div>
      <div class="feature reveal delay-2">
        <div class="feature-ico"><svg class="icon"><use href="#i-shield"/></svg></div>
        <div class="feature-body">
          <h3>日志审计与安全体检</h3>
          <p>操作日志、审计明细与安全体检报告；响应签名密钥、离线宽限密钥一键轮换，风险项给出修复建议。</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============ 04 安全防护 ============ -->
<section class="section" id="security" data-form="3">
  <span class="ghost-num px-el gn-l" data-speed="-70" aria-hidden="true">04</span>
  <span class="float-tag px-el" data-speed="-30" style="top:20%; right:3%;">ES256 验签</span>
  <span class="float-tag px-el" data-speed="-52" style="bottom:24%; right:6%;">TLS 指纹</span>
  <div class="container">
    <div class="sec-head reveal">
      <span class="sec-tag">安全防护</span>
      <h2>五层纵深防御<span class="grad-text">体系</span></h2>
      <p>从传输到代码、从客户端到服务端，破解者需要同时突破所有防线。</p>
    </div>

    <div class="defense">
      <div class="defense-stack">
        <div class="defense-layer l1 reveal"><div class="dl-head"><svg class="icon"><use href="#i-lock"/></svg><span>传输加密层</span></div><p>AES-GCM 加密信封：请求与响应全程密文传输，抓包只能看到乱码。</p></div>
        <div class="defense-layer l2 reveal delay-1"><div class="dl-head"><svg class="icon"><use href="#i-shield-check"/></svg><span>会话握手层</span></div><p>ECDH P-256 临时握手协商会话密钥 + seq 单调防重放，客户端零静态对称机密。</p></div>
        <div class="defense-layer l3 reveal delay-2"><div class="dl-head"><svg class="icon"><use href="#i-globe"/></svg><span>响应验签层</span></div><p>服务端用 ES256（ECDSA P-256，环境不支持时回落 RS256）私钥签名响应，客户端内置公钥验签，伪造服务器与中间人攻击失效。</p></div>
        <div class="defense-layer l4 reveal delay-3"><div class="dl-head"><svg class="icon"><use href="#i-cpu"/></svg><span>代码保护层</span></div><p>VMProtect / Themida 壳标记四档强度、不透明谓词与虚假分支混淆、字符串加密混淆。</p></div>
        <div class="defense-layer l5 reveal delay-4"><div class="dl-head"><svg class="icon"><use href="#i-scan"/></svg><span>完整性校验层</span></div><p>SDK 运行时自我完整性检测防内存补丁，服务端文件基线校验防源码篡改。</p></div>
      </div>

      <div class="defense-side">
        <div class="mini-card reveal">
          <div class="mini-ico"><svg class="icon"><use href="#i-eye-off"/></svg></div>
          <h4>调试与模拟对抗</h4>
          <p>客户端锁定服务端证书指纹，透明代理与中间人抓包失效；服务端结合设备指纹识别模拟器、虚拟机与机器码伪造，异常设备可直接封禁。</p>
        </div>
        <div class="mini-card reveal delay-1">
          <div class="mini-ico"><svg class="icon"><use href="#i-zap"/></svg></div>
          <h4>授权门卫 guardAuth</h4>
          <p>验证判定分支自动收进壳虚拟化区域，接入层不再暴露一眼可补丁的裸跳转。</p>
        </div>
        <div class="mini-card reveal delay-2">
          <div class="mini-ico"><svg class="icon"><use href="#i-wrench"/></svg></div>
          <h4>强度自由取舍</h4>
          <p>四档保护强度按函数粒度标记：高频路径用变异保性能，关键判定用虚拟化保安全。</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============ 05 管理后台 ============ -->
<section class="section section-alt" id="admin" data-form="4">
  <span class="ghost-num px-el gn-r" data-speed="-70" aria-hidden="true">05</span>
  <span class="float-tag px-el" data-speed="-30" style="top:16%; left:2%;">CSRF Token</span>
  <span class="float-tag px-el" data-speed="-52" style="bottom:22%; left:5%;">会话绑定</span>
  <div class="container">
    <div class="sec-head reveal">
      <span class="sec-tag">管理后台</span>
      <h2>一切管理操作<span class="grad-text">尽在掌握</span></h2>
      <p>浏览器打开即用，无需安装客户端；随机管理目录名 + 入口令牌 + 管理员两步验证保护管理入口。</p>
    </div>

    <div class="admin-wrap">
      <div class="admin-shot reveal">
        <div class="browser">
          <div class="browser-bar">
            <span class="dot dot-r"></span><span class="dot dot-y"></span><span class="dot dot-g"></span>
            <span class="browser-url">https://your-domain.com/admin-panel</span>
          </div>
          <div class="browser-body">
            <aside class="fake-side">
              <span class="fs-item on"><svg class="icon"><use href="#i-gauge"/></svg>数据概览</span>
              <span class="fs-item"><svg class="icon"><use href="#i-layers"/></svg>软件管理</span>
              <span class="fs-item"><svg class="icon"><use href="#i-users"/></svg>用户管理</span>
              <span class="fs-item"><svg class="icon"><use href="#i-key"/></svg>卡密管理</span>
              <span class="fs-item"><svg class="icon"><use href="#i-monitor"/></svg>设备与会话</span>
              <span class="fs-item"><svg class="icon"><use href="#i-card"/></svg>发卡网订单</span>
              <span class="fs-item"><svg class="icon"><use href="#i-package"/></svg>版本管理</span>
              <span class="fs-item"><svg class="icon"><use href="#i-bell"/></svg>公告管理</span>
              <span class="fs-item"><svg class="icon"><use href="#i-refresh"/></svg>系统更新</span>
            </aside>
            <div class="fake-main">
              <div class="fake-cards">
                <div class="fk"><span class="fk-num">1,024</span><span class="fk-lb">注册用户</span></div>
                <div class="fk"><span class="fk-num">368</span><span class="fk-lb">今日在线</span></div>
                <div class="fk"><span class="fk-num">57</span><span class="fk-lb">今日订单</span></div>
                <div class="fk"><span class="fk-num">v2.66.4.8</span><span class="fk-lb">当前版本</span></div>
              </div>
              <div class="fake-chart">
                <svg viewBox="0 0 400 110" preserveAspectRatio="none" aria-hidden="true">
                  <defs>
                    <linearGradient id="ag1" x1="0" y1="0" x2="1" y2="1">
                      <stop offset="0" stop-color="#22d3ee"/><stop offset="1" stop-color="#818cf8"/>
                    </linearGradient>
                  </defs>
                  <polyline points="0,88 40,80 80,84 120,62 160,70 200,44 240,52 280,30 320,38 360,20 400,26"
                            fill="none" stroke="url(#ag1)" stroke-width="3" stroke-linecap="round"/>
                  <polyline points="0,88 40,80 80,84 120,62 160,70 200,44 240,52 280,30 320,38 360,20 400,26 400,110 0,110"
                            fill="rgba(34,211,238,.08)" stroke="none"/>
                </svg>
                <span class="fake-chart-lb">近 30 日验证请求趋势（示意）</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="admin-list">
        <ul class="check-list reveal">
          <li><svg class="icon"><use href="#i-check"/></svg>数据概览与数据大屏：注册、在线、订单、版本分布一屏总览</li>
          <li><svg class="icon"><use href="#i-check"/></svg>用户管理：封禁、解封、设备重置、到期调整、批量与导入导出</li>
          <li><svg class="icon"><use href="#i-check"/></svg>卡密管理：批量生成、时长与权限配置、状态追踪与作废</li>
          <li><svg class="icon"><use href="#i-check"/></svg>发卡网管理：商品上架、支付通道配置、订单与发卡记录</li>
          <li><svg class="icon"><use href="#i-check"/></svg>代理 · 经销商：代理注册码、充值卡、代理入口与分账单价</li>
          <li><svg class="icon"><use href="#i-check"/></svg>设备与会话：设备绑定记录、设备封禁与解绑、在线会话踢下线</li>
          <li><svg class="icon"><use href="#i-check"/></svg>公告与版本：弹窗 / 立即 / 列表公告、维护模式、强制与可选更新</li>
          <li><svg class="icon"><use href="#i-check"/></svg>官网内容：官网模板、留言板、反馈回复、套餐、卖家与截图</li>
          <li><svg class="icon"><use href="#i-check"/></svg>日志与审计：操作日志、审计明细与安全体检报告</li>
        </ul>
        <ul class="check-list reveal delay-1">
          <li><svg class="icon"><use href="#i-check"/></svg>入口隐匿：随机管理目录名 + 入口令牌，无令牌输出仿真 404</li>
          <li><svg class="icon"><use href="#i-check"/></svg>两步验证：管理员动态码（TOTP）与一次性恢复码</li>
          <li><svg class="icon"><use href="#i-check"/></svg>会话绑定：会话密钥仅登录响应下发一次，盗用 Cookie 无效</li>
          <li><svg class="icon"><use href="#i-check"/></svg>登录防护：失败次数锁定 + 请求令牌（CSRF）校验</li>
          <li><svg class="icon"><use href="#i-check"/></svg>多管理员分权：角色权限矩阵，操作员与超管权限分离</li>
          <li><svg class="icon"><use href="#i-check"/></svg>一键自更新：对接独立更新系统，验证系统后台一键升级</li>
          <li><svg class="icon"><use href="#i-check"/></svg>文件完整性：基准文件 SHA-256 基线扫描，异常即告警</li>
          <li><svg class="icon"><use href="#i-check"/></svg>数据备份：自动 / 手动备份模式，按周期节流执行</li>
          <li><svg class="icon"><use href="#i-check"/></svg>密钥轮换：响应签名密钥与离线宽限密钥一键轮换</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- ============ 06 配套前端 ============ -->
<section class="section" id="frontends" data-form="5">
  <span class="ghost-num px-el gn-l" data-speed="-70" aria-hidden="true">06</span>
  <span class="float-tag px-el" data-speed="-30" style="top:16%; left:2%;">自动发卡</span>
  <span class="float-tag px-el" data-speed="-52" style="bottom:20%; right:2%;">支付回调验签</span>
  <div class="container">
    <div class="sec-head reveal">
      <span class="sec-tag">配套前端</span>
      <h2>门户官网与发卡网<span class="grad-text">开箱即用</span></h2>
      <p>每个软件可配置独立的门户官网，发卡网支持在线支付自动发卡——随系统一同部署，无需额外开发。</p>
    </div>
    <div class="front-grid">
      <figure class="front-card reveal">
        <div class="front-shot"><img src="assets/img/portal-web.jpeg" alt="软件门户官网示例" loading="lazy"></div>
        <figcaption>
          <div class="front-txt">
            <span class="front-tag">门户官网</span>
            <p>每个软件独立官网：公告、功能介绍、价格套餐、购买流程、常见问题一页呈现。</p>
          </div>
          <a class="btn btn-outline" href="https://yz.baige.fun/web/" target="_blank" rel="noopener">访问示例 <svg class="icon"><use href="#i-arrow"/></svg></a>
        </figcaption>
      </figure>
      <figure class="front-card reveal delay-1">
        <div class="front-shot"><img src="assets/img/portal-shop.jpeg" alt="发卡网示例" loading="lazy"></div>
        <figcaption>
          <div class="front-txt">
            <span class="front-tag">发卡网</span>
            <p>在线选购套餐，微信 / 支付宝支付后自动发卡，订单与卡密全程后台可查。</p>
          </div>
          <a class="btn btn-outline" href="https://yz.baige.fun/shop/" target="_blank" rel="noopener">访问示例 <svg class="icon"><use href="#i-arrow"/></svg></a>
        </figcaption>
      </figure>
    </div>
  </div>
</section>

<!-- ============ 07 自动更新 ============ -->
<section class="section section-alt" id="update" data-form="7">
  <span class="ghost-num px-el gn-r" data-speed="-70" aria-hidden="true">07</span>
  <div class="container">
    <div class="sec-head reveal">
      <span class="sec-tag">自动更新</span>
      <h2>验证系统升级<span class="grad-text">一键完成</span></h2>
      <p>独立更新系统为验证服务端项目提供版本分发与一键升级——升级在管理后台点击完成，全程无需登录服务器。</p>
    </div>

    <div class="flow reveal">
      <div class="flow-step">
        <div class="flow-num">1</div>
        <div class="flow-ico"><svg class="icon"><use href="#i-package"/></svg></div>
        <h4>打包发布</h4>
        <p>打包脚本自动生成版本清单与更新包，排除配置等敏感文件。</p>
      </div>
      <div class="flow-arrow"><svg class="icon"><use href="#i-arrow"/></svg></div>
      <div class="flow-step">
        <div class="flow-num">2</div>
        <div class="flow-ico"><svg class="icon"><use href="#i-download"/></svg></div>
        <h4>后台一键升级</h4>
        <p>管理后台自动检测新版本，点击即可开始升级，无需任何命令行操作。</p>
      </div>
      <div class="flow-arrow"><svg class="icon"><use href="#i-arrow"/></svg></div>
      <div class="flow-step">
        <div class="flow-num">3</div>
        <div class="flow-ico"><svg class="icon"><use href="#i-file-check"/></svg></div>
        <h4>校验落盘</h4>
        <p>SHA-256 完整性校验通过后才解压写入，损坏包一律拒收。</p>
      </div>
      <div class="flow-arrow"><svg class="icon"><use href="#i-arrow"/></svg></div>
      <div class="flow-step">
        <div class="flow-num">4</div>
        <div class="flow-ico"><svg class="icon"><use href="#i-refresh"/></svg></div>
        <h4>备份替换</h4>
        <p>旧版本自动备份，替换后自动刷新系统版本号，失败可回滚。</p>
      </div>
    </div>

    <div class="grid grid-3 update-points">
      <div class="mini-card reveal">
        <div class="mini-ico"><svg class="icon"><use href="#i-lock"/></svg></div>
        <h4>配置永不覆盖</h4>
        <p>更新包自动排除 config 等关键目录，生产配置与密钥全程无虞。</p>
      </div>
      <div class="mini-card reveal delay-1">
        <div class="mini-ico"><svg class="icon"><use href="#i-clock"/></svg></div>
        <h4>更新失败可回滚</h4>
        <p>每次升级前自动备份当前版本，异常时恢复到上一个可用状态。</p>
      </div>
      <div class="mini-card reveal delay-2">
        <div class="mini-ico"><svg class="icon"><use href="#i-globe"/></svg></div>
        <h4>全程无需登服务器</h4>
        <p>升级在管理后台点击完成，无需 FTP 或 SSH，虚拟主机环境同样适用。</p>
      </div>
    </div>
  </div>
</section>

<!-- ============ 08 快速接入 ============ -->
<section class="section" id="quickstart" data-form="6">
  <span class="ghost-num px-el gn-l" data-speed="-70" aria-hidden="true">08</span>
  <span class="float-tag px-el" data-speed="-30" style="top:14%; right:2%;">6 项配置</span>
  <span class="float-tag px-el" data-speed="-52" style="bottom:16%; right:5%;">guardAuth()</span>
  <div class="container">
    <div class="sec-head reveal">
      <span class="sec-tag">快速接入</span>
      <h2>五步完成<span class="grad-text">从零到上线</span></h2>
      <p>以下为官方 C++ SDK 的接入流程——Header-Only 设计让接入像引入一个头文件一样轻量；Python SDK 改一个 config.py 即可跑起来，其他语言依协议文档对接。</p>
    </div>

    <div class="steps">
      <ol class="steps-list reveal">
        <li>
          <div class="step-t"><svg class="icon"><use href="#i-server"/></svg>创建软件</div>
          <p>管理后台创建软件，获得 AppKey 与响应验签公钥。</p>
        </li>
        <li>
          <div class="step-t"><svg class="icon"><use href="#i-code"/></svg>引入 SDK</div>
          <p>将 sdk 目录加入工程，一行 include 引入唯一入口头文件。</p>
        </li>
        <li>
          <div class="step-t"><svg class="icon"><use href="#i-wrench"/></svg>填写配置</div>
          <p>仅需修改 config.hpp 一个文件的四项配置即可完成对接。</p>
        </li>
        <li>
          <div class="step-t"><svg class="icon"><use href="#i-terminal"/></svg>调用接口</div>
          <p>init 初始化 + login 登录，guardAuth 自动分流成功与失败。</p>
        </li>
        <li>
          <div class="step-t"><svg class="icon"><use href="#i-shield"/></svg>加壳发布</div>
          <p>编译后使用 VMProtect 等壳处理，代码内标记自动生效。</p>
        </li>
      </ol>

      <div class="code-window code-lg reveal delay-1">
        <div class="code-titlebar">
          <span class="dot dot-r"></span><span class="dot dot-y"></span><span class="dot dot-g"></span>
          <span class="code-file">nebula/client/config.hpp — 接入唯一需要修改的文件（示例）</span>
        </div>
<pre class="code-body"><code><span class="cm">// —— 接入仅需修改以下四项（3.1 协议：客户端零静态对称密钥） ——</span>
<span class="kw">inline const std::string</span> kApiUrl         = NEBULA_STR(<span class="str">"https://api.your-domain.com/api/index.php"</span>);
<span class="kw">inline const SecureString</span> kAppKey        { NEBULA_STR(<span class="str">"后台「软件管理」行点复制获得"</span>) };
<span class="kw">inline const std::string</span> kRespSignPubKey = NEBULA_STR(<span class="str">"-----BEGIN PUBLIC KEY-----\\n...\\n-----END PUBLIC KEY-----\\n"</span>);  <span class="cm">// 必填</span>
<span class="kw">inline const std::string</span> kTlsCertSha256  = NEBULA_STR(<span class="str">"服务端证书 SHA-256 指纹（可选，仅在 https 生效）"</span>);
<span class="cm">// 通信密钥由 ECDH P-256 握手临时协商，3.0 的 AES_KEY / SIGN_SALT 已彻底移除</span></code></pre>
      </div>
    </div>

    <!-- 官方示例客户端预览 -->
    <div class="client-shots reveal">
      <div class="cs-head"><svg class="icon"><use href="#i-monitor"/></svg>官方示例客户端预览（C++ SDK 示例登录器）</div>
      <div class="cs-grid">
        <figure class="cs-item">
          <img src="assets/img/client-login-card.png" alt="卡密直登模式示例" loading="lazy">
          <figcaption><span class="cs-tag">卡密直登</span>无需注册，输入卡密直接登录</figcaption>
        </figure>
        <figure class="cs-item">
          <img src="assets/img/client-login-account.png" alt="账号密码登录模式示例" loading="lazy">
          <figcaption><span class="cs-tag">账号密码</span>经典登录，支持设备绑定</figcaption>
        </figure>
        <figure class="cs-item">
          <img src="assets/img/client-login-register.png" alt="激活码注册模式示例" loading="lazy">
          <figcaption><span class="cs-tag">激活码注册</span>账号 + 激活码一步完成注册</figcaption>
        </figure>
      </div>
      <p class="cs-note">C++ SDK 示例客户端内置完整登录界面与公告展示，三种登录方式按后台配置自动适配，接入方零 UI 代码。</p>
    </div>

    <!-- SDK 获取说明（不再提供公开下载） -->
    <div class="dl-grid">
      <div class="sdk-dl wide reveal">
        <div class="sdk-dl-info">
          <div class="sdk-dl-ico"><svg class="icon"><use href="#i-code"/></svg></div>
          <div>
            <h4>SDK 获取方式</h4>
            <p>官方 C++ / Python / C# 三套协议同规格 SDK 现改为<strong>定向分发</strong>，不再提供公开下载。接入方请依据 <a href="docs.html#d3-h0">HTTP 接口协议文档</a>自行对接，或联系我们获取对应语言的 SDK 分发包（含完整接入文档与示例工程）。</p>
            <span class="sdk-meta">C++（Header-Only · MSVC/MinGW）· Python（3.10 ~ 3.13）· C#（.NET 10 / WinForms）</span>
          </div>
        </div>
        <a class="btn btn-primary btn-lg" href="docs.html">
          <svg class="icon"><use href="#i-code"/></svg> 查看接入文档
        </a>
      </div>
    </div>

    <div class="env reveal">
      <div class="env-title"><svg class="icon"><use href="#i-server"/></svg>运行环境要求</div>
      <div class="env-grid">
        <div class="env-item"><span class="env-k">服务端语言</span><span class="env-v">PHP 8.0 及以上</span></div>
        <div class="env-item"><span class="env-k">数据库</span><span class="env-v">MySQL 5.7+（建议 8.0）</span></div>
        <div class="env-item"><span class="env-k">部署环境</span><span class="env-v">虚拟主机 / 云服务器均可</span></div>
        <div class="env-item"><span class="env-k">网络要求</span><span class="env-v">建议启用 HTTPS 域名</span></div>
        <div class="env-item"><span class="env-k">官方 SDK</span><span class="env-v">C++（Windows · MSVC / MinGW）· Python（3.10 ~ 3.13）· C#（.NET 10 / WinForms）</span></div>
        <div class="env-item"><span class="env-k">多语言接入</span><span class="env-v">任何语言依协议文档均可对接</span></div>
      </div>
    </div>
  </div>
</section>

<!-- ============ 09 常见问题 ============ -->
<section class="section section-alt" id="faq" data-form="3">
  <span class="ghost-num px-el gn-r" data-speed="-70" aria-hidden="true">09</span>
  <span class="float-tag px-el" data-speed="-30" style="top:18%; left:2%;">离线宽限</span>
  <span class="float-tag px-el" data-speed="-52" style="bottom:20%; left:5%;">自动更新</span>
  <div class="container container-narrow">
    <div class="sec-head reveal">
      <span class="sec-tag">常见问题</span>
      <h2>你可能想了解<span class="grad-text">这些</span></h2>
      <p>更多接口与接入细节见 <a href="docs.html">开发文档</a>。</p>
    </div>
    <div class="faq-list reveal">
      <details class="faq">
        <summary>接入需要改多少代码？<svg class="icon faq-arrow"><use href="#i-arrow"/></svg></summary>
        <p>客户端只需一行 include 引入头文件、修改 config.hpp 四项配置（kAppKey / kApiUrl / kRespSignPubKey / kTlsCertSha256），再调用 init 与 login 两个接口，并传入客户端版本号。C++ SDK 附带完整的示例登录器工程可供参考。</p>
      </details>
      <details class="faq">
        <summary>支持哪些加壳工具？<svg class="icon faq-arrow"><use href="#i-arrow"/></svg></summary>
        <p>C++ SDK 内置 VMProtect 与 Themida / WinLicense 标记适配，编译时自动探测已安装的壳 SDK；其他壳预留了自定义挂载点。未加壳时所有标记宏为空，零开销。</p>
      </details>
      <details class="faq">
        <summary>壳保护会影响运行性能吗？<svg class="icon faq-arrow"><use href="#i-arrow"/></svg></summary>
        <p>C++ SDK 的保护标记按函数粒度自由取舍：网络收发等高频路径默认使用变异（Mutation），开销极小；仅授权判定等最关键分支使用虚拟化。合理标记下用户几乎无感。</p>
      </details>
      <details class="faq">
        <summary>可以管理多个软件吗？<svg class="icon faq-arrow"><use href="#i-arrow"/></svg></summary>
        <p>可以。一个后台管理任意多个软件，每个软件拥有独立的 AppKey、加密密钥、用户体系与公告配置，数据完全隔离。</p>
      </details>
      <details class="faq">
        <summary>更新会覆盖我的服务器配置吗？<svg class="icon faq-arrow"><use href="#i-arrow"/></svg></summary>
        <p>不会。更新包在打包与安装两个环节都会自动排除 config 等关键目录；升级前自动备份当前版本，并通过 SHA-256 校验确保包完整，异常可回滚。</p>
      </details>
      <details class="faq">
        <summary>授权激活码是什么？怎么获取？<svg class="icon faq-arrow"><use href="#i-arrow"/></svg></summary>
        <p>授权激活码用于把你的部署站域名与官方授权绑定（一码一域）：安装向导第一页填入即可自动激活，激活码会写入 config.php。可在首页点击「免费获取试用授权码」，或联系管理员签发正式授权码。未激活不影响系统任何功能使用。</p>
      </details>
      <details class="faq">
        <summary>更新门禁开启后，未激活的站点怎么办？<svg class="icon faq-arrow"><use href="#i-arrow"/></svg></summary>
        <p>门禁开启后，未激活（或授权过期/停用）的部署站调用「系统更新」时会收到明确提示，无法在线获取新版本包；完成激活后立即恢复。门禁默认关闭，存量部署不受影响。</p>
      </details>
      <details class="faq">
        <summary>我的软件不是 C++ 写的能用吗？<svg class="icon faq-arrow"><use href="#i-arrow"/></svg></summary>
        <p>可以。除官方 C++ / Python / C# SDK 外，系统提供完整开放的 HTTP 协议文档（含加密信封格式、签名算法与业务码定义），Java、Go、易语言等任何具备加密与网络能力的语言都可以按文档对接。</p>
      </details>
    </div>
  </div>
</section>

<!-- ============ 联系 / CTA ============ -->
<section class="cta" id="contact" data-form="0">
  <div class="container cta-inner reveal">
    <h2>准备好为软件加上<span class="grad-text">专业防护</span>了吗？</h2>
    <p>前往 Gitee 开源仓库获取完整项目，十分钟内完成你的第一次验证接入。</p>
    <div class="cta-actions">
      <a class="btn btn-primary btn-lg" href="https://gitee.com/xinia/online-verification" target="_blank" rel="noopener">
        <svg class="icon"><use href="#i-package"/></svg> 获取项目
      </a>
      <a class="btn btn-outline btn-lg" href="docs.html">
        <svg class="icon"><use href="#i-doc"/></svg> 查看接入指南
      </a>
      <a class="btn btn-outline btn-lg" href="javascript:void(0)" onclick="getTrialKey(this)">
        <svg class="icon"><use href="#i-key"/></svg> 免费获取试用授权码
      </a>
    </div>
    <div class="cta-contact">
      <span><svg class="icon"><use href="#i-mail"/></svg> contact@your-domain.com</span>
      <span><svg class="icon"><use href="#i-chat"/></svg> 商务合作请说明来意</span>
    </div>
  </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
