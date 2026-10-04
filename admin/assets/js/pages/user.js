import { api, apiDownload } from '../core/api.js';
import { register, go } from '../core/router.js';
import { pageState, resetPageState, S } from '../core/state.js';
import {
    loading, empty, esc, tag, statusTag, ts2str, str2ts, downloadBlob, copyText,
} from '../core/util.js';
import {
    openModal, closeModal, confirmBox, confirmPassword, toast,
    pager, bindPager, createSelection, checkAllBox, rowCheckBox,
} from '../core/ui.js';

const DEFAULTS = { page: 1, size: 20, keyword: '', status: '', sw: 0, sort: 'id', order: 'desc' };

let sel = null;
let swCache = [];
let groupCache = [];

register('user_list', render);

async function render() {
    const st = pageState('user_list', DEFAULTS);
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('user_list', st);
    if (res.code !== 0) return;
    const d = res.data;
    swCache = d.softwares || [];
    groupCache = d.groups || [];

    const isSuper = S.admin && parseInt(S.admin.role, 10) === 1;
    const rows = d.list.map(u => `
        <tr>
            ${rowCheckBox(u.id)}
            <td>${u.id}</td>
            <td><b>${esc(u.username)}</b>${u.nickname && u.nickname !== u.username
                ? `<div style="font-size:12px;color:#9ca3af">${esc(u.nickname)}</div>` : ''}</td>
            <td>${u.software_id > 0 ? tag(esc(u.software_name || ('软件#' + u.software_id)), 'blue')
                : tag('通用', 'gray')}</td>
            <td>${u.status === 0 && u.ban_text
                ? `<div>${tag('封禁', 'red')}</div><div style="font-size:11px;color:#9ca3af;margin-top:2px">${esc(u.ban_text)}</div>`
                : statusTag(u.status, { 0: ['封禁', 'red'], 1: ['正常', 'green'], 2: ['冻结', 'yellow'] })}</td>
            <td>${u.vip_text === '未激活' ? tag('未激活', 'gray')
                : (u.vip_valid ? tag(u.vip_text, 'green') : tag(u.vip_text, 'red'))}</td>
            <td>${u.points}</td>
            <td>${u.device_count}/${u.max_devices}</td>
            <td class="mono">${esc(u.last_login_ip || '-')}</td>
            <td class="mono" style="font-size:12px">${esc(u.created_at)}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-act="detail" data-id="${u.id}">详情</button>
                <button class="btn ghost sm" data-act="edit" data-id="${u.id}">编辑</button>
                ${isSuper ? `<button class="btn danger sm" data-act="del" data-id="${u.id}" data-name="${esc(u.username)}">删除</button>` : ''}
            </td>
        </tr>`).join('');

    const swOpts = (d.softwares || []).map(s =>
        `<option value="${s.id}" ${st.sw == s.id ? 'selected' : ''}>${esc(s.name)}</option>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>用户列表</h3>
            <div class="toolbar">
                <input id="uKw" placeholder="搜索用户名/邮箱/昵称" value="${esc(st.keyword)}">
                <select id="uStatus">
                    <option value="">全部状态</option>
                    <option value="1" ${st.status === '1' ? 'selected' : ''}>正常</option>
                    <option value="0" ${st.status === '0' ? 'selected' : ''}>封禁</option>
                    <option value="2" ${st.status === '2' ? 'selected' : ''}>冻结</option>
                </select>
                <select id="uSw">
                    <option value="0">全部软件</option>
                    ${swOpts}
                </select>
                <button class="btn" id="uSearch">搜索</button>
                <button class="btn ghost" id="uReset">重置</button>
            </div>
            <div class="acts">
                <span id="uBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="uBulkOp">
                        <option value="status-on">批量解封</option>
                        <option value="status-off">批量封禁</option>
                        <option value="add-days">批量加时长</option>
                        <option value="add-points">批量加点数</option>
                        <option value="kick">批量下线</option>
                        <option value="clear-devices">批量清空设备</option>
                        ${isSuper ? '<option value="delete">批量删除</option>' : ''}
                    </select>
                    <button class="btn" id="uBulkRun">执行</button>
                </span>
                <button class="btn bulk-hide ghost" id="uImport">导入</button>
                <button class="btn bulk-hide ghost" id="uExport">导出</button>
                <button class="btn bulk-hide success" id="uCreate">+ 新增用户</button>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>用户名</th><th>归属软件</th><th>状态</th><th>会员</th><th>点数</th>
                    <th>设备</th><th>最后登录IP</th><th>注册时间</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="10">${empty('<i class="bi bi-people"></i>', '没有用户')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>`;

    bindPager(c, p => { st.page = p; render(); });

    document.getElementById('uKw').addEventListener('keydown', e => {
        if (e.key === 'Enter') doSearch();
    });
    document.getElementById('uSearch').addEventListener('click', doSearch);
    document.getElementById('uReset').addEventListener('click', () => {
        resetPageState('user_list', DEFAULTS);
        render();
    });
    document.getElementById('uCreate').addEventListener('click', () => userCreate());
    document.getElementById('uImport').addEventListener('click', () => userImport());
    document.getElementById('uExport').addEventListener('click', () => userExport());


    c.querySelectorAll('[data-act]').forEach(b => {
        b.addEventListener('click', () => {
            const id = parseInt(b.dataset.id, 10);
            if (b.dataset.act === 'detail') userDetail(id);
            else if (b.dataset.act === 'edit') userEdit(id);
            else if (b.dataset.act === 'del') userDelete(id, b.dataset.name);
        });
    });


    sel = createSelection({
        root: c,
        allIds: d.list.map(u => u.id),
        onChange: ids => {
            const box = document.getElementById('uBulkBox');
            if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
            c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
        },
    });


    const bulkRun = document.getElementById('uBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('uBulkOp').value;
        doBulk(op);
    });
}

function doSearch() {
    const st = pageState('user_list', DEFAULTS);
    st.keyword = document.getElementById('uKw').value.trim();
    st.status = document.getElementById('uStatus').value;
    st.sw = parseInt(document.getElementById('uSw').value, 10) || 0;
    st.page = 1;
    render();
}



async function doBulk(op) {
    const ids = sel ? sel.ids() : [];
    if (!ids.length) return toast('请先选择用户', 'warn');

    if (op === 'delete') {
        confirmPassword('批量删除用户',
            `将永久删除 ${ids.length} 个用户及其设备、会话，卡密会归还为未使用。此操作不可恢复。`,
            async (pass) => {
                const res = await api('user_batch_op', { op: 'delete', ids, password: pass });
                if (res.code === 0) { toast(res.msg); if (sel) sel.clear(); render(); }
            });
        return;
    }

    if (op === 'clear-devices') {
        confirmBox('批量清空设备', `将解绑已选的 ${ids.length} 个用户的全部设备并强制下线，确定继续？`,
            async () => {
                const res = await api('device_unbind', { op: 'user', user_id: ids[0] });
                if (res.code !== 0) return;
                let total = (res.data && res.data.count) || 0;
                for (let i = 1; i < ids.length; i++) {
                    const r2 = await api('device_unbind', { op: 'user', user_id: ids[i] });
                    if (r2.code === 0) total += (r2.data && r2.data.count) || 0;
                }
                toast(`已解绑 ${total} 台设备`);
                if (sel) sel.clear();
                render();
            }, true);
        return;
    }

    if (op === 'add-days' || op === 'add-points') {
        const isDays = op === 'add-days';
        openModal(isDays ? '批量增加时长' : '批量增加点数', `
            <div class="field">
                <label>${isDays ? '增加天数' : '增加点数'}（可为负数表示扣减）</label>
                <input id="bulkNum" type="number" value="${isDays ? 30 : 100}">
            </div>
            <div class="hint">将对已选的 ${ids.length} 个用户生效</div>`,
            [{ text: '取消', cls: 'ghost', act: closeModal },
             { text: '确定', cls: '', act: async () => {
                 const n = parseInt(document.getElementById('bulkNum').value, 10) || 0;
                 if (!n) return toast('请输入数值', 'warn');
                 const res = await api('user_batch_op', {
                     op: isDays ? 'add_days' : 'add_points', ids, value: n,
                 });
                 if (res.code === 0) { toast(res.msg); closeModal(); if (sel) sel.clear(); render(); }
             }}]);
        return;
    }

    if (op === 'status-off') {
        openModal('批量封禁', `
            <div class="row2">
                <div class="field"><label>封禁时长</label><input id="bBanDur" type="number" min="0" value="0" placeholder="0=永久"></div>
                <div class="field"><label>单位</label><select id="bBanUnit">
                    <option value="day" selected>天</option><option value="week">星期</option><option value="month">月</option>
                    <option value="year">年</option><option value="hour">小时</option><option value="minute">分钟</option><option value="second">秒</option>
                </select></div>
            </div>
            <div class="hint">对已选的 ${ids.length} 个用户生效；0=永久封禁，限时封禁到期自动解封</div>`,
            [{ text: '取消', cls: 'ghost', act: closeModal },
             { text: '确定', cls: '', act: async () => {
                 const res = await api('user_batch_op', {
                     op: 'status', value: 0, ids,
                     duration: parseInt(document.getElementById('bBanDur').value, 10) || 0,
                     unit: document.getElementById('bBanUnit').value,
                 });
                 if (res.code === 0) { toast(res.msg); closeModal(); if (sel) sel.clear(); render(); }
             }}]);
        return;
    }

    const map = {
        'status-on':  { op: 'status', value: 1, label: '解封' },
        'kick':       { op: 'kick', label: '强制下线' },
    };
    const cfg = map[op];
    if (!cfg) return;

    confirmBox('批量操作', `将对已选的 ${ids.length} 个用户执行「${cfg.label}」，确定继续？`, async () => {
        const payload = { op: cfg.op, ids };
        if (cfg.value !== undefined) payload.value = cfg.value;
        const res = await api('user_batch_op', payload);
        if (res.code === 0) { toast(res.msg); if (sel) sel.clear(); render(); }
    });
}



function userExport() {
    const groupOpts = (groupCache || []).map(g =>
        `<option value="group:${g.id}">${esc(g.name)}</option>`).join('');
    openModal('导出用户', `
        <div class="row2">
            <div class="field"><label>导出范围</label>
                <select id="ueScope">
                    <option value="filter">当前筛选结果</option>
                    <option value="all">全部用户</option>
                    ${groupOpts}
                </select>
            </div>
            <div class="field"><label>导出格式</label>
                <select id="ueFmt">
                    <option value="csv">CSV（含详细信息）</option>
                    <option value="txt">TXT（用户名+密码占位）</option>
                </select>
            </div>
        </div>
        <div class="field"><label>最大条数</label><input id="ueLimit" type="number" value="10000"></div>
        <div class="hint">CSV 会包含用户名、昵称、邮箱、状态、会员到期、点数、设备上限等字段，可直接用于导入。</div>`,
        [{ text: '取消', cls: 'ghost', act: closeModal },
         { text: '导出', cls: '', act: async () => {
             const st = pageState('user_list', DEFAULTS);
             const scope = document.getElementById('ueScope').value;
             const payload = {
                 format: document.getElementById('ueFmt').value,
                 limit: parseInt(document.getElementById('ueLimit').value, 10) || 10000,
             };
             if (scope === 'filter') {
                 payload.keyword = st.keyword;
                 payload.status = st.status;
             } else if (scope.startsWith('group:')) {
                 payload.group_id = parseInt(scope.slice(6), 10) || 0;
             }
             try {
                 const { blob, name } = await apiDownload('user_export', payload, 'users.csv');
                 downloadBlob(blob, name);
                 toast('导出成功');
                 closeModal();
             } catch (e) { toast('导出失败：' + e.message, 'err'); }
         }}], 'wide');
}



function userImport() {
    openModal('批量导入用户', `
        <div class="field">
            <label>CSV 文件</label>
            <input id="uiFile" type="file" accept=".csv,.txt">
            <div class="hint">格式：每行 <code>用户名,密码,昵称,邮箱,会员天数,点数,设备上限</code>，
                至少需要「用户名,密码」两列。首行若为表头会自动跳过。</div>
        </div>
        <div class="row2">
            <div class="field"><label>已存在用户</label>
                <select id="uiDup">
                    <option value="skip">跳过（不修改）</option>
                    <option value="update">更新（覆盖信息）</option>
                </select>
            </div>
            <div class="field"><label>会员时长(天)</label>
                <input id="uiDays" type="number" value="0" placeholder="0=不激活">
                <div class="hint">当 CSV 未指定天数时使用此默认值</div>
            </div>
        </div>
        <div id="uiPreview" style="margin-top:8px"></div>`,
        [{ text: '取消', cls: 'ghost', act: closeModal },
         { text: '开始导入', cls: 'success', act: doImport }], 'wide');

    const fileInput = document.getElementById('uiFile');
    fileInput.addEventListener('change', async () => {
        const f = fileInput.files[0];
        if (!f) return;
        const text = await f.text();
        const lines = text.split(/\r?\n/).filter(l => l.trim());
        const preview = lines.slice(0, 6).map(l => esc(l)).join('<br>');
        document.getElementById('uiPreview').innerHTML = `
            <div class="hint" style="margin-bottom:6px">共读取 ${lines.length} 行，预览前 ${Math.min(6, lines.length)} 行：</div>
            <div class="code-box" style="max-height:160px">${preview}</div>`;
    });
}

async function doImport() {
    const f = document.getElementById('uiFile').files[0];
    if (!f) return toast('请先选择文件', 'warn');
    const text = await f.text();
    const res = await api('user_import', {
        content: text,
        dup: document.getElementById('uiDup').value,
        default_days: parseInt(document.getElementById('uiDays').value, 10) || 0,
    });
    if (res.code !== 0) return;
    const d = res.data;
    closeModal();
    openModal('导入结果', `
        <div class="stats" style="margin-bottom:14px">
            <div class="stat c2"><div class="label">成功</div><div class="value">${d.ok}</div></div>
            <div class="stat c1"><div class="label">跳过</div><div class="value">${d.skip}</div></div>
            <div class="stat c3"><div class="label">更新</div><div class="value">${d.update}</div></div>
            <div class="stat c4"><div class="label">失败</div><div class="value">${d.fail}</div></div>
        </div>
        ${d.errors && d.errors.length ? `
            <div class="hint" style="margin-bottom:6px">错误明细（最多显示 50 条）：</div>
            <div class="code-box" style="max-height:220px">${d.errors.map(esc).join('\n')}</div>`
        : '<p style="color:#059669">全部导入成功，无错误。</p>'}`,
        [{ text: '知道了', cls: '', act: () => { closeModal(); render(); } }], 'wide');
}



async function userDetail(id) {
    openModal('用户详情', loading(), [], 'wide');
    const res = await api('user_detail', { user_id: id });
    if (res.code !== 0) { closeModal(); return; }
    const d = res.data, u = d.user;

    const devRows = d.devices.map(x => `
        <tr>
            <td class="mono">${esc(x.machine_id.slice(0, 20))}</td>
            <td>${esc(x.device_name || '-')}</td>
            <td>${x.online ? tag('在线', 'green') : tag('离线', 'gray')}</td>
            <td class="mono">${esc(x.ip || '-')}</td>
            <td class="mono" style="font-size:12px">${esc(x.last_seen)}</td>
            <td>${x.status === 1 ? `<button class="btn danger sm" data-unbind="${x.id}">解绑</button>` : tag('已解绑', 'gray')}</td>
        </tr>`).join('');

    const cardRows = d.cards.map(x => `
        <tr>
            <td class="mono">
                ${esc(x.code)}
                <button class="btn ghost sm" data-act="copy-card" data-code="${esc(x.code)}" style="margin-left:6px">复制</button>
            </td>
            <td>${esc(x.type_text)}</td>
            <td class="mono" style="font-size:12px">${esc(x.used_at_text)}</td>
            <td class="mono">${esc(x.used_ip || '-')}</td>
        </tr>`).join('');

    const logRows = d.logs.map(l => `
        <tr>
            <td class="mono" style="font-size:12px">${esc(l.time_text)}</td>
            <td>${esc(l.action)}</td>
            <td>${l.result == 1 ? tag('成功', 'green') : tag('失败', 'red')}</td>
            <td style="color:#6b7280">${esc(l.message || '')}</td>
            <td class="mono">${esc(l.ip || '')}</td>
        </tr>`).join('');

    const sessRows = d.sessions.map(s => `
        <tr>
            <td class="mono">${esc((s.machine_id || '').slice(0, 18))}</td>
            <td class="mono">${esc(s.ip || '-')}</td>
            <td>${s.online ? tag('在线', 'green') : tag('离线', 'gray')}</td>
            <td class="mono" style="font-size:12px">${esc(s.last_active_text)}</td>
        </tr>`).join('');

    const body = `
    <div class="tabs" id="uTabs">
        <button class="on" data-t="0">基本信息</button>
        <button data-t="1">设备 (${d.devices.length})</button>
        <button data-t="2">卡密记录 (${d.cards.length})</button>
        <button data-t="3">会话 (${d.sessions.length})</button>
        <button data-t="4">日志 (${d.logs.length})</button>
    </div>
    <div data-p="0">
        <div class="kv">
            <span class="k">用户 ID</span><span class="v">${u.id}</span>
            <span class="k">用户名</span><span class="v">${esc(u.username)}</span>
            <span class="k">昵称</span><span class="v">${esc(u.nickname || '-')}</span>
            <span class="k">邮箱</span><span class="v">${esc(u.email || '-')}</span>
            <span class="k">状态</span><span class="v">${statusTag(u.status, { 0: ['封禁', 'red'], 1: ['正常', 'green'], 2: ['冻结', 'yellow'] })}</span>
            <span class="k">用户组</span><span class="v">${esc(u.group_name)}</span>
            <span class="k">归属软件</span><span class="v">${u.software_id > 0 ? tag(esc(u.software_name || ('软件#' + u.software_id)), 'blue') : tag('通用（未绑定）', 'gray')}</span>
            <span class="k">会员到期</span><span class="v">${esc(u.vip_text)}</span>
            <span class="k">剩余点数</span><span class="v">${u.points}</span>
            <span class="k">设备上限</span><span class="v">${u.max_devices}</span>
            <span class="k">激活卡密</span><span class="v mono" style="font-size:12px">${esc(u.card_code || '-')}${u.card_code ? ` <button class="btn ghost sm" data-act="copy-card" data-code="${esc(u.card_code)}">复制</button>` : ''}</span>
            <span class="k">注册 IP</span><span class="v">${esc(u.register_ip || '-')}</span>
            <span class="k">最后登录</span><span class="v">${esc(u.last_login)} (${esc(u.last_login_ip || '-')})</span>
            <span class="k">注册时间</span><span class="v">${esc(u.created_at)}</span>
            <span class="k">备注</span><span class="v">${esc(u.remark || '-')}</span>
        </div>
    </div>
    <div data-p="1" style="display:none">
        <div class="table-wrap"><table>
            <thead><tr><th>机器码</th><th>设备名</th><th>状态</th><th>IP</th><th>最后活跃</th><th>操作</th></tr></thead>
            <tbody>${devRows || '<tr><td colspan="6">' + empty('<i class="bi bi-phone"></i>', '无设备') + '</td></tr>'}</tbody>
        </table></div>
    </div>
    <div data-p="2" style="display:none">
        <div class="table-wrap"><table>
            <thead><tr><th>卡密</th><th>类型</th><th>使用时间</th><th>使用IP</th></tr></thead>
            <tbody>${cardRows || '<tr><td colspan="4">' + empty('<i class="bi bi-credit-card"></i>', '无记录') + '</td></tr>'}</tbody>
        </table></div>
    </div>
    <div data-p="3" style="display:none">
        <div class="table-wrap"><table>
            <thead><tr><th>机器码</th><th>IP</th><th>状态</th><th>最后活跃</th></tr></thead>
            <tbody>${sessRows || '<tr><td colspan="4">' + empty('<i class="bi bi-activity"></i>', '无会话') + '</td></tr>'}</tbody>
        </table></div>
    </div>
    <div data-p="4" style="display:none">
        <div class="table-wrap"><table>
            <thead><tr><th>时间</th><th>动作</th><th>结果</th><th>说明</th><th>IP</th></tr></thead>
            <tbody>${logRows || '<tr><td colspan="5">' + empty('<i class="bi bi-journal-text"></i>', '无日志') + '</td></tr>'}</tbody>
        </table></div>
    </div>`;

    const btns = [
        { text: '重置密码', cls: 'ghost', act: () => userResetPwd(id) },
        { text: '强制下线', cls: 'ghost', act: () => userKick(id) },
        { text: '清空设备', cls: 'ghost', act: () => userClearDev(id) },
        { text: '关闭', cls: '', act: closeModal },
    ];
    openModal(`用户详情 · ${u.username}`, body, btns, 'wide');

    document.querySelectorAll('#uTabs button').forEach(b => {
        b.addEventListener('click', () => {
            document.querySelectorAll('#uTabs button').forEach(x => x.classList.remove('on'));
            b.classList.add('on');
            document.querySelectorAll('[data-p]').forEach(p => {
                p.style.display = p.dataset.p === b.dataset.t ? '' : 'none';
            });
        });
    });

    document.querySelectorAll('[data-unbind]').forEach(b => {
        b.addEventListener('click', () => unbindDevice(parseInt(b.dataset.unbind, 10), id));
    });

    document.querySelectorAll('[data-act="copy-card"]').forEach(b => {
        b.addEventListener('click', () => copyText(b.dataset.code));
    });
}



function userCreate() {
    const body = `
    <div class="row2">
        <div class="field"><label>用户名 *</label><input id="cUser"></div>
        <div class="field"><label>密码 *</label><input id="cPass" type="text" placeholder="至少6位"></div>
    </div>
    <div class="row2">
        <div class="field"><label>昵称</label><input id="cNick"></div>
        <div class="field"><label>邮箱</label><input id="cEmail"></div>
    </div>
    <div class="field"><label>归属软件</label>
        <select id="cSw">
            <option value="0">通用（未绑定，可在任意软件登录）</option>
            ${swCache.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('')}
        </select>
    </div>
    <div class="row3">
        <div class="field"><label>设备上限</label><input id="cDev" type="number" value="1" min="1" max="99"></div>
        <div class="field"><label>剩余点数</label><input id="cPts" type="number" value="0"></div>
        <div class="field"><label>会员时长(天)</label><input id="cDays" type="number" value="0" placeholder="0=不激活"></div>
    </div>
    <div class="field"><label>备注</label><input id="cRemark"></div>`;

    openModal('新增用户', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '创建', cls: 'success', act: async () => {
            const days = parseInt(document.getElementById('cDays').value, 10) || 0;
            const payload = {
                op: 'create',
                username: document.getElementById('cUser').value.trim(),
                password: document.getElementById('cPass').value,
                nickname: document.getElementById('cNick').value.trim(),
                email: document.getElementById('cEmail').value.trim(),
                software_id: parseInt(document.getElementById('cSw').value, 10) || 0,
                max_devices: parseInt(document.getElementById('cDev').value, 10) || 1,
                points: parseInt(document.getElementById('cPts').value, 10) || 0,
                remark: document.getElementById('cRemark').value.trim(),
            };
            if (days > 0) payload.vip_expire = Math.floor(Date.now() / 1000) + days * 86400;
            const res = await api('user_save', payload);
            if (res.code === 0) { toast('用户创建成功'); closeModal(); render(); }
        }},
    ]);
}

async function userEdit(id) {
    const res = await api('user_detail', { user_id: id }, true);
    if (res.code !== 0) return;
    const u = res.data.user;
    const softwares = res.data.softwares || [];
    let banDirty = false;

    const body = `
    <div class="row2">
        <div class="field"><label>用户名</label><input value="${esc(u.username)}" disabled></div>
        <div class="field"><label>新密码（留空不改）</label><input id="ePass" type="text"></div>
    </div>
    <div class="row2">
        <div class="field"><label>昵称</label><input id="eNick" value="${esc(u.nickname || '')}"></div>
        <div class="field"><label>邮箱</label><input id="eEmail" value="${esc(u.email || '')}"></div>
    </div>
    <div class="field"><label>归属软件</label>
        <select id="eSw">
            <option value="0" ${u.software_id === 0 ? 'selected' : ''}>通用（未绑定，可在任意软件登录）</option>
            ${softwares.map(s => `<option value="${s.id}" ${u.software_id === s.id ? 'selected' : ''}>${esc(s.name)}</option>`).join('')}
        </select>
        <div class="hint">改动后该账号只能在所选软件的客户端登录（通用=任意软件）</div>
    </div>
    <div class="row3">
        <div class="field"><label>状态</label>
            <select id="eStatus">
                <option value="1" ${u.status === 1 ? 'selected' : ''}>正常</option>
                <option value="0" ${u.status === 0 ? 'selected' : ''}>封禁</option>
                <option value="2" ${u.status === 2 ? 'selected' : ''}>冻结</option>
            </select>
        </div>
        <div class="field"><label>设备上限</label><input id="eDev" type="number" value="${u.max_devices}" min="1" max="99"></div>
        <div class="field"><label>剩余点数</label><input id="ePts" type="number" value="${u.points}"></div>
    </div>
    <div class="row2" id="eBanBox" ${u.status === 0 ? '' : 'hidden'}>
        <div class="field"><label>封禁时长</label><input id="eBanDur" type="number" min="0" value="0" placeholder="0=永久"></div>
        <div class="field"><label>单位</label><select id="eBanUnit">
            <option value="day" selected>天</option><option value="week">星期</option><option value="month">月</option>
            <option value="year">年</option><option value="hour">小时</option><option value="minute">分钟</option><option value="second">秒</option>
        </select></div>
    </div>
    <div class="hint" id="eBanHint" ${u.status === 0 ? '' : 'hidden'}>0=永久封禁；限时封禁到期自动解封，改时长直接填写即可</div>
    <div class="field">
        <label>会员到期时间</label>
        <input id="eVip" value="${esc(u.vip_text === '永久' ? '-1' : u.vip_text)}" placeholder="YYYY-MM-DD HH:MM:SS 或 -1 表示永久">
        <div class="hint">也可用下方快捷操作增减天数</div>
    </div>
    <div class="row2">
        <div class="field"><label>增加天数</label><input id="eAddDays" type="number" value="0"></div>
        <div class="field"><label>增加点数</label><input id="eAddPts" type="number" value="0"></div>
    </div>
    <div class="field"><label>备注</label><input id="eRemark" value="${esc(u.remark || '')}"></div>`;

    openModal(`编辑用户 · ${u.username}`, body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '保存', cls: '', act: async () => {
            const payload = {
                user_id: id,
                nickname: document.getElementById('eNick').value.trim(),
                email: document.getElementById('eEmail').value.trim(),
                software_id: parseInt(document.getElementById('eSw').value, 10) || 0,
                max_devices: parseInt(document.getElementById('eDev').value, 10),
                points: parseInt(document.getElementById('ePts').value, 10),
                remark: document.getElementById('eRemark').value.trim(),
            };
            const statusVal = parseInt(document.getElementById('eStatus').value, 10);
            payload.status = statusVal;

            if (statusVal === 0 && (u.status !== 0 || banDirty)) {
                payload.duration = parseInt(document.getElementById('eBanDur').value, 10) || 0;
                payload.unit = document.getElementById('eBanUnit').value;
            }
            const pass = document.getElementById('ePass').value;
            if (pass) payload.password = pass;

            const vipStr = document.getElementById('eVip').value.trim();
            if (vipStr === '-1') payload.vip_expire = -1;
            else if (vipStr && vipStr !== '-') {
                const ts = str2ts(vipStr);
                if (ts) payload.vip_expire = ts;
            }

            const addDays = parseInt(document.getElementById('eAddDays').value, 10) || 0;
            const addPts = parseInt(document.getElementById('eAddPts').value, 10) || 0;
            if (addDays) payload.add_days = addDays;
            if (addPts) payload.add_points = addPts;

            const r = await api('user_save', payload);
            if (r.code === 0) { toast('保存成功'); closeModal(); render(); }
        }},
    ]);


    const stSel = document.getElementById('eStatus');
    const banBox = document.getElementById('eBanBox');
    if (stSel && banBox) {
        stSel.addEventListener('change', () => { banBox.hidden = stSel.value !== '0'; });
        const dur = document.getElementById('eBanDur');
        const unit = document.getElementById('eBanUnit');
        if (dur) dur.addEventListener('input', () => { banDirty = true; });
        if (unit) unit.addEventListener('change', () => { banDirty = true; });
    }
}



function userResetPwd(id) {
    confirmBox('重置密码', '将随机生成新密码并强制该用户下线，确定继续？', async () => {
        const res = await api('user_kick', { op: 'reset_password', user_id: id });
        if (res.code === 0) {
            openModal('密码已重置', `
                <p style="margin-bottom:12px;color:var(--text-sub)">请复制以下新密码并通知用户：</p>
                <div class="code-box">${esc(res.data.password)}</div>`,
                [{ text: '复制', cls: 'ghost', act: () => copyText(res.data.password).then(() => toast('已复制')) },
                 { text: '知道了', cls: '', act: closeModal }]);
        }
    });
}

function userKick(id) {
    confirmBox('强制下线', '将踢出该用户所有登录会话，确定继续？', async () => {
        const res = await api('user_kick', { op: 'kick', user_id: id });
        if (res.code === 0) toast(res.msg);
    });
}

function userClearDev(id) {
    confirmBox('清空设备', '将解绑该用户全部设备并强制下线，确定继续？', async () => {
        const res = await api('user_kick', { op: 'clear_devices', user_id: id });
        if (res.code === 0) { toast(res.msg); closeModal(); }
    }, true);
}

function userDelete(id, username) {
    confirmPassword('删除用户',
        `将永久删除用户「${username}」及其设备、会话、卡密记录。此操作不可恢复。`,
        async (pass) => {
            const res = await api('user_delete', { user_id: id, password: pass });
            if (res.code === 0) { toast('用户已删除'); closeModal(); render(); }
        });
}

function unbindDevice(deviceId, userId) {
    confirmBox('解绑设备', '确定解绑该设备？用户在该设备上会被强制下线。', async () => {
        const res = await api('device_unbind', { op: 'single', device_id: deviceId });
        if (res.code === 0) { toast('已解绑'); closeModal(); userDetail(userId); }
    }, true);
}
