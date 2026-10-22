import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { pageState } from '../core/state.js';
import { loading, empty, esc, tag, statusTag, copyText } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast, pager, bindPager,
         createSelection, checkAllBox, rowCheckBox } from '../core/ui.js';

const DEFAULTS = { tab: 'code', page: 1, size: 20, keyword: '', status: '', kind: '' };

register('agent_code_list', render);

let swCache = null;
let codeSel = null;
let codeLastList = [];
let rcSel = null;
let rcLastList = [];



function modeTag(mode) {
    const map = { 1: ['张数额度', 'blue'], 2: ['余额计费', 'purple'], 3: ['不限量', 'gray'] };
    const m = map[mode] || ['未知', 'gray'];
    return tag(m[0], m[1]);
}



function specCell(c) {
    const isBal = c.charge_mode === 2;
    const parts = (c.types || []).filter(t => t.enabled).map(t => {
        const spec = isBal ? t.price_text : t.quota_text;
        const grp  = t.group_name ? ` → ${t.group_name}` : '';
        return `${t.name} ${spec}${grp}`;
    });
    return parts.length ? `<span style="font-size:12px">${esc(parts.join(' · '))}</span>`
                        : '<span style="color:#9ca3af;font-size:12px">未开放任何类型</span>';
}





function tabBar(st) {
    const tabs = [
        { id: 'code',     name: '注册激活码', desc: '代理商凭码自助注册开户' },
        { id: 'recharge', name: '充值卡密',   desc: '代理商凭码兑换余额 / 张数额度' },
    ];
    return `<div class="tabs">${tabs.map(t => `
        <button class="${st.tab === t.id ? 'on' : ''}" data-tab="${t.id}" title="${esc(t.desc)}">${esc(t.name)}</button>`).join('')}</div>`;
}

function bindTabs(c, st) {
    c.querySelectorAll('[data-tab]').forEach(b => {
        b.addEventListener('click', () => {
            if (st.tab === b.dataset.tab) return;
            st.tab  = b.dataset.tab;
            st.page = 1;
            render();
        });
    });
}





function replayFade(c) {
    c.style.animation = 'none';

    c.offsetHeight;
    c.style.animation = '';
}

async function render() {
    const st = pageState('agent_code_list', DEFAULTS);
    if (!st.tab) st.tab = 'code';

    const c = document.getElementById('content');
    c.innerHTML = loading();

    if (st.tab === 'recharge') return renderRecharge(c, st);
    return renderCodes(c, st);
}



async function renderCodes(c, st) {
    const res = await api('agent_code_list', st);
    if (res.code !== 0) return;
    const d = res.data;

    codeLastList = d.list || [];

    const rows = d.list.map(x => `
        <tr>
            ${rowCheckBox(x.id)}
            <td class="mono"><b>${esc(x.code)}</b></td>
            <td>${esc(x.nickname || '-')}</td>
            <td>${modeTag(x.charge_mode)}</td>
            <td>
                ${specCell(x)}
                <div style="font-size:12px;color:#9ca3af;margin-top:3px">
                    兜底：${x.group_name ? esc(x.group_name) : '不换组'} · ${x.max_devices} 设备 ·
                    ${x.can_void ? '可作废' : '不可作废'} ·
                    ${x.charge_mode === 2 && x.init_balance > 0
                        ? `<span style="color:#7c3aed">赠予 ¥${esc(x.init_balance_text)}</span> · ` : ''}
                    前缀：${x.card_prefix ? `<b class="mono">${esc(x.card_prefix)}</b>` : '代理自填'}
                </div>
            </td>
            <td>${x.used_count} / ${x.max_uses}${x.left_uses > 0 ? ` <span style="color:#16a34a;font-size:12px">剩 ${x.left_uses}</span>` : ' <span style="color:#9ca3af;font-size:12px">已用完</span>'}</td>
            <td style="font-size:12px">${esc(x.expire_text)}</td>
            <td>${statusTag(x.status, { 1: ['可用', 'green'], 0: ['已停用', 'gray'] })}</td>
            <td>
                ${x.reg_count > 0
                    ? `<span data-act="detail" data-id="${x.id}" style="color:var(--primary-strong);cursor:pointer">${x.reg_count} 个</span>`
                    : '<span style="color:#9ca3af">0</span>'}
            </td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-act="detail" data-id="${x.id}">详情</button>
                <button class="btn ghost sm" data-act="copy" data-code="${esc(x.code)}">复制</button>
                <button class="btn ghost sm" data-act="edit" data-id="${x.id}">编辑</button>
                <button class="btn ghost sm" data-act="toggle" data-id="${x.id}">${x.status === 1 ? '停用' : '启用'}</button>
                <button class="btn danger sm" data-act="del" data-id="${x.id}">删除</button>
            </td>
        </tr>`).join('');

    c.innerHTML = `
    ${tabBar(st)}
    <div class="card">
        <div class="card-head">
            <h3>注册激活码</h3>
            <div class="acts">
                <span id="acBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="acBulkOp">
                        <option value="enable">批量启用</option>
                        <option value="disable">批量停用</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="acBulkRun">执行</button>
                </span>
                <div class="toolbar">
                    <input id="acKw" placeholder="搜索激活码 / 名称 / 备注" value="${esc(st.keyword)}">
                    <select id="acStatus">
                        <option value="">全部状态</option>
                        <option value="1" ${st.status === '1' ? 'selected' : ''}>可用</option>
                        <option value="0" ${st.status === '0' ? 'selected' : ''}>已停用</option>
                    </select>
                    <button class="btn" id="acSearch">搜索</button>
                </div>
                <button class="btn bulk-hide success" id="acNew">+ 生成激活码</button>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>激活码</th><th>名称</th><th>控量模式</th><th>发货规格（按卡类型）</th>
                    <th>已用次数</th><th>有效期</th><th>状态</th><th>已注册代理</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="10">${empty('<i class="bi bi-ticket-perforated"></i>', '还没有代理商激活码')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>

    <div class="card">
        <div class="card-head"><h3>说明</h3></div>
        <div class="card-body" style="font-size:13px;color:var(--text-sub);line-height:1.9">
            · 代理商打开 <code>/agent/#reg</code>，填入激活码即可自助注册（后台可在系统设置里关闭注册）<br>
            · 激活码上的<b>各类型额度单价 / 各类型激活用户组 / 设备上限 / 控量模式</b>会复制到代理档案，
              代理商登录后<b>无法自行修改</b> —— 卡密最终进入哪个用户组由这里决定<br>
            · <b>激活用户组（按卡类型）</b>：每种卡类型可单独指定用户组（如永久卡进 VIP 组、时长卡进普通组）；
              某类型留「跟随默认」时使用下方的兜底用户组<br>
            · <b>代理卡密固定前缀</b>：填了以后该码注册出的代理生成卡密时前缀被强制锁定（代理端不可改）；
              留空则代理可在 <code>/agent/</code> 自行填写前缀<br>
            · <b>配额模式</b>：按卡类型分配张数（-1 = 不限），生成时扣该类型的额度<br>
            · <b>余额模式</b>：按卡类型分别定价，生成时按该类型的单价 × 张数扣代理余额；
              可同时设置<b>注册后赠予余额</b>，代理注册到手即可发货（代理端会按余额显示每种卡还能生成多少张）<br>
            · <b>不限量</b>：只记录归属与日志；<b>可用次数</b>大于 1 时同一个码可注册多个代理（分销裂变）<br>
            · 已注册过代理的激活码不可删除（保留追溯记录），只能停用
        </div>
    </div>`;

    replayFade(c);
    bindTabs(c, st);
    bindPager(c, p => { st.page = p; render(); });

    document.getElementById('acKw').addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });
    document.getElementById('acSearch').addEventListener('click', doSearch);
    document.getElementById('acNew').addEventListener('click', () => codeForm(null, d));
    document.getElementById('acStatus').addEventListener('change', doSearch);

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = d.list.find(x => x.id === id);
        b.addEventListener('click', () => {
            const act = b.dataset.act;
            if (act === 'copy') {
                copyText(b.dataset.code).then(() => toast('已复制激活码'));
            } else if (act === 'edit') {
                codeForm(item, d);
            } else if (act === 'detail') {
                codeDetail(item);
            } else if (act === 'toggle') {
                codeToggle(item);
            } else if (act === 'del') {
                codeDel(item);
            }
        });
    });


    codeSel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        const box = document.getElementById('acBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});


    const bulkRun = document.getElementById('acBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('acBulkOp').value;
        codeDoBulk(op);
    });
}



async function codeDoBulk(op) {
    const ids = codeSel ? codeSel.ids() : [];
    if (!ids.length) return toast('请先选择激活码', 'warn');

    if (op === 'delete') {
        confirmBox('批量删除', `确定删除选中的 ${ids.length} 个激活码？已注册过代理的码会被跳过。`, async () => {
            let n = 0, fail = 0;
            for (const id of ids) {
                const res = await api('agent_code_save', { op: 'delete', id: parseInt(id, 10) });
                if (res.code === 0) n++; else fail++;
            }
            toast(`已删除 ${n} 个` + (fail ? `，${fail} 个失败（已注册过代理）` : ''), fail ? 'warn' : 'ok');
            if (codeSel) codeSel.clear();
            render();
        }, true);
        return;
    }

    const targetStatus = op === 'enable' ? 1 : 0;
    const label = op === 'enable' ? '启用' : '停用';
    const targets = codeLastList.filter(x => ids.includes(String(x.id)) && x.status !== targetStatus);

    confirmBox(`批量${label}`, `确定${label}选中的 ${ids.length} 个激活码吗？`, async () => {
        let n = 0;
        for (const item of targets) {
            const res = await api('agent_code_save', { op: 'toggle', id: item.id });
            if (res.code === 0) n++;
        }
        toast(`已${label} ${n} 个激活码`);
        if (codeSel) codeSel.clear();
        render();
    });
}

function doSearch() {
    const st = pageState('agent_code_list', DEFAULTS);
    st.keyword = document.getElementById('acKw').value.trim();
    st.status  = document.getElementById('acStatus').value;
    st.page    = 1;
    render();
}






function typeEditor(preset, cardTypes, mode, groups = []) {
    const rows = cardTypes.map(t => {
        const p = preset[String(t.type)] || { enabled: 1, quota: 0, price: '0', group_id: 0 };
        const isBal = mode === 2;
        const tgid = Number(p.group_id) || 0;
        const gOpts = groups.map(g =>
            `<option value="${g.id}" ${tgid === Number(g.id) ? 'selected' : ''}>${esc(g.name)}</option>`).join('');
        return `<tr>
            <td style="white-space:nowrap"><b>${esc(t.name)}</b></td>
            <td>
                <select data-t="${t.type}" data-k="enabled">
                    <option value="1" ${String(p.enabled) === '1' ? 'selected' : ''}>开放</option>
                    <option value="0" ${String(p.enabled) === '1' ? '' : 'selected'}>不开放</option>
                </select>
            </td>
            <td style="opacity:${isBal ? '.5' : '1'}">
                <input type="number" data-t="${t.type}" data-k="quota" value="${esc(p.quota)}" style="width:110px">
            </td>
            <td style="opacity:${isBal ? '1' : '.5'}">
                <input type="number" step="0.01" min="0" data-t="${t.type}" data-k="price" value="${esc(p.price)}" style="width:110px">
            </td>
            <td>
                <select data-t="${t.type}" data-k="group_id" style="min-width:130px">
                    <option value="0" ${tgid > 0 ? '' : 'selected'}>跟随默认</option>
                    ${gOpts}
                </select>
            </td>
        </tr>`;
    }).join('');

    return `
    <div class="hint" style="margin:4px 0 10px">
        按卡类型分别配置：<b>配额模式</b>看「额度」列（-1 = 不限）；<b>余额模式</b>看「单价」列（元/张，留 0 则用全局默认单价）。
        <b>激活用户组</b>决定该类型卡密被激活后用户进入哪个组（「跟随默认」= 用下方兜底用户组）。
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>卡类型</th><th>是否开放</th><th>额度（张，-1 不限）</th><th>单价（元/张）</th><th>激活用户组</th></tr></thead>
            <tbody>${rows}</tbody>
        </table>
    </div>`;
}



function readTypeEditor() {
    const preset = {};
    document.querySelectorAll('[data-t][data-k]').forEach(el => {
        const t = el.dataset.t;
        preset[t] = preset[t] || {};
        preset[t][el.dataset.k] = el.value;
    });
    return preset;
}



async function codeForm(c, d) {
    c = c || {};
    const isNew = !c.id;
    const cardTypes = d.card_types || [];
    const modes = d.modes || {};


    let groupOptions = '';
    let groupList = [];
    const gRes = await api('group_list', {}, true);
    if (gRes.code === 0 && Array.isArray(gRes.data.list)) {
        groupList = gRes.data.list;
        groupOptions = groupList.map(g => {
            const quota = g.daily_quota > 0 ? `每日 ${g.daily_quota} 次` : '每日不限';
            return `<option value="${g.id}" ${Number(c.group_id) === Number(g.id) ? 'selected' : ''}>${esc(g.name)}（${g.max_devices} 设备 · ${quota}）</option>`;
        }).join('');
    }
    const groupCount = groupList.length;

    const modeSel = Object.keys(modes).map(k =>
        `<option value="${k}" ${String(c.charge_mode || 1) === k ? 'selected' : ''}>${esc(modes[k])}</option>`).join('');


    const initBalVal = isNew ? '0' : (c.init_balance_text || '0');

    const preset = c.preset || {};
    const expireDays = 0;

    let swOptions = '';
    if (isNew) {
        try {
            if (!swCache) {
                const swRes = await api('software_list', {}, true);
                if (swRes.code === 0) swCache = swRes.data.options || [];
            }
            swOptions = (swCache || []).map(x => `<option value="${x.id}">${esc(x.name)}</option>`).join('');
        } catch (e) {
 }
    }

    const body = `
    ${isNew ? `
    <div class="field">
        <label>归属软件 *</label>
        <select id="acSw">${swOptions || '<option value="1">默认软件</option>'}</select>
        <div class="hint">该激活码注册的代理商只能为这个软件生成/售卖卡密</div>
    </div>
    <div class="row2">
        <div class="field"><label>生成数量 *</label>
            <input id="acCount" type="number" value="1" min="1" max="100">
            <div class="hint">一次最多 100 个；一次生成多个的码规格完全相同</div>
        </div>
        <div class="field"><label>激活码前缀</label>
            <input id="acPrefix" placeholder="默认 AGT，可留空">
        </div>
    </div>` : `
    <div class="kv" style="margin-bottom:14px">
        <span class="k">激活码</span><span class="v mono"><b>${esc(c.code)}</b></span>
        <span class="k">已注册</span><span class="v">${c.used_count} / ${c.max_uses} 次</span>
        <span class="k">状态</span><span class="v">${esc(c.status_text)}</span>
    </div>`}

    <div class="row2">
        <div class="field"><label>预设代理名称</label>
            <input id="acNick" value="${esc(c.nickname || '')}" placeholder="可选，注册时作为默认名称">
        </div>
        <div class="field"><label>${isNew ? '可用注册次数' : '可用注册次数（不小于已用）'} *</label>
            <input id="acUses" type="number" value="${isNew ? 1 : c.max_uses}" min="1" max="1000">
            <div class="hint">1 = 一次性；&gt;1 表示同一个码可注册多个代理</div>
        </div>
    </div>

    <div class="field"><label>控量模式 *</label>
        <select id="acMode">${modeSel}</select>
        <div class="hint">决定代理生成卡密时怎么扣：张数额度 / 余额计费 / 不限量</div>
    </div>

    <div class="field" id="acInitBalRow" style="${Number(c.charge_mode || 1) === 2 ? '' : 'display:none'}">
        <label>注册后赠予余额（元）</label>
        <input id="acInitBal" type="number" step="0.01" min="0" value="${esc(initBalVal)}">
        <div class="hint">
            仅<b>余额计费</b>模式生效。凭此码注册的代理，到手即有此余额，可直接发货，无需再等你充值；
            填 0 则注册后余额为 0（保持旧行为）。
        </div>
    </div>

    ${typeEditor(preset, cardTypes, Number(c.charge_mode || 1), groupList)}

    <div class="row2" style="margin-top:16px">
        <div class="field"><label>兜底用户组（卡类型未单独指定时）</label>
            <select id="acGroup">
                <option value="0" ${(c.group_id || 0) === 0 ? 'selected' : ''}>不换组（保持默认用户组）</option>
                ${groupOptions}
            </select>
            <div class="hint">
                上方发货规格里可<b>按卡类型分别</b>指定用户组；此处是兜底值（某类型选「跟随默认」时生效）。
                ${groupCount === 0 ? '<br><b style="color:#f59e0b">当前还没有用户组，请先到「运营 → 用户组」创建</b>' : ''}
            </div>
        </div>
        <div class="field"><label>卡密设备上限 *</label>
            <input id="acDev" type="number" value="${isNew ? 1 : c.max_devices}" min="1" max="99">
            <div class="hint">代理不可自行修改</div>
        </div>
    </div>

    <div class="row2">
        <div class="field"><label>允许代理作废自己的卡密</label>
            <select id="acVoid">
                <option value="1" ${c.can_void === false ? '' : 'selected'}>允许</option>
                <option value="0" ${c.can_void === false ? 'selected' : ''}>不允许</option>
            </select>
        </div>
        <div class="field"><label>激活码有效期（天）</label>
            <input id="acExpire" type="number" value="${expireDays}" min="0" max="3650" placeholder="0 = 永久有效">
            <div class="hint">${isNew ? '超过该天数未注册则失效' : '填 0 表示改为永久有效'}</div>
        </div>
    </div>

    <div class="field"><label>代理生成卡密的固定前缀</label>
        <input id="acCardPrefix" value="${esc(c.card_prefix || '')}" maxlength="8"
               placeholder="如 VIP，仅字母数字、最多 8 位；留空 = 代理可自填">
        <div class="hint">
            填写后，该激活码注册出的代理在 <code>/agent/</code> 生成卡密时，前缀被<b>强制</b>为这个值（代理端不可修改）；
            留空则保持原来的「代理自行填写前缀」。
        </div>
    </div>

    <div class="field"><label>备注</label>
        <input id="acRemark" value="${esc(c.remark || '')}" placeholder="如：给某工作室开的码">
    </div>`;

    openModal(isNew ? '生成代理商激活码' : ('编辑激活码 · ' + c.code), body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: isNew ? '生成' : '保存', cls: 'success', act: async () => {
            const payload = {
                nickname:    document.getElementById('acNick').value.trim(),
                group_id:    parseInt(document.getElementById('acGroup').value, 10) || 0,
                max_devices: parseInt(document.getElementById('acDev').value, 10) || 1,
                can_void:    parseInt(document.getElementById('acVoid').value, 10),
                charge_mode: parseInt(document.getElementById('acMode').value, 10),
                init_balance_yuan: document.getElementById('acInitBal').value || 0,
                card_prefix: document.getElementById('acCardPrefix').value.trim(),
                preset:      readTypeEditor(),
                max_uses:    parseInt(document.getElementById('acUses').value, 10) || 1,
                expire_days: parseInt(document.getElementById('acExpire').value, 10) || 0,
                remark:      document.getElementById('acRemark').value.trim(),
            };

            if (document.getElementById('acSw')) {
                payload.software_id = parseInt(document.getElementById('acSw').value, 10) || 1;
            }

            let res;
            if (isNew) {
                payload.count  = parseInt(document.getElementById('acCount').value, 10) || 1;
                payload.prefix = document.getElementById('acPrefix').value.trim();
                res = await api('agent_code_save', Object.assign({ op: 'generate' }, payload));
            } else {
                res = await api('agent_code_save', Object.assign({ op: 'update', id: c.id }, payload));
            }

            if (res.code === 0) {
                closeModal();
                render();

                if (isNew && res.data && res.data.codes) {
                    showCodes(res.data.codes);
                } else {
                    toast('保存成功');
                }
            }
        }},
    ], 'wide');


    const modeEl = document.getElementById('acMode');
    const balRow = document.getElementById('acInitBalRow');
    if (modeEl && balRow) {
        modeEl.addEventListener('change', () => {
            balRow.style.display = modeEl.value === '2' ? '' : 'none';
        });
    }
}



function showCodes(codes) {
    openModal('激活码已生成', `
        <div style="background:rgba(52,211,153,.12);color:var(--success);padding:12px 16px;border-radius:9px;margin-bottom:14px">
            成功生成 <b>${codes.length}</b> 个激活码，请复制后发放给代理商。
        </div>
        <div class="code-box">${codes.map(esc).join('\n')}</div>
        <p style="margin-top:10px;color:#6b7280;font-size:13px">
            代理商打开 <code>/agent/#reg</code> 填入激活码即可注册；已注册过代理的码不能删除，只能停用。
        </p>`,
        [{ text: '复制全部', cls: 'ghost', act: () => copyText(codes.join('\n')).then(() => toast('已复制')) },
         { text: '关闭', cls: '', act: closeModal }], 'wide');
}



function codeDetail(c) {
    const isBal = c.charge_mode === 2;
    const typeRows = (c.types || []).map(t => {
        const spec = t.enabled
            ? (isBal ? ('¥ ' + t.price_text + ' / 张') : t.quota_text)
            : '<span style="color:#9ca3af">未开放</span>';
        const grp = (t.enabled && t.group_name)
            ? esc(t.group_name)
            : '<span style="color:#9ca3af">跟随兜底</span>';
        return `<tr>
            <td>${esc(t.name)}</td>
            <td>${t.enabled ? tag('开放', 'green') : tag('不开放', 'gray')}</td>
            <td>${spec}</td>
            <td>${grp}</td>
        </tr>`;
    }).join('');

    const agentRows = (c.agents || []).map(a => `
        <tr>
            <td>#${a.id}</td>
            <td class="mono">${esc(a.username)}</td>
            <td>${esc(a.nickname || '-')}</td>
            <td>${statusTag(a.status, { 1: ['正常', 'green'], 0: ['已禁用', 'gray'] })}</td>
            <td class="mono" style="font-size:12px">${esc(a.created_at_text)}</td>
        </tr>`).join('');

    openModal('激活码详情 · ' + c.code, `
    <div class="kv">
        <span class="k">激活码</span><span class="v mono"><b>${esc(c.code)}</b></span>
        <span class="k">预设名称</span><span class="v">${esc(c.nickname || '-')}</span>
        <span class="k">控量模式</span><span class="v">${modeTag(c.charge_mode)}</span>
        ${isBal ? `<span class="k">注册赠予余额</span><span class="v">${c.init_balance > 0 ? '¥ ' + esc(c.init_balance_text) : '无'}</span>` : ''}
        <span class="k">兜底用户组</span><span class="v">${c.group_name ? esc(c.group_name) : '不换组'}</span>
        <span class="k">设备上限</span><span class="v">${c.max_devices} 台</span>
        <span class="k">代理卡密固定前缀</span><span class="v">${c.card_prefix ? `<b class="mono">${esc(c.card_prefix)}</b>` : '不限制（代理自填）'}</span>
        <span class="k">允许作废</span><span class="v">${c.can_void ? '是' : '否'}</span>
        <span class="k">注册次数</span><span class="v">已用 ${c.used_count} / 共 ${c.max_uses}（剩 ${c.left_uses}）</span>
        <span class="k">有效期</span><span class="v">${esc(c.expire_text)}</span>
        <span class="k">状态</span><span class="v">${statusTag(c.status, { 1: ['可用', 'green'], 0: ['已停用', 'gray'] })}</span>
        <span class="k">创建时间</span><span class="v">${esc(c.created_at_text)}</span>
        <span class="k">备注</span><span class="v">${esc(c.remark || '-')}</span>
    </div>

    <h4 style="margin:18px 0 10px;font-size:14px">按卡类型的发货规格</h4>
    <div class="table-wrap"><table>
        <thead><tr><th>卡类型</th><th>是否开放</th><th>${isBal ? '单价' : '额度'}</th><th>激活用户组</th></tr></thead>
        <tbody>${typeRows}</tbody>
    </table></div>

    <h4 style="margin:18px 0 10px;font-size:14px">已注册代理（${c.reg_count}）</h4>
    ${agentRows ? `<div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>代理账号</th><th>名称</th><th>状态</th><th>注册时间</th></tr></thead>
        <tbody>${agentRows}</tbody></table></div>` : '<p style="color:#9ca3af;font-size:13px">还没有代理用这个码注册</p>'}`,
    [{ text: '复制激活码', cls: 'ghost', act: () => copyText(c.code).then(() => toast('已复制')) },
     { text: '关闭', cls: '', act: closeModal }], 'wide');
}

function codeToggle(c) {
    const off = c.status === 1;
    confirmBox(off ? '停用激活码' : '启用激活码',
        off ? '停用后该激活码无法再注册新代理（已注册的代理不受影响）。确定继续？'
            : '确定重新启用该激活码？',
        async () => {
            const res = await api('agent_code_save', { op: 'toggle', id: c.id });
            if (res.code === 0) { toast(res.msg); render(); }
        }, off);
}

function codeDel(c) {
    confirmBox('删除激活码',
        c.used_count > 0
            ? '该激活码已被注册使用，系统会拒绝删除；如需停止使用请点「停用」。'
            : '确定删除该激活码？删除后不可恢复。',
        async () => {
            const res = await api('agent_code_save', { op: 'delete', id: c.id });
            if (res.code === 0) { toast('已删除'); render(); }
        }, true);
}






async function renderRecharge(c, st) {
    const res = await api('agent_recharge_list', st);
    if (res.code !== 0) return;
    const d = res.data;

    rcLastList = d.list || [];

    const rows = d.list.map(x => `
        <tr>
            ${rowCheckBox(x.id)}
            <td class="mono"><b>${esc(x.code)}</b></td>
            <td>${x.kind === 1 ? tag('余额充值', 'purple') : tag('张数额度', 'blue')}</td>
            <td><b>${esc(x.spec_text)}</b></td>
            <td>${x.used_count} / ${x.max_uses}${x.left_uses > 0
                ? ` <span style="color:#16a34a;font-size:12px">剩 ${x.left_uses}</span>`
                : ' <span style="color:#9ca3af;font-size:12px">已用完</span>'}</td>
            <td style="font-size:12px">${esc(x.expire_text)}</td>
            <td>${statusTag(x.status, { 1: ['可用', 'green'], 0: ['已停用', 'gray'] })}</td>
            <td style="font-size:12px">${x.last_agent_name ? esc(x.last_agent_name) : '-'}
                ${x.last_used_at_text ? `<div style="color:#9ca3af">${esc(x.last_used_at_text)}</div>` : ''}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-act="copy" data-code="${esc(x.code)}">复制</button>
                <button class="btn ghost sm" data-act="toggle" data-id="${x.id}">${x.status === 1 ? '停用' : '启用'}</button>
                <button class="btn danger sm" data-act="del" data-id="${x.id}">删除</button>
            </td>
        </tr>`).join('');

    c.innerHTML = `
    ${tabBar(st)}
    <div class="card">
        <div class="card-head">
            <h3>充值卡密</h3>
            <div class="acts">
                <span id="rcBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="rcBulkOp">
                        <option value="enable">批量启用</option>
                        <option value="disable">批量停用</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="rcBulkRun">执行</button>
                </span>
                <div class="toolbar">
                    <input id="rcKw" placeholder="搜索卡密 / 备注" value="${esc(st.keyword || '')}">
                    <select id="rcKind">
                        <option value="">全部类型</option>
                        <option value="1" ${st.kind === '1' ? 'selected' : ''}>余额充值</option>
                        <option value="2" ${st.kind === '2' ? 'selected' : ''}>张数额度</option>
                    </select>
                    <select id="rcStatus">
                        <option value="">全部状态</option>
                        <option value="1" ${st.status === '1' ? 'selected' : ''}>可用</option>
                        <option value="0" ${st.status === '0' ? 'selected' : ''}>已停用</option>
                    </select>
                    <button class="btn" id="rcSearch">搜索</button>
                </div>
                <button class="btn bulk-hide success" id="rcNew">+ 生成充值卡密</button>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>充值卡密</th><th>类型</th><th>面值 / 规格</th><th>已兑换</th>
                    <th>有效期</th><th>状态</th><th>最近兑换</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="9">${empty('<i class="bi bi-cash-coin"></i>', '还没有充值卡密')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>

    <div class="card">
        <div class="card-head"><h3>说明</h3></div>
        <div class="card-body" style="font-size:13px;color:var(--text-sub);line-height:1.9">
            · 充值卡密与「注册激活码」分工不同：<b>激活码用于开户</b>，充值卡密用于<b>给已有代理续费 / 加量</b><br>
            · <b>余额充值卡</b>：兑换后给代理余额加钱（仅「余额计费」模式的代理有意义，生成卡密时按余额扣款）<br>
            · <b>张数额度卡</b>：生成时可为<b>多种卡类型分别填写张数</b>（0 = 不充值，-1 = 该类型设为「不限量」），
              代理商兑换一次即同时到账；仅「张数额度」模式的代理有意义<br>
            · 代理商登录 <code>/agent/</code> → <b>充值卡密</b> → 输入卡密即可自助兑换，无需你在线操作<br>
            · 一张卡密可设置多次兑换（如当作通用充值码）；已兑换过的卡密不可删除，只能停用
        </div>
    </div>`;

    replayFade(c);
    bindTabs(c, st);
    bindPager(c, p => { st.page = p; render(); });

    document.getElementById('rcKw').addEventListener('keydown', e => { if (e.key === 'Enter') rcSearch(); });
    document.getElementById('rcSearch').addEventListener('click', rcSearch);
    document.getElementById('rcNew').addEventListener('click', () => rechargeForm(d));
    document.getElementById('rcKind').addEventListener('change', rcSearch);
    document.getElementById('rcStatus').addEventListener('change', rcSearch);

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = d.list.find(x => x.id === id);
        b.addEventListener('click', () => {
            const act = b.dataset.act;
            if (act === 'copy') {
                copyText(b.dataset.code).then(() => toast('已复制充值卡密'));
            } else if (act === 'toggle') {
                rcToggle(item);
            } else if (act === 'del') {
                rcDel(item);
            }
        });
    });


    rcSel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        const box = document.getElementById('rcBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});


    const bulkRun = document.getElementById('rcBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('rcBulkOp').value;
        rcDoBulk(op);
    });
}



async function rcDoBulk(op) {
    const ids = rcSel ? rcSel.ids() : [];
    if (!ids.length) return toast('请先选择充值卡密', 'warn');

    if (op === 'delete') {
        confirmBox('批量删除', `确定删除选中的 ${ids.length} 张充值卡密？已兑换过的会被跳过。`, async () => {
            let n = 0, fail = 0;
            for (const id of ids) {
                const res = await api('agent_recharge_save', { op: 'delete', id: parseInt(id, 10) });
                if (res.code === 0) n++; else fail++;
            }
            toast(`已删除 ${n} 张` + (fail ? `，${fail} 张失败（已兑换过）` : ''), fail ? 'warn' : 'ok');
            if (rcSel) rcSel.clear();
            render();
        }, true);
        return;
    }

    const targetStatus = op === 'enable' ? 1 : 0;
    const label = op === 'enable' ? '启用' : '停用';
    const targets = rcLastList.filter(x => ids.includes(String(x.id)) && x.status !== targetStatus);

    confirmBox(`批量${label}`, `确定${label}选中的 ${ids.length} 张充值卡密吗？`, async () => {
        let n = 0;
        for (const item of targets) {
            const res = await api('agent_recharge_save', { op: 'toggle', id: item.id });
            if (res.code === 0) n++;
        }
        toast(`已${label} ${n} 张充值卡密`);
        if (rcSel) rcSel.clear();
        render();
    });
}

function rcSearch() {
    const st = pageState('agent_code_list', DEFAULTS);
    st.keyword = document.getElementById('rcKw').value.trim();
    st.kind    = document.getElementById('rcKind').value;
    st.status  = document.getElementById('rcStatus').value;
    st.page    = 1;
    render();
}



function rechargeForm(d) {
    const kinds     = d.kinds || { 1: '余额充值', 2: '张数额度' };
    const cardTypes = d.card_types || [];

    const kindOpts = Object.keys(kinds).map(k => `<option value="${k}">${esc(kinds[k])}</option>`).join('');


    const quotaRows = cardTypes.map(t => `
        <tr>
            <td style="white-space:nowrap"><b>${esc(t.name)}</b></td>
            <td><input type="number" data-qtype="${t.type}" class="rcQuotaInput" value="0" min="-1" style="width:130px"></td>
        </tr>`).join('');

    const body = `
    <div class="row2">
        <div class="field"><label>生成数量 *</label>
            <input id="rcCount" type="number" value="1" min="1" max="200">
            <div class="hint">一次最多 200 张；同批规格完全相同</div>
        </div>
        <div class="field"><label>卡密前缀</label>
            <input id="rcPrefix" placeholder="默认 RCG，可留空">
        </div>
    </div>

    <div class="field"><label>卡密类型 *</label>
        <select id="rcKindSel">${kindOpts}</select>
        <div class="hint">余额充值卡给代理加余额；张数额度卡给代理加卡类型的可生成张数（可一次加多种）</div>
    </div>

    <div class="field" id="rcAmountRow">
        <label>充值金额（元）*</label>
        <input id="rcAmount" type="number" step="0.01" min="0" placeholder="如 100 = 兑换后余额 +100.00 元">
        <div class="hint">仅对「余额计费」模式的代理有意义（该模式生成卡密按余额扣款）</div>
    </div>

    <div id="rcQuotaRow" style="display:none">
        <div class="hint" style="margin:4px 0 10px">
            为每种卡类型填写本次要增加的张数：填 <b>0</b> 表示该类型不充值；填 <b>-1</b> 表示该类型设为「不限量」。
            <b>至少要为一种类型填写张数</b>，代理商兑换一次即可让所有填了张数的类型同时到账。
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>卡类型</th><th>增加张数（0=不充值，-1=不限量）</th></tr></thead>
                <tbody>${quotaRows || '<tr><td colspan="2">暂无可充值的卡类型</td></tr>'}</tbody>
            </table>
        </div>
        <div class="toolbar" style="margin-top:8px">
            <button class="btn ghost sm" id="rcQuotaClear" type="button">全部清零</button>
        </div>
    </div>

    <div class="row2">
        <div class="field"><label>可兑换次数 *</label>
            <input id="rcUses" type="number" value="1" min="1" max="1000">
            <div class="hint">1 = 一次性；&gt;1 表示同一张卡密可被兑换多次</div>
        </div>
        <div class="field"><label>有效期（天）</label>
            <input id="rcExpire" type="number" value="0" min="0" max="3650" placeholder="0 = 永久有效">
            <div class="hint">超过该天数未兑换则失效</div>
        </div>
    </div>

    <div class="field"><label>备注</label>
        <input id="rcRemark" placeholder="如：双十一活动充值码">
    </div>`;

    openModal('生成充值卡密', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '生成', cls: 'success', act: async () => {
            const kind = parseInt(document.getElementById('rcKindSel').value, 10);
            const payload = {
                op:          'generate',
                count:       parseInt(document.getElementById('rcCount').value, 10) || 1,
                prefix:      document.getElementById('rcPrefix').value.trim(),
                kind:        kind,
                max_uses:    parseInt(document.getElementById('rcUses').value, 10) || 1,
                expire_days: parseInt(document.getElementById('rcExpire').value, 10) || 0,
                remark:      document.getElementById('rcRemark').value.trim(),
            };
            if (kind === 1) {
                payload.amount_yuan = document.getElementById('rcAmount').value || 0;
            } else {

                const map = {};
                document.querySelectorAll('.rcQuotaInput').forEach(el => {
                    const v = parseInt(el.value, 10) || 0;
                    if (v !== 0) map[el.dataset.qtype] = v < -1 ? -1 : v;
                });
                if (!Object.keys(map).length) {
                    return toast('请至少为一种卡类型填写增加张数', 'warn');
                }
                payload.quota_map = map;
            }

            const res = await api('agent_recharge_save', payload);
            if (res.code === 0) {
                closeModal();
                render();
                if (res.data && res.data.codes) showRechargeCodes(res.data.codes);
                else toast('生成成功');
            }
        }},
    ], 'wide');


    const kindEl = document.getElementById('rcKindSel');
    const amtRow = document.getElementById('rcAmountRow');
    const qRow   = document.getElementById('rcQuotaRow');
    const sync   = () => {
        const bal = kindEl.value === '1';
        amtRow.style.display = bal ? '' : 'none';
        qRow.style.display   = bal ? 'none' : '';
    };
    kindEl.addEventListener('change', sync);
    sync();

    const clearBtn = document.getElementById('rcQuotaClear');
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            document.querySelectorAll('.rcQuotaInput').forEach(el => { el.value = '0'; });
        });
    }
}



function showRechargeCodes(codes) {
    openModal('充值卡密已生成', `
        <div style="background:rgba(52,211,153,.12);color:var(--success);padding:12px 16px;border-radius:9px;margin-bottom:14px">
            成功生成 <b>${codes.length}</b> 张充值卡密，请复制后发给代理商。
        </div>
        <div class="code-box">${codes.map(esc).join('\n')}</div>
        <p style="margin-top:10px;color:#6b7280;font-size:13px">
            代理商登录 <code>/agent/</code> → <b>充值卡密</b> 输入卡密即可兑换。
        </p>`,
        [{ text: '复制全部', cls: 'ghost', act: () => copyText(codes.join('\n')).then(() => toast('已复制')) },
         { text: '关闭', cls: '', act: closeModal }], 'wide');
}



function rcToggle(x) {
    const off = x.status === 1;
    confirmBox(off ? '停用充值卡密' : '启用充值卡密',
        off ? '停用后代理商将无法再兑换该卡密。确定继续？' : '确定重新启用该充值卡密？',
        async () => {
            const res = await api('agent_recharge_save', { op: 'toggle', id: x.id });
            if (res.code === 0) { toast(res.msg); render(); }
        }, off);
}

function rcDel(x) {
    confirmBox('删除充值卡密',
        x.used_count > 0
            ? '该卡密已被兑换过，系统会拒绝删除；如需停用请点「停用」。'
            : '确定删除该充值卡密？删除后不可恢复。',
        async () => {
            const res = await api('agent_recharge_save', { op: 'delete', id: x.id });
            if (res.code === 0) { toast('已删除'); render(); }
        }, true);
}
