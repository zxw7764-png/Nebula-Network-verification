# Nebula 模板开发文档

> 📚 本文属 Nebula 文档中心，主索引见 [README.md](../README.md)，完整指南见 [GUIDE.md](GUIDE.md)；
> 其他文档：[架构设计](ARCHITECTURE.md) · [API 接口](API.md) · [报文示例](API_RAW_EXAMPLES.md)（SDK 接入文档随仓库发行处提供）
> （本文件已从 `web/Template/README.md` 迁入 docs/，原位置留有跳转壳）

给官网（portal）和发卡网（shop）做界面模板。**官网和发卡网都直接从 `web/Template/` 识别并加载模板**——在这里建好文件夹，后台模板管理立刻出现，前台立刻能选，改完刷新即生效，无需同步、无需碰其它目录。

---

## 一、放哪里：`web/Template/<模板名>/`

**唯一推荐做法**：在 `web/Template/` 下建一个以模板名命名的文件夹：

```
web/Template/
└─ wzry/                 ← 模板名 = 文件夹名（小写字母/数字/下划线）
   ├─ web.css            ← 官网样式（有则官网识别此模板，可省略）
   ├─ shop.css           ← 发卡网样式（有则发卡识别此模板，可省略）
   ├─ game.html          ← 自带小游戏页面（可选，官网 + 发卡网通用）
   ├─ game.js            ← 游戏逻辑（game.html 自己引）
   ├─ interact.js        ← 交互音效脚本（可选，官网 + 发卡网都自动加载，见第六节）
   ├─ sfx/               ← 音效文件（可选）
   │  ├─ shoot.mp3
   │  └─ hit.mp3
   ├─ sections/          ← 官网自定义区块片段（可选，配合官网 Layout 使用）
   │  └─ banner.html
   ├─ shop-sections/     ← 发卡网自定义区块片段（可选，配合发卡网 Layout 使用）
   │  └─ promo.html
   └─ bg.png …           ← 其它任何文件，game.html 里直接写相对路径引用即可
```

识别规则：
- 文件夹里有 `web.css` → 官网模板管理出现此模板；前台官网加载 `Template/<名>/web.css`
- 文件夹里有 `shop.css` → 发卡网模板管理出现此模板；前台发卡加载 `../Template/<名>/shop.css`
- 有 `game.html` → 前台右下角**直接常驻显示悬浮游戏面板**（iframe 加载，两端通用，无 🎮 按钮）
- 两端样式通常不一样，一般两个文件都放；只想做一端就只放对应那个

**改完没生效？** 模板 CSS 的加载地址带版本号（`?v=版本.文件修改时间`），保存文件后自动变化，浏览器会重新拉取——**直接刷新页面即可**。如果仍旧显示默认深空，按顺序检查：

1. 文件夹名是否和 CSS 里的 `body.ui-<名>` 完全一致（最容易踩的坑，见第二节）；
2. 该端有没有对应文件（官网要 `web.css`、发卡要 `shop.css`，注意别写成 `web.css.css` 这种多后缀）；
3. 后台模板管理里有没有点「保存」——只在下拉里选了但没保存，库里仍是旧值；
4. 「分软件模板」有没有给当前软件设了覆盖（覆盖优先于全局）；发卡网访客软件由 `?app=` 决定；
5. 仍是旧的：强制刷新（Ctrl+F5），或确认改的是不是 `_shared.css` 之外的模板文件。

**兼容**：各端 assets 模板文件夹（`web/assets/css/templates/`、`shop/assets/templates/`）里的旧式模板继续有效（单文件 `<id>.css` 或文件夹 `<id>/<id>.css`）。与 `web/Template` 同名时，**`web/Template` 优先**。`_` 开头的文件夹不识别；`_shared.css` 是两端共享修正样式（浅色容器修正 + 游戏面板骨架），所有模板都会先加载它。

---

## 二、命名的铁律

**文件夹名 = CSS 选择器里的 id**。模板机制是给 `<body>` 加 `ui-<文件夹名>` 类：

```
web/Template/wzry/  →  body 加 class "ui-wzry"  →  CSS 里全部选择器写 body.ui-wzry
```

文件名叫别的（比如 `123`）但选择器写 `ui-wzry`，一条样式都匹配不上，前台会看起来像默认主题——这是最容易踩的坑。

- id 只允许 `小写字母 / 数字 / 下划线`（同 CSS 类名字符集）。
- 小游戏排行榜的 `game` 字段 = 文件夹名，自动按模板分榜。

---

## 三、写样式

`<id>.css` 加载在 `_shared.css` 之后，可以覆盖共享样式。模板自带整套配色（CSS 变量）+ 装饰背景，**激活后优先于后台主题色 / 背景图**。

变量基座（`_shared.css` / site.css 里已有定义，模板里按主题重定义）：

```css
body.ui-wzry {
    --bg: #0a0610;        --bg-soft: #1a1224;
    --panel: #1e1428;     --panel-2: #241830;
    --border: #4a3520;    --border-2: #8a6d3b;
    --text: #f0e6d2;      --text-sub: #d4b878;   --text-strong: #f5e6c8;
    --primary: #c8102e;   --primary-rgb: 200, 16, 46;
}
```

---

## 四、⚠️ 霓虹禁忌（重要，审核也会按这个查）

有些区块**不允许出现霓虹效果**。默认主题的青色 `rgba(34,211,238,…)` / `#22d3ee` / 紫色光晕是默认深空主题的专属装饰，模板里出现就是残留 bug。

| 区块 | 要求 |
|---|---|
| 面板 / 卡片 / 价格套餐卡 / 商品卡 / 表单 / 弹窗 / 公告条 | **必须不透明底色**（`var(--panel)` / `var(--panel-2)` / `var(--bg-soft)` 或 color-mix 不透明结果），禁止 `rgba(var(--primary-rgb), .1~.3)` 这类半透明主色大面积铺底 |
| 分类页签 / 页签选中态 | 选中用实底主色或实底面板色，文字用白/浅色，禁止发光文字 |
| 标题 / 正文 | 禁止大面积 `text-shadow` 霓虹发光；小面积点缀（徽章、角标）可用 1~2px 微光 |
| 按钮 / 高亮 / 边框 | 主色只做小面积点缀；禁止多层 `box-shadow` 光晕叠满屏 |
| 浅色系模板 | 面板一律不透明浅底 + 深色文字，半透明主色只允许出现在徽章 / 状态点等 <20px 的小元素 |
| 动画背景 | 装饰层（云 / 星点 / 山水）`z-index:0` + `pointer-events:none`，不得盖住内容与版权 |

简单判断：**大面积 = 实底不透明；发光 = 只许小面积点缀**。

---

## 五、自带小游戏（可选）

文件夹里放 `game.html`（+ `game.js` / 图片 / 音效），前台**右下角直接常驻显示悬浮游戏面板**，iframe 加载你的游戏页面，替代内置小游戏。发卡网同样支持。**不出现 🎮 按钮，面板一进页面就挂在右下角。**

### 面板形态：常驻悬浮，可收起

iframe 面板里加载的就是你的整个游戏页（页面自动铺满面板），**别做成一个光秃秃的按钮页**，要和内置马里奥 / 农场那个面板同款观感——从上到下四段：

| 区块 | 内容 |
|---|---|
| 顶部标题栏 | 游戏名 + HUD（分数 / 血量 / 时间等实时数据）+ **⏸ 暂停按钮**（可恢复）+ **🏆 排行榜按钮**（用户随时可看完整榜单，打开时游戏自动暂停） |
| 游戏主体 | canvas 画面，上面叠 HUD 与覆盖层 |
| 覆盖层 overlay | 未开始 / 结束时盖在画面上：游戏名、最高纪录、**排行榜 TOP5**、开始按钮 |
| 底部提示条 | 一行操作说明（如「← → 移动 · 空格射击」） |

> 💡 **收起 / 展开按钮不用你做**：系统会在面板右上角自动加一个 `−` 浮标，点击收起成**一条显示游戏名的横条**（宽不变；游戏名系统自动从你页面里读，读不到显示「🎮 小游戏」），点横条任意处展开。收起状态会记住——**刷新页面后仍是收起的，关闭页面重新进入才恢复展开**。你只管把 `game.html` 写成铺满面板的游戏页，无需任何适配。

**关键：右下角是常驻面板本体，不是按钮。** 骨架参考（`html,body` 铺满面板、纵向 flex，配色换成你模板的主题色）：

```html
<div id="game-header">               <!-- 标题栏 -->
    <div class="title">游戏名</div>
    <div class="hud"><span id="hud-score">SCORE 0</span></div>
</div>
<div id="game-board">                <!-- 游戏主体 -->
    <canvas id="game-canvas" width="320" height="200"></canvas>
    <div id="game-overlay">
        <h4>按空格 / 点击开始</h4>
        <p>最高纪录 0</p>
        <div class="board"></div>   <!-- 排行榜 TOP5 -->
        <!-- 昵称输入框：仅未登录显示（CFG.name 为空），已登录隐藏 -->
        <input class="name" maxlength="16" placeholder="输入昵称上榜（可留空）">
        <button class="start">开始游戏</button>
    </div>
</div>
<div id="game-hint">← → 移动 · 空格射击</div>   <!-- 提示条 -->
```

**iframe 内部 CSS 要点**（页面会被 iframe 拉伸铺满，**不要写 `position: fixed` / 自定宽度**）：

```css
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { width: 100%; height: 100%; overflow: hidden; user-select: none; }
body { display: flex; flex-direction: column; }   /* 纵向四段 */

#game-header { flex: none; }        /* 标题栏 */
#game-board  { flex: 1; min-height: 0; position: relative; }   /* 主体占满剩余 */
#game-hint   { flex: none; }        /* 提示条 */
```

### 输入框 / 弹窗必须自定义效果，禁止原生弹窗

- **禁用 `alert()` / `prompt()` / `confirm()`** —— 原生弹窗风格突兀，和游戏面板完全不搭。提示用自定义 toast 或内嵌提示行；确认类操作用页面内按钮 / 覆盖层完成。
- **昵称输入按登录状态决定**：`CFG.name` 是当前登录用户名（未登录为空字符串）。
    - **已登录（`CFG.name` 有值）→ 不显示昵称输入框**，直接用账号名上榜（和内置马里奥一致）；
    - **未登录 → 显示自定义样式的昵称输入框**，可用 `localStorage` 记住上次输入；留空则用默认匿名（如「匿名峡谷英雄」）。

    ```js
    var LOGGED = String(CFG.name || '').trim();
    var nameInput = document.querySelector('#game-overlay .name');
    if (LOGGED) {
        nameInput.style.display = 'none';                 // 已登录：隐藏输入框
    } else {
        nameInput.value = localStorage.getItem('nb_my_name') || '';
    }
    var finalName = LOGGED || nameInput.value.trim().substring(0, 16) || '匿名峡谷英雄';
    // 未登录且填了昵称时顺手记住：localStorage.setItem('nb_my_name', finalName)
    ```
- **输入框自己写样式**：主题底色 + 主色边框，`:focus` 主色发光。
- 自定义 toast 示例（2.5 秒自动消失）：

```css
#toast {
    position: fixed; left: 50%; bottom: 16px; transform: translateX(-50%);
    background: var(--panel, #1e1428); border: 1px solid var(--primary, #c8102e);
    color: var(--text, #f0e6d2); padding: 8px 16px; font-size: 12px;
    border-radius: 8px; opacity: 0; pointer-events: none;
    transition: opacity .25s, transform .25s;
}
#toast.show { opacity: 1; transform: translateX(-50%) translateY(-6px); }
```

```js
var toastTimer = 0;
function toast(msg) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('show'); }, 2500);
}
```

### 音效（可选）

Nebula **不提供音效 API**，音效完全由前端自己实现。文件直接放模板文件夹，`game.js` 相对路径引用：

```
wzry/sfx/shoot.mp3
wzry/sfx/hit.mp3
wzry/sfx/explode.mp3
```

推荐用 `Audio` 对象预加载（游戏高频音效，避免触发延迟）：

```js
var SFX = {};
['shoot', 'hit', 'explode'].forEach(function (name) {
    var a = new Audio('sfx/' + name + '.mp3');
    a.preload = 'auto';
    a.volume = 0.5;
    SFX[name] = a;
});

function play(name) {
    if (muted) return;              // 静音开关
    var a = SFX[name];
    if (!a) return;
    a.currentTime = 0;
    a.play().catch(function () {});
}
```

**两个必须注意的坑：**

1. **浏览器自动播放限制**：用户没交互前 `play()` 会被拦截。在用户第一次点击 / 按键时"解锁"一次音频。
2. **提供静音开关**：给标题栏加个 🔊/🔇 按钮，状态存 `localStorage`。

```js
// 解锁（用户首次交互时调用一次）
function unlockAudio() {
    Object.keys(SFX).forEach(function (k) {
        SFX[k].play().catch(function () {}).then(function () {
            SFX[k].pause();
            SFX[k].currentTime = 0;
        });
    });
    if (window.actx && actx.state === 'suspended') actx.resume();
}
document.addEventListener('click', unlockAudio, { once: true });
document.addEventListener('keydown', unlockAudio, { once: true });

// 静音开关
var muted = localStorage.getItem('nb_muted') === '1';
// 按钮点击：muted 取反 + 存 localStorage + 换图标
```

像素风小游戏也可以完全不用音频文件，用 **Web Audio API** 纯代码合成：

```js
var actx = new (window.AudioContext || window.webkitAudioContext)();
function beep(freq, dur, type) {
    var o = actx.createOscillator(), g = actx.createGain();
    o.type = type || 'square';
    o.frequency.value = freq;
    g.gain.setValueAtTime(0.1, actx.currentTime);
    g.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + dur);
    o.connect(g).connect(actx.destination);
    o.start(); o.stop(actx.currentTime + dur);
}
beep(880, 0.05, 'square');   // 射击
beep(120, 0.3, 'sawtooth');  // 爆炸
```

> **现成参考**：lol 模板 `game.js` 的 `sfx(kind)` 就是完整的 Web Audio 合成实现（coin / tick / start / over 四种音效，懒创建 AudioContext + suspended 自动 resume），复制改频率即可用。**要点**：`AudioContext` 务必懒创建（第一次用户点击内创建），不要页面加载就 new——否则违反自动播放策略直接被挂起。

### 上榜接口

游戏页里拿配置与上榜（`wzry/game.js` 是接口部分的完整可运行示例）：

```js
// 1. 配置（iframe 同源可读）：{ enabled, api, name, csrf }
var CFG = window.parent.__NB_GAMES__ || {};

// 2. api 是相对官网根的路径（"api.php"），iframe 在子目录，必须基于顶层窗口解析！
var API = new URL(CFG.api || 'api.php', window.parent.location.href).toString();

// 2.5 后台下发的游戏参数（后台「官网运营 → 小游戏与排行榜 → 游戏参数」可配）：
//     CFG.cfg = { game:'模板id', duration:30, durations:{模板id:秒}, topN:10 }
//     duration=全局默认时长(秒)；durations=各游戏单独时长；topN=榜单显示条数
//     限时类游戏必须按「分游戏 > 全局默认」的优先级读，别把时长写死：
var GID = String((CFG.cfg || {}).game || '你的文件夹名');
var DURATION = Math.max(10, Math.min(300,
    parseInt((CFG.cfg || {}).durations?.[GID], 10) || parseInt((CFG.cfg || {}).duration, 10) || 30));

// 3. 拉排行榜（game = 文件夹名，自动分榜）
fetch(API + '?action=game_top&game=wzry', { credentials: 'same-origin' })

// 4. 提交成绩
fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF': CFG.csrf },
    credentials: 'same-origin',
    body: JSON.stringify({ action: 'game_score_save', game: 'wzry', name: '玩家名', score: 100, csrf: CFG.csrf })
});
```

后台「官网运营 → 小游戏与排行榜」的总开关和游戏参数（**分游戏时长** / 默认时长 / 榜单条数 / 提交频控）对模板自带小游戏同样生效：开关与频控在服务端强制执行，时长与榜单条数经 `CFG.cfg` 下发、由游戏读取。

---

## 六、交互音效（可选，官网 + 发卡网）

给**页面本身**（不只是游戏）加交互音效：鼠标悬停按钮 / 点击链接、发卡网选分类 / 选商品等操作触发提示音。

**用法**：模板文件夹放一个 `interact.js`，官网和发卡网检测到该文件就会在页面底部**自动加载**，无需改任何页面代码：

```
web/Template/<模板名>/interact.js   ← 存在即两端自动加载
```

单文件 css 模板（assets 兼容位置）也支持：在 css 同级建同名文件夹只放 `interact.js` 即可，如
`shop/assets/templates/<模板名>/interact.js`（发卡网）、`web/assets/css/templates/<模板名>/interact.js`（官网）。

**实现规范**（完整可运行示例见 `web/Template/lol/interact.js`，悬停 / 点击双音效 + 静音开关）：

```js
// 1. Web Audio 合成或 Audio 文件均可（游戏音效一节的两条路线通用）
// 2. AudioContext / Audio 播放必须「懒创建 + 用户手势内解锁」——页面加载就 new 会被自动播放策略挂起；
//    建议再加一次性 pointerdown 监听提前解锁，保证「第一次点击」就有声音
// 3. 事件用委托绑定（document 级），别给每个按钮单独绑——区块是动态渲染的
// 4. 悬停音必须节流（80ms 左右）+ 音量要小（0.03 左右），否则扫过一排按钮时连环炸响
// 5. 提供静音开关，状态存 localStorage（如 nb_sfx_muted）
```

**LOL 模板示例要点**：`mouseover` 委托 + `closest('a, button, .plan, .card, .seller, .goods-card')` 匹配可点元素（`.goods-card` 是发卡网商品卡）→ 轻柔短音；`click` → 确认音；控制台 `NebulaSFX.muted = true/false` 一键开关（状态记住）。

> 模板管理页的模板卡片会标注「· 交互音效」提示该模板带此文件。

---

## 七、模板名称 / 简介 / 色卡（css 头注释，推荐）

不写也能用（后台显示「模板 xxx · 自动识别的模板」+ 默认紫色）。在**入口 css 文件头部**写块注释即可自动识别（WordPress 主题同款）：

```css
/*
Template Name: 王者荣耀
Description: 峡谷国风 · 金红锋锐
Color: #c8102e
Layout: hero, banner, features, flow, pricing, notice, board, faq
*/
```

- `Template Name` 显示名（30 字内）、`Description` 一句话简介、`Color` 色卡 `#RRGGBB`；三项可只写任意几项
- **`Layout` 区块布局（可选）**：逗号分隔的区块 id 顺序表，决定首页**显示哪些区块、按什么顺序**——**省略的区块不显示**。声明写在**该端入口 css** 头注释里（官网 = `web.css`，发卡网 = `shop.css`，两端互不影响）：
  - 官网内置区块：`hero`(首屏横幅) / `features`(特性) / `shots`(实拍) / `flow`(流程) / `pricing`(套餐) / `sellers`(商家) / `notice`(公告) / `board`(留言板) / `faq`(常见问题)。不写 Layout = 全部按默认顺序显示
  - 发卡网内置区块：`notice`(公告横幅) / `notes`(购买须知) / `goods`(商品区)。不写 Layout = 全部按默认顺序显示
- **自定义区块**：Layout 里写一个不在上表的 id（官网如 `banner`、发卡网如 `promo`），并在模板文件夹建对应片段文件——官网 `sections/<id>.html`、发卡网 `shop-sections/<id>.html`（HTML 片段），前台渲染时插到该位置；片段里 `{{SITE_NAME}}` 会自动替换为站点名
- **区块样式**：全部写在模板 css 里改——内置区块用 `body.ui-<名>` 前缀覆盖现有选择器（`.hero` / `.card` / `.plan` / `.seller` / `.goods-card`…）；自定义区块给个自己的 id/class（如上例 `#banner`），样式同样写进模板 css（推荐），内联 style 也能用
- **后台可视化编辑**：以上 Layout 顺序与自定义区块内容，站长不用改文件也能在 **后台 → 界面模板 → 布局与自定义区块（官网 / 发卡网）** 里直接编辑——先选编辑端（官网 / 发卡网）再选模板（保存区块顺序 = 写回该端入口 css 头注释 Layout 行，分隔符兼容全角逗号 / 顿号；保存/新增区块 = 写 `sections/<id>.html` 或 `shop-sections/<id>.html`；删除区块 = 删文件并自动从布局顺序移除），前台即时生效
- **保存不生效？** 保存后提示里若带「已忽略无效项」，说明输入里有非法字符（区块 id 仅限小写字母/数字/下划线）；顺序框保存后会自动规范化成英文逗号分隔
- 改完刷新后台模板管理立即生效，无需改任何代码
- 内置四款（云上农场/马里奥/水墨/STAR RAIDER）的名字写在 `lib/UiTemplate.php` 的 `$meta` 里作后备；自己写的模板用 css 注释就够了

---

## 八、检查清单

- [ ] 文件夹名 = `body.ui-<名>` 选择器一致
- [ ] 所有面板 / 卡片 / 公告条 / 套餐卡不透明底色，无半透明主色大面积铺底
- [ ] 无默认主题残留色（`rgba(34,211,238,` / `#22d3ee` / `#67e8f9` / 紫色系 `#ddd6fe`）
- [ ] 装饰背景层 `pointer-events:none`、`z-index:0`，不遮内容、不遮版权
- [ ] 版权（页脚 `© …`）在任何模板下可见
- [ ] 游戏提交成绩的 `game` 字段 = 文件夹名
- [ ] 游戏面板是**右下角常驻悬浮**形态，**无 🎮 按钮**（收起/展开浮标由系统自动提供，模板无需实现）
- [ ] 游戏页是面板形态（标题栏 + HUD + 覆盖层 + 提示条），无原生 `alert` / `prompt` / `confirm`
- [ ] 有**用户可点的排行榜入口**（标题栏 🏆 按钮 → TOP10 浮层，打开时游戏自动暂停），不能让用户只能去后台看记录
- [ ] 有**暂停按钮**（标题栏 ⏸，暂停后变 ▶ 可恢复，提示条同步提示「已暂停」）
- [ ] 昵称：已登录不显示输入框直接账号上榜，未登录才显示自定义输入框
- [ ] 音效：文件放模板文件夹，有静音开关，用户交互后才播放
- [ ] 文件夹名 = CSS 选择器 id；入口文件命名正确（`web.css` / `shop.css`，不要多后缀）
- [ ] 浅色模板文字是深色（能看清）

---

## 九、本次改动摘要（对照旧版）

| 位置 | 旧版 | 新版 |
|---|---|---|
| 一、识别规则 | 有 `game.html` → 右下角出现 🎮 按钮 | 有 `game.html` → 右下角**直接常驻悬浮游戏面板**，无按钮 |
| 一、目录结构 | 无 `sfx/` | 新增 `sfx/` 音效文件夹示例 |
| 五、标题 | 「自带小游戏」 | 同，但内容改为常驻悬浮面板 |
| 五、面板形态 | iframe 弹出 | **常驻悬浮**，收起/展开浮标由系统自动提供 |
| 五、新增 | — | **音效**小节（无 API、Audio 预加载、自动播放限制、静音开关、Web Audio 合成） |
| 六、新增 | — | **交互音效**（interact.js 两端自动加载：悬停/点击音效、委托+节流、静音开关；assets 单文件模板同名文件夹也支持） |
| 七、布局 | 仅官网 Layout | 官网 + **发卡网**双端布局（发卡网内置 notice/notes/goods，自定义区块放 `shop-sections/`）；后台编辑器加「编辑端」切换 |
| 文档位置 | `web/Template/README.md` | 迁入 `docs/TEMPLATE.md`（原位置留跳转壳），纳入文档中心统一跳转 |
| 七、检查清单 | 无游戏面板形态要求 | 新增「常驻悬浮、无 🎮 按钮」；新增音效检查项 |

> **一句话**：所有「右下角 🎮 按钮 → 点击 iframe 弹出」的表述，统一改为「右下角直接常驻悬浮游戏面板（iframe 加载），无按钮，可收起（浮标系统提供）」；并补充音效实现指引。