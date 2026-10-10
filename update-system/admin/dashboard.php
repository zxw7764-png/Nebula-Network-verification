<?php
/**
 * 后台数据概览页面
 * ==================================================================
 * 安装统计、版本分布、API 调用统计、系统信息。
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
<title>数据概览 - <?= htmlspecialchars($appName) ?></title>
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
            <a href="dashboard.php" class="nav-item active">
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
            <div class="page-title">数据概览</div>
            <div class="topbar-right">
                <div class="user-chip">
                    <div class="avatar"><?= htmlspecialchars($avatarChar) ?></div>
                    <span><?= htmlspecialchars($nickname) ?></span>
                    <a href="#" onclick="return doLogout()">退出</a>
                </div>
            </div>
        </div>

        <div class="content-area">
            <!-- 统计卡片 -->
            <div class="stat-grid">
                <div class="stat-box primary">
                    <div class="label">版本总数</div>
                    <div class="value" id="statTotalReleases">—</div>
                    <div class="sub" id="statActiveReleases">上架 — 个</div>
                </div>
                <div class="stat-box success">
                    <div class="label">安装总数</div>
                    <div class="value" id="statTotalInstalls">—</div>
                    <div class="sub" id="statUniqueInstalls">独立设备 — 个</div>
                </div>
                <div class="stat-box warning">
                    <div class="label">今日安装</div>
                    <div class="value" id="statTodayInstalls">—</div>
                    <div class="sub" id="statTodayApi">API 调用 — 次</div>
                </div>
                <div class="stat-box danger">
                    <div class="label">API 调用总数</div>
                    <div class="value" id="statTotalApi">—</div>
                    <div class="sub">总请求次数</div>
                </div>
            </div>

            <!-- 趋势图 -->
            <div class="card">
                <div class="card-head">
                    <h3>近 7 天安装趋势</h3>
                    <button class="btn btn-ghost btn-sm" onclick="loadOverview()">刷新</button>
                </div>
                <div class="chart-bar" id="trendBar" style="margin-top:12px"></div>
                <div class="chart-labels" id="trendLabels"></div>
            </div>

            <!-- 版本分布 + 系统信息 -->
            <div style="display:flex;gap:20px;flex-wrap:wrap">
                <div class="card" style="flex:1;min-width:360px">
                    <div class="card-head"><h3>版本分布</h3></div>
                    <div id="versionDist">
                        <div class="empty">加载中...</div>
                    </div>
                </div>
                <div class="card" style="flex:1;min-width:280px">
                    <div class="card-head"><h3>系统信息</h3></div>
                    <div class="info-list" id="systemInfo">
                        <div class="info-item"><span class="k">—</span><span class="v">—</span></div>
                    </div>
                </div>
            </div>

            <!-- 更新历史 -->
            <div class="card">
                <div class="card-head"><h3>更新历史</h3></div>
                <table>
                    <thead>
                        <tr><th>时间</th><th>从版本</th><th>到版本</th><th>操作人</th><th>状态</th><th>备注</th></tr>
                    </thead>
                    <tbody id="historyBody">
                        <tr><td colspan="6" class="empty">加载中...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="assets/admin.js?v=<?= htmlspecialchars($ver) ?>"></script>
<script>
var CSRF_TOKEN = '<?= htmlspecialchars($csrf) ?>';

function apiDashboard(op, data) {
    data = data || {}; data.op = op;
    return fetch('handlers/dashboard.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    }).then(function(r) { return r.json(); });
}

function apiUpdate(op, data) {
    data = data || {}; data.op = op; data.csrf = CSRF_TOKEN;
    return fetch('handlers/update.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    }).then(function(r) { return r.json(); });
}

function loadOverview() {
    apiDashboard('overview').then(function(res) {
        if (res.code !== 0) {
            // 接口报错时显示 0 而不是永远"加载中"
            document.getElementById('statTotalReleases').textContent = '0';
            document.getElementById('statActiveReleases').textContent = '上架 0 个';
            document.getElementById('statTotalInstalls').textContent = '0';
            document.getElementById('statUniqueInstalls').textContent = '独立设备 0 个';
            document.getElementById('statTodayInstalls').textContent = '0';
            document.getElementById('statTodayApi').textContent = 'API 0 次';
            document.getElementById('statTotalApi').textContent = '0';
            document.getElementById('trendBar').innerHTML = '';
            document.getElementById('trendLabels').innerHTML = '';
            document.getElementById('versionDist').innerHTML = '<div class="empty">暂无数据</div>';
            document.getElementById('systemInfo').innerHTML = '<div class="info-item"><span class="k">提示</span><span class="v" style="color:var(--danger)">' + esc(res.msg || '获取数据失败') + '</span></div>';
            loadHistory();
            return;
        }
        var d = res.data;

        document.getElementById('statTotalReleases').textContent = d.releases.total;
        document.getElementById('statActiveReleases').textContent = '上架 ' + d.releases.active + ' 个';
        document.getElementById('statTotalInstalls').textContent = d.installs.total;
        document.getElementById('statUniqueInstalls').textContent = '独立设备 ' + d.installs.unique + ' 个';
        document.getElementById('statTodayInstalls').textContent = d.installs.today;
        document.getElementById('statTodayApi').textContent = 'API ' + d.api.today_calls + ' 次';
        document.getElementById('statTotalApi').textContent = d.api.total_calls;

        var trend = d.installs.trend || [];
        var maxVal = Math.max(1, Math.max.apply(null, trend.map(function(t){return t.count;})));
        var barHtml = '', labelHtml = '';
        for (var i = 0; i < trend.length; i++) {
            var h = (trend[i].count / maxVal * 140).toFixed(0);
            var dl = trend[i].date.slice(5);
            barHtml += '<div class="bar" style="height:' + h + 'px"><div class="tooltip">' + trend[i].count + ' 次 · ' + dl + '</div></div>';
            labelHtml += '<div class="label">' + dl + '</div>';
        }
        document.getElementById('trendBar').innerHTML = barHtml;
        document.getElementById('trendLabels').innerHTML = labelHtml;

        var dist = d.installs.version_dist || [];
        var dh = '';
        if (dist.length === 0) {
            dh = '<div class="empty">暂无安装数据</div>';
        } else {
            var total = dist.reduce(function(s, v) { return s + parseInt(v.cnt); }, 0) || 1;
            for (var i = 0; i < dist.length; i++) {
                var pct = (parseInt(dist[i].cnt) / total * 100).toFixed(1);
                dh += '<div class="ver-bar">' +
                    '<span class="ver-name">v' + esc(dist[i].version) + '</span>' +
                    '<div class="track"><div class="fill" style="width:' + pct + '%"></div></div>' +
                    '<span class="pct">' + dist[i].cnt + ' (' + pct + '%)</span>' +
                '</div>';
            }
        }
        document.getElementById('versionDist').innerHTML = dh;

        var sys = d.system;
        var sh = '';
        sh += '<div class="info-item"><span class="k">当前版本</span><span class="v">v' + esc(sys.version) + '</span></div>';
        sh += '<div class="info-item"><span class="k">Build 编号</span><span class="v">' + sys.build + '</span></div>';
        sh += '<div class="info-item"><span class="k">产品标识</span><span class="v">' + esc(sys.product) + '</span></div>';
        sh += '<div class="info-item"><span class="k">PHP 版本</span><span class="v">' + esc(sys.php) + '</span></div>';
        sh += '<div class="info-item"><span class="k">MySQL 版本</span><span class="v">' + esc(sys.mysql) + '</span></div>';
        sh += '<div class="info-item"><span class="k">更新成功</span><span class="v" style="color:var(--success)">' + d.update_history.success + ' 次</span></div>';
        sh += '<div class="info-item"><span class="k">更新失败</span><span class="v" style="color:var(--danger)">' + d.update_history.failed + ' 次</span></div>';
        sh += '<div class="info-item"><span class="k">更新总次数</span><span class="v">' + d.update_history.total + ' 次</span></div>';
        document.getElementById('systemInfo').innerHTML = sh;

        loadHistory();
    }).catch(function() {
        // 网络错误也更新 DOM
        document.getElementById('statTotalReleases').textContent = '0';
        document.getElementById('statTotalInstalls').textContent = '0';
        document.getElementById('statTodayInstalls').textContent = '0';
        document.getElementById('statTotalApi').textContent = '0';
        document.getElementById('versionDist').innerHTML = '<div class="empty">网络错误</div>';
        loadHistory();
    });
}

function loadHistory() {
    apiUpdate('history', { page: 1, size: 5 }).then(function(res) {
        if (res.code !== 0 || !res.data || !res.data.list || !res.data.list.length) {
            document.getElementById('historyBody').innerHTML = '<tr><td colspan="6" class="empty">暂无更新记录</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < res.data.list.length; i++) {
            var h = res.data.list[i];
            var sm = {
                'success': '<span class="tag tag-green">成功</span>',
                'failed': '<span class="tag tag-red">失败</span>',
                'running': '<span class="tag tag-blue">进行中</span>',
            };
            html += '<tr>' +
                '<td>' + esc(h.started_at || '-') + '</td>' +
                '<td>v' + esc(h.from_version || '-') + '</td>' +
                '<td>v' + esc(h.to_version || '-') + '</td>' +
                '<td>' + esc(h.operator_name || '-') + '</td>' +
                '<td>' + (sm[h.status] || h.status) + '</td>' +
                '<td class="text-sm text-muted">' + esc((h.error_message || '-').slice(0, 50)) + '</td>' +
            '</tr>';
        }
        document.getElementById('historyBody').innerHTML = html;
    }).catch(function() {
        document.getElementById('historyBody').innerHTML = '<tr><td colspan="6" class="empty">暂无更新记录</td></tr>';
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

loadOverview();
setInterval(loadOverview, 60000);
</script>
</body>
</html>
