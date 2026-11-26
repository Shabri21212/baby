/*
 * «Тихое» обновление страницы: действия внутри вкладки не перезагружают её.
 *
 * Форма уходит через fetch, сервер отвечает той же страницей, что и раньше. Скрипт сравнивает
 * прошлую разметку сервера с новой и переносит в документ только отличия — изменившуюся ячейку,
 * карточку, счётчик. Всё, что сотрудник сделал на странице сам (выбранная вкладка, фильтр,
 * раскрытый блок, набранный текст), остаётся как было: трогаем лишь то, что поменял сервер.
 *
 * PHP-обработчики, формы и редиректы не меняются. Если страницу нельзя обновить точечно
 * (сервер увёл на другой раздел, заменился блок со своими обработчиками), выполняется обычный переход.
 */
(function () {
  "use strict";

  var GP = (window.GP = window.GP || {});
  var main = document.getElementById("main");
  if (!main || !window.fetch || !window.DOMParser || !window.FormData || !window.URL) return;

  var LOOKAHEAD = 48;
  // Атрибуты, по которым блок считается «тем же самым»: день графика, шаг курса, смена.
  var KEYS = ["data-date-key", "data-step", "data-shift-id"];
  var CONTROLS = "input, select, textarea, button, [role='button'], [tabindex], [contenteditable]";
  var HEADERS = { Accept: "text/html", "X-GP-Live": "1" };

  // Копия разметки сервера. У каждого её узла есть ссылка __live на «живой» узел страницы.
  var base = main.cloneNode(true);
  var ready = [];
  var guards = [];
  var added = [];
  var opened = [];
  var queue = Promise.resolve();
  var softHistory = false;

  function all(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function link(srv, live) {
    srv.__live = live;
    var a = srv.firstChild;
    var b = live.firstChild;
    while (a && b) {
      link(a, b);
      a = a.nextSibling;
      b = b.nextSibling;
    }
  }
  link(base, main);

  /* ---------- перенос отличий ---------- */

  // Узлы совпали — новая копия сервера наследует ссылки на живые узлы.
  function carry(o, n) {
    n.__live = o.__live;
    var a = o.firstChild;
    var b = n.firstChild;
    while (a && b) {
      carry(a, b);
      a = a.nextSibling;
      b = b.nextSibling;
    }
  }

  function fresh(srv, quiet) {
    var live = document.importNode(srv, true);
    link(srv, live);
    if (!quiet && live.nodeType === 1) added.push({ el: live, fresh: true });
    return live;
  }

  function attached(srv, parent) {
    var live = srv.__live;
    return !!live && live.parentNode === parent;
  }

  function sameKind(a, b) {
    if (a.nodeType !== b.nodeType) return false;
    if (a.nodeType !== 1) return true;
    if (a.nodeName !== b.nodeName || a.id !== b.id) return false;
    for (var i = 0; i < KEYS.length; i++) {
      if (a.getAttribute(KEYS[i]) !== b.getAttribute(KEYS[i])) return false;
    }
    return true;
  }

  function findEqual(list, node, from) {
    var end = Math.min(list.length, from + LOOKAHEAD);
    for (var i = from; i < end; i++) {
      if (list[i].isEqualNode(node)) return i;
    }
    return -1;
  }

  // Классы меняем по одному: is-glow, hidden и прочие, добавленные скриптами, остаются.
  function syncClass(o, n, live) {
    var was = o.classList;
    var now = n.classList;
    var i;
    for (i = 0; i < was.length; i++) {
      if (!now.contains(was[i])) live.classList.remove(was[i]);
    }
    for (i = 0; i < now.length; i++) {
      if (!was.contains(now[i])) live.classList.add(now[i]);
    }
  }

  function syncAttrs(o, n, live) {
    var i;
    var attr;
    var name;
    for (i = 0; i < n.attributes.length; i++) {
      attr = n.attributes[i];
      name = attr.name;
      if (o.getAttribute(name) === attr.value) continue;
      if (name === "class") {
        syncClass(o, n, live);
        continue;
      }
      live.setAttribute(name, attr.value);
      if (name === "value" && "value" in live) live.value = attr.value;
      else if (name === "checked") live.checked = true;
      else if (name === "selected") live.selected = true;
      else if (name === "data-dialog-autoopen") opened.push(live);
    }
    for (i = o.attributes.length - 1; i >= 0; i--) {
      name = o.attributes[i].name;
      if (n.hasAttribute(name)) continue;
      if (name === "class") {
        syncClass(o, n, live);
        continue;
      }
      live.removeAttribute(name);
      if (name === "checked") live.checked = false;
      else if (name === "selected") live.selected = false;
    }
  }

  // Содержимое блока пересобрал скрипт страницы — сопоставить узлы нельзя, берём разметку сервера целиком.
  function rebuild(n, live) {
    while (live.firstChild) live.removeChild(live.firstChild);
    for (var c = n.firstChild; c; c = c.nextSibling) live.appendChild(fresh(c, true));
    added.push({ el: live, fresh: false });
  }

  function patchKids(o, n, live) {
    var olds = o.childNodes;
    var news = n.childNodes;
    var i;
    var j = 0;
    for (i = 0; i < olds.length; i++) {
      if (!attached(olds[i], live)) {
        rebuild(n, live);
        return;
      }
    }
    // Число узлов изменилось — что-то добавили или убрали; иначе правим узлы на месте.
    var shifted = olds.length !== news.length;
    var anchor = null;
    function place(node) {
      var ref = anchor ? anchor.nextSibling : i < olds.length ? olds[i].__live : null;
      live.insertBefore(node, ref);
      anchor = node;
    }
    i = 0;
    while (i < olds.length || j < news.length) {
      var a = olds[i];
      var b = news[j];
      var k;
      if (!a) {
        place(fresh(b));
        j++;
        continue;
      }
      if (!b) {
        a.__live.remove();
        i++;
        continue;
      }
      if (a.isEqualNode(b)) {
        carry(a, b);
        anchor = a.__live;
        i++;
        j++;
        continue;
      }
      if (shifted) {
        k = findEqual(olds, b, i + 1);
        if (k !== -1) {
          while (i < k) olds[i++].__live.remove();
          continue;
        }
        k = findEqual(news, a, j + 1);
        if (k !== -1) {
          while (j < k) place(fresh(news[j++]));
          continue;
        }
      }
      if (sameKind(a, b)) {
        if (a.nodeType === 1) {
          patchEl(a, b);
        } else {
          a.__live.data = b.data;
          b.__live = a.__live;
        }
        anchor = a.__live;
      } else {
        place(fresh(b));
        a.__live.remove();
      }
      i++;
      j++;
    }
  }

  function patchEl(o, n) {
    var live = o.__live;
    n.__live = live;
    syncAttrs(o, n, live);
    patchKids(o, n, live);
    if (live.nodeName === "TEXTAREA") live.value = live.defaultValue;
  }

  function patch(next) {
    added = [];
    opened = [];
    if (base.isEqualNode(next)) carry(base, next);
    else patchEl(base, next);
    base = next;
  }

  /* ---------- то, что живёт вне #main ---------- */

  function syncBadge(from, to, sel, before) {
    var next = from.querySelector(sel);
    var live = to.querySelector(sel);
    if (next && live) {
      if (live.innerHTML !== next.innerHTML) live.innerHTML = next.innerHTML;
    } else if (next) {
      var holder = next.parentNode === from ? to : to.firstElementChild || to;
      holder.insertBefore(document.importNode(next, true), before ? holder.querySelector(before) : null);
    } else if (live) {
      live.remove();
    }
  }

  // Счётчики в меню: новые объявления, заявки, поломки.
  function syncNav(doc) {
    [".sb--desk", ".sb--drawer", ".dock"].forEach(function (scope) {
      var from = doc.querySelector(scope);
      var to = document.querySelector(scope);
      if (!from || !to) return;
      var byHref = {};
      all("a[href]", to).forEach(function (a) {
        byHref[a.getAttribute("href")] = a;
      });
      all("a[href]", from).forEach(function (a) {
        var live = byHref[a.getAttribute("href")];
        if (!live) return;
        syncBadge(a, live, ".sb-badge, .dock-badge");
        if (a.hasAttribute("data-tip")) live.setAttribute("data-tip", a.getAttribute("data-tip"));
      });
      all("[data-sb-group]", from).forEach(function (group) {
        var liveGroup = to.querySelector('[data-sb-group="' + group.getAttribute("data-sb-group") + '"]');
        var head = group.querySelector(".sb-group-head");
        var liveHead = liveGroup ? liveGroup.querySelector(".sb-group-head") : null;
        if (head && liveHead) syncBadge(head, liveHead, ".sb-group-count", ".sb-chevron");
      });
    });
  }

  function toastOf(doc) {
    var toast = doc.querySelector("[data-toast]");
    return toast ? toast.parentNode : null;
  }

  function showToast(wrap) {
    all("[data-toast]").forEach(function (toast) {
      if (toast.parentNode) toast.parentNode.remove();
    });
    var node = document.importNode(wrap, true);
    document.body.appendChild(node);
    // Открытое окно лежит в верхнем слое — уведомление поднимаем туда же, иначе его не видно.
    if (document.querySelector("dialog[open]") && node.showPopover) {
      node.setAttribute("popover", "manual");
      node.classList.add("toast-layer");
      node.showPopover();
    }
    if (GP.initToasts) GP.initToasts(node);
  }

  function notice(message) {
    var holder = document.createElement("div");
    holder.innerHTML =
      '<div class="pointer-events-none fixed inset-x-0 top-0 z-[70] flex flex-col items-center gap-2 p-3 lg:items-end lg:p-5">' +
      '<div class="toast pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border border-primary/40 bg-popover px-4 py-3 text-sm text-popover-foreground shadow-lg" role="alert" data-toast>' +
      '<p class="min-w-0 flex-1 leading-relaxed"></p></div></div>';
    holder.querySelector("p").textContent = message;
    showToast(holder.firstChild);
  }

  function pageData(doc) {
    var scripts = doc.getElementsByTagName("script");
    for (var i = scripts.length - 1; i >= 0; i--) {
      var m = /^window\.GP=(\{[\s\S]*\});$/.exec(scripts[i].textContent);
      if (!m) continue;
      try {
        return JSON.parse(m[1]);
      } catch (err) {
        return null;
      }
    }
    return null;
  }

  /* ---------- запрос и разбор ответа ---------- */

  function here() {
    return location.href.split("#")[0];
  }

  function samePath(url) {
    var u = new URL(url, location.href);
    return u.origin === location.origin && u.pathname === location.pathname;
  }

  // Обычный переход. Уведомление сервер уже отдал в этом ответе — переносим его на следующую страницу.
  function leave(url, wrap) {
    try {
      if (wrap) sessionStorage.setItem("gp-flash", wrap.outerHTML);
    } catch (err) {}
    if (new URL(url, location.href).href === here()) location.reload();
    else location.assign(url);
  }

  function download(blob, disposition) {
    var m = /filename\*=UTF-8''([^;]+)/i.exec(disposition) || /filename="?([^";]+)"?/i.exec(disposition);
    var name = "download";
    try {
      if (m) name = decodeURIComponent(m[1]);
    } catch (err) {
      name = m[1];
    }
    var href = URL.createObjectURL(blob);
    var a = document.createElement("a");
    a.href = href;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () {
      URL.revokeObjectURL(href);
    }, 30000);
  }

  // Заменён блок, на который скрипт страницы вешал обработчики при загрузке, — точечно его не оживить.
  function fragile(item) {
    if (!guards.length) return false;
    var el = item.el;
    var sel = guards.join(", ");
    var controls = (item.fresh && el.matches(CONTROLS)) || el.querySelector(CONTROLS);
    return !!controls && !!(el.closest(sel) || el.querySelector(sel));
  }

  /**
   * ctx.user — действие сотрудника (форма, ссылка): можно менять адрес и уходить на другую страницу.
   * Без него это фоновое обновление: оно либо тихо правит страницу, либо не делает ничего.
   */
  function handle(res, ctx) {
    var disposition = res.headers.get("Content-Disposition") || "";
    if (/attachment/i.test(disposition)) {
      return res.blob().then(function (blob) {
        download(blob, disposition);
        return true;
      });
    }
    if ((res.headers.get("Content-Type") || "").indexOf("text/html") === -1) {
      if (ctx.user) leave(res.url);
      return false;
    }
    return res.text().then(function (text) {
      var doc = new DOMParser().parseFromString(text, "text/html");
      var next = doc.getElementById("main");
      var wrap = toastOf(doc);
      if (!next || !samePath(res.url)) {
        if (ctx.user) leave(res.url, wrap);
        return false;
      }
      patch(next);
      if (ctx.user && added.some(fragile)) {
        leave(res.url, wrap);
        return false;
      }
      if (ctx.user && !ctx.pop && res.url !== here()) {
        history.pushState(null, "", res.url);
        softHistory = true;
      }
      if (doc.title) document.title = doc.title;
      syncNav(doc);

      var failed = !!(wrap && wrap.querySelector('[role="alert"]'));
      if (ctx.unlock) ctx.unlock();
      if (ctx.form && !failed) {
        if (ctx.dialog && ctx.dialog.open) ctx.dialog.close();
        if (ctx.form.isConnected) ctx.form.reset();
      }
      if (wrap) showToast(wrap);
      opened.forEach(function (dialog) {
        if (GP.openDialog) GP.openDialog(dialog);
      });
      added.forEach(function (item) {
        ready.forEach(function (fn) {
          fn(item.el);
        });
      });
      document.dispatchEvent(
        new CustomEvent("gp:patched", { detail: { user: !!ctx.user, failed: failed, gp: pageData(doc) } })
      );
      return true;
    });
  }

  // Ответы применяются строго по очереди: каждый сравнивается с уже обновлённой страницей.
  function enqueue(job) {
    queue = queue.then(job, job);
    return queue;
  }

  function get(url) {
    return fetch(url, { credentials: "same-origin", headers: HEADERS });
  }

  function lock(form, submitter) {
    var released = false;
    var buttons = all("button:not([type='button']), input[type='submit']", form).filter(function (b) {
      return !b.disabled;
    });
    form.setAttribute("aria-busy", "true");
    buttons.forEach(function (b) {
      b.disabled = true;
    });
    return function () {
      if (released) return;
      released = true;
      form.removeAttribute("aria-busy");
      buttons.forEach(function (b) {
        b.disabled = false;
      });
      if (submitter && submitter.isConnected && document.activeElement === document.body) {
        submitter.focus({ preventScroll: true });
      }
    };
  }

  function submit(form, submitter) {
    var data = new FormData(form);
    if (submitter && submitter.name) data.append(submitter.name, submitter.value);
    var url = new URL((submitter && submitter.getAttribute("formaction")) || form.getAttribute("action") || location.href, location.href);
    url.hash = "";
    var dialog = form.closest("dialog");
    var unlock = lock(form, submitter);
    return enqueue(function () {
      return fetch(url.href, { method: "POST", body: data, credentials: "same-origin", headers: HEADERS })
        .then(function (res) {
          return handle(res, { user: true, form: form, dialog: dialog, unlock: unlock });
        })
        .catch(function () {
          notice("Нет связи с сервером — действие не выполнено. Повторите ещё раз.");
          return false;
        })
        .then(function (ok) {
          unlock();
          return ok;
        });
    });
  }

  /** Переход по ссылке внутри той же страницы (шаг урока, соседний день) без перезагрузки. */
  function go(url) {
    var target = new URL(url, location.href);
    target.hash = "";
    return enqueue(function () {
      return get(target.href)
        .then(function (res) {
          return handle(res, { user: true });
        })
        .catch(function () {
          location.assign(url);
          return false;
        });
    });
  }

  /** Фоновое обновление: забирает свежую страницу и правит только изменившиеся места. */
  function refresh() {
    return enqueue(function () {
      return get(here())
        .then(function (res) {
          return handle(res, {});
        })
        .catch(function () {
          return false;
        });
    });
  }

  /* ---------- перехват форм и ссылок ---------- */

  document.addEventListener("submit", function (e) {
    var form = e.target;
    if (e.defaultPrevented || !form || form.nodeName !== "FORM" || !main.contains(form)) return;
    if ((form.getAttribute("method") || "get").toLowerCase() !== "post") return;
    // data-live="off" на форме или любом её предке — обычная отправка с переходом.
    if (form.getAttribute("target") || form.closest("[data-live='off']")) return;
    e.preventDefault();
    if (form.getAttribute("aria-busy") === "true") return;
    submit(form, e.submitter || null);
  });

  document.addEventListener("click", function (e) {
    var a = e.target.closest ? e.target.closest("a[href]") : null;
    if (!a || !main.contains(a) || !a.closest("[data-live-nav]")) return;
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if ((a.target && a.target !== "_self") || a.hasAttribute("download")) return;
    if (a.origin !== location.origin || a.pathname !== location.pathname || a.search === location.search) return;
    e.preventDefault();
    go(a.href);
  });

  window.addEventListener("popstate", function () {
    if (!softHistory) return;
    enqueue(function () {
      return get(here())
        .then(function (res) {
          return handle(res, { user: true, pop: true });
        })
        .catch(function () {
          location.reload();
          return false;
        });
    });
  });

  GP.live = {
    refresh: refresh,
    submit: submit,
    go: go,
    /** fn(root) вызывается для каждого блока, который появился на странице после обновления. */
    ready: function (fn) {
      ready.push(fn);
    },
    /** Блоки с обработчиками, навешанными при загрузке: если такой заменён — страница перезагружается. */
    guard: function (selector) {
      guards.push(selector);
    },
  };
})();
