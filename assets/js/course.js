/*
 * GAMER PRO CRM — урок курса обучения.
 *
 * • Карточки «Ситуация → ответ» (ul[data-type=cases]) и мини-проверка (ul[data-type=quiz])
 *   собираются из обычной разметки шага — её можно править в редакторе как статью.
 * • Кнопка «Дальше» у непройденного шага ждёт, пока шаг дочитан до конца, открыты все
 *   ситуации и решена проверка. Порядок шагов при этом всё равно проверяет сервер.
 * • Позиция чтения сохраняется (sessionStorage + сервер), чтобы вернуться туда же.
 */
(function () {
  "use strict";

  var GP = window.GP || {};
  var one = GP.one;
  if (!one) return;

  var root = one("[data-course-reader]");
  if (!root) return;

  var stepId = parseInt(root.getAttribute("data-step") || "0", 10);
  var initial = parseInt(root.getAttribute("data-scroll") || "0", 10);
  // data-free-reading — в настройках курса отключено требование дочитать шаг и решить задания.
  var alreadyRead = root.getAttribute("data-read") === "1" || root.hasAttribute("data-free-reading");
  var nextBtn = one("button[data-step-next]");
  var hint = one("[data-step-hint]");
  if (!stepId) return;

  var need = { end: !alreadyRead, cases: 0, quiz: 0, answers: 0 };

  /** Текст блока по абзацам — без слипания соседних <p>. */
  function textOf(el) {
    var parts = Array.prototype.map.call(el.children.length ? el.children : [el], function (child) {
      return child.textContent.trim();
    });
    return parts.filter(Boolean).join(" ");
  }

  /* ---------- «Правильный ответ» открытого вопроса: скрыт до нажатия ---------- */
  Array.prototype.forEach.call(root.querySelectorAll('blockquote[data-variant="answer"]'), function (answer) {
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "answer-reveal";
    btn.textContent = "Показать правильный ответ";
    answer.hidden = true;
    if (!alreadyRead) need.answers++;
    btn.addEventListener("click", function () {
      answer.hidden = false;
      btn.remove();
      if (!alreadyRead) need.answers--;
      update();
      answer.setAttribute("tabindex", "-1");
      answer.focus({ preventScroll: true });
    });
    answer.parentNode.insertBefore(btn, answer);
  });

  /* ---------- карточки «Ситуация» ---------- */
  Array.prototype.forEach.call(root.querySelectorAll('ul[data-type="cases"] > li'), function (li) {
    var situation = li.querySelector("strong");
    var answer = li.querySelector("span");
    var label = li.querySelector("em");
    if (!situation || !answer) return;
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "case-card";
    btn.setAttribute("aria-expanded", "false");
    btn.innerHTML =
      '<span class="case-card__front"><span class="case-card__label">Ситуация</span>' +
      '<span class="case-card__text"></span><span class="case-card__hint">Показать ответ →</span></span>' +
      '<span class="case-card__answer" aria-live="polite"><span class="case-card__label">Правильное действие</span>' +
      '<span class="case-card__text"></span><span class="case-card__hint">← Вернуться к ситуации</span></span>';
    btn.querySelector(".case-card__front .case-card__text").textContent = situation.textContent;
    btn.querySelector(".case-card__answer .case-card__text").textContent = answer.textContent;
    if (label && label.textContent.trim() !== "") {
      btn.querySelector(".case-card__answer .case-card__label").textContent = label.textContent.trim();
    }
    var seen = false;
    if (!alreadyRead) need.cases++;
    btn.addEventListener("click", function () {
      var open = btn.getAttribute("aria-expanded") === "true";
      btn.setAttribute("aria-expanded", open ? "false" : "true");
      if (!open && !seen) {
        seen = true;
        if (!alreadyRead) need.cases--;
        update();
      }
    });
    li.textContent = "";
    li.appendChild(btn);
  });

  /* ---------- мини-проверка ---------- */
  Array.prototype.forEach.call(root.querySelectorAll('ul[data-type="quiz"]'), function (list) {
    var options = Array.prototype.slice.call(list.children);
    if (!options.length) return;
    var feedback = document.createElement("div");
    feedback.className = "quiz-feedback";
    feedback.setAttribute("aria-live", "polite");
    var solved = false;
    if (!alreadyRead) need.quiz++;
    var buttons = options.map(function (li, i) {
      var note = li.querySelector("blockquote");
      var explanation = note ? textOf(note) : "";
      if (note) note.remove();
      var text = li.textContent.trim();
      var right = li.getAttribute("data-checked") === "true";
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "quiz-option";
      btn.innerHTML = '<span class="quiz-option__letter"></span><span class="quiz-option__text"></span>';
      btn.querySelector(".quiz-option__letter").textContent = "АБВГДЕЖЗ".charAt(i) || String(i + 1);
      btn.querySelector(".quiz-option__text").textContent = text;
      btn.addEventListener("click", function () {
        if (solved) return;
        buttons.forEach(function (b) {
          b.classList.remove("is-wrong");
        });
        feedback.textContent = "";
        feedback.classList.toggle("is-wrong", !right);
        var title = document.createElement("strong");
        var body = document.createElement("span");
        body.textContent = explanation;
        if (right) {
          solved = true;
          btn.classList.add("is-right");
          title.textContent = "Верно";
          buttons.forEach(function (b) {
            b.disabled = true;
          });
          if (!alreadyRead) need.quiz--;
          update();
        } else {
          btn.classList.add("is-wrong");
          title.textContent = "Неверный выбор";
          body.textContent = (explanation + " Исправьте решение и попробуйте ещё раз.").trim();
        }
        feedback.appendChild(title);
        feedback.appendChild(body);
      });
      li.textContent = "";
      li.appendChild(btn);
      return btn;
    });
    list.parentNode.insertBefore(feedback, list.nextSibling);
  });

  /* ---------- гейт кнопки «Дальше» ---------- */
  function blockers() {
    var list = [];
    if (need.end) list.push("дочитайте шаг до конца");
    if (need.cases > 0) list.push("откройте все ситуации (осталось " + need.cases + ")");
    if (need.quiz > 0) list.push("ответьте на вопрос правильно");
    if (need.answers > 0) list.push("подумайте над вопросом и откройте правильный ответ");
    return list;
  }

  function update() {
    if (!nextBtn) return;
    var list = blockers();
    if (list.length) {
      nextBtn.setAttribute("aria-disabled", "true");
      if (hint) hint.textContent = "Чтобы открыть следующий шаг: " + list.join(", ") + ".";
    } else {
      nextBtn.removeAttribute("aria-disabled");
      if (hint) hint.textContent = "Готово — нажмите «" + nextBtn.textContent.trim() + "», чтобы открыть следующий шаг.";
    }
  }

  if (nextBtn) {
    nextBtn.addEventListener("click", function (event) {
      if (nextBtn.getAttribute("aria-disabled") === "true") {
        event.preventDefault();
        if (hint) {
          hint.classList.remove("text-primary-ink");
          void hint.offsetWidth;
          hint.classList.add("text-primary-ink");
        }
      }
    });
  }

  /* ---------- позиция чтения ---------- */
  var storeKey = "gamerpro-course-scroll:" + stepId;
  var lastSent = -1;
  var lastPercent = 0;
  var timer = 0;

  function percent() {
    var rect = root.getBoundingClientRect();
    var top = rect.top + window.scrollY;
    var height = Math.max(rect.height, 1);
    var seen = window.scrollY + window.innerHeight - top;
    return Math.max(0, Math.min(100, Math.round((seen / height) * 100)));
  }

  function send(value, beacon) {
    if (!GP.csrf || value === lastSent) return;
    var data = new FormData();
    data.append("_action", "course_progress");
    data.append("_csrf", GP.csrf);
    data.append("stepId", String(stepId));
    data.append("percent", String(value));
    var url = window.location.pathname + window.location.search;
    lastSent = value;
    if (beacon && navigator.sendBeacon) {
      navigator.sendBeacon(url, data);
      return;
    }
    fetch(url, {
      method: "POST",
      body: data,
      credentials: "same-origin",
      keepalive: true,
      headers: { "X-Requested-With": "XMLHttpRequest" },
    }).catch(function () {
      lastSent = -1;
    });
  }

  function track() {
    var value = percent();
    lastPercent = Math.max(lastPercent, value);
    try {
      sessionStorage.setItem(storeKey, String(Math.round(window.scrollY)));
    } catch (err) {
      /* приватный режим — без запоминания позиции */
    }
    if (!timer) {
      timer = window.setTimeout(function () {
        timer = 0;
        send(lastPercent, false);
      }, 1500);
    }
  }

  var restored = 0;
  try {
    restored = parseInt(sessionStorage.getItem(storeKey) || "0", 10) || 0;
  } catch (err) {
    restored = 0;
  }
  if (restored > 0) {
    window.scrollTo(0, restored);
  } else if (initial > 0 && initial < 92) {
    var rect = root.getBoundingClientRect();
    var target = rect.top + window.scrollY + (rect.height * initial) / 100 - window.innerHeight / 2;
    window.scrollTo(0, Math.max(0, Math.round(target)));
  }

  // Конец шага отмечается невидимой меткой: короткий шаг, целиком влезающий в экран, засчитывается сразу.
  if (need.end && "IntersectionObserver" in window) {
    var sentinel = document.createElement("span");
    sentinel.setAttribute("aria-hidden", "true");
    sentinel.style.display = "block";
    sentinel.style.height = "1px";
    root.appendChild(sentinel);
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          need.end = false;
          io.disconnect();
          update();
        }
      });
    });
    io.observe(sentinel);
  } else {
    need.end = false;
  }
  update();

  document.addEventListener("scroll", track, { passive: true });
  window.addEventListener("resize", track);

  function flush() {
    if (lastPercent > lastSent) send(lastPercent, true);
  }
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") flush();
  });
  window.addEventListener("pagehide", flush);
})();
