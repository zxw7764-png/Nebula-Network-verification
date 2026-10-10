/* ============================================================
   update-system 后台共享 JS — admin/assets/admin.js
   ============================================================
   提供 Toast 提示和自定义确认弹窗，替代原生 alert/confirm。
   所有后台页面在 </body> 前引入此文件。
*/

/** 确保 toast 容器存在 */
function ensureToastContainer() {
    var c = document.querySelector('.toast-container');
    if (!c) {
        c = document.createElement('div');
        c.className = 'toast-container';
        document.body.appendChild(c);
    }
    return c;
}

/** 显示 Toast 提示 */
function showToast(msg, type) {
    type = type || 'info';
    var icons = { info: 'i', success: '✓', warning: '!', error: '✕' };
    var c = ensureToastContainer();
    var t = document.createElement('div');
    t.className = 'toast toast-' + type;
    t.innerHTML = '<span class="toast-icon">' + (icons[type] || 'i') + '</span><span class="toast-body">' + escHtml(msg) + '</span>';
    c.appendChild(t);
    setTimeout(function() {
        t.classList.add('out');
        setTimeout(function() { t.remove(); }, 300);
    }, 3500);
}

/** 快捷方法 */
function toastInfo(msg)  { showToast(msg, 'info'); }
function toastOk(msg)    { showToast(msg, 'success'); }
function toastWarn(msg)  { showToast(msg, 'warning'); }
function toastErr(msg)   { showToast(msg, 'error'); }

/** 自定义确认弹窗（替代 confirm） */
function confirmBox(title, message, onConfirm, onCancel) {
    var overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML =
        '<div class="confirm-box">' +
            '<div class="confirm-head">' + escHtml(title) + '</div>' +
            '<div class="confirm-body">' + escHtml(message) + '</div>' +
            '<div class="confirm-foot">' +
                '<button class="btn btn-ghost" data-act="cancel">取消</button>' +
                '<button class="btn btn-danger" data-act="ok">确定</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);
    // 强制重绘后添加 show 类，触发动画
    void overlay.offsetWidth;
    overlay.classList.add('show');

    overlay.querySelector('[data-act="ok"]').addEventListener('click', function() {
        closeConfirm(overlay);
        if (typeof onConfirm === 'function') onConfirm();
    });
    overlay.querySelector('[data-act="cancel"]').addEventListener('click', function() {
        closeConfirm(overlay);
        if (typeof onCancel === 'function') onCancel();
    });
    // 点击遮罩关闭
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            closeConfirm(overlay);
            if (typeof onCancel === 'function') onCancel();
        }
    });
}

function closeConfirm(overlay) {
    overlay.classList.remove('show');
    setTimeout(function() { overlay.remove(); }, 200);
}

/** HTML 转义 */
function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}
