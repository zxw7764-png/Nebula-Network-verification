<?php
/**
 * 后台授权管理页面（v1.2.0）
 * ==================================================================
 * 授权码签发 / 停用 / 换绑 / 续期，及更新门禁开关。
 */

require_once __DIR__ . '/../lib/bootstrap.php';

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

// 门禁开关当前状态
$gateOn = '0';
try {
    $row = DB::one("SELECT svalue FROM " . DB::t('settings') . " WHERE skey = 'license_gate'");
    $gateOn = ($row && trim((string) $row['svalue']) === '1') ? '1' : '0';
} catch (Throwable $e) { /* 表未建时默认关闭 */ }

// 试用授权配置（trial_days / trial_daily_limit / trial_pow_difficulty）
$trialDays = 7; $trialLimit = 3; $trialDiff = 4;
try {
    $rows = DB::all("SELECT skey, svalue FROM " . DB::t('settings') . " WHERE skey IN ('trial_days','trial_daily_limit','trial_pow_difficulty')");
    foreach ($rows as $r) {
        if ($r['skey'] === 'trial_days') $trialDays = max(1, (int) $r['svalue']);
        if ($r['skey'] === 'trial_daily_limit') $trialLimit = max(0, (int) $r['svalue']);
        if ($r['skey'] === 'trial_pow_difficulty') $trialDiff = min(6, max(1, (int) $r['svalue']));
    }
} catch (Throwable $e) { /* 用默认值 */ }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>授权管理 - <?= htmlspecialchars($appName) ?></title>
<link rel="stylesheet" href="assets/admin.css?v=<?= htmlspecialchars($ver) ?>">
<style>
.filter-bar { display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:12px 16px;background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.06); }
.filter-bar select, .filter-bar input[type="text"] { background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:8px;padding:6px 10px;color:var(--text,#e8eaf0);font-size:13px; }
.filter-bar input[type="text"] { width:180px; }
.filter-info { font-size:12px;color:#8b93a7;margin-left:auto; }
.pagination { display:flex;align-items:center;justify-content:center;gap:6px;padding:14px 0 6px;border-top:1px solid rgba(255,255,255,.06);margin-top:8px; }
.btn-page { padding:5px 12px;font-size:13px;border-radius:6px;cursor:pointer;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:var(--text); }
.btn-page:disabled { opacity:.4;cursor:default; }
.btn-page.active { background:#7c5cff;border-color:#7c5cff;color:#fff; }
.page-info { font-size:12px;color:#8b93a7;margin:0 8px; }
</style>
</head>
<body>
<div class="layout">
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
            <a href="releases.php" class="nav-item">
                <span class="icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line>
                    </svg>
                </span><span>版本管理</span>
            </a>
            <a href="licenses.php" class="nav-item active">
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

    <div class="main">
        <div class="topbar">
            <div class="page-title">授权管理</div>
            <div class="topbar-right">
                <div class="user-chip">
                    <div class="avatar"><?= htmlspecialchars($avatarChar) ?></div>
                    <span><?= htmlspecialchars($nickname) ?></span>
                    <a href="#" onclick="return doLogout()">退出</a>
                </div>
            </div>
        </div>

        <div class="content-area">
            <div class="card" style="margin-bottom:16px">
                <div class="card-head">
                    <h3>更新门禁（license_gate）</h3>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" id="gateSwitch" <?= $gateOn === '1' ? 'checked' : '' ?> onchange="toggleGate(this.checked)">
                        <span id="gateText"><?= $gateOn === '1' ? '已开启：下发更新包需有效授权' : '已关闭：所有部署均可获取更新（兼容旧客户端）' ?></span>
                    </label>
                </div>
                <div class="hint" style="padding:0 16px 12px">
                    开启后，部署站调用 version.php 必须携带有效授权码 + 绑定域名才能获取下载地址（限时签名链接）。
                    存量未升级的老站将无法在线更新 —— 建议先让部署站升级到支持授权的版本，再开启门禁。
                </div>
            </div>

            <div class="card" style="margin-bottom:16px">
                <div class="card-head">
                    <h3>试用授权配置（门户「免费获取试用授权码」）</h3>
                    <button class="btn btn-primary btn-sm" onclick="saveTrialConf()">保存配置</button>
                </div>
                <div style="display:flex;gap:16px;padding:0 16px 14px;flex-wrap:wrap;align-items:center">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px">
                        有效期
                        <input type="number" id="trialDays" value="<?= (int)$trialDays ?>" min="1" max="3650" style="width:90px;background:var(--card-bg,#1a1f2e);border:1px solid rgba(255,255,255,.14);border-radius:8px;padding:7px 10px;color:var(--text,#e8eaf0)">
                        天
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px">
                        每人每日限领
                        <input type="number" id="trialLimit" value="<?= (int)$trialLimit ?>" min="0" max="100" style="width:90px;background:var(--card-bg,#1a1f2e);border:1px solid rgba(255,255,255,.14);border-radius:8px;padding:7px 10px;color:var(--text,#e8eaf0)">
                        枚（0 = 关闭发放）
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px">
                        人机验证难度
                        <input type="number" id="trialDiff" value="<?= (int)$trialDiff ?>" min="1" max="6" style="width:90px;background:var(--card-bg,#1a1f2e);border:1px solid rgba(255,255,255,.14);border-radius:8px;padding:7px 10px;color:var(--text,#e8eaf0)">
                        （1-6，越大越难刷，4 约 0.1-1 秒）
                    </label>
                </div>
                <div class="hint" style="padding:0 16px 12px">
                    试用授权码需填写部署域名并绑定（一域一枚），领取时须通过人机验证；同域名已存在授权则不可重复领取。
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h3>授权码</h3>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button class="btn btn-success btn-sm" onclick="batchOp('enable')">批量启用</button>
                        <button class="btn btn-ghost btn-sm" onclick="batchOp('disable')">批量停用</button>
                        <button class="btn btn-danger btn-sm" onclick="batchOp('delete')">批量删除</button>
                        <button class="btn btn-primary btn-sm" onclick="openCreateModal()">批量签发</button>
                    </div>
                </div>
                <!-- 筛选栏 -->
                <div class="filter-bar">
                    <select id="filterChannel" onchange="reloadFiltered()">
                        <option value="">全部通道</option>
                        <option value="standard">standard（标准版）</option>
                        <option value="pro">pro（专业版）</option>
                        <option value="trial">trial（试用）</option>
                    </select>
                    <select id="filterStatus" onchange="reloadFiltered()">
                        <option value="-1">全部状态</option>
                        <option value="1">启用</option>
                        <option value="0">停用</option>
                    </select>
                    <input type="text" id="filterKeyword" placeholder="搜索授权码 / 域名 / 备注" onkeydown="if(event.key==='Enter') reloadFiltered()">
                    <button class="btn btn-ghost btn-sm" onclick="resetFilter()">重置</button>
                    <span class="filter-info" id="filterInfo"></span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="chkAll" onclick="chkAll(this)"></th>
                            <th>ID</th>
                            <th>授权码</th>
                            <th>绑定域名</th>
                            <th>档位</th>
                            <th>到期</th>
                            <th>状态</th>
                            <th>最近验签</th>
                            <th>备注</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="licBody">
                        <tr><td colspan="10" class="empty">加载中...</td></tr>
                    </tbody>
                </table>
                <!-- 分页控件 -->
                <div class="pagination" id="pagination"></div>
            </div>
        </div>
    </div>
</div>

<!-- 门禁确认弹窗 -->
<div class="modal-overlay" id="gateModal">
    <div class="modal" style="width:min(440px,92%)">
        <div class="modal-head">
            <span id="gateTitle">确认操作</span>
            <a href="#" class="close" onclick="gateHide(false);return false">&times;</a>
        </div>
        <div class="modal-body">
            <p id="gateModalText" style="margin:0;font-size:13.5px;line-height:1.8;color:var(--text,#c6cddb)"></p>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="gateHide(false)">取消</button>
            <button class="btn btn-primary" id="gateBtn" onclick="gateHide(true)">确认</button>
        </div>
    </div>
</div>

<!-- 通用确认弹窗 -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal" style="width:min(420px,92%)">
        <div class="modal-head"><span id="confirmTitle">确认</span><a href="#" class="close" onclick="confirmHide(false);return false">&times;</a></div>
        <div class="modal-body"><p id="confirmText" style="margin:0;font-size:13.5px;line-height:1.8;color:var(--text,#c6cddb)"></p></div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="confirmHide(false)">取消</button>
            <button class="btn btn-primary" id="confirmBtn" onclick="confirmHide(true)">确认</button>
        </div>
    </div>
</div>

<!-- prompt 弹窗 -->
<div class="modal-overlay" id="promptModal">
    <div class="modal" style="width:min(440px,92%)">
        <div class="modal-head"><span id="promptTitle">输入</span><a href="#" class="close" onclick="promptHide(null);return false">&times;</a></div>
        <div class="modal-body"><input id="promptInput" class="prompt-input" type="text" placeholder="" autocomplete="off" style="width:100%;background:rgba(124,92,255,.1);border:1px solid rgba(124,92,255,.4);border-radius:10px;padding:10px 14px;color:#c4b5fd;font-size:14px;font-family:ui-monospace,Consolas,monospace;letter-spacing:1px;box-sizing:border-box;"></div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="promptHide(null)">取消</button>
            <button class="btn btn-primary" id="promptBtn" onclick="promptHide(document.getElementById('promptInput').value)">确定</button>
        </div>
    </div>
</div>

<!-- 签发弹窗 -->
<div class="modal-overlay" id="licModal">
    <div class="modal">
        <div class="modal-head">
            <span>批量签发授权码</span>
            <a href="#" class="close" onclick="closeModal();return false">&times;</a>
        </div>
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group">
                    <label>数量 <span class="req">*</span></label>
                    <input id="fCount" type="number" value="1" min="1" max="100">
                </div>
                <div class="form-group">
                    <label>有效期（天，0=永久）</label>
                    <input id="fDays" type="number" value="0" min="0" max="3650">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>档位</label>
                    <select id="fPlan">
                        <option value="standard">standard（标准版）</option>
                        <option value="pro">pro（专业版）</option>
                        <option value="trial">trial（试用）</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>备注</label>
                    <input id="fNote" placeholder="客户名 / 订单号">
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeModal()">取消</button>
            <button class="btn btn-primary" id="btnSave" onclick="createLic()">签发</button>
        </div>
    </div>
</div>

<script src="assets/admin.js?v=<?= htmlspecialchars($ver) ?>"></script>
<script>
var CSRF_TOKEN = '<?= htmlspecialchars($csrf) ?>';
var _filterState = { channel: '', status: -1, keyword: '', page: 1 };

function api(op, data) {
    data = data || {}; data.op = op;
    if (op !== 'list' && op !== 'get') data.csrf = CSRF_TOKEN;
    return fetch('handlers/licenses.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    }).then(function(r) { return r.json(); });
}

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function fmtTime(ts) {
    ts = parseInt(ts || 0, 10);
    if (!ts) return '—';
    var d = new Date(ts * 1000);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

// ---- 加载列表（带筛选 + 分页）----
function loadList() {
    var params = Object.assign({ page: _filterState.page }, _filterState);
    delete params.page;
    api('list', params).then(function(r) {
        if (r.code !== 0) { showConfirm('加载失败', r.msg || '未知错误'); return; }
        var d = r.data, rows = d.list;
        var html = '';
        if (!rows.length) {
            html = '<tr><td colspan="10" class="empty">暂无授权码，点击右上角「批量签发」</td></tr>';
        }
        for (var i = 0; i < rows.length; i++) {
            var it = rows[i];
            var expired = it.expires_at > 0 && it.expires_at < Math.floor(Date.now() / 1000);
            var act = parseInt(it.status) === 1;
            html += '<tr>'
                + '<td><input type="checkbox" class="lic-chk" value="' + it.id + '"></td>'
                + '<td>' + it.id + '</td>'
                + '<td class="font-mono">' + esc(it.license_key) + ' <a href="#" onclick="copyKeyById(' + it.id + ');return false">复制</a></td>'
                + '<td>' + (it.domain ? esc(it.domain) : '<span style="color:var(--warn,#c80)">未绑定</span>') + '</td>'
                + '<td>' + esc(it.plan) + '</td>'
                + '<td>' + (parseInt(it.expires_at) > 0 ? fmtTime(it.expires_at) + (expired ? ' <b style="color:#c33">已过期</b>' : '') : '永久') + '</td>'
                + '<td>' + (act ? '<b style="color:#1D9E75">启用</b>' : '停用') + '</td>'
                + '<td>' + (it.last_check_at > 0 ? fmtTime(it.last_check_at) : '—') + '</td>'
                + '<td>' + esc(it.note || '') + '</td>'
                + '<td><div style="display:flex;gap:6px;flex-wrap:wrap">'
                + '<button class="btn btn-ghost btn-sm" style="padding:4px 10px" onclick="toggle(' + it.id + ',' + it.status + ')">' + (act ? '停用' : '启用') + '</button>'
                + '<button class="btn btn-ghost btn-sm" style="padding:4px 10px" onclick="rebind(' + it.id + ',\'' + esc(it.domain) + '\')">换绑</button>'
                + '<button class="btn btn-danger btn-sm" style="padding:4px 10px" onclick="del(' + it.id + ')">删除</button>'
                + '</div></td></tr>';
        }
        document.getElementById('licBody').innerHTML = html;
        renderPagination(d.page, d.total_pages || 1, d.total);
        document.getElementById('filterInfo').textContent = '共 ' + d.total + ' 条 · 第 ' + d.page + '/' + (d.total_pages || 1) + ' 页';
    });
}

function renderPagination(curPage, totalPages, total) {
    var el = document.getElementById('pagination');
    if (totalPages <= 1) { el.innerHTML = '<span class="page-info">共 ' + total + ' 条</span>'; return; }
    var html = '';
    html += '<button class="btn-page" ' + (curPage <= 1 ? 'disabled' : '') + ' onclick="goPage(' + (curPage - 1) + ')">上一页</button>';
    var start = Math.max(1, curPage - 3);
    var end   = Math.min(totalPages, start + 6);
    if (end - start < 6) start = Math.max(1, end - 6);
    for (var p = start; p <= end; p++) {
        html += '<button class="btn-page' + (p === curPage ? ' active' : '') + '" onclick="goPage(' + p + ')">' + p + '</button>';
    }
    html += '<button class="btn-page" ' + (curPage >= totalPages ? 'disabled' : '') + ' onclick="goPage(' + (curPage + 1) + ')">下一页</button>';
    html += '<span class="page-info">共 ' + total + ' 条</span>';
    el.innerHTML = html;
}
function goPage(n) { _filterState.page = n; loadList(); }

function reloadFiltered() {
    _filterState.channel = document.getElementById('filterChannel').value;
    _filterState.status  = parseInt(document.getElementById('filterStatus').value);
    _filterState.keyword = document.getElementById('filterKeyword').value.trim();
    _filterState.page = 1;
    loadList();
}
function resetFilter() {
    document.getElementById('filterChannel').value = '';
    document.getElementById('filterStatus').value  = '-1';
    document.getElementById('filterKeyword').value = '';
    _filterState = { channel: '', status: -1, keyword: '', page: 1 };
    loadList();
}

// ---- 自定义 modal 工具 ----
function showConfirm(title, text, onOk) {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmText').innerHTML = text;
    document.getElementById('confirmModal').classList.add('show');
    window._confirmCb = onOk || null;
}
function confirmHide(ok) {
    document.getElementById('confirmModal').classList.remove('show');
    if (ok && window._confirmCb) { window._confirmCb(); window._confirmCb = null; }
}

function showPrompt(title, placeholder, defaultVal, onOk) {
    document.getElementById('promptTitle').textContent = title;
    var inp = document.getElementById('promptInput');
    inp.placeholder = placeholder || '';
    inp.value = defaultVal || '';
    document.getElementById('promptModal').classList.add('show');
    setTimeout(function() { inp.focus(); inp.select(); }, 50);
    window._promptCb = onOk || null;
}
function promptHide(val) {
    document.getElementById('promptModal').classList.remove('show');
    if (val !== null && window._promptCb) { window._promptCb(val); window._promptCb = null; }
}

// ---- 门禁切换 ----
var _gatePending = false;
function toggleGate(on) {
    _gatePending = on;
    showConfirm(
        on ? '开启更新门禁' : '关闭更新门禁',
        on
            ? '开启后，部署站调用版本检查接口必须携带<b>有效授权码 + 绑定域名</b>才能获取更新包下载地址（限时签名链接）。<br><br><b style="color:var(--warn,#e9a23b)">注意：</b>存量未升级的老站将无法在线更新，建议先让所有部署站升级到支持授权的版本（≥ 2.66.0）再开启。'
            : '关闭后，所有部署站（无论是否激活授权）都可获取更新包下载地址，与旧版行为一致。',
        function() {
            api('gate', { on: on ? 1 : 0 }).then(function(r) {
                if (r.code !== 0) { showConfirm('操作失败', r.msg || '未知错误'); document.getElementById('gateSwitch').checked = !on; return; }
                document.getElementById('gateText').textContent =
                    on ? '已开启：下发更新包需有效授权' : '已关闭：所有部署均可获取更新（兼容旧客户端）';
            });
        }
    );
}

function saveTrialConf() {
    api('trial_conf', {
        days: parseInt(document.getElementById('trialDays').value || '7', 10),
        limit: parseInt(document.getElementById('trialLimit').value || '3', 10),
        difficulty: parseInt(document.getElementById('trialDiff').value || '4', 10)
    }).then(function(r) {
        if (r.code !== 0) { showConfirm('保存失败', r.msg || '未知错误'); return; }
        showConfirm('保存成功', '试用配置已更新，门户弹窗将即时生效。');
    });
}

function chkAll(master) {
    document.querySelectorAll('.lic-chk').forEach(function(c){ c.checked = master.checked; });
}
function checkedIds() {
    return Array.prototype.map.call(document.querySelectorAll('.lic-chk:checked'), function(c){ return parseInt(c.value, 10); });
}
function batchOp(act) {
    var ids = checkedIds();
    if (!ids.length) { showConfirm('提示', '请先勾选要操作的授权码'); return; }
    if (act === 'delete') {
        showConfirm('确认删除', '确认删除选中的 <b>' + ids.length + '</b> 枚授权码？删除后不可恢复。', function() { doBatchOp(act, ids); });
    } else {
        doBatchOp(act, ids);
    }
}
function doBatchOp(act, ids) {
    api('batch', { action: act, ids: ids }).then(function(r) {
        if (r.code !== 0) { showConfirm('操作失败', r.msg || '未知错误'); return; }
        showConfirm('操作成功', r.msg || '已完成', function(){ loadList(); });
    });
}

function openCreateModal() { document.getElementById('licModal').style.display = 'flex'; }
function closeModal() { document.getElementById('licModal').style.display = 'none'; }

function createLic() {
    var btn = document.getElementById('btnSave');
    btn.disabled = true;
    api('create', {
        count: parseInt(document.getElementById('fCount').value || '1', 10),
        days: parseInt(document.getElementById('fDays').value || '0', 10),
        plan: document.getElementById('fPlan').value,
        note: document.getElementById('fNote').value
    }).then(function(r) {
        btn.disabled = false;
        if (r.code !== 0) { showConfirm('签发失败', r.msg || '未知错误'); return; }
        showConfirm(
            '签发成功',
            '已签发 <b>' + r.data.count + '</b> 枚授权码：<br>'
            + '<button class="btn btn-primary btn-sm" style="margin:8px 0 4px" onclick="copyAllKeys()">复制全部</button>'
            + '<textarea id="newKeysBox" readonly onclick="this.select()" spellcheck="false" '
            + 'style="width:100%;height:' + Math.min(60 + r.data.keys.length * 18, 240) + 'px;margin-top:4px;padding:8px 10px;'
            + 'background:rgba(0,0,0,.3);border:1px solid var(--border-2);border-radius:8px;font-size:12px;'
            + 'font-family:ui-monospace,Consolas,monospace;color:#c6cddb;word-break:break-all;box-sizing:border-box;resize:vertical">'
            + esc(r.data.keys.join('\n')) + '</textarea>',
            function() { closeModal(); loadList(); }
        );
    });
}

function toggle(id, status) {
    showConfirm(
        '确认操作',
        '确认' + (parseInt(status) === 1 ? '停用' : '启用') + '该授权码？',
        function() {
            api('toggle', { id: id }).then(function(r) {
                if (r.code !== 0) { showConfirm('操作失败', r.msg || '未知错误'); return; }
                loadList();
            });
        }
    );
}

function rebind(id, domain) {
    showPrompt(
        '换绑域名',
        '输入新域名（归一化后存储），留空则解除绑定',
        domain || '',
        function(val) {
            if (val === null) return;
            api('rebind', { id: id, domain: val }).then(function(r) {
                if (r.code !== 0) { showConfirm('操作失败', r.msg || '未知错误'); return; }
                loadList();
            });
        }
    );
}

function del(id) {
    api('get', { id: id }).then(function(r) {
        if (r.code !== 0) { showConfirm('加载失败', r.msg || '未知错误'); return; }
        var row = r.data;
        showConfirm(
            '确认删除',
            '确认删除授权码 <b>' + esc(row.license_key) + '</b>？<br>绑定域名：<code>' + esc(row.domain || '未绑定') + '</code><br>删除后不可恢复。',
            function() {
                api('delete', { id: id }).then(function(r) {
                    if (r.code !== 0) { showConfirm('操作失败', r.msg || '未知错误'); return; }
                    loadList();
                });
            }
        );
    });
}

function copyKeyById(id) {
    // 列表中的授权码已脱敏，复制时按需向后端取完整值（reveal 需 CSRF）
    api('reveal', { id: id }).then(function(r) {
        if (r.code !== 0) { showConfirm('获取失败', r.msg || '未知错误'); return; }
        if (navigator.clipboard) navigator.clipboard.writeText(r.data.license_key);
        showToast('已复制授权码');
    });
}

function copyAllKeys() {
    var box = document.getElementById('newKeysBox');
    if (!box) return;
    box.focus(); box.select();
    var done = function() { showToast('已复制全部授权码'); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(box.value).then(done);
    } else {
        document.execCommand('copy'); done();
    }
}

loadList();
</script>
</body>
</html>
