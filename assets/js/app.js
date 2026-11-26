/*
 * GAMER PRO CRM — общие обработчики интерфейса.
 * Разметку отдаёт сервер (src/ui.php, src/portal.php); скрипт только оживляет data-атрибуты.
 * Обработчики отдельных страниц — в pages.js, график смен — в schedule.js.
 */
(function () {
  "use strict";

  var GP = (window.GP = window.GP || {});

  function on(el, ev, fn, opt) {
    if (el) el.addEventListener(ev, fn, opt);
  }
  function all(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }
  function one(sel, root) {
    return (root || document).querySelector(sel);
  }

  GP.on = on;
  GP.all = all;
  GP.one = one;
  GP.num = function (raw) {
    var n = Number(String(raw == null ? "" : raw).replace(/[\s\u00a0\u202f₽]/g, "").replace(",", "."));
    return isFinite(n) ? n : 0;
  };
  GP.money = function (n) {
    return Math.round(n).toLocaleString("ru-RU") + "\u00a0₽";
  };
  GP.plural = function (n, a, b, c) {
    var m10 = Math.abs(n) % 10;
    var m100 = Math.abs(n) % 100;
    if (m10 === 1 && m100 !== 11) return a;
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return b;
    return c;
  };

  /* ---------- модальные окна ---------- */

  GP.openDialog = function (dlg) {
    if (dlg && dlg.showModal && !dlg.open) dlg.showModal();
  };

  var pressedOn = null;
  on(document, "mousedown", function (e) {
    pressedOn = e.target;
  });
  on(document, "click", function (e) {
    var opener = e.target.closest("[data-dialog-open]");
    if (opener) {
      e.preventDefault();
      GP.openDialog(document.getElementById(opener.getAttribute("data-dialog-open")));
      return;
    }
    var closer = e.target.closest("[data-dialog-close]");
    if (closer) {
      var owner = closer.closest("dialog");
      if (owner) owner.close();
      return;
    }
    // Клик по затемнению: и нажатие, и отпускание пришлись на сам <dialog> за пределами окна.
    var dlg = e.target;
    if (dlg.tagName === "DIALOG" && dlg.open && pressedOn === dlg) {
      var r = dlg.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) dlg.close();
    }
  });
  all("dialog[data-dialog-autoopen]").forEach(GP.openDialog);

  /* ---------- формы: подтверждение, защита от двойной отправки, автоотправка ---------- */

  // Перехват на погружении: отказ в подтверждении не должен запустить обработчики самой формы.
  on(
    document,
    "submit",
    function (e) {
      var form = e.target;
      var msg = (e.submitter && e.submitter.getAttribute("data-confirm")) || form.getAttribute("data-confirm");
      if (msg && !window.confirm(msg)) {
        e.preventDefault();
        e.stopPropagation();
      }
    },
    true
  );

  on(document, "submit", function (e) {
    var form = e.target;
    if (e.defaultPrevented || !form.hasAttribute || !form.hasAttribute("data-submit-once")) return;
    if (form.getAttribute("aria-busy") === "true") {
      e.preventDefault();
      return;
    }
    form.setAttribute("aria-busy", "true");
    // Кнопки гасим после отправки, иначе значение нажатой кнопки не попадёт в запрос.
    setTimeout(function () {
      all("button:not([type='button'])", form).forEach(function (b) {
        b.disabled = true;
      });
    }, 0);
  });
  on(window, "pageshow", function (e) {
    if (!e.persisted) return;
    all("form[aria-busy='true']").forEach(function (form) {
      form.removeAttribute("aria-busy");
      all("button:disabled", form).forEach(function (b) {
        b.disabled = false;
      });
    });
  });

  on(document, "change", function (e) {
    var el = e.target.closest ? e.target.closest("[data-autosubmit]") : null;
    if (!el || !el.form) return;
    if (el.form.requestSubmit) el.form.requestSubmit();
    else el.form.submit();
  });

  /* ---------- сохранение прокрутки после отправки формы ---------- */

  (function keepScroll() {
    var KEY = "gamerpro-scroll:" + location.pathname + location.search;
    try {
      var saved = sessionStorage.getItem(KEY);
      if (saved !== null) {
        sessionStorage.removeItem(KEY);
        window.scrollTo(0, Number(saved) || 0);
      }
    } catch (err) {}
    on(document, "submit", function (e) {
      if (e.defaultPrevented || !e.target.closest("[data-keep-scroll]")) return;
      try {
        sessionStorage.setItem(KEY, String(window.scrollY));
      } catch (err) {}
    });
  })();

  var scrollTarget = one("[data-scroll-into]");
  if (scrollTarget) scrollTarget.scrollIntoView({ block: "center" });

  /* ---------- уведомления ---------- */

  all("[data-toast]").forEach(function (toast) {
    function close() {
      clearTimeout(timer);
      toast.classList.add("is-leaving");
      setTimeout(function () {
        var wrap = toast.parentElement;
        toast.remove();
        if (wrap && !wrap.children.length) wrap.remove();
      }, 180);
    }
    var timer = setTimeout(close, toast.getAttribute("role") === "alert" ? 9000 : 5000);
    on(toast, "mouseenter", function () {
      clearTimeout(timer);
    });
    on(one("[data-toast-close]", toast), "click", close);
  });

  /* ---------- боковое меню на телефоне ---------- */

  var drawer = one("[data-nav-drawer]");
  var drawerOpener = null;
  function setDrawer(open) {
    if (!drawer) return;
    drawer.classList.toggle("hidden", !open);
    document.documentElement.style.overflow = open ? "hidden" : "";
    all("[data-open-nav]").forEach(function (b) {
      b.setAttribute("aria-expanded", open ? "true" : "false");
    });
    if (open) {
      var first = one("[data-close-nav]:not([tabindex='-1'])", drawer);
      if (first) first.focus();
    } else if (drawerOpener) {
      drawerOpener.focus();
    }
  }
  on(document, "click", function (e) {
    var opener = e.target.closest("[data-open-nav]");
    if (opener) {
      drawerOpener = opener;
      setDrawer(true);
    } else if (e.target.closest("[data-close-nav]")) {
      setDrawer(false);
    }
  });
  on(document, "keydown", function (e) {
    if (e.key === "Escape" && drawer && !drawer.classList.contains("hidden")) setDrawer(false);
  });

  /* ---------- тема ---------- */

  var SUN = '<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';
  var MOON = '<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>';
  var themeButtons = all("[data-theme-toggle]");
  function syncTheme() {
    var dark = document.documentElement.classList.contains("dark");
    themeButtons.forEach(function (btn) {
      btn.setAttribute("aria-label", dark ? "Включить светлую тему" : "Включить тёмную тему");
      btn.innerHTML = dark ? SUN : MOON;
    });
    var meta = one('meta[name="theme-color"]');
    if (meta) meta.content = dark ? "#0d0f12" : "#f3f4f6";
  }
  themeButtons.forEach(function (btn) {
    on(btn, "click", function () {
      var root = document.documentElement;
      var next = !root.classList.contains("dark");
      function apply() {
        root.classList.toggle("dark", next);
        root.classList.toggle("light", !next);
        root.style.colorScheme = next ? "dark" : "light";
        try {
          localStorage.setItem("gamerpro-theme", next ? "dark" : "light");
        } catch (err) {}
        syncTheme();
      }
      if (!document.startViewTransition || window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        apply();
        return;
      }
      // Новая тема раскрывается кругом из кнопки.
      var r = btn.getBoundingClientRect();
      var x = r.left + r.width / 2;
      var y = r.top + r.height / 2;
      var radius = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));
      document.startViewTransition(apply).ready.then(function () {
        root.animate(
          { clipPath: ["circle(0px at " + x + "px " + y + "px)", "circle(" + radius + "px at " + x + "px " + y + "px)"] },
          { duration: 560, easing: "cubic-bezier(0.22, 1, 0.36, 1)", pseudoElement: "::view-transition-new(root)" }
        );
      });
    });
  });
  syncTheme();

  /* ---------- пароли ---------- */

  on(document, "click", function (e) {
    var toggle = e.target.closest("[data-toggle-pass]");
    if (toggle) {
      var input = one("input", toggle.parentElement);
      if (!input) return;
      var show = input.type === "password";
      input.type = show ? "text" : "password";
      toggle.setAttribute("aria-pressed", show ? "true" : "false");
      toggle.setAttribute("aria-label", show ? "Скрыть пароль" : "Показать пароль");
      return;
    }
    var gen = e.target.closest("[data-gen-pass]");
    if (!gen) return;
    var target = one("[data-pass-target]", gen.parentElement);
    if (!target || !window.crypto) return;
    // Без похожих символов (0/O, 1/l/I), чтобы пароль можно было продиктовать.
    var abc = "abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    var buf = new Uint32Array(12);
    window.crypto.getRandomValues(buf);
    var pass = "";
    for (var i = 0; i < buf.length; i++) pass += abc.charAt(buf[i] % abc.length);
    target.value = pass;
    target.focus();
    target.select();
  });

  /* ---------- вкладки (главная, тарифы) ---------- */

  all("[data-tabs]").forEach(function (box) {
    var btns = all("[data-tab]", box);
    function show(key, focus) {
      var found = false;
      btns.forEach(function (b) {
        var onTab = b.getAttribute("data-tab") === key;
        if (onTab) found = true;
        b.setAttribute("aria-selected", onTab ? "true" : "false");
        b.tabIndex = onTab ? 0 : -1;
        if (onTab && focus) b.focus();
      });
      if (!found) return false;
      all("[data-tab-panel]", box).forEach(function (p) {
        p.hidden = p.getAttribute("data-tab-panel") !== key;
      });
      return true;
    }
    btns.forEach(function (b, i) {
      on(b, "click", function () {
        var key = b.getAttribute("data-tab");
        show(key);
        if (history.replaceState) history.replaceState(null, "", "#" + key);
      });
      on(b, "keydown", function (e) {
        var step = e.key === "ArrowRight" ? 1 : e.key === "ArrowLeft" ? -1 : 0;
        if (!step) return;
        e.preventDefault();
        var next = btns[(i + step + btns.length) % btns.length];
        show(next.getAttribute("data-tab"), true);
      });
    });
    var hash = (location.hash || "").slice(1);
    if (hash) show(hash);
  });

  /* ---------- поиск по таблицам ---------- */

  all("[data-table-filter]").forEach(function (input) {
    var id = input.getAttribute("data-table-filter");
    var table = document.getElementById(id);
    if (!table) return;
    var empty = one('[data-filter-empty="' + id + '"]');
    on(input, "input", function () {
      var q = input.value.toLowerCase().trim();
      // Телефон ищем по цифрам без кода страны: «+7 (900) 1» и «8900 1» находят один и тот же номер.
      var digits = /^[\d\s()+\-]+$/.test(q) ? q.replace(/\D/g, "") : "";
      if (digits.length > 1 && (digits.charAt(0) === "7" || digits.charAt(0) === "8")) digits = digits.slice(1);
      var needle = digits || q;
      var shown = 0;
      all("[data-filter-text]", table).forEach(function (row) {
        var ok = needle === "" || (row.getAttribute("data-filter-text") || "").toLowerCase().indexOf(needle) !== -1;
        row.classList.toggle("hidden", !ok);
        if (ok) shown++;
      });
      if (empty) empty.classList.toggle("hidden", needle === "" || shown > 0);
    });
  });

  /* ---------- «показать ещё» ---------- */

  all("[data-show-more]").forEach(function (list) {
    var step = parseInt(list.getAttribute("data-show-more"), 10) || 8;
    var items = all("[data-more-item]", list);
    var btn = list.parentElement ? one("[data-show-more-btn]", list.parentElement) : null;
    var shown = step;
    function paint() {
      items.forEach(function (item, i) {
        item.hidden = i >= shown;
      });
      if (!btn) return;
      var left = items.length - shown;
      if (left <= 0) btn.remove();
      else btn.textContent = "Показать ещё " + Math.min(step, left);
    }
    if (items.length <= step) {
      if (btn) btn.remove();
      return;
    }
    on(btn, "click", function () {
      shown += step;
      paint();
    });
    paint();
  });

  /* ---------- часы по времени клуба ---------- */

  var clocks = all("[data-clock]");
  if (clocks.length && window.Intl) {
    var clockFormat = new Intl.DateTimeFormat("ru-RU", { timeZone: "Asia/Yekaterinburg", hour: "2-digit", minute: "2-digit" });
    setInterval(function () {
      var now = clockFormat.format(new Date());
      clocks.forEach(function (c) {
        if (c.textContent !== now) c.textContent = now;
      });
    }, 15000);
  }

  /* ---------- аватар: выбор файла сразу отправляет форму ---------- */

  all("[data-avatar-auto]").forEach(function (input) {
    on(input, "change", function () {
      if (!input.files || !input.files.length) return;
      var form = input.form || document.getElementById(input.getAttribute("form"));
      if (form) form.submit();
    });
  });

  /* ---------- фоновое обновление Langame (не блокирует открытие вкладок) ---------- */

  function langameBranches() {
    return GP.langameRefresh && GP.langameRefresh.length
      ? GP.langameRefresh
      : GP.langameBranches && GP.langameBranches.length
        ? GP.langameBranches
        : [];
  }

  function refreshLangame(opts) {
    opts = opts || {};
    var branches = opts.branches || langameBranches();
    if (!branches.length || !GP.csrf) return Promise.resolve(null);
    var badge = one("[data-langame-status]");
    var btn = opts.button;
    if (badge) {
      badge.hidden = false;
      badge.setAttribute("aria-busy", "true");
      badge.textContent = opts.force ? "Обновляем продажи из Langame…" : "Сверяем продажи с Langame…";
      badge.classList.remove("is-done", "is-error");
    }
    if (btn) {
      btn.disabled = true;
      btn.setAttribute("aria-busy", "true");
    }
    var body =
      "_action=refresh_langame&_csrf=" +
      encodeURIComponent(GP.csrf) +
      "&branches=" +
      encodeURIComponent(branches.join(",")) +
      (opts.force ? "&force=1" : "");
    return fetch((GP.base || "") + "/", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
      body: body,
      keepalive: true,
    })
      .then(function (r) {
        return r.ok ? r.json() : null;
      })
      .then(function (data) {
        if (badge) {
          badge.setAttribute("aria-busy", "false");
          if (data && data.ok) {
            badge.textContent = "Данные обновлены";
            badge.classList.add("is-done");
          } else {
            badge.textContent = "Не удалось обновить в фоне";
            badge.classList.add("is-error");
          }
        }
        if (btn) {
          btn.disabled = false;
          btn.removeAttribute("aria-busy");
        }
        var synced = data && data.synced ? data.synced.length : 0;
        var live = data && data.live ? data.live.length : 0;
        var shouldReload =
          data &&
          data.ok &&
          (opts.reload ||
            (opts.reloadIfSynced && synced > 0) ||
            (opts.fromQueue && GP.langameReload && (synced > 0 || live > 0)));
        if (shouldReload) {
          setTimeout(function () {
            location.reload();
          }, 400);
        }
        return data;
      })
      .catch(function () {
        if (badge) {
          badge.setAttribute("aria-busy", "false");
          badge.textContent = "Фоновое обновление недоступно";
          badge.classList.add("is-error");
        }
        if (btn) {
          btn.disabled = false;
          btn.removeAttribute("aria-busy");
        }
        return null;
      });
  }

  GP.refreshLangame = refreshLangame;

  all("[data-langame-refresh]").forEach(function (btn) {
    on(btn, "click", function () {
      refreshLangame({
        force: btn.getAttribute("data-force") === "1",
        reload: true,
        button: btn,
      });
    });
  });

  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-copy]');
    if (!btn || !navigator.clipboard) return;
    navigator.clipboard.writeText(btn.getAttribute('data-copy')).then(function () {
      var label = btn.getAttribute('aria-label');
      btn.setAttribute('aria-label', 'Скопировано');
      btn.setAttribute('title', 'Скопировано');
      btn.classList.add('text-primary');
      setTimeout(function () {
        btn.setAttribute('aria-label', label || '');
        btn.removeAttribute('title');
        btn.classList.remove('text-primary');
      }, 1500);
    });
  });

  if (GP.langameRefresh && GP.langameRefresh.length) {
    refreshLangame({ fromQueue: true, force: !!GP.langameForce });
  }

  if (GP.langamePollMs && GP.langamePollMs >= 60000 && langameBranches().length) {
    setInterval(function () {
      if (document.hidden) return;
      refreshLangame({ force: false, reloadIfSynced: true });
    }, GP.langamePollMs);
  }
})();
