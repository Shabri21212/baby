/**
 * Тихий снег на фоне портала (новогоднее оформление, см. assets/css/src/newyear.css).
 * Снежинки мелкие и полупрозрачные — «где-то вдалеке», под карточками.
 * Не запускается при «Уменьшить движение», ставится на паузу во фоновой вкладке.
 */
(function () {
  "use strict";

  // Переход между разделами — полная перезагрузка страницы. Чтобы гирлянда не «перезапускалась»,
  // фаза мерцания считается от реального времени, а не от момента загрузки страницы.
  var TWINKLE_MS = 3600;
  document.querySelectorAll(".ny-garland").forEach(function (g) {
    g.style.setProperty("--ny-t", ((Date.now() % TWINKLE_MS) / 1000).toFixed(3) + "s");
  });

  var canvas = document.querySelector("[data-ny-snow]");
  if (!canvas || !canvas.getContext) return;
  var motion = window.matchMedia("(prefers-reduced-motion: reduce)");
  if (motion.matches) return;

  var ctx = canvas.getContext("2d");
  var flakes = [];
  var width = 0;
  var height = 0;
  var dpr = 1;
  var color = "255,255,255";
  var running = false;
  var last = 0;

  function readColor() {
    // В светлой теме белый снег не виден — берём приглушённый цвет текста.
    color = document.documentElement.classList.contains("dark") ? "235,238,245" : "120,128,145";
  }

  function makeFlake(anywhere) {
    var depth = Math.random();
    return {
      x: Math.random() * width,
      y: anywhere ? Math.random() * height : -6,
      r: 0.6 + depth * 1.5,
      speed: 8 + depth * 22,
      drift: (Math.random() - 0.5) * 10,
      phase: Math.random() * Math.PI * 2,
      alpha: 0.14 + depth * 0.32,
    };
  }

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    width = window.innerWidth;
    height = window.innerHeight;
    canvas.width = Math.round(width * dpr);
    canvas.height = Math.round(height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    var target = Math.round(Math.min(90, Math.max(28, (width * height) / 22000)));
    while (flakes.length < target) flakes.push(makeFlake(true));
    flakes.length = target;
  }

  function frame(now) {
    if (!running) return;
    var dt = Math.min(0.05, (now - (last || now)) / 1000);
    last = now;
    ctx.clearRect(0, 0, width, height);
    for (var i = 0; i < flakes.length; i++) {
      var f = flakes[i];
      f.phase += dt * 0.8;
      f.y += f.speed * dt;
      f.x += (f.drift + Math.sin(f.phase) * 6) * dt;
      if (f.y > height + 6 || f.x < -10 || f.x > width + 10) {
        flakes[i] = makeFlake(false);
        continue;
      }
      ctx.beginPath();
      ctx.fillStyle = "rgba(" + color + "," + f.alpha + ")";
      ctx.arc(f.x, f.y, f.r, 0, Math.PI * 2);
      ctx.fill();
    }
    window.requestAnimationFrame(frame);
  }

  function start() {
    if (running) return;
    running = true;
    last = 0;
    window.requestAnimationFrame(frame);
  }
  function stop() {
    running = false;
  }

  // Снежинки сохраняем при уходе со страницы и продолжаем с тех же мест на следующей,
  // сдвинув их на прошедшее время — снег не начинается заново при переключении вкладок.
  var STATE_KEY = "gamerpro-ny-snow";
  function saveState() {
    try {
      sessionStorage.setItem(
        STATE_KEY,
        JSON.stringify({ t: Date.now(), w: width, h: height, f: flakes.map(function (f) {
          return [Math.round(f.x * 10) / 10, Math.round(f.y * 10) / 10, f.r, f.speed, f.drift, f.phase, f.alpha];
        }) })
      );
    } catch (err) {}
  }
  function restoreState() {
    try {
      var s = JSON.parse(sessionStorage.getItem(STATE_KEY) || "null");
      if (!s || !Array.isArray(s.f) || !s.f.length) return;
      var elapsed = (Date.now() - s.t) / 1000;
      if (!(elapsed >= 0 && elapsed < 30)) return;
      var sx = s.w ? width / s.w : 1;
      var sy = s.h ? height / s.h : 1;
      flakes = s.f.map(function (a) {
        var f = { x: a[0] * sx, y: a[1] * sy, r: a[2], speed: a[3], drift: a[4], phase: a[5], alpha: a[6] };
        f.y += f.speed * elapsed;
        f.x += f.drift * elapsed;
        f.phase += elapsed * 0.8;
        if (f.y > height + 6) f.y = ((f.y % (height + 12)) + height + 12) % (height + 12) - 6;
        if (f.x < -10 || f.x > width + 10) f.x = ((f.x % width) + width) % width;
        return f;
      });
    } catch (err) {}
  }

  readColor();
  resize();
  restoreState();
  resize();
  start();

  window.addEventListener("pagehide", saveState);
  window.addEventListener("resize", resize);
  document.addEventListener("visibilitychange", function () {
    if (document.hidden) stop();
    else start();
  });
  // Переключение темы меняет класс на <html> — подстраиваем цвет снега.
  new MutationObserver(readColor).observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });
  var onMotion = function (e) {
    if (e.matches) {
      stop();
      ctx.clearRect(0, 0, width, height);
    } else start();
  };
  if (motion.addEventListener) motion.addEventListener("change", onMotion);
})();
