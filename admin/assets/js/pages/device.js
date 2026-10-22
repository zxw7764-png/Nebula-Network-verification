import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { pageState } from '../core/state.js';
import { loading, empty, esc, tag, copyText } from '../core/util.js';
import {
    openModal, closeModal, confirmBox, toast,
    pager, bindPager, createSelection, checkAllBox, rowCheckBox,
} from '../core/ui.js';

const DEFAULTS = { page: 1, size: 20, keyword: '', status: '', online: false, risk: '', fp: '' };



const rowCache = new Map();

let sel = null;

register('device_list', render);



function riskTag(x) {
    if (!x.risk || !x.risk.length) return '';
    const color = x.vm ? 'red' : 'amber';
    const text = x.risk_text || x.risk.join('/');
    return `<span title="${esc(text)}">${tag(x.vm ? '虚拟环境' : '风险', color)}</span>`;
}



function fpCell(x) {
    if (!x.fp_count) {
        return '<span style="color:#9ca3af" title="客户端未上报 device_fp">未上报</span>';
    }
    const view = x.fp_components_view || [];
    const names = view.map(c => c.label).join('·');
    const pct = x.fp_max_score > 0 ? Math.round((x.fp_score || 0) * 100 / x.fp_max_score) : 0;
    return `<span data-fp="${x.id}"
        title="点击查看硬件明细：${esc(names)}（权重完整度 ${pct}%）"
        style="color:#2563eb;cursor:pointer;white-space:nowrap">${x.fp_count}项
        <span style="color:#6b7280;font-size:12px;margin-left:4px">${esc(names)}</span></span>`;
}

async function render() {
    const st = pageState('device_list', DEFAULTS);
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('device_list', st);
    if (res.code !== 0) return;
    const d = res.data;

    rowCache.clear();
    d.list.forEach(x => rowCache.set(x.id, x));

    const rows = d.list.map(x => `
        <tr>
            ${rowCheckBox(x.id)}
            <td>${x.id}</td>
            <td>${x.user_id}</td>
            <td><b>${esc(x.username || '-')}</b></td>
            <td class="mono" style="font-size:12px">${esc(x.machine_id)}</td>
            <td>${esc(x.device_name || '-')}</td>
            <td class="mono">${esc(x.ip || '-')}</td>
            <td>${x.online ? tag('在线', 'green') : (x.status === 1 ? tag('离线', 'gray') : tag('已解绑', 'red'))}</td>
            <td>${fpCell(x)}</td>
            <td>${riskTag(x) || '<span style="color:#9ca3af">-</span>'}</td>
            <td class="mono" style="font-size:12px">${esc(x.last_seen)}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-act="fp" data-id="${x.id}">指纹</button>
                ${x.status === 1 ? `<button class="btn danger sm" data-act="unbind" data-id="${x.id}">解绑</button>` : ''}
                <button class="btn ghost sm" data-act="ban" data-id="${x.id}">拉黑</button>
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>设备列表</h3>
            <div class="toolbar">
                <input id="dKw" placeholder="搜索机器码/用户名/IP" value="${esc(st.keyword)}">
                <select id="dStatus">
                    <option value="">全部状态</option>
                    <option value="1" ${st.status === '1' ? 'selected' : ''}>正常</option>
                    <option value="0" ${st.status === '0' ? 'selected' : ''}>已解绑</option>
                </select>
                <select id="dRisk">
                    <option value="">全部风险</option>
                    <option value="vm" ${st.risk === 'vm' ? 'selected' : ''}>仅虚拟环境</option>
                    <option value="any" ${st.risk === 'any' ? 'selected' : ''}>仅有风险标记</option>
                </select>
                <select id="dFp">
                    <option value="">全部指纹</option>
                    <option value="none" ${st.fp === 'none' ? 'selected' : ''}>未上报指纹</option>
                </select>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
                    <input type="checkbox" id="dOnline" ${st.online ? 'checked' : ''} style="width:auto">
                    仅在线
                </label>
                <button class="btn" id="dSearch">搜索</button>
            </div>
            <div class="acts">
                <span id="dBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="dBulkOp">
                        <option value="unbind">批量解绑</option>
                        <option value="ban">批量拉黑机器码</option>
                        <option value="delete">批量删除记录</option>
                    </select>
                    <button class="btn" id="dBulkRun">执行</button>
                </span>
                <button class="btn bulk-hide ghost" id="dGc">清理离线设备</button>
            </div>
        </div>

        ${d.risk_total ? `<div style="padding:10px 20px;background:#fffbeb;border-bottom:1px solid #fde68a;font-size:13px;color:var(--warning)">
            检测到 <b>${d.risk_total}</b> 台设备存在指纹风险（疑似模拟器/虚拟机、机器码伪造或多账号共用），可切换上方「风险」筛选查看。
        </div>` : ''}

        ${d.nofp_total ? `<div style="padding:10px 20px;background:#eff6ff;border-bottom:1px solid #bfdbfe;font-size:13px;color:#1e40af">
            有 <b>${d.nofp_total}</b> 台设备<strong>未上报硬件指纹</strong>（客户端未接入 device_fp）。
            这些设备无法使用漂移容忍与虚拟机识别，建议引导用户升级客户端；可切换上方「指纹 → 未上报指纹」查看。
        </div>` : ''}

        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>用户ID</th><th>用户名</th><th>机器码</th><th>设备名</th>
                    <th>IP</th><th>状态</th><th>硬件指纹</th><th>风险</th><th>最后活跃</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="12">${empty('<i class="bi bi-phone"></i>', '暂无设备')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>`;

    bindPager(c, p => { st.page = p; render(); });
    document.getElementById('dKw').addEventListener('keydown', e => {
        if (e.key === 'Enter') doSearch();
    });
    document.getElementById('dSearch').addEventListener('click', doSearch);
    document.getElementById('dGc').addEventListener('click', devGc);

    c.querySelectorAll('[data-act]').forEach(b => {
        b.addEventListener('click', () => {
            const id = parseInt(b.dataset.id, 10);
            if (b.dataset.act === 'unbind') devUnbind(id);
            else if (b.dataset.act === 'ban') devBan([id]);
            else if (b.dataset.act === 'fp') devFp(id);
        });
    });

    c.querySelectorAll('[data-fp]').forEach(a => {
        a.addEventListener('click', () => devFp(parseInt(a.dataset.fp, 10)));
    });


    sel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        const box = document.getElementById('dBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});
    document.getElementById('dBulkRun').addEventListener('click', () => {
        const ids = sel ? sel.ids() : [];
        if (!ids.length) return toast('请先选择设备', 'warn');
        const op = document.getElementById('dBulkOp').value;
        if (op === 'unbind') {
            confirmBox('批量解绑', `将解绑已选的 ${ids.length} 台设备，对应用户会被强制下线。确定继续？`,
                async () => {
                    const res = await api('device_unbind', { op: 'batch', device_ids: ids });
                    if (res.code === 0) { toast(res.msg); if (sel) sel.clear(); render(); }
                }, true);
        } else if (op === 'ban') {
            devBan(ids);
        } else if (op === 'delete') {
            devDelete(ids);
        }
    });
}

function doSearch() {
    const st = pageState('device_list', DEFAULTS);
    st.keyword = document.getElementById('dKw').value.trim();
    st.status = document.getElementById('dStatus').value;
    st.risk = document.getElementById('dRisk').value;
    st.fp = document.getElementById('dFp').value;
    st.online = document.getElementById('dOnline').checked;
    st.page = 1;
    render();
}



function devFp(id) {
    const x = rowCache.get(id);
    if (!x) return;

    const comps = x.fp_components_view || [];
    const maxScore = x.fp_max_score || 110;
    const pct = maxScore > 0 ? Math.round((x.fp_score || 0) * 100 / maxScore) : 0;
    const barColor = pct >= 85 ? '#16a34a' : (pct >= 55 ? '#f59e0b' : '#dc2626');

    const rows = comps.length ? comps.map(c => `
        <tr>
            <td><b>${esc(c.label)}</b>
                <span style="color:#9ca3af;font-size:12px">权重 ${c.weight}</span></td>
            <td class="mono" style="font-size:12px;word-break:break-all">
                ${esc(c.value)}
                ${c.vm_oui ? ' ' + tag('虚拟机网卡', 'red') : ''}
            </td>
        </tr>`).join('')
        : `<tr><td colspan="2" style="color:#9ca3af">该设备未上报硬件组件明细</td></tr>`;

    openModal('设备指纹详情', `
        <div style="margin-bottom:14px">
            <div style="font-size:13px;color:var(--text-sub);margin-bottom:8px">
                用户 <b>${esc(x.username || '-')}</b>（ID ${x.user_id}） ·
                ${x.online ? tag('在线', 'green') : tag('离线', 'gray')}
            </div>
            <table style="width:100%">
                <tr><td style="width:110px;color:#6b7280">机器码</td>
                    <td class="mono" style="font-size:12px;word-break:break-all">${esc(x.machine_id)}</td></tr>
                <tr><td style="color:#6b7280">设备名</td><td>${esc(x.device_name || '-')}</td></tr>
                <tr><td style="color:#6b7280">系统信息</td><td>${esc(x.os_info || '-')}</td></tr>
                <tr><td style="color:#6b7280">绑定时间</td><td>${esc(x.bind_at || '-')}</td></tr>
                <tr><td style="color:#6b7280">最后活跃</td><td>${esc(x.last_seen || '-')} · ${esc(x.ip || '-')}</td></tr>
            </table>
        </div>

        <div style="margin-bottom:6px;font-size:13px">
            <b>硬件组件</b>
            <span style="color:#6b7280">完整度 ${pct}%（权重 ${x.fp_score || 0}/${maxScore}）</span>
        </div>
        <div style="height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;margin-bottom:12px">
            <div style="height:100%;width:${pct}%;background:${barColor}"></div>
        </div>
        <div class="table-wrap" style="max-height:280px;overflow:auto">
            <table>
                <thead><tr><th style="width:130px">组件</th><th>客户端上报值</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>

        <div class="field" style="margin-top:14px">
            <label>加权指纹 fp_hash</label>
            <input id="fpH" class="mono" readonly value="${esc(x.fp_hash || '')}"
                   style="font-size:12px" placeholder="未上报">
        </div>

        <div style="margin-top:10px">
            <div style="font-size:13px;margin-bottom:6px"><b>风险标记</b></div>
            ${x.risk && x.risk.length
                ? x.risk.map(r => tag([...x.risk].includes(r) ? riskLabel(r) : r, r === 'vm' ? 'red' : 'amber')).join(' ')
                : '<span style="color:#9ca3af">无</span>'}
        </div>

        <div class="hint" style="margin-top:12px">
            指纹由客户端主动上报。同一台机器正常升级（换硬盘/网卡/显卡/刷 BIOS）不会被判为换机，
            只有核心组件（主板 / CPU）变化才标记「机器码疑似伪造」。
        </div>`,
        [{ text: '复制指纹', cls: 'ghost', act: () => {
             if (!x.fp_hash) return toast('该设备未上报指纹', 'warn');
             copyText(x.fp_hash).then(() => toast('已复制'));
         }},
         { text: '关闭', cls: 'ghost', act: closeModal }]);
}

const RISK_LABELS = {
    vm: '疑似模拟器/虚拟机',
    low_entropy: '硬件信息过少',
    same_value: '组件值雷同',
    fp_changed: '机器码疑似伪造',
    multi_fp: '同账号多机器指纹',
    shared_machine: '同一机器多账号',
};
function riskLabel(k) { return RISK_LABELS[k] || k; }

function devUnbind(id) {
    confirmBox('解绑设备', '确定解绑该设备？用户会被强制下线。', async () => {
        const res = await api('device_unbind', { op: 'single', device_id: id });
        if (res.code === 0) { toast('已解绑'); render(); }
    }, true);
}

function devBan(ids) {
    openModal('拉黑机器码', `
        <p style="color:var(--text-sub);margin-bottom:14px">
            拉黑后该机器码将无法再绑定任何账号（登录时会被拒绝）。
        </p>
        <div class="field">
            <label>拉黑原因</label>
            <input id="banReason" placeholder="如：异常刷接口 / 多开" value="管理员拉黑">
        </div>
        <div class="row2">
            <div class="field">
                <label>拉黑时长</label>
                <input id="banDur" type="number" min="0" value="0" placeholder="0=永久">
            </div>
            <div class="field">
                <label>单位</label>
                <select id="banUnit">
                    <option value="day" selected>天</option>
                    <option value="week">星期</option>
                    <option value="month">月</option>
                    <option value="year">年</option>
                    <option value="hour">小时</option>
                    <option value="minute">分钟</option>
                    <option value="second">秒</option>
                </select>
            </div>
        </div>
        <div class="hint">0 = 永久拉黑；将对已选的 ${ids.length} 台设备生效</div>`,
        [{ text: '取消', cls: 'ghost', act: closeModal },
         { text: '确认拉黑', cls: 'danger', act: async () => {
             const res = await api('device_ban', {
                 device_ids: ids,
                 reason: document.getElementById('banReason').value.trim(),
                 duration: parseInt(document.getElementById('banDur').value, 10) || 0,
                 unit: document.getElementById('banUnit').value,
             });
             if (res.code === 0) { toast(res.msg); closeModal(); if (sel) sel.clear(); render(); }
         }}]);
}

function devGc() {
    confirmBox('清理离线设备', '将解绑所有心跳超时的设备，确定继续？', async () => {
        const res = await api('device_unbind', { op: 'gc' });
        if (res.code === 0) { toast(res.msg); render(); }
    });
}



function devDelete(ids) {
    openModal('批量删除设备记录', `
        <p style="color:var(--danger);margin-bottom:14px">
            <b>此操作不可恢复！</b>设备绑定记录将被永久删除，对应用户会被强制下线。
        </p>
        <p style="color:var(--text-sub);margin-bottom:14px;font-size:13px">
            提示：若只想让用户重新绑定设备，请使用「批量解绑」——那会保留历史记录。
            删除后用户可立即重新绑定同一台设备（受最大设备数限制）。
        </p>
        <div class="field">
            <label>管理密码 *</label>
            <input id="ddPass" type="password" autocomplete="off" placeholder="请输入管理密码">
        </div>
        <div class="hint">将对已选的 ${ids.length} 台设备生效</div>`,
        [{ text: '取消', cls: 'ghost', act: closeModal },
         { text: '确认删除', cls: 'danger', act: async () => {
             const pass = document.getElementById('ddPass').value;
             if (!pass) return toast('请输入管理密码', 'warn');
             const res = await api('device_unbind', { op: 'delete', device_ids: ids, password: pass });
             if (res.code === 0) { toast(res.msg); closeModal(); if (sel) sel.clear(); render(); }
         }}]);
    setTimeout(() => { const i = document.getElementById('ddPass'); if (i) i.focus(); }, 50);
}
