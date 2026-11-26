<?php
declare(strict_types=1);

const HANDOVER_PHASES = [
    'open' => ['label' => 'Приём смены', 'verb' => 'Принять смену', 'done' => 'Принята', 'missing' => 'Не принята', 'icon' => 'clipboard-check'],
    'close' => ['label' => 'Сдача смены', 'verb' => 'Сдать смену', 'done' => 'Сдана', 'missing' => 'Не сдана', 'icon' => 'wallet'],
];

/** Принять смену можно заранее, сдать — с опозданием: админ часто заполняет кассу уже после пересменки. */
const HANDOVER_EARLY_MINUTES = 90;
const HANDOVER_LATE_MINUTES = 180;

/** Расхождение кассы: [текст, тон]. Тон: ok, over, short или '' — не посчитать. */
function cash_diff(?int $expected, ?int $actual): array
{
    if ($expected === null || $actual === null) {
        return ['—', ''];
    }
    $d = $actual - $expected;
    if ($d === 0) {
        return ['Сходится', 'ok'];
    }
    return $d > 0 ? ['+' . money_rub($d) . ' излишек', 'over'] : ['−' . money_rub(-$d) . ' недостача', 'short'];
}

function cash_diff_class(string $tone): string
{
    return $tone === 'short' ? 'text-primary-ink' : ($tone === '' ? 'text-muted-foreground' : 'text-foreground');
}

trait PagesHandover
{
    /** Смена сотрудника, по которой сейчас открыт чек-лист: идёт, скоро начнётся или недавно закончилась. */
    private static function handoverShift(array $user): ?array
    {
        $eid = $user['employeeId'] ?? null;
        if (!$eid) {
            return null;
        }
        $now = now_tyumen();
        $rows = Db::all(
            'SELECT s.id, s.date, s."branchId", b.name AS "branchName", b.slug AS "branchSlug", s."shiftType", s."employeeId"
             FROM shifts s JOIN branches b ON b.id = s."branchId"
             WHERE s."employeeId" = ? AND s.date >= ? AND s.date <= ?
             ORDER BY s.date',
            [(int) $eid, $now->modify('-1 day')->format('Y-m-d'), $now->modify('+1 day')->format('Y-m-d')],
        );
        $best = null;
        $bestRank = PHP_INT_MAX;
        foreach ($rows as $r) {
            $r['date'] = substr((string) $r['date'], 0, 10);
            $st = shift_state($r['date'], (string) $r['shiftType'], $now, (int) $r['branchId']);
            $rank = match ($st['state']) {
                'live' => 0,
                'upcoming' => $st['minutesToStart'] <= HANDOVER_EARLY_MINUTES ? 1 : null,
                default => ($now->getTimestamp() - $st['end']->getTimestamp()) <= HANDOVER_LATE_MINUTES * 60 ? 2 : null,
            };
            if ($rank !== null && $rank < $bestRank) {
                $r['state'] = $st;
                $best = $r;
                $bestRank = $rank;
            }
        }
        return $best;
    }

    /** Активные пункты чек-листа: ['open' => [...], 'close' => [...]]. */
    private static function checklistItems(): array
    {
        $out = ['open' => [], 'close' => []];
        foreach (Db::all('SELECT id, title, phase FROM shift_checklist_items WHERE "isActive" = 1 ORDER BY "sortOrder", id') as $it) {
            if (isset($out[$it['phase']])) {
                $out[$it['phase']][] = ['id' => (int) $it['id'], 'title' => (string) $it['title']];
            }
        }
        return $out;
    }

    /** Отчёты по смене филиала: phase => строка отчёта с отметками checks[itemId] => bool. */
    private static function handoverReports(int $branchId, string $date, string $type): array
    {
        $out = [];
        foreach (Db::all('SELECT * FROM shift_reports WHERE "branchId" = ? AND date = ? AND "shiftType" = ?', [$branchId, $date, $type]) as $r) {
            $r['checks'] = [];
            foreach (Db::all('SELECT "itemId", done FROM shift_report_checks WHERE "reportId" = ?', [(int) $r['id']]) as $c) {
                $r['checks'][(int) $c['itemId']] = boolish($c['done']);
            }
            $out[(string) $r['phase']] = $r;
        }
        return $out;
    }

    /** Последняя сдача смены на филиале перед этой — по концу окна смены, не по createdAt (его переписывают при правках). */
    private static function previousHandover(int $branchId, string $date, string $type): ?array
    {
        [$start] = shift_window($date, $type, $branchId);
        $rows = Db::all(
            'SELECT * FROM shift_reports
             WHERE "branchId" = ? AND phase = ? AND date >= ? AND date <= ?
               AND NOT (date = ? AND "shiftType" = ?)',
            [$branchId, 'close', add_days($date, -3), $date, $date, $type],
        );
        $best = null;
        $bestEnd = null;
        foreach ($rows as $r) {
            $d = substr((string) $r['date'], 0, 10);
            $t = (string) $r['shiftType'];
            if (!isset(SHIFT_LABELS[$t]) && $t !== 'day' && $t !== 'night' && $t !== 'full') {
                continue;
            }
            [, $end] = shift_window($d, $t, $branchId);
            if ($end > $start) {
                continue;
            }
            if ($bestEnd === null || $end > $bestEnd) {
                $best = $r;
                $bestEnd = $end;
            }
        }
        return $best;
    }

    /** Какую фазу предложить на «Моём дне»: open, close или null, если всё заполнено или сдавать рано. */
    private static function handoverNextPhase(array $shift, array $reports): ?string
    {
        if (!isset($reports['open']) && $shift['state']['state'] !== 'done') {
            return 'open';
        }
        if (!isset($reports['close']) && ($shift['state']['state'] === 'done' || $shift['state']['minutesLeft'] <= 120)) {
            return 'close';
        }
        return null;
    }

    private static function pageHandover(array $user): void
    {
        $canHistory = can($user, 'journal.view');
        $html = page_head('Приём и сдача смены', 'Чек-лист и касса этого клуба. Остаток подставляет Langame, вы вписываете только факт в ящике.');
        $shift = self::handoverShift($user);
        if ($shift) {
            $html .= self::handoverWorkspace($shift);
        } else {
            $text = $user['employeeId']
                ? 'Чек-лист открывается за полтора часа до начала смены и доступен ещё три часа после её конца. Если вышли за кого-то — попросите поставить смену в графике.'
                : 'Учётная запись не привязана к сотруднику — чек-листы заполняют администраторы на своих сменах.';
            $html .= empty_state('clipboard-check', 'Сейчас у вас нет смены', $text, '<a class="' . btn_class('outline') . '" href="' . h(url_to('/schedule')) . '">' . icon('calendar') . 'График смен</a>');
        }
        $opts = ['title' => 'Приём и сдача'];
        if ($shift) {
            Langame::requestRefresh((int) $shift['branchId']);
            $opts['langameBranches'] = [(int) $shift['branchId']];
        }
        if ($canHistory) {
            // Журнал тяжёлый (2 недели отчётов) — показываем по запросу, иначе владелец ждёт синхронный разбор всех пунктов.
            if (self::getStr('history', 4) === '1') {
                $html .= self::handoverHistory($user);
            } else {
                $html .= '<div class="mt-8 border-t border-border pt-6"><a class="' . btn_class('outline') . '" href="' . h(url_to('/shift', ['history' => '1'])) . '">' . icon('history', 'size-4') . 'Открыть журнал приёма и сдачи</a>';
                $html .= '<p class="mt-2 text-xs text-muted-foreground">Журнал подгружается отдельно — так страница смены открывается быстрее.</p></div>';
            }
        }
        self::layout($user, $html, $opts);
    }

    private static function handoverWorkspace(array $shift): string
    {
        $branchId = (int) $shift['branchId'];
        $date = (string) $shift['date'];
        $type = (string) $shift['shiftType'];
        $reports = self::handoverReports($branchId, $date, $type);
        $items = self::checklistItems();
        $prev = self::previousHandover($branchId, $date, $type);

        $aside = '<div class="flex min-w-0 flex-col gap-6 lg:sticky lg:top-9 lg:col-start-2 lg:row-start-1">';
        $aside .= self::handoverTicket($shift, $reports) . self::handoverDrawerCard($shift) . self::previousHandoverCard($prev) . '</div>';

        $forms = '<div class="flex min-w-0 flex-col gap-6 lg:col-start-1 lg:row-start-1">';
        $st = $shift['state'];
        $canClose = $st['state'] === 'done' || $st['minutesLeft'] <= 120;
        foreach (array_keys(HANDOVER_PHASES) as $phase) {
            if ($phase === 'close' && !$canClose && empty($reports['close'])) {
                $forms .= card(
                    card_head('Сдача смены', 'Откроется в последние два часа смены или после её окончания.', chip('Рано', 'outline', 'clock'), 'wallet')
                    . '<p class="mt-4 text-sm leading-relaxed text-muted-foreground">Сейчас можно принять смену. Остаток по Langame уже виден справа. Факт при сдаче впишете в последние два часа.</p>',
                    'p-5',
                );
                continue;
            }
            $forms .= self::handoverForm($shift, $phase, $items[$phase], $reports[$phase] ?? null);
        }
        $forms .= '</div>';

        return '<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">' . $aside . $forms . '</div>';
    }

    private static function handoverTicket(array $shift, array $reports): string
    {
        $type = (string) $shift['shiftType'];
        $st = $shift['state'];
        $badge = match ($st['state']) {
            'live' => '<span class="inline-flex items-center gap-2 rounded-[5px] bg-primary px-2 py-0.5 text-xs leading-5 font-semibold text-primary-foreground"><span class="live-dot" aria-hidden="true"></span>Идёт сейчас</span>',
            'upcoming' => '<span class="rounded-[5px] bg-sidebar-accent px-2 py-0.5 text-xs leading-5 font-semibold">Через ' . h(duration_label($st['minutesToStart'])) . '</span>',
            default => '<span class="rounded-[5px] bg-sidebar-accent px-2 py-0.5 text-xs leading-5 font-semibold">Закончилась</span>',
        };
        $tone = $type === 'night' ? 'text-night-strong' : 'text-day-strong';
        $html = '<section class="cut hero-panel flex flex-col gap-5 p-5 " style="--cut:20px" aria-labelledby="handover-shift">';
        $html .= '<div class="flex flex-wrap items-center justify-between gap-2"><p class="flex items-center gap-2 text-[11px] font-semibold tracking-[0.18em] text-sidebar-muted uppercase"><span class="cut-mark" aria-hidden="true"></span>Ваша смена</p>' . $badge . '</div>';
        $html .= '<div class="flex flex-col gap-1.5"><p id="handover-shift" class="font-display text-2xl leading-none font-semibold tracking-tight">' . h((string) $shift['branchName']) . '</p>';
        $html .= '<p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-sidebar-foreground">' . icon(SHIFT_ICONS[$type] ?? 'sun', 'size-4 ' . $tone);
        $html .= '<span class="font-semibold text-sidebar-accent-foreground">' . h(SHIFT_LABELS[$type] ?? $type) . '</span><span class="tabular-nums">' . $st['start']->format('H:i') . '–' . $st['end']->format('H:i') . '</span><span class="first-letter:uppercase">· ' . h(format_day_short((string) $shift['date'])) . '</span></p></div>';
        $html .= '<ol class="flex flex-col gap-3 border-t border-sidebar-border pt-4">';
        foreach (HANDOVER_PHASES as $phase => $meta) {
            $r = $reports[$phase] ?? null;
            $html .= '<li class="flex items-start gap-3">';
            $html .= $r
                ? '<span class="flex size-6 shrink-0 items-center justify-center rounded-[5px] bg-sidebar-accent-foreground text-sidebar">' . icon('check', 'size-4') . '</span>'
                : '<span class="size-6 shrink-0 rounded-[5px] border-2 border-sidebar-border" aria-hidden="true"></span>';
            $html .= '<div class="flex min-w-0 flex-col"><span class="text-sm font-semibold">' . h($meta['label']) . '</span>';
            $html .= '<span class="text-xs text-sidebar-muted">' . ($r ? h(short_name((string) $r['userName']) . ' · ' . format_dt((string) $r['createdAt'])) : h($meta['missing'])) . '</span></div></li>';
        }
        return $html . '</ol></section>';
    }

    private static function previousHandoverCard(?array $prev): string
    {
        if (!$prev) {
            $html = card_head('От прошлой смены', 'Предыдущая смена на этом филиале не оставила отчёта о сдаче.', '', 'inbox');
            return card($html, 'p-5');
        }
        $type = (string) $prev['shiftType'];
        $meta = h(short_name((string) $prev['userName'])) . ' · ' . h(mb_strtolower(SHIFT_LABELS[$type] ?? $type)) . ' · ' . h(format_dt((string) $prev['createdAt']));
        $html = card_head('От прошлой смены', $meta, '', 'inbox');
        $note = trim((string) ($prev['noteForNext'] ?? ''));
        $html .= $note !== ''
            ? '<p class="mt-4 rounded-md bg-muted/60 px-3 py-2.5 text-sm leading-relaxed whitespace-pre-line text-pretty break-words">' . h($note) . '</p>'
            : '<p class="mt-4 text-sm text-muted-foreground">Заметок сменщику не оставили.</p>';
        if ($prev['cashActual'] !== null && $prev['cashActual'] !== '') {
            [$diffText, $diffTone] = cash_diff($prev['cashExpected'] === null ? null : (int) $prev['cashExpected'], (int) $prev['cashActual']);
            $html .= '<dl class="mt-4 flex flex-col gap-1.5 border-t border-border pt-4 text-sm">';
            $html .= '<div class="flex items-baseline justify-between gap-3"><dt class="text-muted-foreground">Касса на конец</dt><dd class="font-display font-semibold tabular-nums">' . h(money_rub((int) $prev['cashActual'])) . '</dd></div>';
            if ($diffTone !== '') {
                $html .= '<div class="flex items-baseline justify-between gap-3"><dt class="text-muted-foreground">Сверка</dt><dd class="font-medium tabular-nums ' . cash_diff_class($diffTone) . '">' . h($diffText) . '</dd></div>';
            }
            $html .= '</dl>';
        }
        return card($html, 'p-5');
    }

    private static function cashField(string $name, string $label, string $hint, ?string $value, string $attr): string
    {
        $control = '<span class="relative block"><input name="' . h($name) . '" inputmode="numeric" autocomplete="off" maxlength="12" class="' . h(field_class('pr-9 tabular-nums')) . '" value="' . h((string) $value) . '" placeholder="0" ' . $attr . '>';
        $control .= '<span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground" aria-hidden="true">₽</span></span>';
        return ui_field($label, $control, $hint);
    }

    /** Остаток кассы этого клуба из Langame. Сотрудник его не вводит. */
    private static function handoverDrawerCard(array $shift): string
    {
        $snap = Langame::drawerSnapshot((int) $shift['branchId']);
        $club = (string) $shift['branchName'];
        $html = card_head('Касса · ' . $club, 'Только этот клуб. Цифру даёт Langame.', '<button type="button" class="' . btn_class('outline', 'xs') . '" data-langame-refresh data-force="1">' . icon('refresh', 'size-3.5') . 'Обновить</button>', 'wallet');
        $html .= '<p class="mt-2 text-xs text-muted-foreground" data-langame-status hidden>Обновляем кассу…</p>';
        if (!$snap['found']) {
            $html .= '<p class="mt-4 text-sm leading-relaxed text-muted-foreground">За последние сутки Langame не открывал смену на «' . h($club) . '». Когда смена появится в Admin, остаток подставится сам.</p>';
            return card($html, 'p-5');
        }
        $who = $snap['staffName'] !== '' ? short_name($snap['staffName']) : 'администратор';
        $when = $snap['startedAt'] ? format_dt(str_replace(' ', 'T', (string) $snap['startedAt'])) : '';
        $html .= '<p class="mt-3 text-xs text-muted-foreground">' . h($who) . ($when !== '' ? ' · с ' . h($when) : '') . ' · ' . ($snap['open'] ? 'смена открыта' : 'смена закрыта') . '</p>';
        $figure = $snap['open'] ? $snap['cashStart'] : $snap['endDrawer'];
        $caption = $snap['open'] ? 'в ящике на открытии' : 'остаток по Langame';
        $html .= '<p class="mt-3 font-display text-3xl font-semibold tabular-nums">' . ($figure !== null ? h(money_rub((int) $figure)) : '—') . '</p>';
        $html .= '<p class="text-xs text-muted-foreground">' . h($caption) . '</p>';
        $html .= '<dl class="mt-4 flex flex-col gap-1.5 border-t border-border pt-3 text-sm">';
        $rows = [
            ['На открытии', $snap['cashStart'] !== null ? money_rub((int) $snap['cashStart']) : '—'],
            ['Нал за смену', $snap['open'] ? 'после закрытия в Admin' : money_rub((int) $snap['cashNal'])],
            ['Безнал', $snap['open'] ? 'после закрытия в Admin' : money_rub((int) $snap['cashBeznal'])],
        ];
        if ((int) $snap['cashIncass'] > 0) {
            $rows[] = ['Инкассация', money_rub((int) $snap['cashIncass'])];
        }
        foreach ($rows as [$label, $value]) {
            $html .= '<div class="flex items-baseline justify-between gap-3"><dt class="text-muted-foreground">' . h($label) . '</dt><dd class="text-right font-medium tabular-nums">' . h($value) . '</dd></div>';
        }
        $html .= '</dl>';
        if ((int) $snap['barCashKop'] > 0 || (int) $snap['barCardKop'] > 0) {
            $html .= '<p class="mt-3 text-xs leading-relaxed text-muted-foreground">Бар с этой смены: нал ' . h(kop_rub((int) $snap['barCashKop'])) . ' · карта ' . h(kop_rub((int) $snap['barCardKop'])) . '. Это не весь ящик — пополнения баланса гостей сюда не входят.</p>';
        }
        if ($snap['open']) {
            $html .= '<p class="mt-3 text-xs leading-relaxed text-muted-foreground">Итог наличных Langame записывает, когда смену закрывают в Admin. Нажмите «Обновить» после закрытия — остаток подставится в сдачу.</p>';
        }
        return card($html, 'p-5');
    }

    /** Одно поле: факт. Ожидаемое берётся из Langame и в форму не вводится. */
    private static function handoverFactFields(array $shift, string $phase, ?array $stash, ?array $report): string
    {
        $snap = Langame::drawerSnapshot((int) $shift['branchId']);
        $expected = $phase === 'open' ? $snap['expectedOpen'] : $snap['expectedClose'];
        $act = '';
        if (is_array($stash) && isset($stash['cashActual'])) {
            $act = (string) $stash['cashActual'];
        } elseif ($report && $report['cashActual'] !== null && $report['cashActual'] !== '') {
            $act = (string) $report['cashActual'];
        }
        $numAct = preg_match('/^\d+$/', $act) ? (int) $act : null;
        [$diffText, $diffTone] = cash_diff($expected, $numAct);
        $label = $phase === 'open' ? 'Факт при приёме' : 'Факт в ящике';
        $hint = $phase === 'open' ? 'Сколько пересчитали, принимая смену' : 'Сколько пересчитали в конце смены';
        $html = '<div class="flex flex-col gap-3 border-t border-border pt-5" data-cash>';
        $html .= '<input type="hidden" name="cashExpected" value="' . h($expected === null ? '' : (string) $expected) . '" data-cash-expected>';
        if ($expected !== null) {
            $html .= '<p class="text-sm text-muted-foreground">По Langame сейчас <strong class="font-display text-base text-foreground tabular-nums">' . h(money_rub($expected)) . '</strong>. Впишите только факт.</p>';
        } elseif ($phase === 'close' && $snap['open']) {
            $html .= '<p class="text-sm leading-relaxed text-muted-foreground">Смена в Langame ещё открыта, итогового остатка нет. Факт можно сохранить уже сейчас — сверка появится после закрытия смены в Admin.</p>';
        } else {
            $html .= '<p class="text-sm text-muted-foreground">Langame пока не отдал остаток этого клуба. Факт всё равно сохранится.</p>';
        }
        $html .= self::cashField('cashActual', $label, $hint, $act, 'data-cash-actual');
        $html .= '<p class="flex items-center justify-between gap-3 rounded-md bg-muted/60 px-3 py-2.5 text-sm"><span class="text-muted-foreground">Расхождение</span><output class="font-display font-semibold tabular-nums ' . cash_diff_class($diffTone) . '" data-cash-diff aria-live="polite">' . h($diffText) . '</output></p>';
        return $html . '</div>';
    }

    private static function handoverForm(array $shift, string $phase, array $items, ?array $report): string
    {
        $meta = HANDOVER_PHASES[$phase];
        $stash = form_stash_take('handover_' . $phase);
        $checked = [];
        if ($stash !== null) {
            $checked = array_map('intval', array_filter((array) ($stash['check'] ?? []), 'is_scalar'));
        } elseif ($report) {
            $checked = array_keys(array_filter($report['checks']));
        }
        $total = count($items);
        $doneNow = count(array_filter($items, static fn ($it) => in_array($it['id'], $checked, true)));

        $sub = $report
            ? 'Заполнил ' . h(short_name((string) $report['userName'])) . ' · ' . h(format_dt((string) $report['createdAt'])) . '. Можно поправить и сохранить ещё раз.'
            : h($phase === 'open' ? 'В начале смены пройдите по залу и отметьте, что проверили.' : 'В конце смены — касса, порядок в зале и заметка сменщику.');
        $status = $report ? chip($meta['done'], 'ink', 'check') : chip($meta['missing'], 'outline');

        $html = form_open('save_handover', 'class="flex flex-col gap-5" data-checklist data-submit-once');
        $html .= hidden('phase', $phase) . hidden('branchId', (int) $shift['branchId']) . hidden('date', (string) $shift['date']) . hidden('shiftType', (string) $shift['shiftType']);
        $html .= card_head($meta['label'], $sub, $status, $meta['icon']);

        if ($items) {
            $html .= '<fieldset class="flex flex-col gap-2"><legend class="sr-only">Чек-лист: ' . h(mb_strtolower($meta['label'])) . '</legend>';
            $html .= '<div class="flex items-center justify-between gap-3 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"><span>Чек-лист</span><span class="tabular-nums" data-check-count aria-live="polite">' . $doneNow . ' из ' . $total . '</span></div>';
            foreach ($items as $it) {
                $on = in_array($it['id'], $checked, true);
                $html .= '<label class="flex cursor-pointer items-start gap-3 rounded-md border border-border p-3 transition-colors hover:bg-muted/40 has-checked:border-foreground/25 has-checked:bg-muted/50">';
                $html .= '<input type="checkbox" name="check[]" value="' . $it['id'] . '" class="' . check_class('mt-0.5 size-5') . '" data-check' . ($on ? ' checked' : '') . '>';
                $html .= '<span class="text-sm leading-relaxed text-pretty">' . h($it['title']) . '</span></label>';
            }
            $html .= '</fieldset>';
        } else {
            $html .= '<p class="text-sm text-muted-foreground">Пункты чек-листа для этой фазы не заданы.</p>';
        }

        $html .= self::handoverFactFields($shift, $phase, $stash, $report);
        if ($phase === 'close') {
            $note = $stash['noteForNext'] ?? (string) ($report['noteForNext'] ?? '');
            $html .= ui_field('Заметка следующей смене', '<textarea name="noteForNext" maxlength="2000" rows="3" class="' . h(textarea_class()) . '" placeholder="Что сломалось, что закончилось на складе, о чём предупредить">' . h((string) $note) . '</textarea>', 'Сменщик увидит её, когда будет принимать смену.');
        }

        $html .= '<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">';
        $html .= '<p class="text-xs leading-relaxed text-muted-foreground">Неотмеченные пункты сохранятся как невыполненные.</p>';
        $html .= '<button class="' . btn_class($report ? 'outline' : 'primary') . ' sm:shrink-0">' . icon($report ? 'save' : 'check', 'size-4') . h($report ? 'Сохранить изменения' : $meta['verb']) . '</button></div>';
        return card($html . '</form>', 'p-5');
    }

    /* ---------- журнал для управляющих ---------- */

    private static function handoverHistory(array $user): string
    {
        $visible = self::visibleBranches($user);
        $branch = self::pickBranch($user, $visible);
        if (!$branch) {
            return '';
        }
        $now = now_tyumen();
        $today = date_key($now);
        $from = add_days($today, -6);
        $bid = $branch['id'];

        $rows = [];
        $shifts = Db::all(
            'SELECT s.date, s."shiftType", e.name AS "employeeName" FROM shifts s JOIN employees e ON e.id = s."employeeId"
             WHERE s."branchId" = ? AND s.date >= ? AND s.date <= ? ORDER BY e."sortOrder", e.id',
            [$bid, $from, $today],
        );
        foreach ($shifts as $s) {
            $date = substr((string) $s['date'], 0, 10);
            $type = (string) $s['shiftType'];
            $k = $date . '|' . $type;
            $rows[$k] ??= ['date' => $date, 'type' => $type, 'names' => []];
            $rows[$k]['names'][] = short_name((string) $s['employeeName']);
        }
        $reports = Db::all('SELECT * FROM shift_reports WHERE "branchId" = ? AND date >= ? AND date <= ?', [$bid, $from, $today]);
        $missed = [];
        $totals = [];
        if ($reports) {
            // Только счётчики done/all — без JOIN на названия пунктов (это и тормозило у владельца).
            $checks = Db::all(
                'SELECT c."reportId", SUM(CASE WHEN c.done = 1 THEN 1 ELSE 0 END) AS done_n, COUNT(*) AS all_n
                 FROM shift_report_checks c
                 JOIN shift_reports r ON r.id = c."reportId"
                 WHERE r."branchId" = ? AND r.date >= ? AND r.date <= ?
                 GROUP BY c."reportId"',
                [$bid, $from, $today],
            );
            foreach ($checks as $c) {
                $totals[(int) $c['reportId']] = [(int) $c['done_n'], (int) $c['all_n']];
            }
        }
        foreach ($reports as $r) {
            $date = substr((string) $r['date'], 0, 10);
            $k = $date . '|' . $r['shiftType'];
            $rows[$k] ??= ['date' => $date, 'type' => (string) $r['shiftType'], 'names' => []];
            $rows[$k][(string) $r['phase']] = $r;
        }
        foreach ($rows as $k => $row) {
            $st = shift_state($row['date'], $row['type'], $now, $bid);
            if ($st['state'] === 'upcoming') {
                unset($rows[$k]);
                continue;
            }
            $rows[$k]['state'] = $st;
        }
        uasort($rows, static fn ($a, $b) => $b['state']['start'] <=> $a['state']['start']);

        $head = '<div class="flex flex-col gap-1"><h2 class="font-display text-lg leading-tight font-semibold">Журнал приёма и сдачи</h2><p class="text-sm text-muted-foreground">Последние 7 дней. Раздел виден тем, у кого есть право «Журнал действий».</p></div>';
        $actions = self::canViewShiftReconcile($user)
            ? '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/shift/reconcile', ['branch' => $branch['slug']])) . '">' . icon('scale', 'size-3.5') . 'Сверка с Langame</a>'
            : '';
        $html = '<section class="flex flex-col gap-4 border-t border-border pt-8" aria-label="Журнал приёма и сдачи смен">';
        $html .= '<div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">' . $head . '<div class="flex flex-wrap items-center gap-2">' . $actions . self::branchSwitch('/shift', $branch, $visible, ['history' => '1']) . '</div></div>';
        if (!$rows) {
            return $html . empty_state('history', 'Смен за две недели нет', 'Когда администраторы начнут принимать и сдавать смены на филиале ' . $branch['name'] . ', отчёты появятся здесь.') . '</section>';
        }

        $html .= table_open(['Смена', 'Приём', 'Сдача', 'Касса', 'Заметка']);
        foreach ($rows as $row) {
            $type = $row['type'];
            $cell = '<div class="flex flex-col gap-1"><span class="font-semibold first-letter:uppercase">' . h(format_day_short($row['date'])) . '</span>';
            $cell .= '<span class="flex flex-wrap items-center gap-1.5">' . chip(SHIFT_LABELS[$type] ?? $type, $type === 'night' ? 'night' : ($type === 'full' ? 'outline' : 'day'), SHIFT_ICONS[$type] ?? 'sun');
            if ($row['names']) {
                $cell .= '<span class="text-xs text-muted-foreground">' . h(implode(', ', array_unique($row['names']))) . '</span>';
            }
            $cell .= '</span></div>';
            $html .= '<tr class="border-t border-border">' . td('Смена', $cell);
            foreach (HANDOVER_PHASES as $phase => $meta) {
                $html .= td($meta['label'], self::historyPhaseCell($row, $phase, $totals, $missed));
            }
            $close = $row['close'] ?? null;
            $cash = '<span class="text-muted-foreground">—</span>';
            if ($close && $close['cashActual'] !== null && $close['cashActual'] !== '') {
                [$diffText, $diffTone] = cash_diff($close['cashExpected'] === null ? null : (int) $close['cashExpected'], (int) $close['cashActual']);
                $cash = '<div class="flex flex-col gap-0.5"><span class="font-semibold tabular-nums">' . h(money_rub((int) $close['cashActual'])) . '</span>';
                if ($diffTone !== '') {
                    $cash .= '<span class="text-xs font-medium tabular-nums ' . cash_diff_class($diffTone) . '">' . h($diffText) . '</span>';
                }
                $cash .= '</div>';
            }
            $html .= td('Касса', $cash, 'whitespace-nowrap');
            $note = trim((string) ($close['noteForNext'] ?? ''));
            $html .= td('Заметка', $note !== ''
                ? '<p class="line-clamp-3 max-w-xs text-xs leading-relaxed whitespace-pre-line text-pretty break-words" title="' . h($note) . '">' . h($note) . '</p>'
                : '<span class="text-muted-foreground">—</span>');
            $html .= '</tr>';
        }
        return $html . table_close() . '</section>';
    }

    private static function historyPhaseCell(array $row, string $phase, array $totals, array $missed = []): string
    {
        unset($missed);
        $r = $row[$phase] ?? null;
        if (!$r) {
            if ($phase === 'close' && $row['state']['state'] === 'live') {
                return chip('Смена идёт', 'neutral', 'clock');
            }
            return chip(HANDOVER_PHASES[$phase]['missing'], 'red', 'alert');
        }
        $rid = (int) $r['id'];
        [$done, $all] = $totals[$rid] ?? [0, 0];
        $html = '<div class="flex flex-col gap-0.5"><span class="font-medium">' . h(short_name((string) $r['userName'])) . '</span>';
        $html .= '<span class="text-xs text-muted-foreground tabular-nums">' . h(format_dt((string) $r['createdAt'])) . ($all ? ' · ' . $done . '/' . $all : '') . '</span>';
        return $html . '</div>';
    }

    /* ---------- сверка: график CRM ↔ Langame ↔ сдача ---------- */

    private static function canViewShiftReconcile(array $user): bool
    {
        return can($user, 'page.reconcile');
    }

    private static function pageShiftReconcile(array $user): void
    {
        if (!self::canViewShiftReconcile($user)) {
            http_response_code(403);
            self::layout($user, '<div class="py-10">' . empty_state('lock', 'Нет доступа', 'Контроль смен выдаётся в «Доступах и правах» → вкладка «Контроль смен».', '<a class="' . btn_class('outline') . '" href="' . h(url_to('/')) . '">' . icon('home') . 'На главную</a>') . '</div>', ['title' => 'Нет доступа', 'width' => 'narrow']);
            return;
        }
        $visible = self::visibleBranches($user);
        $branch = self::pickBranch($user, $visible);
        if (!$branch) {
            self::layout($user, page_head('Сверка смен') . empty_state('building', 'Нет филиалов', 'Нет доступных филиалов для сверки.'), ['title' => 'Сверка смен']);
            return;
        }
        $now = now_tyumen();
        $today = date_key($now);
        $raw = self::getStr('d', 10);
        $day = is_date_key($raw) ? $raw : $today;
        if ($day > $today) {
            $day = $today;
        }
        Langame::requestRefresh((int) $branch['id']);

        $data = self::shiftReconcileDay((int) $branch['id'], $day, $now);
        $issues = count(array_filter($data['rows'], static fn ($r) => !empty($r['issues'])));

        $nav = '<div class="flex flex-wrap items-center gap-2">';
        $nav .= '<a class="' . btn_class('outline', 'icon-sm') . '" aria-label="Предыдущий день" href="' . h(url_to('/shift/reconcile', ['branch' => $branch['slug'], 'd' => add_days($day, -1)])) . '">' . icon('chevronleft') . '</a>';
        $nav .= '<span class="text-sm font-semibold first-letter:uppercase">' . h(format_day_month($day)) . '</span>';
        if ($day < $today) {
            $nav .= '<a class="' . btn_class('outline', 'icon-sm') . '" aria-label="Следующий день" href="' . h(url_to('/shift/reconcile', ['branch' => $branch['slug'], 'd' => add_days($day, 1)])) . '">' . icon('chevronright') . '</a>';
        } else {
            $nav .= '<span class="' . btn_class('outline', 'icon-sm') . ' pointer-events-none opacity-40" aria-hidden="true">' . icon('chevronright') . '</span>';
        }
        if ($day !== $today) {
            $nav .= '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/shift/reconcile', ['branch' => $branch['slug']])) . '">Сегодня</a>';
        }
        $nav .= '</div>';

        $html = page_head('Контроль смен', 'Три источника в одном месте — найти, где что-то не сошлось.', $nav, $branch['name']);
        $html .= self::branchSwitch('/shift/reconcile', $branch, $visible, ['d' => $day !== $today ? $day : null]);
        $html .= '<div class="rounded-lg border border-border bg-muted/30 px-4 py-3 text-sm leading-relaxed text-muted-foreground">';
        $html .= '<p class="font-medium text-foreground">Зачем эта вкладка</p>';
        $html .= '<ol class="mt-2 list-decimal space-y-1 pl-5">';
        $html .= '<li><span class="text-foreground">По графику</span> — кого вы поставили в CRM на этот день.</li>';
        $html .= '<li><span class="text-foreground">В Langame</span> — кто реально открыл смену в Admin (касса).</li>';
        $html .= '<li><span class="text-foreground">В CRM</span> — кто отметил приём и сдачу на сайте.</li>';
        $html .= '</ol>';
        $html .= '<p class="mt-2">Красная карточка = расхождение. Например: стоит в графике, а в Langame смены нет; или смену не сдали на сайте.</p>';
        $html .= '</div>';
        $html .= '<p class="text-xs text-muted-foreground" data-langame-status hidden>Обновляем смены из Langame…</p>';

        $kpi = '<div class="grid grid-cols-2 gap-3 sm:grid-cols-4">';
        $kpi .= self::reconcileStat('По графику', (string) $data['stats']['scheduled']);
        $kpi .= self::reconcileStat('В Langame', (string) $data['stats']['langame']);
        $kpi .= self::reconcileStat('Сдали в CRM', (string) $data['stats']['crmClosed']);
        $kpi .= self::reconcileStat('Проблемы', (string) $issues, $issues > 0);
        $html .= $kpi . '</div>';

        if (!$data['rows']) {
            $html .= empty_state('calendar', 'За день пусто', 'Нет смен в графике и нет открытых смен Langame на ' . format_day_month($day) . '.');
        } else {
            $html .= '<ul class="mt-2 flex flex-col gap-3">';
            foreach ($data['rows'] as $row) {
                $html .= self::reconcileRowCard($row);
            }
            $html .= '</ul>';
        }
        $html .= '<p class="mt-6 text-xs leading-relaxed text-muted-foreground">Цифры кассы Langame подтянутся после синхронизации. Это не «сколько заработал клуб» для сотрудников — раздел только для контроля смен.</p>';
        self::layout($user, $html, [
            'title' => 'Контроль смен',
            'width' => 'wide',
            'langameReload' => true,
            'langameForce' => true,
        ]);
    }

    private static function reconcileStat(string $label, string $value, bool $warn = false): string
    {
        return '<div class="rounded-lg border border-border p-4' . ($warn ? ' border-primary/40 bg-primary/5' : '') . '"><p class="text-xs text-muted-foreground">' . h($label) . '</p><p class="mt-1 font-display text-2xl font-semibold tabular-nums">' . h($value) . '</p></div>';
    }

    /**
     * @return array{stats: array{scheduled: int, langame: int, crmClosed: int}, rows: list<array<string,mixed>>}
     */
    private static function shiftReconcileDay(int $branchId, string $day, DateTimeImmutable $now): array
    {
        $scheduled = Db::all(
            'SELECT s.id, s."employeeId", s."shiftType", e.name AS "employeeName"
             FROM shifts s JOIN employees e ON e.id = s."employeeId"
             WHERE s."branchId" = ? AND s.date = ? ORDER BY s."shiftType", e."sortOrder", e.id',
            [$branchId, $day],
        );
        $lg = Langame::workingShiftsForDay($branchId, $day);
        $reports = [];
        foreach (Db::all('SELECT * FROM shift_reports WHERE "branchId" = ? AND date = ?', [$branchId, $day]) as $r) {
            $reports[(string) $r['shiftType'] . '|' . (string) $r['phase']] = $r;
        }

        $usedLg = [];
        $rows = [];
        foreach ($scheduled as $s) {
            $eid = (int) $s['employeeId'];
            $type = (string) $s['shiftType'];
            $st = shift_state($day, $type, $now, $branchId);
            $match = null;
            $matchIdx = null;
            foreach ($lg as $i => $w) {
                if (isset($usedLg[$i])) {
                    continue;
                }
                if ($w['employeeId'] !== null && $w['employeeId'] === $eid) {
                    $match = $w;
                    $matchIdx = $i;
                    break;
                }
            }
            if ($matchIdx !== null) {
                $usedLg[$matchIdx] = true;
            }
            $openRep = $reports[$type . '|open'] ?? null;
            $closeRep = $reports[$type . '|close'] ?? null;
            $issues = [];
            if ($st['state'] !== 'upcoming' && !$match) {
                $issues[] = 'Нет смены в Langame';
            }
            if ($match && $match['open'] && $st['state'] === 'done') {
                $issues[] = 'В Langame смена ещё открыта';
            }
            if ($st['state'] !== 'upcoming' && !$openRep) {
                $issues[] = 'Не принята в CRM';
            }
            if ($st['state'] === 'done' && !$closeRep) {
                $issues[] = 'Не сдана в CRM';
            }
            if ($match && !$match['open'] && $closeRep && $match['cashNal'] !== null && $closeRep['cashActual'] !== null && $closeRep['cashActual'] !== '') {
                $diff = abs((int) $closeRep['cashActual'] - (int) $match['cashNal']);
                if ($diff >= 50) {
                    $issues[] = 'Касса CRM и нал Langame расходятся на ' . money_rub($diff);
                }
            }
            $rows[] = [
                'kind' => 'scheduled',
                'name' => (string) $s['employeeName'],
                'type' => $type,
                'state' => $st,
                'langame' => $match,
                'open' => $openRep,
                'close' => $closeRep,
                'issues' => $issues,
            ];
        }
        foreach ($lg as $i => $w) {
            if (isset($usedLg[$i])) {
                continue;
            }
            $rows[] = [
                'kind' => 'extra',
                'name' => $w['staffName'],
                'type' => null,
                'state' => null,
                'langame' => $w,
                'open' => null,
                'close' => null,
                'issues' => ['Есть в Langame, нет в графике CRM' . ($w['employeeId'] === null ? ' (учётка не привязана)' : '')],
            ];
        }

        return [
            'stats' => [
                'scheduled' => count($scheduled),
                'langame' => count($lg),
                'crmClosed' => count(array_filter($reports, static fn ($r, $k) => str_ends_with((string) $k, '|close'), ARRAY_FILTER_USE_BOTH)),
            ],
            'rows' => $rows,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function reconcileRowCard(array $row): string
    {
        $issues = $row['issues'];
        $border = $issues ? 'border-primary/35 bg-primary/5' : 'border-border bg-card';
        $html = '<li class="rounded-xl border ' . $border . ' p-4">';
        $html .= '<div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0">';
        $html .= '<p class="font-display text-base font-semibold">' . h(short_name((string) $row['name'])) . '</p>';
        $meta = [];
        if ($row['type']) {
            $meta[] = SHIFT_LABELS[$row['type']] ?? $row['type'];
        }
        if ($row['kind'] === 'extra') {
            $meta[] = 'только Langame';
        }
        if ($row['state']) {
            $meta[] = match ($row['state']['state']) {
                'live' => 'идёт сейчас',
                'done' => 'закончилась',
                default => 'ещё не началась',
            };
        }
        $html .= '<p class="mt-0.5 text-xs text-muted-foreground">' . h(implode(' · ', $meta)) . '</p></div>';
        if ($issues) {
            $html .= chip('Расхождение', 'brand', 'alert');
        } else {
            $html .= chip('Ок', 'ink', 'check');
        }
        $html .= '</div>';

        $html .= '<div class="mt-3 grid gap-2 sm:grid-cols-3">';
        $lg = $row['langame'];
        if ($lg) {
            $lgBody = ($lg['open'] ? 'Открыта' : 'Закрыта') . ' · ' . format_dt(str_replace(' ', 'T', $lg['dateStart']));
            if (!$lg['open'] && $lg['dateStop']) {
                $lgBody .= ' → ' . format_dt(str_replace(' ', 'T', $lg['dateStop']));
            }
            $cashBits = [];
            if ($lg['cashStart'] !== null) {
                $cashBits[] = 'старт ' . money_rub($lg['cashStart']);
            }
            if ($lg['cashNal'] !== null) {
                $cashBits[] = 'нал ' . money_rub($lg['cashNal']);
            }
            if ($lg['cashBeznal'] !== null) {
                $cashBits[] = 'безнал ' . money_rub($lg['cashBeznal']);
            }
            if ($lg['cashIncass'] !== null && $lg['cashIncass'] > 0) {
                $cashBits[] = 'инк. ' . money_rub($lg['cashIncass']);
            }
            $html .= self::reconcileMini('Langame', h($lgBody), $cashBits ? h(implode(' · ', $cashBits)) : ($lg['closeMessage'] ? h(mb_substr($lg['closeMessage'], 0, 80)) : ''));
        } else {
            $html .= self::reconcileMini('Langame', '—', 'Смены в ПО нет');
        }

        $open = $row['open'];
        $html .= self::reconcileMini(
            'Приём CRM',
            $open ? h(short_name((string) $open['userName']) . ' · ' . format_dt((string) $open['createdAt'])) : '—',
            $open ? '' : 'Не заполнен',
        );
        $close = $row['close'];
        $closeHint = '';
        if ($close && $close['cashActual'] !== null && $close['cashActual'] !== '') {
            $closeHint = 'касса ' . money_rub((int) $close['cashActual']);
        } elseif (!$close) {
            $closeHint = 'Не заполнена';
        }
        $html .= self::reconcileMini(
            'Сдача CRM',
            $close ? h(short_name((string) $close['userName']) . ' · ' . format_dt((string) $close['createdAt'])) : '—',
            h($closeHint),
        );
        $html .= '</div>';

        if ($issues) {
            $html .= '<ul class="mt-3 flex flex-col gap-1 border-t border-border/80 pt-3">';
            foreach ($issues as $issue) {
                $html .= '<li class="flex items-start gap-2 text-xs text-primary-ink">' . icon('alert', 'size-3.5 mt-0.5') . '<span>' . h($issue) . '</span></li>';
            }
            $html .= '</ul>';
        }
        return $html . '</li>';
    }

    private static function reconcileMini(string $label, string $value, string $hint = ''): string
    {
        $html = '<div class="rounded-lg border border-border/80 bg-background/60 px-3 py-2.5">';
        $html .= '<p class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">' . h($label) . '</p>';
        $html .= '<p class="mt-1 text-sm font-semibold leading-snug">' . $value . '</p>';
        if ($hint !== '') {
            $html .= '<p class="mt-0.5 text-xs text-muted-foreground">' . $hint . '</p>';
        }
        return $html . '</div>';
    }

    /* ---------- подмены ---------- */

    private static function pageShiftSwaps(array $user): void
    {
        $prefs = $user['prefs'] ?? Notify::PREF_DEFAULTS;
        if (!Notify::feature($prefs, 'swaps')) {
            self::layout($user, page_head('Подмены') . empty_state('users', 'Подмены выключены', 'Включите блок в профиле → Уведомления и блоки.', '<a class="' . btn_class('outline') . '" href="' . h(url_to('/account') . '#prefs') . '">Настройки</a>'), ['title' => 'Подмены', 'width' => 'narrow']);
            return;
        }
        $eid = (int) ($user['employeeId'] ?? 0);
        $now = now_tyumen();
        $today = date_key($now);
        $from = $now->modify('-1 day')->format('Y-m-d');
        $html = page_head('Подмены', 'Не можете выйти — откройте заявку. Коллега берёт смену, график обновится без чатов в Telegram.');

        if ($eid > 0) {
            $rawMine = Db::all(
                'SELECT s.id, s.date, s."shiftType", s."branchId", b.name AS "branchName",
                        (SELECT id FROM shift_swaps w WHERE w."shiftId" = s.id AND w.status = \'open\' LIMIT 1) AS open_swap
                 FROM shifts s JOIN branches b ON b.id = s."branchId"
                 WHERE s."employeeId" = ? AND s.date >= ? ORDER BY s.date, s."shiftType" LIMIT 40',
                [$eid, $from],
            );
            $mine = [];
            foreach ($rawMine as $s) {
                $d = substr((string) $s['date'], 0, 10);
                if (shift_state($d, (string) $s['shiftType'], $now, (int) $s['branchId'])['state'] === 'done') {
                    continue;
                }
                $mine[] = $s;
                if (count($mine) >= 21) {
                    break;
                }
            }
            $selId = (int) self::getStr('shift', 12);
            $offer = card_head('Не могу выйти', $mine ? 'Сначала выберите смену вкладкой' : 'В графике нет ближайших смен', '', 'alert');
            if ($mine) {
                $byId = [];
                $tabs = [];
                foreach ($mine as $s) {
                    $byId[(int) $s['id']] = $s;
                    $d = substr((string) $s['date'], 0, 10);
                    $tabs[] = [
                        'href' => url_to('/shift/swaps', ['shift' => (int) $s['id']]),
                        'label' => format_day_short($d) . ' · ' . (SHIFT_LABELS[$s['shiftType']] ?? $s['shiftType']),
                        'active' => $selId === (int) $s['id'],
                    ];
                }
                if ($selId <= 0 || !isset($byId[$selId])) {
                    $selId = (int) $mine[0]['id'];
                    $tabs[0]['active'] = true;
                }
                $chosen = $byId[$selId];
                $label = format_day_month(substr((string) $chosen['date'], 0, 10)) . ' · ' . (SHIFT_LABELS[$chosen['shiftType']] ?? $chosen['shiftType']) . ' · ' . $chosen['branchName'];
                $offer .= '<div class="mt-4">' . segmented($tabs, 'Ваша смена') . '</div>';
                $offer .= '<div class="mt-4 rounded-lg border border-border bg-muted/20 p-4">';
                $offer .= '<p class="text-sm font-semibold">' . h($label) . '</p>';
                if ($chosen['open_swap']) {
                    $offer .= '<p class="mt-2 text-sm text-muted-foreground">Заявка уже открыта — коллеги видят её ниже.</p>';
                    $offer .= form_open('cancel_shift_swap', 'class="mt-3"') . hidden('id', (int) $chosen['open_swap']) . '<button class="' . btn_class('outline') . '">Снять заявку</button></form>';
                } else {
                    $offer .= form_open('request_shift_swap', 'class="mt-3 flex flex-col gap-3"') . hidden('shiftId', (int) $chosen['id']);
                    $offer .= ui_field('Причина', '<input name="reason" class="' . field_class() . '" maxlength="500" placeholder="Необязательно — увидят коллеги">');
                    $offer .= '<button class="' . btn_class('primary') . '">' . icon('users', 'size-4') . 'Ищу подмену на эту смену</button></form>';
                }
                $offer .= '</div>';
            } else {
                $offer .= '<p class="mt-4 text-sm text-muted-foreground">Когда появится смена в графике — здесь можно будет отдать её коллеге.</p>';
            }
            $html .= card($offer, 'p-5');
        } else {
            $html .= empty_state('user', 'Нет карточки сотрудника', 'Подмены доступны тем, кто стоит в графике.');
        }

        try {
            $openRaw = Db::all(
                'SELECT w.id, w.reason, w."createdAt", w."fromEmployeeId",
                        s.date, s."shiftType", s."branchId", b.name AS "branchName",
                        e.name AS "fromName"
                 FROM shift_swaps w
                 JOIN shifts s ON s.id = w."shiftId"
                 JOIN branches b ON b.id = s."branchId"
                 JOIN employees e ON e.id = w."fromEmployeeId"
                 WHERE w.status = ? AND s.date >= ?
                 ORDER BY s.date, w."createdAt"',
                ['open', $from],
            );
            $open = [];
            foreach ($openRaw as $w) {
                $d = substr((string) $w['date'], 0, 10);
                if (shift_state($d, (string) $w['shiftType'], $now, (int) $w['branchId'])['state'] === 'done') {
                    continue;
                }
                $open[] = $w;
            }
        } catch (PDOException) {
            $open = [];
        }
        $board = card_head('Открытые заявки', $open ? plural(count($open), 'заявка', 'заявки', 'заявок') : 'Сейчас никто не ищет подмену', '', 'users');
        if ($open) {
            $board .= '<ul class="mt-4 flex flex-col gap-2">';
            foreach ($open as $w) {
                $label = format_day_month(substr((string) $w['date'], 0, 10)) . ' · ' . (SHIFT_LABELS[$w['shiftType']] ?? '') . ' · ' . $w['branchName'];
                $board .= '<li class="flex flex-wrap items-start justify-between gap-3 rounded-md border border-border px-3 py-3">';
                $board .= '<div class="min-w-0"><p class="text-sm font-semibold">' . h(short_name((string) $w['fromName'])) . '</p><p class="text-sm text-muted-foreground">' . h($label) . '</p>';
                if (trim((string) $w['reason']) !== '') {
                    $board .= '<p class="mt-1 text-xs text-muted-foreground">' . h((string) $w['reason']) . '</p>';
                }
                $board .= '</div>';
                if ($eid > 0 && (int) $w['fromEmployeeId'] !== $eid) {
                    $board .= form_open('claim_shift_swap', 'class="shrink-0" ' . confirm_attr('Взять смену «' . $label . '»? Она появится в вашем графике.')) . hidden('id', (int) $w['id']) . '<button class="' . btn_class('primary', 'sm') . '">Возьму</button></form>';
                } elseif ((int) $w['fromEmployeeId'] === $eid) {
                    $board .= form_open('cancel_shift_swap', 'class="shrink-0"') . hidden('id', (int) $w['id']) . '<button class="' . btn_class('outline', 'sm') . '">Отменить</button></form>';
                }
                $board .= '</li>';
            }
            $board .= '</ul>';
        }
        $html .= '<div class="mt-6">' . card($board, 'p-5') . '</div>';
        self::layout($user, $html, ['title' => 'Подмены']);
    }

    /**
     * Смены по графику, которые уже закончились, но сдачи (close) в CRM нет.
     * @return list<array{branchId:int,branchName:string,date:string,shiftType:string,employeeName:string,shiftId:int}>
     */
    private static function missingHandoversForDay(string $day, DateTimeImmutable $now, ?array $branchIds = null): array
    {
        $branches = self::branches();
        if ($branchIds !== null) {
            $want = array_flip($branchIds);
            $branches = array_values(array_filter($branches, static fn ($b) => isset($want[$b['id']])));
        }
        $out = [];
        foreach ($branches as $b) {
            $bid = (int) $b['id'];
            $scheduled = Db::all(
                'SELECT s.id, s."shiftType", e.name AS "employeeName"
                 FROM shifts s JOIN employees e ON e.id = s."employeeId"
                 WHERE s."branchId" = ? AND s.date = ?',
                [$bid, $day],
            );
            $closed = [];
            foreach (Db::all('SELECT "shiftType" FROM shift_reports WHERE "branchId" = ? AND date = ? AND phase = ?', [$bid, $day, 'close']) as $r) {
                $closed[(string) $r['shiftType']] = true;
            }
            foreach ($scheduled as $s) {
                $type = (string) $s['shiftType'];
                $st = shift_state($day, $type, $now, $bid);
                if ($st['state'] !== 'done') {
                    continue;
                }
                if (isset($closed[$type])) {
                    continue;
                }
                $out[] = [
                    'branchId' => $bid,
                    'branchName' => (string) $b['name'],
                    'date' => $day,
                    'shiftType' => $type,
                    'employeeName' => (string) $s['employeeName'],
                    'shiftId' => (int) $s['id'],
                ];
            }
        }
        return $out;
    }

    private static function pageShiftMissing(array $user): void
    {
        if (!can($user, 'page.missing')) {
            http_response_code(403);
            self::layout($user, empty_state('lock', 'Нет доступа', 'Раздел выдаётся в «Доступах и правах» → вкладка «Не сдали смену».'), ['title' => 'Нет доступа', 'width' => 'narrow']);
            return;
        }
        $now = now_tyumen();
        $today = date_key($now);
        $raw = self::getStr('d', 10);
        $day = is_date_key($raw) ? $raw : $today;
        if ($day > $today) {
            $day = $today;
        }
        $scope = can($user, 'schedule.view_all') || !empty($user['isOwner']) ? null : user_branch_ids($user);
        $rows = self::missingHandoversForDay($day, $now, $scope);

        Notify::once('missing_handover:' . $day, static function () use ($rows, $day): void {
            if (!$rows) {
                return;
            }
            $byBranch = [];
            foreach ($rows as $r) {
                $byBranch[$r['branchName']][] = short_name($r['employeeName']) . ' · ' . (SHIFT_LABELS[$r['shiftType']] ?? $r['shiftType']);
            }
            $lines = ['Не сдали смену · ' . format_day_month($day)];
            foreach ($byBranch as $bn => $people) {
                $lines[] = $bn . ': ' . implode(', ', $people);
            }
            Notify::toUsers(Notify::recipientsForPerm('schedule.edit'), 'handover', implode("\n", $lines), url_to('/shift/missing'));
        });

        $nav = '<div class="flex flex-wrap items-center gap-2">';
        $nav .= '<a class="' . btn_class('outline', 'icon-sm') . '" aria-label="Предыдущий день" href="' . h(url_to('/shift/missing', ['d' => add_days($day, -1)])) . '">' . icon('chevronleft') . '</a>';
        $nav .= '<span class="text-sm font-semibold first-letter:uppercase">' . h(format_day_month($day)) . '</span>';
        if ($day < $today) {
            $nav .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/shift/missing', ['d' => add_days($day, 1)])) . '">' . icon('chevronright') . '</a>';
        }
        if ($day !== $today) {
            $nav .= '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/shift/missing')) . '">Сегодня</a>';
        }
        $nav .= '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/shift/reconcile')) . '">Сверка' . icon('arrow', 'size-3.5') . '</a></div>';

        $html = page_head('Не сдали смену', 'Одна таблица по всем филиалам: смена по графику закончилась, а сдачи в CRM нет.', $nav);
        if (!$rows) {
            $html .= empty_state('check-circle', 'Все сдали', 'На ' . format_day_month($day) . ' нет провалов по клубам — либо смены ещё идут, либо чек-лист закрыт.');
        } else {
            $html .= table_open(['Филиал', 'Смена', 'Кто по графику', 'Статус']);
            foreach ($rows as $r) {
                $html .= '<tr class="border-t border-border">';
                $html .= td('Филиал', h($r['branchName']));
                $html .= td('Смена', h((SHIFT_LABELS[$r['shiftType']] ?? $r['shiftType']) . ' · ' . format_day_month($r['date'])));
                $html .= td('Кто', h(short_name($r['employeeName'])));
                $html .= td('Статус', chip('Не сдана', 'red', 'alert'));
                $html .= '</tr>';
            }
            $html .= table_close();
            $html .= '<p class="mt-4 text-xs text-muted-foreground">Уведомление в Telegram уходит один раз в сутки тем, у кого в настройках включено «Не сдали смену».</p>';
        }
        self::layout($user, $html, ['title' => 'Не сдали смену', 'width' => 'wide']);
    }
}
