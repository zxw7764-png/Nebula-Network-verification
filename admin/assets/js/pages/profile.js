import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, esc, tag } from '../core/util.js';
import { toast, pager, bindPager } from '../core/ui.js';

register('profile', render);

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();
    const res = await api('profile', { op: 'get' });
    if (res.code !== 0) return;
    const a = res.data;

    c.innerHTML = `
    <div class="card">
        <div class="card-head"><h3>账号信息</h3></div>
        <div class="card-body">
            <div class="kv" style="margin-bottom:20px">
                <span class="k">账号</span><span class="v">${esc(a.username)}</span>
                <span class="k">昵称</span><span class="v">${esc(a.nickname)}</span>
                <span class="k">角色</span><span class="v">${tag(a.role_text, 'purple')}</span>
                <span class="k">最后登录</span><span class="v">${esc(a.last_login_text || '-')}</span>
                <span class="k">最后登录IP</span><span class="v mono">${esc(a.last_login_ip || '-')}</span>
            </div>
            <div class="field" style="max-width:400px">
                <label>修改昵称</label>
                <input id="pNick" value="${esc(a.nickname)}">
            </div>
            <button class="btn" id="pSave">保存昵称</button>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h3>修改密码</h3></div>
        <div class="card-body">
            <div class="field" style="max-width:400px">
                <label>原密码</label><input id="pOld" type="password" autocomplete="current-password">
            </div>
            <div class="field" style="max-width:400px">
                <label>新密码</label><input id="pNew" type="password" autocomplete="new-password" placeholder="10-64 位，含字母数字，且须有大写或符号">
            </div>
            <div class="field" style="max-width:400px">
                <label>确认新密码</label><input id="pNew2" type="password" autocomplete="new-password">
            </div>
            <button class="btn danger" id="pPwd">修改密码</button>
            <div class="hint">修改密码后当前会话会失效，需要重新登录。</div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h3>二次验证（2FA）</h3></div>
        <div class="card-body" id="pTotp"><div class="loading">加载中...</div></div>
    </div>

    <div class="card">
        <div class="card-head"><h3>登录记录</h3></div>
        <div class="table-wrap" id="pLogins"><div class="loading">加载中...</div></div>
    </div>`;

    document.getElementById('pSave').addEventListener('click', saveProfile);
    document.getElementById('pPwd').addEventListener('click', changePwd);

    loadTotp();
    loadLoginHistory(1);
}

const loginPageSize = 10;
let loginCurPage = 1;

async function loadLoginHistory(page) {
    loginCurPage = page || 1;
    const box = document.getElementById('pLogins');
    if (!box) return;
    box.innerHTML = '<div class="loading">加载中...</div>';
    const res = await api('profile', { op: 'login_history', page: loginCurPage, size: loginPageSize }, true);
    if (res.code !== 0) { box.innerHTML = ''; return; }
    const d = res.data || {};
    const list = d.list || [];
    const total = d.total || list.length;
    box.innerHTML = list.length ? `
        <table>
            <thead><tr><th>时间</th><th>IP</th><th>结果</th><th>说明</th></tr></thead>
            <tbody>${list.map(l => `
                <tr>
                    <td class="mono" style="font-size:12px">${esc(l.time_text)}</td>
                    <td class="mono">${esc(l.ip)}</td>
                    <td>${l.result == 1 ? tag('成功', 'green') : tag('失败', 'red')}</td>
                    <td style="color:#6b7280">${esc(l.message || '')}</td>
                </tr>`).join('')}</tbody>
        </table>
        ${pager(total, loginCurPage, loginPageSize)}` : '<div class="empty">暂无登录记录</div>';
    bindPager(box, p => loadLoginHistory(p));
}

async function saveProfile() {
    const res = await api('profile', {
        op: 'update',
        nickname: document.getElementById('pNick').value.trim(),
    });
    if (res.code === 0) {
        toast('已保存');
        if (window.__NB_STATE__ && window.__NB_STATE__.admin) {
            window.__NB_STATE__.admin.nickname = document.getElementById('pNick').value.trim();
        }
    }
}

async function changePwd() {
    const oldP = document.getElementById('pOld').value;
    const newP = document.getElementById('pNew').value;
    const newP2 = document.getElementById('pNew2').value;
    if (!oldP || !newP) return toast('请填写完整', 'warn');
    if (newP !== newP2) return toast('两次输入的新密码不一致', 'warn');
    if (newP.length < 10) return toast('管理员密码至少 10 位', 'warn');
    if (newP.length > 64) return toast('密码最长 64 位', 'warn');
    if (!/[a-zA-Z]/.test(newP) || !/\d/.test(newP)) return toast('密码需同时包含字母和数字', 'warn');
    if (!/[A-Z]/.test(newP) && !/[^A-Za-z0-9]/.test(newP)) return toast('管理员密码需额外包含大写字母或符号', 'warn');
    const weak = ['admin888', 'admin123', 'administrator', 'password', 'passw0rd', '12345678', '123456789',
        '1234567890', '87654321', '11111111', 'qwerty123', 'qwertyuiop', 'abcd1234', 'a1234567', 'abc12345',
        '1qaz2wsx', 'iloveyou', 'woaini1314', 'aa123456', 'qq123456', 'zxcvbnm1', 'asdfghj1', 'aa111111',
        '123456a', 'a123456'];
    if (weak.includes(newP.toLowerCase())) return toast('密码过于简单，请更换', 'warn');
    if (/^(.)\1+$/.test(newP)) return toast('密码过于简单，请更换', 'warn');

    const res = await api('profile', { op: 'change_password', old_password: oldP, new_password: newP });
    if (res.code === 0) {
        toast('密码修改成功，请重新登录');
        setTimeout(() => {
            localStorage.removeItem((window.__NB__ && window.__NB__.tokenKey) || 'nb_token');
            location.reload();
        }, 1200);
    }
}

async function loadTotp() {
    const box = document.getElementById('pTotp');
    if (!box) return;
    box.innerHTML = '<div class="loading">加载中...</div>';
    const res = await api('profile', { op: 'totp_status' });
    if (res.code !== 0) { box.innerHTML = ''; return; }
    const s = res.data || {};

    if (s.supported === false) {
        box.innerHTML = '<div class="empty">当前数据库缺少二次验证字段，请先执行 <span class="mono">install/migrate_admin_totp.php</span></div>';
        return;
    }

    if (s.enabled) {
        box.innerHTML = `
        <div class="kv" style="margin-bottom:16px">
            <span class="k">状态</span><span class="v">${tag('已开启', 'green')}</span>
            <span class="k">剩余恢复码</span><span class="v">${s.recovery_left || 0} 枚</span>
        </div>
        <div class="hint">登录后台时，除密码外还需输入验证器里的 6 位动态码；恢复码用一枚少一枚，请妥善保管。</div>
        <div class="field" style="max-width:400px">
            <label>输入当前密码以关闭</label>
            <input id="pTotpPass" type="password" autocomplete="current-password">
        </div>
        <button class="btn danger" id="pTotpOff">关闭二次验证</button>`;
        document.getElementById('pTotpOff').addEventListener('click', totpDisable);
        return;
    }

    box.innerHTML = `
    <div class="kv" style="margin-bottom:16px">
        <span class="k">状态</span><span class="v">${tag('未开启', 'gray')}</span>
    </div>
    <div class="hint">开启后，登录后台除密码外还需输入验证器（Google / Microsoft Authenticator、1Password 等）中的 6 位动态码。即使密码被撞库或钓鱼泄露，攻击者依然进不来。</div>
    <button class="btn" id="pTotpOn">开启二次验证</button>`;
    document.getElementById('pTotpOn').addEventListener('click', totpInit);
}

async function totpInit() {
    const res = await api('profile', { op: 'totp_init' });
    if (res.code !== 0) return;
    const d = res.data || {};
    const box = document.getElementById('pTotp');
    box.innerHTML = `
    <div class="hint" style="margin-bottom:12px">在验证器 App 中「手动添加账号」，输入下面的密钥（类型选「基于时间」）：</div>
    <div class="field" style="max-width:520px">
        <label>密钥（Base32）</label>
        <input id="pTotpSecret" class="mono" readonly value="${esc(d.secret || '')}">
    </div>
    <div class="field" style="max-width:520px">
        <label>otpauth 链接（可粘贴到任意二维码生成器扫码）</label>
        <input id="pTotpUri" class="mono" readonly value="${esc(d.uri || '')}">
    </div>
    <div class="field" style="max-width:400px">
        <label>输入验证器当前显示的 6 位动态码</label>
        <input id="pTotpCode" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="6 位数字">
    </div>
    <button class="btn" id="pTotpConfirm">确认开启</button>
    <button class="btn" id="pTotpCancel" style="margin-left:8px">取消</button>`;
    document.getElementById('pTotpConfirm').addEventListener('click', totpEnable);
    document.getElementById('pTotpCancel').addEventListener('click', loadTotp);
    const sec = document.getElementById('pTotpSecret');
    if (sec) sec.select();
}

async function totpEnable() {
    const code = (document.getElementById('pTotpCode').value || '').trim();
    if (!/^\d{6}$/.test(code)) return toast('请输入 6 位动态码', 'warn');
    const res = await api('profile', { op: 'totp_enable', code });
    if (res.code !== 0) return;
    const codes = (res.data && res.data.recovery) || [];
    const box = document.getElementById('pTotp');
    box.innerHTML = `
    <div class="kv" style="margin-bottom:16px">
        <span class="k">状态</span><span class="v">${tag('已开启', 'green')}</span>
    </div>
    <div class="hint" style="color:#b45309">以下是恢复码，<b>仅显示这一次</b>。手机丢失时可代替动态码登录（每用一次自动作废），请立即抄写保存到安全的地方：</div>
    <div class="mono" style="max-width:520px;line-height:2;padding:12px;border-radius:8px;border:1px solid var(--border)">${codes.map(x => esc(x)).join('<br>')}</div>
    <button class="btn" id="pTotpDone" style="margin-top:12px">我已保存</button>`;
    document.getElementById('pTotpDone').addEventListener('click', loadTotp);
    toast('二次验证已开启');
}

async function totpDisable() {
    const pass = document.getElementById('pTotpPass').value;
    if (!pass) return toast('请输入当前密码', 'warn');
    const res = await api('profile', { op: 'totp_disable', password: pass });
    if (res.code === 0) {
        toast('已关闭二次验证');
        loadTotp();
    }
}
