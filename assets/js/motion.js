/*
 * Движение оболочки: аккордеон групп, раскрытие панели по наведению, подсказки, «перетекающие»
 * подложки вкладок, свечение под курсором и смена разделов из левого меню.
 */
(function () {
  "use strict";

  var root = document.documentElement;
  var reduced = window.matchMedia("(prefers-reduced-motion: reduce)");
  var finePointer = window.matchMedia("(hover: hover) and (pointer: fine)");
  var SPRING = "cubic-bezier(0.22, 1, 0.36, 1)";

  function store(kind, key, value) {
    try {
      window[kind].setItem(key, value);
    } catch (e) {}
  }
  function read(kind, key) {
    try {
      return window[kind].getItem(key);
    } catch (e) {
      return null;
    }
  }

  /* ---------- аккордеон групп ---------- */

  function groupState() {
    try {
      return JSON.parse(read("localStorage", "gp-nav-groups") || "{}");
    } catch (e) {
      return {};
    }
  }

  document.addEventListener("click", function (e) {
    var head = e.target.closest("button.sb-group-head");
    if (!head) return;
    var group = head.closest("[data-sb-group]");
    var open = group.getAttribute("data-open") !== "true";
    group.setAttribute("data-open", open ? "true" : "false");
    head.setAttribute("aria-expanded", open ? "true" : "false");
    var state = groupState();
    state[group.getAttribute("data-sb-group")] = open;
    store("localStorage", "gp-nav-groups", JSON.stringify(state));
  });

  /* ---------- панель: раскрывается по наведению, сворачивается при уходе курсора ---------- */

  // Ширину меняет CSS (:hover и .is-open). Класс нужен для короткой задержки перед сворачиванием —
  // панель не дёргается, когда курсор на мгновение соскользнул с края.
  var desk = document.querySelector(".sb--desk");
  var closeTimer = 0;
  if (desk) {
    desk.addEventListener("mouseenter", function () {
      clearTimeout(closeTimer);
      desk.classList.add("is-open");
    });
    desk.addEventListener("mouseleave", function () {
      clearTimeout(closeTimer);
      closeTimer = setTimeout(function () {
        desk.classList.remove("is-open");
      }, 140);
    });
    // После перехода по меню панель приходит раскрытой (см. sidebarBoot): если курсора над ней уже нет — сворачиваем.
    if (desk.classList.contains("is-open")) {
      setTimeout(function () {
        if (!desk.matches(":hover")) desk.classList.remove("is-open");
      }, 600);
    }
  }

  /* ---------- подсказки ---------- */

  var tip = document.createElement("div");
  tip.className = "gp-tip";
  tip.setAttribute("role", "tooltip");
  tip.id = "gp-tip";
  document.body.appendChild(tip);
  var tipTarget = null;
  var tipTimer = 0;

  // Пунктам меню подсказка не нужна: при наведении панель сама раскрывается и показывает подписи.
  function tipAllowed(el) {
    return el.hasAttribute("data-tip-always");
  }
  function showTip(el) {
    tipTarget = el;
    tip.textContent = el.getAttribute("data-tip");
    var r = el.getBoundingClientRect();
    var inSidebar = !!el.closest(".sb");
    var w = tip.offsetWidth;
    var h = tip.offsetHeight;
    var x;
    var y;
    if (inSidebar && r.right + w + 16 < window.innerWidth) {
      x = r.right + 10;
      y = r.top + r.height / 2 - h / 2;
      tip.style.setProperty("--tx", "-4px");
      tip.style.setProperty("--ty", "0px");
    } else {
      x = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
      y = r.top - h - 8;
      tip.style.setProperty("--tx", "0px");
      tip.style.setProperty("--ty", "4px");
    }
    tip.style.left = x + "px";
    tip.style.top = Math.max(8, y) + "px";
    el.setAttribute("aria-describedby", "gp-tip");
    requestAnimationFrame(function () {
      tip.classList.add("is-on");
    });
  }
  function hideTip() {
    clearTimeout(tipTimer);
    tip.classList.remove("is-on");
    if (tipTarget) tipTarget.removeAttribute("aria-describedby");
    tipTarget = null;
  }
  function onTipEnter(e) {
    var el = e.target.closest && e.target.closest("[data-tip]");
    if (!el || el === tipTarget || !tipAllowed(el)) return;
    hideTip();
    tipTimer = setTimeout(function () {
      showTip(el);
    }, e.type === "focusin" ? 0 : 280);
  }
  function onTipLeave(e) {
    var el = e.target.closest && e.target.closest("[data-tip]");
    if (!el) return;
    if (e.relatedTarget && el.contains(e.relatedTarget)) return;
    hideTip();
  }
  document.addEventListener("mouseover", onTipEnter);
  document.addEventListener("mouseout", onTipLeave);
  document.addEventListener("focusin", onTipEnter);
  document.addEventListener("focusout", onTipLeave);
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") hideTip();
  });
  window.addEventListener("scroll", hideTip, { passive: true, capture: true });

  /* ---------- «перетекающая» подложка вкладок ---------- */

  function slidePill(container, from, to) {
    if (reduced.matches || !from || !to || from === to || !to.animate) return;
    var cs = getComputedStyle(to);
    var bg = cs.backgroundColor;
    if (!bg || bg === "transparent" || bg === "rgba(0, 0, 0, 0)") return;
    var c = container.getBoundingClientRect();
    var a = from.getBoundingClientRect();
    var b = to.getBoundingClientRect();
    if (!a.width || !b.width) return;
    if (getComputedStyle(container).position === "static") container.style.position = "relative";
    var ghost = document.createElement("span");
    ghost.className = "pill-ghost";
    ghost.style.cssText =
      "left:" + (b.left - c.left + container.scrollLeft) + "px;top:" + (b.top - c.top + container.scrollTop) + "px;width:" + b.width +
      "px;height:" + b.height + "px;border-radius:" + cs.borderRadius + ";background:" + bg + ";box-shadow:" + cs.boxShadow;
    container.insertBefore(ghost, container.firstChild);
    var prev = { bg: to.style.backgroundColor, shadow: to.style.boxShadow, pos: to.style.position, z: to.style.zIndex };
    to.style.backgroundColor = "transparent";
    to.style.boxShadow = "none";
    if (cs.position === "static") to.style.position = "relative";
    to.style.zIndex = "1";
    from.style.backgroundColor = "transparent";
    from.style.boxShadow = "none";
    var anim = ghost.animate(
      [
        { transform: "translate(" + (a.left - b.left) + "px," + (a.top - b.top) + "px) scale(" + a.width / b.width + "," + a.height / b.height + ")" },
        { transform: "none" },
      ],
      { duration: 520, easing: SPRING }
    );
    function done() {
      ghost.remove();
      to.style.backgroundColor = prev.bg;
      to.style.boxShadow = prev.shadow;
      to.style.position = prev.pos;
      to.style.zIndex = prev.z;
      from.style.backgroundColor = "";
      from.style.boxShadow = "";
    }
    anim.onfinish = done;
    anim.oncancel = done;
  }

  // Внутристраничные вкладки (role="tablist"): подложка едет за выбранной вкладкой.
  document.querySelectorAll('[role="tablist"]').forEach(function (list) {
    var current = list.querySelector('[aria-selected="true"]');
    new MutationObserver(function () {
      var next = list.querySelector('[aria-selected="true"]');
      if (next && next !== current) {
        var prev = current;
        current = next;
        slidePill(list, prev, next);
      }
    }).observe(list, { subtree: true, attributes: true, attributeFilter: ["aria-selected"] });
  });

  /* ---------- смена раздела ---------- */

  // Анимация появления страницы играет только после перехода из левого меню: флаг ставит клик по пункту,
  // класс gp-enter — скрипт в <head>. Сохранение формы, вкладки и ссылки внутри страницы её не запускают.
  if (root.classList.contains("gp-enter")) {
    setTimeout(function () {
      root.classList.remove("gp-enter");
    }, 1200);
  }

  function isPlainNav(e, a) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return false;
    if (a.target && a.target !== "_self") return false;
    if (a.hasAttribute("download") || a.origin !== location.origin) return false;
    if (a.pathname === location.pathname && a.search === location.search) return false;
    return !/\.(xlsx|csv|pdf|zip)$|\/export/i.test(a.pathname);
  }

  document.addEventListener("click", function (e) {
    var a = e.target.closest("a[href]");
    if (!a || !isPlainNav(e, a)) return;

    var sbLink = a.closest(".sb--desk .sb-link");
    if (sbLink) {
      var pill = document.querySelector(".sb--desk [aria-current='page'] .sb-pill");
      if (pill) {
        var r = pill.getBoundingClientRect();
        store("sessionStorage", "gp-pill", JSON.stringify({ x: r.left, y: r.top, w: r.width, h: r.height }));
      }
      sbLink.classList.add("is-pending");
      // Курсор остаётся над панелью — следующая страница сразу рисует её раскрытой, без рывка.
      store("sessionStorage", "gp-sb-open", "1");
    }

    var menuLink = sbLink || a.closest(".sb-link, .dock-item");
    var group = a.closest("[data-pill-group], nav");
    if (group && !sbLink && !a.closest("[data-live-nav]")) {
      var currentLink = group.querySelector('[aria-current="page"]');
      if (currentLink && currentLink !== a) {
        // Сначала переносим «активность», потом подложка едет к ней.
        var bg = getComputedStyle(currentLink).backgroundColor;
        a.style.color = getComputedStyle(currentLink).color;
        a.style.backgroundColor = bg;
        slidePill(group, currentLink, a);
        a.style.backgroundColor = "";
      }
    }

    if (menuLink) {
      store("sessionStorage", "gp-enter", "1");
      root.classList.add("is-leaving");
    }
  });

  window.addEventListener("pagehide", function () {
    var nav = document.querySelector(".sb--desk .sb-nav");
    if (nav) store("sessionStorage", "gp-nav-scroll", String(nav.scrollTop));
  });
  // Возврат «назад» из bfcache — снимаем затемнение.
  window.addEventListener("pageshow", function () {
    root.classList.remove("is-leaving");
    document.querySelectorAll(".sb-link.is-pending").forEach(function (l) {
      l.classList.remove("is-pending");
    });
  });

  /* ---------- свечение под курсором и «магнитные» иконки ---------- */

  var GLOW = ".surface, .sb-link, .sb-user, .dock-item";
  var glowEl = null;
  var magEl = null;
  var frame = 0;
  var lastEvent = null;

  function clearGlow() {
    if (glowEl) glowEl.classList.remove("is-glow");
    glowEl = null;
  }
  function clearMag() {
    if (magEl) {
      magEl.style.removeProperty("--mag-x");
      magEl.style.removeProperty("--mag-y");
    }
    magEl = null;
  }

  function onFrame() {
    frame = 0;
    var e = lastEvent;
    if (!e || !e.target.closest) return;

    var g = e.target.closest(GLOW);
    if (g !== glowEl) {
      clearGlow();
      // Таблицы и большие формы не подсвечиваем — свечение там отвлекает.
      if (g && g.offsetHeight < 420) {
        glowEl = g;
        g.classList.add("is-glow");
      }
    }
    if (glowEl) {
      var r = glowEl.getBoundingClientRect();
      glowEl.style.setProperty("--gx", e.clientX - r.left + "px");
      glowEl.style.setProperty("--gy", e.clientY - r.top + "px");
    }

    var m = e.target.closest(".sb-icon-btn");
    if (m !== magEl) clearMag();
    if (m) {
      magEl = m;
      var mr = m.getBoundingClientRect();
      var mx = ((e.clientX - mr.left) / mr.width - 0.5) * 6;
      var my = ((e.clientY - mr.top) / mr.height - 0.5) * 6;
      m.style.setProperty("--mag-x", mx.toFixed(2) + "px");
      m.style.setProperty("--mag-y", my.toFixed(2) + "px");
    }
  }

  document.addEventListener(
    "pointermove",
    function (e) {
      if (reduced.matches || !finePointer.matches || e.pointerType !== "mouse") return;
      lastEvent = e;
      if (!frame) frame = requestAnimationFrame(onFrame);
    },
    { passive: true }
  );
  document.addEventListener("pointerleave", function () {
    clearGlow();
    clearMag();
  });
})();
