/* ============================================================
   Nebula · 开发文档中心交互
   文档切换 / 目录高亮 / 搜索过滤 / 代码复制 / 移动端目录
   ============================================================ */
(function () {
  "use strict";

  var tabs = Array.prototype.slice.call(document.querySelectorAll(".doc-tab"));
  var tocs = Array.prototype.slice.call(document.querySelectorAll(".toc"));
  var docs = Array.prototype.slice.call(document.querySelectorAll(".doc"));
  var sidebar = document.getElementById("sidebar");
  var tocToggle = document.getElementById("tocToggle");
  var searchInput = document.getElementById("tocSearch");
  var backTop = document.getElementById("backTop");
  var STORE_KEY = "nebula-docs-active";
  var current = null;

  /* ---------- 文档切换 ---------- */
  function activate(id, keepScroll) {
    if (!document.getElementById("doc-" + id)) id = "d1";
    if (current === id) return;
    current = id;
    tabs.forEach(function (t) { t.classList.toggle("active", t.dataset.doc === id); });
    tocs.forEach(function (t) { t.classList.toggle("active", t.dataset.doc === id); });
    docs.forEach(function (d) { d.classList.toggle("active", d.dataset.doc === id); });
    try { localStorage.setItem(STORE_KEY, id); } catch (e) { /* ignore */ }
    if (!keepScroll) window.scrollTo({ top: 0 });
    closeSidebar();
    requestAnimationFrame(spy);
  }

  function closeSidebar() {
    if (sidebar) sidebar.classList.remove("open");
    var burger = document.getElementById("burgerIcon");
    if (burger) burger.innerHTML = '<use href="#i-menu"></use>';
  }

  tabs.forEach(function (t) {
    t.addEventListener("click", function () { activate(t.dataset.doc); });
  });

  /* 顶部导航的文档入口 */
  Array.prototype.forEach.call(document.querySelectorAll("[data-doc-link]"), function (a) {
    a.addEventListener("click", function (e) {
      e.preventDefault();
      activate(a.dataset.docLink);
    });
  });

  /* 正文内跨文档链接 */
  Array.prototype.forEach.call(document.querySelectorAll(".md-link"), function (a) {
    a.addEventListener("click", function (e) {
      e.preventDefault();
      var target = a.dataset.goto;
      var anchor = a.getAttribute("href");
      activate(target, true);
      var el = document.querySelector(anchor);
      if (el) el.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  });

  /* ---------- 滚动监听：目录高亮 + 返回顶部按钮 ---------- */
  var ticking = false;
  function onScroll() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () {
      spy();
      if (backTop) backTop.classList.toggle("show", window.scrollY > 500);
      ticking = false;
    });
  }

  function spy() {
    var active = document.getElementById("doc-" + current);
    var toc = document.getElementById("toc-" + current);
    if (!active || !toc) return;
    var heads = active.querySelectorAll("h2[id], h3[id]");
    var pos = window.scrollY + 100;
    var idx = -1;
    for (var i = 0; i < heads.length; i++) {
      if (heads[i].getBoundingClientRect().top + window.scrollY <= pos) idx = i;
      else break;
    }
    var links = toc.querySelectorAll("a");
    Array.prototype.forEach.call(links, function (l, j) {
      l.classList.toggle("current", j === idx);
    });
    /* 让当前项滚入侧边栏可视区 */
    if (idx >= 0 && links[idx]) {
      var st = sidebar.getBoundingClientRect();
      var lt = links[idx].getBoundingClientRect();
      if (lt.top < st.top + 60 || lt.bottom > st.bottom - 20) {
        links[idx].scrollIntoView({ block: "nearest" });
      }
    }
  }

  window.addEventListener("scroll", onScroll, { passive: true });

  if (backTop) {
    backTop.addEventListener("click", function () {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }

  /* ---------- 目录搜索过滤 ---------- */
  if (searchInput) {
    searchInput.addEventListener("input", function () {
      var q = searchInput.value.trim().toLowerCase();
      var toc = document.getElementById("toc-" + current);
      if (!toc) return;
      Array.prototype.forEach.call(toc.querySelectorAll("a"), function (a) {
        var hit = !q || a.textContent.toLowerCase().indexOf(q) !== -1;
        a.classList.toggle("hidden", !hit);
      });
    });
  }

  /* ---------- 代码复制 ---------- */
  Array.prototype.forEach.call(document.querySelectorAll(".copy-btn"), function (btn) {
    btn.addEventListener("click", function () {
      var fig = btn.closest("figure.code");
      var code = fig ? fig.querySelector("pre code") : null;
      if (!code) return;
      var text = code.innerText;

      function done(ok) {
        var old = btn.textContent;
        btn.textContent = ok ? "已复制" : "复制失败";
        btn.classList.add("done");
        setTimeout(function () {
          btn.textContent = old;
          btn.classList.remove("done");
        }, 1600);
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(fallback(text)); });
      } else {
        done(fallback(text));
      }

      function fallback(t) {
        try {
          var ta = document.createElement("textarea");
          ta.value = t;
          ta.style.cssText = "position:fixed;opacity:0;";
          document.body.appendChild(ta);
          ta.select();
          var ok = document.execCommand("copy");
          document.body.removeChild(ta);
          return ok;
        } catch (e) { return false; }
      }
    });
  });

  /* ---------- 移动端目录开关 ---------- */
  if (tocToggle && sidebar) {
    tocToggle.addEventListener("click", function (e) {
      e.stopPropagation();
      var open = sidebar.classList.toggle("open");
      var burger = document.getElementById("burgerIcon");
      if (burger) burger.innerHTML = open ? '<use href="#i-x"></use>' : '<use href="#i-menu"></use>';
    });
    document.addEventListener("click", function (e) {
      if (sidebar.classList.contains("open") && !sidebar.contains(e.target) && e.target !== tocToggle) {
        closeSidebar();
      }
    });
  }

  /* ---------- 初始状态 ---------- */
  var m = (location.hash || "").match(/^#(d\d)-h/);
  var init = m ? m[1] : null;
  if (!init) {
    try { init = localStorage.getItem(STORE_KEY); } catch (e) { /* ignore */ }
  }
  activate(init || "d1", true);

  if (m) {
    /* 等文档显示后跳到锚点 */
    setTimeout(function () {
      var el = document.querySelector(location.hash);
      if (el) el.scrollIntoView({ block: "start" });
    }, 60);
  }

  window.addEventListener("hashchange", function () {
    var hm = (location.hash || "").match(/^#(d\d)-h/);
    if (hm && hm[1] !== current) {
      activate(hm[1], true);
      setTimeout(function () {
        var el = document.querySelector(location.hash);
        if (el) el.scrollIntoView({ block: "start" });
      }, 60);
    }
  });

  spy();
})();
