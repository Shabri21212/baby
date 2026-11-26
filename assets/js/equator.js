/*
 * GAMER PRO CRM — «Экватор. Проверка знаний на практике».
 *
 * Часть 1 — выбор действия: при ошибке показываем разбор, можно ответить снова.
 * Часть 2 — сборка последовательности: ошибочный шаг не засчитывается, даём подсказку.
 * Журнал всех нажатий отправляется на сервер — он сам считает результат с первой попытки.
 */
(function () {
  "use strict";

  var GP = window.GP || {};
  var root = document.querySelector("[data-equator]");
  // Задания рисует этот скрипт при загрузке — карточку «Экватора» тихое обновление не подменяет.
  if (GP.live) GP.live.guard("[data-equator]");
  if (!root || root.getAttribute("data-passed") === "1") return;

  var dataEl = root.querySelector("[data-eq-data]");
  var intro = root.querySelector("[data-eq-intro]");
  var stage = root.querySelector("[data-eq-stage]");
  var startBtn = root.querySelector("[data-eq-start]");
  if (!dataEl || !stage || !startBtn) return;

  var data = JSON.parse(dataEl.textContent || "{}");
  var tasks = data.tasks || [];
  var stepId = root.getAttribute("data-step");
  var LETTERS = ["A", "B", "C", "D", "E", "F"];

  var index = 0;
  var logs = [];

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = text;
    return node;
  }

  function shuffle(list) {
    var a = list.slice();
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = a[i];
      a[i] = a[j];
      a[j] = t;
    }
    // Не показываем последовательность сразу в правильном порядке.
    var sorted = a.every(function (v, k) { return v === k; });
    return sorted && a.length > 1 ? a.slice(1).concat(a[0]) : a;
  }

  function feedback(kind, title, text, note) {
    var box = el("div", "eq-feedback is-" + kind);
    box.setAttribute("role", "status");
    var head = el("p", "eq-feedback__title");
    head.appendChild(el("span", "eq-feedback__icon", kind === "right" ? "\u2713" : "!"));
    head.appendChild(document.createTextNode(title));
    box.appendChild(head);
    if (text) box.appendChild(el("p", "eq-feedback__text", text));
    if (note) box.appendChild(el("p", "eq-feedback__note", note));
    return box;
  }

  function nextButton(label) {
    var btn = el("button", "eq-btn eq-btn--primary", label);
    btn.type = "button";
    btn.addEventListener("click", function () {
      index++;
      render();
    });
    return btn;
  }

  function scrollIntoViewSoft(node) {
    var rect = node.getBoundingClientRect();
    if (rect.bottom > window.innerHeight || rect.top < 0) {
      node.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
  }

  function renderChoice(task, body) {
    var log = (logs[index] = []);
    var list = el("div", "eq-options");
    var slot = el("div", "eq-slot");
    var buttons = task.options.map(function (opt, i) {
      var btn = el("button", "eq-option");
      btn.type = "button";
      btn.appendChild(el("span", "eq-option__letter", LETTERS[i]));
      btn.appendChild(el("span", "eq-option__text", opt.text));
      btn.addEventListener("click", function () {
        if (btn.disabled) return;
        log.push(i);
        slot.textContent = "";
        if (i === task.correct) {
          buttons.forEach(function (b) { b.disabled = true; });
          btn.classList.add("is-right");
          slot.appendChild(feedback("right", "Верное решение", task.right, task.rightNote));
          slot.appendChild(nextButton(index === tasks.length - 1 ? "Завершить проверку" : "Далее"));
        } else {
          btn.disabled = true;
          btn.classList.add("is-wrong");
          slot.appendChild(feedback("wrong", "Не лучший вариант", opt.note || "Подумайте, к чему приведёт это действие.", "Прочитайте разбор и выберите другой вариант."));
        }
        scrollIntoViewSoft(slot);
      });
      list.appendChild(btn);
      return btn;
    });
    body.appendChild(list);
    body.appendChild(slot);
  }

  function renderSequence(task, body) {
    var log = (logs[index] = []);
    var n = task.steps.length;
    var pos = 0;
    var progress = el("div", "eq-seq-progress");
    var counter = el("span", "", "Собрано шагов: 0 из " + n);
    var dots = el("span", "eq-dots");
    var dotEls = task.steps.map(function () {
      var d = el("span", "eq-dot");
      dots.appendChild(d);
      return d;
    });
    progress.appendChild(counter);
    progress.appendChild(dots);
    body.appendChild(progress);

    var list = el("div", "eq-options");
    var slot = el("div", "eq-slot");
    var items = [];
    shuffle(task.steps.map(function (_, i) { return i; })).forEach(function (stepIdx) {
      var btn = el("button", "eq-option eq-option--seq");
      btn.type = "button";
      var mark = el("span", "eq-option__mark", "\u2192");
      mark.setAttribute("aria-hidden", "true");
      btn.appendChild(mark);
      btn.appendChild(el("span", "eq-option__text", task.steps[stepIdx]));
      btn.addEventListener("click", function () {
        if (btn.disabled) return;
        log.push(stepIdx);
        items.forEach(function (b) { b.classList.remove("is-wrong"); });
        slot.textContent = "";
        if (stepIdx === pos) {
          pos++;
          btn.disabled = true;
          btn.classList.add("is-right");
          mark.textContent = "\u2713";
          dotEls[pos - 1].classList.add("is-done");
          counter.textContent = "Собрано шагов: " + pos + " из " + n;
          if (pos === n) {
            slot.appendChild(feedback("right", "Последовательность собрана", task.done, task.doneNote));
            slot.appendChild(nextButton(index === tasks.length - 1 ? "Завершить проверку" : "Далее"));
          }
        } else {
          btn.classList.add("is-wrong");
          slot.appendChild(feedback("wrong", "Сейчас рано", "«" + task.steps[stepIdx] + "» сейчас не подходит.", "Выберите правильный следующий шаг."));
        }
        scrollIntoViewSoft(slot);
      });
      list.appendChild(btn);
      items.push(btn);
    });
    body.appendChild(list);
    body.appendChild(slot);
  }

  function render() {
    if (index >= tasks.length) {
      submit();
      return;
    }
    var task = tasks[index];
    var part = data.parts[task.type];
    stage.textContent = "";

    var bar = el("div", "eq-progress");
    bar.appendChild(el("p", "eq-progress__label", index + " из " + tasks.length + " заданий"));
    var track = el("div", "eq-progress__track");
    track.setAttribute("role", "progressbar");
    track.setAttribute("aria-valuemin", "0");
    track.setAttribute("aria-valuemax", String(tasks.length));
    track.setAttribute("aria-valuenow", String(index));
    var fill = el("div", "eq-progress__fill");
    fill.style.width = (index / tasks.length) * 100 + "%";
    track.appendChild(fill);
    bar.appendChild(track);
    stage.appendChild(bar);

    var grid = el("div", "eq-task");
    var side = el("div", "eq-task__side");
    side.appendChild(el("p", "eq-eyebrow", part.label));
    side.appendChild(el("h3", "eq-task__part", part.title));
    side.appendChild(el("p", "eq-task__hint", part.text));
    side.appendChild(el("p", "eq-task__count", "Задание " + (index + 1) + " / " + tasks.length));

    var body = el("div", "eq-task__body");
    body.appendChild(el("p", "eq-eyebrow", "Рабочая ситуация"));
    body.appendChild(el("p", "eq-task__situation", task.situation));
    var q = el("h3", "eq-task__question", task.question);
    q.setAttribute("tabindex", "-1");
    body.appendChild(q);

    if (task.type === "sequence") renderSequence(task, body);
    else renderChoice(task, body);

    grid.appendChild(side);
    grid.appendChild(body);
    stage.appendChild(grid);
    q.focus({ preventScroll: true });
    if (stage.getBoundingClientRect().top < 0) stage.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function statsBlock(r) {
    var dl = el("dl", "eq-stats");
    [
      [r.percent + "%", "с первой попытки"],
      [r.firstTry, "верных сразу"],
      [r.mistakes, "ошибок исправлено"],
      [r.total, "заданий завершено"],
    ].forEach(function (p) {
      var d = el("div");
      d.appendChild(el("dt", "", String(p[0])));
      d.appendChild(el("dd", "", p[1]));
      dl.appendChild(d);
    });
    return dl;
  }

  function showResult(r) {
    stage.textContent = "";
    var wrap = el("div", "eq-result");
    wrap.appendChild(el("p", "eq-eyebrow", "Проверка завершена"));
    if (r.passed) {
      wrap.appendChild(el("h2", "eq-title", "Экватор пройден"));
      wrap.appendChild(el("p", "eq-lead", "Отличный результат — модуль закрыт. Повторно пройти проверку нельзя."));
      wrap.appendChild(statsBlock(r));
      var go = el("a", "eq-btn eq-btn--primary", r.next ? "Перейти: " + (r.next.module || r.next.chapter) : "К курсу");
      go.href = r.next ? r.next.href : r.courseHref;
      wrap.appendChild(go);
      var nextLink = document.querySelector("[data-eq-next]");
      if (nextLink && nextLink.tagName !== "A") {
        var a = el("a", nextLink.className.replace("pointer-events-none", "").replace("opacity-60", ""), "Дальше");
        a.href = go.href;
        nextLink.replaceWith(a);
      }
    } else {
      wrap.appendChild(el("h2", "eq-title", "Нужно закрепить решения"));
      wrap.appendChild(el("p", "eq-lead", "Все задания завершены, но для зачёта нужно больше решений с первой попытки."));
      wrap.appendChild(statsBlock(r));
      var rule = el("div", "eq-rule");
      rule.appendChild(el("strong", "", "Проходной результат — " + data.pass + "%"));
      rule.appendChild(el("span", "", "Пройдите ещё раз. Следующие модули откроются после зачёта."));
      wrap.appendChild(rule);
      var again = el("button", "eq-btn eq-btn--primary", "Пройти ещё раз");
      again.type = "button";
      again.addEventListener("click", start);
      wrap.appendChild(again);
    }
    stage.appendChild(wrap);
    stage.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function showError(message) {
    stage.textContent = "";
    var wrap = el("div", "eq-result");
    wrap.appendChild(feedback("wrong", "Не удалось сохранить результат", message, ""));
    var again = el("button", "eq-btn eq-btn--primary", "Пройти ещё раз");
    again.type = "button";
    again.addEventListener("click", start);
    wrap.appendChild(again);
    stage.appendChild(wrap);
  }

  function submit() {
    stage.textContent = "";
    stage.appendChild(el("p", "eq-lead", "Сохраняем результат…"));
    var form = new FormData();
    form.append("_action", "submit_equator");
    form.append("_csrf", GP.csrf || "");
    form.append("stepId", stepId);
    form.append("logs", JSON.stringify(logs));
    fetch(window.location.pathname + window.location.search, {
      method: "POST",
      body: form,
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (res) {
        return res.json().catch(function () { return { ok: false, error: "Сервер вернул неожиданный ответ" }; });
      })
      .then(function (r) {
        if (r && r.ok) showResult(r);
        else showError((r && r.error) || "Попробуйте ещё раз.");
      })
      .catch(function () {
        showError("Нет соединения с сервером. Проверьте сеть и попробуйте ещё раз.");
      });
  }

  function start() {
    index = 0;
    logs = [];
    if (intro) intro.hidden = true;
    stage.hidden = false;
    render();
  }

  startBtn.addEventListener("click", start);
})();
