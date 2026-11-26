/**
 * Редактор шага курса (страница /exams/chapter/<id>/edit).
 *
 * • Панель: оформление текста, «Фото» (загрузка прямо в текст), блоки «Ситуации», «Вопрос»,
 *   «Скрытый ответ». Блоки — обычная разметка (ul[data-type=cases|quiz], blockquote[data-variant=answer]),
 *   у сотрудника её оживляет course.js.
 * • Контекстная строка под панелью: действия для блока, в котором стоит курсор, или для выбранного фото.
 * • Фото: кнопка, вставка из буфера, перетаскивание. Загрузка — экшен upload_course_image (JSON).
 * • Несохранённые изменения: статус, предупреждение при уходе, Ctrl + S.
 * HTML всё равно чистится на сервере (sanitize_html) — здесь только удобство.
 */
(function () {
  "use strict";

  var GP = window.GP || {};
  var root = document.querySelector("[data-course-editor]");
  if (!root) return;

  var form = root.closest("form");
  var area = root.querySelector("[data-editor-area]");
  var input = root.querySelector("[data-editor-input]");
  var sourceBtn = root.querySelector("[data-editor-source]");
  var fileInput = root.querySelector("[data-editor-file]");
  var context = root.querySelector("[data-editor-context]");
  var status = form ? form.querySelector("[data-editor-status]") : null;
  var titleInput = form ? form.querySelector('input[name="title"]') : null;
  var stepId = root.getAttribute("data-step") || "";
  if (!area || !input || !form) return;

  var source = false;
  var dirty = false;
  var uploading = 0;
  var savedRange = null;
  var selectedImage = null;

  /* ---------- статус и защита от потери правок ---------- */

  function setStatus(text, tone) {
    if (!status) return;
    status.textContent = text;
    status.setAttribute("data-tone", tone || "");
  }
  function markDirty() {
    dirty = true;
    setStatus(uploading > 0 ? "Загружаем фото…" : "Есть несохранённые изменения", "dirty");
  }
  window.addEventListener("beforeunload", function (e) {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = "";
  });
  document.addEventListener("keydown", function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === "s" || e.key === "S" || e.key === "ы" || e.key === "Ы")) {
      e.preventDefault();
      if (typeof form.requestSubmit === "function") form.requestSubmit();
      else form.submit();
    }
  });
  area.addEventListener("input", markDirty);
  if (titleInput) titleInput.addEventListener("input", markDirty);
  Array.prototype.forEach.call(form.querySelectorAll('input[type="file"][name], input[name="removeImages[]"]'), function (el) {
    el.addEventListener("change", markDirty);
  });

  /* ---------- выделение ---------- */

  function rangeInArea() {
    var sel = window.getSelection();
    if (!sel || !sel.rangeCount) return null;
    var range = sel.getRangeAt(0);
    return area.contains(range.commonAncestorContainer) ? range : null;
  }
  function rememberRange() {
    var r = rangeInArea();
    if (r) savedRange = r.cloneRange();
  }
  function restoreRange() {
    area.focus();
    if (!savedRange) return;
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(savedRange);
  }
  function exec(cmd, value) {
    restoreRange();
    document.execCommand(cmd, false, value);
    rememberRange();
    markDirty();
  }
  function closestInArea(node, selector) {
    var el = node && (node.nodeType === 1 ? node : node.parentElement);
    el = el ? el.closest(selector) : null;
    return el && area.contains(el) ? el : null;
  }
  function caretNode() {
    var r = rangeInArea() || savedRange;
    return r ? r.startContainer : null;
  }
  function blockTag() {
    var el = closestInArea(caretNode(), "h2,h3,h4,blockquote,p,li");
    return el ? el.tagName : "";
  }
  /** Блок верхнего уровня (прямой потомок поля), в котором стоит курсор. */
  function topLevelBlock(node) {
    var el = node && (node.nodeType === 1 ? node : node.parentElement);
    while (el && el.parentElement !== area) {
      if (el === area) return null;
      el = el.parentElement;
    }
    return el && area.contains(el) ? el : null;
  }
  /**
   * Вставка блока целиком после текущего абзаца (не внутрь списка, цитаты или вопроса),
   * с пустым абзацем следом. DOM, а не execCommand: браузер иначе склеивает блоки
   * и теряет data-атрибуты. Возвращает первый вставленный элемент.
   */
  function insertBlock(html) {
    var anchor = topLevelBlock(caretNode());
    var tpl = document.createElement("template");
    tpl.innerHTML = html + "<p><br></p>";
    var first = tpl.content.firstElementChild;
    var tail = tpl.content.lastElementChild;
    if (anchor) anchor.after(tpl.content);
    else area.appendChild(tpl.content);
    var range = document.createRange();
    range.selectNodeContents(tail);
    range.collapse(true);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    savedRange = range.cloneRange();
    markDirty();
    return first;
  }

  /* ---------- готовые блоки ---------- */

  function escapeHtml(text) {
    return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }
  var CASE_ITEM = "<li><strong>Ситуация: что произошло</strong><span>Правильное действие администратора</span></li>";
  var QUIZ_ITEM = '<li data-checked="false">Другой вариант ответа</li>';
  var templates = {
    cases: '<ul data-type="cases">' + CASE_ITEM + CASE_ITEM + "</ul>",
    quiz:
      "<p><strong>Вопрос.</strong> Как поступить в этой ситуации?</p>" +
      '<ul data-type="quiz"><li data-checked="true">Верный вариант<blockquote>Почему это правильно — сотрудник увидит после ответа.</blockquote></li>' +
      QUIZ_ITEM +
      QUIZ_ITEM +
      "</ul>",
    answer: '<blockquote data-variant="answer"><p>Правильный ответ — откроется по кнопке.</p></blockquote>',
  };

  /* ---------- фото ---------- */

  var uploadSeq = 0;
  function defaultAlt() {
    var t = titleInput && titleInput.value.trim();
    return t ? t + " — иллюстрация" : "Иллюстрация к шагу";
  }
  function uploadFiles(files) {
    var list = Array.prototype.filter.call(files || [], function (f) {
      return /^image\//.test(f.type);
    });
    if (!list.length) return;
    list.forEach(function (file) {
      uploadSeq++;
      var token = "up" + Date.now() + "-" + uploadSeq;
      insertBlock('<p data-uploading="' + token + '" class="course-rich__uploading" contenteditable="false">Загружаем фото «' + escapeHtml(file.name) + "»…</p>");
      uploading++;
      markDirty();
      var data = new FormData();
      data.append("_action", "upload_course_image");
      data.append("_csrf", GP.csrf || "");
      data.append("stepId", stepId);
      data.append("image", file);
      fetch(window.location.pathname + window.location.search, { method: "POST", body: data, credentials: "same-origin" })
        .then(function (res) {
          return res.json().catch(function () {
            return { ok: false, error: "Сервер ответил не так, как ожидалось" };
          });
        })
        .then(function (json) {
          var holder = area.querySelector('[data-uploading="' + token + '"]');
          if (!json || !json.ok) {
            if (holder) holder.remove();
            window.alert("Фото не загрузилось: " + ((json && json.error) || "ошибка сети"));
            return;
          }
          var img = document.createElement("img");
          img.src = json.url;
          img.alt = defaultAlt();
          if (json.width && json.height) {
            img.width = json.width;
            img.height = json.height;
          }
          if (holder) holder.replaceWith(img);
          else area.appendChild(img);
          selectImage(img);
        })
        .catch(function () {
          var holder = area.querySelector('[data-uploading="' + token + '"]');
          if (holder) holder.remove();
          window.alert("Фото не загрузилось: нет связи с сервером");
        })
        .then(function () {
          uploading--;
          markDirty();
        });
    });
  }
  if (fileInput) {
    fileInput.addEventListener("change", function () {
      uploadFiles(fileInput.files);
      fileInput.value = "";
    });
  }
  area.addEventListener("paste", function (e) {
    var cd = e.clipboardData;
    if (!cd) return;
    if (cd.files && cd.files.length) {
      e.preventDefault();
      rememberRange();
      uploadFiles(cd.files);
      return;
    }
    // Текст из Word/Google Docs: оставляем структуру, убираем стили и мусор.
    var html = cd.getData("text/html");
    if (html) {
      e.preventDefault();
      document.execCommand("insertHTML", false, scrub(html, true));
      markDirty();
    }
  });
  area.addEventListener("dragover", function (e) {
    if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], "Files") !== -1) {
      e.preventDefault();
      area.classList.add("is-drop");
    }
  });
  area.addEventListener("dragleave", function () {
    area.classList.remove("is-drop");
  });
  area.addEventListener("drop", function (e) {
    area.classList.remove("is-drop");
    if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
    e.preventDefault();
    var range = document.caretRangeFromPoint ? document.caretRangeFromPoint(e.clientX, e.clientY) : null;
    if (range && area.contains(range.startContainer)) savedRange = range;
    uploadFiles(e.dataTransfer.files);
  });

  function selectImage(img) {
    if (selectedImage) selectedImage.classList.remove("is-selected");
    selectedImage = img;
    if (img) img.classList.add("is-selected");
    renderContext();
  }
  area.addEventListener("click", function (e) {
    var img = e.target.closest ? e.target.closest("img") : null;
    selectImage(img && area.contains(img) ? img : null);
  });

  /* ---------- команды панели ---------- */

  var commands = {
    bold: function () {
      exec("bold");
    },
    italic: function () {
      exec("italic");
    },
    h2: function () {
      exec("formatBlock", blockTag() === "H2" ? "p" : "h2");
    },
    h3: function () {
      exec("formatBlock", blockTag() === "H3" ? "p" : "h3");
    },
    ul: function () {
      exec("insertUnorderedList");
    },
    ol: function () {
      exec("insertOrderedList");
    },
    note: function () {
      exec("formatBlock", blockTag() === "BLOCKQUOTE" ? "p" : "blockquote");
    },
    link: function () {
      rememberRange();
      var url = (window.prompt("Адрес ссылки", "https://") || "").trim();
      if (!url || url === "https://") return;
      if (!/^(https?:\/\/|mailto:|tel:|\/)/i.test(url)) url = "https://" + url;
      exec("createLink", url);
    },
    image: function () {
      rememberRange();
      if (fileInput) fileInput.click();
    },
    cases: function () {
      insertBlock(templates.cases);
    },
    quiz: function () {
      insertBlock(templates.quiz);
    },
    answer: function () {
      insertBlock(templates.answer);
    },
    clear: function () {
      exec("removeFormat");
      exec("formatBlock", "p");
    },
  };

  root.addEventListener("mousedown", function (e) {
    // Нажатие на кнопку панели не должно снимать выделение в тексте.
    if (e.target.closest("[data-cmd], [data-ctx]")) e.preventDefault();
  });
  root.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-cmd]");
    if (!btn || source) return;
    var run = commands[btn.getAttribute("data-cmd")];
    if (run) run();
    renderContext();
  });

  /* ---------- контекстная строка ---------- */

  function ctxButton(action, label, danger) {
    return '<button type="button" class="course-rich__ctx-btn' + (danger ? " is-danger" : "") + '" data-ctx="' + action + '">' + escapeHtml(label) + "</button>";
  }
  var current = { kind: "", el: null, item: null };

  function renderContext() {
    if (!context) return;
    var node = caretNode();
    var html = "";
    current = { kind: "", el: null, item: null };
    if (selectedImage && area.contains(selectedImage)) {
      current = { kind: "image", el: selectedImage, item: null };
      html = '<span class="course-rich__ctx-label">Фото</span>' + ctxButton("alt", "Подпись для незрячих") + ctxButton("img-up", "Выше") + ctxButton("img-down", "Ниже") + ctxButton("img-remove", "Удалить фото", true);
    } else if (node) {
      var cases = closestInArea(node, 'ul[data-type="cases"]');
      var quiz = closestInArea(node, 'ul[data-type="quiz"]');
      var answer = closestInArea(node, 'blockquote[data-variant="answer"]');
      if (cases) {
        current = { kind: "cases", el: cases, item: closestInArea(node, 'ul[data-type="cases"] > li') };
        html = '<span class="course-rich__ctx-label">Ситуации</span>' + ctxButton("case-add", "+ Ситуация") + (current.item ? ctxButton("item-remove", "Удалить ситуацию") : "") + ctxButton("block-remove", "Удалить блок", true);
      } else if (quiz) {
        current = { kind: "quiz", el: quiz, item: closestInArea(node, 'ul[data-type="quiz"] > li') };
        html =
          '<span class="course-rich__ctx-label">Вопрос</span>' +
          (current.item ? ctxButton("quiz-right", "Это верный вариант") : "") +
          ctxButton("quiz-add", "+ Вариант") +
          (current.item ? ctxButton("quiz-note", "Пояснение к варианту") + ctxButton("item-remove", "Удалить вариант") : "") +
          ctxButton("block-remove", "Удалить вопрос", true);
      } else if (answer) {
        current = { kind: "answer", el: answer, item: null };
        html = '<span class="course-rich__ctx-label">Скрытый ответ</span>' + ctxButton("answer-plain", "Сделать обычным текстом") + ctxButton("block-remove", "Удалить блок", true);
      }
    }
    context.innerHTML = html;
    context.hidden = html === "";
  }
  document.addEventListener("selectionchange", function () {
    if (source) return;
    if (rangeInArea()) {
      rememberRange();
      renderContext();
    }
  });

  context &&
    context.addEventListener("click", function (e) {
      var btn = e.target.closest("[data-ctx]");
      if (!btn) return;
      var action = btn.getAttribute("data-ctx");
      var el = current.el;
      var item = current.item;
      if (!el) return;
      switch (action) {
        case "alt": {
          var alt = window.prompt("Что изображено на фото? Подпись читают программы для незрячих.", el.getAttribute("alt") || "");
          if (alt !== null) el.setAttribute("alt", alt.trim() || defaultAlt());
          break;
        }
        case "img-up":
          if (el.previousElementSibling) el.parentNode.insertBefore(el, el.previousElementSibling);
          break;
        case "img-down":
          if (el.nextElementSibling) el.parentNode.insertBefore(el.nextElementSibling, el);
          break;
        case "img-remove":
          el.remove();
          selectImage(null);
          break;
        case "case-add":
          (item || el.lastElementChild || el).insertAdjacentHTML(item ? "afterend" : "beforeend", CASE_ITEM);
          break;
        case "quiz-add":
          (item || el.lastElementChild || el).insertAdjacentHTML(item ? "afterend" : "beforeend", QUIZ_ITEM);
          break;
        case "quiz-right":
          Array.prototype.forEach.call(el.children, function (li) {
            li.setAttribute("data-checked", li === item ? "true" : "false");
          });
          break;
        case "quiz-note":
          if (item && !item.querySelector("blockquote")) item.insertAdjacentHTML("beforeend", "<blockquote>Пояснение: почему этот вариант верный или нет</blockquote>");
          break;
        case "item-remove":
          if (item && el.children.length > 1) item.remove();
          else el.remove();
          break;
        case "answer-plain":
          while (el.firstChild) el.parentNode.insertBefore(el.firstChild, el);
          el.remove();
          break;
        case "block-remove":
          el.remove();
          break;
      }
      markDirty();
      renderContext();
    });

  /* ---------- HTML-режим ---------- */

  /** Убирает опасное (скрипты, обработчики) и, для вставки, — стили и классы. */
  function scrub(html, forPaste) {
    var doc = new DOMParser().parseFromString("<div>" + html + "</div>", "text/html");
    var box = doc.body.firstChild;
    if (!box) return "";
    Array.prototype.forEach.call(box.querySelectorAll("script,style,iframe,object,embed,link,meta,form,input,button,textarea,select"), function (n) {
      n.remove();
    });
    Array.prototype.forEach.call(box.querySelectorAll("*"), function (n) {
      Array.prototype.slice.call(n.attributes).forEach(function (attr) {
        var name = attr.name.toLowerCase();
        if (/^on/.test(name) || ((name === "href" || name === "src") && /^\s*(javascript|vbscript|data):/i.test(attr.value))) n.removeAttribute(attr.name);
        else if (forPaste && (name === "style" || name === "class" || name === "id" || /^data-/.test(name))) n.removeAttribute(attr.name);
      });
    });
    return box.innerHTML;
  }
  function cleanForSave() {
    var clone = area.cloneNode(true);
    Array.prototype.forEach.call(clone.querySelectorAll("[data-uploading]"), function (n) {
      n.remove();
    });
    Array.prototype.forEach.call(clone.querySelectorAll("img"), function (img) {
      img.classList.remove("is-selected");
      img.removeAttribute("loading");
      img.removeAttribute("decoding");
      if (!img.getAttribute("class")) img.removeAttribute("class");
    });
    return clone.innerHTML;
  }
  sourceBtn &&
    sourceBtn.addEventListener("click", function () {
      source = !source;
      if (source) input.value = cleanForSave();
      else area.innerHTML = scrub(input.value, false);
      area.hidden = source;
      input.classList.toggle("hidden", !source);
      sourceBtn.setAttribute("aria-pressed", source ? "true" : "false");
      Array.prototype.forEach.call(root.querySelectorAll("[data-cmd]"), function (btn) {
        btn.disabled = source;
      });
      if (context) context.hidden = true;
      (source ? input : area).focus();
    });
  input.addEventListener("input", markDirty);

  /* ---------- сохранение ---------- */

  form.addEventListener("submit", function (e) {
    if (uploading > 0) {
      e.preventDefault();
      setStatus("Дождитесь загрузки фото — потом сохраните", "dirty");
      return;
    }
    if (!source) input.value = cleanForSave();
    dirty = false;
    setStatus("Сохраняем…", "saving");
  });
})();
