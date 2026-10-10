/* ============================================================
   Nebula · 页面背景粒子（单页滚动版）
   整站一个页面，各区块用 data-form 指定编队索引，滚动时按当前
   所在区块自动切换，粒子在编队之间平滑变形（morph）：
     0 星云 · 1 架构网格 · 2 功能蜂巢 · 3 盾牌
     4 仪表柱 · 5 星球 · 6 代码括号 · 7 箭头
   粒子在编队附近做惯性运动，叠加鼠标视差与滚动位移；
   同时驱动滚动进度条与 .px-el 视差装饰。
   纯原生 Canvas，无依赖。
   ============================================================ */
(function () {
  "use strict";

  var canvas = document.getElementById("story-canvas");
  if (!canvas || !canvas.getContext) return;
  var ctx = canvas.getContext("2d");

  var CYAN = "34, 211, 238";
  var INDIGO = "129, 140, 248";
  var WHITE = "226, 232, 240";

  var W = 0, H = 0, DPR = 1;
  var N = window.innerWidth < 760 ? 90 : 170;

  /* ---------------- 编队生成（归一化 0~1 坐标） ---------------- */

  function rnd(a, b) { return a + Math.random() * (b - a); }
  function gauss() { return (Math.random() + Math.random() + Math.random()) / 1.5 - 1; }

  /* 从 SVG path 字符串均匀采样点（路径以 100x100 视框书写） */
  function samplePath(d, n) {
    var NS = "http://www.w3.org/2000/svg";
    var svg = document.createElementNS(NS, "svg");
    var p = document.createElementNS(NS, "path");
    p.setAttribute("d", d);
    svg.appendChild(p);
    svg.setAttribute("style", "position:absolute;left:-9999px;top:0;width:10px;height:10px;visibility:hidden;");
    document.body.appendChild(svg);
    var pts = [];
    try {
      var len = p.getTotalLength();
      for (var i = 0; i < n; i++) {
        var q = p.getPointAtLength(len * i / n);
        pts.push([q.x / 100, q.y / 100]);
      }
    } catch (e) {
      for (var j = 0; j < n; j++) pts.push([rnd(.3, .7), rnd(.3, .7)]);
    }
    document.body.removeChild(svg);
    return pts;
  }

  /* 在基础点集上补齐到 n 个（复制 + 抖动），保证各编队点数一致 */
  function fillTo(base, n, jitter) {
    var pts = base.slice(0, Math.min(base.length, n));
    while (pts.length < n) {
      var s = base[Math.floor(Math.random() * base.length)];
      pts.push([s[0] + rnd(-jitter, jitter), s[1] + rnd(-jitter, jitter)]);
    }
    return pts;
  }

  function fNebula(n) {
    var pts = [];
    for (var i = 0; i < n; i++) {
      var a = Math.random() * Math.PI * 2;
      var r = Math.pow(Math.random(), .62);
      pts.push([.5 + Math.cos(a) * r * .48 + gauss() * .05, .5 + Math.sin(a) * r * .40 + gauss() * .06]);
    }
    return pts;
  }

  function fGrid(n) {
    var base = [], g = 5;
    for (var y = 0; y < g; y++)
      for (var x = 0; x < g; x++)
        base.push([.16 + .68 * x / (g - 1), .18 + .64 * y / (g - 1)]);
    return fillTo(base, n, .012);
  }

  function fHoney(n) {
    var base = [], rows = 6, cols = 9;
    for (var r = 0; r < rows; r++)
      for (var c = 0; c < cols; c++)
        base.push([.13 + .74 * c / (cols - 1) + (r % 2 ? .041 : 0), .2 + .6 * r / (rows - 1)]);
    return fillTo(base, n, .014);
  }

  function fShield(n) {
    return samplePath("M50 94 C24 82 12 62 12 36 L12 20 L50 6 L88 20 L88 36 C88 62 76 82 50 94 Z", n);
  }

  function fBars(n) {
    var pts = [], hs = [.28, .5, .72, .94], bw = .1, gap = .055;
    var total = hs.length * bw + (hs.length - 1) * gap, x0 = .5 - total / 2;
    hs.forEach(function (h, i) {
      var xa = x0 + i * (bw + gap), xb = xa + bw, yb = .86, yt = yb - h;
      var per = 2 * ((xb - xa) + (yb - yt));
      var count = Math.floor(n / hs.length);
      for (var k = 0; k < count; k++) {
        var d = k / count * per, p;
        if (d < xb - xa) p = [xa + d, yt];
        else if (d < (xb - xa) + (yb - yt)) p = [xb, yt + (d - (xb - xa))];
        else if (d < 2 * (xb - xa) + (yb - yt)) p = [xb - (d - (xb - xa) - (yb - yt)), yb];
        else p = [xa, yb - (d - 2 * (xb - xa) - (yb - yt))];
        pts.push(p);
      }
    });
    return fillTo(pts, n, .004);
  }

  function fGlobe(n) {
    var pts = [];
    for (var i = 0; i < n; i++) {
      var t = i / n;
      if (t < .5) { var a = t / .5 * Math.PI * 2; pts.push([.5 + .43 * Math.cos(a), .5 + .43 * Math.sin(a)]); }
      else if (t < .72) { var b = (t - .5) / .22 * Math.PI; pts.push([.5 + .17 * Math.sin(b), .5 + .43 * Math.cos(b)]); }
      else if (t < .94) { var c = (t - .72) / .22 * Math.PI; pts.push([.5 + .33 * Math.sin(c), .5 + .43 * Math.cos(c)]); }
      else { pts.push([.07 + .86 * ((t - .94) / .06), .5]); }
    }
    return pts;
  }

  function fBrackets(n) {
    return samplePath("M32 22 L12 50 L32 78 M68 22 L88 50 L68 78 M44 82 L56 18", n);
  }

  function fArrow(n) {
    return samplePath("M50 6 L82 42 L60 42 L60 90 L40 90 L40 42 L18 42 Z", n);
  }

  var FORMS = [fNebula, fGrid, fHoney, fShield, fBars, fGlobe, fBrackets, fArrow]
    .map(function (fn) { return fn(N); });

  /* ---------------- 区块 → 编队：滚动时切换并在编队间变形 ---------------- */
  var secs = [];
  var secEls = document.querySelectorAll("section[data-form]");
  for (var s = 0; s < secEls.length; s++) {
    var f = parseInt(secEls[s].getAttribute("data-form"), 10);
    if (!(f >= 0 && f < FORMS.length)) f = 0;
    secs.push({ el: secEls[s], form: f, top: 0 });
  }
  if (!secs.length) {
    // 兜底：页面未标注区块时，用画布自身的 data-form
    var fb = parseInt(canvas.getAttribute("data-form") || "0", 10);
    if (!(fb >= 0 && fb < FORMS.length)) fb = 0;
    secs.push({ el: document.body, form: fb, top: 0 });
  }

  function measureSecs() {
    for (var i = 0; i < secs.length; i++) {
      secs[i].top = secs[i].el.getBoundingClientRect().top + window.scrollY;
    }
  }

  /* 当前视口中线落在哪个区块 → 该区块的编队即目标编队 */
  var formIdx = secs[0].form;
  function pickForm() {
    var y = window.scrollY + H * .5, idx = 0;
    for (var i = 0; i < secs.length; i++) {
      if (secs[i].top <= y) idx = i;
    }
    formIdx = secs[idx].form;
  }

  /* 变形坐标：每帧向目标编队插值，形成编队之间的平滑过渡 */
  var morph = [];
  for (var m = 0; m < N; m++) {
    morph.push([FORMS[formIdx][m][0], FORMS[formIdx][m][1]]);
  }

  /* ---------------- 粒子 ---------------- */
  var particles = [];
  for (var i = 0; i < N; i++) {
    particles.push({
      x: Math.random(), y: Math.random(),   // 当前（归一化）
      vx: 0, vy: 0,
      z: rnd(.45, 1),                        // 深度
      c: i % 3,                              // 颜色通道
      s: rnd(0, Math.PI * 2)                 // 噪声种子
    });
  }

  /* ---------------- 滚动进度条 + 视差装饰 ---------------- */
  var progressBar = document.getElementById("scrollProgress");
  var pxEls = Array.prototype.slice.call(document.querySelectorAll(".px-el")).map(function (el) {
    return { el: el, sp: parseFloat(el.getAttribute("data-speed") || "-40") };
  });

  function updateProgress() {
    if (!progressBar) return;
    var doc = document.documentElement;
    var max = Math.max(doc.scrollHeight - window.innerHeight, 1);
    progressBar.style.width = (window.scrollY / max * 100).toFixed(2) + "%";
  }

  function updateParallax() {
    for (var i = 0; i < pxEls.length; i++) {
      var pe = pxEls[i];
      var rc = pe.el.getBoundingClientRect();
      var p = (H - rc.top) / (H + rc.height);
      if (p < -.15 || p > 1.15) continue;
      var ty = (p - .5) * pe.sp;
      pe.el.style.transform = "translate3d(0," + ty.toFixed(1) + "px,0)";
    }
  }

  /* ---------------- 滚轮惯性滚动（lerp 丝滑滑动） ---------------- */
  var wheelTarget = window.scrollY, wheelCurrent = wheelTarget;

  function maxScrollY() {
    return Math.max(document.documentElement.scrollHeight - window.innerHeight, 0);
  }

  window.addEventListener("wheel", function (e) {
    if (e.ctrlKey) return;                                   // Ctrl+滚轮 = 缩放，交还浏览器
    if (Math.abs(e.deltaX) > Math.abs(e.deltaY)) return;     // 横向滚动（如代码块）交还原生
    e.preventDefault();
    var d = e.deltaY;
    if (e.deltaMode === 1) d *= 16;
    else if (e.deltaMode === 2) d *= window.innerHeight;
    d = Math.max(-240, Math.min(240, d));
    wheelTarget = Math.max(0, Math.min(maxScrollY(), wheelTarget + d));
  }, { passive: false });

  /* 外部滚动（滚动条拖动 / 键盘 / 触摸）与插值位置偏差过大时，视为外部操作并同步 */
  window.addEventListener("scroll", function () {
    if (Math.abs(window.scrollY - wheelCurrent) > 1.5) {
      wheelCurrent = wheelTarget = window.scrollY;
    }
    pickForm();
  }, { passive: true });

  function applyWheelLerp() {
    wheelCurrent += (wheelTarget - wheelCurrent) * .11;
    if (Math.abs(wheelTarget - wheelCurrent) < .4) wheelCurrent = wheelTarget;
    if (window.scrollY !== wheelCurrent) window.scrollTo(0, wheelCurrent);
  }

  /* ---------------- 站内锚点：统一走惯性滚动目标 ---------------- */
  document.addEventListener("click", function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var a = t.closest('a[href^="#"]');
    if (!a) return;
    var href = a.getAttribute("href");
    if (!href || href === "#") return;
    var target = document.querySelector(href);
    if (!target) return;
    e.preventDefault();
    var y = target.getBoundingClientRect().top + window.scrollY - 72;
    wheelTarget = Math.max(0, Math.min(maxScrollY(), y));
  });

  /* ---------------- 渲染 ---------------- */
  function resize() {
    DPR = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth; H = window.innerHeight;
    canvas.width = W * DPR; canvas.height = H * DPR;
    canvas.style.width = W + "px"; canvas.style.height = H + "px";
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
  }

  var mouse = { x: .5, y: .5 };
  window.addEventListener("mousemove", function (e) {
    mouse.x = e.clientX / W; mouse.y = e.clientY / H;
  }, { passive: true });

  var scrollDrift = 0;

  function frame(now) {
    if (!document.hidden) {
      applyWheelLerp();

      var S = Math.min(W * .78, H * .98);
      var cx = W / 2 + (mouse.x - .5) * 26;
      var cy = H / 2 + (mouse.y - .5) * 18;
      scrollDrift += ((window.scrollY * .05) - scrollDrift) * .06;

      /* 底色与氛围光 */
      ctx.fillStyle = "#070b14";
      ctx.fillRect(0, 0, W, H);
      var g1 = ctx.createRadialGradient(W * .16, H * -.05, 0, W * .16, H * -.05, H * .9);
      g1.addColorStop(0, "rgba(" + CYAN + ", .11)");
      g1.addColorStop(1, "rgba(" + CYAN + ", 0)");
      ctx.fillStyle = g1; ctx.fillRect(0, 0, W, H);
      var g2 = ctx.createRadialGradient(W * .86, H * .04, 0, W * .86, H * .04, H * .85);
      g2.addColorStop(0, "rgba(" + INDIGO + ", .12)");
      g2.addColorStop(1, "rgba(" + INDIGO + ", 0)");
      ctx.fillStyle = g2; ctx.fillRect(0, 0, W, H);

      /* 粒子运动：向当前区块编队收敛（先做编队变形插值）+ 呼吸抖动 + 滚动位移 */
      var k = now * .0011;
      var F = FORMS[formIdx];
      for (var i = 0; i < N; i++) {
        var p = particles[i];
        morph[i][0] += (F[i][0] - morph[i][0]) * .045;
        morph[i][1] += (F[i][1] - morph[i][1]) * .045;
        var tx = (morph[i][0] - .5) * S + cx;
        var ty = (morph[i][1] - .5) * S * .96 + cy;
        tx += Math.sin(k + p.s) * 5 * p.z;
        ty += Math.cos(k * .9 + p.s * 1.7) * 5 * p.z + (p.z - .7) * scrollDrift;
        p.vx = (p.vx + (tx - p.x) * .016) * .86;
        p.vy = (p.vy + (ty - p.y) * .016) * .86;
        p.x += p.vx; p.y += p.vy;
      }

      /* 连线（星座效果） */
      ctx.lineWidth = 1;
      var maxD = Math.min(90, S * .12), maxD2 = maxD * maxD;
      for (var m = 0; m < N; m++) {
        var pm = particles[m];
        for (var q = m + 1; q < N; q++) {
          var pn = particles[q];
          var dx = pm.x - pn.x, dy = pm.y - pn.y;
          var d2 = dx * dx + dy * dy;
          if (d2 < maxD2) {
            var al = (1 - Math.sqrt(d2) / maxD) * .14;
            ctx.strokeStyle = "rgba(" + (pm.c === 1 || pn.c === 1 ? INDIGO : CYAN) + ", " + al.toFixed(3) + ")";
            ctx.beginPath();
            ctx.moveTo(pm.x, pm.y);
            ctx.lineTo(pn.x, pn.y);
            ctx.stroke();
          }
        }
      }

      /* 粒子点 */
      for (var u = 0; u < N; u++) {
        var pt = particles[u];
        var col = pt.c === 0 ? CYAN : (pt.c === 1 ? INDIGO : WHITE);
        var rr = 1 + pt.z * 1.5;
        ctx.fillStyle = "rgba(" + col + ", " + (.3 + pt.z * .45).toFixed(3) + ")";
        ctx.beginPath();
        ctx.arc(pt.x, pt.y, rr, 0, Math.PI * 2);
        ctx.fill();
        if (pt.c === 0 && pt.z > .85) {
          ctx.fillStyle = "rgba(" + CYAN + ", .12)";
          ctx.beginPath();
          ctx.arc(pt.x, pt.y, rr * 3.4, 0, Math.PI * 2);
          ctx.fill();
        }
      }

      updateProgress();
      updateParallax();
    }
    requestAnimationFrame(frame);
  }

  var rT;
  window.addEventListener("resize", function () {
    clearTimeout(rT);
    rT = setTimeout(function () { resize(); measureSecs(); pickForm(); }, 120);
  }, { passive: true });

  resize();
  measureSecs();
  pickForm();
  /* 粒子初始位置直接放到当前区块编队附近，避免开场乱飞 */
  (function () {
    var S = Math.min(W * .78, H * .98);
    var F0 = FORMS[formIdx];
    particles.forEach(function (p, i) {
      morph[i][0] = F0[i][0]; morph[i][1] = F0[i][1];
      p.x = (F0[i][0] - .5) * S + W / 2;
      p.y = (F0[i][1] - .5) * S * .96 + H / 2;
    });
  })();
  /* 图片加载完成后区块高度会变化，重新测量各区块位置 */
  window.addEventListener("load", function () { measureSecs(); pickForm(); });
  requestAnimationFrame(frame);
})();
