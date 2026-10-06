import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc } from '../core/util.js';
import { areaChart, multiLineChart, rankingBars, donutChart, fmtNum, PALETTE } from '../core/chart.js';

register('bigscreen', render);

let timer = null;

function statsCards(title, items) {
    const cells = items.map((it, i) => `
        <div class="stat c${(i % 4) + 1}">
            <div class="num">${fmtNum(it.value)}</div>
            <div class="label">${esc(it.label)}</div>
        </div>`).join('');
    return `
    <div class="card">
        <div class="card-head">${esc(title)}</div>
        <div class="card-body"><div class="stats">${cells}</div></div>
    </div>`;
}

function grid(children, min = '320px') {
    return `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(${min},1fr));gap:14px;margin-top:14px">${children}</div>`;
}

function card(title, body) {
    return `<div class="card"><div class="card-head">${esc(title)}</div><div class="card-body">${body}</div></div>`;
}

async function load(el) {
    const days = parseInt(el.querySelector('#bsDays')?.value || '30', 10);
    const hours = parseInt(el.querySelector('#bsHours')?.value || '24', 10);
    const res = await api('bigscreen', { days, hours }).catch(() => null);
    const body = el.querySelector('#bsBody');
    if (!res || res.code !== 0) {
        if (body) body.innerHTML = empty('<i class="bi bi-tv"></i>', res?.msg || '数据大屏加载失败');
        return;
    }
    const d = res.data || {};
    const rt = d.realtime || {}, td = d.today || {}, tt = d.total || {}, rg = d.runtime || {};

    // 实时 / 今日 / 累计
    const live = statsCards('实时在线', [
        { label: '在线会话', value: rt.online },
        { label: '在线设备', value: rt.online_devices },
        { label: '在线用户', value: rt.online_users },
    ]);
    const today = statsCards('今日概览', [
        { label: '新增用户', value: td.new_users },
        { label: '激活卡密', value: td.activations },
        { label: '登录次数', value: td.logins },
        { label: 'API 调用', value: td.api_calls },
        { label: 'API 失败', value: td.api_fails },
        { label: '代理充值', value: td.recharge },
        { label: '新代理商', value: td.new_agents },
    ]);
    const total = statsCards('累计总量', [
        { label: '用户', value: tt.users },
        { label: '激活用户', value: tt.users_active },
        { label: '卡密', value: tt.cards },
        { label: '未用卡', value: tt.cards_unused },
        { label: '代理商', value: tt.agents },
        { label: '绑定设备', value: tt.devices },
    ]);

    // 在线曲线（近 N 小时）
    const oc = (d.online_curve || []).map(p => ({ label: p.label, online: p.online, devices: p.devices }));
    const onlineChart = card('在线曲线（近 ' + hours + ' 小时，5 分钟/点）', oc.length
        ? multiLineChart(oc, [
            { key: 'online', name: '在线会话', color: 'var(--primary-strong)' },
            { key: 'devices', name: '在线设备', color: '#10b981' },
        ])
        : empty('<i class="bi bi-activity"></i>', '暂无数据（需 cron 写入 nb_online_stats）'));

    // 近 N 天：新增 / 激活 / API 调用
    const cv = (d.curves || []).map(p => ({
        label: p.label, new_users: p.new_users, activations: p.activations,
        api_calls: p.api_calls, api_fails: p.api_fails,
    }));
    const curvesChart = card('近 ' + days + ' 天曲线', cv.length
        ? multiLineChart(cv, [
            { key: 'new_users', name: '新增用户', color: 'var(--primary-strong)' },
            { key: 'activations', name: '激活', color: '#10b981' },
            { key: 'api_calls', name: 'API 调用', color: '#f59e0b' },
        ])
        : empty('<i class="bi bi-bar-chart-line"></i>', '暂无数据'));

    // 代理销量排行 / 卡密类型分布
    const rank = card('代理销量排行（近 ' + days + ' 天 TOP10）',
        d.agent_rank && d.agent_rank.length
            ? rankingBars(d.agent_rank.map(a => ({
                name: a.name,
                value: a.generated,
                sub: `生成 ${fmtNum(a.generated)} · 已用 ${fmtNum(a.used)} · 未用 ${fmtNum(a.unused)}`,
            })), { color: 'var(--primary-strong)' })
            : empty('<i class="bi bi-diagram-3"></i>', '暂无数据'));
    const dist = card('卡密类型分布',
        d.type_dist && d.type_dist.length
            ? donutChart(d.type_dist.map((t, i) => ({
                label: t.name, value: t.total, color: PALETTE[i % PALETTE.length],
            })), { centerLabel: '卡密' })
            : empty('<i class="bi bi-pie-chart"></i>', '暂无数据'));

    // 运行状态
    const cache = rg.cache || {};
    const hb = rg.heartbeat || {};
    const runtime = card('运行状态', `
        <div class="stats">
            <div class="stat c1"><div class="num">${esc(cache.driver || '-')}</div><div class="label">缓存驱动</div></div>
            <div class="stat c2"><div class="num">${fmtNum(cache.keys ?? hb.pending ?? 0)}</div><div class="label">缓存键</div></div>
            <div class="stat c3"><div class="num">${fmtNum(hb.pending ?? 0)}</div><div class="label">心跳待落库</div></div>
            <div class="stat c4"><div class="num">${fmtNum(rg.stat_buffer ?? 0)}</div><div class="label">统计缓冲</div></div>
        </div>`);

    // 最近动态
    const logs = (d.recent_logs || []).map(l => `
        <tr>
            <td class="mono">${esc(l.action)}</td>
            <td>${l.result == 1 ? '<span style="color:#10b981">成功</span>' : '<span style="color:#ef4444">失败</span>'}</td>
            <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(l.message || '')}</td>
            <td class="mono">${esc(l.username || '-')}</td>
            <td class="mono">${esc(l.ip || '-')}</td>
            <td class="mono">${esc(l.time_text || '')}</td>
        </tr>`).join('');
    const recent = card('最近动态', logs ? `
        <div class="table-wrap"><table>
            <thead><tr><th>动作</th><th>结果</th><th>信息</th><th>管理员</th><th>IP</th><th>时间</th></tr></thead>
            <tbody>${logs}</tbody></table>
        </div>` : empty('<i class="bi bi-journal-text"></i>', '暂无记录'));

    const srv = d.server || {};
    body.innerHTML = grid([live, today, total]) + grid([onlineChart, curvesChart]) + grid([rank, dist]) + grid([runtime, recent]) + `
        <div style="margin-top:12px;text-align:right;font-size:12px;color:var(--text-faint)">
            ${esc(srv.site_name || 'Nebula')} · 服务器时间 ${esc(srv.time || '')} · PHP ${esc(srv.php_version || '')}
        </div>`;
}

async function render() {
    const el = document.getElementById('content');
    if (!el) return;
    el.innerHTML = `
    <div class="toolbar" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <b>数据大屏</b>
        <span style="color:var(--text-faint);font-size:12px">自动刷新 30s</span>
        <select id="bsDays" style="min-width:76px" title="曲线天数">
            ${[30, 14, 7, 60, 90].map(n => `<option value="${n}" ${n === 30 ? 'selected' : ''}>${n} 天</option>`).join('')}
        </select>
        <select id="bsHours" style="min-width:76px" title="在线曲线回溯小时">
            ${[24, 12, 6, 48, 72].map(n => `<option value="${n}" ${n === 24 ? 'selected' : ''}>${n} 小时</option>`).join('')}
        </select>
        <button class="btn sm ghost" id="bsFlush">清空缓存（排障）</button>
        <span id="bsMsg" style="font-size:12px;color:var(--text-faint)"></span>
    </div>
    <div id="bsBody">${loading('加载数据大屏…')}</div>`;

    el.querySelector('#bsDays').addEventListener('change', () => load(el));
    el.querySelector('#bsHours').addEventListener('change', () => load(el));
    el.querySelector('#bsFlush').addEventListener('click', async () => {
        const msg = el.querySelector('#bsMsg');
        if (msg) msg.textContent = '清空中…';
        const res = await api('bigscreen', { op: 'flush_cache' }).catch(() => null);
        if (msg) msg.textContent = res?.code === 0 ? `已清空 ${res.data?.count ?? 0} 个键` : (res?.msg || '权限不足，仅超管可清缓存');
    });

    await load(el);
    if (timer) clearInterval(timer);
    timer = setInterval(() => load(el), 30000);
}