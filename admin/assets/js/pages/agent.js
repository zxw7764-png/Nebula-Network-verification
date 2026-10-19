import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { pageState } from '../core/state.js';
import { loading, empty, esc, tag, statusTag } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast, pager, bindPager,
         createSelection, checkAllBox, rowCheckBox } from '../core/ui.js';

const DEFAULTS = { page: 1, size: 20, keyword: '', status: '', mode: 0 };
let sel = null;
let lastList = [];

register('agent_list', render);

// 代理密码强度预检 —— 与后端 Util::passwordIssue()（普通档）规则保持一致，
// 只为即时反馈；后端始终是最终防线。规则：8-64 位、须含字母与数字、
// 拒绝常见弱口令与全同字符。
const WEAK_PASS = new Set([
    'admin888', 'admin123', 'administrator', 'password', 'passw0rd',
    '12345678', '123456789', '1234567890', '87654321', '11111111',
    'qwerty123', 'qwertyuiop', 'abcd1234', 'a1234567', 'abc12345',
    '1qaz2wsx', 'iloveyou', 'woaini1314', 'aa123456', 'qq123456',
    'zxcvbnm1', 'asdfghj1', 'aa111111', '123456a', 'a123456',
]);
function passIssue(p) {
    const s = String(p || '');
    if (s.length < 8) return '密码至少 8 位';
    if (s.length > 64) return '密码最长 64 位';
    if (!/[A-Za-z]/.test(s) || !/[0-9]/.test(s)) return '密码需同时包含字母和数字';
    if (WEAK_PASS.has(s.toLowerCase())) return '密码过于简单，请更换';
    if (/^(.)\1+$/.test(s)) return '密码过于简单，请更换';
    return null;
}

function modeTag(mode) {
    const map = { 1: ['张数额度', 'blue'], 2: ['余额计费', 'purple'], 3: ['不限量', 'gray'] };
    const m = map[mode] || ['未知', 'gray'];
    return tag(m[0], m[1]);
}

function quotaCell(a) {
    if (a.charge_mode === 1) {
        const total = a.quota_left_total === -1 ? '不限' : (a.quota_left_total + ' 张');
        const detail = (a.types || []).filter(t => t.enabled).map(t => `${t.name} ${t.quota_text}`).join(' · ');
        return `<b>${total}</b><div style="font-size:12px;color:#9ca3af">${esc(detail || '未配置')}</div>`;
    }
    if (a.charge_mode === 2) {
        const detail = (a.types || []).filter(t => t.enabled)
            .map(t => `${t.name} ¥${t.price_text || '0.00'}`
                + (t.price > 0 ? `（可生成 ${t.can_make} 张）` : '')).join(' · ');
        return `¥ <b>${esc(a.balance_text)}</b><div style="font-size:12px;color:#9ca3af">${esc(detail || '未配置单价')}</div>`;
    }
    return '<span style="color:#9ca3af">不限量</span>';
}

async function render() {
    const st = pageState('agent_list', DEFAULTS);
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('agent_list', st);
    if (res.code !== 0) return;
    const d = res.data;
    const modes = d.modes || {};

    lastList = d.list || [];

    const rows = d.list.map(a => `
        <tr>
            ${rowCheckBox(a.id)}
            <td>${a.id}</td>
            <td class="mono"><b>${esc(a.username)}</b></td>
            <td>${esc(a.nickname)}
                ${a.nickname !== a.username ? `<div style="font-size:12px;color:#9ca3af">${esc(a.username)}</div>` : ''}
                ${a.reg_code ? `<div style="font-size:12px;color:#9ca3af" title="注册使用的激活码">码 ${esc(a.reg_code)}</div>` : ''}
            </td>
            <td>${esc(a.contact || '-')}</td>
            <td>${esc(a.software_name || '-')}</td>
            <td>${modeTag(a.charge_mode)}</td>
            <td>${quotaCell(a)}</td>
            <td style="white-space:nowrap">
                <span title="未使用">${a.stats.unused}</span> /
                <span title="已使用">${a.stats.used}</span> /
                <span title="已作废">${a.stats.void}</span>
            </td>
            <td>${statusTag(a.status, { 1: ['正常', 'green'], 0: ['已禁用', 'gray'] })}</td>
            <td class="mono" style="font-size:12px">${esc(a.created_at_text)}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-act="detail" data-id="${a.id}">详情</button>
                <button class="btn ghost sm" data-act="edit" data-id="${a.id}">编辑</button>
                <button class="btn ghost sm" data-act="grant" data-id="${a.id}">充值</button>
                <button class="btn ghost sm" data-act="pass" data-id="${a.id}">改密</button>
                <button class="btn ghost sm" data-act="toggle" data-id="${a.id}">${a.status === 1 ? '禁用' : '启用'}</button>
                <button class="btn danger sm" data-act="del" data-id="${a.id}">删除</button>
            </td>
        </tr>`).join('');

    const modeOpts = Object.keys(modes).map(k =>
        `<option value="${k}" ${String(st.mode) === k ? 'selected' : ''}>${esc(modes[k])}</option>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>代理商列表</h3>
            <div class="acts">
                <span id="aBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="aBulkOp">
                        <option value="enable">批量启用</option>
                        <option value="disable">批量禁用</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="aBulkRun">执行</button>
                </span>
                <div class="toolbar">
                    <input id="aKw" placeholder="搜索账号 / 名称 / 联系方式" value="${esc(st.keyword)}">
                    <select id="aStatus">
                        <option value="">全部状态</option>
                        <option value="1" ${st.status === '1' ? 'selected' : ''}>正常</option>
                        <option value="0" ${st.status === '0' ? 'selected' : ''}>已禁用</option>
                    </select>
                    <select id="aMode">
                        <option value="0">全部模式</option>
                        ${modeOpts}
                    </select>
                    <button class="btn" id="aSearch">搜索</button>
                </div>
                <button class="btn bulk-hide success" id="aNew">+ 新增代理</button>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>代理账号</th><th>名称</th><th>联系方式</th><th>软件</th><th>控量模式</th>
                    <th>剩余额度 / 单价</th><th>未用/已用/作废</th><th>状态</th><th>创建时间</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="12">${empty('<i class="bi bi-diagram-3"></i>', '暂无代理商')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>

    <div class="card">
        <div class="card-head"><h3>说明</h3></div>
        <div class="card-body" style="font-size:13px;color:var(--text-sub);line-height:1.9">
            · 代理商可登录 <code>/agent/</code> 自行生成卡密，卡密归属该代理，可在「卡密管理 → 来源」筛选<br>
            · 代理商有两个来源：<b>此处直接创建</b>，或<b>凭「代理商激活码」自助注册</b>（规格来自激活码）<br>
            · <b>额度与单价按卡类型分别配置</b>：永久卡 / 时长卡 / 点数卡 / 次数卡 各有一套，
              在「编辑 → 按卡类型的额度与单价」里设置<br>
            · <b>张数额度</b>：各类型独立额度（-1 = 该类型不限），生成时扣对应类型；<br>
            · <b>余额计费</b>：按「该类型单价 × 张数」扣代理余额，单价留 0 则用「系统设置 → 代理商默认单价」<br>
            · <b>不限量</b>：只记录归属与日志<br>
            · <b>激活用户组按卡类型配置</b>：在「编辑 → 按卡类型的额度、单价与激活用户组」里，可为每种卡类型
              单独指定激活后进入的用户组（如永久卡进 VIP 组、时长卡进普通组），代理不可自行修改<br>
            · 卡密的<b>设备上限</b>与<b>固定前缀</b>由代理档案固定（前缀留空则代理可自填），避免越权发放高权限卡密
        </div>
    </div>`;

    bindPager(c, p => { st.page = p; render(); });

    document.getElementById('aKw').addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });
    document.getElementById('aSearch').addEventListener('click', doSearch);
    document.getElementById('aMode').addEventListener('change', doSearch);
    document.getElementById('aStatus').addEventListener('change', doSearch);
    document.getElementById('aNew').addEventListener('click', () => agentEdit(null, modes, d.card_types));

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = d.list.find(x => x.id === id);
        b.addEventListener('click', () => {
            const act = b.dataset.act;
            if (act === 'detail') agentDetail(id);
            else if (act === 'edit') agentEdit(item, modes, d.card_types);
            else if (act === 'grant') agentGrant(item);
            else if (act === 'pass') agentResetPass(item);
            else if (act === 'toggle') agentToggle(item);
            else if (act === 'del') agentDel(item);
        });
    });

    sel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        const box = document.getElementById('aBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});

    const bulkRun = document.getElementById('aBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('aBulkOp').value;
        doBulk(op);
    });
}

async function doBulk(op) {
    const ids = sel ? sel.ids() : [];
    if (!ids.length) return toast('请先选择代理商', 'warn');

    if (op === 'delete') {
        confirmBox('批量删除', `确定删除选中的 ${ids.length} 个代理商？仅当代理名下没有卡密时才能删除。`, async () => {
            let n = 0, fail = 0;
            for (const id of ids) {
                const res = await api('agent_save', { op: 'delete', id: parseInt(id, 10) });
                if (res.code === 0) n++; else fail++;
            }
            toast(`已删除 ${n} 个` + (fail ? `，${fail} 个失败（有名下有卡密）` : ''), fail ? 'warn' : 'ok');
            if (sel) sel.clear();
            render();
        }, true);
        return;
    }

    const targetStatus = op === 'enable' ? 1 : 0;
    const label = op === 'enable' ? '启用' : '禁用';
    const targets = lastList.filter(x => ids.includes(String(x.id)) && x.status !== targetStatus);

    confirmBox(`批量${label}`, `确定${label}选中的 ${ids.length} 个代理商吗？`, async () => {
        let n = 0;
        for (const item of targets) {
            const res = await api('agent_save', { op: 'toggle', id: item.id });
            if (res.code === 0) n++;
        }
        toast(`已${label} ${n} 个代理商`);
        if (sel) sel.clear();
        render();
    });
}

function doSearch() {
    const st = pageState('agent_list', DEFAULTS);
    st.keyword = document.getElementById('aKw').value.trim();
    st.status  = document.getElementById('aStatus').value;
    st.mode    = parseInt(document.getElementById('aMode').value, 10) || 0;
    st.page    = 1;
    render();
}

function typeEditor(types, cardTypes, mode, groups = []) {
    const map = {};
    (types || []).forEach(t => { map[String(t.type)] = t; });

    const rows = (cardTypes || []).map(ct => {
        const t = map[String(ct.type)] || { enabled: true, quota_total: 0, quota_used: 0, price_text: '0.00', group_id: 0 };
        const quota = t.quota_total === -1 ? '-1' : (t.quota_total || 0);
        const isBal = mode === 2;
        const tgid = Number(t.group_id) || 0;
        const gOpts = (groups || []).map(g =>
            `<option value="${g.id}" ${tgid === Number(g.id) ? 'selected' : ''}>${esc(g.name)}</option>`).join('');
        return `<tr>
            <td style="white-space:nowrap"><b>${esc(ct.name)}</b></td>
            <td>
                <select data-t="${ct.type}" data-k="enabled">
                    <option value="1" ${t.enabled ? 'selected' : ''}>开放</option>
                    <option value="0" ${t.enabled ? '' : 'selected'}>不开放</option>
                </select>
            </td>
            <td style="opacity:${isBal ? '.5' : '1'}">
                <input type="number" data-t="${ct.type}" data-k="quota_total" value="${quota}" style="width:110px">
            </td>
            <td style="opacity:${isBal ? '1' : '.5'}">
                <input type="number" step="0.01" min="0" data-t="${ct.type}" data-k="price_yuan"
                       value="${esc(t.price_text || '0.00')}" style="width:110px">
            </td>
            <td>
                <select data-t="${ct.type}" data-k="group_id" style="min-width:120px">
                    <option value="0" ${tgid > 0 ? '' : 'selected'}>跟随默认</option>
                    ${gOpts}
                </select>
            </td>
            <td style="color:#6b7280">${t.quota_used || 0} 张</td>
        </tr>`;
    }).join('');

    return `
    <div class="hint" style="margin:4px 0 10px">
        <b>配额模式</b>看「额度」列（-1 = 该类型不限）；<b>余额模式</b>看「单价」列（元/张，留 0 用全局默认单价）。
        <b>激活用户组</b>可按卡类型分别指定，「跟随默认」= 用下方兜底用户组。
        已用张数不可回退，把总额度调到已用之下会自动收敛为已用量。
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>卡类型</th><th>是否开放</th><th>额度（张）</th><th>单价（元/张）</th><th>激活用户组</th><th>已用</th></tr></thead>
            <tbody>${rows}</tbody>
        </table>
    </div>`;
}

function readTypeEditor() {
    const out = {};
    document.querySelectorAll('[data-t][data-k]').forEach(el => {
        const t = el.dataset.t;
        out[t] = out[t] || {};
        out[t][el.dataset.k] = el.value;
    });
    return out;
}

async function agentEdit(a, modes, cardTypes) {
    a = a || {};
    const isNew = !a.id;

    if (!cardTypes) {
        const r = await api('agent_list', { all: 1 }, true);
        cardTypes = (r.code === 0 && r.data.card_types) ? r.data.card_types : [];
    }

    let groupOptions = '';
    let groupList = [];
    const gRes = await api('group_list', {}, true);
    if (gRes.code === 0 && Array.isArray(gRes.data.list)) {
        groupList = gRes.data.list;
        groupOptions = groupList
            .map(g => `<option value="${g.id}" ${Number(a.group_id) === Number(g.id) ? 'selected' : ''}>${esc(g.name)}（${g.max_devices} 设备）</option>`)
            .join('');
    }

    const modeSel = Object.keys(modes).map(k =>
        `<option value="${k}" ${String(a.charge_mode || 1) === k ? 'selected' : ''}>${esc(modes[k])}</option>`).join('');

    let softwareOptions = '';
    try {
        const swRes = await api('software_list', {}, true);
        if (swRes.code === 0 && Array.isArray(swRes.data.options) && swRes.data.options.length) {
            const curSw = Number(a.software_id) || 0;
            softwareOptions = swRes.data.options.map(x =>
                `<option value="${x.id}" ${curSw === Number(x.id) ? 'selected' : ''}>${esc(x.name)}</option>`).join('');
        }
    } catch (e) {
 }

    const body = `
    <div class="row2">
        <div class="field"><label>代理账号 *</label>
            <input id="agUser" value="${esc(a.username || '')}" placeholder="3-32 位字母数字下划线" ${isNew ? '' : 'disabled'}>
            ${isNew ? '' : '<div class="hint">账号创建后不可修改</div>'}
        </div>
        <div class="field"><label>名称</label><input id="agNick" value="${esc(a.nickname || '')}" placeholder="如：老王工作室"></div>
    </div>
    <div class="row2">
        <div class="field"><label>联系方式</label><input id="agContact" value="${esc(a.contact || '')}" placeholder="QQ / 微信 / 邮箱"></div>
        <div class="field"><label>${isNew ? '初始密码 *' : '状态'}</label>
            ${isNew
                ? '<input id="agPass" type="text" placeholder="8-64 位，须含字母和数字" autocomplete="new-password">'
                : `<select id="agStatus">
                       <option value="1" ${a.status === 1 ? 'selected' : ''}>正常</option>
                       <option value="0" ${a.status === 0 ? 'selected' : ''}>禁用</option>
                   </select>`}
        </div>
    </div>

    <div class="row2">
        <div class="field"><label>所属软件 *</label>
            ${isNew
                ? `<select id="agSoftware">${softwareOptions || '<option value="0">默认软件</option>'}</select>
                   <div class="hint">该代理生成的卡密与其注册的账号归属此软件；创建后不可更换</div>`
                : `<input value="${esc(a.software_name || '默认软件')}" disabled>
                   <div class="hint">软件归属创建后不可更换</div>`}
        </div>
        <div class="field"><label>控量模式 *</label>
            <select id="agMode">${modeSel}</select>
            <div class="hint">张数额度：按卡类型分配张数；余额计费：按卡类型单价扣余额；不限量：不做限制</div>
        </div>
    </div>

    <h4 style="margin:16px 0 8px;font-size:14px">按卡类型的额度、单价与激活用户组</h4>
    ${typeEditor(a.types, cardTypes, Number(a.charge_mode || 1), groupList)}

    <div class="row2" style="margin-top:16px">
        <div class="field"><label>账户余额（元）</label>
            <input id="agBalance" type="number" step="0.01" value="${isNew ? '0' : a.balance_text}">
            <div class="hint">仅「余额计费」模式生效，充值请在列表点「充值」</div>
        </div>
        <div class="field"><label>卡密设备上限</label>
            <input id="agDev" type="number" value="${isNew ? 1 : a.max_devices}" min="1" max="99">
            <div class="hint">该代理生成的卡密统一使用此上限，代理不可修改</div>
        </div>
    </div>

    <div class="field"><label>兜底用户组（卡类型未单独指定时）</label>
        <select id="agGroup">
            <option value="0" ${(a.group_id || 0) === 0 ? 'selected' : ''}>不换组（保持注册时的默认用户组）</option>
            ${groupOptions}
        </select>
        <div class="hint">上方「按卡类型的额度、单价与激活用户组」里可为每种卡类型单独指定；此处是兜底值（某类型选「跟随默认」时生效）</div>
    </div>

    <div class="field"><label>代理生成卡密的固定前缀</label>
        <input id="agCardPrefix" value="${esc(a.card_prefix || '')}" maxlength="8"
               placeholder="如 VIP，仅字母数字、最多 8 位；留空 = 代理可自填">
        <div class="hint">填写后该代理在 <code>/agent/</code> 生成卡密时前缀被<b>强制锁定</b>（代理端不可修改）；留空则保持代理自行填写</div>
    </div>

    <div class="row2">
        <div class="field"><label>允许代理作废自己的卡密</label>
            <select id="agVoid">
                <option value="1" ${a.can_void === false ? '' : 'selected'}>允许</option>
                <option value="0" ${a.can_void === false ? 'selected' : ''}>不允许</option>
            </select>
        </div>
        <div class="field"><label>备注</label><input id="agRemark" value="${esc(a.remark || '')}"></div>
    </div>`;

    openModal(isNew ? '新增代理商' : ('编辑代理商 · ' + (a.username || '')), body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '保存', cls: 'success', act: async () => {
            const payload = {
                id: a.id || 0,
                username: document.getElementById('agUser').value.trim(),
                nickname: document.getElementById('agNick').value.trim(),
                contact: document.getElementById('agContact').value.trim(),
                charge_mode: parseInt(document.getElementById('agMode').value, 10),
                balance_yuan: document.getElementById('agBalance').value,
                max_devices: parseInt(document.getElementById('agDev').value, 10) || 1,
                group_id: parseInt(document.getElementById('agGroup').value, 10) || 0,
                card_prefix: document.getElementById('agCardPrefix').value.trim(),
                can_void: parseInt(document.getElementById('agVoid').value, 10),
                remark: document.getElementById('agRemark').value.trim(),
                types: readTypeEditor(),
            };
            if (isNew) {
                payload.password = document.getElementById('agPass').value;
                const pwBad = passIssue(payload.password);
                if (pwBad) return toast(pwBad, 'warn');
                const swEl = document.getElementById('agSoftware');
                if (swEl) payload.software_id = parseInt(swEl.value, 10) || 0;
            } else {
                payload.status = parseInt(document.getElementById('agStatus').value, 10);
            }
            const res = await api('agent_save', payload);
            if (res.code === 0) { toast('保存成功'); closeModal(); render(); }
        }},
    ], 'wide');
}

function agentGrant(a) {
    const types = a.types || [];
    const rows = types.map(t => `
        <tr>
            <td style="white-space:nowrap"><b>${esc(t.name)}</b></td>
            <td style="color:#6b7280">${t.quota_total === -1 ? '不限' : t.quota_total + ' 张'}</td>
            <td style="color:#6b7280">已用 ${t.quota_used}</td>
            <td><input type="number" data-grant="${t.type}" value="0" style="width:110px"></td>
        </tr>`).join('');

    const body = `
    <div class="kv">
        <span class="k">代理</span><span class="v">${esc(a.nickname)}（${esc(a.username)}）</span>
        <span class="k">控量模式</span><span class="v">${modeTag(a.charge_mode)}</span>
        <span class="k">当前余额</span><span class="v">¥ ${esc(a.balance_text)}</span>
    </div>

    <h4 style="margin:16px 0 8px;font-size:14px">张数额度调整（按卡类型，可填负数扣减）</h4>
    <div class="table-wrap">
        <table>
            <thead><tr><th>卡类型</th><th>当前额度</th><th>已用</th><th>增加张数</th></tr></thead>
            <tbody>${rows}</tbody>
        </table>
    </div>

    <div class="row2" style="margin-top:16px">
        <div class="field"><label>余额调整（元，可填负数扣减）</label>
            <input id="grYuan" type="number" step="0.01" value="0"></div>
        <div class="field"><label>备注</label><input id="grRemark" placeholder="如：双十一补货"></div>
    </div>
    <div class="hint">额度调整只影响「张数额度」模式；余额调整只影响「余额计费」模式。</div>`;

    openModal('充值 / 调整额度 · ' + a.username, body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '确定调整', cls: 'success', act: async () => {
            const adds = {};
            document.querySelectorAll('[data-grant]').forEach(el => {
                const v = parseInt(el.value, 10) || 0;
                if (v !== 0) { adds[el.dataset.grant] = v; }
            });
            const res = await api('agent_save', {
                op: 'grant',
                id: a.id,
                types: adds,
                add_balance_yuan: document.getElementById('grYuan').value || 0,
                remark: document.getElementById('grRemark').value.trim(),
            });
            if (res.code === 0) { toast(res.msg); closeModal(); render(); }
        }},
    ], 'wide');
}

function agentResetPass(a) {
    openModal('重置代理密码 · ' + a.username, `
        <div class="field"><label>新密码 *</label>
            <input id="rpPass" type="text" placeholder="8-64 位，须含字母和数字">
            <div class="hint">重置后该代理的所有登录会话将立即失效</div>
        </div>`,
        [{ text: '取消', cls: 'ghost', act: closeModal },
         { text: '重置', cls: 'danger', act: async () => {
             const p = document.getElementById('rpPass').value;
             const pwBad = passIssue(p);
             if (pwBad) return toast(pwBad, 'warn');
             const res = await api('agent_save', { op: 'reset_password', id: a.id, password: p });
             if (res.code === 0) { toast('密码已重置'); closeModal(); }
         }}]);
}

function agentToggle(a) {
    const off = a.status === 1;
    confirmBox(off ? '禁用代理商' : '启用代理商',
        off ? '禁用后该代理无法登录，已登录会话会被踢下线。确定继续？' : '确定启用该代理商？',
        async () => {
            const res = await api('agent_save', { op: 'toggle', id: a.id });
            if (res.code === 0) { toast(res.msg); render(); }
        }, off);
}

function agentDel(a) {
    confirmBox('删除代理商',
        '仅当该代理名下没有卡密时才能删除。删除后账号与登录会话一并清除，确定继续？',
        async () => {
            const res = await api('agent_save', { op: 'delete', id: a.id });
            if (res.code === 0) { toast('已删除'); render(); }
        }, true);
}

async function agentDetail(id) {
    openModal('代理商详情', loading(), [], 'wide');
    const res = await api('agent_detail', { id });
    if (res.code !== 0) { closeModal(); return; }
    const d = res.data, a = d.agent, s = d.stats;

    const isBal = a.charge_mode === 2;
    const typeRows = (a.types || []).map(t => `
        <tr>
            <td>${esc(t.name)}</td>
            <td>${t.enabled ? tag('开放', 'green') : tag('不开放', 'gray')}</td>
            <td>${t.quota_total === -1 ? '不限' : t.quota_total + ' 张'}</td>
            <td>${t.quota_used} 张</td>
            <td>${isBal
                ? ('¥ ' + t.price_text + ' / 张'
                   + (t.price > 0 ? `<div style="font-size:12px;color:#7c3aed">按余额可生成 ${t.can_make} 张</div>` : ''))
                : '-'}</td>
            <td>${t.group_name ? esc(t.group_name) : '<span style="color:#9ca3af">跟随兜底</span>'}</td>
        </tr>`).join('');

    const cardRows = (d.cards || []).map(x => `
        <tr>
            <td class="mono">${esc(x.code)}</td>
            <td>${esc(x.type_text)}</td>
            <td>${esc(x.duration_text)}</td>
            <td>${x.max_devices}</td>
            <td>${statusTag(x.status, { 0: ['未使用', 'green'], 1: ['已使用', 'gray'], 2: ['已作废', 'red'] })}</td>
            <td>${x.used_by ? 'UID:' + x.used_by : '-'}</td>
            <td class="mono" style="font-size:12px">${esc(x.created_at_text)}</td>
        </tr>`).join('');

    const batchRows = (d.batches || []).map(b => `
        <tr>
            <td>#${b.id}</td>
            <td>${esc(b.name || '-')}</td>
            <td class="mono">${esc(b.prefix || '-')}</td>
            <td>${b.count}</td>
            <td>${b.used_count}</td>
            <td class="mono" style="font-size:12px">${esc(b.created_at_text)}</td>
        </tr>`).join('');

    const logRows = (d.logs || []).map(l => `
        <tr>
            <td>${esc(l.action_text)}</td>
            <td>${esc(l.detail || '-')}</td>
            <td>${l.amount !== 0 ? l.amount : '-'}</td>
            <td class="mono" style="font-size:12px">${esc(l.ip || '-')}</td>
            <td class="mono" style="font-size:12px">${esc(l.time_text)}</td>
        </tr>`).join('');

    openModal('代理商详情 · ' + a.username, `
    <div class="kv">
        <span class="k">账号</span><span class="v mono"><b>${esc(a.username)}</b></span>
        <span class="k">名称</span><span class="v">${esc(a.nickname)}</span>
        <span class="k">联系方式</span><span class="v">${esc(a.contact || '-')}</span>
        <span class="k">所属软件</span><span class="v">${esc(a.software_name || '-')}</span>
        <span class="k">控量模式</span><span class="v">${modeTag(a.charge_mode)}</span>
        <span class="k">账户余额</span><span class="v">¥ ${esc(a.balance_text)}</span>
        <span class="k">设备上限</span><span class="v">${a.max_devices}</span>
        <span class="k">兜底用户组</span><span class="v">${a.group_name ? esc(a.group_name) : '不换组'}</span>
        <span class="k">卡密固定前缀</span><span class="v">${a.card_prefix ? `<b class="mono">${esc(a.card_prefix)}</b>` : '不限制（代理自填）'}</span>
        <span class="k">允许作废</span><span class="v">${a.can_void ? '是' : '否'}</span>
        <span class="k">来源</span><span class="v">${a.reg_code ? ('激活码 ' + esc(a.reg_code)) : '管理员创建'}</span>
        <span class="k">状态</span><span class="v">${statusTag(a.status, { 1: ['正常', 'green'], 0: ['已禁用', 'gray'] })}</span>
        <span class="k">最后登录</span><span class="v">${esc(a.last_login_text || '-')} ${esc(a.last_login_ip || '')}</span>
        <span class="k">创建时间</span><span class="v">${esc(a.created_at_text)}</span>
        <span class="k">备注</span><span class="v">${esc(a.remark || '-')}</span>
    </div>

    <h4 style="margin:18px 0 10px;font-size:14px">按卡类型的额度与单价</h4>
    <div class="table-wrap"><table>
        <thead><tr><th>卡类型</th><th>是否开放</th><th>额度</th><th>已用</th><th>单价</th><th>激活用户组</th></tr></thead>
        <tbody>${typeRows}</tbody>
    </table></div>

    <div class="stats" style="margin-top:18px">
        <div class="stat c1"><div class="label">卡密总数</div><div class="value">${s.total}</div></div>
        <div class="stat c2"><div class="label">未使用</div><div class="value">${s.unused}</div></div>
        <div class="stat"><div class="label">已使用</div><div class="value" style="color:var(--text-sub)">${s.used}</div></div>
        <div class="stat"><div class="label">已作废</div><div class="value" style="color:#ef4444">${s.void}</div></div>
        <div class="stat c1"><div class="label">今日生成</div><div class="value">${s.today}</div></div>
        <div class="stat"><div class="label">批次数量</div><div class="value">${s.batches}</div></div>
    </div>

    <h4 style="margin:18px 0 10px;font-size:14px">最近 30 张卡密</h4>
    ${cardRows ? `<div class="table-wrap"><table>
        <thead><tr><th>卡密</th><th>类型</th><th>时长/点数</th><th>设备</th><th>状态</th><th>使用者</th><th>生成时间</th></tr></thead>
        <tbody>${cardRows}</tbody></table></div>` : '<p style="color:#9ca3af;font-size:13px">暂无卡密</p>'}

    <h4 style="margin:18px 0 10px;font-size:14px">最近批次</h4>
    ${batchRows ? `<div class="table-wrap"><table>
        <thead><tr><th>批次</th><th>名称</th><th>前缀</th><th>数量</th><th>已用</th><th>时间</th></tr></thead>
        <tbody>${batchRows}</tbody></table></div>` : '<p style="color:#9ca3af;font-size:13px">暂无批次</p>'}

    <h4 style="margin:18px 0 10px;font-size:14px">操作记录</h4>
    ${logRows ? `<div class="table-wrap"><table>
        <thead><tr><th>操作</th><th>详情</th><th>张数</th><th>IP</th><th>时间</th></tr></thead>
        <tbody>${logRows}</tbody></table></div>` : '<p style="color:#9ca3af;font-size:13px">暂无记录</p>'}`,
    [{ text: '关闭', cls: '', act: closeModal }], 'wide');
}
