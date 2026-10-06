import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc } from '../core/util.js';
import { areaChart, multiLineChart, fmtNum } from '../core/chart.js';

register('analytics', render);

let timer = null;

function card(title, body) {
    return `<div class="card"><div class="card-head">${esc(title)}</div><div class="card-body">${body}</div></div>`;
}

function grid(children, min = '320px') {
    return `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(${min},1fr));gap:14px;margin-top:14px">${children}</div>`;
}

async function load(el) {
    const days = parseInt(el.querySelector('#anDays')?.value || '30', 10);
    const res = await api('analytics', { days }).catch(() => null);
    const body = el.querySelector('#anBody');
    if (!res || res.code !== 0) {
        if (body) body.innerHTML = empty('<i class="bi bi-graph-up-arrow"></i>', res?.msg || '统计加载失败');
        return;
    }
    const d = res.data || {};
    const ret = d.retention || {}, sum = ret.summary || {}, tier = d.tier || {}, rp = d.repurchase || {};

    // 留存概览
    const retSum = statsCards('留存概览（满 7 天队列均值）', [
        { label: '队列用户', value: sum.cohorts },
        { label: 'D1 留存', value: (sum.d1 ?? 0) + '%' },
        { label: 'D3 留存', value: (sum.d3 ?? 0) + '%' },
        { label: 'D7 留存', value: (sum.d7 ?? 0) + '%' },
    ]);

    // 留存队列明细
    const rows = (ret.list || []).map(c => `
        <tr>
            <td class="mono">${esc(c.date)}</td>
            <td>${fmtNum(c.total)}</td>
            <td>${ageCell(c.d1, c.d1_pct)}</td>
            <td>${ageCell(c.d3, c.d3_pct)}</td>
            <td>${ageCell(c.d7, c.d7_pct)}</td>
        </tr>`).join('');
    const retTable = card('留存队列（注册日 → D1/D3/D7）', rows ? `
        <div class="table-wrap"><table>
            <thead><tr><th>注册日</th><th>新增</th><th>D1</th><th>D3</th><th>D7</th></tr></thead>
            <tbody>${rows}</tbody></table>
        </div>` : empty('<i class="bi bi-table"></i>', '暂无数据'));

    // 活跃分层
    const tierCards = statsCards('活跃分层（30 天）', [
        { label: '今日活跃', value: tier.today },
        { label: '7 天活跃', value: tier.d7 },
        { label: '30 天活跃', value: tier.d30 },
        { label: '30 天沉默', value: tier.silent },
        { label: '总用户', value: tier.total },
    ]);

    // DAU 曲线
    const dauData = (d.dau || []).map(p => ({ label: p.label, users: p.users, logins: p.logins }));
    const dauChart = card('逐日活跃（近 ' + days + ' 天）', dauData.length
        ? multiLineChart(dauData, [
            { key: 'users', name: '活跃用户', color: 'var(--primary-strong)' },
            { key: 'logins', name: '登录次数', color: '#f59e0b' },
        ])
        : empty('<i class="bi bi-people"></i>', '暂无数据'));

    // 代理充值趋势
    const rc = (d.recharge_trend || []).map(p => ({ label: p.label, value: p.count }));
    const rcChart = card('代理充值走势（近 ' + days + ' 天）', rc.length
        ? areaChart(rc, { color: '#10b981', fill: 'rgba(16,185,129,.14)', unit: ' 次' })
        : empty('<i class="bi bi-cash-stack"></i>', '暂无数据'));

    // 复购
    const u = rp.user || {}, a = rp.agent || {};
    const repurchase = card('复购率', `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            <div>
                <div style="font-size:13px;color:var(--text-sub);margin-bottom:8px">用户（按激活 ≥2 张卡）</div>
                <div class="stats">
                    <div class="stat c1"><div class="num">${fmtNum(u.buyers)}</div><div class="label">购买用户</div></div>
                    <div class="stat c2"><div class="num">${(u.rate ?? 0)}%</div><div class="label">复购率</div></div>
                    <div class="stat c3"><div class="num">${fmtNum(u.once)}</div><div class="label">仅 1 张</div></div>
                    <div class="stat c4"><div class="num">${fmtNum(u.six_plus)}</div><div class="label">≥6 张</div></div>
                </div>
            </div>
            <div>
                <div style="font-size:13px;color:var(--text-sub);margin-bottom:8px">代理商（按充值 ≥2 次）</div>
                <div class="stats">
                    <div class="stat c1"><div class="num">${fmtNum(a.agents)}</div><div class="label">代理数</div></div>
                    <div class="stat c2"><div class="num">${(a.rate ?? 0)}%</div><div class="label">复充率</div></div>
                    <div class="stat c3"><div class="num">${fmtNum(a.once)}</div><div class="label">仅 1 次</div></div>
                    <div class="stat c4"><div class="num">${fmtNum(a.five_plus)}</div><div class="label">≥5 次</div></div>
                </div>
            </div>
        </div>`);

    body.innerHTML = grid([retSum, tierCards]) + grid([retTable, dauChart]) + grid([repurchase, rcChart]);
}

function statsCards(title, items) {
    const cells = items.map((it, i) => `
        <div class="stat c${(i % 4) + 1}">
            <div class="num">${esc(String(it.value))}</div>
            <div class="label">${esc(it.label)}</div>
        </div>`).join('');
    return `
    <div class="card">
        <div class="card-head">${esc(title)}</div>
        <div class="card-body"><div class="stats">${cells}</div></div>
    </div>`;
}

function ageCell(v, pct) {
    if (v === null || v === undefined) return '<span style="color:var(--text-faint)">—</span>';
    const p = pct != null ? ` <span style="color:var(--text-faint);font-size:12px">(${pct}%)</span>` : '';
    return `${fmtNum(v)}${p}`;
}

async function render() {
    const el = document.getElementById('content');
    if (!el) return;
    el.innerHTML = `
    <div class="toolbar" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <b>留存复购</b>
        <select id="anDays" style="min-width:76px" title="统计天数">
            ${[30, 14, 7, 60, 90].map(n => `<option value="${n}" ${n === 30 ? 'selected' : ''}>${n} 天</option>`).join('')}
        </select>
        <span style="color:var(--text-faint);font-size:12px">已启用缓存 60s</span>
    </div>
    <div id="anBody">${loading('加载留存复购统计…')}</div>`;

    el.querySelector('#anDays').addEventListener('change', () => load(el));
    await load(el);
    if (timer) clearInterval(timer);
    timer = setInterval(() => load(el), 60000);
}