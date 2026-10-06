import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc } from '../core/util.js';
import { areaChart, multiLineChart, fmtNum } from '../core/chart.js';

register('analytics', render);

let timer = null;

/** 两列自适应网格（窄屏自动单列）；默认 stretch 让同行卡片等高 */
const COLS = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,440px),1fr));gap:16px';

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

function chartEmpty(icon, text, hint) {
    return `<div class="empty" style="padding:44px 16px">
        <div class="icon"><i class="bi ${icon}"></i></div>
        <div style="font-size:13px">${esc(text)}</div>
        ${hint ? `<div style="font-size:12px;color:var(--text-hint,#9aa1af);margin-top:6px">${esc(hint)}</div>` : ''}
    </div>`;
}

function pctCell(v, pct) {
    if (v === null || v === undefined) return '<span style="color:var(--text-hint,#9aa1af)">—</span>';
    const p = pct != null ? ` <span class="text-hint">${pct}%</span>` : '';
    return `${fmtNum(v)}${p}`;
}

async function load(el) {
    if (!el.isConnected) {
        if (timer) { clearInterval(timer); timer = null; }
        return;
    }
    const days = parseInt(el.querySelector('#anDays')?.value || '30', 10);
    const res = await api('analytics', { days }).catch(() => null);
    const body = el.querySelector('#anBody');
    if (!body) return;
    if (!res || res.code !== 0) {
        body.innerHTML = empty('<i class="bi bi-graph-up-arrow"></i>', res?.msg || '统计加载失败，请稍后重试');
        return;
    }
    const d = res.data || {};
    const ret = d.retention || {}, sum = ret.summary || {}, tier = d.tier || {}, rp = d.repurchase || {};

    // ---- 核心 KPI ----
    const kpi = kpis([
        { label: '队列用户', value: sum.cohorts ?? 0, unit: '', extra: `满 7 天队列均值` },
        { label: 'D1 留存', value: sum.d1 ?? 0, unit: '%' },
        { label: 'D3 留存', value: sum.d3 ?? 0, unit: '%' },
        { label: 'D7 留存', value: sum.d7 ?? 0, unit: '%' },
        { label: '今日活跃', value: tier.today ?? 0 },
        { label: '7 天活跃', value: tier.d7 ?? 0 },
        { label: '30 天活跃', value: tier.d30 ?? 0 },
        { label: '30 天沉默', value: tier.silent ?? 0, extra: `总用户 ${fmtNum(tier.total ?? 0)}` },
    ]);

    // ---- 留存队列表 ----
    const rows = (ret.list || []).map(c => `
        <tr>
            <td class="mono">${esc(c.date)}</td>
            <td>${fmtNum(c.total)}</td>
            <td>${pctCell(c.d1, c.d1_pct)}</td>
            <td>${pctCell(c.d3, c.d3_pct)}</td>
            <td>${pctCell(c.d7, c.d7_pct)}</td>
        </tr>`).join('');
    const retPanel = panel('留存队列', rows ? `
        <div class="table-wrap"><table>
            <thead><tr><th>注册日</th><th>新增</th><th>D1</th><th>D3</th><th>D7</th></tr></thead>
            <tbody>${rows}</tbody></table>
        </div>` : chartEmpty('bi-table', '暂无数据', '注册用户后次日开始形成留存队列（未满周期显示 —）'));

    // ---- 逐日活跃 ----
    const dauData = (d.dau || []).map(p => ({ label: p.label, users: p.users, logins: p.logins }));
    const dauPanel = panel('逐日活跃', dauData.length
        ? multiLineChart(dauData, [
            { key: 'users', name: '活跃用户', color: 'var(--primary-strong)' },
            { key: 'logins', name: '登录次数', color: '#f59e0b' },
        ], { h: 230 })
        : chartEmpty('bi-people', '暂无活跃数据', '近 N 天内有登录行为的去重用户',),
        `<span class="text-hint">近 ${days} 天</span>`);

    // ---- 复购 ----
    const u = rp.user || {}, a = rp.agent || {};
    const repPanel = panel('复购率', `
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px">
            <div>
                <div style="font-size:13px;color:var(--text-sub);margin-bottom:10px">用户 · 激活 ≥ 2 张卡</div>
                <div class="stats" style="margin-bottom:0">
                    <div class="stat c1"><div class="label">购买用户</div><div class="value">${fmtNum(u.buyers ?? 0)}</div></div>
                    <div class="stat c2"><div class="label">复购率</div><div class="value">${u.rate ?? 0}<span style="font-size:13px">%</span></div></div>
                    <div class="stat c3"><div class="label">仅 1 张</div><div class="value">${fmtNum(u.once ?? 0)}</div></div>
                    <div class="stat c4"><div class="label">≥ 6 张</div><div class="value">${fmtNum(u.six_plus ?? 0)}</div></div>
                </div>
            </div>
            <div>
                <div style="font-size:13px;color:var(--text-sub);margin-bottom:10px">代理商 · 充值 ≥ 2 次</div>
                <div class="stats" style="margin-bottom:0">
                    <div class="stat c1"><div class="label">代理数</div><div class="value">${fmtNum(a.agents ?? 0)}</div></div>
                    <div class="stat c2"><div class="label">复充率</div><div class="value">${a.rate ?? 0}<span style="font-size:13px">%</span></div></div>
                    <div class="stat c3"><div class="label">仅 1 次</div><div class="value">${fmtNum(a.once ?? 0)}</div></div>
                    <div class="stat c4"><div class="label">≥ 5 次</div><div class="value">${fmtNum(a.five_plus ?? 0)}</div></div>
                </div>
            </div>
        </div>`);

    // ---- 代理充值走势 ----
    const rc = (d.recharge_trend || []).map(p => ({ label: p.label, value: p.count }));
    const rcPanel = panel('代理充值走势', rc.length
        ? areaChart(rc, { color: '#10b981', fill: 'rgba(16,185,129,.14)', h: 230 })
        : chartEmpty('bi-cash-stack', '暂无充值数据', '近 ' + days + ' 天代理充值次数'),
        `<span class="text-hint">近 ${days} 天</span>`);

    body.innerHTML = `
        ${kpi}
        <div style="${COLS};margin-top:16px">${retPanel}${dauPanel}</div>
        <div style="${COLS};margin-top:16px">${repPanel}${rcPanel}</div>`;
}

async function render() {
    const el = document.getElementById('content');
    if (!el) return;
    el.innerHTML = `
    <div class="toolbar" style="justify-content:space-between;margin-bottom:16px">
        <div class="toolbar" style="gap:10px;align-items:center">
            <select id="anDays" title="统计天数">
                ${[30, 14, 7, 60, 90].map(n => `<option value="${n}" ${n === 30 ? 'selected' : ''}>近 ${n} 天</option>`).join('')}
            </select>
            <span class="text-hint">缓存 60s · 自动刷新</span>
        </div>
    </div>
    <div id="anBody">${loading('正在加载留存复购统计…')}</div>`;

    el.querySelector('#anDays').addEventListener('change', () => load(el));
    await load(el);
    if (timer) clearInterval(timer);
    timer = setInterval(() => load(el), 60000);
}