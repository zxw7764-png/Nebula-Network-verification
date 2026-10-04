/**
 * 防护配置后台页面（§37-§45）
 * 复合页：概览 / 安全事件 / 风险会话 / 风险设备 / 防护策略
 */
import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, esc, tag, empty, ts2str } from '../core/util.js';
import { pageState } from '../core/state.js';
import { toast, confirmBox, openModal, closeModal, pager, bindPager, createSelection, checkAllBox, rowCheckBox } from '../core/ui.js';

// 注册复合页主入口 → 默认显示概览
register('rt_security', renderOverview);
register('rt_overview', renderOverview);
register('rt_event_list', renderEventList);
register('rt_risk_sessions', renderRiskSessions);
register('rt_risk_devices', renderRiskDevices);
register('rt_policy_list', renderPolicyList);

const EVENT_TYPES = [
    'CODE_INTEGRITY_FAIL', 'CODE_PATCH', 'GUARDED_CODE_FAIL',
    'MANUAL_MAP', 'MODULE_INJECTION', 'MODULE_TAMPER', 'ABNORMAL_MODULE',
    'INLINE_HOOK', 'ABNORMAL_EXECUTABLE_MEMORY', 'MEMORY_REGION_TAMPER', 'CRITICAL_DATA_TAMPER',
    'DEBUGGER_DETECTED', 'HARDWARE_BREAKPOINT', 'DEBUG_TOOL_RISK',
    'PROCESS_ACCESS_RISK',
    'WATCHDOG_FAILURE', 'PROTECTION_DISABLED', 'PROTECTION_DEGRADED', 'RUNTIME_POLICY_INVALID',
];

// 事件类型中文映射
const EVENT_TYPE_LABELS = {
    CODE_INTEGRITY_FAIL: '代码完整性校验失败',
    CODE_PATCH: '代码补丁',
    GUARDED_CODE_FAIL: '守护代码失败',
    MANUAL_MAP: '手动映射',
    MODULE_INJECTION: '模块注入',
    MODULE_TAMPER: '模块篡改',
    ABNORMAL_MODULE: '异常模块',
    INLINE_HOOK: '内联钩子',
    ABNORMAL_EXECUTABLE_MEMORY: '异常可执行内存',
    MEMORY_REGION_TAMPER: '内存区域篡改',
    CRITICAL_DATA_TAMPER: '关键数据篡改',
    DEBUGGER_DETECTED: '检测到调试器',
    HARDWARE_BREAKPOINT: '硬件断点',
    DEBUG_TOOL_RISK: '调试工具风险',
    PROCESS_ACCESS_RISK: '进程访问风险',
    WATCHDOG_FAILURE: '看门狗失效',
    PROTECTION_DISABLED: '防护已禁用',
    PROTECTION_DEGRADED: '防护降级',
    RUNTIME_POLICY_INVALID: '运行时策略无效',
};

function eventLabel(type) {
    return EVENT_TYPE_LABELS[type] || type;
}

// 处理动作中文映射
const ACTION_LABELS = {
    REPORT: '仅上报',
    TERMINATE: '终止进程',
    REVOKE_SESSION: '吊销会话',
};

function actionLabel(action) {
    return ACTION_LABELS[action] || action || '';
}

// ============================================================
// 1. 概览（§38）
// ============================================================
async function renderOverview() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('rt_overview', {}, true);
    if (res.code !== 0) {
        c.innerHTML = `<div class="card"><div class="card-body" style="padding:24px;color:#ef4444">加载失败：${esc(res.msg || '未知错误')}</div></div>`;
        return;
    }

    const s = res.data.stats || {};
    const dist = res.data.type_distribution || [];
    const recent = res.data.recent_events || [];

    const statCards = [
        { label: '今日安全事件', value: s.events_today || 0, icon: 'bi-shield-exclamation', color: '#3b82f6' },
        { label: '严重', value: s.critical_today || 0, icon: 'bi-x-octagon', color: '#ef4444' },
        { label: '高危', value: s.high_today || 0, icon: 'bi-exclamation-triangle', color: '#f59e0b' },
        { label: '中危', value: s.medium_today || 0, icon: 'bi-info-circle', color: '#eab308' },
        { label: '阻断会话', value: s.blocked_sessions || 0, icon: 'bi-slash-circle', color: '#dc2626' },
    ];

    const distRows = dist.map(d => `<tr><td><b>${esc(eventLabel(d.event_type))}</b></td><td>${d.cnt}</td></tr>`).join('');

    const recentRows = recent.map(e => `
        <tr style="cursor:pointer" data-event-id="${e.id}">
            <td>${e.id}</td>
            <td>${riskTag(e.risk_level)}</td>
            <td><b>${esc(eventLabel(e.event_type))}</b></td>
            <td>${e.risk_score}</td>
            <td style="font-family:monospace;font-size:12px">${esc(e.session_id ? String(e.session_id).substr(0, 16) + '...' : '-')}</td>
            <td>${ts2str(e.created_at)}</td>
        </tr>`).join('');

    c.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px">
        ${statCards.map(sc => `
            <div class="card" style="padding:18px">
                <div style="display:flex;align-items:center;gap:12px">
                    <div style="width:42px;height:42px;border-radius:10px;background:${sc.color}1a;display:flex;align-items:center;justify-content:center">
                        <i class="bi ${sc.icon}" style="font-size:20px;color:${sc.color}"></i>
                    </div>
                    <div>
                        <div style="font-size:24px;font-weight:700">${sc.value}</div>
                        <div style="font-size:12px;color:#6b7280">${sc.label}</div>
                    </div>
                </div>
            </div>`).join('')}
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="card">
            <div class="card-head"><h3><i class="bi bi-pie-chart"></i> 事件类型分布</h3></div>
            <div class="table-wrap">
                ${distRows ? `<table><thead><tr><th>事件类型</th><th>数量</th></tr></thead><tbody>${distRows}</tbody></table>` : empty('<i class="bi bi-inbox"></i>', '今日暂无安全事件')}
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h3><i class="bi bi-clock-history"></i> 最近事件</h3></div>
            <div class="table-wrap">
                ${recentRows ? `<table><thead><tr><th>ID</th><th>等级</th><th>类型</th><th>分数</th><th>会话</th><th>时间</th></tr></thead><tbody>${recentRows}</tbody></table>` : empty('<i class="bi bi-inbox"></i>', '今日暂无安全事件')}
            </div>
        </div>
    </div>`;

    c.querySelectorAll('tr[data-event-id]').forEach(tr => {
        tr.addEventListener('click', () => showEventDetail(parseInt(tr.dataset.eventId)));
    });
}

// ============================================================
// 2. 安全事件（§39 §40）
// ============================================================
let evSel = null;   // createSelection 实例（每次渲染重建）

async function renderEventList() {
    const c = document.getElementById('content');
    const ps = pageState('rt_event_list', { page: 1, size: 20, event_type: '', risk_level: '', handled: '' });
    c.innerHTML = loading();

    const res = await api('rt_event_list', {
        page: ps.page, size: ps.size,
        event_type: ps.event_type, risk_level: ps.risk_level, handled: ps.handled,
    }, true);

    if (res.code !== 0) {
        c.innerHTML = `<div class="card"><div class="card-body" style="padding:24px;color:#ef4444">加载失败：${esc(res.msg || '未知错误')}</div></div>`;
        return;
    }

    const rows = res.data.list || [];

    const rowsHtml = rows.map(e => {
        const handledTag = e.handled == 1 ? tag('已处理', 'ok') : (e.handled == 2 ? tag('误报', 'purple') : tag('未处理', 'warn'));
        const flagsHex = e.violation_flags !== null && e.violation_flags !== undefined
            ? '0x' + parseInt(e.violation_flags).toString(16).toUpperCase()
            : '-';
        return `<tr style="cursor:pointer" data-event-id="${e.id}">
            ${rowCheckBox(e.id)}
            <td>${e.id}</td>
            <td>${riskTag(e.risk_level)}</td>
            <td><b>${esc(eventLabel(e.event_type))}</b></td>
            <td>${e.risk_score}</td>
            <td class="mono">${flagsHex}</td>
            <td>${esc(e.module_name || '-')}</td>
            <td>${esc(e.client_ip || '-')}</td>
            <td>${handledTag}</td>
            <td>${ts2str(e.created_at)}</td>
        </tr>`;
    }).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3><i class="bi bi-list-check"></i> 安全事件</h3>
            <div class="toolbar">
                <select id="evFilterType" class="rt-select">
                    <option value="">全部类型</option>
                    ${EVENT_TYPES.map(t => `<option value="${t}" ${ps.event_type === t ? 'selected' : ''}>${eventLabel(t)}</option>`).join('')}
                </select>
                <select id="evFilterLevel" class="rt-select">
                    <option value="">全部等级</option>
                    <option value="LOW" ${ps.risk_level === 'LOW' ? 'selected' : ''}>低危</option>
                    <option value="MEDIUM" ${ps.risk_level === 'MEDIUM' ? 'selected' : ''}>中危</option>
                    <option value="HIGH" ${ps.risk_level === 'HIGH' ? 'selected' : ''}>高危</option>
                    <option value="CRITICAL" ${ps.risk_level === 'CRITICAL' ? 'selected' : ''}>严重</option>
                </select>
                <select id="evFilterHandled" class="rt-select">
                    <option value="">全部状态</option>
                    <option value="0" ${ps.handled === '0' ? 'selected' : ''}>未处理</option>
                    <option value="1" ${ps.handled === '1' ? 'selected' : ''}>已处理</option>
                    <option value="2" ${ps.handled === '2' ? 'selected' : ''}>误报</option>
                </select>
                <button class="btn sm" id="evSearch"><i class="bi bi-search"></i> 筛选</button>
            </div>
            <div class="acts">
                <span id="evBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="evBulkOp">
                        <option value="handled">批量已处理</option>
                        <option value="false_positive">批量误报</option>
                    </select>
                    <button class="btn" id="evBulkRun">执行</button>
                </span>
            </div>
        </div>
        <div class="table-wrap">
            ${rowsHtml ? `<table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>等级</th><th>事件类型</th><th>分数</th><th>标记位</th><th>模块</th><th>IP</th><th>状态</th><th>时间</th>
                </tr></thead>
                <tbody>${rowsHtml}</tbody>
            </table>` : empty('<i class="bi bi-inbox"></i>', '没有匹配的安全事件')}
        </div>
        ${pager(res.data.total, res.data.page, res.data.size)}
    </div>`;

    document.getElementById('evSearch')?.addEventListener('click', () => {
        ps.event_type = document.getElementById('evFilterType').value;
        ps.risk_level = document.getElementById('evFilterLevel').value;
        ps.handled = document.getElementById('evFilterHandled').value;
        ps.page = 1;
        renderEventList();
    });

    bindPager(c, p => { ps.page = p; renderEventList(); });

    c.querySelectorAll('tr[data-event-id]').forEach(tr => {
        tr.addEventListener('click', () => showEventDetail(parseInt(tr.dataset.eventId)));
    });
    // 勾选框点击不要冒泡到行（否则会弹出事件详情）
    c.querySelectorAll('.col-check input').forEach(cb => {
        cb.addEventListener('click', e => e.stopPropagation());
    });

    // ---- 批量操作（与其他页面同款：选中后在工具栏出现批量框） ----
    evSel = createSelection({ root: c, allIds: rows.map(e => e.id), onChange: ids => {
        const box = document.getElementById('evBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
    }});
    document.getElementById('evBulkRun')?.addEventListener('click', () => {
        const ids = evSel ? evSel.ids() : [];
        if (!ids.length) return toast('请先勾选事件', 'warn');
        const op = document.getElementById('evBulkOp').value;
        batchMarkEvents(ids, op === 'handled' ? 1 : 2);
    });
}

async function showEventDetail(eventId) {
    const res = await api('rt_event_detail', { id: eventId }, true);
    if (res.code !== 0) { toast(res.msg || '加载失败', 'err'); return; }

    const e = res.data.event;
    const handledTag = e.handled == 1 ? tag('已处理', 'ok') : (e.handled == 2 ? tag('误报', 'purple') : tag('未处理', 'warn'));

    let detailHtml = '';
    if (e.event_detail) {
        try {
            const d = typeof e.event_detail === 'string' ? JSON.parse(e.event_detail) : e.event_detail;
            detailHtml = Object.entries(d).map(([k, v]) =>
                `<div style="display:flex;padding:4px 0;border-bottom:1px solid var(--border)"><b style="width:140px;color:#6b7280">${esc(k)}</b><span style="flex:1">${esc(String(v))}</span></div>`
            ).join('');
        } catch (err) {
            detailHtml = '<pre style="white-space:pre-wrap">' + esc(String(e.event_detail)) + '</pre>';
        }
    }

    const rows = [
        ['事件ID', e.id],
        ['事件类型', '<b>' + esc(eventLabel(e.event_type)) + '</b>'],
        ['风险等级', riskTag(e.risk_level)],
        ['风险分数', e.risk_score],
        ['SDK版本', esc(e.sdk_version || '-')],
        ['处理状态', handledTag],
        ['会话', esc(e.session_id ? String(e.session_id).substr(0, 24) + '...' : '-')],
        ['设备ID', e.device_id || '-'],
        ['客户端IP', esc(e.client_ip || '-')],
        ['处理动作', tag(actionLabel(e.action_taken) || '-', 'gray')],
        ['客户端时间', ts2str(e.client_time)],
        ['服务器时间', ts2str(e.server_time)],
        ['持续标记', e.sticky ? tag('是', 'danger') : tag('否', 'ok')],
        ['模块名', esc(e.module_name || '-')],
    ];

    const detailGrid = rows.map(([label, val]) =>
        `<div style="display:flex;align-items:center;padding:5px 0;border-bottom:1px solid var(--border)"><b style="width:120px;color:#6b7280;font-size:13px">${esc(label)}</b><span style="flex:1;font-size:13px">${val}</span></div>`
    ).join('');

    const body = `
        <div style="max-width:560px">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 20px">${detailGrid}</div>
            ${detailHtml ? `<div style="margin-top:12px"><div style="font-size:13px;font-weight:600;margin-bottom:8px;color:#6b7280"><i class="bi bi-info-circle"></i> 诊断详情</div>${detailHtml}</div>` : ''}
        </div>`;

    const buttons = [];
    if (e.handled != 1) buttons.push({ text: '标记已处理', cls: '', act: () => markEvent(e.id, 1) });
    if (e.handled != 2) buttons.push({ text: '标记误报', cls: 'ghost', act: () => markEvent(e.id, 2) });
    buttons.push({ text: '关闭', cls: 'ghost', act: closeModal });

    openModal('安全事件详情 #' + e.id, body, buttons, 'wide');
}

async function markEvent(id, status) {
    const res = await api('rt_event_handle', { id, status });
    if (res.code === 0) {
        toast(status === 1 ? '已标记为已处理' : '已标记为误报', 'ok');
        closeModal();
        renderEventList();
    }
}

async function batchMarkEvents(ids, status) {
    if (!ids.length) { toast('请先勾选事件', 'warn'); return; }
    const label = status === 1 ? '标记为已处理' : '标记为误报';
    confirmBox('批量' + label, '确定将选中的 ' + ids.length + ' 条事件' + label + '？', async () => {
        const res = await api('rt_event_batch', { ids, status });
        if (res.code === 0) {
            toast('已' + label + ' ' + (res.data.affected || 0) + ' 条', 'ok');
            if (evSel) evSel.clear();
            renderEventList();
        }
    });
}

// ============================================================
// 3. 风险会话
// ============================================================
async function renderRiskSessions() {
    const c = document.getElementById('content');
    const ps = pageState('rt_risk_sessions', { page: 1, size: 20 });
    c.innerHTML = loading();

    const res = await api('rt_risk_sessions', { page: ps.page, size: ps.size }, true);
    if (res.code !== 0) {
        c.innerHTML = `<div class="card"><div class="card-body" style="padding:24px;color:#ef4444">加载失败：${esc(res.msg || '未知错误')}</div></div>`;
        return;
    }

    const total = res.data.total || 0;
    const rows = res.data.list || [];

    const rowsHtml = rows.map(s => {
        const stTag = s.rt_status === 'BLOCKED' ? tag('已阻断', 'danger') : tag('风险', 'warn');
        return `<tr>
            <td>${s.id}</td>
            <td>${stTag}</td>
            <td>${riskTag(s.rt_risk_level)}</td>
            <td><b>${s.rt_risk_score}</b></td>
            <td>${esc(s.username || '-')}</td>
            <td style="font-family:monospace;font-size:12px">${esc(s.token ? String(s.token).substr(0, 20) + '...' : '-')}</td>
            <td style="font-family:monospace;font-size:12px">${esc(s.machine_id ? String(s.machine_id).substr(0, 20) + '...' : '-')}</td>
            <td>${esc(s.ip || '-')}</td>
            <td>${ts2str(s.last_active)}</td>
            <td>${s.rt_status === 'BLOCKED' ? `<button class="btn sm ghost" data-unblock-session="${esc(s.token)}"><i class="bi bi-unlock"></i> 解除</button>` : ''}</td>
        </tr>`;
    }).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3><i class="bi bi-exclamation-octagon"></i> 风险会话</h3>
            <span style="color:#6b7280;font-size:13px">共 ${total} 条</span>
        </div>
        <div class="table-wrap">
            ${rowsHtml ? `<table>
                <thead><tr><th>ID</th><th>状态</th><th>等级</th><th>分数</th><th>用户</th><th>令牌</th><th>设备</th><th>IP</th><th>最后活跃</th><th>操作</th></tr></thead>
                <tbody>${rowsHtml}</tbody>
            </table>` : empty('<i class="bi bi-check-circle"></i>', '当前没有风险会话')}
        </div>
        ${pager(total, res.data.page, ps.size)}
    </div>`;

    bindPager(c, p => { ps.page = p; renderRiskSessions(); });

    c.querySelectorAll('[data-unblock-session]').forEach(btn => {
        btn.addEventListener('click', () => {
            const token = btn.dataset.unblockSession;
            confirmBox('解除会话阻断', '确定将该会话恢复为正常状态？', async () => {
                // 参数名用 session_token：body 里叫 token 会被入口当成管理员令牌（导致登出）
                const res = await api('rt_session_unblock', { session_token: token });
                if (res.code === 0) { toast('已解除阻断', 'ok'); renderRiskSessions(); }
            });
        });
    });
}

// ============================================================
// 4. 风险设备
// ============================================================
async function renderRiskDevices() {
    const c = document.getElementById('content');
    const ps = pageState('rt_risk_devices', { page: 1, size: 20 });
    c.innerHTML = loading();

    const res = await api('rt_risk_devices', { page: ps.page, size: ps.size }, true);
    if (res.code !== 0) {
        c.innerHTML = `<div class="card"><div class="card-body" style="padding:24px;color:#ef4444">加载失败：${esc(res.msg || '未知错误')}</div></div>`;
        return;
    }

    const total = res.data.total || 0;
    const rows = res.data.list || [];

    const rowsHtml = rows.map(d => `<tr>
        <td>${d.device_id}</td>
        <td>${riskTag(d.rt_risk_level)}</td>
        <td><b>${d.rt_risk_score}</b></td>
        <td>${d.total_events || 0}</td>
        <td>${d.critical_events || 0}</td>
        <td>${d.high_events || 0}</td>
        <td>${esc(d.username || '-')}</td>
        <td style="font-family:monospace;font-size:12px">${esc(d.machine_id ? String(d.machine_id).substr(0, 24) + '...' : '-')}</td>
        <td>${esc(d.device_name || '-')}</td>
        <td>${ts2str(d.last_event_at)}</td>
        <td><button class="btn sm ghost" data-unblock-device="${d.device_id}"><i class="bi bi-arrow-counterclockwise"></i> 重置</button></td>
    </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3><i class="bi bi-pc-display-exclamation"></i> 风险设备</h3>
            <span style="color:#6b7280;font-size:13px">共 ${total} 条</span>
        </div>
        <div style="padding:12px 16px;color:#6b7280;font-size:13px;line-height:1.8">
            按设备聚合统计运行时防护违规事件，同一设备反复出现违规说明长期信誉存在问题。
            <b>不建议</b>因为单次低可信事件（如进程访问风险）就封禁设备。
        </div>
        <div class="table-wrap">
            ${rowsHtml ? `<table>
                <thead><tr><th>ID</th><th>等级</th><th>分数</th><th>总事件</th><th>严重</th><th>高危</th><th>用户</th><th>机器码</th><th>设备名</th><th>最后事件</th><th>操作</th></tr></thead>
                <tbody>${rowsHtml}</tbody>
            </table>` : empty('<i class="bi bi-check-circle"></i>', '当前没有风险设备')}
        </div>
        ${pager(total, res.data.page, ps.size)}
    </div>`;

    bindPager(c, p => { ps.page = p; renderRiskDevices(); });

    c.querySelectorAll('[data-unblock-device]').forEach(btn => {
        btn.addEventListener('click', () => {
            const deviceId = parseInt(btn.dataset.unblockDevice);
            confirmBox('重置设备风险', '确定将该设备的运行时风险分数清零？', async () => {
                const res = await api('rt_device_unblock', { device_id: deviceId });
                if (res.code === 0) { toast('已重置设备风险', 'ok'); renderRiskDevices(); }
            });
        });
    });
}

// ============================================================
// 5. 防护策略
// ============================================================
async function renderPolicyList() {
    const c = document.getElementById('content');
    const ps = pageState('rt_policy_list', { page: 1, size: 20 });
    c.innerHTML = loading();

    const res = await api('rt_policy_list', { page: ps.page, size: ps.size }, true);
    if (res.code !== 0) {
        c.innerHTML = `<div class="card"><div class="card-body" style="padding:24px;color:#ef4444">加载失败：${esc(res.msg || '未知错误')}</div></div>`;
        return;
    }

    const total = res.data.total || 0;
    const rows = res.data.list || [];

    const lvMap = { 0: tag('关闭', 'danger'), 1: tag('基础', 'gray'), 2: tag('标准', 'ok'), 3: tag('严格', 'warn') };

    const rowsHtml = rows.map(p => `<tr style="cursor:pointer" data-policy-id="${p.id}">
        <td>${p.id}</td>
        <td><b>${esc(p.policy_name)}</b></td>
        <td>${p.software_id == 0 ? tag('全局', 'purple') : '软件#' + p.software_id}</td>
        <td>${lvMap[p.protection_level] || '-'}</td>
        <td>${p.enabled ? tag('启用', 'ok') : tag('禁用', 'danger')}</td>
        <td>v${p.policy_version}</td>
        <td>${ts2str(p.updated_at)}</td>
        <td>
            <button class="btn sm" data-edit-policy="${p.id}"><i class="bi bi-pencil"></i> 编辑</button>
            ${p.software_id != 0 ? `<button class="btn sm ghost" data-del-policy="${p.id}" style="color:#ef4444"><i class="bi bi-trash"></i></button>` : ''}
        </td>
    </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3><i class="bi bi-shield-check"></i> 防护策略</h3>
            <div class="toolbar">
                <button class="btn sm" id="rtPolicyNew"><i class="bi bi-plus-lg"></i> 新建策略</button>
            </div>
        </div>
        <div style="padding:10px 16px;font-size:12px;color:#9ca3af;border-bottom:1px solid var(--border)">
            <i class="bi bi-info-circle"></i>
            策略按「软件专属 &gt; 全局」匹配：软件没有专属策略时回落全局策略，两者都没有时使用<b>内置默认策略（标准级全开）</b>。要关闭所有软件防护，建一条全局（作用范围=全局）等级 0 策略即可。
        </div>
        <div class="table-wrap">
            ${rowsHtml ? `<table>
                <thead><tr><th>ID</th><th>策略名</th><th>范围</th><th>防护等级</th><th>状态</th><th>版本</th><th>更新时间</th><th>操作</th></tr></thead>
                <tbody>${rowsHtml}</tbody>
            </table>` : empty('<i class="bi bi-inbox"></i>', '暂无策略，点击「新建策略」创建')}
        </div>
        ${pager(total, res.data.page, ps.size)}
    </div>`;

    bindPager(c, p => { ps.page = p; renderPolicyList(); });

    document.getElementById('rtPolicyNew')?.addEventListener('click', () => showPolicyEditor(null));
    c.querySelectorAll('[data-edit-policy]').forEach(btn => {
        btn.addEventListener('click', e => { e.stopPropagation(); showPolicyEditor(parseInt(btn.dataset.editPolicy)); });
    });
    c.querySelectorAll('[data-del-policy]').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const id = parseInt(btn.dataset.delPolicy);
            confirmBox('删除策略', '确定删除此策略？此操作不可恢复。', async () => {
                const res = await api('rt_policy_delete', { id });
                if (res.code === 0) { toast('策略已删除', 'ok'); renderPolicyList(); }
            });
        });
    });
}

// ============================================================
// 防护等级 → 检测模块（等级即策略）
// 位定义与 lib/RuntimePolicy.php levelModuleMask() 及
// sdk nebula/protect/runtime_policy.hpp ModBit* 严格一致；
// 检测模块随防护等级整档下发，不再单独勾选。
// ============================================================
const RT_MODULES = [
    [1,   '反调试'],
    [2,   '反虚拟机/沙箱'],
    [4,   'API钩子检测'],
    [8,   '代码补丁检测'],
    [16,  '代码完整性校验'],
    [32,  '模块守卫'],
    [64,  '内存守卫'],
    [128, '进程守卫'],
    [256, '时序检测'],
    [512, '环境痕迹'],
];

const RT_LEVEL_MASK = {
    0: 0,                                       // 关闭：全不启用
    1: 1 | 2 | 16,                              // 基础
    2: 1 | 2 | 16 | 4 | 8 | 32 | 64,            // 标准
    3: 1 | 2 | 16 | 4 | 8 | 32 | 64 | 128 | 256 | 512, // 严格
};

const RT_LEVEL_HINT = {
    0: '关闭防护：不检测、不下发，客户端将真正停止全部检测与看门狗。',
    1: '基础：反调试 + 反虚拟机/沙箱 + 代码完整性校验。',
    2: '标准：基础之上增加 API钩子、代码补丁、模块守卫、内存守卫。',
    3: '严格：标准之上增加进程守卫、时序检测、环境痕迹检测。',
};

function renderLevelModules(level) {
    const mask = RT_LEVEL_MASK[level] ?? 0;
    return RT_MODULES.map(([bit, label]) => {
        const on = (mask & bit) !== 0;
        const style = on
            ? 'display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;font-size:12px;background:rgba(16,185,129,.12);color:#10b981;border:1px solid rgba(16,185,129,.35)'
            : 'display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;font-size:12px;background:var(--input-bg,rgba(127,127,127,.08));color:#9ca3af;border:1px solid var(--border);opacity:.55;text-decoration:line-through';
        return `<span style="${style}"><i class="bi ${on ? 'bi-check-circle-fill' : 'bi-x-circle'}"></i>${esc(label)}</span>`;
    }).join('');
}

async function showPolicyEditor(policyId) {
    let p = null;
    if (policyId) {
        const res = await api('rt_policy_detail', { id: policyId }, true);
        if (res.code !== 0) { toast(res.msg || '加载失败', 'err'); return; }
        p = res.data.policy;
    }

    const inputStyle = 'width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--input-bg);color:var(--text);margin-top:4px;box-sizing:border-box';

    // 软件下拉选项（0=全局）
    const swRes = await api('software_list', { page: 1, size: 100 });
    const swOpts = (swRes.code === 0 ? (swRes.data.options || []) : []);
    const swSelect = `<select id="ptSwId" style="${inputStyle}">
        <option value="0" ${p?.software_id == 0 ? 'selected' : ''}>全局（所有软件）</option>
        ${swOpts.map(s => `<option value="${s.id}" ${p?.software_id == s.id ? 'selected' : ''}>${esc(s.name)} (#${s.id})</option>`).join('')}
    </select>`;

    const body = `
        <div style="max-width:620px">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;margin-bottom:16px">
                <div>
                    <label style="font-size:12px;color:#6b7280">策略名称</label>
                    <input id="ptName" value="${esc(p?.policy_name || '')}" style="${inputStyle}">
                </div>
                <div>
                    <label style="font-size:12px;color:#6b7280">作用范围</label>
                    ${swSelect}
                    <div class="hint" style="font-size:11px;color:#9ca3af;margin-top:4px">软件没有专属策略且无全局策略时，将使用内置默认（标准级全开）。想关闭某软件防护：给它建等级 0 策略，或建全局等级 0 策略。</div>
                </div>
                <div>
                    <label style="font-size:12px;color:#6b7280">防护等级</label>
                    <select id="ptLevel" style="${inputStyle}">
                        <option value="0" ${p?.protection_level == 0 ? 'selected' : ''}>0 - 关闭</option>
                        <option value="1" ${p?.protection_level == 1 ? 'selected' : ''}>1 - 基础</option>
                        <option value="2" ${p?.protection_level == 2 ? 'selected' : ''}>2 - 标准</option>
                        <option value="3" ${p?.protection_level == 3 ? 'selected' : ''}>3 - 严格</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;color:#6b7280">看门狗间隔(ms)</label>
                    <input id="ptWd" type="number" value="${p?.watchdog_interval_ms ?? 3000}" style="${inputStyle}">
                </div>
            </div>
            <div style="margin-bottom:16px;padding:12px 14px;border:1px solid var(--border);border-radius:8px">
                <div style="font-size:13px;font-weight:600;margin-bottom:4px"><i class="bi bi-shield-lock"></i> 本等级检测模块（随策略真实下发）</div>
                <div id="ptLevelHint" style="font-size:12px;color:#6b7280;margin-bottom:10px"></div>
                <div id="ptLevelModules" style="display:flex;flex-wrap:wrap;gap:6px"></div>
                <div style="font-size:12px;color:#9ca3af;margin-top:10px;line-height:1.6">
                    检测模块由防护等级决定并随策略整档下发，不再单独勾选。
                    违规处置（弹窗/终止/上报）已并入下方中危/高危/严重三档动作；
                    看门狗开关随防护等级生效（关闭等级时客户端自动停止看门狗）。
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px">
                <div>
                    <label style="font-size:12px;color:#6b7280">中危动作</label>
                    <select id="ptMedAct" style="${inputStyle}">
                        <option value="REPORT" ${p?.medium_action === 'REPORT' ? 'selected' : ''}>仅上报</option>
                        <option value="TERMINATE" ${p?.medium_action === 'TERMINATE' ? 'selected' : ''}>终止进程</option>
                        <option value="REVOKE_SESSION" ${p?.medium_action === 'REVOKE_SESSION' ? 'selected' : ''}>吊销会话</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;color:#6b7280">高危动作</label>
                    <select id="ptHighAct" style="${inputStyle}">
                        <option value="REPORT" ${p?.high_action === 'REPORT' ? 'selected' : ''}>仅上报</option>
                        <option value="TERMINATE" ${p?.high_action === 'TERMINATE' ? 'selected' : ''}>终止进程</option>
                        <option value="REVOKE_SESSION" ${p?.high_action === 'REVOKE_SESSION' ? 'selected' : ''}>吊销会话</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;color:#6b7280">严重动作</label>
                    <select id="ptCritAct" style="${inputStyle}">
                        <option value="REPORT" ${p?.critical_action === 'REPORT' ? 'selected' : ''}>仅上报</option>
                        <option value="TERMINATE" ${p?.critical_action === 'TERMINATE' ? 'selected' : ''}>终止进程</option>
                        <option value="REVOKE_SESSION" ${p?.critical_action === 'REVOKE_SESSION' ? 'selected' : ''}>吊销会话</option>
                    </select>
                </div>
            </div>
        </div>`;

    const mask = openModal(policyId ? '编辑策略 #' + policyId : '新建策略', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '保存', cls: '', act: async () => {
            const data = {
                id: policyId || undefined,
                policy_name: document.getElementById('ptName').value.trim() || 'default',
                software_id: parseInt(document.getElementById('ptSwId').value) || 0,
                protection_level: parseInt(document.getElementById('ptLevel').value),
                watchdog_interval_ms: parseInt(document.getElementById('ptWd').value) || 3000,
                medium_action: document.getElementById('ptMedAct').value,
                high_action: document.getElementById('ptHighAct').value,
                critical_action: document.getElementById('ptCritAct').value,
                enabled: 1,
                status: 1,
            };
            const res = await api('rt_policy_save', data);
            if (res.code === 0) {
                toast('策略已保存', 'ok');
                closeModal();
                renderPolicyList();
            }
        }},
    ], 'wide');

    // 防护等级联动：更新说明 + 检测模块只读展示
    const syncLevelModules = () => {
        const lv = parseInt(document.getElementById('ptLevel').value) || 0;
        document.getElementById('ptLevelHint').textContent = RT_LEVEL_HINT[lv] || '';
        document.getElementById('ptLevelModules').innerHTML = renderLevelModules(lv);
    };
    document.getElementById('ptLevel').addEventListener('change', syncLevelModules);
    syncLevelModules();

    return mask;
}

// ============================================================
// 辅助
// ============================================================
function riskTag(level) {
    const m = {
        CRITICAL: ['严重', 'danger'],
        HIGH: ['高危', 'warn'],
        MEDIUM: ['中危', 'purple'],
        LOW: ['低危', 'ok'],
    };
    const v = m[(level || '').toUpperCase()] || [level || '-', 'gray'];
    return tag(v[0], v[1]);
}
