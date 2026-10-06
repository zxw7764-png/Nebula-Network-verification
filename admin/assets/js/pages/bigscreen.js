import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc } from '../core/util.js';
import { multiLineChart, rankingBars, donutChart, fmtNum, PALETTE } from '../core/chart.js';

register('bigscreen', render);

let timer = null;

/** 两列自适应网格（窄屏自动单列）；默认 stretch 让同行卡片等高，避免长短不齐 */
const COLS = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,440px),1fr));gap:16px';

/** KPI 统计行（沿用后台 .stats/.stat 原生组件：label 在上、value 在下） */
function kpis(items) {
    return `<div class="stats" style="margin-bottom:0">${items.map((it, i) => `
        <div class="stat c${(i % 4) + 1}">
            <div class="label">${esc(it.label)}</div>
            <div class="value">${esc(String(it.value))}${it.unit ? `<span style="font-size:13px;font-weight:600;color:var(--text-sub);margin-left:2px">${esc(it.unit)}</span>` : ''}</div>
            ${it.extra ? `<div class="extra">${esc(it.extra)}</div>` : ''}
        </div>`).join('')}</div>`;
}

function panel(title, body, acts = '') {
    return `<section class="card" style="margin-bottom:0">
        <div class="card-head"><h3>${esc(title)}</h3>${acts ? `<div class="acts">${acts}</div>` : ''}</div>
        <div class="card-body" style="min-height:232px;display:flex;flex-direction:column;justify-content:center">${body}</div>
    </section>`;
}

/** 图表区空态：说明「为什么空」+「怎么才有数据」，而不是只有一个图标 */
function chartEmpty(icon, text, hint) {
    return `<div class="empty" style="padding:44px 16px">
        <div class="icon"><i class="bi ${icon}"></i></div>
        <div style="font-size:13px">${esc(text)}</div>
        ${hint ? `<div style="font-size:12px;color:var(--text-hint,#9aa1af);margin-top:6px">${esc(hint)}</div>` : ''}
    </div>`;
}

function kvRows(pairs) {
    return `<div class="kv">${pairs.map(([k, v]) => `<div class="k">${esc(k)}</div><div class="v">${esc(String(v))}</div>`).join('')}</div>`;
}

async function load(el) {
    if (!el.isConnected) { // 已切走页面：停止刷新
        if (timer) { clearInterval(timer); timer = null; }
        return;
    }
    const days = parseInt(el.querySelector('#bsDays')?.value || '30', 10);
    const hours = parseInt(el.querySelector('#bsHours')?.value || '24', 10);
    const res = await api('bigscreen', { days, hours }).catch(() => null);
    const body = el.querySelector('#bsBody');
    if (!body) return;
    if (!res || res.code !== 0) {
        body.innerHTML = empty('<i class="bi bi-tv"></i>', res?.msg || '数据大屏加载失败，请稍后重试');
        return;
    }
    const d = res.data || {};
    const rt = d.realtime || {}, td = d.today || {}, tt = d.total || {}, rg = d.runtime || {};

    // ---- 核心 KPI（一屏可扫） ----
    const kpi = kpis([
        { label: '在线会话', value: rt.online ?? 0 },
        { label: '在线用户', value: rt.online_users ?? 0 },
        { label: '在线设备', value: rt.online_devices ?? 0 },
        { label: '今日新增用户', value: td.new_users ?? 0 },
        { label: '今日激活', value: td.activations ?? 0 },
        { label: '今日登录', value: td.logins ?? 0 },
        { label: '今日 API 调用', value: fmtNum(td.api_calls ?? 0), extra: `失败 ${fmtNum(td.api_fails ?? 0)}` },
        { label: '今日代理充值', value: td.recharge ?? 0 },
    ]);

    // ---- 曲线 ----
    const oc = (d.online_curve || []).map(p => ({ label: p.label, online: p.online, devices: p.devices }));
    const onlinePanel = panel('在线曲线', oc.length
        ? multiLineChart(oc, [
            { key: 'online', name: '在线会话', color: 'var(--primary-strong)' },
            { key: 'devices', name: '在线设备', color: '#10b981' },
        ], { h: 230 })
        : chartEmpty('bi-activity', '暂无在线曲线数据', '该数据由定时任务写入 nb_online_stats（5 分钟/点），配置 cron 后自动出现'),
        `<span class="text-hint">近 ${hours} 小时</span>`);

    const cv = (d.curves || []).map(p => ({
        label: p.label, new_users: p.new_users, activations: p.activations, api_calls: p.api_calls,
    }));
    const curvePanel = panel('增长曲线', cv.length
        ? multiLineChart(cv, [
            { key: 'new_users', name: '新增用户', color: 'var(--primary-strong)' },
            { key: 'activations', name: '激活', color: '#10b981' },
            { key: 'api_calls', name: 'API 调用', color: '#f59e0b' },
        ], { h: 230 })
        : chartEmpty('bi-graph-up', '暂无数据', '近 N 天的注册 / 激活 / 调用量'),
        `<span class="text-hint">近 ${days} 天</span>`);

    // ---- 排行 / 分布 ----
    const rankPanel = panel('代理销量排行', d.agent_rank && d.agent_rank.length
        ? rankingBars(d.agent_rank.map(a => ({
            name: a.name, value: a.generated,
            sub: `生成 ${fmtNum(a.generated)} · 已用 ${fmtNum(a.used)} / 未用 ${fmtNum(a.unused)}`,
        })))
        : chartEmpty('bi-diagram-3', '暂无代理销量', '近 ' + days + ' 天没有代理商生成卡密'),
        `<span class="text-hint">TOP 10 · 近 ${days} 天</span>`);

    const distPanel = panel('卡密类型分布', d.type_dist && d.type_dist.length
        ? donutChart(d.type_dist.map((t, i) => ({ label: t.name, value: t.total, color: PALETTE[i % PALETTE.length] })), { centerLabel: '卡密总数', size: 190 })
        : chartEmpty('bi-pie-chart', '暂无卡密', '生成卡密后此处展示类型占比'));

    // ---- 运行状态 / 累计总量 ----
    const cache = rg.cache || {}, hb = rg.heartbeat || {};
    const runtimePanel = panel('运行状态与累计总量', `
        <div class="stats" style="margin-bottom:16px">
            <div class="stat"><div class="label">缓存驱动</div><div class="value" style="font-size:18px">${esc(cache.driver || '-')}</div></div>
            <div class="stat"><div class="label">心跳待落库</div><div class="value" style="font-size:18px">${fmtNum(hb.pending ?? 0)}</div></div>
            <div class="stat"><div class="label">统计缓冲</div><div class="value" style="font-size:18px">${fmtNum(rg.stat_buffer ?? 0)}</div></div>
        </div>
        ${kvRows([
            ['用户', `${fmtNum(tt.users ?? 0)}（激活 ${fmtNum(tt.users_active ?? 0)}）`],
            ['卡密', `${fmtNum(tt.cards ?? 0)}（未用 ${fmtNum(tt.cards_unused ?? 0)} / 已用 ${fmtNum(tt.cards_used ?? 0)}）`],
            ['代理商', fmtNum(tt.agents ?? 0)],
            ['绑定设备', fmtNum(tt.devices ?? 0)],
        ])}`);

    const logs = (d.recent_logs || []).map(l => `
        <tr>
            <td class="mono">${esc(l.action)}</td>
            <td>${l.result == 1 ? '<span class="tag green">成功</span>' : '<span class="tag red">失败</span>'}</td>
            <td style="max-width:240px"><div class="cell-content" style="max-width:240px;-webkit-line-clamp:1">${esc(l.message || '')}</div></td>
            <td class="mono">${esc(l.time_text || '')}</td>
        </tr>`).join('');
    const recentPanel = panel('最近动态', logs ? `
        <div class="table-wrap"><table>
            <thead><tr><th>动作</th><th>结果</th><th>信息</th><th>时间</th></tr></thead>
            <tbody>${logs}</tbody></table>
        </div>` : chartEmpty('bi-journal-text', '暂无操作记录'));

    const srv = d.server || {};
    body.innerHTML = `
        ${kpi}
        <div style="${COLS};margin-top:16px">${onlinePanel}${curvePanel}</div>
        <div style="${COLS};margin-top:16px">${rankPanel}${distPanel}</div>
        <div style="${COLS};margin-top:16px">${runtimePanel}${recentPanel}</div>
        <div style="margin-top:14px;text-align:right;font-size:12px;color:var(--text-hint,#9aa1af)">
            ${esc(srv.site_name || 'Nebula')} · 服务器时间 ${esc(srv.time || '')} · PHP ${esc(srv.php_version || '')}
        </div>`;
}

async function render() {
    const el = document.getElementById('content');
    if (!el) return;
    el.innerHTML = `
    <div class="toolbar" style="justify-content:space-between;margin-bottom:16px">
        <div class="toolbar" style="gap:10px;align-items:center">
            <select id="bsDays" title="曲线统计天数">
                ${[30, 14, 7, 60, 90].map(n => `<option value="${n}" ${n === 30 ? 'selected' : ''}>近 ${n} 天</option>`).join('')}
            </select>
            <select id="bsHours" title="在线曲线回溯小时">
                ${[24, 12, 6, 48, 72].map(n => `<option value="${n}" ${n === 24 ? 'selected' : ''}>近 ${n} 小时</option>`).join('')}
            </select>
            <span class="text-hint" id="bsMeta">自动刷新 30s</span>
        </div>
        <div class="toolbar" style="gap:10px;align-items:center">
            <button class="btn sm ghost" id="bsFlush">清空缓存</button>
        </div>
    </div>
    <div id="bsBody">${loading('正在加载数据大屏…')}</div>`;

    el.querySelector('#bsDays').addEventListener('change', () => load(el));
    el.querySelector('#bsHours').addEventListener('change', () => load(el));
    el.querySelector('#bsFlush').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        const res = await api('bigscreen', { op: 'flush_cache' }).catch(() => null);
        btn.disabled = false;
        const meta = el.querySelector('#bsMeta');
        if (meta) meta.textContent = res?.code === 0 ? `已清空 ${res.data?.count ?? 0} 个缓存键` : (res?.msg || '仅超级管理员可清空缓存');
    });

    await load(el);
    if (timer) clearInterval(timer);
    timer = setInterval(() => load(el), 30000);
}