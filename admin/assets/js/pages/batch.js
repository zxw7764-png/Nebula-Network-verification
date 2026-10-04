import { api, apiDownload } from '../core/api.js';
import { register, go } from '../core/router.js';
import { pageState } from '../core/state.js';
import { loading, empty, esc, tag, downloadBlob } from '../core/util.js';
import {
    openModal, closeModal, confirmBox, toast, pager, bindPager,
    createSelection, checkAllBox, rowCheckBox,
} from '../core/ui.js';

const DEFAULTS = { page: 1, size: 20 };

let sel = null;

register('card_batch_list', render);

async function render() {
    const st = pageState('card_batch_list', DEFAULTS);
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('card_batch_list', st);
    if (res.code !== 0) return;
    const d = res.data;

    const rows = d.list.map(b => `
        <tr>
            ${rowCheckBox(b.id)}
            <td>${b.id}</td>
            <td><b>${esc(b.name || '-')}</b></td>
            <td>${b.prefix ? tag(b.prefix, 'purple') : '-'}</td>
            <td class="mono" style="font-size:12px">${b.code_format ? esc(b.code_format) : '-'}</td>
            <td>${tag(b.type_text, b.is_ext ? 'yellow' : 'blue')}</td>
            <td>${esc(b.duration_text)}</td>
            <td>${b.max_devices || '-'}</td>
            <td>${b.group_name ? tag(b.group_name, 'purple') : '-'}</td>
            <td>${b.count}</td>
            <td>${b.used_count}</td>
            <td>${b.unused_count}</td>
            <td class="mono" style="font-size:12px">${esc(b.created_at)}</td>
            <td style="white-space:nowrap">${b.is_ext ? `
                <button class="btn ghost sm" data-act="view" data-id="${b.id}">查看</button>
                <button class="btn danger sm" data-act="del" data-id="${b.id}">删除批次</button>
            ` : `
                <button class="btn ghost sm" data-act="export" data-id="${b.id}">导出</button>
                <button class="btn ghost sm" data-act="view" data-id="${b.id}">查看</button>
                <button class="btn danger sm" data-act="void" data-id="${b.id}">作废未用</button>
                <button class="btn danger sm" data-act="del" data-id="${b.id}">删除批次</button>
            `}</td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>卡密批次</h3>
            <div class="acts">
                <span id="bBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="bBulkOp">
                        <option value="export">批量导出</option>
                        <option value="void">批量作废未用</option>
                        <option value="delete">批量删除批次</option>
                    </select>
                    <button class="btn" id="bBulkRun">执行</button>
                </span>
                <button class="btn bulk-hide success" id="bGen">+ 生成卡密</button>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>批次名</th><th>前缀</th><th>格式</th><th>类型</th><th>时长/点数</th>
                    <th>设备</th><th>激活分组</th><th>总数</th><th>已用</th><th>未用</th><th>生成时间</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="14">${empty('<i class="bi bi-boxes"></i>', '暂无批次')}</td></tr>`}</tbody>
            </table>
        </div>
        ${pager(d.total, d.page, d.size)}
    </div>`;

    bindPager(c, p => { st.page = p; render(); });
    document.getElementById('bGen').addEventListener('click', () => {

        go('card_list');
        setTimeout(() => {
            const btn = document.getElementById('kGen');
            if (btn) btn.click();
        }, 120);
    });

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = d.list.find(x => x.id === id) || {};
        b.addEventListener('click', () => {
            if (b.dataset.act === 'export') batchExport(id);
            else if (b.dataset.act === 'view') batchView(id, item.is_ext === 1);
            else if (b.dataset.act === 'void') batchVoid(id);
            else if (b.dataset.act === 'del') batchDelete(id);
        });
    });

    sel = createSelection({ root: c, allIds: d.list.map(x => x.id), onChange: ids => {
        const box = document.getElementById('bBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});

    const bulkRun = document.getElementById('bBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const op = document.getElementById('bBulkOp').value;
        doBulk(op);
    });
}

async function doBulk(op) {
    const ids = sel ? sel.ids() : [];
    if (!ids.length) return toast('请先选择批次', 'warn');

    if (op === 'export') {
        for (const id of ids) {
            try {
                const { blob } = await apiDownload('card_export',
                    { batch_id: id, format: 'txt', status: '' }, `batch_${id}.txt`);
                downloadBlob(blob, `batch_${id}_${Date.now()}.txt`);
            } catch (e) {
 }
        }
        toast(`已导出 ${ids.length} 个批次`);
        return;
    }

    if (op === 'void') {
        confirmBox('批量作废批次', `将作废已选的 ${ids.length} 个批次中所有「未使用」卡密，确定继续？`, async () => {
            let total = 0;
            for (const id of ids) {
                const res = await api('card_void', { op: 'batch', batch_id: id });
                if (res.code === 0) total += (res.data && res.data.count) || 0;
            }
            toast(`已作废 ${total} 张卡密`);
            if (sel) sel.clear();
            render();
        }, true);
        return;
    }

    if (op === 'delete') {
        deleteBatches(ids);
    }
}

function deleteBatches(ids) {
    openModal('批量删除批次', `
        <p style="color:var(--danger);margin-bottom:14px">
            <b>此操作不可恢复！</b>批次记录将被永久删除，
            批次内<b>未使用</b>的卡密会被一并清理；已使用的卡密会保留但脱离批次。
        </p>
        <div class="field">
            <label>管理密码 *</label>
            <input id="bcPass" type="password" autocomplete="off" placeholder="请输入管理密码">
        </div>
        <div class="hint">将对已选的 ${ids.length} 个批次生效</div>`,
        [{ text: '取消', cls: 'ghost', act: closeModal },
         { text: '确认删除', cls: 'danger', act: async () => {
             const pass = document.getElementById('bcPass').value;
             if (!pass) return toast('请输入管理密码', 'warn');
             const res = await api('card_batch_op', { op: 'delete_batches', ids, password: pass });
             if (res.code === 0) { toast(res.msg); closeModal(); if (sel) sel.clear(); render(); }
         }}]);
    setTimeout(() => { const i = document.getElementById('bcPass'); if (i) i.focus(); }, 50);
}

async function batchExport(batchId) {
    try {
        const { blob } = await apiDownload('card_export',
            { batch_id: batchId, format: 'txt', status: '' },
            `batch_${batchId}.txt`);
        downloadBlob(blob, `batch_${batchId}_${Date.now()}.txt`);
        toast('导出成功');
    } catch (e) { toast('导出失败：' + e.message, 'err'); }
}

function batchView(batchId, isExt) {
    const st = pageState('card_list', {});
    st.batch_id = batchId;
    st.agent_id = isExt ? 'ext' : '';
    st.page = 1;
    st.keyword = '';
    st.status = '';
    st.type = 0;
    go('card_list');
}

function batchVoid(batchId) {
    confirmBox('作废批次', '将作废该批次中所有「未使用」的卡密，确定继续？', async () => {
        const res = await api('card_void', { op: 'batch', batch_id: batchId });
        if (res.code === 0) { toast(res.msg); render(); }
    }, true);
}

function batchDelete(batchId) {
    confirmBox('删除批次', '将删除该批次记录（批次内已使用的卡密会保留）。确定继续？', async () => {
        const res = await api('card_batch_op', { op: 'delete_batch', batch_id: batchId });
        if (res.code === 0) { toast(res.msg); render(); }
    }, true);
}
