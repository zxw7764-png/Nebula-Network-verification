/* ============================================================
   Nebula 网络验证 · 官网交互脚本
   ============================================================ */
(function () {
  "use strict";

  /* ---------- 导航：滚动加背景 ---------- */
  const nav = document.getElementById("nav");
  const backTop = document.getElementById("backTop");

  function onScroll() {
    const y = window.scrollY || document.documentElement.scrollTop;
    nav.classList.toggle("scrolled", y > 24);
    backTop.classList.toggle("show", y > 560);
  }
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  /* ---------- 移动端菜单 ---------- */
  const burger = document.getElementById("navBurger");
  const burgerIcon = document.getElementById("burgerIcon");

  burger.addEventListener("click", function () {
    const open = nav.classList.toggle("open");
    burgerIcon.innerHTML = open
      ? '<use href="#i-x"></use>'
      : '<use href="#i-menu"></use>';
  });

  // 点击菜单项后收起移动菜单
  document.querySelectorAll("#navLinks a").forEach(function (a) {
    a.addEventListener("click", function () {
      nav.classList.remove("open");
      burgerIcon.innerHTML = '<use href="#i-menu"></use>';
    });
  });

  /* ---------- 导航：高亮当前滚动到的板块 ---------- */
  const spyItems = Array.prototype.slice
    .call(document.querySelectorAll('#navLinks a[href^="#"]'))
    .map(function (a) { return { a: a, el: document.querySelector(a.getAttribute("href")) }; })
    .filter(function (it) { return it.el; });

  if (spyItems.length) {
    const spy = function () {
      const line = (window.scrollY || 0) + window.innerHeight * 0.34;
      let cur = null;
      spyItems.forEach(function (it) {
        if (it.el.getBoundingClientRect().top + window.scrollY <= line) cur = it;
      });
      spyItems.forEach(function (it) { it.a.classList.toggle("on", it === cur); });
    };
    window.addEventListener("scroll", spy, { passive: true });
    window.addEventListener("resize", spy, { passive: true });
    spy();
  }

  /* ---------- 入场动画（IntersectionObserver） ---------- */
  const revealEls = document.querySelectorAll(".reveal");
  if ("IntersectionObserver" in window) {
    const io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("visible");
            io.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -40px 0px" }
    );
    revealEls.forEach(function (el) { io.observe(el); });
  } else {
    revealEls.forEach(function (el) { el.classList.add("visible"); });
  }

  /* ---------- 数字滚动 ---------- */
  const counters = document.querySelectorAll("[data-count]");
  function animateCount(el) {
    const target = parseInt(el.getAttribute("data-count"), 10) || 0;
    const duration = 1100;
    const start = performance.now();
    function tick(now) {
      const p = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - p, 3); // easeOutCubic
      el.textContent = Math.round(target * eased);
      if (p < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }
  if ("IntersectionObserver" in window && counters.length) {
    const cio = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            animateCount(entry.target);
            cio.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.6 }
    );
    counters.forEach(function (el) { cio.observe(el); });
  } else {
    counters.forEach(function (el) {
      el.textContent = el.getAttribute("data-count");
    });
  }

  /* ---------- 使用人数：后台实时数据（60 秒轮询） ---------- */
  const liveUsers = document.getElementById("statUsers");
  const liveTag = document.getElementById("statUsersLive");
  if (liveUsers) {
    let lastUsers = null;

    function applyUsers(n) {
      if (n === lastUsers) { showTag(); return; }
      lastUsers = n;
      liveUsers.setAttribute("data-count", String(n));
      // 页面不预置占位数字，取到真实安装量后直接滚动到该值
      animateCount(liveUsers);
      showTag();
    }

    function showTag() {
      if (liveTag) liveTag.hidden = false;
    }

    function fetchUsers() {
      fetch("api/stats.php", { cache: "no-store" })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || d.success !== true) return;
          const n = parseInt(d.users, 10);
          if (isFinite(n) && n >= 0) applyUsers(n);
        })
        .catch(function () { /* 拉取失败保留当前值与占位值 */ });
    }

    fetchUsers();
    setInterval(fetchUsers, 60000);
  }

  /* ---------- 返回顶部 ---------- */
  backTop.addEventListener("click", function () {
    window.scrollTo({ top: 0, behavior: "smooth" });
  });
})();
