#!/usr/bin/env node
/**
 * scripts/build-course.cjs
 *
 * Собирает курс обучения в course_content/course.json (+ картинки в assets/course_images/).
 * Портал при открытии «Обучения» сам импортирует этот файл в базу (Course::syncSeed),
 * повторный импорт происходит только при смене "version".
 *
 * Источник — полная сохранённая копия курса (course_full23.html): по экрану на каждый шаг,
 * текст шага лежит в .tf-step-html, картинки — data: URI. Экраны повторяются, дубли
 * отбрасываются по ключу «раздел + глава + номер шага».
 *
 * Запуск:
 *   node scripts/build-course.cjs --source ~/course/course_full23.html
 *
 * Картинки сжимаются в WebP через sharp, если он доступен (NODE_PATH=/путь/node_modules),
 * иначе копируются как есть. В проект sharp не ставится — сборка выполняется редко.
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

let sharp = null;
try {
  sharp = require('sharp');
} catch {
  sharp = null;
}

const ROOT = path.resolve(__dirname, '..');
const OUT_JSON = path.join(ROOT, 'course_content', 'course.json');
const IMG_DIR = path.join(ROOT, 'assets', 'course_images');
const IMG_URL = '/assets/course_images/';
const MAX_WIDTH = 1600;

function arg(name) {
  const i = process.argv.indexOf('--' + name);
  return i > 0 ? process.argv[i + 1] : null;
}

const entities = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', laquo: '«', raquo: '»', mdash: '—', ndash: '–' };
function decode(s) {
  return s
    .replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(Number(n)))
    .replace(/&#x([0-9a-f]+);/gi, (_, n) => String.fromCodePoint(parseInt(n, 16)))
    .replace(/&([a-z]+);/gi, (m, n) => entities[n.toLowerCase()] ?? m);
}
function escapeHtml(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function plain(html) {
  return decode(html.replace(/<!--.*?-->/gs, '').replace(/<[^>]+>/g, ' ')).replace(/\s+/g, ' ').trim();
}

/* ---------- картинки ---------- */

const images = [];
function queueImage(name, buffer) {
  images.push({ name, buffer });
  return IMG_URL + name;
}
/** Метка размеров картинки: после сжатия заменяется на width/height (без сдвига вёрстки при загрузке). */
function dimToken(name) {
  return '{{dim:' + name + '}}';
}
async function writeImages() {
  fs.mkdirSync(IMG_DIR, { recursive: true });
  const dims = {};
  for (const img of images) {
    const out = path.join(IMG_DIR, img.name);
    if (sharp) {
      const info = await sharp(img.buffer).rotate().resize({ width: MAX_WIDTH, withoutEnlargement: true }).webp({ quality: 78, effort: 5 }).toFile(out);
      dims[img.name] = ' width="' + info.width + '" height="' + info.height + '"';
    } else {
      fs.writeFileSync(out, img.buffer);
      dims[img.name] = '';
    }
  }
  return dims;
}
function imageName(chapterKey, n, ext) {
  return 'ch' + chapterKey + '-' + n + (sharp ? '.webp' : ext);
}

/* ---------- разбор копии курса ---------- */

/** Экран → { eyebrow, chapter, stepNo, html }. Пустые экраны (экватор, экзамен) тоже возвращаются. */
function parseScreens(file) {
  const html = fs.readFileSync(file, 'utf8');
  const parts = html.split(/<div class="saved-screen-wrapper"[^>]*>/).slice(1);
  const screens = [];
  for (const part of parts) {
    const top = part.match(/class="lesson-topline"[^>]*>\s*<div>\s*<p class="eyebrow">(.*?)<\/p>\s*<h2>(.*?)<\/h2>/s);
    if (!top) continue;
    const pill = part.match(/class="step-pill"[^>]*>(.*?)<\/div>/s);
    const step = part.match(/<div class="tf-step-html">(.*?)<\/div><\/div><\/div><\/article>/s);
    const stepNo = pill ? Number((plain(pill[1]).match(/Шаг\s+(\d+)/) || [])[1] || 1) : 1;
    screens.push({
      eyebrow: plain(top[1]),
      chapter: plain(top[2]).replace(/\s+/g, ' '),
      stepNo,
      html: step ? step[1] : '',
    });
  }
  return screens;
}

/** Флип-карточки «Ситуация → ответ» → ul[data-type=cases] (интерактив собирает assets/js/course.js). */
function convertFlipCards(html) {
  return html.replace(/<div class="flip-card-grid">(.*?)<\/button>\s*<\/div>/gs, (block) => {
    const cards = [...block.matchAll(/<button class="flip-card"[^>]*>(.*?)<\/button>/gs)].map((m) => {
      const front = m[1].match(/flip-card-front">.*?<span class="flip-card-content">(.*?)<\/span>\s*<span class="flip-card-action"/s);
      const back = m[1].match(/flip-card-back">.*?<span class="flip-card-content">(.*?)<\/span>\s*<span class="flip-card-action"/s);
      return { situation: front ? plain(front[1]) : '', answer: back ? plain(back[1]) : '' };
    }).filter((c) => c.situation !== '');
    if (!cards.length) return '';
    return '<ul data-type="cases">' + cards.map((c) => '<li><strong>' + escapeHtml(c.situation) + '</strong><span>' + escapeHtml(c.answer) + '</span></li>').join('') + '</ul>';
  });
}

/**
 * Практика в копии курса записана текстом вместе с ответом. Делаем её интерактивной:
 *   «Вариант А / Б … Правильный ответ: вариант Б.» → ul[data-type=quiz] (ответ выбирается);
 *   «Правильный ответ: …» без вариантов          → blockquote[data-variant=answer] (ответ открывается кнопкой).
 */
function convertPractice(html) {
  const choice = html.match(/((?:<p><strong>Вариант\s+[А-ЯA-Z]:<\/strong>.*?<\/p>)+)<p><strong>Правильный ответ:\s*вариант\s+([А-ЯA-Z])\.?<\/strong><\/p>(.*)$/s);
  if (choice) {
    const [whole, variantsHtml, correct, explanation] = choice;
    const options = [...variantsHtml.matchAll(/<p><strong>Вариант\s+([А-ЯA-Z]):<\/strong>\s*(.*?)<\/p>/gs)].map((m) => {
      const text = plain(m[2]).replace(/^./, (ch) => ch.toUpperCase());
      const isRight = m[1] === correct;
      const note = isRight && explanation.trim() !== '' ? '<blockquote>' + explanation + '</blockquote>' : '';
      return '<li data-checked="' + (isRight ? 'true' : 'false') + '">' + escapeHtml(text) + note + '</li>';
    });
    return html.slice(0, html.length - whole.length) + '<ul data-type="quiz">' + options.join('') + '</ul>';
  }
  const open = html.match(/<p><strong>Правильный ответ:\s*<\/strong>\s*(.*?)<\/p>(.*)$/s);
  if (open) {
    const [whole, first, rest] = open;
    const body = (first.trim() !== '' ? '<p>' + first.trim() + '</p>' : '') + rest;
    return html.slice(0, html.length - whole.length) + '<blockquote data-variant="answer">' + body + '</blockquote>';
  }
  return html;
}

/** Картинки из data: URI → файлы; подпись figcaption идёт отдельной строкой под картинкой. */
function convertFigures(html, key, counter, fallbackAlt) {
  return html.replace(/<figure class="training-media">\s*<img src="data:image\/([a-z+]+);base64,([^"]+)"([^>]*)>\s*(?:<figcaption>(.*?)<\/figcaption>)?\s*<\/figure>/gs, (_, type, b64, attrs, caption) => {
    counter.n++;
    const ext = type === 'jpeg' ? '.jpg' : '.' + type.replace('+xml', '');
    const name = imageName(key, counter.n, ext);
    const url = queueImage(name, Buffer.from(b64, 'base64'));
    const altAttr = attrs.match(/alt="([^"]*)"/);
    let alt = altAttr ? plain(altAttr[1]) : '';
    if (alt === '' || alt === 'Обучающий материал') alt = caption ? plain(caption) : fallbackAlt + ' — иллюстрация';
    const img = '<img src="' + url + '" alt="' + escapeHtml(alt) + '"' + dimToken(name) + '>';
    return caption ? img + '<p><em>' + escapeHtml(plain(caption)) + '</em></p>' : img;
  });
}

/** Шаг: первый заголовок (h2/h3) — название шага, остальное — HTML. */
function buildStep(raw, key, counter, chapterTitle) {
  let html = raw.replace(/<!--.*?-->/gs, '');
  const head = html.match(/^\s*<(h2|h3)>(.*?)<\/\1>/s);
  const title = head ? plain(head[2]) : '';
  if (head) html = html.slice(head[0].length);
  html = convertFlipCards(html);
  html = convertPractice(html.replace(/>\s+</g, '><'));
  html = convertFigures(html, key, counter, title || chapterTitle);
  html = html.replace(/>\s+</g, '><').trim();
  return { title, html };
}

/* ---------- «Экватор»: в копии курса пустой экран, собирается вручную по сценарию клуба ---------- */

function equatorSteps() {
  const html = [
    '<p>Принимайте решения как администратор: выбирайте действие, исправляйте ошибки и собирайте смену в правильной последовательности.</p>',
    '<h3>Как проходит проверка</h3>',
    '<ol>',
    '<li><strong>Решайте рабочие ситуации.</strong> Вопросы проверяют действия, а не память на названия.</li>',
    '<li><strong>Исправляйте ошибки.</strong> Разбор объяснит риск, после чего можно ответить снова.</li>',
    '<li><strong>Разные форматы.</strong> Выбор, несколько ответов, последовательности и распределение.</li>',
    '<li><strong>Результат с первой попытки.</strong> В конце — точность и темы для повторения.</li>',
    '</ol>',
    '<h3>Часть 1. Ситуации во время смены</h3>',
    '<p>Выберите первое правильное действие. При ошибке прочитайте разбор и попробуйте ещё раз.</p>',
    '<p><strong>Задание 1.</strong> Вы приняли смену и хотите проверить, не оставил ли руководитель важную инструкцию на сегодня. С чего начать?</p>',
    '<ul data-type="quiz">',
    '<li data-checked="false">Искать сообщение руководителя в «Чатах»<blockquote>«Чаты» предназначены для переписки с гостями. Служебная информация находится в истории уведомлений.</blockquote></li>',
    '<li data-checked="false">Ждать, пока следующий сотрудник расскажет устно<blockquote>Устная передача не заменяет служебные уведомления: важная инструкция может потеряться или дойти с искажениями.</blockquote></li>',
    '<li data-checked="true">Открыть «Историю уведомлений» и прочитать сообщение полностью<blockquote>Инструкции руководителя и напоминания приходят в «Историю уведомлений» — с неё и начинается смена.</blockquote></li>',
    '</ul>',
  ].join('');
  return [{ title: 'Проверьте себя в рабочих ситуациях', html }];
}

/* ---------- сборка ---------- */

async function main() {
  const source = arg('source');
  if (!source) {
    console.error('Использование: node scripts/build-course.cjs --source <course_full.html>');
    process.exit(1);
  }
  const modules = [];
  const seen = new Set();
  for (const screen of parseScreens(source)) {
    const id = screen.eyebrow + '|' + screen.chapter + '|' + screen.stepNo;
    if (seen.has(id)) continue;
    seen.add(id);

    const numbered = screen.eyebrow.match(/^Модуль\s+(\d+)\.\s*(.+)$/i);
    const moduleTitle = numbered ? numbered[2].trim() : screen.eyebrow;
    const kind = /экзамен/i.test(moduleTitle) ? 'exam' : numbered ? 'lessons' : 'intro';
    let mod = modules.find((m) => m.title === moduleTitle);
    if (!mod) {
      mod = { title: moduleTitle, kind, chapters: [] };
      modules.push(mod);
    }
    if (kind === 'exam') continue;

    let chapter = mod.chapters.find((c) => c.title === screen.chapter);
    if (!chapter) {
      const n = modules.reduce((s, m) => s + m.chapters.length, 0) + 1;
      chapter = { key: String(n).padStart(2, '0'), title: screen.chapter, steps: [], counter: { n: 0 }, byNo: new Map() };
      mod.chapters.push(chapter);
    }
    if (/^Экватор/i.test(screen.chapter)) {
      if (!chapter.steps.length) chapter.steps = equatorSteps();
      continue;
    }
    if (screen.html.trim() === '' || chapter.byNo.has(screen.stepNo)) continue;
    chapter.byNo.set(screen.stepNo, buildStep(screen.html, chapter.key, chapter.counter, screen.chapter));
  }
  for (const m of modules) {
    for (const c of m.chapters) {
      if (c.byNo.size) c.steps = [...c.byNo.entries()].sort((a, b) => a[0] - b[0]).map(([, s]) => s);
    }
  }
  if (!modules.some((m) => m.kind === 'exam')) {
    modules.push({ title: 'Экзамен', kind: 'exam', chapters: [] });
  }

  for (const f of fs.existsSync(IMG_DIR) ? fs.readdirSync(IMG_DIR) : []) {
    if (/^ch\d+-/.test(f)) fs.unlinkSync(path.join(IMG_DIR, f));
  }
  const dims = await writeImages();
  const payload = modules.map((m) => ({
    title: m.title,
    kind: m.kind,
    chapters: m.chapters.map((c) => ({
      key: c.key,
      title: c.title,
      steps: c.steps.map((s) => ({ title: s.title, html: s.html.replace(/\{\{dim:([^}]+)\}\}/g, (_, name) => dims[name] ?? '') })),
    })),
  }));
  const version = crypto.createHash('sha256').update(JSON.stringify(payload)).digest('hex').slice(0, 16);
  fs.mkdirSync(path.dirname(OUT_JSON), { recursive: true });
  fs.writeFileSync(OUT_JSON, JSON.stringify({ version, modules: payload }, null, 2) + '\n');

  const chapters = payload.reduce((s, m) => s + m.chapters.length, 0);
  const steps = payload.reduce((s, m) => s + m.chapters.reduce((t, c) => t + c.steps.length, 0), 0);
  console.log('[course] version ' + version + ': разделов ' + payload.length + ', глав ' + chapters + ', шагов ' + steps + ', картинок ' + images.length + (sharp ? ' (webp)' : ' (без сжатия)'));
  for (const m of payload) {
    console.log('  [' + m.kind + '] ' + m.title);
    for (const c of m.chapters) {
      console.log('    ' + c.key + ' ' + c.title + ' — ' + c.steps.length + ' шаг.');
    }
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
