import { api, apiDownload, uploadHeaders } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, esc, tag, downloadBlob, copyText } from '../core/util.js';
import { toast, confirmPassword } from '../core/ui.js';
import { can } from '../core/state.js';
import { API_ENTRY } from '../core/state.js';

register('setting', render);

const TIER_PERM = {
    site:     'settings.site',
    business: 'settings.business',
    security: 'settings.security',
    infra:    'settings.infra',
};

const canMaint = () => can(TIER_PERM.infra);

const TABS = [
    { key: 'site',     label: '站点', tierName: '站点设置' },
    { key: 'business', label: '业务', tierName: '业务设置' },
    { key: 'security', label: '安全', tierName: '安全设置' },
    { key: 'system',   label: '系统', tierName: '系统与缓存' },
    { key: 'maint',    label: '维护', tierName: '系统维护' },
];

const TAB_PERM = { site: 'site', business: 'business', security: 'security', system: 'infra', maint: 'infra' };

let curTab = 'site';
try {
    const t = localStorage.getItem('nb_setting_tab_v1');
    if (TABS.some(x => x.key === t)) curTab = t;
} catch (e) {
 }

function noPermBar(tierName) {
    return `<div class="hint" style="color:#f59e0b;background:rgba(245,158,11,.08);
        border:1px solid rgba(245,158,11,.25);border-radius:6px;padding:10px 12px;margin-bottom:14px">
        当前角色无权修改「${esc(tierName)}」，以下内容仅供查看。如需变更请联系超级管理员。
    </div>`;
}

/**
 * 公钥展示：固定 SEC1 头置灰、差异段高亮（前 12 + 后 10 字符加粗），附 kid/algo 指纹行。
 * P-256 公钥前 36 个 base64 字符对所有密钥都相同，旧实现整串截断导致两把钥匙看起来一样；
 * 这里把「差异部分」突出显示，一眼可区分，完整 PEM 仍在 title 中可悬停查看。
 */
function pubBrief(pem, kid, algo) {
    if (!pem) return '';
    const b64 = String(pem).replace(/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/g, '');
    const HEAD = b64.slice(0, 36);
    const TAIL = b64.slice(36);
    const tailHead = TAIL.slice(0, 12);
    const tailTail = TAIL.slice(-10);
    const fp = [kid ? 'kid ' + kid : '', algo ? 'algo ' + algo : ''].filter(Boolean).join(' · ');
    return `<span class="mono" style="display:inline-block;vertical-align:middle;font-size:11.5px;line-height:1.65" title="${esc(pem)}"
        ><span style="color:#7d93a8">${esc(HEAD)}</span><b style="color:#66d9ef">${esc(tailHead)}</b><span style="color:#7d93a8">…</span><b style="color:#66d9ef">${esc(tailTail)}</b>${fp ? `<br><span style="color:#4ade80;font-size:10.5px">${esc(fp)}</span>` : ''}</span>`;
}

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();
    const res = await api('setting_get');
    if (res.code !== 0) return;
    const d = res.data;
    const s = d.settings || {};

    const cfg = Object.assign({
        crypto: {}, policy: {}, version: {}, admin: {},
    }, d.config || {});
    cfg.crypto = cfg.crypto || {};
    cfg.policy = cfg.policy || {};
    cfg.version = cfg.version || {};
    cfg.admin = cfg.admin || {};

    const cfgCache = Object.assign({ redis: {} }, d.cache_config || {});
    cfgCache.redis = cfgCache.redis || {};

    const cacheStatus = (info) => {
        if (!info) return '';
        const badge = info.driver === 'redis'
            ? tag('Redis 运行中', 'green') + ' · ' + (info.ext_redis ? 'ext-redis 扩展' : '内置 RESP 客户端')
            : info.driver === 'file'
                ? tag('文件缓存', 'yellow') + '（Redis 不可用或驱动指定 file，已降级）'
                : tag('未启用', 'gray');
        const conn = info.driver === 'redis'
            ? `<span class="k">Redis 连接</span><span class="v mono">${esc(info.redis_host)}:${info.redis_port} · db${info.redis_db} · ${info.redis_auth ? '已设密码' : '无密码'}</span>`
            : `<span class="k">Redis 连接</span><span class="v mono">${esc(info.redis_host || '127.0.0.1')}:${info.redis_port || 6379} · db${info.redis_db} · ${info.redis_auth ? '已设密码' : '无密码'}</span>`;
        const notes = (info.notes || []).length
            ? `<span class="k">运行提示</span><span class="v">${info.notes.map(n => esc(n)).join('；')}</span>`
            : '';
        return `<div class="kv" style="margin-bottom:14px">
            <span class="k">当前驱动</span><span class="v">${badge}</span>
            ${conn}
            ${notes}
        </div>`;
    };

    const boolSel = (id, val) => `
        <select id="${id}">
            <option value="1" ${val === '1' ? 'selected' : ''}>开启</option>
            <option value="0" ${val !== '1' ? 'selected' : ''}>关闭</option>
        </select>`;

    const canTab = {
        site:     can(TIER_PERM.site),
        business: can(TIER_PERM.business),
        security: can(TIER_PERM.security),
        system:   can(TIER_PERM.infra),
    };

    const EFF_LABELS = {
        single_login: '同账号单点登录',
        geo_block: '异地登录拦截',
        heartbeat_interval: '心跳间隔',
        heartbeat_timeout: '离线判定',
        unbind_per_day: '每日解绑上限',
        rate_limit_per_min: '接口限流',
        web_reg_max_hour: '注册频控上限',
        web_act_cooldown_min: '激活冷却期',
        web_act_max_min: '激活尝试频控',
        session_ttl: '登录态有效期',
        default_max_devices: '默认设备上限',
        points_deduct_mode: '点数扣点模式',
    };
    const effBox = (eff) => {
        if (!eff) return '';
        const rows = Object.keys(EFF_LABELS)
            .filter(k => eff[k])
            .map(k => {
                const e = eff[k];
                const fromDb = e.source === 'db';
                return `<span style="display:inline-flex;align-items:center;gap:4px;margin:0 10px 6px 0;
                    padding:3px 10px;background:var(--card);border:1px solid var(--border);border-radius:20px;
                    white-space:nowrap;font-size:12px">
                    ${esc(EFF_LABELS[k])} <b>${esc(String(e.effective))}</b>
                    <span style="color:${fromDb ? 'var(--success)' : 'var(--text-faint)'};font-size:11px">
                        ${fromDb ? '后台' : '配置'}</span>
                </span>`;
            }).join('');
        return `<div style="margin:14px 0 4px;padding:10px 12px;background:var(--hover-wash);
            border:1px solid var(--border);border-radius:8px;font-size:13px;color:var(--text-sub);line-height:1.9">
            <div style="font-weight:600;margin-bottom:8px;color:var(--text)">当前生效值</div>
            <div style="display:flex;flex-wrap:wrap;align-items:center">${rows}</div>
            <div style="color:var(--text-faint);font-size:12px;margin-top:6px">
                「后台」= 以本页保存的值为准；「配置」= 尚未保存过，沿用 config/config.php 的默认值。
            </div>
        </div>`;
    };

    const lmOpts = d.login_methods_options || { password: '用户名 + 密码' };
    const lmCur  = d.login_method || s.login_methods || 'password';
    const lmSel  = Object.keys(lmOpts).map(k =>
        `<option value="${esc(k)}" ${lmCur === k ? 'selected' : ''}>${esc(lmOpts[k])}</option>`).join('');

    const pSite = `
        ${canTab.site ? '' : noPermBar('站点设置')}
        <div class="row2">
            <div class="field"><label>站点名称</label>
                <input id="stName" value="${esc(s.site_name || '')}"></div>
            <div class="field"><label>客服联系方式</label>
                <input id="stContact" value="${esc(s.contact || '')}" placeholder="QQ / 微信 / 邮箱 / 网址">
                <div class="hint">官网「价格套餐 → 立即购买」弹窗展示；邮箱与网址会渲染成可点链接，其余提供复制按钮</div>
            </div>
        </div>
        <div class="field"><label>全局 Logo（官网 / 发卡网 / 管理后台共用）</label>
            <div class="logo-row">
                <input id="stLogo" value="${esc(s.shop_logo || '')}" placeholder="https://... 或上传（留空用默认 logo.png）">
                <button type="button" class="btn ghost sm" id="stLogoUpload">上传图片</button>
            </div>
            <div class="hint" id="stLogoHint">jpg / png / gif / webp，5MB 以内；上传后点下方「保存 Logo」生效</div>
        </div>
        <button class="btn ghost sm" id="stSaveLogo" ${can('settings.business') ? '' : 'disabled'}>保存 Logo</button>
        <div class="row2">
            <div class="field"><label>开放注册</label>${boolSel('stReg', s.register_enable)}</div>
            <div class="field"><label>首页留言板</label>
                <select id="stMsgBoard">
                    <option value="1" ${s.web_message_board !== '0' ? 'selected' : ''}>开启</option>
                    <option value="0" ${s.web_message_board === '0' ? 'selected' : ''}>关闭（官网隐藏留言板区块）</option>
                </select>
                <div class="hint">留言一律先审后显示，审核入口在「内容运营 → 留言板」</div>
            </div>
        </div>
        <div class="row2">
            <div class="field"><label>总站白页（分软件官网模式）</label>
                <select id="stTotalBlank">
                    <option value="1" ${s.web_total_blank === '1' ? 'selected' : ''}>开启（推荐多软件使用）</option>
                    <option value="0" ${s.web_total_blank !== '1' ? 'selected' : ''}>关闭</option>
                </select>
                <div class="hint">开启后多软件未选择软件时，官网首页只显示极简「选择软件」占位页，已激活用户自动进入自己软件的分站官网；关闭则总站照常显示完整官网（下方官网内容在「内容运营 → 官网内容」维护，分软件内容在官网内容的「分软件覆盖」里单独设置）</div>
            </div>
            <div class="field"><label>维护模式</label>${boolSel('stMaint', s.maintain_mode)}</div>
        </div>
        <div class="field"><label>维护提示语</label>
            <input id="stMaintMsg" value="${esc(s.maintain_msg || '')}"></div>
        <button class="btn" id="stSaveSite" ${canTab.site ? '' : 'disabled'}>保存站点设置</button>`;

    const pBusiness = `
        ${canTab.business ? '' : noPermBar('业务设置')}
        <div class="row2">
            <div class="field"><label>新用户默认设备数</label>
                <input id="stDefDev" type="number" value="${esc(s.default_max_devices || '1')}" min="1" max="99"></div>
            <div class="field"><label>新用户注册赠送天数</label>
                <input id="stRegDays" type="number" value="${esc(s.register_gift_days || '0')}"></div>
        </div>
        <div class="row2">
            <div class="field"><label>新用户注册赠送点数</label>
                <input id="stRegPts" type="number" value="${esc(s.register_gift_points || '0')}"></div>
            <div class="field"></div>
        </div>
        <div class="card-head" style="padding-left:0"><h3 style="font-size:13px">点数/次数卡扣点</h3></div>
        <div class="row2">
            <div class="field"><label>扣点模式</label>
                <select id="stPtsMode">
                    <option value="per_login" ${s.points_deduct_mode !== 'daily' && s.points_deduct_mode !== 'online' ? 'selected' : ''}>每次登录扣 1</option>
                    <option value="daily" ${s.points_deduct_mode === 'daily' ? 'selected' : ''}>每天首次登录扣 1</option>
                    <option value="online" ${s.points_deduct_mode === 'online' ? 'selected' : ''}>按在线时长扣</option>
                </select>
                <div class="hint">点数卡按此模式扣；<b>次数卡固定每次登录扣 1</b>（不受此模式影响）；时长卡/永久卡不扣；耗尽后登录/心跳被拒</div>
            </div>
            <div class="field"><label>在线每 N 分钟扣 1</label>
                <input id="stPtsMin" type="number" value="${esc(s.points_deduct_minutes || '30')}" min="1">
                <div class="hint">仅「按在线时长扣」模式生效；挂机也会消耗点数</div>
            </div>
        </div>
        <div class="card-head" style="padding-left:0"><h3 style="font-size:13px">代理商</h3></div>
        <div class="row2">
            <div class="field"><label>开放代理商后台</label>${boolSel('stAgEnable', s.agent_enable)}
                <div class="hint">关闭后代理商无法登录 /agent/，已发放的卡密不受影响</div>
            </div>
            <div class="field"><label>开放代理商自助注册</label>${boolSel('stAgReg', s.agent_register_enable)}
                <div class="hint">
                    开启后代理商可用「后台生成的激活码」在 <code>/agent/#reg</code> 自助注册；
                    关闭则只能由管理员在「代理商」页创建账号
                </div>
            </div>
        </div>
        <div class="row2">
            <div class="field"><label>代理商默认单价（元/张）</label>
                <input id="stAgPrice" value="${esc(s.agent_unit_price || '0')}" placeholder="0 = 不启用余额计费">
                <div class="hint">
                    某一卡类型未单独定价时使用此价；为 0 时「余额计费」模式会拒绝生成该类型
                </div>
            </div>
            <div class="field"><label>入口密钥（可选）</label>
                <input id="stAgEntry" value="${esc(s.agent_entry_key || '')}" placeholder="留空则不校验">
                <div class="hint">
                    填写后必须先访问 <code>/agent/?k=密钥</code> 才能打开代理商后台，用于隐藏入口；清空即关闭该校验
                </div>
            </div>
        </div>
        <button class="btn" id="stSaveBiz" ${canTab.business ? '' : 'disabled'}>保存业务设置</button>`;

    const pSecurity = `
        ${canTab.security ? '' : noPermBar('安全设置')}
        <div class="field"><label>登录方式</label>
            <select id="stLoginMethod">${lmSel}</select>
            <div class="hint">
                单选决定客户端与官网的登录字段，客户端按 init 下发的方式直接提交对应参数：<br>
                · <b>用户名 + 密码</b>：username + password<br>
                · <b>用户名 + 激活码</b>：username + code（卡密已绑定则校验用户名一致；未绑定则为该用户名建号并激活）<br>
                · <b>激活码（卡密直登）</b>：code（已绑定直接登录，未绑定自动建号；此方式下注册功能自动关闭）<br>
                各软件可在「软件管理 → 编辑软件 → 登录方式」单独设置并覆盖此处。
            </div>
        </div>
        <div class="field"><label>激活码找回密码（发卡网）</label>
            ${boolSel('stReclaim', s.login_reclaim_enable)}
            <div class="hint">从「激活码登录」切回「用户名+密码」后，自动建号的账号没有密码。开启后发卡网登录弹窗出现「激活码找回」入口：凭绑定的激活码查回用户名并设置新密码（带图形验证码与限流）</div>
        </div>
        <div class="row2">
            <div class="field"><label>同账号单点登录</label>
                <select id="stSingle">
                    <option value="1" ${s.single_login === '1' ? 'selected' : ''}>开启（后登录踢掉先登录）</option>
                    <option value="0" ${s.single_login !== '1' ? 'selected' : ''}>关闭（允许多端同时在线）</option>
                </select>
                <div class="hint">开启后同一账号仅保留 1 个在线会话，后登录会使先登录的端掉线</div>
            </div>
            <div class="field"><label>异地登录拦截</label>
                <select id="stGeo">
                    <option value="1" ${s.geo_block === '1' ? 'selected' : ''}>开启（IP 变化即拒绝）</option>
                    <option value="0" ${s.geo_block !== '1' ? 'selected' : ''}>关闭</option>
                </select>
                <div class="hint">仅拦截「已绑定设备换 IP」，首次绑定的新机器不判定，避免误伤</div>
            </div>
        </div>
        <div class="field"><label>IP 黑名单（禁止访问全站任何页面）</label>
            <textarea id="stIpBlacklist" rows="4" spellcheck="false"
                placeholder="每行一个，支持单个 IP 或 CIDR 段：&#10;1.2.3.4&#10;5.6.7.0/24">${esc(s.ip_blacklist || '')}</textarea>
            <div class="hint">命中的 IP 访问本站任何页面/接口（含后台）一律返回 404；以 # 开头的行为注释。填写自己当前 IP 会导致自己被锁，请谨慎</div>
        </div>
        <div class="row2">
            <div class="field"><label>心跳间隔(秒)</label>
                <input id="stHb" type="number" value="${esc(s.heartbeat_interval || '30')}"></div>
            <div class="field"><label>离线判定(秒)</label>
                <input id="stTimeout" type="number" value="${esc(s.heartbeat_timeout || '180')}"></div>
        </div>
        <div class="row2">
            <div class="field"><label>登录态有效期(秒)</label>
                <input id="stSessionTtl" type="number" value="${esc(s.session_ttl || '3600')}">
                <div class="hint">客户端登录令牌最长存活时间（从首次登录起算），到期需重新 init + login；0 = 不限</div></div>
        </div>
        <div class="card-head" style="padding-left:0;margin-top:4px"><h3 style="font-size:13px">离线宽限（断网时客户端可凭签名票据继续运行）</h3></div>
        <div class="row2">
            <div class="field"><label>启用离线宽限</label>
                ${boolSel('stGraceEnable', (s.grace_enable === undefined ? '1' : s.grace_enable))}
                <div class="hint">关闭后心跳失败即判离线，不签发宽限票据；分软件可在「软件管理 → 编辑 → 策略覆盖」单独开关</div>
            </div>
            <div class="field"><label>单次宽限时长(秒)</label>
                <input id="stGraceSec" type="number" value="${esc(s.grace_seconds || '3600')}">
                <div class="hint">一张票据允许的离线时长，建议为心跳间隔的 15~30 倍；填 0 = 关闭</div>
            </div>
        </div>
        <div class="row2">
            <div class="field"><label>单次累计上限(秒)</label>
                <input id="stGraceMax" type="number" value="${esc(s.grace_max_seconds || '7200')}">
                <div class="hint">一个会话内离线总上限（0 = 不限制，仅受账号到期时间钳制）</div>
            </div>
            <div class="field"><label>时钟偏差容忍(秒)</label>
                <span class="v" style="display:block;padding-top:6px">${esc(cfg?.grace?.clock_skew || '120')}</span>
                <div class="hint">只读：客户端时钟与服务器时间的允许偏差，修改 config/config.php 中的 grace.clock_skew</div>
            </div>
        </div>
        <div class="row2">
            <div class="field"><label>每日解绑次数上限</label>
                <input id="stUnbind" type="number" value="${esc(s.unbind_per_day || '3')}"></div>
            <div class="field"><label>接口限流(次/分钟)</label>
                <input id="stRate" type="number" value="${esc(s.rate_limit_per_min || '120')}"></div>
        </div>
        <div class="card-head" style="padding-left:0"><h3 style="font-size:13px">人机风控（防自动化 / 防批量刷单）</h3></div>
        <div class="field"><label>启用前端环境信号采集 + 服务端加权评分</label>
            ${boolSel('stGuard', (d.guard === undefined ? '1' : (d.guard.enabled ? '1' : '0')))}
            <div class="hint">
                官网 / 发卡网 / 代理 / 后台提交请求时会附带浏览器环境信号（自动化框架特征、环境异常、真实操作轨迹），服务端加权评分：<br>
                · 命中自动化特征（无头浏览器、脚本 UA、按字段名填表的机器人）→ 直接拒绝并记录审计日志<br>
                · <b>采集到真实操作轨迹（鼠标 / 键盘 / 触摸 / 滚动 / 点击）一律放行</b>，真人不会因为这个开关被拦<br>
                · 判定为可疑但非自动化的请求照常放行，只是接口限流阈值收紧<br>
                排查前端异常、或怀疑误拦正常用户时，可临时关闭（关闭后不再评分，等同未部署）。
            </div>
        </div>
        <div class="card-head" style="padding-left:0"><h3 style="font-size:13px">官网防刷</h3></div>
        <div class="row2">
            <div class="field"><label>注册频控(个/小时/IP)</label>
                <input id="stWebRegMax" type="number" value="${esc(s.web_reg_max_hour || '10')}">
                <div class="hint">配合注册验证码拦截批量注册</div></div>
            <div class="field"><label>激活冷却期(分钟)</label>
                <input id="stWebActCd" type="number" value="${esc(s.web_act_cooldown_min || '10')}">
                <div class="hint">新注册账号需等待 N 分钟才能激活卡密，0 = 关闭冷却</div></div>
        </div>
        <div class="field"><label>激活尝试频控(次/分钟/IP)</label>
            <input id="stWebActMax" type="number" value="${esc(s.web_act_max_min || '20')}">
            <div class="hint">限制激活码尝试频率，防卡密枚举爆破</div>
        </div>
        ${effBox(d.effective)}
        <button class="btn" id="stSaveSec" ${canTab.security ? '' : 'disabled'}>保存安全设置</button>`;

    const pSystem = `
        ${canTab.system ? '' : noPermBar('系统与缓存')}
        <div class="card-head" style="padding-left:0"><h3 style="font-size:13px">缓存与 Redis</h3></div>
        ${cacheStatus(d.cache_info)}
        <div class="row2">
            <div class="field"><label>缓存驱动</label>
                <select id="stCacheDriver">
                    <option value="auto" ${(s.cache_driver || '') === 'auto' || !s.cache_driver ? 'selected' : ''}>auto（推荐：有 Redis 用 Redis，否则文件缓存）</option>
                    <option value="redis" ${s.cache_driver === 'redis' ? 'selected' : ''}>redis（仅 Redis，连不上自动降级文件）</option>
                    <option value="file" ${s.cache_driver === 'file' ? 'selected' : ''}>file（仅文件缓存，仅限单机部署）</option>
                    <option value="none" ${s.cache_driver === 'none' ? 'selected' : ''}>none（关闭缓存，全部直连数据库）</option>
                </select>
                <div class="hint">
                    缓存承载心跳在线、接口统计等高频写入，可用时大幅降低数据库压力；
                    多机部署必须用 Redis，否则各机器缓存不共享。保存后立即生效，无需重启。
                </div>
            </div>
            <div class="field"><label>Key 前缀说明</label>
                <div class="hint" style="margin-top:6px">
                    所有缓存键都带 <code>nb:</code> 前缀（config 中 prefix），多套系统共用一个 Redis 时请勿修改；
                    心跳/统计等批量落库由 cron.php 兜底，请确保已配置定时任务。
                </div>
            </div>
        </div>
        <div class="row2">
            <div class="field"><label>Redis 地址</label>
                <input id="stCacheHost" value="${esc(s.cache_redis_host || cfgCache.redis.host)}" placeholder="127.0.0.1">
            </div>
            <div class="field"><label>Redis 端口</label>
                <input id="stCachePort" type="number" value="${esc(s.cache_redis_port || String(cfgCache.redis.port))}" placeholder="6379">
            </div>
        </div>
        <div class="row2">
            <div class="field"><label>Redis 密码（可选）</label>
                <input id="stCachePass" value="${esc(s.cache_redis_password || '')}" placeholder="未设置密码留空" autocomplete="new-password">
                <div class="hint">保存过则以后台值为准；从未保存过则沿用 config/config.php 的出厂值（出厂值不回显）</div>
            </div>
            <div class="field"><label>Redis 库号（0-15）</label>
                <input id="stCacheDb" type="number" value="${esc(s.cache_redis_database || '0')}" placeholder="0">
            </div>
        </div>
        <button class="btn" id="stSaveSys" ${canTab.system ? '' : 'disabled'}>保存系统设置</button>
        <div class="card-head" style="padding-left:0;margin-top:8px">
            <h3 style="font-size:13px">运行参数（只读，修改 config/config.php）</h3>
        </div>
        <div class="kv">
            <span class="k">通信加密</span>
            <span class="v">${cfg.crypto.enforce ? tag('已开启', 'green') : tag('已关闭', 'red')} · ${esc(cfg.crypto.algo)} + ${esc(cfg.crypto.sign)}
                <span class="hint">客户端与 API 的全部请求/响应强制走加密信封；关闭后可明文通信，仅调试用，生产环境务必开启</span></span>
            <span class="k">时间窗口</span>
            <span class="v">${cfg.crypto.time_window} 秒
                <span class="hint">请求时间戳与服务器时间的允许偏差，超出即拒绝（防重放）；客户端时钟偏差过大时会报「请求已过期」</span></span>
            <span class="k">心跳间隔</span>
            <span class="v">${cfg.policy.heartbeat_interval} 秒
                <span class="hint">客户端多久向服务器报一次活；SDK 默认采用 init 下发的该值</span></span>
            <span class="k">离线判定</span>
            <span class="v">${cfg.policy.heartbeat_timeout} 秒
                <span class="hint">超过该时长没收到心跳，客户端即被判为离线（会话清理、在线人数不再计入）</span></span>
            <span class="k">会话有效期</span>
            <span class="v">${cfg.policy.session_ttl} 秒
                <span class="hint">登录令牌的最长有效期，到期后客户端需重新 init + login</span></span>
            <span class="k">默认设备数</span>
            <span class="v">${cfg.policy.default_max_devices}
                <span class="hint">新账号默认可绑定的设备数量上限（账号级设置可单独调整）</span></span>
            <span class="k">接口限流</span>
            <span class="v">${cfg.policy.rate_limit_per_min} 次/分钟
                <span class="hint">单 IP 对全部 API 的每分钟请求上限，超出返回 5001；防 CC / 暴力枚举</span></span>
            <span class="k">登录限流</span>
            <span class="v">${cfg.policy.login_attempt_per_min} 次/分钟
                <span class="hint">单 IP 每分钟登录尝试上限，超出返回 5001</span></span>
            <span class="k">锁定策略</span>
            <span class="v">失败 ${cfg.policy.login_fail_threshold} 次锁定 ${cfg.policy.login_lock_seconds} 秒
                <span class="hint">同一账号连续登录失败达到次数后锁定该时长，防暴力破解密码/卡密</span></span>
            <span class="k">离线宽限</span>
            <span class="v">${cfg.grace.enable ? tag('已开启', 'green') : tag('未开启', 'gray')}${cfg.grace.enable ? ` · 单次 ${cfg.grace.seconds} 秒 / 累计上限 ${cfg.grace.max_seconds} 秒` : ''}
                <span class="hint">断网时客户端可凭签名票据离线运行；单次=一张票据允许的离线时长，累计=一个会话内离线总上限（不超账号到期时间）；分软件开关在「软件管理 → 编辑 → 策略覆盖」</span></span>
            <span class="k">离线宽限公钥</span>
            <span class="v">${cfg.grace.public_key ? `${pubBrief(cfg.grace.public_key, cfg.grace.kid, cfg.grace.algo)} <button class="btn ghost xs" id="stRotateGraceKey">轮换密钥</button> ${tag('已生成', 'green')}` : tag('尚未生成（首次心跳时自动生成）', 'gray')}
                <span class="hint">离线宽限票据验证公钥，客户端登录/心跳时由服务端自动下发，SDK 无需手动配置；「轮换密钥」删除旧密钥文件并重新生成（旧离线票据立即失效，客户端重新登录后自动获取新公钥）</span></span>
            <span class="k">响应签名公钥</span>
            <span class="v">${cfg.resp_sign && cfg.resp_sign.public_key ? `${pubBrief(cfg.resp_sign.public_key, cfg.resp_sign.kid, cfg.resp_sign.algo)} <button class="btn ghost xs" id="stCopyRespPub">复制 PEM</button> <button class="btn ghost xs" id="stCopyRespCpp">复制 C++ 代码</button> <button class="btn ghost xs" id="stRotateRespKey">轮换密钥</button> ${tag('已生成', 'green')}` : tag('尚未生成（首次请求时自动生成）', 'gray')}
                <span class="hint">API 响应签名验证公钥，客户端 SDK 的 kRespSignPubKey 配置填它；与离线宽限密钥独立管理，轮换不影响离线票据；「复制 C++ 代码」得到可直接粘贴进客户端 SDK 的 NEBULA_STR 片段（换行已转义）</span></span>
            <span class="k">后台入口</span>
            <span class="v mono">${esc(cfg.admin.path || '/admin/')} ${cfg.admin.entry_key_enable ? tag('已启用入口密钥', 'green') : ''}
                <span class="hint">后台访问路径与入口密钥；入口密钥开启后需先验密钥才能打开登录页，可隐藏后台被扫描</span></span>
            <span class="k">IP 白名单</span>
            <span class="v">${cfg.admin.ip_whitelist_enable ? tag('已开启', 'green') : tag('未开启', 'gray')}${cfg.admin.ip_whitelist_enable && (cfg.admin.ip_whitelist || []).length ? ' · ' + esc(cfg.admin.ip_whitelist.join(', ')) : ''}
                <span class="hint">开启后仅名单内 IP 可访问后台；注意本机公网 IP 变化会被锁在外面</span></span>
            <span class="k">删除二次确认</span>
            <span class="v">${cfg.admin.require_password_confirm ? tag('已开启', 'green') : tag('已关闭', 'red')}
                <span class="hint">后台执行删除类操作时需输入登录密码二次确认，防误删/越权</span></span>
            <span class="k">后台会话</span>
            <span class="v">${cfg.admin.session_ttl} 秒
                <span class="hint">后台登录态的有效时长，过期需重新登录管理后台</span></span>
            <span class="k">后台登录锁定</span>
            <span class="v">失败 ${cfg.admin.login_fail_threshold} 次锁定 ${cfg.admin.login_lock_seconds} 秒
                <span class="hint">管理后台的防暴力破解策略（与 API 侧登录限流相互独立）</span></span>
        </div>`;

    const pMaint = `
        ${canTab.system ? '' : noPermBar('系统维护')}
        <div class="maint-grid">
            <section class="maint-block">
                <div class="mb-head">
                    <h4>健康巡检</h4>
                    ${canTab.system ? '<button class="btn ghost sm" id="stHealthRun">重新检测</button>' : ''}
                </div>
                <div class="hint">
                    检查磁盘空间、数据库连通性与关键表、目录可写、PHP 扩展、近 24 小时失败日志、备份新鲜度。
                    cron.php 每轮执行时也会自动跑一次，有异常会写入日志。
                </div>
                <div id="stHealth">${canTab.system ? '<div class="loading">检测中...</div>' : '<div class="empty">当前角色无系统维护权限</div>'}</div>
            </section>

            <section class="maint-block">
                <div class="mb-head">
                    <h4>数据备份</h4>
                    ${canTab.system ? `
                    <div class="mb-tools">
                        <select id="stBkMode" title="备份模式">
                            <option value="off">不备份</option>
                            <option value="auto" selected>自动备份</option>
                            <option value="manual">手动备份</option>
                        </select>
                        <span class="mb-sep"></span>
                        <label class="mini-num" title="自动备份间隔（1 ~ 720 小时）">
                            <input id="stBkHours" type="number" min="1" max="720" step="1" value="24" aria-label="备份间隔小时数"><i>小时</i>
                        </label>
                        <label class="mini-num" title="最多保留几份备份，超出自动清理（1 ~ 90 份）">
                            <input id="stBkKeep" type="number" min="1" max="90" step="1" value="7" aria-label="备份保留份数"><i>份</i>
                        </label>
                        <span class="mini-dirty" id="stBkDirty" hidden>未保存</span>
                        <button class="btn ghost sm" id="stBkSave">保存</button>
                        <button class="btn" id="stBackupRun">立即备份</button>
                    </div>` : ''}
                </div>
                <div class="hint">
                    备份为 gzip 压缩的 SQL 全量转储，存放在 <span class="mono">data/backups/</span>（该目录已禁止外部访问）。
                    <span id="stBkModeHint"></span>
                </div>
                <div id="stBackup">${canTab.system ? '<div class="loading">加载中...</div>' : '<div class="empty">当前角色无系统维护权限</div>'}</div>
            </section>
        </div>
    `;

    const panels = { site: pSite, business: pBusiness, security: pSecurity, system: pSystem, maint: pMaint };

    c.innerHTML = `
    <div class="card">
        <div class="tabs" id="stTabs" style="margin:0;padding:12px 20px 0">
            ${TABS.map(t =>
                `<button data-tab="${t.key}"${t.key === curTab ? ' class="on"' : ''}>${t.label}</button>`
            ).join('')}
        </div>
        ${TABS.map(t =>
            `<div class="card-body" id="stP-${t.key}"${t.key === curTab ? '' : ' style="display:none"'}>${panels[t.key]}</div>`
        ).join('')}
    </div>`;

    const switchTab = (key) => {
        curTab = key;
        try { localStorage.setItem('nb_setting_tab_v1', key); } catch (e) {
 }
        c.querySelectorAll('#stTabs button').forEach(b =>
            b.classList.toggle('on', b.dataset.tab === key));
        TABS.forEach(t => {
            const p = document.getElementById('stP-' + t.key);
            if (p) p.style.display = t.key === key ? '' : 'none';
        });
    };
    c.querySelectorAll('#stTabs button').forEach(btn =>
        btn.addEventListener('click', () => switchTab(btn.dataset.tab)));

    if (canTab.system) {
        const bkSel = document.getElementById('stBkMode');
        if (bkSel) bkSel.addEventListener('change', () => { markBkDirty(); syncBkModeUI(); });
        ['stBkHours', 'stBkKeep'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', markBkDirty);
        });
        const bkSave = document.getElementById('stBkSave');
        if (bkSave) bkSave.addEventListener('click', saveBackupCfg);
        loadHealth();
        loadBackup();
    }

    syncBkModeUI();
    const stHealthRun = document.getElementById('stHealthRun');
    if (stHealthRun) stHealthRun.addEventListener('click', loadHealth);
    const stBkRun = document.getElementById('stBackupRun');
    if (stBkRun) stBkRun.addEventListener('click', runBackup);

    document.getElementById('stSaveSite').addEventListener('click', saveSite);

    const gracePubCpp = pem => {
        const lines = String(pem).trim().split(/\r?\n/).map(l => '    "' + l + '\\n"');
        return 'NEBULA_STR(\n' + lines.join('\n') + ')';
    };
    const rotateGraceBtn = document.getElementById('stRotateGraceKey');
    if (rotateGraceBtn) rotateGraceBtn.addEventListener('click', async () => {
        if (!confirm('轮换离线宽限签名密钥？\n\n旧密钥文件将被备份，旧客户端的离线票据立即失效。\n轮换后需要更新并重新发布所有客户端。')) return;
        rotateGraceBtn.disabled = true;
        rotateGraceBtn.textContent = '轮换中…';
        try {
            const res = await api('grace_rotate_keys', {});
            if (res.code !== 0) { toast(res.msg || '轮换失败', 'err'); return; }
            toast('密钥已轮换，kid=' + (res.data?.kid || ''), 'ok');
            setTimeout(() => render(), 600);
        } catch (e) {
            toast('轮换失败：' + (e.message || ''), 'err');
        } finally {
            rotateGraceBtn.disabled = false;
            rotateGraceBtn.textContent = '轮换密钥';
        }
    });

    // 响应签名公钥按钮
    const copyRespPub = document.getElementById('stCopyRespPub');
    if (copyRespPub) copyRespPub.addEventListener('click', () => {
        const pem = ((((d.config || {}).resp_sign || {}).public_key) || '');
        if (!pem) return toast('响应签名公钥尚未生成', 'err');
        copyText(pem).then(() => toast('已复制响应签名公钥 PEM'));
    });
    const copyRespCpp = document.getElementById('stCopyRespCpp');
    if (copyRespCpp) copyRespCpp.addEventListener('click', () => {
        const pem = ((((d.config || {}).resp_sign || {}).public_key) || '');
        if (!pem) return toast('响应签名公钥尚未生成', 'err');
        copyText(gracePubCpp(pem)).then(() => toast('已复制 C++ 片段，可直接替换 kRespSignPubKey'));
    });
    const rotateRespBtn = document.getElementById('stRotateRespKey');
    if (rotateRespBtn) rotateRespBtn.addEventListener('click', async () => {
        if (!confirm('轮换响应签名密钥？\n\n旧密钥文件将被备份，旧 API 响应的签名立即失效。\n轮换后需要更新并重新发布所有客户端。\n\n注意：此操作不影响离线宽限票据。')) return;
        rotateRespBtn.disabled = true;
        rotateRespBtn.textContent = '轮换中…';
        try {
            const res = await api('resp_sign_rotate_keys', {});
            if (res.code !== 0) { toast(res.msg || '轮换失败', 'err'); return; }
            toast('密钥已轮换，kid=' + (res.data?.kid || ''), 'ok');
            setTimeout(() => render(), 600);
        } catch (e) {
            toast('轮换失败：' + (e.message || ''), 'err');
        } finally {
            rotateRespBtn.disabled = false;
            rotateRespBtn.textContent = '轮换密钥';
        }
    });

    const logoBtn = document.getElementById('stLogoUpload');
    if (logoBtn) {
        logoBtn.addEventListener('click', () => {
            const inp = document.createElement('input');
            inp.type = 'file';
            inp.accept = 'image/jpeg,image/png,image/gif,image/webp';
            inp.onchange = async () => {
                const f = inp.files && inp.files[0];
                if (!f) return;
                logoBtn.disabled = true;
                logoBtn.textContent = '上传中…';
                try {
                    const fd = new FormData();
                    fd.append('file', f);
                    const res = await fetch(API_ENTRY + '?action=shop_goods_upload', {
                        method: 'POST',
                        headers: uploadHeaders(),
                        body: fd,
                        credentials: 'same-origin',
                    });
                    const j = await res.json();
                    if (j.code !== 0) throw new Error(j.msg || '上传失败');
                    const logo = document.getElementById('stLogo');
                    if (logo) logo.value = (j.data || {}).url || '';
                    const hint = document.getElementById('stLogoHint');
                    if (hint) hint.textContent = '已上传：' + ((j.data || {}).url || '') + '，点「保存 Logo」生效';
                } catch (e) {
                    toast(e.message || '上传失败', 'err');
                } finally {
                    logoBtn.disabled = false;
                    logoBtn.textContent = '上传图片';
                }
            };
            inp.click();
        });
    }

    const saveLogoBtn = document.getElementById('stSaveLogo');
    if (saveLogoBtn) {
        saveLogoBtn.addEventListener('click', async () => {
            const url = document.getElementById('stLogo').value.trim();
            const res = await api('shop_setting_save', { settings: { shop_logo: url } });
            if (res.code === 0) toast('Logo 已保存');
        });
    }

    document.getElementById('stSaveBiz').addEventListener('click', saveBusiness);
    document.getElementById('stSaveSec').addEventListener('click', saveSecurity);
    document.getElementById('stSaveSys').addEventListener('click', saveSystem);
}

async function saveSite() {
    const res = await api('setting_save', {
        settings: {
            site_name: document.getElementById('stName').value.trim(),
            register_enable: document.getElementById('stReg').value,
            maintain_mode: document.getElementById('stMaint').value,
            maintain_msg: document.getElementById('stMaintMsg').value.trim(),
            contact: document.getElementById('stContact').value.trim(),
            web_message_board: document.getElementById('stMsgBoard').value,
            web_total_blank: document.getElementById('stTotalBlank').value,
        },
    });
    if (res.code === 0) toast('站点设置已保存');
}

async function saveBusiness() {
    const price = document.getElementById('stAgPrice').value.trim();
    if (price !== '' && (isNaN(Number(price)) || Number(price) < 0)) {
        toast('默认单价需为不小于 0 的数字', 'warn');
        return;
    }
    const ptsMin = document.getElementById('stPtsMin').value;
    if (isNaN(Number(ptsMin)) || Number(ptsMin) < 1) {
        toast('在线扣点间隔需为不小于 1 的分钟数', 'warn');
        return;
    }
    const res = await api('setting_save', {
        settings: {
            default_max_devices: document.getElementById('stDefDev').value,
            register_gift_days: document.getElementById('stRegDays').value,
            register_gift_points: document.getElementById('stRegPts').value,
            agent_enable: document.getElementById('stAgEnable').value,
            agent_register_enable: document.getElementById('stAgReg').value,
            agent_unit_price: price === '' ? '0' : price,
            agent_entry_key: document.getElementById('stAgEntry').value.trim(),
            points_deduct_mode: document.getElementById('stPtsMode').value,
            points_deduct_minutes: ptsMin,
        },
    });
    if (res.code === 0) toast('业务设置已保存');
}

async function saveSecurity() {

    const rules = [
        ['stHb',      '心跳间隔',   1, 3600],
        ['stTimeout', '离线判定',   1, 86400],
        ['stSessionTtl','登录态有效期', 0, 604800],
        ['stUnbind',  '每日解绑上限', 0, 9999],
        ['stRate',    '接口限流',   1, 100000],
        ['stGraceSec','单次宽限时',  0, 86400],
        ['stGraceMax','累计上限',    0, 604800],
    ];
    for (const [id, name, min, max] of rules) {
        const raw = document.getElementById(id).value.trim();
        const n = Number(raw);
        if (raw === '' || !Number.isFinite(n) || !Number.isInteger(n) || n < min || n > max) {
            return toast(`${name}需为 ${min} ~ ${max} 之间的整数`, 'warn');
        }
    }
    const res = await api('setting_save', {
        settings: {
            login_methods: document.getElementById('stLoginMethod').value,
            login_reclaim_enable: document.getElementById('stReclaim').value,
            single_login: document.getElementById('stSingle').value,
            geo_block: document.getElementById('stGeo').value,
            heartbeat_interval: document.getElementById('stHb').value.trim(),
            heartbeat_timeout: document.getElementById('stTimeout').value.trim(),
            session_ttl: document.getElementById('stSessionTtl').value.trim(),
            unbind_per_day: document.getElementById('stUnbind').value.trim(),
            rate_limit_per_min: document.getElementById('stRate').value.trim(),
            web_reg_max_hour: document.getElementById('stWebRegMax').value.trim(),
            web_act_cooldown_min: document.getElementById('stWebActCd').value.trim(),
            web_act_max_min: document.getElementById('stWebActMax').value.trim(),
            guard_enabled: document.getElementById('stGuard').value,
            ip_blacklist: document.getElementById('stIpBlacklist').value,
            grace_enable: document.getElementById('stGraceEnable').value,
            grace_seconds: document.getElementById('stGraceSec').value.trim(),
            grace_max_seconds: document.getElementById('stGraceMax').value.trim(),
        },
    });
    if (res.code === 0) {
        toast('安全设置已保存');
        render();
    }
}

async function saveSystem() {

    const rules = [
        ['stCachePort', 'Redis 端口', 1, 65535],
        ['stCacheDb',   'Redis 库号', 0, 15],
    ];
    for (const [id, name, min, max] of rules) {
        const raw = document.getElementById(id).value.trim();
        const n = Number(raw);
        if (raw === '' || !Number.isFinite(n) || !Number.isInteger(n) || n < min || n > max) {
            return toast(`${name}需为 ${min} ~ ${max} 之间的整数`, 'warn');
        }
    }
    const res = await api('setting_save', {
        settings: {
            cache_driver: document.getElementById('stCacheDriver').value,
            cache_redis_host: document.getElementById('stCacheHost').value.trim(),
            cache_redis_port: document.getElementById('stCachePort').value.trim(),
            cache_redis_password: document.getElementById('stCachePass').value,
            cache_redis_database: document.getElementById('stCacheDb').value.trim(),
        },
    });
    if (res.code === 0) {
        toast('系统设置已保存，缓存立即生效');
        render();
    }
}

function lvTag(level) {
    if (level === 'ok') return tag('正常', 'green');
    if (level === 'warn') return tag('警告', 'yellow');
    return tag('异常', 'red');
}

function failBox(msg, retryId) {
    return `<div class="empty">${esc(msg)}<div style="margin-top:10px">`
        + `<button class="btn ghost sm" id="${retryId}">重试</button></div></div>`;
}

function withTimeout(promise, ms) {
    let timer;
    return Promise.race([
        promise,
        new Promise((_, reject) => {
            timer = setTimeout(() => reject(new Error('请求超时（' + Math.round(ms / 1000) + 's 无响应）')), ms);
        }),
    ]).finally(() => clearTimeout(timer));
}

function bindRetry(box, id, fn) {
    const b = box.querySelector('#' + id);
    if (b) b.addEventListener('click', fn);
}

let bkDirty = false;

function bkMode() {
    const el = document.getElementById('stBkMode');
    return el ? el.value : '';
}

const BK_HINT = {
    off:    '当前为「不备份」：cron 与后台都不会生成备份。',
    auto:   'cron 按上方周期自动备份；超出保留份数的旧备份会自动清理。',
    manual: '只在点击「立即备份」时生成，cron 不会自动备份；超出保留份数的旧备份会自动清理。',
};

function markBkDirty() {
    bkDirty = true;
    const dot = document.getElementById('stBkDirty');
    if (dot) dot.hidden = false;
}

function clearBkDirty() {
    bkDirty = false;
    const dot = document.getElementById('stBkDirty');
    if (dot) dot.hidden = true;
}

function syncBkModeUI() {
    const mode = bkMode() || 'auto';

    const hours = document.getElementById('stBkHours');
    if (hours) {
        const on = mode === 'auto';
        hours.disabled = !on;
        hours.title = on ? '自动备份间隔（1 ~ 720 小时）' : '仅「自动备份」需要填间隔';
        const lab = hours.closest('label');
        if (lab) lab.classList.toggle('off', !on);
    }

    const hint = document.getElementById('stBkModeHint');
    if (hint) hint.textContent = BK_HINT[mode] || BK_HINT.auto;

    const run = document.getElementById('stBackupRun');
    if (run) {
        run.disabled = mode === 'off';
        run.title = mode === 'off' ? '当前为「不备份」模式，请先改为「自动备份」或「手动备份」' : '';
    }
}

function fillBackupCfg(d) {
    const sel = document.getElementById('stBkMode');
    if (!sel || bkDirty) return;
    const mode = ['off', 'auto', 'manual'].indexOf(d.mode) >= 0 ? d.mode : 'auto';
    sel.value = mode;
    const hours = document.getElementById('stBkHours');
    if (hours) hours.value = String(d.interval_hours || 24);
    const keep = document.getElementById('stBkKeep');
    if (keep) keep.value = String(d.keep || 7);
    syncBkModeUI();
}

async function saveBackupCfg() {
    const mode = bkMode();
    if (!mode) { toast('请先选择备份模式', 'warn'); return; }
    let hours = parseInt((document.getElementById('stBkHours') || {}).value, 10);

    if (mode !== 'auto' && !(hours >= 1 && hours <= 720)) hours = 24;
    const keep = parseInt((document.getElementById('stBkKeep') || {}).value, 10);
    if (!(hours >= 1 && hours <= 720)) { toast('自动备份间隔需在 1 ~ 720 小时之间', 'warn'); return; }
    if (!(keep >= 1 && keep <= 90)) { toast('备份保留份数需在 1 ~ 90 份之间', 'warn'); return; }

    const btn = document.getElementById('stBkSave');
    if (btn) { btn.disabled = true; btn.textContent = '保存中...'; }
    try {
        const res = await api('system_maintenance', { op: 'backup_save', mode, interval_hours: hours, keep });
        if (res && res.code === 0) {
            clearBkDirty();
            toast(res.msg || '备份设置已保存');
        } else {
            toast((res && res.msg) || '保存失败', 'err');
        }
    } catch (e) {
        toast('保存失败：' + ((e && e.message) || '网络异常'), 'err');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = '保存'; }
        loadBackup();
        loadHealth();
    }
}

function bkEmptyText(mode) {
    if (mode === 'off') return '当前为「不备份」模式，不会生成任何备份。';
    if (mode === 'manual') return '暂无备份记录。当前为「手动备份」模式，点上方「立即备份」即可生成一份。';
    return '暂无备份记录。备份由 cron.php 按周期自动执行，也可点上方「立即备份」生成一份。';
}

async function loadHealth() {
    const box = document.getElementById('stHealth');
    if (!box) return;
    box.innerHTML = '<div class="loading">检测中...</div>';
    try {
        let res;
        try {
            res = await withTimeout(api('system_maintenance', { op: 'health' }), 20000);
        } catch (e) {
            throw new Error('网络异常（' + ((e && e.message) || '请求失败') + '）');
        }
        if (!res || res.code !== 0) {
            throw new Error((res && res.msg) || '无权限或接口异常');
        }
        const h = res.data || {};
        const checks = Array.isArray(h.checks) ? h.checks : [];
        box.innerHTML = `
            <div class="kv" style="margin-bottom:12px">
                <span class="k">巡检结果</span><span class="v">${lvTag(h.level)} ${esc(h.summary || '')}</span>
                <span class="k">检测时间</span><span class="v">${esc(h.time_text || '')}</span>
            </div>
            <div class="table-wrap"><table>
                <thead><tr><th>项目</th><th style="width:90px">状态</th><th>说明</th></tr></thead>
                <tbody>${checks.map(x => `
                    <tr>
                        <td>${esc(x.name)}</td>
                        <td>${lvTag(x.level)}</td>
                        <td style="color:#6b7280">${esc(x.text)}</td>
                    </tr>`).join('')}</tbody>
            </table></div>`;
    } catch (e) {

        box.innerHTML = failBox('巡检失败：' + ((e && e.message) || e), 'stHealthRetry');
        bindRetry(box, 'stHealthRetry', loadHealth);
    }
}

async function loadBackup() {
    const box = document.getElementById('stBackup');
    if (!box) return;
    box.innerHTML = '<div class="loading">加载中...</div>';
    try {
        let res;
        try {
            res = await withTimeout(api('system_maintenance', { op: 'backup_list' }), 20000);
        } catch (e) {
            throw new Error('网络异常（' + ((e && e.message) || '请求失败') + '）');
        }
        if (!res || res.code !== 0) {
            throw new Error((res && res.msg) || '无权限或接口异常');
        }

        const d = res.data || {};
        const list = Array.isArray(d.list) ? d.list : [];

        fillBackupCfg(d);

        const mode = d.mode || (d.enabled ? 'auto' : 'off');
        const modeTag = mode === 'off' ? tag('不备份', 'gray')
            : (mode === 'manual' ? tag('手动备份', 'yellow') : tag('自动备份', 'green'));
        const modeText = mode === 'auto'
            ? ` · 每 ${esc(String(d.interval_hours))} 小时 · 保留 ${esc(String(d.keep))} 份`
            : (mode === 'manual' ? ' · 仅手动触发，cron 不自动备份' : ' · cron 与后台都不生成备份');

        box.innerHTML = `
            <div class="kv" style="margin-bottom:12px">
                <span class="k">已保存模式</span><span class="v">${modeTag}${modeText}</span>
                <span class="k">最近备份</span><span class="v">${esc(d.last_text || '暂无')}</span>
                <span class="k">备份份数</span><span class="v">共 ${esc(String(d.total || 0))} 份 · 存放在 <span class="mono">${esc(d.dir || 'data/backups')}</span></span>
            </div>
            ${list.length ? `<div class="table-wrap"><table>
                <thead><tr><th>文件</th><th style="width:100px">大小</th><th style="width:170px">时间</th><th style="width:150px">操作</th></tr></thead>
                <tbody>${list.map(f => `
                    <tr>
                        <td class="mono" style="font-size:12px">${esc(f.name)}</td>
                        <td>${esc(f.size_text)}</td>
                        <td class="mono" style="font-size:12px">${esc(f.time_text)}</td>
                        <td style="white-space:nowrap">${canMaint()
                            ? `<button class="btn ghost sm" data-dl="${esc(f.name)}">下载</button>
                               <button class="btn danger sm" data-del="${esc(f.name)}">删除</button>`
                            : ''}</td>
                    </tr>`).join('')}</tbody>
            </table></div>`
            : `<div class="empty">${esc(bkEmptyText(mode))}</div>`}`;

        box.querySelectorAll('button[data-dl]').forEach(b =>
            b.addEventListener('click', () => dlBackup(b.dataset.dl, b)));
        box.querySelectorAll('button[data-del]').forEach(b =>
            b.addEventListener('click', () => delBackup(b.dataset.del, b)));
    } catch (e) {

        box.innerHTML = failBox('备份信息读取失败：' + ((e && e.message) || e), 'stBackupRetry');
        bindRetry(box, 'stBackupRetry', loadBackup);
    }
}

async function runBackup() {
    if (bkMode() === 'off') {
        toast('当前为「不备份」模式，请先切换为「自动备份」或「手动备份」', 'warn');
        return;
    }
    const btn = document.getElementById('stBackupRun');
    if (btn) { btn.disabled = true; btn.textContent = '备份中...'; }
    try {
        const res = await api('system_maintenance', { op: 'backup_run' });
        if (res && res.code === 0) toast(res.msg || '备份完成');
        else toast((res && res.msg) || '备份失败', 'err');
    } catch (e) {
        toast('备份请求失败：' + ((e && e.message) || '网络异常'), 'err');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = '立即备份'; }
        loadBackup();
        loadHealth();
    }
}

function dlBackup(name, btn) {
    confirmPassword(
        '下载数据库备份',
        '备份是整库转储，包含全部卡密、账号与密钥信息。下载前请确认是本人操作，且文件会保存到安全位置。',
        async (pwd) => {
            if (btn) { btn.disabled = true; btn.textContent = '下载中...'; }
            try {
                const { blob, name: fn } = await apiDownload(
                    'system_maintenance',
                    { op: 'backup_download', name, confirm_pwd: pwd },
                    name);
                downloadBlob(blob, fn || name);
                toast('已开始下载 ' + (fn || name));
            } catch (e) {
                toast('下载失败：' + ((e && e.message) || '网络异常'), 'err');
            } finally {
                if (btn) { btn.disabled = false; btn.textContent = '下载'; }
            }
        });
}

async function delBackup(name, btn) {
    if (btn.dataset.armed !== '1') {
        btn.dataset.armed = '1';
        btn.textContent = '确认删除';
        setTimeout(() => {
            if (btn.dataset.armed === '1') {
                btn.dataset.armed = '';
                btn.textContent = '删除';
            }
        }, 4000);
        return;
    }
    try {
        const res = await api('system_maintenance', { op: 'backup_delete', name });
        if (res && res.code === 0) {
            toast('已删除');
            loadBackup();
        } else {
            toast((res && res.msg) || '删除失败', 'err');
        }
    } catch (e) {
        toast('删除失败：' + ((e && e.message) || '网络异常'), 'err');
    }
}
