import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, empty, esc, tag } from '../core/util.js';
import { openModal, closeModal, confirmBox, toast, createSelection, checkAllBox, rowCheckBox } from '../core/ui.js';

register('software_list', render);

async function copyText(v) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(v);
        } else {
            const t = document.createElement('textarea');
            t.value = v;
            t.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(t);
            t.select();
            document.execCommand('copy');
            t.remove();
        }
        toast('已复制');
    } catch (e) {
        toast('复制失败，请手动选择复制', 'err');
    }
}

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();
    const res = await api('software_list');
    if (res.code !== 0) return;
    const list = res.data.list || [];
    const copyBtn = v => `<button class="btn ghost xs" data-copy="${esc(v)}">复制</button>`;

    const keyCell = v => `<div style="display:flex;align-items:center;gap:4px;min-width:0"><span title="${esc(v)}" style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11.5px">${esc(v)}</span>${copyBtn(v)}</div>`;

    const rows = list.map(s => `
        <tr>
            ${rowCheckBox(s.id)}
            <td>${s.id}</td>
            <td><b>${esc(s.name)}</b></td>
            <td style="max-width:170px">${keyCell(s.app_key)}</td>
            <td>${esc(s.min_version)}</td>
            <td>${s.status === 1 ? tag('启用', 'green') : tag('停用', 'gray')}</td>
            <td style="white-space:nowrap">
                <button class="btn ghost sm" data-act="edit" data-id="${s.id}">编辑</button>
                <button class="btn ghost sm" data-act="copyapi" data-id="${s.id}">复制接口</button>
                ${list.length > 1 ? `<button class="btn danger sm" data-act="del" data-id="${s.id}">删除</button>` : ''}
            </td>
        </tr>`).join('');

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>软件列表</h3>
            <div class="acts">
                <span id="swBulkBox" hidden class="bulk-inline" data-count="0">
                    <select id="swBulkOp">
                        <option value="enable">批量启用</option>
                        <option value="disable">批量停用</option>
                        <option value="delete">批量删除</option>
                    </select>
                    <button class="btn" id="swBulkRun">执行</button>
                </span>
                <button class="btn bulk-hide success" id="swNew">+ 新增软件</button>
            </div>
        </div>
        <p class="muted" style="margin:4px 0 16px;padding:10px 14px;background:var(--bg);border:1px solid var(--border-soft);border-radius:10px;font-size:12.5px;line-height:1.8">
            客户端请求外层携带 <code>app_key</code> 识别软件；通信采用 3.1 协议（ECDH 握手 + AES-256-GCM 会话信封），客户端不持有静态通信密钥。
            卡密 / 代理商 / 版本发布 / 账号均按软件隔离 —— 1 软件的激活码与账号无法在 2 软件使用。
        </p>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    ${checkAllBox()}
                    <th>ID</th><th>软件名</th><th>app_key</th>
                    <th>最低/最新版本</th><th>状态</th><th>操作</th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="7">${empty('<i class="bi bi-window-stack"></i>', '暂无软件')}</td></tr>`}</tbody>
            </table>
        </div>
    </div>`;

    document.getElementById('swNew').addEventListener('click', () => swEdit(null));

    const sel = createSelection({ root: c, allIds: list.map(s => s.id), onChange: ids => {
        const box = document.getElementById('swBulkBox');
        if (box) { box.hidden = ids.length === 0; box.dataset.count = String(ids.length); }
        c.querySelectorAll('.bulk-hide').forEach(b => { b.hidden = ids.length > 0; });
    }});
    const bulkRun = document.getElementById('swBulkRun');
    if (bulkRun) bulkRun.addEventListener('click', () => {
        const ids = sel.ids();
        if (!ids.length) { toast('请先勾选软件'); return; }
        const act = document.getElementById('swBulkOp').value;
        if (act === 'delete') {
            confirmBox('批量删除软件', `确定删除所选 ${ids.length} 个软件？被删除软件的会话密钥会被清除，卡密与账号记录保留但不再可用。`, async () => {
                const r = await api('software_batch', { act: 'delete', ids });
                if (r.code === 0) { toast(r.msg || '已删除'); render(); }
                else toast(r.msg || '删除失败', 'err');
            }, true);
            return;
        }
        api('software_batch', { act, ids }).then(r => {
            if (r.code === 0) { toast(r.msg || '已操作'); render(); }
            else toast(r.msg || '操作失败', 'err');
        });
    });

    c.querySelectorAll('[data-copy]').forEach(b => {
        b.addEventListener('click', () => copyText(b.dataset.copy));
    });

    c.querySelectorAll('[data-act]').forEach(b => {
        const id = parseInt(b.dataset.id, 10);
        const item = list.find(x => x.id === id);
        b.addEventListener('click', () => {
            if (b.dataset.act === 'edit') swEdit(item);
            else if (b.dataset.act === 'copyapi') copyText(location.origin + '/api/index.php');
            else if (b.dataset.act === 'del') swDel(item);
        });
    });
}

function swEdit(s) {
    s = s || {};
    const pol = (s.policy && !Array.isArray(s.policy)) ? s.policy : {};
    const body = `
    <div class="row2">
        <div class="field"><label>软件名称 *</label><input id="swName" value="${esc(s.name || '')}" placeholder="如：某某辅助"></div>
        <div class="field"><label>app_key（客户端标识）</label><input id="swAppKey" value="${esc(s.app_key || '')}" placeholder="留空自动生成，如 SW1A2B3C4D5E6F">
            <small class="muted">${s.id ? '修改后需同步更新客户端' : '创建后客户端请求外层携带此字段'}</small></div>
    </div>
    <div class="row2">
        <div class="field"><label>最低可用版本</label><input id="swMin" value="${esc(s.min_version || '1.0.0')}" placeholder="低于此版本强制更新"></div>
        <div class="field"><label>登录方式（客户端与官网通用）</label>
            <select id="swLogin">
                <option value="" ${!s.login_methods ? 'selected' : ''}>跟随全局设置</option>
                <option value="password" ${s.login_methods === 'password' ? 'selected' : ''}>用户名 + 密码</option>
                <option value="username_code" ${s.login_methods === 'username_code' ? 'selected' : ''}>用户名 + 激活码</option>
                <option value="code" ${s.login_methods === 'code' ? 'selected' : ''}>激活码（卡密直登）</option>
            </select>
            <small class="muted">留空跟随「系统设置 → 安全」的全局登录方式</small>
        </div>
    </div>
    <div class="field" style="margin-bottom:10px"><label>功能密钥（仅登录成功后下发给客户端）</label>
        <div style="display:flex;gap:6px">
            <input id="swFeatureKey" value="${esc(s.feature_key || '')}" placeholder="留空 = 未启用" style="flex:1">
            <button type="button" class="btn ghost" id="swFeatureKeyGen" style="padding:4px 14px;flex-shrink:0">生成随机</button>
        </div>
        <small class="muted">接入方用它加密核心数据包随程序分发（SDK nebula::feature::seal / open）；登录失败或被踢后数据保持密文</small>
    </div>
    <div class="row2">
        <div class="field"><label>状态</label>
            <select id="swStatus">
                <option value="1" ${s.id === undefined || s.status === 1 ? 'selected' : ''}>启用</option>
                <option value="0" ${s.status === 0 ? 'selected' : ''}>停用</option>
            </select>
        </div>
        <div class="field"><label>备注</label><input id="swRemark" value="${esc(s.remark || '')}"></div>
    </div>
    <div class="field" style="margin-top:6px"><label>策略覆盖（留空 = 跟随「系统设置 → 安全」的全局值，仅对该软件生效）</label></div>
    <div class="row2">
        <div class="field"><label>注册开关</label>
            <select id="swP_register_enable">
                <option value="">跟随全局</option>
                <option value="1" ${pol.register_enable === 1 ? 'selected' : ''}>开放注册</option>
                <option value="0" ${pol.register_enable === 0 ? 'selected' : ''}>关闭注册</option>
            </select>
        </div>
        <div class="field"><label>维护模式</label>
            <select id="swP_maintain_mode">
                <option value="">跟随全局</option>
                <option value="0" ${pol.maintain_mode === 0 ? 'selected' : ''}>正常运行</option>
                <option value="1" ${pol.maintain_mode === 1 ? 'selected' : ''}>维护中</option>
            </select>
        </div>
    </div>
    <div class="field"><label>维护提示文案</label><input id="swP_maintain_msg" value="${esc(pol.maintain_msg || '')}" placeholder="仅该软件维护时显示，留空用全局文案"></div>
    <div class="row2">
        <div class="field"><label>单点登录</label>
            <select id="swP_single_login">
                <option value="">跟随全局</option>
                <option value="1" ${pol.single_login === 1 ? 'selected' : ''}>开启（后登踢前登）</option>
                <option value="0" ${pol.single_login === 0 ? 'selected' : ''}>关闭</option>
            </select>
        </div>
        <div class="field"><label>异地登录拦截</label>
            <select id="swP_geo_block">
                <option value="">跟随全局</option>
                <option value="1" ${pol.geo_block === 1 ? 'selected' : ''}>开启</option>
                <option value="0" ${pol.geo_block === 0 ? 'selected' : ''}>关闭</option>
            </select>
        </div>
    </div>
    <div class="row2">
        <div class="field"><label>心跳间隔（秒）</label><input id="swP_heartbeat_interval" type="number" min="1" value="${pol.heartbeat_interval > 0 ? pol.heartbeat_interval : ''}" placeholder="跟随全局"></div>
        <div class="field"><label>心跳超时（秒）</label><input id="swP_heartbeat_timeout" type="number" min="1" value="${pol.heartbeat_timeout > 0 ? pol.heartbeat_timeout : ''}" placeholder="跟随全局"></div>
    </div>
    <div class="row2">
        <div class="field"><label>每日解绑次数（0=不限）</label><input id="swP_unbind_per_day" type="number" min="0" value="${Number.isFinite(pol.unbind_per_day) ? pol.unbind_per_day : ''}" placeholder="跟随全局"></div>
        <div class="field"><label>新号默认设备数</label><input id="swP_default_max_devices" type="number" min="1" value="${pol.default_max_devices > 0 ? pol.default_max_devices : ''}" placeholder="跟随全局"></div>
    </div>
    <div class="row2">
        <div class="field"><label>设备指纹</label>
            <select id="swP_device_fp_enable">
                <option value="">跟随全局</option>
                <option value="1" ${pol.device_fp_enable === 1 ? 'selected' : ''}>开启</option>
                <option value="0" ${pol.device_fp_enable === 0 ? 'selected' : ''}>关闭</option>
            </select>
        </div>
        <div class="field"><label>离线宽限票据</label>
            <select id="swP_grace_enable">
                <option value="">跟随全局</option>
                <option value="1" ${pol.grace_enable === 1 ? 'selected' : ''}>开启</option>
                <option value="0" ${pol.grace_enable === 0 ? 'selected' : ''}>关闭</option>
            </select>
        </div>
        <div class="field"><label>单次宽限时长(秒)</label><input id="swP_grace_seconds" type="number" min="0" value="${pol.grace_seconds > 0 ? pol.grace_seconds : ''}" placeholder="跟随全局"></div>
        <div class="field"><label>累计上限(秒)</label><input id="swP_grace_max_seconds" type="number" min="0" value="${pol.grace_max_seconds > 0 ? pol.grace_max_seconds : ''}" placeholder="跟随全局"></div>
    </div>`;

    openModal(s.id ? `编辑软件 · ${esc(s.name)}` : '新增软件', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '保存', cls: 'success', act: async () => {            const payload = {
                id: s.id || 0,
                name: document.getElementById('swName').value.trim(),
                app_key: document.getElementById('swAppKey').value.trim(),
                min_version: document.getElementById('swMin').value.trim(),
                login_methods: document.getElementById('swLogin').value,
                feature_key: document.getElementById('swFeatureKey').value.trim(),
                policy: {
                    register_enable: document.getElementById('swP_register_enable').value,
                    maintain_mode: document.getElementById('swP_maintain_mode').value,
                    maintain_msg: document.getElementById('swP_maintain_msg').value.trim(),
                    single_login: document.getElementById('swP_single_login').value,
                    geo_block: document.getElementById('swP_geo_block').value,
                    heartbeat_interval: document.getElementById('swP_heartbeat_interval').value.trim(),
                    heartbeat_timeout: document.getElementById('swP_heartbeat_timeout').value.trim(),
                    unbind_per_day: document.getElementById('swP_unbind_per_day').value.trim(),
                    default_max_devices: document.getElementById('swP_default_max_devices').value.trim(),
                    device_fp_enable: document.getElementById('swP_device_fp_enable').value,
                    grace_enable: document.getElementById('swP_grace_enable').value,
                    grace_seconds: document.getElementById('swP_grace_seconds').value.trim(),
                    grace_max_seconds: document.getElementById('swP_grace_max_seconds').value.trim(),
                },
                status: parseInt(document.getElementById('swStatus').value, 10) || 0,
                remark: document.getElementById('swRemark').value.trim(),
            };
            const res = await api('software_save', payload);
            if (res.code === 0) { toast('保存成功'); closeModal(); render(); }
            else toast(res.msg || '保存失败', 'err');
        }},
    ]);

    // CSP 的 script-src 不含 unsafe-inline，内联 onclick 会被拦截，改为事件绑定
    const genBtn = document.getElementById('swFeatureKeyGen');
    if (genBtn) {
        genBtn.addEventListener('click', () => {
            const buf = new Uint8Array(16);
            crypto.getRandomValues(buf);
            document.getElementById('swFeatureKey').value =
                Array.from(buf, b => b.toString(16).padStart(2, '0')).join('');
        });
    }
}

function swDel(s) {
    const body = `
    <p style="font-size:13px;line-height:1.8">
        确定删除软件「<b>${esc(s.name)}</b>」？<br>
        该软件的会话密钥会被清除，卡密与账号记录保留但不再可用。
    </p>
    <div class="field"><label>当前登录密码（敏感操作二次确认）</label>
        <input id="dPwd" type="password" autocomplete="current-password" placeholder="请输入你的后台登录密码"></div>`;

    openModal('删除软件', body, [
        { text: '取消', cls: 'ghost', act: closeModal },
        { text: '确认删除', cls: 'danger', act: async () => {
            const pwd = document.getElementById('dPwd').value;
            if (!pwd) { toast('请输入当前登录密码', 'warn'); return; }
            const res = await api('software_delete', { id: s.id, confirm_pwd: pwd });
            if (res.code === 0) { closeModal(); toast('已删除'); render(); }
            else toast(res.msg || '删除失败', 'err');
        }},
    ], true);
}
