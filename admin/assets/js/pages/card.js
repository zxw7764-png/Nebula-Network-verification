import { api, apiDownload } from '../core/api.js';
import { register } from '../core/router.js';
import { pageState, resetPageState } from '../core/state.js';
import { loading, empty, esc, tag, statusTag, downloadBlob, copyText } from '../core/util.js';
import {
    openModal, closeModal, confirmBox, confirmPassword, toast,
    pager, bindPager, createSelection, checkAllBox, rowCheckBox,
} from '../core/ui.js';

const DEFAULTS = { page: 1, size: 20, keyword: '', status: '', type: 0, batch_id: 0, agent_id: '' };

let sel = null;


let agentOptions = null;



async function loadAgentOptions() {
    if (agentOptions !== null) { return agentOptions; }
    agentOptions = [];
    try {
        const r = await api('agent_list', { all: 1 }, true);
        if (r.code === 0 && r.data && Array.isArray(r.data.options)) {
            agentOptions = r.data.options;
        }
    } catch (e) {
 }
    return agentOptions;
}

register('card_list', render);

let swCache = null;

async function render() {
    const st = pageState('card_list', DEFAULTS);
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const [, res] = await Promise.all([loadAgentOptions(), api('card_list', st)]);
    if (res.code !== 0) return;
    const d = res.data;
    const isExtView = st.agent_id === 'ext';

    const rows = d.list.map(x => `
        <tr>
            ${x.is_ext ? '<td></td>' : rowCheckBox(x.id)}
            <td>${x.id}</td>
            <td class="mono"><b>${esc(x.code)}</b></td>
            ${x.is_ext ? '<td></td>' : `<td>${tag(esc(x.software_name || ('软件#' + x.software_id)), 'purple')}</td>`}
            <td>${tag(x.type_text, 'blue')}</td>
            <td>${esc(x.duration_text)}</td>
            <td>${x.max_devices || '-'}</td>
            <td>${x.group_name ? tag(x.group_name, 'purple') : '-'}</td>
            <td>${x.is_ext ? tag('外部导入', 'yellow') : (x.agent_id > 0 ? tag(x.agent_name || ('代理#' + x.agent_id), 'yellow') : tag('官方', 'gray'))}</td>
            <td>${x.is_ext ? (x.status === 0 ? tag('未售', 'green') : tag('已售', 'gray'))
                : statusTag(x.status, { 0: ['未使用', 'green'], 1: ['已使用', 'gray'], 2: ['已作废', 'red'], 3: ['已售出', 'yellow'] })}</td>
            <td>${esc(x.used_text || '-')}</td>
            <td class="mono" style="font-size:12px">${esc(x.used_at)}</td>
            <td>${esc(x.expire_text)}</td>
            <td style="white-space:nowrap">${x.is_ext
                ? (x.status === 0 ? `<button class="btn danger sm" data-act="del" data-id="${x.id}">删除</button>` : '<span style="color:#94a3b8">—</span>')
                : `
                <button class="btn ghost sm" data-act="detail" data-id="${x.id}">详情</button>
                ${x.status === 0 ? `<button class="btn ghost sm" data-act="edit" data-id="${x.id}">编辑</button>` : ''}
                ${x.status === 0 ? `<button class="btn danger sm" data-act="void" data-id="${x.id}">作废</button>` : ''}`}
            </td>
        </tr>`).join('');

    const batchTip = st.batch_id
        ? `<div class="hint" style="padding:8px 20px;background:var(--primary-bg);color:var(--primary-strong)">
             当前仅显示批次 #${st.batch_id} 的卡密
             <button class="btn ghost sm" id="kClearBatch" style="margin-left:8px">显示全部</button>
           </div>` : '';

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>卡密列表</h3>
            <div class="toolbar">
                <input id="kKw" placeholder="搜索卡密" value="${esc(st.keyword)}">
                <select id="kStatus">
                    <option value="">全部状态</option>
                    <option value="0" ${st.status === '0' ? 'selected' : ''}>未使用</option>
                    <option value="1" ${st.status === '1' ? 'selected' : ''}>已使用</option>
                    <option value="2" ${st.status === '2' ? 'selected' : ''}>已作废</option>
                    <option value="3" ${st.status === '3' ? 'selected' : ''}>已售出</option>
                </select>
                <select id="kType">
                    <option value="0">全部类型</option>
                    <option value="1" ${st.type == 1 ? 'selected' : ''}>时长卡</option>
                    <option value="2" ${st.type == 2 ? 'selected' : ''}>点数卡</option>
                    <option value="3" ${st.type == 3 ? 'selected' : ''}>次数卡</option>
                    <option value="4" ${st.type == 4 ? 'selected' : ''}>永久卡</option>
                </select>
                <select id="kAgent">
                    <option value="">全部来源</option>
                    <option value="0" ${String(st.agent_id) === '0' ? 'selected' : ''}>官方直发</option>
                    <option value="ext" ${st.agent_id === 'ext' ? 'selected' : ''}>外部导入（发卡商品）</option>
                    ${(agentOptions || []).map(a =>
                        `<option value="${a.id}" ${String(st.agent_id) === String(a.id) ? 'selected' : ''}>代理：${esc(a.name)}</option>`).join('')}
                </select>
                <button class="btn" id="kSearch">搜索</button>
                <span id="kBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="kBulkOp">
                        ${isExtView ? '' : '<option value="void">批量作废</option><option value="copy">复制卡密</option><option value="extend">批量延长有效期</option>'}
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="kBulkRun">执行</button>
                </span>
                <button class="btn ghost bulk-hide" id="kExtCards">外部卡密</button>
                <button class="btn ghost bulk-hide" id="kExport">导出</button>
                <button class="btn success bulk-hide" id="kGen">+ 生成卡密</button>
            </div>
        </div>
        ${batchTip}

        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>卡密</th><th>软件</th><th>类型</th><th>时长/点数</th><th>设备</th><th>激活分组</th>
                    <th>来源</th><th>状态</th><th>使用者</th><th>使用时间</th><th>卡密有效期</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="14">${empty('<i class="bi bi-credit-card"></i>', '没有卡密')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>`;

    bindPager(c, p => { st.page = p; render(); });

    document.getElementById('kKw').addEventListener('keydown', e => {
        if (e.key === 'Enter') doSearch();
    });
    document.getElementById('kSearch').addEventListener('click', doSearch);
    document.getElementById('kExtCards').addEventListener('click', () => extCardsView());
    document.getElementById('kExport').addEventListener('click', () => cardExport());
    document.getElementById('kGen').addEventListener('click', () => cardGenerate());
    const cb = document.getElementById('kClearBatch');
    if (cb) cb.addEventListener('click', () => { st.batch_id = 0; render(); });

    c.querySelectorAll('[data-act]').forEach(b => {
        b.addEventListener('click', () => {
            const id = parseInt(b.dataset.id, 10);
            if (b.dataset.act === 'detail') cardDetail(id);
            else if (b.dataset.act === 'edit') cardEdit(id);
            else if (b.dataset.act === 'void') voidCard(id);
            else if (b.dataset.act === 'del') extCardDelete([id]);
        });
    });

    sel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        const box = document.getElementById('kBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }

        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});


    const bulkRun = document.getElementById('kBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('kBulkOp').value;
        doBulk(op);
    });
}

function doSearch() {
    const st = pageState('card_list', DEFAULTS);
    st.keyword = document.getElementById('kKw').value.trim();
    st.status = document.getElementById('kStatus').value;
    st.type = parseInt(document.getElementById('kType').value, 10);
    st.agent_id = document.getElementById('kAgent').value;
    st.page = 1;
    render();
}



async function doBulk(op) {
    const ids = sel ? sel.ids() : [];
    if (!ids.length) return toast('请先选择卡密', 'warn');

    if (op === 'copy') {
        const st = pageState('card_list', DEFAULTS);
        const res = await api('card_list', Object.assign({}, st, { page: 1, size: 1000, ids_only: 1 }), true);

        const codes = [...document.querySelectorAll('tbody tr')]
            .filter(tr => {
                const cb = tr.querySelector('[data-row-check]');
                return cb && cb.checked;
            })
            .map(tr => {
                const td = tr.querySelectorAll('td')[2];
                return td ? td.textContent.trim() : '';
            })
            .filter(Boolean);
        if (!codes.length) return toast('未取到卡密', 'warn');
        copyText(codes.join('\n')).then(() => toast(`已复制 ${codes.length} 张卡密`));
        return;
    }

    if (op === 'extend') {
        openModal('批量延长卡密有效期', `
            <div class="field">
                <label>延长天数</label>
                <input id="bulkDays" type="number" value="30" min="1">
                <div class="hint">仅对「未使用」的卡密生效，延长卡密自身的有效期</div>
            </div>`,
            [{ text: '取消', cls: 'ghost', act: closeModal },
             { text: '确定', cls: '', act: async () => {
                 const n = parseInt(document.getElementById('bulkDays').value, 10) || 0;
                 if (n <= 0) return toast('请输入天数', 'warn');
                 const res = await api('card_batch_op', { op: 'extend', ids, days: n });
                 if (res.code === 0) { toast(res.msg); closeModal(); if (sel) sel.clear(); render(); }
             }}]);
        return;
    }

    if (op === 'void') {
        confirmBox('批量作废', `将作废已选的 ${ids.length} 张「未使用」卡密，确定继续？`, async () => {
            const res = await api('card_batch_op', { op: 'void', ids });
            if (res.code === 0) { toast(res.msg); if (sel) sel.clear(); render(); }
        }, true);
        return;
    }

    if (op === 'delete') {
        const st = pageState('card_list', DEFAULTS);
        if (st.agent_id === 'ext') {
            extCardDelete(ids);
        } else {
            deleteCards(ids);
        }
    }
}



function deleteCards(ids) {
    openModal('批量删除卡密', `
        <p style="color:var(--danger);margin-bottom:14px">
            <b>此操作不可恢复！</b>卡密及其使用日志将被永久删除。
        </p>
        <div class="field">
            <label>删除范围</label>
            <select id="kcScope">
                <option value="all">全部（含已使用，会一并删除使用记录）</option>
                <option value="unused">仅未使用</option>
                <option value="void">仅已作废</option>
            </select>
            <div class="hint">已使用的卡密删除后，对应用户的会员权益不会回滚。</div>
        </div>
        <div class="field">
            <label>管理密码 *</label>
            <input id="kcPass" type="password" autocomplete="off" placeholder="请输入管理密码">
        </div>
        <div class="hint">将对已选的 ${ids.length} 张卡密生效</div>`,
        [{ text: '取消', cls: 'ghost', act: closeModal },
         { text: '确认删除', cls: 'danger', act: async () => {
             const pass = document.getElementById('kcPass').value;
             if (!pass) return toast('请输入管理密码', 'warn');
             const scope = document.getElementById('kcScope').value;
             const res = await api('card_batch_op', { op: 'delete', ids, scope, password: pass });
             if (res.code === 0) { toast(res.msg); closeModal(); if (sel) sel.clear(); render(); }
         }}]);
    setTimeout(() => { const i = document.getElementById('kcPass'); if (i) i.focus(); }, 50);
}



function extCardDelete(ids) {
    openModal('删除外部卡密', `
        <p style="color:var(--danger);margin-bottom:14px">
            <b>此操作不可恢复！</b>删除后商品库存实时减少。
        </p>
        <div class="field">
            <label>删除范围</label>
            <select id="ecScope">
                <option value="unused">仅未售（推荐）</option>
                <option value="all">全部（含已售，已售内容在订单中有快照，不影响订单）</option>
            </select>
        </div>
        <div class="field">
            <label>管理密码 *</label>
            <input id="ecPass" type="password" autocomplete="off" placeholder="请输入管理密码">
        </div>
        <div class="hint">将对已选的 ${ids.length} 条外部卡密生效</div>`,
        [{ text: '取消', cls: 'ghost', act: closeModal },
         { text: '确认删除', cls: 'danger', act: async () => {
             const pass = document.getElementById('ecPass').value;
             if (!pass) return toast('请输入管理密码', 'warn');
             const scope = document.getElementById('ecScope').value;
             const res = await api('shop_card_delete', { ids, scope, password: pass });
             if (res.code === 0) { toast(res.msg); closeModal(); if (sel) sel.clear(); render(); }
         }}]);
    setTimeout(() => { const i = document.getElementById('ecPass'); if (i) i.focus(); }, 50);
}



async function extCardsView() {
    const res = await api('shop_cards_list', {}, true);
    if (res.code !== 0) return;
    const plans = res.data.list || [];
    if (!plans.length) { toast('暂无外部卡密商品（在发卡商品页把卡密来源设为外部卡密）', 'warn'); return; }

    const body = `
    <div class="field"><label>外部卡密商品</label>
        <select id="ecPlan">${plans.map(p =>
            `<option value="${p.plan_id}">${esc(p.plan_name)}（未售 ${p.unsold} / 已售 ${p.sold}）</option>`).join('')}
        </select>
        <div class="hint" id="ecStat"></div>
    </div>
    <div class="table-wrap" id="ecBox" style="max-height:420px;overflow:auto"></div>`;

    openModal('外部卡密池（发卡商品导入）', body, [
        { text: '关闭', cls: 'ghost', act: closeModal },
        { text: '复制未售', cls: '', act: async () => {
            const pid = document.getElementById('ecPlan').value;
            const r = await api('shop_cards_list', { plan_id: pid, unsold_only: 1 }, true);
            if (r.code !== 0) return;
            const codes = (r.data.list || []).map(x => x.content);
            if (!codes.length) return toast('该商品没有未售卡密', 'warn');
            copyText(codes.join('\n')).then(() => toast(`已复制 ${codes.length} 条未售卡密`));
        }},
    ], 'wide');

    const load = async () => {
        const pid = document.getElementById('ecPlan').value;
        const r = await api('shop_cards_list', { plan_id: pid }, true);
        if (r.code !== 0) return;
        const stat = document.getElementById('ecStat');
        const box = document.getElementById('ecBox');
        if (!stat || !box) return;
        stat.textContent = `未售 ${r.data.unsold} 条 · 已售 ${r.data.sold} 条（明细最多显示最新 1000 条）`;
        const rows = (r.data.list || []).map(x => `
            <tr>
                <td>${x.id}</td>
                <td class="mono">${esc(x.content)}</td>
                <td>${x.status === 0 ? tag('未售', 'green') : tag('已售', 'gray')}</td>
                <td>${x.status === 1 ? '订单#' + x.order_id : '-'}</td>
                <td class="mono" style="font-size:12px">${esc(x.sold_at_text || '-')}</td>
            </tr>`).join('');
        box.innerHTML = `<table><thead><tr>
            <th>ID</th><th>卡密内容</th><th>状态</th><th>订单</th><th>售出时间</th>
        </tr></thead><tbody>${rows || `<tr><td colspan="5">${empty('<i class="bi bi-inbox"></i>', '池中还没有卡密，去「发卡商品」页导入')}</td></tr>`}</tbody></table>`;
    };
    document.getElementById('ecPlan').addEventListener('change', load);
    load();
}



async function cardGenerate() {

    let groupOptions = '';
    const gRes = await api('group_list', {}, true);
    if (gRes.code === 0 && Array.isArray(gRes.data.list)) {
        groupOptions = gRes.data.list
            .map(g => `<option value="${g.id}">${esc(g.name)}（${g.max_devices} 设备）</option>`)
            .join('');
    }


    let goodsList = [];
    const gkRes = await api('shop_goods_options', {}, true);
    if (gkRes.code === 0 && Array.isArray(gkRes.data.list)) goodsList = gkRes.data.list;

    let swOptions = '';
    try {
        if (!swCache) {
            const swRes = await api('software_list', {}, true);
            if (swRes.code === 0) swCache = swRes.data.options || [];
        }
        swOptions = (swCache || []).map(x => `<option value="${x.id}">${esc(x.name)}</option>`).join('');
    } catch (e) {
 }

    const body = `
    <div class="field">
        <label>所属软件 *</label>
        <select id="gSw">${swOptions || '<option value="1">默认软件</option>'}</select>
        <div class="hint">生成的卡密只能在该软件中使用</div>
    </div>
    <div class="field">
        <label>关联发卡商品（可选）</label>
        <select id="gGoods">
            <option value="0">不关联（手动填写规格）</option>
        </select>
        <div class="hint" id="gGoodsHint">选择上架商品后自动带出挂卡规格</div>
    </div>
    <div class="field" id="gSpecField" style="display:none">
        <label>选择规格 *</label>
        <select id="gSpec"></select>
        <div class="hint" id="gSpecHint">商品有多规格，选择要生成的卡类型规格</div>
    </div>
    <div class="row2">
        <div class="field"><label>生成数量 *</label><input id="gCount" type="number" value="10" min="1" max="10000"></div>
        <div class="field" id="gSpecSummary" style="display:none">
            <label>卡密规格</label>
            <div class="hint" id="gSpecSummaryText" style="font-size:14px;color:var(--text)"></div>
        </div>
    </div>
    <div id="gSpecDetail">
    <div class="row2">
        <div class="field"><label>卡密类型 *</label>
            <select id="gType">
                <option value="1">时长卡（按时间）</option>
                <option value="2">点数卡（按点数）</option>
                <option value="3">次数卡（按次数）</option>
                <option value="4">永久卡</option>
            </select>
        </div>
        <div class="field">
            <label>时长 / 数值 *</label>
            <div style="display:flex;gap:8px">
                <input id="gDur" type="number" value="30" style="flex:1;min-width:0">
                <select id="gDurUnit" style="width:86px;flex:none">
                    <option value="31536000">年</option>
                    <option value="2592000">月</option>
                    <option value="604800">星期</option>
                    <option value="86400">天</option>
                    <option value="3600">小时</option>
                    <option value="60">分钟</option>
                </select>
            </div>
            <div class="hint" id="gDurHint">时长卡填时长并选择单位，其他填数值</div>
        </div>
    </div>
    <div class="row2">
        <div class="field"><label>最大设备数</label><input id="gDev" type="number" value="1" min="1" max="99"></div>
        <div class="field">
            <label>激活后进入用户组</label>
            <select id="gGroup">
                <option value="0">不换组（保持注册时的默认用户组）</option>
                ${groupOptions}
            </select>
            <div class="hint">用户激活该卡密后，账号将自动切换到所选用户组</div>
        </div>
    </div>
    </div>
    <div class="field">
        <label>卡密格式 *</label>
        <select id="gFmt">
            <option value="XXXX-XXXX-XXXX-XXXX">经典 4×4（XXXX-XXXX-XXXX-XXXX）</option>
            <option value="XXXX-XXXX-XXXX-XXXX-XXXX">长 5×4（20 位）</option>
            <option value="XXXX-XXXX-XXXX">短 3×4（12 位）</option>
            <option value="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX">超长 6×4（24 位）</option>
            <option value="DDDD-DDDD-DDDD-DDDD">16 位纯数字（DDDD-DDDD-DDDD-DDDD）</option>
            <option value="__custom__">自定义模板…</option>
        </select>
        <div class="hint">示例：<b id="gFmtSample" class="mono"></b>　·　X=字母数字（不含 0/O/1/I），D=纯数字，其余字符（如 -）原样</div>
    </div>
    <div class="field" id="gFmtCustom" style="display:none">
        <label>自定义模板</label>
        <input id="gFmtTpl" value="XXXX-XXXX-XXXX-XXXX" maxlength="48" placeholder="仅支持 X / D 与 - . _ 分隔符">
        <div class="hint">例：VIP-XXXX-XXXX-XXXX、XXXX.XXXX.XXXX、DDDDDDDD</div>
    </div>
    <div class="row2">
        <div class="field"><label>卡密前缀</label><input id="gPrefix" placeholder="如 VIP（仅字母数字，可选）"></div>
        <div class="field">
            <label>卡密自身有效期(天)</label>
            <input id="gExpire" type="number" value="0" placeholder="0=永久有效">
        </div>
    </div>
    <div class="field"><label>批次备注</label><input id="gName" placeholder="如：国庆活动批次"></div>
    <div class="field"><label>统一备注</label><input id="gRemark"></div>`;

    openModal('批量生成卡密', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '生成', cls: 'success', act: doGenerate },
    ]);

    // 卡密格式：预设 + 自定义模板 + 实时示例
    const fmtSample = tpl => {
        const ALPHA = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        const NUM = '0123456789';
        const buf = new Uint8Array(tpl.length * 2);
        crypto.getRandomValues(buf);
        let out = '', j = 0;
        for (const ch of String(tpl).toUpperCase()) {
            if (ch === 'X') out += ALPHA[buf[j++] % ALPHA.length];
            else if (ch === 'D') out += NUM[buf[j++] % NUM.length];
            else out += ch;
        }
        return out;
    };
    const gFmtSel = document.getElementById('gFmt');
    const gFmtTpl = document.getElementById('gFmtTpl');
    const gFmtCustom = document.getElementById('gFmtCustom');
    const gFmtSample = document.getElementById('gFmtSample');
    const upFmt = () => {
        const isCustom = gFmtSel.value === '__custom__';
        gFmtCustom.style.display = isCustom ? '' : 'none';
        const tpl = (isCustom ? gFmtTpl.value : gFmtSel.value) || 'XXXX-XXXX-XXXX-XXXX';
        gFmtSample.textContent = fmtSample(tpl);
    };
    gFmtSel.addEventListener('change', upFmt);
    gFmtTpl.addEventListener('input', upFmt);
    upFmt();

    const updateGoodsOptions = () => {
        const swId = parseInt(document.getElementById('gSw').value, 10) || 0;
        const gSel = document.getElementById('gGoods');
        const cur = gSel.value;
        const filtered = goodsList.filter(g => !swId || (g.shop_software_id || 0) === 0 || (g.shop_software_id || 0) === swId);
        gSel.innerHTML = '<option value="0">不关联（手动填写规格）</option>'
            + filtered.map(g => `<option value="${g.id}">${esc(g.name)}</option>`).join('');

        if (cur && !filtered.some(g => String(g.id) === cur)) gSel.value = '0';
        gSel.dispatchEvent(new Event('change'));
    };
    document.getElementById('gSw').addEventListener('change', updateGoodsOptions);
    updateGoodsOptions();

    document.getElementById('gType').addEventListener('change', e => {
        const t = e.target.value;
        const hint = document.getElementById('gDurHint');
        const inp = document.getElementById('gDur');
        const unit = document.getElementById('gDurUnit');
        if (t === '1') { hint.textContent = '时长卡填时长并选择单位，如 30 天 / 12 小时'; if (!inp.value) inp.value = 30; unit.style.display = ''; inp.disabled = false; }
        else if (t === '4') { hint.textContent = '永久卡无需填写'; inp.value = 0; unit.style.display = 'none'; inp.disabled = true; }
        else if (t === '2') { hint.textContent = '点数卡填点数，如 100'; if (!inp.value) inp.value = 100; unit.style.display = 'none'; inp.disabled = false; }
        else { hint.textContent = '次数卡填次数，如 50'; if (!inp.value) inp.value = 50; unit.style.display = 'none'; inp.disabled = false; }
    });

    document.getElementById('gGoods').addEventListener('change', e => {
        const gid = parseInt(e.target.value, 10) || 0;
        const g = goodsList.find(x => x.id === gid);
        const typeSel = document.getElementById('gType');
        const durInp = document.getElementById('gDur');
        const devInp = document.getElementById('gDev');
        const grpSel = document.getElementById('gGroup');
        const gHint = document.getElementById('gGoodsHint');
        const specField = document.getElementById('gSpecField');
        const specSel = document.getElementById('gSpec');
        const specDetail = document.getElementById('gSpecDetail');
        const specSummary = document.getElementById('gSpecSummary');
        const specSummaryText = document.getElementById('gSpecSummaryText');
        if (!g) {

            typeSel.disabled = false; devInp.disabled = false; grpSel.disabled = false;
            specField.style.display = 'none';
            specDetail.style.display = '';
            specSummary.style.display = 'none';
            typeSel.dispatchEvent(new Event('change'));
            gHint.textContent = '选择上架商品后自动带出挂卡规格';
            return;
        }

        specDetail.style.display = 'none';
        specSummary.style.display = '';

        const cards = Array.isArray(g.cards) ? g.cards : [];
        if (cards.length > 1) {
            specField.style.display = '';
            specSel.innerHTML = cards.map((c, i) => {
                const name = c.card_type_text || '';
                const dur = durSpecText(c);
                return `<option value="${i}">${esc(name)} · ${esc(dur)}</option>`;
            }).join('');
            specSel.dispatchEvent(new Event('change'));
            gHint.textContent = `「${g.name}」有 ${cards.length} 个规格，选择要生成的规格`;
        } else {
            specField.style.display = 'none';

            fillSpec(g.card_type, g.card_duration, g.card_max_devices, g.card_group_id, typeSel, durInp, devInp, grpSel);
            const dur = durSpecText({ card_type: g.card_type, card_duration: g.card_duration });
            specSummaryText.innerHTML = `<b>${esc(TYPE_TEXT[g.card_type] || '卡')}</b> · ${esc(dur)} · ${g.card_max_devices || 1} 设备`;
            gHint.textContent = `已按「${g.name}」锁定规格`;
        }
        const nameInp = document.getElementById('gName');
        if (!nameInp.value.trim()) nameInp.value = g.name;
    });


    document.getElementById('gSpec').addEventListener('change', e => {
        const gid = parseInt(document.getElementById('gGoods').value, 10) || 0;
        const g = goodsList.find(x => x.id === gid);
        if (!g) return;
        const cards = Array.isArray(g.cards) ? g.cards : [];
        const idx = parseInt(e.target.value, 10) || 0;
        const c = cards[idx];
        if (!c) return;
        const typeSel = document.getElementById('gType');
        const durInp = document.getElementById('gDur');
        const devInp = document.getElementById('gDev');
        const grpSel = document.getElementById('gGroup');
        fillSpec(c.card_type, c.card_duration, c.card_max_devices, c.card_group_id, typeSel, durInp, devInp, grpSel);
        const gHint = document.getElementById('gGoodsHint');
        const dur = durSpecText(c);
        gHint.textContent = `已选规格：${c.card_type_text || ''} · ${dur} · ${c.card_max_devices} 设备`;
        const specSummaryText = document.getElementById('gSpecSummaryText');
        if (specSummaryText) specSummaryText.innerHTML = `<b>${esc(c.card_type_text || TYPE_TEXT[c.card_type] || '')}</b> · ${esc(dur)} · ${c.card_max_devices} 设备`;
    });
}



function splitDur(sec) {
    sec = Math.max(60, sec || 0);
    const U = [[31536000, '年'], [2592000, '月'], [604800, '星期'], [86400, '天'], [3600, '小时'], [60, '分钟']];
    for (const [f, n] of U) { if (sec % f === 0) return { v: sec / f, sec: f }; }
    return { v: Math.max(1, Math.round(sec / 60)), sec: 60 };
}



function durSpecText(c) {
    const t = Number(c.card_type);
    const v = parseInt(c.card_duration, 10) || 0;
    if (t === 4 || v <= 0) return '永久';
    if (t === 2) return v + ' 点';
    if (t === 3) return v + ' 次';
    const sp = splitDur(v);
    const U = {31536000:'年',2592000:'月',604800:'星期',86400:'天',3600:'小时',60:'分钟'};
    const unitName = U[sp.sec] || '分钟';
    return sp.v + ' ' + unitName;
}



function fillSpec(cardType, cardDuration, maxDevices, groupId, typeSel, durInp, devInp, grpSel) {
    typeSel.value = String(cardType);
    typeSel.dispatchEvent(new Event('change'));
    const unitSel = document.getElementById('gDurUnit');
    if (cardType === 1) {
        const sp = splitDur(cardDuration || 86400);
        durInp.value = sp.v;
        unitSel.value = String(sp.sec);
    } else {
        durInp.value = cardDuration || 0;
    }
    devInp.value = maxDevices || 1;
    grpSel.value = String(groupId || 0);
    [typeSel, durInp, devInp, grpSel].forEach(el => { el.disabled = true; });
}

async function doGenerate() {
    const type = parseInt(document.getElementById('gType').value, 10);
    const rawDur = parseInt(document.getElementById('gDur').value, 10) || 0;
    const unitSec = parseInt(document.getElementById('gDurUnit').value, 10) || 86400;
    const duration = type === 1 ? rawDur * unitSec : rawDur;

    const gFmtSel = document.getElementById('gFmt');
    const fmtTpl = (gFmtSel && gFmtSel.value === '__custom__'
        ? (document.getElementById('gFmtTpl').value || 'XXXX-XXXX-XXXX-XXXX')
        : (gFmtSel ? gFmtSel.value : 'XXXX-XXXX-XXXX-XXXX')).toUpperCase();

    const payload = {
        software_id: parseInt(document.getElementById('gSw').value, 10) || 1,
        count: parseInt(document.getElementById('gCount').value, 10) || 1,
        type: type,
        duration: duration,
        max_devices: parseInt(document.getElementById('gDev').value, 10) || 1,
        group_id: parseInt(document.getElementById('gGroup').value, 10) || 0,
        prefix: document.getElementById('gPrefix').value.trim(),
        format: fmtTpl,
        expire_days: parseInt(document.getElementById('gExpire').value, 10) || 0,
        name: document.getElementById('gName').value.trim(),
        remark: document.getElementById('gRemark').value.trim(),
    };

    const res = await api('card_generate', payload);
    if (res.code !== 0) return;

    const d = res.data;
    closeModal();
    openModal('生成成功', `
        <div style="background:rgba(52,211,153,.12);color:var(--success);padding:12px 16px;border-radius:9px;margin-bottom:16px">
            成功生成 <b>${d.count}</b> 张卡密（批次 #${d.batch_id}）
        </div>
        <p style="margin-bottom:10px;color:var(--text-sub);font-size:13px">
            以下为前 ${d.preview_count} 条预览，完整卡密请点击「导出」下载：
        </p>
        <div class="code-box">${d.codes.map(esc).join('\n')}</div>`,
        [
            { text: '复制预览', cls: 'ghost', act: () => {
                copyText(d.codes.join('\n')).then(() => toast('已复制到剪贴板'));
            }},
            { text: '去导出', cls: '', act: () => { closeModal(); render(); }},
        ], 'wide');
}



function cardExport() {
    const body = `
    <div class="row2">
        <div class="field"><label>导出格式</label>
            <select id="exFmt">
                <option value="txt">TXT（纯卡密，一行一个）</option>
                <option value="csv">CSV（含详细信息）</option>
            </select>
        </div>
        <div class="field"><label>卡密状态</label>
            <select id="exStatus">
                <option value="0">仅未使用</option>
                <option value="1">仅已使用</option>
                <option value="3">仅已售出</option>
                <option value="">全部</option>
            </select>
        </div>
    </div>
    <div class="field"><label>最大导出条数</label><input id="exLimit" type="number" value="10000"></div>
    <div class="hint">提示：如需按批次导出，请在「卡密批次」页面操作。</div>`;

    openModal('导出卡密', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '导出', cls: '', act: async () => {
            try {
                const { blob, name } = await apiDownload('card_export', {
                    format: document.getElementById('exFmt').value,
                    status: document.getElementById('exStatus').value,
                    limit: document.getElementById('exLimit').value,
                }, 'cards.txt');
                downloadBlob(blob, name);
                toast('导出成功');
                closeModal();
            } catch (e) { toast('导出失败：' + e.message, 'err'); }
        }},
    ]);
}



async function cardDetail(id) {
    openModal('卡密详情', loading(), [], 'wide');
    const res = await api('card_detail', { card_id: id });
    if (res.code !== 0) { closeModal(); return; }
    const d = res.data, k = d.card;

    const devRows = (d.devices || []).map(x => `
        <tr>
            <td class="mono" style="font-size:12px">${esc(x.machine_id)}</td>
            <td>${esc(x.device_name || '-')}</td>
            <td class="mono">${esc(x.ip || '-')}</td>
            <td class="mono" style="font-size:12px">${esc(x.bound_at_text)}</td>
        </tr>`).join('');

    openModal(`卡密详情 · ${k.code}`, `
    <div class="kv">
        <span class="k">卡密</span><span class="v mono"><b>${esc(k.code)}</b></span>
        <span class="k">批次</span><span class="v">${k.batch_id ? '#' + k.batch_id + ' ' + esc(k.batch_name || '') : '-'}</span>
        <span class="k">类型</span><span class="v">${tag(k.type_text, 'blue')}</span>
        <span class="k">时长/点数</span><span class="v">${esc(k.duration_text)}</span>
        <span class="k">设备上限</span><span class="v">${k.max_devices}</span>
        <span class="k">激活分组</span><span class="v">${k.group_name ? tag(k.group_name, 'purple') : '-'}</span>
        <span class="k">状态</span><span class="v">${statusTag(k.status, { 0: ['未使用', 'green'], 1: ['已使用', 'gray'], 2: ['已作废', 'red'], 3: ['已售出', 'yellow'] })}</span>
        <span class="k">卡密有效期</span><span class="v">${esc(k.expire_text)}</span>
        <span class="k">使用者</span><span class="v">${k.used_by ? 'UID:' + k.used_by + ' ' + esc(k.used_username || '') : '-'}</span>
        <span class="k">使用时间</span><span class="v">${esc(k.used_at_text)}</span>
        <span class="k">使用 IP</span><span class="v mono">${esc(k.used_ip || '-')}</span>
        <span class="k">生成人</span><span class="v">${esc(k.create_admin_name || (k.agent_id ? '代理：' + (k.agent_name || ('#' + k.agent_id)) : '') || '-')}</span>
        <span class="k">生成时间</span><span class="v">${esc(k.created_at_text)}</span>
        <span class="k">备注</span><span class="v">${esc(k.remark || '-')}</span>
    </div>
    ${devRows ? `
    <h4 style="margin:18px 0 10px;font-size:14px">激活时绑定的设备</h4>
    <div class="table-wrap"><table>
        <thead><tr><th>机器码</th><th>设备名</th><th>IP</th><th>绑定时间</th></tr></thead>
        <tbody>${devRows}</tbody>
    </table></div>` : ''}`,
    [{ text: '关闭', cls: '', act: closeModal }], 'wide');
}

async function cardEdit(id) {
    const res = await api('card_detail', { card_id: id }, true);
    if (res.code !== 0) return;
    const k = res.data.card;


    let groupOptions = '';
    const gRes = await api('group_list', {}, true);
    if (gRes.code === 0 && Array.isArray(gRes.data.list)) {
        groupOptions = gRes.data.list
            .map(g => `<option value="${g.id}" ${g.id === (k.group_id || 0) ? 'selected' : ''}>${esc(g.name)}（${g.max_devices} 设备）</option>`)
            .join('');
    }

    const durSp = k.type === 1 ? splitDur(k.duration) : null;

    openModal(`编辑卡密 · ${k.code}`, `
    <div class="field"><label>卡密</label><input value="${esc(k.code)}" disabled></div>
    <div class="row2">
        <div class="field"><label>时长/点数（${esc(k.type_text)}）</label>
            ${k.type === 1
                ? `<div style="display:flex;gap:8px">
                       <input id="ceDur" type="number" value="${durSp.v}" style="flex:1;min-width:0">
                       <select id="ceUnit" style="width:86px;flex:none">
                           <option value="31536000" ${durSp.sec === 31536000 ? 'selected' : ''}>年</option>
                           <option value="2592000" ${durSp.sec === 2592000 ? 'selected' : ''}>月</option>
                           <option value="604800" ${durSp.sec === 604800 ? 'selected' : ''}>星期</option>
                           <option value="86400" ${durSp.sec === 86400 ? 'selected' : ''}>天</option>
                           <option value="3600" ${durSp.sec === 3600 ? 'selected' : ''}>小时</option>
                           <option value="60" ${durSp.sec === 60 ? 'selected' : ''}>分钟</option>
                       </select>
                   </div>
                   <div class="hint">选择单位后保存，激活时按时长叠加</div>`
                : `<input id="ceDur" type="number" value="${k.duration}">
                   <div class="hint">原始数值</div>`}
        </div>
        <div class="field"><label>最大设备数</label><input id="ceDev" type="number" value="${k.max_devices}" min="1" max="99"></div>
    </div>
    <div class="field">
        <label>激活后进入用户组</label>
        <select id="ceGroup">
            <option value="0" ${(k.group_id || 0) === 0 ? 'selected' : ''}>不换组（保持注册时的默认用户组）</option>
            ${groupOptions}
        </select>
        <div class="hint">用户激活该卡密后，账号将自动切换到所选用户组</div>
    </div>
    <div class="field">
        <label>卡密自身有效期</label>
        <input id="ceExpire" value="${esc(k.expire_text === '永久有效' ? '-1' : k.expire_text)}" placeholder="YYYY-MM-DD HH:MM:SS 或 -1 永久">
    </div>
    <div class="field"><label>备注</label><input id="ceRemark" value="${esc(k.remark || '')}"></div>`,
    [{ text: '取消', cls: 'ghost', act: closeModal },
     { text: '保存', cls: '', act: async () => {
         const raw = parseInt(document.getElementById('ceDur').value, 10) || 0;
         const expStr = document.getElementById('ceExpire').value.trim();
         let expire = 0;
         if (expStr === '-1') expire = -1;
         else if (expStr && expStr !== '-') {
             const t = Math.floor(new Date(expStr.replace(/-/g, '/')).getTime() / 1000);
             if (!isNaN(t)) expire = t;
         }
         const unitEl = document.getElementById('ceUnit');
         const unitSec = unitEl ? (parseInt(unitEl.value, 10) || 86400) : 86400;
         const r = await api('card_update', {
             card_id: id,
             duration: k.type === 1 ? raw * unitSec : raw,
             max_devices: parseInt(document.getElementById('ceDev').value, 10) || 1,
             group_id: parseInt(document.getElementById('ceGroup').value, 10) || 0,
             expire_at: expire,
             remark: document.getElementById('ceRemark').value.trim(),
         });
         if (r.code === 0) { toast('保存成功'); closeModal(); render(); }
     }}]);
}

function voidCard(id) {
    confirmBox('作废卡密', '作废后该卡密将无法使用，确定继续？', async () => {
        const res = await api('card_void', { op: 'single', card_id: id });
        if (res.code === 0) { toast('已作废'); render(); }
    }, true);
}
