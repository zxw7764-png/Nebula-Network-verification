import { api } from '../core/api.js';
import { register } from '../core/router.js';
import { loading, esc, tag } from '../core/util.js';
import { toast, confirmBox } from '../core/ui.js';

register('system_update', render);

async function render() {
    const c = document.getElementById('content');
    c.innerHTML = loading();

    const res = await api('system_update_check', { force: 1 }, true);

    if (res.code !== 0) {
        c.innerHTML = `
        <div class="card">
            <div class="card-head">
                <h3>系统更新</h3>
                <div class="acts">
                    <button class="btn ghost sm" id="suRefresh">重新检查</button>
                </div>
            </div>
            <div class="card-body">
                <div style="padding:16px 20px;border-radius:10px;background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.3);font-size:13px">
                    ${esc(res.msg || '检查更新失败')}
                </div>
            </div>
        </div>
        <div id="licCardWrap" style="margin-top:16px"></div>`;
        document.getElementById('suRefresh').addEventListener('click', () => render());
        renderLicenseCard();
        return;
    }

    const d = res.data;
    const latest = d.latest || {};
    const currentVer = d.current_version || '0.0.0';
    const latestVer = latest.latest_version || currentVer;
    const latestBuild = latest.latest_build || 0;
    const minVersion = latest.min_version || '';
    const channel = latest.channel || 'stable';
    const publishedAt = latest.published_at || '';
    const hasNew = compareVersion(currentVer, latestVer) < 0;
    const isForce = !!(latest.force_update);
    const cached = d.cached;

    const statusBadge = hasNew
        ? (isForce ? tag('需要强制更新', 'red') : tag('发现新版本', 'yellow'))
        : tag('已是最新版本', 'green');


    const releaseNotes = Array.isArray(latest.release_notes) ? latest.release_notes : [];
    const notesHtml = releaseNotes.length
        ? `<div style="background:var(--bg-alt);border-radius:10px;padding:16px 20px;margin:16px 0">
              <h4 style="font-size:14px;margin-bottom:12px;color:var(--text-sub)">v${esc(latestVer)} 更新内容</h4>
              <ul style="list-style:none;padding:0;margin:0">
                  ${releaseNotes.map(n => `<li style="padding:5px 0 5px 20px;position:relative;font-size:13px;color:var(--text-sub);line-height:1.5">
                      <span style="position:absolute;left:4px;top:13px;width:6px;height:6px;border-radius:50%;background:var(--accent)"></span>${esc(n)}</li>`).join('')}
              </ul>
          </div>`
        : `<div style="padding:12px 20px;border-radius:10px;background:var(--bg-alt);font-size:13px;color:var(--text-sub);margin:16px 0">
              暂无更新日志
          </div>`;


    let actionHtml = '';
    if (hasNew && latest.download_url) {
        actionHtml = `
            <div style="display:flex;gap:12px;margin-top:20px;align-items:center;flex-wrap:wrap">
                <button class="btn success" id="suDoUpdate">一键更新到 v${esc(latestVer)}</button>
                <a class="btn ghost sm" href="${esc(latest.download_url)}" target="_blank" style="text-decoration:none">手动下载</a>
                ${latest.sha256 ? `<span class="mono" style="font-size:12px;color:var(--text-sub)">SHA256: ${esc(latest.sha256)}</span>` : ''}
            </div>`;
    } else if (!hasNew) {
        actionHtml = `<div style="padding:12px 20px;border-radius:10px;background:rgba(34,197,94,.12);color:#86efac;border:1px solid rgba(34,197,94,.35);font-size:13px;margin-top:16px;display:flex;align-items:center;gap:8px">
            <i class="bi bi-check-circle" style="font-size:16px"></i> 当前已是最新版本，无需更新。
        </div>`;
    }


    const forceAlert = isForce ? `
        <div style="padding:14px 20px;border-radius:10px;background:rgba(239,68,68,.12);color:#fca5a5;border:1px solid rgba(239,68,68,.35);font-size:13px;margin-bottom:16px;display:flex;align-items:flex-start;gap:10px">
            <i class="bi bi-exclamation-triangle" style="font-size:16px;flex-shrink:0;color:#f87171"></i>
            <div>
                <b>当前版本已停止支持</b><br>
                必须更新到 v${esc(latestVer)} 后才能继续使用。最低支持版本为 v${esc(minVersion)}。
            </div>
        </div>` : '';


    const minVersionHint = (hasNew && minVersion && !isForce && compareVersion(currentVer, minVersion) < 0)
        ? `<div style="padding:10px 16px;border-radius:8px;background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.2);font-size:12px;margin-top:8px">
            当前版本低于最低支持版本 v${esc(minVersion)}，更新后将获得更好的体验。
        </div>` : '';

    c.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>系统更新 ${statusBadge}</h3>
            <div class="acts">
                <button class="btn ghost sm" id="suRefresh">重新检查</button>
            </div>
        </div>
        <div class="card-body">
            ${forceAlert}

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
                <div style="border:1px solid var(--border);border-radius:10px;padding:20px">
                    <div style="font-size:13px;color:var(--text-sub);margin-bottom:8px">当前版本</div>
                    <div style="font-size:24px;font-weight:600">v${esc(currentVer)}</div>
                    <div style="font-size:13px;color:var(--text-sub);margin-top:4px">Nebula 网络验证系统</div>
                </div>
                <div style="border:1px solid var(--border);border-radius:10px;padding:20px">
                    <div style="font-size:13px;color:var(--text-sub);margin-bottom:8px">最新版本</div>
                    <div style="font-size:24px;font-weight:600">v${esc(latestVer)}</div>
                    <div style="font-size:13px;color:var(--text-sub);margin-top:4px">
                        Build ${esc(latestBuild)} · ${esc(channel)} · ${esc(publishedAt)}
                    </div>
                </div>
            </div>

            ${minVersionHint}

            ${notesHtml}

            ${actionHtml}

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);font-size:12px;color:var(--text-faint)">
                版本检查由在线版本更新系统提供。每次检查间隔 6 小时，点击「重新检查」可强制刷新。<br>
                一键更新会自动下载、校验、备份并安装更新包，无需手动上传。
            </div>
        </div>
    </div>
    <div id="licCardWrap" style="margin-top:16px"></div>`;

    document.getElementById('suRefresh').addEventListener('click', () => render());
    renderLicenseCard();


    const doBtn = document.getElementById('suDoUpdate');
    if (doBtn) {
        doBtn.addEventListener('click', () => {
            doUpdate(latest, doBtn);
        });
    }
}



async function doUpdate(latest, btn) {
    confirmBox('一键更新', `将自动下载并安装 v${latest.latest_version}，更新期间后台将暂时不可用。确定继续吗？`, async () => {

        btn.disabled = true;
        btn.textContent = '正在下载并安装...';
        btn.style.opacity = '0.7';

        try {
            const res = await api('system_update_do', {
                download_url: latest.download_url,
                sha256: latest.sha256,
                version: latest.latest_version,
                build: latest.latest_build || 0,
            }, true);

            if (res.code === 0) {
                const d = res.data || {};
                toast(`更新完成！已更新 ${d.updated_files || 0} 个文件`, 'ok');


                const c = document.getElementById('content');
                c.innerHTML = `
                <div class="card">
                    <div class="card-head">
                        <h3>更新完成</h3>
                    </div>
                    <div class="card-body" style="text-align:center;padding:40px 20px">
                        <div style="width:64px;height:64px;margin:0 auto 16px;border-radius:50%;background:rgba(34,197,94,.15);display:flex;align-items:center;justify-content:center">
                            <i class="bi bi-check-lg" style="font-size:32px;color:#4ade80"></i>
                        </div>
                        <h3 style="font-size:18px;margin-bottom:8px">系统已成功更新到 v${esc(d.new_version || latest.latest_version)}</h3>
                        <p style="font-size:13px;color:var(--text-sub);margin-bottom:20px">
                            更新了 ${d.updated_files || 0} 个文件，备份了 ${d.backup_files || 0} 个文件。<br>
                            备份目录：${esc(d.backup_path || '-')}
                        </p>
                        <button class="btn success" id="suReload">刷新后台</button>
                    </div>
                </div>`;
                document.getElementById('suReload').addEventListener('click', () => location.reload());
            } else {
                btn.disabled = false;
                btn.textContent = `一键更新到 v${latest.latest_version}`;
                btn.style.opacity = '1';
                toast(res.msg || '更新失败', 'err');
            }
        } catch (e) {
            btn.disabled = false;
            btn.textContent = `一键更新到 v${latest.latest_version}`;
            btn.style.opacity = '1';
            toast('更新请求失败：' + (e.message || '网络错误'), 'err');
        }
    });
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


// ------------------------------------------------------------------
// 授权激活卡片：查看当前授权状态 / 重新填写激活（过期、换码、补激活）
// ------------------------------------------------------------------
async function renderLicenseCard() {
    const wrap = document.getElementById('licCardWrap');
    if (!wrap) return;
    const st = await api('license_manage', { op: 'status' }, true);
    const d = (st.code === 0 && st.data) ? st.data : { configured: false, masked: '', domain: '' };

    wrap.innerHTML = `
    <div class="card">
        <div class="card-head">
            <h3>授权激活 ${d.configured ? tag('已配置', 'green') : tag('未激活', 'yellow')}</h3>
        </div>
        <div class="card-body">
            <div style="font-size:13px;color:var(--text-sub);margin-bottom:12px">
                ${d.configured
                    ? `当前授权码：<span class="mono">${esc(d.masked)}</span> · 绑定域名：<span class="mono">${esc(d.domain || '-')}</span>`
                    : '尚未配置授权激活码。未激活不影响系统使用，但后续开启更新门禁后将无法在线获取新版本。'}
                授权过期或更换授权码时，在此重新填写即可完成激活（绑定当前域名）。
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <input id="licKeyInput" class="mono" placeholder="32 位授权码" maxlength="32"
                       oninput="this.value=this.value.toLowerCase().replace(/[^0-9a-f]/g,'')"
                       style="flex:1;min-width:220px;background:var(--bg-alt);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text)">
                <button class="btn success sm" id="licActivateBtn">激活 / 重新激活</button>
            </div>
        </div>
    </div>`;

    document.getElementById('licActivateBtn').addEventListener('click', async (ev) => {
        const btn = ev.currentTarget;
        const key = (document.getElementById('licKeyInput').value || '').trim();
        if (!key) { toast('请输入授权码', 'err'); return; }
        btn.disabled = true; btn.textContent = '激活中...';
        try {
            const r = await api('license_manage', { op: 'activate', license_key: key }, true);
            if (r.code === 0) {
                toast(r.msg || '激活成功', 'ok');
                renderLicenseCard();
                // 激活后刷新更新检查结果
                render();
            } else {
                btn.disabled = false; btn.textContent = '激活 / 重新激活';
                toast(r.msg || '激活失败', 'err');
            }
        } catch (e) {
            btn.disabled = false; btn.textContent = '激活 / 重新激活';
            toast('激活请求失败：' + (e.message || '网络错误'), 'err');
        }
    });
}
