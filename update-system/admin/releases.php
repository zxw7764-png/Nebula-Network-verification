<?php
/**
 * 后台版本管理页面
 * ==================================================================
 * 发布 / 编辑 / 下架 / 删除版本。
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// 入口令牌守卫：无令牌一律 404（不暴露后台位置）
AdminAuth::entryGuard();

$admin = AdminAuth::check();
if (!$admin) {
    header('Location: login.php');
    exit;
}

$csrf = $admin['csrf'];
$cv = VersionManager::getCurrentVersion();
$nickname = $admin['nickname'] ?? $admin['username'];
$avatarChar = mb_strtoupper(mb_substr($nickname, 0, 1));
$ver = APP_VERSION;
$appName = APP_NAME;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>版本管理 - <?= htmlspecialchars($appName) ?></title>
<link rel="stylesheet" href="assets/admin.css?v=<?= htmlspecialchars($ver) ?>">
</head>
<body>
<div class="layout">
    <!-- 侧边栏 -->
    <aside class="sidebar">
        <div class="brand">
            <div class="logo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polyline>
                </svg>
            </div>
            <div class="bt">
                <div class="t1"><?= htmlspecialchars($appName) ?></div>
                <div class="t2">管理后台</div>
            </div>
        </div>
        <nav class="nav">
            <a href="dashboard.php" class="nav-item">
                <span class="icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                </span><span>数据概览</span>
            </a>
            <a href="releases.php" class="nav-item active">
                <span class="icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line>
                    </svg>
                </span><span>版本管理</span>
            </a>
            <a href="licenses.php" class="nav-item">
                <span class="icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </span><span>授权管理</span>
            </a>
        </nav>
        <div class="foot">
            <div>v<?= htmlspecialchars($ver) ?></div>
            <div class="ver">Build <?= (int)$cv['build'] ?></div>
        </div>
    </aside>

    <!-- 主区域 -->
    <div class="main">
        <div class="topbar">
            <div class="page-title">版本管理</div>
            <div class="topbar-right">
                <div class="user-chip">
                    <div class="avatar"><?= htmlspecialchars($avatarChar) ?></div>
                    <span><?= htmlspecialchars($nickname) ?></span>
                    <a href="#" onclick="return doLogout()">退出</a>
                </div>
            </div>
        </div>

        <div class="content-area">
            <div class="card">
                <div class="card-head">
                    <h3>版本发布管理</h3>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button class="btn btn-success btn-sm" onclick="batchOp('on')">批量上架</button>
                        <button class="btn btn-ghost btn-sm" onclick="batchOp('off')">批量下架</button>
                        <button class="btn btn-danger btn-sm" onclick="batchOp('delete')">批量删除</button>
                        <button class="btn btn-primary btn-sm" onclick="openCreateModal()">发布新版本</button>
                    </div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:0 16px 14px;font-size:13px">
                    <select id="fltChannel" onchange="loadReleases()" style="padding:7px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:var(--card-bg,#1a1f2e);color:var(--text,#e8eaf0)">
                        <option value="">全部通道</option>
                        <option value="stable">stable</option>
                        <option value="beta">beta</option>
                        <option value="dev">dev</option>
                    </select>
                    <select id="fltStatus" onchange="loadReleases()" style="padding:7px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:var(--card-bg,#1a1f2e);color:var(--text,#e8eaf0)">
                        <option value="">全部状态</option>
                        <option value="1">已上架</option>
                        <option value="0">已下架</option>
                    </select>
                    <input id="fltKeyword" placeholder="按版本号搜索，如 2.66" onkeydown="if(event.key==='Enter')loadReleases()"
                           style="padding:7px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:var(--card-bg,#1a1f2e);color:var(--text,#e8eaf0);width:180px">
                    <button class="btn btn-ghost btn-sm" onclick="loadReleases()">筛选</button>
                    <button class="btn btn-ghost btn-sm" onclick="resetFilter()">重置</button>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="relChkAll" onclick="relChkAll(this)"></th>
                            <th>ID</th>
                            <th>版本号</th>
                            <th>Build</th>
                            <th>通道</th>
                            <th>最低版本</th>
                            <th>状态</th>
                            <th>发布时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="releasesBody">
                        <tr><td colspan="8" class="empty">加载中...</td></tr>
                    </tbody>
                </table>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;font-size:13px;color:var(--text-sub,#8b93a7)">
                    <span id="relPageInfo">共 0 条</span>
                    <div style="display:flex;gap:8px">
                        <button class="btn btn-ghost btn-sm" id="relPrev" onclick="relPage(-1)">上一页</button>
                        <button class="btn btn-ghost btn-sm" id="relNext" onclick="relPage(1)">下一页</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 发布/编辑弹窗 -->
<div class="modal-overlay" id="releaseModal">
    <div class="modal">
        <div class="modal-head">
            <span id="modalTitle">发布新版本</span>
            <a href="#" class="close" onclick="closeModal();return false">&times;</a>
        </div>
        <div class="modal-body">
            <input type="hidden" id="fId">
            <div class="form-row">
                <div class="form-group">
                    <label>版本号 <span class="req">*</span></label>
                    <input id="fVersion" placeholder="如 1.0.0" oninput="filterVersion(this)">
                    <div class="hint">SemVer 格式：MAJOR.MINOR.PATCH</div>
                </div>
                <div class="form-group">
                    <label>Build 编号</label>
                    <input id="fBuild" type="number" placeholder="如 2026010101" oninput="filterNum(this)">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>最低支持版本</label>
                    <input id="fMinVersion" placeholder="如 1.0.0" value="1.0.0" oninput="filterVersion(this)">
                    <div class="hint">低于此版本将触发强制更新</div>
                </div>
                <div class="form-group">
                    <label>通道</label>
                    <select id="fChannel">
                        <option value="stable">stable（稳定版）</option>
                        <option value="beta">beta（测试版）</option>
                        <option value="dev">dev（开发版）</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Schema 版本</label>
                    <input id="fSchemaVersion" type="number" value="1" oninput="filterNum(this)">
                </div>
                <div class="form-group">
                    <label>状态</label>
                    <select id="fStatus">
                        <option value="1">已发布（上架）</option>
                        <option value="0">下架</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>更新包 <span class="req">*</span></label>
                <input type="file" id="fPackage" accept=".zip">
                <div class="hint">上传 ZIP 更新包，保存后自动计算 SHA-256 并生成下载地址</div>
                <div class="hint" id="fDownloadHint" style="margin-top:4px;word-break:break-all"></div>
                <input type="hidden" id="fDownloadUrl">
            </div>
            <div class="form-group">
                <label>SHA-256</label>
                <input id="fSha256" placeholder="更新包 SHA-256 哈希值" class="font-mono">
            </div>
            <div class="form-group">
                <label>数字签名（Base64）</label>
                <textarea id="fSignature" placeholder="Ed25519 签名（可选）" style="min-height:50px"></textarea>
            </div>
            <div class="form-group">
                <label>更新日志</label>
                <textarea id="fReleaseNotes" placeholder="每行一条更新内容&#10;如：修复初始化失败问题&#10;优化网络请求性能"></textarea>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeModal()">取消</button>
            <button class="btn btn-primary" id="btnSave" onclick="saveRelease()">保存</button>
        </div>
    </div>
</div>

<script src="assets/admin.js?v=<?= htmlspecialchars($ver) ?>"></script>
<script>
var CSRF_TOKEN = '<?= htmlspecialchars($csrf) ?>';
var CURRENT_VERSION = '<?= htmlspecialchars($cv['version']) ?>';

function api(op, data) {
    data = data || {}; data.op = op;
    if (op !== 'list') data.csrf = CSRF_TOKEN;
    return fetch('handlers/releases.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    }).then(function(r) { return r.json(); });
}

var REL_PAGE = 1, REL_SIZE = 15, REL_TOTAL = 0;

function relChkAll(master) {
    document.querySelectorAll('.rel-chk').forEach(function(c){ c.checked = master.checked; });
}
function relCheckedIds() {
    return Array.prototype.map.call(document.querySelectorAll('.rel-chk:checked'), function(c){ return parseInt(c.value, 10); });
}
function batchOp(act) {
    var ids = relCheckedIds();
    if (!ids.length) { toastErr('请先勾选要操作的版本'); return; }
    if (act === 'delete' && !confirm('确定删除选中的 ' + ids.length + ' 个版本？不可撤销。')) return;
    api('batch', { action: act, ids: ids }).then(function(res) {
        if (res.code === 0) { loadReleases(); toastOk(res.msg || '操作成功'); }
        else toastErr(res.msg || '操作失败');
    }).catch(function() { toastErr('网络错误'); });
}
function relPage(delta) {
    var maxPage = Math.max(1, Math.ceil(REL_TOTAL / REL_SIZE));
    var np = REL_PAGE + delta;
    if (np < 1 || np > maxPage) return;
    REL_PAGE = np;
    loadReleases();
}
function resetFilter() {
    document.getElementById('fltChannel').value = '';
    document.getElementById('fltStatus').value = '';
    document.getElementById('fltKeyword').value = '';
    REL_PAGE = 1;
    loadReleases();
}

function loadReleases() {
    var data = {
        page: REL_PAGE, size: REL_SIZE,
        channel: document.getElementById('fltChannel').value,
        status: document.getElementById('fltStatus').value,
        keyword: document.getElementById('fltKeyword').value.trim()
    };
    api('list', data).then(function(res) {
        var body = document.getElementById('releasesBody');
        var info = document.getElementById('relPageInfo');
        if (res.code !== 0 || !res.data || !res.data.list || !res.data.list.length) {
            body.innerHTML = '<tr><td colspan="9" class="empty">没有符合条件的版本记录</td></tr>';
            if (info) info.textContent = '共 0 条';
            REL_TOTAL = 0;
            return;
        }
        REL_TOTAL = parseInt(res.data.total, 10) || 0;
        if (info) {
            var maxPage = Math.max(1, Math.ceil(REL_TOTAL / REL_SIZE));
            info.textContent = '共 ' + REL_TOTAL + ' 条 · 第 ' + REL_PAGE + ' / ' + maxPage + ' 页';
        }
        var html = '';
        for (var i = 0; i < res.data.list.length; i++) {
            var r = res.data.list[i];
            var isForce = r.min_version && r.min_version !== '1.0.0' && r.min_version !== r.version;
            var stActive = parseInt(r.status) === 1;
            html += '<tr>' +
                '<td><input type="checkbox" class="rel-chk" value="' + r.id + '"></td>' +
                '<td class="text-muted">' + r.id + '</td>' +
                '<td><b>v' + esc(r.version) + '</b></td>' +
                '<td class="text-muted">' + (r.build || '-') + '</td>' +
                '<td><span class="tag tag-gray">' + esc(r.channel) + '</span></td>' +
                '<td>' + esc(r.min_version || '-') + (isForce ? ' <span class="tag tag-red">强制</span>' : '') + '</td>' +
                '<td><span class="tag ' + (stActive ? 'tag-green' : 'tag-gray') + '">' + (stActive ? '已上架' : '已下架') + '</span></td>' +
                '<td class="text-sm text-muted">' + esc(r.published_at || '-') + '</td>' +
                '<td>' +
                    '<button class="btn btn-ghost btn-sm" onclick="editRelease(' + r.id + ')">编辑</button> ' +
                    '<button class="btn btn-ghost btn-sm" onclick="toggleRelease(' + r.id + ')">' + (stActive ? '下架' : '上架') + '</button> ' +
                    '<button class="btn btn-danger btn-sm" onclick="deleteRelease(' + r.id + ',\'v' + esc(r.version) + '\')">删除</button>' +
                '</td>' +
            '</tr>';
        }
        body.innerHTML = html;
    }).catch(function() {
        document.getElementById('releasesBody').innerHTML = '<tr><td colspan="8" class="empty">加载失败，请刷新重试</td></tr>';
    });
}

function openCreateModal() {
    document.getElementById('modalTitle').textContent = '发布新版本';
    document.getElementById('fId').value = '';
    document.getElementById('fVersion').value = '';
    document.getElementById('fBuild').value = '';
    document.getElementById('fMinVersion').value = '1.0.0';
    document.getElementById('fChannel').value = 'stable';
    document.getElementById('fSchemaVersion').value = '1';
    document.getElementById('fStatus').value = '1';
    document.getElementById('fPackage').value = '';
    document.getElementById('fDownloadUrl').value = '';
    document.getElementById('fDownloadHint').textContent = '';
    document.getElementById('fSha256').value = '';
    document.getElementById('fSignature').value = '';
    document.getElementById('fReleaseNotes').value = '';
    document.getElementById('releaseModal').classList.add('show');
    setTimeout(function() { document.getElementById('fVersion').focus(); }, 100);
}

function editRelease(id) {
    api('get', { id: id }).then(function(res) {
        if (res.code !== 0 || !res.data) { toastErr(res.msg || '获取版本信息失败'); return; }
        var r = res.data;
        document.getElementById('modalTitle').textContent = '编辑版本 v' + r.version;
        document.getElementById('fId').value = r.id;
        document.getElementById('fVersion').value = r.version;
        document.getElementById('fBuild').value = r.build;
        document.getElementById('fMinVersion').value = r.min_version;
        document.getElementById('fChannel').value = r.channel;
        document.getElementById('fSchemaVersion').value = r.schema_version;
        document.getElementById('fStatus').value = r.status;
        document.getElementById('fPackage').value = '';
        document.getElementById('fDownloadUrl').value = r.download_url || '';
        document.getElementById('fDownloadHint').textContent = r.download_url ? ('当前下载地址：' + (r.download_url || '')) : '';
        document.getElementById('fSha256').value = r.sha256 || '';
        document.getElementById('fSignature').value = r.signature || '';
        var notes = r.release_notes_arr || [];
        if (typeof notes === 'string') { try { notes = JSON.parse(notes) || []; } catch(e) { notes = []; } }
        document.getElementById('fReleaseNotes').value = Array.isArray(notes) ? notes.join('\n') : '';
        document.getElementById('releaseModal').classList.add('show');
    }).catch(function() { toastErr('网络错误'); });
}

function closeModal() {
    document.getElementById('releaseModal').classList.remove('show');
}

function saveRelease() {
    var id = document.getElementById('fId').value;
    var pkgFile = document.getElementById('fPackage').files[0] || null;
    var notes = document.getElementById('fReleaseNotes').value.split('\n').map(function(s) { return s.trim(); }).filter(function(s) { return s !== ''; });

    if (!document.getElementById('fVersion').value.trim()) { toastWarn('请输入版本号'); return; }
    // 新建必须上传包；编辑未重传时保留原 download_url / SHA-256
    if (!id && !pkgFile) { toastWarn('请选择更新包 ZIP 文件'); return; }

    var fd = new FormData();
    fd.append('op', id ? 'update' : 'create');
    fd.append('csrf', CSRF_TOKEN);
    if (id) fd.append('id', parseInt(id));
    fd.append('version', document.getElementById('fVersion').value.trim());
    fd.append('build', parseInt(document.getElementById('fBuild').value) || 0);
    fd.append('min_version', document.getElementById('fMinVersion').value.trim());
    fd.append('channel', document.getElementById('fChannel').value);
    fd.append('schema_version', parseInt(document.getElementById('fSchemaVersion').value) || 1);
    fd.append('status', parseInt(document.getElementById('fStatus').value));
    fd.append('download_url', document.getElementById('fDownloadUrl').value.trim());
    fd.append('sha256', document.getElementById('fSha256').value.trim());
    fd.append('signature', document.getElementById('fSignature').value.trim());
    fd.append('release_notes', JSON.stringify(notes));
    if (pkgFile) fd.append('update_file', pkgFile);

    var btn = document.getElementById('btnSave');
    btn.disabled = true; btn.textContent = '上传保存中...';

    fetch('handlers/releases.php', {
        method: 'POST', body: fd
    }).then(function(r) { return r.json(); }).then(function(res) {
        btn.disabled = false; btn.textContent = '保存';
        if (res.code === 0) {
            closeModal();
            loadReleases();
            toastOk(res.msg || (id ? '版本已更新' : '版本已发布'));
        } else {
            toastErr(res.msg || '操作失败');
        }
    }).catch(function() {
        btn.disabled = false; btn.textContent = '保存';
        toastErr('网络错误');
    });
}

function toggleRelease(id) {
    api('toggle', { id: id }).then(function(res) {
        if (res.code === 0) { loadReleases(); toastOk(res.msg || '操作成功'); }
        else toastErr(res.msg || '操作失败');
    }).catch(function() { toastErr('网络错误'); });
}

function deleteRelease(id, ver) {
    confirmBox('删除版本', '确定要删除版本 ' + ver + ' 吗？此操作不可撤销。', function() {
        api('delete', { id: id }).then(function(res) {
            if (res.code === 0) { loadReleases(); toastOk(res.msg || '已删除'); }
            else toastErr(res.msg || '删除失败');
        }).catch(function() { toastErr('网络错误'); });
    });
}

function doLogout() {
    fetch('handlers/update.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ op: 'logout', csrf: CSRF_TOKEN })
    }).then(function() { location.href = 'login.php'; });
    return false;
}

function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
function filterVersion(el) { el.value = el.value.replace(/[^0-9.]/g, ''); }
function filterNum(el) { el.value = el.value.replace(/[^0-9]/g, ''); }

loadReleases();
</script>
</body>
</html>
