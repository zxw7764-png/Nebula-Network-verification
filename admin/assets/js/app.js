import { S, setToken, setSessionKey, useCookieSession, API_ENTRY } from './core/state.js';
import { api, login } from './core/api.js';
import { toast, confirmBox } from './core/ui.js';
import { go, currentFromHash, renderNav } from './core/router.js';
import { initTheme, toggleTheme, appliedTheme } from './core/theme.js';
import { esc } from './core/util.js';

const PAGES = [
    "dashboard", "bigscreen", "analytics", "stat", "software", "user", "agent", "agent_code",
    "card", "batch", "device", "device_ban", "session", "notice", "version", "client_notice",
    "group", "message", "feedback", "plan", "shop", "shop_setting", "shop_goods",
    "seller", "screenshot", "log", "files", "audit", "sec_report", "setting", "profile", "portal_web", "templates", "games",
    "system_update", "admins",
    "rt_security",
];
const pagesReady = Promise.all(
    PAGES.map(p => import(`./pages/${p}.js?v=${window.NB_V || ''}`).catch(() => {
 }))
);

function refreshLoginCaptcha() {
    const img = document.getElementById('lgCaptchaImg');
    if (!img) return;
    img.onerror = () => {
        img.onerror = null;
        setTimeout(refreshLoginCaptcha, 1200);
    };
    img.src = `${API_ENTRY}?action=captcha&t=${Date.now()}`;
    const inp = document.getElementById('lgCaptcha');
    if (inp) inp.value = '';
}

function showTotpField() {
    const wrap = document.getElementById('lgTotpWrap');
    if (!wrap) {
        location.reload();
        return false;
    }
    if (wrap.style.display === 'none') {
        wrap.style.display = '';
    }
    return true;
}

async function doLogin() {
    const u = document.getElementById('lgUser').value.trim();
    const p = document.getElementById('lgPass').value;
    const c = (document.getElementById('lgCaptcha')?.value || '').trim();
    const t = (document.getElementById('lgTotp')?.value || '').trim();
    if (!u || !p) return toast('请输入账号和密码', 'warn');
    if (!c) return toast('请输入图形验证码', 'warn');

    const btn = document.getElementById('lgBtn');
    if (btn) { btn.disabled = true; btn.textContent = '登录中...'; }
    try {
        const res = await login(u, p, c, t);

        if (res.code === 2006 || res.code === 2007) {

            if (!showTotpField()) return;
            toast(res.msg || '请输入动态验证码', 'warn');
            refreshLoginCaptcha();
            const tin = document.getElementById('lgTotp');
            if (tin) { tin.focus(); }
            return;
        }
        if (res.code !== 0) {
            toast(res.msg || '登录失败', 'err');
            refreshLoginCaptcha();
            return;
        }
        toast('登录成功');
        enterApp();
    } catch (e) {
        toast('登录请求失败：' + e.message, 'err');
        refreshLoginCaptcha();
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = '登 录'; }
    }
}

function doLogout() {
    confirmBox('退出登录', '确定要退出管理后台吗？', async () => {
        try { await api('logout', {}, true); } catch (e) {
 }
        setToken('');
        setSessionKey('');
        location.reload();
    });
}

async function enterApp() {

    await pagesReady;

    document.getElementById('loginPage').style.display = 'none';
    document.getElementById('app').style.display = 'block';

    const a = S.admin || {};
    document.getElementById('sideAdmin').textContent = a.username || '-';
    document.getElementById('sideRole').textContent = a.role_text || '-';
    document.getElementById('topAvatar').textContent =
        ((a.nickname || a.username || 'A').charAt(0) || 'A').toUpperCase();

    renderNav();

    try {
        const res = await api('system_update_check', { force: 1 }, true);
        if (res.code === 0 && res.data && res.data.latest) {
            const cur = res.data.current_version || '';
            const latest = res.data.latest.latest_version || '';
            if (cur && latest && compareVersion(cur, latest) < 0) {
                if (res.data.latest.force_update) {
                    // 低于最低版本线（min_version）：强制封锁弹窗，后台不可用直至更新
                    showForceUpdateModal(cur, latest, res.data.latest);
                    return;
                }
                // 常规新版本：普通提示框（可关闭，不阻断后台使用；点击可跳转系统更新页）
                showUpdateNoticeModal(cur, latest, res.data.latest);
            }
        }
    } catch (e) {
    }

    go('dashboard');
}

/** 常规更新提示框：检测到新版本但未低于最低版本线时弹出，可关闭/可跳转系统更新页 */
function showUpdateNoticeModal(cur, latest, info) {
    if (document.getElementById('updateNoticeModal')) return;
    const notes = (info && info.release_notes) || [];
    const overlay = document.createElement('div');
    overlay.id = 'updateNoticeModal';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:9500;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = `
    <div style="max-width:440px;width:92%;border:1px solid rgba(96,165,250,.35);border-radius:14px;background:var(--card-bg,#1a1a2e);overflow:hidden;box-shadow:0 12px 48px rgba(0,0,0,.6)">
        <div style="padding:24px 24px 12px;text-align:center">
            <div style="width:52px;height:52px;margin:0 auto 12px;border-radius:50%;background:rgba(96,165,250,.15);display:flex;align-items:center;justify-content:center">
                <i class="bi bi-cloud-arrow-up" style="font-size:26px;color:#60a5fa"></i>
            </div>
            <h3 style="font-size:17px;margin-bottom:6px;color:var(--text,#e2e8f0)">发现系统新版本</h3>
            <p style="font-size:13px;color:var(--text-sub,#94a3b8);line-height:1.7;margin-bottom:0">
                当前 <b>v${esc(cur)}</b> → 新版本 <b style="color:#60a5fa">v${esc(latest)}</b><br>
                可在「系统更新」页一键在线升级（自动备份 + 完整性校验）。
            </p>
        </div>
        ${notes.length ? `<div style="margin:0 24px 12px;max-height:150px;overflow-y:auto;background:var(--bg-alt,#0f0f1a);border-radius:8px;padding:10px 14px">
            <div style="font-size:12px;color:var(--text-sub,#64748b);margin-bottom:4px">v${esc(latest)} 更新内容</div>
            <ul style="list-style:none;padding:0;margin:0">
                ${notes.map(n => `<li style="padding:2px 0 2px 12px;position:relative;font-size:12.5px;color:var(--text-sub,#94a3b8);line-height:1.5">
                    <span style="position:absolute;left:0;top:10px;width:4px;height:4px;border-radius:50%;background:var(--text-faint,#475569)"></span>${esc(n)}</li>`).join('')}
            </ul>
        </div>` : ''}
        <div style="padding:0 24px 20px;display:flex;gap:10px">
            <button class="btn ghost" id="noticeLaterBtn" style="flex:1;text-align:center;padding:10px;border-radius:8px;font-size:14px;cursor:pointer">稍后再说</button>
            <button class="btn success" id="noticeGoUpdateBtn" style="flex:1.2;text-align:center;padding:10px;border-radius:8px;font-size:14px;cursor:pointer">前往系统更新</button>
        </div>
    </div>`;
    document.body.appendChild(overlay);

    const close = () => { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); };
    const laterBtn = overlay.querySelector('#noticeLaterBtn');
    if (laterBtn) laterBtn.addEventListener('click', close);
    const goBtn = overlay.querySelector('#noticeGoUpdateBtn');
    if (goBtn) goBtn.addEventListener('click', () => { close(); go('system_update'); });
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
}

function showSystemCheckError(msg) {
    if (document.getElementById('sysCheckErrorModal')) return;
    const overlay = document.createElement('div');
    overlay.id = 'sysCheckErrorModal';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.92);display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = `
    <div style="max-width:420px;width:92%;border:1px solid rgba(239,68,68,.4);border-radius:14px;background:var(--card-bg,#1a1a2e);overflow:hidden;box-shadow:0 12px 48px rgba(0,0,0,.6)">
        <div style="padding:28px 24px 16px;text-align:center">
            <div style="width:56px;height:56px;margin:0 auto 14px;border-radius:50%;background:rgba(239,68,68,.18);display:flex;align-items:center;justify-content:center">
                <i class="bi bi-shield-exclamation" style="font-size:28px;color:#f87171"></i>
            </div>
            <h3 style="font-size:18px;margin-bottom:8px;color:var(--text,#e2e8f0)">后台启动失败</h3>
            <p style="font-size:13px;color:var(--text-sub,#94a3b8);line-height:1.7;margin-bottom:0">
                ${esc(msg)}<br><br>
                系统更新组件缺失或损坏，后台无法启动。请联系管理员修复。
            </p>
        </div>
        <div style="padding:0 24px 20px">
            <button class="btn block ghost" id="sysCheckReload" style="display:block;width:100%;text-align:center;padding:12px;border:none;border-radius:8px;font-size:14px;cursor:pointer">重新加载</button>
        </div>
    </div>`;
    document.body.appendChild(overlay);
    const reloadBtn = overlay.querySelector('#sysCheckReload');
    if (reloadBtn) reloadBtn.addEventListener('click', () => location.reload());
}

function compareVersion(a, b) {
    const pa = (a || '0').replace(/^v/i, '').split('.').map(Number);
    const pb = (b || '0').replace(/^v/i, '').split('.').map(Number);
    for (let i = 0; i < 3; i++) {
        const va = pa[i] || 0, vb = pb[i] || 0;
        if (va < vb) return -1;
        if (va > vb) return 1;
    }
    return 0;
}

function showForceUpdateModal(cur, latest, info) {
    if (document.getElementById('forceUpdateModal')) return;

    const downloadUrl = info.download_url || '';
    const sha256 = info.sha256 || '';
    const notes = info.release_notes || [];
    const overlay = document.createElement('div');
    overlay.id = 'forceUpdateModal';

    overlay.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.92);display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = `
    <div style="max-width:460px;width:92%;border:1px solid rgba(239,68,68,.4);border-radius:14px;background:var(--card-bg,#1a1a2e);overflow:hidden;box-shadow:0 12px 48px rgba(0,0,0,.6)">
        <div style="padding:28px 24px 16px;text-align:center">
            <div style="width:56px;height:56px;margin:0 auto 14px;border-radius:50%;background:rgba(239,68,68,.18);display:flex;align-items:center;justify-content:center">
                <i class="bi bi-exclamation-triangle-fill" style="font-size:28px;color:#f87171"></i>
            </div>
            <h3 style="font-size:18px;margin-bottom:8px;color:var(--text,#e2e8f0)">系统需要强制更新</h3>
            <p style="font-size:13px;color:var(--text-sub,#94a3b8);line-height:1.7;margin-bottom:0">
                当前版本 <b style="color:#f87171">v${esc(cur)}</b> 已停止支持，后台功能已被锁定。<br>
                请更新到 <b style="color:#4ade80">v${esc(latest)}</b> 后刷新页面恢复使用。
            </p>
        </div>
        ${notes.length ? `<div style="margin:0 24px 14px;max-height:160px;overflow-y:auto;background:var(--bg-alt,#0f0f1a);border-radius:8px;padding:12px 14px">
            <div style="font-size:12px;color:var(--text-sub,#64748b);margin-bottom:6px">v${esc(latest)} 更新内容</div>
            <ul style="list-style:none;padding:0;margin:0">
                ${notes.map(n => `<li style="padding:2px 0 2px 14px;position:relative;font-size:13px;color:var(--text-sub,#94a3b8);line-height:1.5">
                    <span style="position:absolute;left:2px;top:10px;width:4px;height:4px;border-radius:50%;background:var(--text-faint,#475569)"></span>${esc(n)}</li>`).join('')}
            </ul>
        </div>` : ''}
        <div style="padding:0 24px 20px" id="forceActionArea">
            ${downloadUrl ? `<button class="btn block success" id="forceUpdateBtn" style="display:block;width:100%;text-align:center;padding:12px;margin-bottom:10px;border:none;border-radius:8px;font-size:14px;cursor:pointer">一键更新到 v${esc(latest)}</button>` : '<div style="padding:10px;background:var(--bg-alt,#0f0f1a);border-radius:8px;font-size:13px;color:var(--text-sub,#94a3b8);margin-bottom:10px;text-align:center">请联系管理员获取更新包</div>'}
            <button class="btn ghost block" id="forceRecheckBtn" style="display:block;width:100%;text-align:center">重新检查版本</button>
        </div>
        <div style="padding:10px 24px 16px;border-top:1px solid var(--border,rgba(255,255,255,.08));font-size:12px;color:var(--text-faint,#475569);text-align:center">
            一键更新会自动下载、校验并安装更新包，完成后自动刷新
        </div>
    </div>`;
    document.body.appendChild(overlay);

    overlay.addEventListener('click', e => {

        const tag = e.target.tagName;
        if (tag !== 'BUTTON' && tag !== 'A' && tag !== 'I') {
            e.stopPropagation();
            e.preventDefault();
        }
    });

    const blockKey = e => {

        if (e.key === 'F5' || (e.ctrlKey && e.key === 'r')) return;
        e.preventDefault();
        e.stopPropagation();
    };
    document.addEventListener('keydown', blockKey, { capture: true });

    const blockHash = e => { e.preventDefault(); e.stopPropagation(); };
    window.addEventListener('hashchange', blockHash, { capture: true });

    const recheckBtn = overlay.querySelector('#forceRecheckBtn');
    if (recheckBtn) {
        recheckBtn.addEventListener('click', () => location.reload());
    }

    const updateBtn = overlay.querySelector('#forceUpdateBtn');
    if (updateBtn) {
        updateBtn.addEventListener('click', () => {
            doForceUpdate(info, overlay, updateBtn);
        });
    }
}

async function doForceUpdate(info, overlay, btn) {
    const actionArea = overlay.querySelector('#forceActionArea');
    if (!actionArea) return;

    btn.disabled = true;
    btn.textContent = '正在下载并安装更新...';
    btn.style.opacity = '0.7';

    try {
        const res = await api('system_update_do', {
            download_url: info.download_url,
            sha256: info.sha256,
            version: info.latest_version,
            build: info.latest_build || 0,
        }, true);

        if (res.code === 0) {
            const d = res.data || {};
            actionArea.innerHTML = `
                <div style="text-align:center;padding:20px 0">
                    <div style="width:52px;height:52px;margin:0 auto 12px;border-radius:50%;background:rgba(34,197,94,.15);display:flex;align-items:center;justify-content:center">
                        <i class="bi bi-check-lg" style="font-size:26px;color:#4ade80"></i>
                    </div>
                    <h4 style="font-size:16px;margin-bottom:6px;color:var(--text,#e2e8f0)">更新完成</h4>
                    <p style="font-size:13px;color:var(--text-sub,#94a3b8);line-height:1.6;margin-bottom:16px">
                        已更新 ${d.updated_files || 0} 个文件<br>
                        备份目录：${esc(d.backup_path || '-')}
                    </p>
                    <button class="btn block success" id="forceReloadBtn" style="display:block;width:100%;text-align:center;padding:12px;border:none;border-radius:8px;font-size:14px;cursor:pointer">刷新后台</button>
                </div>`;
            const reloadBtn = actionArea.querySelector('#forceReloadBtn');
            if (reloadBtn) {
                reloadBtn.addEventListener('click', () => location.reload());
            }
        } else {
            btn.disabled = false;
            btn.textContent = `一键更新到 v${info.latest_version}`;
            btn.style.opacity = '1';

            const errTip = overlay.querySelector('#forceErrTip');
            if (errTip) errTip.remove();
            const tip = document.createElement('div');
            tip.id = 'forceErrTip';
            tip.style.cssText = 'padding:8px 12px;border-radius:6px;background:rgba(239,68,68,.12);color:#f87171;font-size:12px;margin-bottom:10px;text-align:center';
            tip.textContent = res.msg || '更新失败';
            actionArea.insertBefore(tip, btn);
        }
    } catch (e) {
        btn.disabled = false;
        btn.textContent = `一键更新到 v${info.latest_version}`;
        btn.style.opacity = '1';
        const errTip = overlay.querySelector('#forceErrTip');
        if (errTip) errTip.remove();
        const tip = document.createElement('div');
        tip.id = 'forceErrTip';
        tip.style.cssText = 'padding:8px 12px;border-radius:6px;background:rgba(239,68,68,.12);color:#f87171;font-size:12px;margin-bottom:10px;text-align:center';
        tip.textContent = '网络错误：' + (e.message || '请求失败');
        actionArea.insertBefore(tip, btn);
    }
}

(async function boot() {

    const mask = document.getElementById('bootMask');
    if (mask) mask.classList.add('hide');

    const lgForm = document.getElementById('lgForm');
    if (lgForm) {
        lgForm.addEventListener('submit', e => {
            e.preventDefault();
            doLogin();
        });
    } else {

        ['lgUser', 'lgPass'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
        });
        const lgBtn = document.getElementById('lgBtn');
        if (lgBtn) lgBtn.addEventListener('click', doLogin);
    }

    const lgCapImg = document.getElementById('lgCaptchaImg');
    if (lgCapImg) {
        const lp = document.getElementById('loginPage');
        if (lp && lp.style.display !== 'none') refreshLoginCaptcha();
        lgCapImg.addEventListener('click', refreshLoginCaptcha);
    }

    const outBtn = document.getElementById('btnLogout');
    if (outBtn) outBtn.addEventListener('click', doLogout);

    initTheme();
    const themeBtn = document.getElementById('btnTheme');
    if (themeBtn) {
        const paintThemeBtn = () => {
            const dark = appliedTheme() === 'dark';
            themeBtn.innerHTML = dark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
            themeBtn.title = dark ? '切换到浅色模式' : '切换到深色模式';
        };
        paintThemeBtn();
        themeBtn.addEventListener('click', () => { toggleTheme(); paintThemeBtn(); });
    }

    window.addEventListener('hashchange', () => {
        if (S.admin) go(currentFromHash());
    });

    const maybeLoggedIn = useCookieSession() || !!S.token;
    if (maybeLoggedIn) {
        try {
            const res = await api('profile', { op: 'get' }, true);
            if (res.code === 0) {
                S.admin = res.data;
                enterApp();
                return;
            }
        } catch (e) {
 }

        setToken('');
        setSessionKey('');
    }

    const lp = document.getElementById('loginPage');
    if (lp) lp.style.display = 'flex';
    refreshLoginCaptcha();

    const userInput = document.getElementById('lgUser');
    if (userInput) userInput.focus();
})();
