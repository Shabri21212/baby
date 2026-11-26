<?php
declare(strict_types=1);

trait PagesShifts
{
    private static function greeting(DateTimeImmutable $now): string
    {
        $h = (int) $now->format('G');
        return match (true) {
            $h < 5 => 'Доброй ночи',
            $h < 12 => 'Доброе утро',
            $h < 18 => 'Добрый день',
            default => 'Добрый вечер',
        };
    }

    private static function pageHome(array $user): void
    {
        $now = now_tyumen();
        $today = date_key($now);
        $name = self::displayName($user);
        $eid = $user['employeeId'];
        $current = self::currentShiftFor($eid);
        $upcoming = $eid ? self::upcomingShiftsFor($eid, 21) : [];
        $next = null;
        foreach ($upcoming as $s) {
            if ($s['state']['state'] === 'upcoming') {
                $next = $s;
                break;
            }
        }
        $live = self::liveShifts();
        $soon = self::soonByBranch();
        if (!can($user, 'schedule.view_all')) {
            $live = array_values(array_filter($live, static fn ($s) => can_view_schedule($user, (int) $s['branchId']) || (int) $s['employeeId'] === (int) $eid));
            $soon = array_filter($soon, static fn ($bid) => can_view_schedule($user, (int) $bid), ARRAY_FILTER_USE_KEY);
        }
        $handover = self::handoverShift($user);
        $handoverPhase = $handover
            ? self::handoverNextPhase($handover, self::handoverReports((int) $handover['branchId'], (string) $handover['date'], (string) $handover['shiftType']))
            : null;
        $prefs = $user['prefs'] ?? Notify::PREF_DEFAULTS;
        $attention = [];
        if ($handoverPhase === 'open') {
            $attention[] = [url_to('/shift'), 'clipboard-check', 'Примите смену — чек-лист приёма ещё не заполнен'];
        } elseif ($handoverPhase === 'close' && $handover && ($handover['state']['state'] === 'done' || $handover['state']['state'] === 'live')) {
            $attention[] = [url_to('/shift'), 'wallet', $handover['state']['state'] === 'done'
                ? 'Смена закончилась, но не сдана — заполните кассу и чек-лист'
                : 'Ближе к концу смены сдайте кассу и чек-лист'];
        }
        $workBranchId = $current ? (int) $current['branchId'] : (int) ($user['homeBranchId'] ?? 0);

        if ($eid && ($current || ($next && $next['date'] === $today))) {
            $taskDate = $current['date'] ?? $today;
            $myTasks = self::staffTasksFor($eid, $taskDate, $user['id']);
            $myLeft = count(array_filter($myTasks, static fn ($t) => empty($t['done_at'])));
            if ($myLeft > 0) {
                $attention[] = ['#', 'clipboard-list', plural($myLeft, 'личное задание на смену', 'личных задания на смену', 'личных заданий на смену')];
            }
        }
        if (Notify::feature($prefs, 'swaps')) {
            try {
                $fromSwap = $now->modify('-1 day')->format('Y-m-d');
                $openSwaps = (int) Db::value('SELECT COUNT(*) FROM shift_swaps w JOIN shifts s ON s.id = w."shiftId" WHERE w.status = ? AND s.date >= ?', ['open', $fromSwap]);
            } catch (PDOException) {
                $openSwaps = 0;
            }
            if ($openSwaps > 0) {
                $attention[] = [url_to('/shift/swaps'), 'users', plural($openSwaps, 'открытая подмена', 'открытые подмены', 'открытых подмен')];
            }
        }
        $homeBid = $current ? (int) $current['branchId'] : (int) ($user['homeBranchId'] ?? 0);
        if (can($user, 'page.reconcile') && Notify::feature($prefs, 'reconcileAttention') && $homeBid > 0) {
            $rec = self::shiftReconcileDay($homeBid, $today, $now);
            $recIssues = count(array_filter($rec['rows'], static fn ($r) => !empty($r['issues'])));
            if ($recIssues > 0) {
                $slug = self::branchById($homeBid)['slug'] ?? null;
                $attention[] = [url_to('/shift/reconcile', ['branch' => $slug]), 'scale', plural($recIssues, 'расхождение смен', 'расхождения смен', 'расхождений смен') . ' · контроль'];
            }
        }
        if (can($user, 'page.missing') && Notify::feature($prefs, 'reconcileAttention')) {
            $missScope = $homeBid > 0 ? [$homeBid] : user_branch_ids($user);
            if ($missScope !== null && $missScope !== []) {
                $miss = self::missingHandoversForDay($today, $now, $missScope);
                if ($miss) {
                    $attention[] = [url_to('/shift/missing'), 'alert', plural(count($miss), 'смена не сдана', 'смены не сданы', 'смен не сдано')];
                }
            }
        }
        $wd = WEEKDAY_LONG[weekday_from_date_key($today)];
        $dateLabel = mb_strtoupper(mb_substr($wd, 0, 1)) . mb_substr($wd, 1) . ', ' . format_day_month($today);

        $html = '<header class="flex flex-col gap-2">' . eyebrow($dateLabel);
        $html .= '<h1 class="font-display text-2xl leading-tight font-semibold tracking-tight text-balance sm:text-[2rem]">' . h(self::greeting($now)) . (($user['role'] ?? '') === 'owner' ? '' : ', ' . h(first_name($name))) . '</h1>';
        $html .= '<p class="flex items-center gap-2 text-sm text-muted-foreground">' . icon('clock', 'size-4') . '<span>Сейчас <time data-clock class="font-semibold text-foreground tabular-nums">' . $now->format('H:i') . '</time></span>';
        $html .= '<span class="ml-2 text-xs text-muted-foreground" data-langame-status hidden>Обновляем данные клуба…</span></p></header>';
        $load = self::clubLoadPanel($user);
        $needLiveReload = $load['reload'];
        $html .= $load['html'];

        /* Главная — вкладками: всё важное видно на первом экране, без длинной прокрутки. */
        $shiftMain = '';
        if ($eid) {
            $hero = $current ?? $next;
            $heroPhase = $hero && $handover && (int) $handover['id'] === (int) $hero['id'] ? $handoverPhase : null;
            $shiftMain .= self::shiftTicket($hero, $live, $today, $heroPhase);
            $taskDate = $current['date'] ?? (($next && $next['date'] === $today) ? $today : null);
            if ($taskDate !== null) {
                $shiftMain .= self::myTasksCard($user, $taskDate, $today);
            }
            // Шаблоны «Задания дня» сами по себе сотрудникам не приходят: они видят только то,
            // что руководитель реально назначил на день (в т.ч. из шаблона) — см. myTasksCard().
        } else {
            $shiftMain .= self::clubsNowHero($live, $soon, $user);
        }
        $shiftSide = self::attentionCard($user, $attention);
        if ($eid) {
            $rest = array_values(array_filter($upcoming, static fn ($s) => !$hero || $s['id'] !== $hero['id']));
            $shiftSide .= self::upcomingCard(array_slice($rest, 0, 6));
        }

        $panels = [
            'shift' => ['Смена', 'calendar', $shiftMain, $shiftSide],
            'money' => ['Деньги', 'wallet', self::personalPayCard($user) . self::todayTopupsCard($user), self::myRecordsCard($user)],
            'top' => ['Рейтинги', 'trophy', self::adminsTopCard($user), self::clubsBarTopCard($user)],
            'club' => ['Клуб', 'users', ($eid ? self::clubsNowCard($live, $soon, $user) : '') . (can($user, 'tasks.assign') && $live ? self::liveTasksOverview($user, $live) : ''), self::latestNewsCard($user)],
        ];
        $panels = array_filter($panels, static fn ($p) => trim($p[2] . $p[3]) !== '');
        $html .= self::homeTabs($panels);
        self::layout($user, $html, ['title' => 'Мой день', 'langameReload' => $needLiveReload]);
    }

    /**
     * Вкладки главной. panels: key => [label, icon, main html, side html].
     * Переключаются без перезагрузки (data-tabs в app.js), выбранная вкладка запоминается в адресе (#money).
     */
    private static function homeTabs(array $panels): string
    {
        if ($panels === []) {
            return '';
        }
        $first = array_key_first($panels);
        $html = '<section class="flex flex-col gap-5" data-tabs>';
        $html .= '<div class="flex max-w-full gap-1 self-start overflow-x-auto rounded-xl border border-border bg-card p-1 shadow-xs scrollbar-none" role="tablist" aria-label="Разделы главной">';
        foreach ($panels as $key => [$label, $ic]) {
            $on = $key === $first;
            $html .= '<button type="button" role="tab" id="tab-' . $key . '" aria-controls="panel-' . $key . '" aria-selected="' . ($on ? 'true' : 'false') . '" tabindex="' . ($on ? '0' : '-1') . '" data-tab="' . $key . '"'
                . ' class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium whitespace-nowrap text-muted-foreground transition-colors hover:text-foreground aria-selected:bg-primary aria-selected:text-primary-foreground">'
                . icon($ic, 'size-4') . h($label) . '</button>';
        }
        $html .= '</div>';
        foreach ($panels as $key => [, , $main, $side]) {
            $grid = trim($main) !== '' && trim($side) !== ''
                ? 'grid items-start gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]'
                : 'grid items-start gap-6';
            $html .= '<div role="tabpanel" id="panel-' . $key . '" aria-labelledby="tab-' . $key . '" data-tab-panel="' . $key . '" class="' . $grid . '"' . ($key === $first ? '' : ' hidden') . '>';
            foreach ([$main, $side] as $col) {
                if (trim($col) !== '') {
                    $html .= '<div class="flex min-w-0 flex-col gap-6">' . $col . '</div>';
                }
            }
            $html .= '</div>';
        }
        return $html . '</section>';
    }

    /** Ближайшая следующая смена по каждому филиалу (сегодня–завтра). */
    private static function soonByBranch(): array
    {
        $now = now_tyumen();
        $rows = Db::all(
            'SELECT s.date, s."branchId", s."shiftType", s."employeeId", e.name AS "employeeName"
             FROM shifts s JOIN employees e ON e.id = s."employeeId"
             WHERE s.date IN (?, ?) ORDER BY e."sortOrder"',
            [date_key($now), $now->modify('+1 day')->format('Y-m-d')],
        );
        $best = [];
        foreach ($rows as $r) {
            $st = shift_state(substr((string) $r['date'], 0, 10), (string) $r['shiftType'], $now, (int) $r['branchId']);
            if ($st['state'] !== 'upcoming') {
                continue;
            }
            $bid = (int) $r['branchId'];
            $start = $st['start']->getTimestamp();
            if (!isset($best[$bid]) || $start < $best[$bid]['start']) {
                $best[$bid] = ['start' => $start, 'startAt' => $st['start'], 'type' => $r['shiftType'], 'names' => [short_name((string) $r['employeeName'])]];
            } elseif ($start === $best[$bid]['start']) {
                $best[$bid]['names'][] = short_name((string) $r['employeeName']);
            }
        }
        return $best;
    }

    private static function shiftTicket(?array $s, array $live, string $today, ?string $handoverPhase = null): string
    {
        $html = '<section class="cut hero-panel relative flex flex-col gap-6 p-5  sm:p-7" style="--cut:26px" aria-labelledby="my-shift-title">';
        $html .= Course::settings()['newYear']
            ? newyear_art('club')
            : '<img src="' . h(asset('img/club/hall-wide-sm.webp')) . '" alt="" class="pointer-events-none absolute inset-y-0 right-0 hidden h-full w-1/2 object-cover opacity-25 [mask-image:linear-gradient(to_left,black,transparent)] sm:block" aria-hidden="true">';
        $html .= '<div class="relative flex flex-wrap items-center justify-between gap-3"><p class="flex items-center gap-2 text-[11px] font-semibold tracking-[0.18em] text-sidebar-muted uppercase"><span class="cut-mark" aria-hidden="true"></span>Моя смена</p>';
        if (!$s) {
            $html .= '</div><div class="relative flex flex-col gap-2"><p id="my-shift-title" class="font-display text-2xl font-semibold">Смен пока нет</p><p class="max-w-md text-sm leading-relaxed text-sidebar-foreground">На ближайшие три недели смены не назначены.</p></div>';
            $html .= '<a href="' . h(url_to('/schedule')) . '" class="relative inline-flex w-fit items-center gap-1.5 text-sm font-semibold hover:text-brand">Открыть график ' . icon('arrow', 'size-4') . '</a></section>';
            return $html;
        }
        $type = (string) $s['shiftType'];
        $st = $s['state'];
        $isLive = $st['state'] === 'live';
        if ($isLive) {
            $html .= '<span class="inline-flex items-center gap-2 rounded-[5px] bg-primary px-2 py-0.5 text-xs leading-5 font-semibold text-primary-foreground"><span class="live-dot" aria-hidden="true"></span>Идёт сейчас</span>';
        } else {
            $html .= '<span class="rounded-[5px] bg-sidebar-accent px-2 py-0.5 text-xs leading-5 font-semibold">' . ($s['date'] === $today ? 'Сегодня' : 'Следующая') . '</span>';
        }
        $html .= '</div>';
        $tone = $type === 'night' ? 'text-night-strong' : 'text-day-strong';
        $html .= '<div class="relative flex flex-col gap-2"><p id="my-shift-title" class="font-display text-[2rem] leading-none font-semibold tracking-tight sm:text-[2.6rem]">' . h($s['branchName']) . '</p>';
        $html .= '<p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-sidebar-foreground">' . icon(SHIFT_ICONS[$type] ?? 'sun', 'size-4 ' . $tone) . '<span class="font-semibold text-sidebar-accent-foreground">' . h(SHIFT_LABELS[$type] ?? $type) . '</span><span>' . $st['start']->format('H:i') . '–' . $st['end']->format('H:i') . '</span>';
        if ($s['date'] !== $today) {
            $html .= '<span>· ' . h(format_day_short($s['date'])) . '</span>';
        }
        $html .= '</p></div>';
        if ($isLive) {
            $html .= '<div class="relative flex max-w-lg flex-col gap-2"><div class="h-1.5 overflow-hidden rounded-full bg-sidebar-accent" role="progressbar" aria-label="Прошло смены" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $st['progress'] . '"><div class="h-full rounded-full bg-primary" style="width:' . $st['progress'] . '%"></div></div>';
            $html .= '<div class="flex justify-between gap-2 text-xs text-sidebar-muted tabular-nums"><span>' . $st['start']->format('H:i') . '</span><span class="font-semibold text-sidebar-accent-foreground">осталось ' . h(duration_label($st['minutesLeft'])) . '</span><span>' . $st['end']->format('H:i') . '</span></div></div>';
            $mates = array_values(array_filter($live, static fn ($r) => (int) $r['branchId'] === (int) $s['branchId'] && (int) $r['employeeId'] !== (int) $s['employeeId']));
            if ($mates) {
                $html .= '<p class="relative text-sm text-sidebar-foreground">С вами на смене: <span class="font-semibold text-sidebar-accent-foreground">' . h(implode(', ', array_map(static fn ($m) => short_name((string) $m['employeeName']), $mates))) . '</span></p>';
            }
        } else {
            $html .= '<p class="relative text-sm text-sidebar-foreground">Начнётся через <span class="font-semibold text-sidebar-accent-foreground">' . h(duration_label($st['minutesToStart'])) . '</span></p>';
        }
        $html .= '<div class="relative flex flex-wrap items-center gap-x-5 gap-y-3">';
        if ($handoverPhase !== null && isset(HANDOVER_PHASES[$handoverPhase])) {
            $meta = HANDOVER_PHASES[$handoverPhase];
            $html .= '<a href="' . h(url_to('/shift')) . '" class="' . btn_class('primary') . '">' . icon($meta['icon'], 'size-4') . h($meta['verb']) . '</a>';
        }
        $html .= '<a href="' . h(url_to('/schedule', ['branch' => self::branchById((int) $s['branchId'])['slug'] ?? null])) . '" class="inline-flex w-fit items-center gap-1.5 text-sm font-semibold hover:text-brand">График смен ' . icon('arrow', 'size-4') . '</a>';
        return $html . '</div></section>';
    }

    private static function myTasksCard(array $user, string $date, string $today): string
    {
        $tasks = self::staffTasksFor((int) $user['employeeId'], $date, $user['id']);
        $editable = $date === $today || $date === add_days($today, -1);
        $done = count(array_filter($tasks, static fn ($t) => !empty($t['done_at'])));
        $meta = h(format_date_human($date)) . ($tasks ? ' · выполнено ' . $done . ' из ' . count($tasks) : '');
        $html = card_head('Задания на смену', $meta, '', 'clipboard-list');
        if (!$tasks) {
            $off = Db::one('SELECT id FROM task_off_days WHERE date = ? LIMIT 1', [$date]);
            $html .= '<p class="mt-4 text-sm text-muted-foreground">' . ($off ? 'Сегодня без заданий — управляющий отметил день свободным.' : 'На эту смену заданий пока не назначено.') . '</p>';
            return card($html, 'p-5');
        }
        $html .= '<ul class="mt-4 flex flex-col gap-2">';
        foreach ($tasks as $t) {
            $isDone = !empty($t['done_at']);
            $html .= '<li class="flex items-start gap-3 rounded-md border border-border p-3 ' . ($isDone ? 'bg-muted/40' : 'bg-card') . '">';
            if ($editable) {
                $html .= form_open($isDone ? 'uncomplete_task' : 'complete_task', 'class="shrink-0"') . hidden('taskId', (int) $t['id']);
                $html .= '<button class="flex size-6 items-center justify-center rounded-[5px] border-2 transition-colors ' . ($isDone ? 'border-foreground bg-foreground text-background' : 'border-input hover:border-foreground') . '" aria-label="' . ($isDone ? 'Снять отметку' : 'Отметить выполненным') . ': ' . h($t['title']) . '">' . ($isDone ? icon('check', 'size-4') : '') . '</button></form>';
            } else {
                $html .= '<span class="flex size-6 shrink-0 items-center justify-center rounded-[5px] border-2 ' . ($isDone ? 'border-foreground bg-foreground text-background' : 'border-input') . '">' . ($isDone ? icon('check', 'size-4') : '') . '</span>';
            }
            $html .= '<div class="flex min-w-0 flex-1 flex-col gap-1"><p class="font-semibold leading-snug text-pretty ' . ($isDone ? 'text-muted-foreground line-through decoration-1' : '') . '">' . h($t['title']) . '</p>';
            if (trim((string) $t['description']) !== '') {
                $html .= '<p class="text-sm leading-relaxed whitespace-pre-line text-muted-foreground break-words">' . h($t['description']) . '</p>';
            }
            if ($isDone) {
                $html .= '<p class="text-xs font-medium text-muted-foreground">Выполнено ' . h(format_dt((string) $t['done_at'])) . '</p>';
            }
            $html .= '</div></li>';
        }
        return card($html . '</ul>', 'p-5');
    }

    /** Задания дня по дню недели (общие для филиала). @return list<array<string,mixed>> */
    private static function dailyTasksFor(string $date, int $branchId): array
    {
        $wd = weekday_from_date_key($date);
        try {
            $rows = Db::all(
                'SELECT t.id, t.title, t.description, t."branchId",
                        c."completedAt" AS done_at, c."userName" AS done_by
                 FROM daily_tasks t
                 LEFT JOIN daily_task_completions c ON c."taskId" = t.id AND c.date = ? AND c."branchId" = ?
                 WHERE t."isActive" = 1 AND t.weekday = ? AND (t."branchId" IS NULL OR t."branchId" = ?)
                 ORDER BY t."sortOrder", t.id',
                [$date, $branchId, $wd, $branchId],
            );
        } catch (PDOException) {
            return [];
        }
        return array_values(array_filter($rows, static fn ($r) => mb_strlen(trim((string) $r['title'])) >= 4));
    }

    /** @param list<array<string,mixed>> $tasks */
    private static function dailyTasksCard(array $tasks, int $branchId, string $today): string
    {
        $done = count(array_filter($tasks, static fn ($t) => !empty($t['done_at'])));
        $wd = WEEKDAY_LONG[weekday_from_date_key($today)];
        $meta = mb_strtoupper(mb_substr($wd, 0, 1)) . mb_substr($wd, 1) . ($tasks ? ' · ' . $done . ' из ' . count($tasks) : '');
        $html = card_head('Задания дня', $meta, '', 'sun');
        if (!$tasks) {
            $html .= '<p class="mt-4 text-sm text-muted-foreground">На сегодня шаблонов нет. Руководитель задаёт их в «Задания на смены».</p>';
            return '<div id="daily-tasks" class="scroll-mt-24">' . card($html, 'p-5') . '</div>';
        }
        $html .= '<ul class="mt-4 flex flex-col gap-2">';
        foreach ($tasks as $t) {
            $isDone = !empty($t['done_at']);
            $html .= '<li class="flex items-start gap-3 rounded-md border border-border p-3 ' . ($isDone ? 'bg-muted/40' : 'bg-card') . '">';
            $html .= form_open($isDone ? 'uncomplete_daily_task' : 'complete_daily_task', 'class="shrink-0"') . hidden('taskId', (int) $t['id']) . hidden('branchId', $branchId);
            $html .= '<button class="flex size-6 items-center justify-center rounded-[5px] border-2 transition-colors ' . ($isDone ? 'border-foreground bg-foreground text-background' : 'border-input hover:border-foreground') . '" aria-label="' . ($isDone ? 'Снять отметку' : 'Отметить выполненным') . ': ' . h($t['title']) . '">' . ($isDone ? icon('check', 'size-4') : '') . '</button></form>';
            $html .= '<div class="flex min-w-0 flex-1 flex-col gap-1"><p class="font-semibold leading-snug text-pretty ' . ($isDone ? 'text-muted-foreground line-through decoration-1' : '') . '">' . h($t['title']) . '</p>';
            if (trim((string) $t['description']) !== '') {
                $html .= '<p class="text-sm leading-relaxed whitespace-pre-line text-muted-foreground break-words">' . h($t['description']) . '</p>';
            }
            if ($isDone) {
                $html .= '<p class="text-xs font-medium text-muted-foreground">Отметил(а) ' . h(short_name((string) ($t['done_by'] ?? ''))) . ' · ' . h(format_dt((string) $t['done_at'])) . '</p>';
            }
            $html .= '</div></li>';
        }
        return '<div id="daily-tasks" class="scroll-mt-24">' . card($html . '</ul>', 'p-5') . '</div>';
    }

    private static function upcomingCard(array $rows): string
    {
        $html = card_head('Ближайшие смены', $rows ? '' : 'Других смен в ближайшие три недели нет', '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/schedule')) . '">Весь график ' . icon('arrow', 'size-3.5') . '</a>', 'calendar');
        if ($rows) {
            $html .= '<ul class="mt-4 flex flex-col divide-y divide-border">';
            foreach ($rows as $r) {
                $type = (string) $r['shiftType'];
                $html .= '<li class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">';
                $html .= '<span class="flex size-9 shrink-0 items-center justify-center rounded-md ' . shift_tone_class($type) . '">' . icon(SHIFT_ICONS[$type] ?? 'sun', 'size-4') . '</span>';
                $html .= '<div class="flex min-w-0 flex-1 flex-col"><span class="text-sm font-semibold capitalize">' . h(format_day_short($r['date'])) . '</span><span class="text-xs text-muted-foreground">' . h(SHIFT_LABELS[$type] ?? $type) . ' ' . h(shift_hint($type, (int) $r['branchId'])) . '</span></div>';
                $html .= '<span class="text-sm text-muted-foreground">' . h($r['branchName']) . '</span></li>';
            }
            $html .= '</ul>';
        }
        return card($html, 'p-5');
    }

    private static function attentionCard(array $user, array $items = []): string
    {
        $prefs = $user['prefs'] ?? Notify::PREF_DEFAULTS;
        if (!Notify::feature($prefs, 'inbox')) {
            return '';
        }
        $c = self::counts($user);
        if ($c['news'] > 0) {
            $items[] = [url_to('/news'), 'megaphone', plural($c['news'], 'новое объявление', 'новых объявления', 'новых объявлений')];
        }
        if ($c['issues'] > 0) {
            $items[] = [url_to('/issues'), 'wrench', plural($c['issues'], 'открытая поломка', 'открытые поломки', 'открытых поломок')];
        }
        if ($c['reviews'] > 0) {
            $items[] = [url_to('/reviews', ['status' => 'pending']), 'star', plural($c['reviews'], 'заявка на бонусы', 'заявки на бонусы', 'заявок на бонусы')];
        }
        if ($c['compensations'] > 0) {
            $items[] = [url_to('/compensations', ['status' => 'pending']), 'coins', plural($c['compensations'], 'компенсация ждёт', 'компенсации ждут', 'компенсаций ждут')];
        }
        $n = count($items);
        $html = card_head('На смену', $n ? plural($n, 'дело', 'дела', 'дел') : 'пусто', '<a class="text-xs font-medium text-muted-foreground hover:text-foreground" href="' . h(url_to('/account') . '#prefs') . '">настроить</a>', 'bell');
        if (!$items) {
            $html .= '<p class="mt-3 flex items-center gap-2 text-sm text-muted-foreground">' . icon('check-circle', 'size-4') . 'Дел нет.</p>';
            return card($html, 'p-4');
        }
        $html .= '<ul class="mt-3 flex flex-col divide-y divide-border">';
        foreach ($items as [$href, $ic, $label]) {
            $tag = $href === '#' ? 'div' : 'a';
            $hrefAttr = $href === '#' ? '' : ' href="' . h($href) . '"';
            $html .= '<li><' . $tag . $hrefAttr . ' class="group flex items-center gap-2.5 py-2 text-sm font-medium transition-colors' . ($href === '#' ? '' : ' hover:text-brand') . '"><span class="text-primary">' . icon($ic, 'size-3.5') . '</span><span class="min-w-0 flex-1 leading-snug">' . h($label) . '</span>' . ($href === '#' ? '' : icon('chevronright', 'size-3.5 text-muted-foreground transition-transform group-hover:translate-x-0.5')) . '</' . $tag . '></li>';
        }
        return card($html . '</ul>', 'p-4');
    }

    private static function clubBranchRows(array $live, array $soon, bool $dark, array $user): string
    {
        $muted = $dark ? 'text-sidebar-muted' : 'text-muted-foreground';
        $html = '';
        foreach (self::branches() as $b) {
            if (!can_view_schedule($user, $b['id'])) {
                continue;
            }
            $people = array_values(array_filter($live, static fn ($r) => (int) $r['branchId'] === $b['id']));
            $html .= '<div class="flex min-w-0 flex-col gap-2.5">';
            $html .= '<div class="flex items-baseline justify-between gap-2"><p class="font-semibold">' . h($b['name']) . '</p><span class="text-xs ' . $muted . '">' . ($people ? h(plural(count($people), 'человек', 'человека', 'человек')) : 'никого') . '</span></div>';
            if ($people) {
                $html .= '<div class="flex flex-wrap gap-1.5">';
                foreach ($people as $p) {
                    $type = (string) $p['shiftType'];
                    $html .= '<span class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold ' . shift_tone_class($type) . '">' . icon(SHIFT_ICONS[$type] ?? 'sun', 'size-3.5') . h(short_name((string) $p['employeeName'])) . '</span>';
                }
                $html .= '</div>';
            } else {
                $html .= '<p class="text-sm ' . $muted . '">Сейчас на смене никого нет</p>';
            }
            if (isset($soon[$b['id']])) {
                $n = $soon[$b['id']];
                $html .= '<p class="text-xs leading-relaxed ' . $muted . '">Далее · ' . h(SHIFT_LABELS[$n['type']] ?? '') . ' с ' . $n['startAt']->format('H:i') . ': ' . h(implode(', ', $n['names'])) . '</p>';
            }
            $html .= '</div>';
        }
        return $html;
    }

    /**
     * Посадка и загрузка залов по данным Langame — инфографика в шапке «Моего дня».
     * @return array{html: string, reload: bool}
     */
    private static function clubLoadPanel(array $user): array
    {
        $enabled = Langame::enabledBranchIds();
        if (!$enabled) {
            return ['html' => '', 'reload' => false];
        }
        $now = now_tyumen();
        $hourNow = (int) $now->format('G');
        $cards = '';
        $totActive = 0;
        $totPcs = 0;
        $totSessions = 0;
        $shown = 0;
        $waiting = 0;
        // Посадку видят только те, кто привязан к филиалу: чужие залы администратору не показываем.
        foreach (self::visibleBranches($user) as $b) {
            if (!in_array($b['id'], $enabled, true)) {
                continue;
            }
            $live = Langame::live((int) $b['id']);
            if (!$live) {
                $waiting++;
                continue;
            }
            $active = $live['active'];
            $pcs = (int) ($b['pcCount'] ?? 0) ?: (int) ($live['pcsSeen'] ?? 0);
            $pct = $active !== null && $pcs > 0 ? min(100, (int) round($active / $pcs * 100)) : null;
            $totActive += (int) $active;
            $totPcs += $pcs;
            $totSessions += (int) ($live['sessionsToday'] ?? 0);
            $hourly = $live['hourly'] ?? array_fill(0, 24, 0);
            $peakVal = max($hourly);
            $peak = $peakVal > 0 ? (int) array_search($peakVal, $hourly, true) : null;
            $level = $pct === null ? 'нет данных' : ($pct >= 80 ? 'почти полный' : ($pct >= 45 ? 'оживлённо' : ($pct > 0 ? 'спокойно' : 'пусто')));

            $r = 34;
            $len = 2 * M_PI * $r;
            $dash = $pct !== null ? round($len * $pct / 100, 1) : 0;
            $gauge = '<svg viewBox="0 0 80 80" class="size-24 shrink-0 -rotate-90" role="img" aria-label="' . h($b['name'] . ': занято ' . ($pct ?? 0) . '%') . '">'
                . '<circle cx="40" cy="40" r="' . $r . '" fill="none" stroke="currentColor" stroke-width="7" class="text-sidebar-foreground/10"/>'
                . '<circle cx="40" cy="40" r="' . $r . '" fill="none" stroke="currentColor" stroke-width="7" stroke-linecap="round" class="text-brand" stroke-dasharray="' . $dash . ' ' . round($len, 1) . '"/></svg>';

            $c = '<article class="flex min-w-0 flex-col gap-4 rounded-lg border border-sidebar-foreground/10 bg-sidebar-foreground/[0.03] p-4">';
            $c .= '<div class="flex items-center justify-between gap-2"><h3 class="font-display text-base font-semibold">' . h($b['name']) . '</h3><span class="text-xs text-sidebar-muted">' . h($level) . '</span></div>';
            $c .= '<div class="flex items-center gap-4"><div class="relative">' . $gauge . '<span class="absolute inset-0 flex flex-col items-center justify-center"><span class="font-display text-xl leading-none font-semibold tabular-nums">' . ($pct !== null ? $pct . '%' : '—') . '</span><span class="mt-1 text-[10px] text-sidebar-muted tabular-nums">' . ($active !== null ? (int) $active . ($pcs ? ' / ' . $pcs : '') . ' ПК' : '') . '</span></span></div>';
            $c .= '<dl class="grid min-w-0 flex-1 grid-cols-2 gap-x-3 gap-y-2 text-sm">';
            foreach ([
                ['Сессий', $live['sessionsToday'] !== null ? (string) (int) $live['sessionsToday'] : '—'],
                ['Гостей', isset($live['guestsToday']) && $live['guestsToday'] !== null ? (string) (int) $live['guestsToday'] : '—'],
                ['Средняя', isset($live['avgMinutes']) && $live['avgMinutes'] !== null ? self::minutesLabel((int) $live['avgMinutes']) : '—'],
                ['По брони', (string) (int) ($live['bookedToday'] ?? 0)],
            ] as [$k, $v]) {
                $c .= '<div class="flex min-w-0 flex-col"><dt class="text-[11px] text-sidebar-muted">' . $k . '</dt><dd class="truncate font-semibold tabular-nums">' . h($v) . '</dd></div>';
            }
            $c .= '</dl></div>';

            $maxH = max(1, $peakVal);
            $c .= '<div class="flex flex-col gap-1.5"><div class="flex h-12 items-end gap-[2px]" role="img" aria-label="' . h('Загрузка по часам, ' . $b['name']) . '">';
            foreach ($hourly as $h => $v) {
                $future = $h > $hourNow;
                $tone = $h === $hourNow ? 'bg-brand' : ($future ? 'bg-sidebar-foreground/10' : 'bg-sidebar-foreground/35');
                $height = $future ? 6 : max(6, (int) round($v / $maxH * 100));
                $c .= '<span class="flex h-full min-w-0 flex-1 items-end" title="' . h(sprintf('%02d:00 — %d ПК', $h, $v)) . '"><span class="block w-full rounded-t-[2px] ' . $tone . '" style="height:' . $height . '%"></span></span>';
            }
            $c .= '</div><div class="flex justify-between text-[10px] text-sidebar-muted tabular-nums"><span>00</span><span>' . ($peak !== null ? 'пик ' . sprintf('%02d:00', $peak) . ' · ' . $peakVal . ' ПК' : 'сегодня') . '</span><span>23</span></div></div>';
            $cards .= $c . '</article>';
            $shown++;
        }
        if ($cards === '' && $waiting === 0) {
            return ['html' => '', 'reload' => false];
        }
        if ($cards === '' && $waiting > 0) {
            return ['html' => '<section class="cut hero-panel flex flex-col gap-3 p-5 sm:p-6" style="--cut:22px" aria-labelledby="club-load" data-langame-live>'
                . '<p id="club-load" class="flex items-center gap-2 text-[11px] font-semibold tracking-[0.18em] text-sidebar-muted uppercase"><span class="cut-mark" aria-hidden="true"></span>Посадка в зале</p>'
                . '<p class="text-sm text-sidebar-muted">Подгружаем занятость ПК из Langame — страница уже открыта, цифры появятся через несколько секунд.</p>'
                . '</section>', 'reload' => true];
        }
        $totPct = $totPcs > 0 ? (int) round($totActive / $totPcs * 100) : null;
        $html = '<section class="cut hero-panel flex flex-col gap-5 p-5 sm:p-6" style="--cut:22px" aria-labelledby="club-load">';
        $html .= '<div class="flex flex-wrap items-center justify-between gap-3"><p id="club-load" class="flex items-center gap-2 text-[11px] font-semibold tracking-[0.18em] text-sidebar-muted uppercase"><span class="cut-mark" aria-hidden="true"></span>' . ($shown > 1 ? 'Посадка в залах' : 'Посадка в зале') . '</p>';
        $html .= '<span class="inline-flex items-center gap-2 rounded-[5px] bg-sidebar-accent px-2 py-0.5 text-xs leading-5 font-semibold tabular-nums"><span class="live-dot" aria-hidden="true"></span>' . ($totPct !== null ? $totPct . '% · ' : '') . $totActive . ($totPcs ? ' из ' . $totPcs : '') . ' ПК · ' . h(plural($totSessions, 'сессия', 'сессии', 'сессий')) . ' сегодня</span></div>';
        $html .= '<div class="grid gap-4' . ($shown > 1 ? ' md:grid-cols-2' : '') . '">' . $cards . '</div>';
        return ['html' => $html . '</section>', 'reload' => false];
    }

    private static function minutesLabel(int $m): string
    {
        return $m >= 60 ? intdiv($m, 60) . ' ч ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) . ' м' : $m . ' мин';
    }

    /** Топ сотрудников по продажам бара — не выручка клуба. */
    private static function todayTopupsCard(array $user): string
    {
        if (!can($user, 'page.bar')) {
            return '';
        }
        $branches = self::barBranches($user);
        if (!$branches) {
            return '';
        }
        $work = self::workBranchId($user);
        $branch = $branches[0];
        foreach ($branches as $b) {
            if ((int) $b['id'] === $work) {
                $branch = $b;
            }
        }
        $now = now_tyumen();
        $monthKey = $now->format('Y-m');
        $from = $now->format('Y-m-01');
        $to = $now->format('Y-m-t');
        $monthName = mb_strtolower(MONTH_NAMES[(int) $now->format('n') - 1]);
        $totals = Langame::totalsByEmployee($from, $to, (int) $branch['id']);
        if (!$totals) {
            return '';
        }
        uasort($totals, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $names = [];
        foreach (self::employees() as $e) {
            $names[(int) $e['id']] = (string) $e['name'];
        }
        $me = (int) ($user['employeeId'] ?? 0);
        $link = url_to('/bar', ['branch' => $branch['slug'], 'm' => $monthKey, 'view' => 'me']);
        $body = card_head(
            'Топ продаж бара',
            h((string) $branch['name']) . ' · ' . h($monthName) . ' · кто сколько пробил в баре',
            '<a class="' . btn_class('quiet', 'sm') . '" href="' . h($link) . '">Мои продажи' . icon('arrow', 'size-4') . '</a>',
            'trophy',
        );
        $body .= '<ol class="mt-4 flex flex-col gap-2">';
        $place = 0;
        $myPlace = null;
        $myRev = null;
        foreach ($totals as $id => $t) {
            $place++;
            $mine = $me > 0 && $id === $me;
            if ($mine) {
                $myPlace = $place;
                $myRev = (int) $t['revenue'];
            }
            if ($place > 5) {
                continue;
            }
            $body .= '<li class="flex items-center gap-3 rounded-lg border border-border px-3 py-2.5' . ($mine ? ' bg-muted/40' : '') . '">';
            $body .= '<span class="flex size-7 shrink-0 items-center justify-center rounded-md font-display text-xs font-semibold tabular-nums ' . ($place === 1 ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground') . '">' . $place . '</span>';
            $body .= '<span class="min-w-0 flex-1 truncate text-sm font-medium">' . h(short_name($names[$id] ?? 'Сотрудник')) . ($mine ? ' · вы' : '') . '</span>';
            $body .= '<span class="shrink-0 font-display text-sm font-semibold tabular-nums">' . h(kop_rub((int) $t['revenue'])) . '</span></li>';
        }
        $body .= '</ol>';
        if ($myPlace !== null && $myPlace > 5 && $myRev !== null) {
            $body .= '<p class="mt-3 text-sm text-muted-foreground">Вы на ' . $myPlace . ' месте · <span class="font-semibold text-foreground tabular-nums">' . h(kop_rub($myRev)) . '</span></p>';
        }
        return card($body, 'p-5');
    }

    /** Рейтинг клубов по продажам бара за текущий месяц. */
    private static function clubsBarTopCard(array $user): string
    {
        if (!can($user, 'page.bar')) {
            return '';
        }
        $branches = self::barBranches($user);
        if (count($branches) < 2) {
            return '';
        }
        $now = now_tyumen();
        $from = $now->format('Y-m-01');
        $to = $now->format('Y-m-t');
        $monthName = mb_strtolower(MONTH_NAMES[(int) $now->format('n') - 1]);
        $ids = array_map(static fn ($b) => (int) $b['id'], $branches);
        $totals = Langame::barRevenueByBranch($from, $to, $ids);
        $rows = [];
        foreach ($branches as $b) {
            $id = (int) $b['id'];
            $rows[] = ['branch' => $b, 'revenue' => (int) ($totals[$id]['revenue'] ?? 0)];
        }
        usort($rows, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        if ($rows[0]['revenue'] <= 0) {
            return '';
        }
        $max = max(1, (int) $rows[0]['revenue']);
        $body = card_head('Топ клубов', 'Продажи бара · ' . h($monthName), '', 'building');
        $body .= '<ol class="mt-4 flex flex-col gap-3">';
        foreach ($rows as $i => $row) {
            $place = $i + 1;
            $w = max(6, (int) round($row['revenue'] / $max * 100));
            $b = $row['branch'];
            $body .= '<li><a class="block rounded-lg border border-border px-3 py-2.5 transition-colors hover:bg-muted/40" href="' . h(url_to('/bar', ['branch' => $b['slug'], 'view' => 'me'])) . '">';
            $body .= '<span class="flex items-center gap-3"><span class="flex size-7 shrink-0 items-center justify-center rounded-md font-display text-xs font-semibold tabular-nums ' . ($place === 1 ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground') . '">' . $place . '</span>';
            $body .= '<span class="min-w-0 flex-1 truncate text-sm font-medium">' . h((string) $b['name']) . '</span>';
            $body .= '<span class="shrink-0 font-display text-sm font-semibold tabular-nums">' . h(kop_rub($row['revenue'])) . '</span></span>';
            $body .= '<span class="mt-2 block h-1 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full ' . ($place === 1 ? 'bg-primary' : 'bg-foreground/35') . '" style="width:' . $w . '%"></span></span>';
            $body .= '</a></li>';
        }
        $body .= '</ol>';
        return card($body, 'p-5');
    }

    /** Общий топ администраторов по всем филиалам + личные «рекорды» месяца. */
    private static function myRecordsCard(array $user): string
    {
        $eid = (int) ($user['employeeId'] ?? 0);
        if ($eid <= 0 || !can($user, 'stats.personal')) {
            return '';
        }
        $now = now_tyumen();
        $from = $now->format('Y-m-01');
        $prevFirst = $now->modify('first day of last month');
        $p = Insights::personal($eid, $from, $now->format('Y-m-t'), $prevFirst->format('Y-m-01'), $prevFirst->format('Y-m-t'));
        if (!$p['best'] && !$p['revenue']) {
            return '';
        }
        $body = card_head('Мои рекорды', 'По продажам бара в Langame', '', 'star');
        $delta = $p['prevRevenue'] > 0 ? (int) round(($p['revenue'] - $p['prevRevenue']) / $p['prevRevenue'] * 100) : null;
        $body .= '<div class="mt-4 flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs text-muted-foreground">Этот месяц</p><p class="mt-1 font-display text-3xl font-semibold tabular-nums">' . h(kop_rub($p['revenue'])) . '</p></div>';
        if ($delta !== null) {
            $body .= '<span class="rounded-md px-2 py-1 text-xs font-semibold tabular-nums ' . ($delta >= 0 ? 'bg-success/15 text-success' : 'bg-destructive/15 text-destructive') . '">' . ($delta >= 0 ? '+' : '') . $delta . '% к прошлому месяцу</span>';
        }
        $body .= '</div>';
        $facts = [];
        if ($p['best']) {
            $facts[] = ['Лучшая смена за всё время', kop_rub($p['best']['amount']), self::branchName($p['best']['branchId']) . ' · ' . date('d.m.Y', (int) strtotime($p['best']['date']))];
        }
        if ($p['shifts']) {
            $facts[] = ['В среднем за смену', kop_rub(intdiv($p['revenue'], $p['shifts'])), plural($p['shifts'], 'смена', 'смены', 'смен') . ' в этом месяце'];
        }
        if ($p['favorite']) {
            $facts[] = ['Продаёте чаще всего', $p['favorite']['name'], $p['favorite']['qty'] . ' шт за месяц'];
        }
        if ($p['hour'] !== null) {
            $facts[] = ['Золотой час', sprintf('%02d:00–%02d:00', $p['hour'], ($p['hour'] + 1) % 24), 'когда вы продаёте больше всего'];
        }
        $body .= '<dl class="mt-4 grid gap-3 border-t border-border pt-4 sm:grid-cols-2">';
        foreach ($facts as [$label, $value, $hint]) {
            $body .= '<div class="min-w-0"><dt class="text-xs text-muted-foreground">' . h($label) . '</dt><dd class="mt-1 truncate text-sm font-semibold">' . h($value) . '</dd><dd class="truncate text-xs text-muted-foreground">' . h($hint) . '</dd></div>';
        }
        return card($body . '</dl>', 'p-5');
    }

    private static function adminsTopCard(array $user): string
    {
        if (!can($user, 'stats.network_top')) {
            return '';
        }
        $money = can($user, 'stats.see_revenue');
        $now = now_tyumen();
        $leaders = Langame::crossBranchLeaders($now->format('Y-m-01'), $now->format('Y-m-t'));
        if (!$leaders) {
            return '';
        }
        $names = [];
        foreach (Db::all('SELECT id, name, "homeBranchId" FROM employees') as $e) {
            $names[(int) $e['id']] = $e;
        }
        $me = (int) ($user['employeeId'] ?? 0);
        $myPlace = 0;
        foreach ($leaders as $i => $l) {
            if ($l['eid'] === $me) {
                $myPlace = $i + 1;
            }
        }
        $bestShift = $leaders[0];
        $bestCheck = $leaders[0];
        $traveller = $leaders[0];
        foreach ($leaders as $l) {
            $bestShift = $l['best'] > $bestShift['best'] ? $l : $bestShift;
            $bestCheck = ($l['lines'] ? $l['revenue'] / $l['lines'] : 0) > ($bestCheck['lines'] ? $bestCheck['revenue'] / $bestCheck['lines'] : 0) ? $l : $bestCheck;
            $traveller = $l['branches'] > $traveller['branches'] ? $l : $traveller;
        }
        $name = static fn (array $l): string => short_name((string) ($names[$l['eid']]['name'] ?? 'Сотрудник'));
        $monthName = mb_strtolower(MONTH_NAMES[(int) $now->format('n') - 1]);
        $meta = 'Все филиалы · ' . $monthName . ($myPlace ? ' · вы на ' . $myPlace . '-м месте из ' . count($leaders) : '');
        $body = card_head('Топ администраторов', h($meta), '', 'trophy');
        $max = max(1, $leaders[0]['revenue']);
        $body .= '<ol class="mt-4 flex flex-col gap-2">';
        foreach (array_slice($leaders, 0, 10) as $i => $l) {
            $place = $i + 1;
            $isMe = $l['eid'] === $me;
            $perShift = $l['shifts'] ? intdiv($l['revenue'], $l['shifts'] * 100) * 100 : 0;
            $branch = self::branchName((int) ($names[$l['eid']]['homeBranchId'] ?? 0));
            $body .= '<li class="rounded-lg border px-3 py-2.5 ' . ($isMe ? 'border-primary/50 bg-primary/5' : 'border-border') . '">';
            $body .= '<div class="flex items-center gap-3"><span class="flex size-7 shrink-0 items-center justify-center rounded-md font-display text-xs font-semibold tabular-nums ' . ($place === 1 ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground') . '">' . $place . '</span>';
            $body .= '<div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold">' . h($name($l)) . ($isMe ? ' <span class="font-normal text-muted-foreground">· вы</span>' : '') . '</p>';
            $showSum = $money || $isMe;
            $body .= '<p class="truncate text-xs text-muted-foreground">' . h($branch . ' · ' . plural($l['shifts'], 'смена', 'смены', 'смен') . ($showSum ? ' · ' . kop_rub($perShift) . ' за смену' : '') . ($l['branches'] > 1 ? ' · ' . $l['branches'] . ' филиала' : '')) . '</p></div>';
            $body .= $showSum ? '<span class="shrink-0 font-display text-sm font-semibold tabular-nums">' . h(kop_rub($l['revenue'])) . '</span></div>' : '</div>';
            $body .= '<span class="mt-2 block h-1 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full ' . ($place === 1 ? 'bg-primary' : 'bg-foreground/35') . '" style="width:' . max(4, (int) round($l['revenue'] / $max * 100)) . '%"></span></span></li>';
        }
        $body .= '</ol>';
        $facts = [
            ['Рекорд смены', $name($bestShift), $money ? kop_rub($bestShift['best']) : ''],
            ['Самый крупный чек в среднем', $name($bestCheck), $money ? kop_rub($bestCheck['lines'] ? intdiv($bestCheck['revenue'], $bestCheck['lines']) : 0) : ''],
            ['Больше всех филиалов', $name($traveller), plural($traveller['branches'], 'филиал', 'филиала', 'филиалов')],
        ];
        $body .= '<dl class="mt-4 grid gap-3 border-t border-border pt-4 sm:grid-cols-3">';
        foreach ($facts as [$label, $who, $value]) {
            $body .= '<div class="min-w-0"><dt class="text-xs text-muted-foreground">' . h($label) . '</dt><dd class="mt-1 truncate text-sm font-semibold">' . h($who) . ' <span class="font-normal text-muted-foreground tabular-nums">' . h($value) . '</span></dd></div>';
        }
        $body .= '</dl>';
        return card($body, 'p-5');
    }

    private static function clubsNowCard(array $live, array $soon, array $user): string
    {
        $html = card_head('Сейчас в клубах', '', '', 'building');
        $html .= '<div class="mt-4 flex flex-col gap-5 divide-border">' . self::clubBranchRows($live, $soon, false, $user) . '</div>';
        return card($html, 'p-5');
    }

    private static function clubsNowHero(array $live, array $soon, array $user): string
    {
        $html = '<section class="cut hero-panel relative flex flex-col gap-6 p-5 sm:p-7" style="--cut:26px" aria-labelledby="clubs-now">';
        $html .= Course::settings()['newYear']
            ? newyear_art('club')
            : '<img src="' . h(asset('img/club/hall-wide-sm.webp')) . '" alt="" class="pointer-events-none absolute inset-y-0 right-0 hidden h-full w-1/2 object-cover opacity-25 [mask-image:linear-gradient(to_left,black,transparent)] sm:block" aria-hidden="true">';
        $html .= '<div class="relative flex flex-wrap items-center justify-between gap-3"><p id="clubs-now" class="flex items-center gap-2 text-[11px] font-semibold tracking-[0.18em] text-sidebar-muted uppercase"><span class="cut-mark" aria-hidden="true"></span>Сейчас в клубах</p>';
        $html .= '<span class="inline-flex items-center gap-2 rounded-[5px] bg-sidebar-accent px-2 py-0.5 text-xs leading-5 font-semibold"><span class="live-dot" aria-hidden="true"></span>' . h(plural(count($live), 'человек', 'человека', 'человек')) . ' на смене</span></div>';
        $html .= '<div class="relative grid gap-6 sm:grid-cols-2">' . self::clubBranchRows($live, $soon, true, $user) . '</div>';
        $html .= '<a href="' . h(url_to('/schedule')) . '" class="relative inline-flex w-fit items-center gap-1.5 text-sm font-semibold hover:text-brand">График смен ' . icon('arrow', 'size-4') . '</a>';
        return $html . '</section>';
    }

    private static function latestNewsCard(array $user): string
    {
        $rows = Db::all(
            'SELECT a.id, a.title, a.body, a."isPinned", a."createdAt", a."createdByName",
                    (SELECT 1 FROM announcement_reads r WHERE r."announcementId" = a.id AND r."userId" = ?) AS read_me
             FROM announcements a WHERE a."isDraft" = 0 ORDER BY a."isPinned" DESC, a."createdAt" DESC LIMIT 3',
            [$user['id']],
        );
        $html = card_head('Объявления', '', '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/news')) . '">Все ' . icon('arrow', 'size-3.5') . '</a>', 'megaphone');
        if (!$rows) {
            $html .= '<p class="mt-4 text-sm text-muted-foreground">Пока ничего не публиковали.</p>';
            return card($html, 'p-5');
        }
        $html .= '<ul class="mt-4 flex flex-col divide-y divide-border">';
        foreach ($rows as $r) {
            $unread = empty($r['read_me']);
            $html .= '<li class="py-3 first:pt-0 last:pb-0"><a class="group flex flex-col gap-1" href="' . h(url_to('/news', ['open' => (int) $r['id']])) . '">';
            $html .= '<span class="flex items-start gap-2"><span class="min-w-0 flex-1 text-sm font-semibold leading-snug text-pretty group-hover:underline">' . h($r['title']) . '</span>';
            if ($unread) {
                $html .= chip('Новое', 'brand');
            } elseif (boolish($r['isPinned'])) {
                $html .= '<span class="text-muted-foreground" title="Закреплено">' . icon('pushpin', 'size-3.5') . '</span>';
            }
            $html .= '</span><span class="text-xs text-muted-foreground">' . h(format_dt((string) $r['createdAt'], false)) . ' · ' . h((string) $r['createdByName']) . '</span></a></li>';
        }
        return card($html . '</ul>', 'p-5');
    }

    private static function liveTasksOverview(array $user, array $live): string
    {
        $scope = perm_branches($user, 'tasks.assign');
        $people = array_values(array_filter($live, static fn ($p) => $scope === null || in_array((int) $p['branchId'], $scope, true) || in_array((int) $p['homeBranchId'], $scope, true)));
        if (!$people) {
            return '';
        }
        $html = '<section class="flex flex-col gap-4">' . section_title('Задания на текущих сменах', '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/manage/tasks')) . '">' . icon('plus', 'size-4') . 'Назначить</a>');
        $html .= '<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">';
        foreach ($people as $p) {
            $tasks = self::staffTasksFor((int) $p['employeeId'], $p['date']);
            $done = count(array_filter($tasks, static fn ($t) => !empty($t['done_at'])));
            $type = (string) $p['shiftType'];
            $inner = '<div class="flex items-start justify-between gap-3"><div class="flex min-w-0 flex-col"><p class="truncate font-semibold">' . h($p['employeeName']) . '</p><p class="text-xs text-muted-foreground">' . h($p['branchName']) . ' · ' . h(SHIFT_LABELS[$type] ?? $type) . '</p></div>';
            $inner .= $tasks ? chip($done . '/' . count($tasks), $done === count($tasks) ? 'ink' : 'neutral', 'check') : '';
            $inner .= '</div>';
            if (!$tasks) {
                $inner .= '<p class="mt-3 text-sm text-muted-foreground">Заданий нет. <a class="font-medium text-primary-ink hover:underline" href="' . h(url_to('/manage/tasks', ['employeeId' => (int) $p['employeeId'], 'from' => $p['date']]) . '#assign') . '">Назначить</a></p>';
            } else {
                $inner .= '<ul class="mt-3 flex flex-col gap-1.5">';
                foreach ($tasks as $t) {
                    $ok = !empty($t['done_at']);
                    $inner .= '<li class="flex items-start gap-2 text-sm"><span class="mt-0.5 ' . ($ok ? 'text-foreground' : 'text-muted-foreground') . '">' . icon($ok ? 'check-circle' : 'clock', 'size-4') . '</span><span class="min-w-0 ' . ($ok ? 'text-muted-foreground line-through' : '') . '">' . h($t['title']) . '</span></li>';
                }
                $inner .= '</ul>';
            }
            $html .= card($inner, 'p-4', 'article');
        }
        return $html . '</div></section>';
    }

    /* ---------------- График ---------------- */

    private static function pageSchedule(array $user): void
    {
        if (!self::branches()) {
            self::layout($user, empty_state('building', 'Филиалы ещё не добавлены'), ['title' => 'График смен']);
            return;
        }
        $seeAll = can($user, 'schedule.view_all');
        $allowed = $seeAll ? self::branches() : self::visibleBranches($user);
        $slug = self::getStr('branch', 64);
        $foreign = false;
        foreach (self::branches() as $b) {
            $foreign = $foreign || ($slug !== '' && $b['slug'] === $slug && !in_array($b, $allowed, true));
        }
        $branch = $foreign ? null : self::pickBranch($user, $allowed);
        if (!$branch) {
            http_response_code(403);
            $back = $allowed ? '<a class="' . btn_class('outline') . '" href="' . h(url_to('/schedule')) . '">Мой филиал</a>' : '';
            self::layout($user, empty_state('lock', 'График этого филиала недоступен', 'Доступны графики только ваших филиалов. Филиалы и право «Видеть графики всех филиалов» выдаёт владелец в разделе «Доступы и права».', $back), ['title' => 'График смен']);
            return;
        }
        $now = now_tyumen();
        $today = date_key($now);
        $ym = self::getStr('m', 7);
        if (preg_match('/^(\d{4})-(\d{2})$/', $ym, $mm) && (int) $mm[2] >= 1 && (int) $mm[2] <= 12) {
            $year = (int) $mm[1];
            $month = (int) $mm[2] - 1;
        } else {
            $year = (int) $now->format('Y');
            $month = (int) $now->format('n') - 1;
        }
        $last = days_in_month($year, $month);
        $from = to_date_key($year, $month, 1);
        $to = to_date_key($year, $month, $last);
        $canEdit = can($user, 'schedule.edit', $branch['id']);
        $meId = (int) ($user['employeeId'] ?? 0);
        $monthParam = sprintf('%04d-%02d', $year, $month + 1);
        $isCurrentMonth = $monthParam === $now->format('Y-m');
        $first = new DateTimeImmutable($from);
        $prevM = $first->modify('-1 month')->format('Y-m');
        $nextM = $first->modify('+1 month')->format('Y-m');

        $shifts = Db::all(
            'SELECT s.id, s.date, s."employeeId", s."shiftType", s."branchId", e.name AS "employeeName", e."homeBranchId", hb.name AS "homeBranchName"
             FROM shifts s
             JOIN employees e ON e.id = s."employeeId"
             JOIN branches hb ON hb.id = e."homeBranchId"
             WHERE s."branchId" = ? AND s.date >= ? AND s.date <= ?
             ORDER BY s.date, CASE s."shiftType" WHEN \'day\' THEN 0 WHEN \'full\' THEN 1 ELSE 2 END, e."sortOrder"',
            [$branch['id'], $from, $to],
        );
        $away = Db::all(
            'SELECT s.id, s.date, s."employeeId", s."shiftType", s."branchId", e.name AS "employeeName", b.name AS "atBranchName"
             FROM shifts s
             JOIN employees e ON e.id = s."employeeId"
             JOIN branches b ON b.id = s."branchId"
             WHERE e."homeBranchId" = ? AND s."branchId" != ? AND s.date >= ? AND s.date <= ?
             ORDER BY s.date',
            [$branch['id'], $branch['id'], $from, $to],
        );
        if (!$seeAll) {
            $away = array_values(array_filter($away, static fn ($s) => (int) $s['employeeId'] === $meId));
        }
        $byDate = [];
        for ($d = 1; $d <= $last; $d++) {
            $byDate[to_date_key($year, $month, $d)] = ['day' => $d, 'shifts' => [], 'away' => []];
        }
        // Свои для филиала — сотрудники с карточкой в нём и те, кому владелец выдал этот филиал вторым.
        $filterEmps = [];
        foreach (self::employees(true) as $e) {
            if (self::worksAt($e, $branch['id'])) {
                $filterEmps[(int) $e['id']] = (string) $e['name'];
            }
        }
        foreach ($shifts as $s) {
            $k = substr((string) $s['date'], 0, 10);
            if (!isset($byDate[$k])) {
                continue;
            }
            $s['guestFromBranchName'] = self::worksAt(['id' => $s['employeeId'], 'homeBranchId' => $s['homeBranchId']], $branch['id']) ? null : $s['homeBranchName'];
            $byDate[$k]['shifts'][] = $s;
            $filterEmps[(int) $s['employeeId']] ??= (string) $s['employeeName'];
        }
        foreach ($away as $a) {
            $k = substr((string) $a['date'], 0, 10);
            if (isset($byDate[$k])) {
                $a['awayAtBranchName'] = $a['atBranchName'];
                $byDate[$k]['away'][] = $a;
            }
        }

        $half = [];
        foreach (self::employees() as $e) {
            $half[(int) $e['id']] = ['name' => (string) $e['name'], 'first' => 0, 'second' => 0, 'month' => 0];
        }
        $summaryScope = $seeAll ? '' : ' AND ("branchId" = ? OR "employeeId" = ?)';
        $summaryParams = $seeAll ? [$from, $to] : [$from, $to, $branch['id'], $meId];
        foreach (Db::all('SELECT "employeeId", date, "shiftType" FROM shifts WHERE date >= ? AND date <= ?' . $summaryScope, $summaryParams) as $r) {
            $id = (int) $r['employeeId'];
            if (!isset($half[$id])) {
                continue;
            }
            $w = shift_weight((string) $r['shiftType']);
            $half[$id][(int) substr((string) $r['date'], 8, 2) <= 15 ? 'first' : 'second'] += $w;
            $half[$id]['month'] += $w;
        }
        // Ночная смена после полуночи всё ещё идёт, хотя её дата уже «вчера» — такие смены и их день не гасим.
        $liveIds = [];
        foreach (self::liveShifts() as $ls) {
            $liveIds[(int) $ls['id']] = true;
        }

        $switch = self::branchSwitch('/schedule', $branch, $allowed, ['m' => $monthParam]);
        $dayStart = branch_day_start($branch['id']);
        $html = page_head('График смен', '', $switch);

        $monthHref = static fn (?string $m): string => h(url_to('/schedule', ['branch' => $branch['slug'], 'm' => $m]));
        $html .= '<div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3"><div class="flex items-center gap-1.5">';
        $html .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . $monthHref($prevM) . '" aria-label="Предыдущий месяц">' . icon('chevronleft') . '</a>';
        $html .= '<h2 class="min-w-40 px-1 text-center font-display text-lg font-semibold tracking-tight">' . h(format_month_title($year, $month)) . '</h2>';
        $html .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . $monthHref($nextM) . '" aria-label="Следующий месяц">' . icon('chevronright') . '</a>';
        if (!$isCurrentMonth) {
            $html .= '<a class="' . btn_class('quiet', 'sm') . '" href="' . $monthHref(null) . '#today">Сегодня</a>';
        }
        $html .= '</div><div class="flex flex-wrap items-center gap-2">' . self::scheduleViewSwitch();
        if ($canEdit) {
            $html .= '<button type="button" class="' . btn_class('primary', 'sm') . '" data-paint-toggle aria-expanded="false" aria-controls="paint-panel">' . icon('pencil', 'size-4') . 'Быстрое заполнение</button>';
            $html .= '<button type="button" class="' . btn_class('outline', 'sm') . '" data-dialog-open="copy-week">' . icon('copy', 'size-4') . 'Повторить неделю</button>';
        }
        $html .= '</div></div>';
        if ($canEdit) {
            $html .= self::paintPanel($branch, $filterEmps);
        }

        if (count($filterEmps) > 1) {
            $html .= '<div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 scrollbar-none sm:mx-0 sm:flex-wrap sm:px-0" role="group" aria-label="Показать смены сотрудника">';
            $html .= '<button type="button" class="filter-chip" data-emp-filter="" aria-pressed="true">Все</button>';
            if ($meId && isset($filterEmps[$meId])) {
                $html .= '<button type="button" class="filter-chip" data-emp-filter="' . $meId . '" aria-pressed="false">' . icon('user', 'size-3.5') . 'Мои смены</button>';
            }
            foreach ($filterEmps as $id => $n) {
                if ($id !== $meId) {
                    $html .= '<button type="button" class="filter-chip" data-emp-filter="' . $id . '" aria-pressed="false">' . h(short_name($n)) . '</button>';
                }
            }
            $html .= '</div>';
        }

        // Одна разметка на оба вида: календарь и список различаются только стилями (data-view), поэтому
        // фильтр, подсчёт периода, окно дня и «кисть» работают одинаково, а несохранённые правки переживают смену вида.
        $html .= '<section class="cal" aria-label="' . h('Смены · ' . format_month_title($year, $month) . ' · ' . $branch['name']) . '" data-month-grid data-view="month" data-year="' . $year . '" data-month="' . $month . '" data-last="' . $last . '" data-can-edit="' . ($canEdit ? '1' : '0') . '">';
        $html .= '<script>' . SCHEDULE_VIEW_BOOT . '</script>';
        $html .= '<div class="cal-head cal-head-list" aria-hidden="true"><div>Дата</div><div>' . icon('sun') . 'День <span>' . h(shift_hint('day', $branch['id'])) . '</span></div><div>' . icon('moon') . 'Ночь <span>' . h(shift_hint('night', $branch['id'])) . '</span></div></div>';
        $html .= '<div class="cal-head cal-head-month" aria-hidden="true">';
        foreach (WEEKDAY_SHORT as $i => $weekdayName) {
            $html .= '<div' . ($i >= 5 ? ' class="is-weekend"' : '') . '>' . h($weekdayName) . '</div>';
        }
        $html .= '</div>';
        // Неделя календаря начинается с понедельника: пустые ячейки до первого числа и после последнего достраивают её.
        $leadPads = weekday_from_date_key($from);
        $pad = '<div class="cal-pad" aria-hidden="true"></div>';
        $html .= '<div class="cal-grid">' . str_repeat($pad, $leadPads);
        foreach ($byDate as $key => $cell) {
            $wd = weekday_from_date_key($key);
            $isToday = $key === $today;
            $hasDay = $hasNight = $hasLive = false;
            $items = '';
            // Ячейка — кнопка, её содержимое скринридер не читает: имена и смены дублируются в подписи.
            $spoken = [];
            foreach ($cell['shifts'] as $s) {
                $type = (string) $s['shiftType'];
                $live = isset($liveIds[(int) $s['id']]);
                $hasLive = $hasLive || $live;
                $hasDay = $hasDay || $type !== 'night';
                $hasNight = $hasNight || $type !== 'day';
                $items .= self::calItem($s, $meId, $live);
                $spoken[] = mb_strtolower(SHIFT_LABELS[$type] ?? 'смена') . ' — ' . $s['employeeName'] . ($live ? ', идёт сейчас' : '');
            }
            foreach ($cell['away'] as $a) {
                $live = isset($liveIds[(int) $a['id']]);
                $hasLive = $hasLive || $live;
                $items .= self::calItem($a, $meId, $live, (string) $a['atBranchName']);
                $spoken[] = $a['employeeName'] . ' — в филиале ' . $a['atBranchName'] . ($live ? ', идёт сейчас' : '');
            }
            foreach (['day' => $hasDay, 'night' => $hasNight] as $slot => $taken) {
                $items .= '<span class="cal-slot' . ($taken ? ' hidden' : '') . '" data-slot-empty="' . $slot . '" aria-hidden="true">' . icon(SHIFT_ICONS[$slot], 'cal-chip-icon') . '—</span>';
            }
            $cls = 'cal-day' . ($hasLive ? ' has-live' : '') . ($wd >= 5 ? ' is-weekend' : '');
            if ($isToday) {
                $cls .= ' is-today';
            } elseif ($key < $today && !$hasLive) {
                $cls .= ' is-past';
            }
            $label = format_date_human($key) . ($isToday ? ', сегодня' : '') . ': ' . ($spoken ? implode('; ', $spoken) : 'смен нет');
            // id="today" — цель кнопки «Сегодня»: ссылка #today работает и без скрипта.
            $html .= '<div class="' . $cls . '"' . ($isToday ? ' id="today"' : '') . ' role="button" tabindex="0" data-date-key="' . h($key) . '" aria-label="' . h($label) . '">';
            $html .= '<div class="cal-date"><span class="cal-num">' . $cell['day'] . '</span><span class="cal-wd">' . h(mb_strtolower(WEEKDAY_SHORT[$wd])) . '</span></div>';
            $html .= '<div class="cal-shifts">' . $items . '</div></div>';
        }
        $html .= str_repeat($pad, (7 - ($leadPads + $last) % 7) % 7) . '</div>';

        $html .= '<div class="cal-foot"><ul class="cal-legend" aria-label="Обозначения">';
        foreach (['day' => 'День', 'night' => 'Ночь', 'full' => 'Сутки'] as $tone => $lab) {
            $html .= '<li class="cal-legend-item"><span class="cal-key" data-tone="' . $tone . '" aria-hidden="true"></span>' . $lab . '</li>';
        }
        $html .= '<li class="cal-legend-item cal-legend-wide">' . icon('arrow-up-right', 'size-3.5') . 'Вышел в другом филиале</li>';
        $html .= '<li class="cal-legend-item cal-legend-wide"><span class="inline-flex text-brand"><span class="live-dot" aria-hidden="true"></span></span>Идёт сейчас</li></ul>';
        // В текущем месяце кнопка прокручивает к сегодняшнему дню, в другом — открывает текущий месяц на нём же.
        $todayHref = $isCurrentMonth ? '#today' : $monthHref(null) . '#today';
        $html .= '<a class="' . btn_class('ink', 'sm') . '" href="' . $todayHref . '" data-today-jump>' . icon('locate', 'size-4') . 'Сегодня<span class="cal-today-date">' . h(format_day_month($today)) . '</span></a></div></section>';

        $sumIds = array_values(array_filter(array_keys($filterEmps), static fn ($id) => isset($half[$id])));
        if ($sumIds) {
            $num = static fn (int $n): string => $n > 0 ? (string) $n : '<span class="text-muted-foreground">—</span>';
            $thc = 'px-3 py-2 text-right text-xs font-semibold tracking-wide whitespace-nowrap uppercase sm:px-4';
            $tdc = 'px-3 py-2.5 text-right tabular-nums sm:px-4';
            $total = ['first' => 0, 'second' => 0, 'month' => 0];
            $html .= '<section class="overflow-hidden rounded-xl border border-border bg-card shadow-xs" aria-labelledby="sum-title" data-summary>';
            $html .= '<div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3 sm:px-5"><div class="flex min-w-0 flex-col gap-0.5"><h3 id="sum-title" class="text-sm font-semibold">Смены за ' . h(mb_strtolower(MONTH_NAMES[$month])) . '</h3>';
            $html .= '<p class="text-sm leading-relaxed text-pretty text-muted-foreground">Сутки — две смены. ' . ($seeAll ? 'Учтены выходы во всех филиалах.' : 'Учтены смены этого филиала и ваши подмены.') . '</p></div>';
            $html .= '<p class="hidden text-xs text-muted-foreground lg:block" data-range-hint>Протяните мышью по дням, чтобы посчитать смены за период</p>';
            $html .= '<div class="flex items-center gap-2 text-sm" data-range-info hidden><span class="text-muted-foreground">Период</span><span class="font-semibold" data-range-label></span><button type="button" class="' . btn_class('outline', 'xs') . '" data-range-clear>Сбросить</button></div></div>';
            $html .= '<div class="overflow-x-auto border-t border-border"><table class="w-full text-sm"><thead class="bg-muted/50 text-muted-foreground"><tr><th scope="col" class="px-4 py-2 text-left text-xs font-semibold tracking-wide uppercase sm:px-5">Сотрудник</th>';
            $html .= '<th scope="col" class="' . $thc . '">1–15</th><th scope="col" class="' . $thc . '">16–' . $last . '</th><th scope="col" class="' . $thc . '">Месяц</th><th scope="col" class="' . $thc . ' text-primary-ink" data-range-col hidden>Период</th></tr></thead><tbody>';
            foreach ($sumIds as $id) {
                $r = $half[$id];
                foreach ($total as $k => $v) {
                    $total[$k] = $v + (int) $r[$k];
                }
                $html .= '<tr class="sum-row border-t border-border' . ($id === $meId ? ' is-mine' : '') . '" data-sum-emp="' . $id . '"><th scope="row" class="px-4 py-2.5 text-left font-medium sm:px-5"><span class="flex items-center gap-2"><span class="min-w-0 break-words">' . h((string) $r['name']) . '</span>' . ($id === $meId ? chip('вы', 'red') : '') . '</span></th>';
                $html .= '<td class="' . $tdc . '">' . $num((int) $r['first']) . '</td><td class="' . $tdc . '">' . $num((int) $r['second']) . '</td><td class="' . $tdc . ' font-semibold">' . $num((int) $r['month']) . '</td>';
                $html .= '<td class="' . $tdc . ' font-semibold text-primary-ink" data-range-cell hidden>0</td></tr>';
            }
            $html .= '</tbody><tfoot><tr class="border-t border-border bg-muted/40 font-semibold"><th scope="row" class="px-4 py-2.5 text-left sm:px-5">Всего</th>';
            $html .= '<td class="' . $tdc . '">' . $num($total['first']) . '</td><td class="' . $tdc . '">' . $num($total['second']) . '</td><td class="' . $tdc . '">' . $num($total['month']) . '</td>';
            $html .= '<td class="' . $tdc . ' text-primary-ink" data-range-total hidden>0</td></tr></tfoot></table></div></section>';
        }

        $body = '<div class="flex flex-col gap-2" data-existing></div>';
        if ($canEdit) {
            $opts = ['0' => '— никого —'] + self::employeeOptions(null, $branch['id']);
            $body .= form_open('save_day', 'class="flex flex-col gap-4 border-t border-border pt-4" data-day-form') . hidden('branchId', $branch['id']) . '<input type="hidden" name="date" value="" data-dialog-date>';
            $body .= '<p class="text-sm font-semibold">Поставить на этот день · ' . h($branch['name']) . '</p>';
            $body .= '<div class="grid gap-3 sm:grid-cols-2">';
            // ui_field() экранирует подпись, поэтому подпись с иконкой собираем здесь.
            $dayText = sprintf('Днём · %02d–%02d', $dayStart, $dayStart + 12);
            $nightText = sprintf('Ночью · %02d–%02d', $dayStart + 12, $dayStart);
            foreach ([['day', 'sun', 'text-day-strong', $dayText, 'dayEmployeeId'], ['night', 'moon', 'text-night-strong', $nightText, 'nightEmployeeId']] as [$slot, $ic, $tone, $text, $name]) {
                $body .= '<label class="flex min-w-0 flex-col gap-1.5"><span class="inline-flex items-center gap-1.5 text-sm font-medium text-foreground">' . icon($ic, 'size-4 ' . $tone) . h($text) . '</span>' . ui_select($name, $opts, '0', 'data-day-slot="' . $slot . '"') . '</label>';
            }
            $body .= '</div><p class="text-xs leading-relaxed text-muted-foreground">Один и тот же сотрудник днём и ночью — встанет на сутки. Сотрудник другого филиала встанет как подмена.</p>';
            $body .= '<div class="flex flex-wrap justify-end gap-2"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Отмена</button><button class="' . btn_class() . '">' . icon('check', 'size-4') . 'Сохранить день</button></div></form>';
        }
        $html .= dialog_html('day-dialog', 'Смены дня', $body, 'max-w-md', 'data-day-dialog');

        if ($canEdit) {
            $weekStart = $isCurrentMonth ? monday_key($now) : monday_key($first);
            $cw = form_open('copy_week', 'class="flex flex-col gap-4"') . hidden('branchId', $branch['id']);
            $cw .= '<p class="text-sm leading-relaxed text-muted-foreground">Смены филиала «' . h($branch['name']) . '» за выбранную неделю встанут на другую неделю в те же дни. Если сотрудник в этот день уже в графике, его смена пропускается.</p>';
            $cw .= '<div class="grid gap-3 sm:grid-cols-2">' . ui_field('Откуда — любой день недели', '<input type="date" name="from" class="' . field_class() . '" value="' . h(add_days($weekStart, -7)) . '" required>');
            $cw .= ui_field('Куда — любой день недели', '<input type="date" name="to" class="' . field_class() . '" value="' . h($weekStart) . '" required>') . '</div>';
            $cw .= '<div class="flex flex-wrap justify-end gap-2"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Отмена</button><button class="' . btn_class() . '">' . icon('copy', 'size-4') . 'Скопировать</button></div></form>';
            $html .= dialog_html('copy-week', 'Повторить неделю', $cw, 'max-w-lg');
        }
        $html .= self::presenceCard($user, $first, (int) $branch['id']);
        self::layout($user, $html, ['title' => 'График смен', 'width' => 'wide', 'scripts' => ['schedule']]);
    }

    /**
     * Присутствие управляющего в клубе. Живёт отдельно от графика, поэтому сетка не меняется.
     * Видят только сам управляющий (свои отметки) и владелец (все отметки).
     */
    private static function presenceCard(array $user, DateTimeImmutable $first, int $branchId): string
    {
        $role = (string) ($user['role'] ?? '');
        $isOwner = !empty($user['isOwner']) || $role === 'owner';
        $eid = (int) ($user['employeeId'] ?? 0);
        $isManager = $role === 'manager' && $eid > 0;
        if (!$isOwner && !$isManager) {
            return '';
        }
        $from = $first->format('Y-m-01');
        $to = $first->modify('first day of next month')->format('Y-m-d');
        $sql = 'SELECT p.*, e.name AS "employeeName", b.name AS "branchName" FROM presence_logs p LEFT JOIN employees e ON e.id = p."employeeId" LEFT JOIN branches b ON b.id = p."branchId" WHERE p.date >= ? AND p.date < ?';
        $args = [$from, $to];
        if (!$isOwner) {
            $sql .= ' AND p."employeeId" = ?';
            $args[] = $eid;
        }
        $rows = Db::all($sql . ' ORDER BY p.date DESC, p."startTime" DESC', $args);
        $minutes = 0;
        foreach ($rows as $r) {
            $minutes += presence_minutes((string) $r['startTime'], (string) $r['endTime']);
        }
        $meta = $rows ? plural(count($rows), 'отметка', 'отметки', 'отметок') . ' · ' . presence_hours_label($minutes) . ' в клубе' : 'Отметки видите только вы' . ($isOwner ? ' и управляющие — свои' : ' и владелец');
        $body = card_head('Присутствие в клубе', h($meta), '', 'briefcase');
        $body .= '<p class="mt-2 text-sm leading-relaxed text-muted-foreground text-pretty">Каждая отметка считается сменой и попадает в зарплату по ставке. Сотрудники эти отметки не видят — сетка графика остаётся прежней.</p>';
        if ($isManager) {
            $today = date_key(now_tyumen());
            $branchOpts = self::branchOptions($user);
            $form = form_open('save_presence', 'class="mt-5 flex flex-col gap-4" id="presence"');
            $form .= '<div class="grid gap-3 sm:grid-cols-4">';
            $form .= ui_field('Дата', '<input type="date" name="date" class="' . field_class() . '" value="' . h($today) . '" max="' . h(add_days($today, 1)) . '" required>');
            $form .= ui_field('С', '<input type="time" name="startTime" class="' . field_class() . '" value="12:00" required>');
            $form .= ui_field('До', '<input type="time" name="endTime" class="' . field_class() . '" value="18:00" required>');
            $form .= ui_field('Филиал', ui_select('branchId', $branchOpts, (string) $branchId, 'required')) . '</div>';
            $form .= ui_field('Чем занимался', '<textarea name="activity" class="' . textarea_class('min-h-20') . '" maxlength="2000" required placeholder="Например: приёмка товара для бара, собеседование, настройка ПК в VIP"></textarea>');
            $form .= '<div class="flex justify-end"><button class="' . btn_class() . '">' . icon('check', 'size-4') . 'Отметить присутствие</button></div></form>';
            $body .= $form;
        }
        if ($rows) {
            $body .= '<ul class="mt-5 divide-y divide-border border-t border-border">';
            foreach ($rows as $r) {
                $mins = presence_minutes((string) $r['startTime'], (string) $r['endTime']);
                $who = $isOwner ? h((string) $r['employeeName']) . ' · ' : '';
                $body .= '<li class="flex items-start justify-between gap-4 py-3"><div class="min-w-0"><p class="text-sm font-semibold">' . $who . h(format_day_month((string) $r['date']) . ' · ' . $r['startTime'] . '–' . $r['endTime']) . ' <span class="font-normal text-muted-foreground">' . h('(' . presence_hours_label($mins) . ', ' . ($r['branchName'] ?? 'клуб') . ')') . '</span></p>';
                $body .= '<p class="mt-1 text-sm leading-relaxed text-muted-foreground text-pretty break-words">' . h((string) $r['activity']) . '</p></div>';
                if ($isOwner || (int) $r['employeeId'] === $eid) {
                    $body .= form_open('delete_presence', 'class="shrink-0" ' . confirm_attr('Удалить отметку присутствия?')) . hidden('id', (int) $r['id']) . '<button class="' . btn_class('danger-ghost', 'xs') . '" aria-label="Удалить отметку">' . icon('trash', 'size-3.5') . '</button></form>';
                }
                $body .= '</li>';
            }
            $body .= '</ul>';
        }
        return '<section class="mt-8 scroll-mt-24" aria-label="Присутствие управляющего">' . card($body, 'p-5') . '</section>';
    }

    /**
     * Переключатель вида графика. Сервер всегда отдаёт календарь; если сотрудник раньше выбрал список,
     * SCHEDULE_VIEW_BOOT переключает вид и нажатую кнопку ещё до первой отрисовки.
     */
    private static function scheduleViewSwitch(): string
    {
        $html = '<div class="view-switch" role="group" aria-label="Вид графика">';
        foreach (['month' => ['calendar', 'Календарь'], 'list' => ['list', 'Список']] as $view => [$iconName, $label]) {
            $html .= '<button type="button" class="view-switch-btn" data-view-set="' . $view . '" aria-pressed="' . ($view === 'month' ? 'true' : 'false') . '" title="' . h($label) . '">' . icon($iconName, 'size-4') . '<span class="sr-only xl:not-sr-only">' . h($label) . '</span></button>';
        }
        return $html . '</div>';
    }

    /** Смена в ячейке календаря; data-атрибуты читает schedule.js (фильтр, подсчёт периода, окно дня). */
    private static function calItem(array $s, int $meId, bool $live, ?string $awayAt = null): string
    {
        $type = (string) $s['shiftType'];
        // Часы смены берутся из филиала, где она стоит: у подмены в другом филиале они могут отличаться.
        $hint = (SHIFT_LABELS[$type] ?? $type) . ' · ' . shift_hint($type, isset($s['branchId']) ? (int) $s['branchId'] : null);
        $attrs = ' data-emp="' . (int) $s['employeeId'] . '" data-emp-name="' . h((string) $s['employeeName']) . '" data-shift-id="' . (int) $s['id'] . '" data-weight="' . shift_weight($type) . '" data-type="' . h($type) . '" data-hint="' . h($hint) . '"';
        if ($awayAt !== null) {
            $attrs .= ' data-away="' . h($awayAt) . '"';
        }
        return '<div class="cal-item"' . $attrs . ($live ? ' data-live' : '') . '>' . shift_chip($s, ['me' => $meId, 'live' => $live]) . '</div>';
    }

    /** Панель «кисти»: выбрал сотрудника и смену — тыкаешь по дням, всё сохраняется одной кнопкой. */
    private static function paintPanel(array $branch, array $emps): string
    {
        // На телефоне и планшете сверху закреплена шапка высотой 3.5rem — панель встаёт под неё, а не за неё.
        $html = '<section id="paint-panel" class="sticky top-16 z-20 flex flex-col gap-3 rounded-xl border border-primary/40 bg-card p-4 shadow-lg lg:top-2" data-paint-panel hidden aria-label="Быстрое заполнение графика">';
        $html .= '<div class="flex flex-wrap items-start justify-between gap-2"><div class="flex flex-col gap-0.5"><h3 class="text-sm font-semibold">Быстрое заполнение</h3>';
        $html .= '<p class="text-xs leading-relaxed text-pretty text-muted-foreground">Выберите сотрудника и смену, затем нажимайте на дни. Повторное нажатие убирает смену. Сохраняется всё разом.</p></div>';
        $html .= '<button type="button" class="' . btn_class('quiet', 'icon-sm') . '" data-paint-cancel aria-label="Закрыть без сохранения">' . icon('x', 'size-4') . '</button></div>';

        $html .= '<div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 scrollbar-none sm:mx-0 sm:flex-wrap sm:px-0" role="group" aria-label="Сотрудник">';
        $first = true;
        foreach ($emps as $id => $name) {
            $html .= '<button type="button" class="filter-chip paint-emp" data-paint-emp="' . (int) $id . '" data-name="' . h($name) . '" data-initials="' . h(initials($name)) . '" aria-pressed="' . ($first ? 'true' : 'false') . '">' . h(short_name($name)) . '</button>';
            $first = false;
        }
        $others = self::employeeOptions(null, $branch['id']);
        unset($others[$branch['name']]);
        if ($others) {
            $html .= '<select class="' . h(select_class('h-8 max-w-44 shrink-0 text-xs sm:text-xs')) . '" data-paint-other aria-label="Сотрудник другого филиала"><option value="">Другой филиал…</option>';
            foreach ($others as $group => $list) {
                if (!is_array($list)) {
                    continue;
                }
                $html .= '<optgroup label="' . h((string) $group) . '">';
                foreach ($list as $v => $l) {
                    $html .= '<option value="' . (int) $v . '" data-name="' . h((string) $l) . '" data-initials="' . h(initials((string) $l)) . '">' . h((string) $l) . '</option>';
                }
                $html .= '</optgroup>';
            }
            $html .= '</select>';
        }
        $html .= '</div>';

        $html .= '<div class="flex flex-wrap items-center justify-between gap-3"><div class="flex gap-2" role="group" aria-label="Смена">';
        foreach (SHIFT_TYPES as $i => $t) {
            $html .= '<button type="button" class="filter-chip paint-type" data-paint-type="' . $t . '" data-hint="' . h(SHIFT_LABELS[$t] . ' · ' . shift_hint($t, $branch['id'])) . '" aria-pressed="' . ($i === 0 ? 'true' : 'false') . '">' . icon(SHIFT_ICONS[$t], 'size-3.5') . h(SHIFT_LABELS[$t]) . '</button>';
        }
        $html .= '</div>' . form_open('save_shifts_bulk', 'class="flex items-center gap-3" data-paint-form') . hidden('branchId', $branch['id']) . '<input type="hidden" name="ops" value="" data-paint-ops>';
        $html .= '<span class="text-xs text-muted-foreground tabular-nums" data-paint-count aria-live="polite">Изменений нет</span>';
        $html .= '<button class="' . btn_class('primary', 'sm') . '" data-paint-save disabled>' . icon('check', 'size-4') . 'Сохранить</button></form></div>';
        return $html . '</section>';
    }

    /* ---------------- Чистка ---------------- */

    private static function pageCleaning(array $user): void
    {
        $visible = self::visibleBranches($user);
        $branch = self::pickBranch($user, $visible);
        if (!$branch) {
            self::layout($user, empty_state('building', 'Нет доступных филиалов', self::branches() ? 'За вашей учётной записью не закреплён филиал. Попросите владельца выдать его в разделе «Доступы и права».' : ''), ['title' => 'Чистка клавиатур']);
            return;
        }
        $now = now_tyumen();
        $thisWeek = monday_key($now);
        $w = self::getStr('w', 10);
        $week = is_date_key($w) ? monday_key(date_from_key($w)) : $thisWeek;
        $pc = (int) $branch['pcCount'];
        if ($pc < 1) {
            $action = $user['isOwner'] ? '<a class="' . btn_class('outline') . '" href="' . h(url_to('/manage/langame')) . '">Настроить филиал</a>' : '';
            self::layout($user, page_head('Чистка клавиатур', '', self::branchSwitch('/cleaning', $branch, $visible)) . empty_state('keyboard', 'Укажите количество ПК', 'Для филиала «' . $branch['name'] . '» количество компьютеров пока не задано. Владелец может указать его в настройках Langame.', $action), ['title' => 'Чистка клавиатур']);
            return;
        }
        $canAssign = can($user, 'cleaning.assign', $branch['id']);
        $meId = (int) ($user['employeeId'] ?? 0);
        $done = [];
        foreach (Db::all('SELECT * FROM keyboard_cleanings WHERE "branchId" = ? AND "weekStart" = ?', [$branch['id'], $week]) as $m) {
            $done[(int) $m['pcNumber']] = $m;
        }
        $bySeat = [];
        foreach (Db::all('SELECT a.*, e.name AS "employeeName" FROM cleaning_seat_assignments a JOIN employees e ON e.id = a."employeeId" WHERE a."branchId" = ? AND a."weekStart" = ?', [$branch['id'], $week]) as $a) {
            $bySeat[(int) $a['pcNumber']] = $a;
        }
        $doneCount = count(array_filter(array_keys($done), static fn ($n) => $n <= $pc));
        $myTotal = 0;
        $myDone = 0;
        foreach ($bySeat as $n => $a) {
            if ((int) $a['employeeId'] === $meId) {
                $myTotal++;
                if (isset($done[$n])) {
                    $myDone++;
                }
            }
        }

        $html = page_head('Чистка клавиатур', 'Еженедельная чистка рабочих мест', self::branchSwitch('/cleaning', $branch, $visible, ['w' => $week]));
        $html .= '<div class="flex flex-wrap items-center justify-between gap-3"><div class="flex items-center gap-2">';
        $html .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/cleaning', ['branch' => $branch['slug'], 'w' => add_days($week, -7)])) . '" aria-label="Предыдущая неделя">' . icon('chevronleft') . '</a>';
        $html .= '<h2 class="min-w-44 text-center font-display text-lg font-semibold tracking-tight">' . h(week_label($week)) . '</h2>';
        $html .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/cleaning', ['branch' => $branch['slug'], 'w' => add_days($week, 7)])) . '" aria-label="Следующая неделя">' . icon('chevronright') . '</a>';
        if ($week !== $thisWeek) {
            $html .= '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/cleaning', ['branch' => $branch['slug']])) . '">Эта неделя</a>';
        }
        $html .= '</div>';
        $html .= '<div class="flex min-w-56 flex-1 flex-col gap-1.5 sm:max-w-xs"><div class="flex justify-between text-xs"><span class="font-semibold">Почищено ' . $doneCount . ' из ' . $pc . '</span><span class="text-muted-foreground tabular-nums">' . (int) round($doneCount / $pc * 100) . '%</span></div>' . progress_bar((int) round($doneCount / $pc * 100), 'Прогресс чистки') . '</div></div>';

        if ($myTotal > 0) {
            $html .= '<p class="flex items-center gap-2 rounded-md border border-primary/30 bg-primary/5 px-3 py-2 text-sm">' . icon('keyboard', 'size-4 text-primary') . 'За вами ' . h(plural($myTotal, 'место', 'места', 'мест')) . ' на этой неделе, готово ' . $myDone . '.</p>';
        }
        if ($canAssign) {
            $html .= '<section class="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 shadow-xs sm:flex-row sm:items-end">';
            $html .= ui_field('Распределить места', ui_select('', ['' => 'Не распределять — только отметки'] + self::employeeOptions(null, $branch['id']), null, 'data-assign-emp aria-describedby="assign-hint"'), '', 'flex-1 sm:max-w-sm');
            $html .= '<p id="assign-hint" class="flex-1 text-xs leading-relaxed text-muted-foreground">Выберите сотрудника и нажимайте на места — они закрепятся за ним. Повторное нажатие снимает закрепление.</p>';
            $html .= '<button type="button" class="' . btn_class('outline', 'sm') . ' hidden" data-assign-done>' . icon('check', 'size-4') . 'Готово</button></section>';
        }

        $html .= '<div class="grid grid-cols-4 gap-2 sm:grid-cols-6 md:grid-cols-8 xl:grid-cols-10" data-keep-scroll>';
        for ($n = 1; $n <= $pc; $n++) {
            $mark = $done[$n] ?? null;
            $asg = $bySeat[$n] ?? null;
            $isMine = $asg && (int) $asg['employeeId'] === $meId;
            $canToggle = $canAssign || !$asg || $isMine;
            if ($mark) {
                $state = 'done';
                $sub = short_name((string) $mark['doneByName']);
            } elseif ($asg) {
                $state = $isMine ? 'mine' : 'taken';
                $sub = $isMine ? 'ваше' : short_name((string) $asg['employeeName']);
            } else {
                $state = 'free';
                $sub = 'свободно';
            }
            $title = 'Место ' . $n . ($mark ? ' — почищено: ' . $mark['doneByName'] . ', ' . format_dt((string) $mark['doneAt']) : '') . ($asg ? ' · закреплено за ' . $asg['employeeName'] : '');
            $inner = '<span class="keycap-num">' . $n . '</span><span class="keycap-sub">' . ($state === 'done' ? icon('check', 'size-3') : '') . h($sub) . '</span>';
            if ($canToggle) {
                $html .= form_open('toggle_clean', 'data-clean-form') . hidden('branchId', $branch['id']) . hidden('weekStart', $week) . hidden('pcNumber', $n) . '<input type="hidden" name="employeeId" value="" data-assign-id>';
                $html .= '<button class="keycap" data-state="' . $state . '" title="' . h($title) . '" aria-label="' . h($title) . '">' . $inner . '</button></form>';
            } else {
                $html .= '<div class="keycap" data-state="' . $state . '" aria-disabled="true" title="' . h($title) . '">' . $inner . '</div>';
            }
        }
        $html .= '</div>';
        $html .= '<div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-muted-foreground" aria-label="Обозначения"><span class="flex items-center gap-2"><span class="keycap-dot" data-state="done"></span>почищено</span><span class="flex items-center gap-2"><span class="keycap-dot" data-state="mine"></span>ваше место</span><span class="flex items-center gap-2"><span class="keycap-dot" data-state="taken"></span>закреплено за другим</span><span class="flex items-center gap-2"><span class="keycap-dot" data-state="free"></span>свободное — может отметить любой</span></div>';
        self::layout($user, $html, ['title' => 'Чистка клавиатур', 'width' => 'wide']);
    }

    /* ---------------- Задания на смены ---------------- */

    private static function pageTasks(array $user): void
    {
        $now = now_tyumen();
        $today = date_key($now);
        $w = self::getStr('w', 10);
        $week = is_date_key($w) ? monday_key(date_from_key($w)) : monday_key($now);
        $weekEnd = add_days($week, 6);
        $days = dates_between($week, $weekEnd, 7);
        $scope = perm_branches($user, 'tasks.assign');
        $preEmp = (int) ($_GET['employeeId'] ?? 0);
        $f = self::getStr('from', 10);
        $preFrom = is_date_key($f) ? $f : $today;
        $t = self::getStr('to', 10);
        $preTo = is_date_key($t) ? $t : $preFrom;

        $emps = array_values(array_filter(self::employees(true), static fn ($e) => self::worksInAny($e, $scope)));
        $shiftMap = [];
        foreach (Db::all('SELECT "employeeId", date, "shiftType", "branchId" FROM shifts WHERE date >= ? AND date <= ?', [$week, $weekEnd]) as $s) {
            $shiftMap[(int) $s['employeeId'] . '|' . substr((string) $s['date'], 0, 10)] = $s;
        }
        $taskMap = [];
        foreach (Db::all(
            'SELECT t.*, (SELECT d."completedAt" FROM staff_task_done d WHERE d."taskId" = t.id LIMIT 1) AS done_at
             FROM staff_tasks t WHERE t.date >= ? AND t.date <= ? ORDER BY t.id',
            [$week, $weekEnd],
        ) as $task) {
            $taskMap[(int) $task['employeeId'] . '|' . substr((string) $task['date'], 0, 10)][] = $task;
        }
        $templates = array_values(array_filter(
            Db::all('SELECT id, weekday, title, description FROM daily_tasks WHERE "isActive" = 1 ORDER BY weekday, "sortOrder", id'),
            static fn ($r) => mb_strlen(trim((string) $r['title'])) >= 4,
        ));

        $lead = 'Задание видно сотруднику на странице «Мой день» в его смену. Ночная смена после полуночи продолжает видеть задание своей даты.';
        if ($scope) {
            $lead .= ' Вы назначаете задания сотрудникам своих филиалов: ' . h(self::branchNames($scope)) . '.';
        }
        $html = page_head('Задания на смены', $lead);
        $html .= '<div class="grid items-start gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">';

        $form = form_open('assign_staff_task', 'id="assign" class="flex flex-col gap-4 scroll-mt-24"') . hidden('week', $week);
        $form .= '<h2 class="font-display text-base font-semibold">Назначить задание</h2>';
        $form .= '<fieldset class="flex flex-col gap-2"><legend class="mb-1 text-sm font-medium">Кому</legend>';
        $form .= '<label class="flex items-center gap-2 text-sm"><input type="radio" name="who" value="picked" class="' . check_class() . '" checked data-who> Выбранным сотрудникам</label>';
        $form .= '<div class="flex flex-wrap gap-1.5 pl-6" data-who-picked>';
        foreach ($emps as $e) {
            $chk = $preEmp === (int) $e['id'] ? ' checked' : '';
            $form .= '<label class="pill-check"><input type="checkbox" name="employees[]" value="' . (int) $e['id'] . '"' . $chk . ' class="peer sr-only"><span>' . h(short_name((string) $e['name'])) . '</span></label>';
        }
        $form .= '</div>';
        $form .= '<label class="flex items-start gap-2 text-sm"><input type="radio" name="who" value="day" class="mt-0.5 ' . check_class() . '" data-who> <span>Всем дневным по графику <span class="text-muted-foreground">(день и сутки)</span></span></label>';
        $form .= '<label class="flex items-start gap-2 text-sm"><input type="radio" name="who" value="night" class="mt-0.5 ' . check_class() . '" data-who> <span>Всем ночным по графику <span class="text-muted-foreground">(ночь и сутки)</span></span></label></fieldset>';
        $form .= '<div class="grid grid-cols-2 gap-3">' . ui_field('С даты', '<input type="date" name="from" class="' . field_class() . '" value="' . h($preFrom) . '" required>') . ui_field('По дату', '<input type="date" name="to" class="' . field_class() . '" value="' . h($preTo) . '" required>') . '</div>';
        if ($templates) {
            $opts = ['' => 'Выбрать шаблон…'];
            foreach ($templates as $tpl) {
                $opts[(string) $tpl['id']] = WEEKDAY_SHORT[(int) $tpl['weekday'] % 7] . ' · ' . mb_substr((string) $tpl['title'], 0, 60);
            }
            $form .= ui_field('Шаблон из «Задания дня»', ui_select('', $opts, '', 'data-task-template'), 'Заполнит название и описание — их можно поправить.');
            $tplJson = [];
            foreach ($templates as $tpl) {
                $tplJson[(string) $tpl['id']] = ['title' => (string) $tpl['title'], 'description' => (string) $tpl['description']];
            }
            $form .= '<script type="application/json" data-task-templates>' . json_encode($tplJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        }
        $form .= ui_field('Задание', '<input name="title" class="' . field_class() . '" required maxlength="200" placeholder="Что сделать на смене" data-task-title>');
        $form .= ui_field('Подробности', '<textarea name="description" class="' . textarea_class('min-h-20') . '" maxlength="2000" placeholder="Необязательно" data-task-desc></textarea>');
        $form .= '<button class="' . btn_class() . '">' . icon('send', 'size-4') . 'Назначить</button></form>';
        $html .= card($form, 'p-5 xl:sticky xl:top-8');

        $nav = '<div class="flex flex-wrap items-center justify-between gap-3"><div class="flex items-center gap-2">';
        $nav .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/manage/tasks', ['w' => add_days($week, -7)])) . '" aria-label="Предыдущая неделя">' . icon('chevronleft') . '</a>';
        $nav .= '<h2 class="min-w-44 text-center font-display text-base font-semibold">' . h(week_label($week)) . '</h2>';
        $nav .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/manage/tasks', ['w' => add_days($week, 7)])) . '" aria-label="Следующая неделя">' . icon('chevronright') . '</a></div>';
        $nav .= '<p class="text-xs text-muted-foreground">Нажмите «+» в ячейке, чтобы заполнить форму на этот день</p></div>';

        $offDays = [];
        foreach (Db::all('SELECT * FROM task_off_days WHERE date >= ? AND date <= ?', [$week, $weekEnd]) as $o) {
            $offDays[substr((string) $o['date'], 0, 10)] = $o;
        }
        $dayCount = [];
        foreach ($taskMap as $k => $list) {
            $dayCount[substr($k, strpos($k, '|') + 1)] = ($dayCount[substr($k, strpos($k, '|') + 1)] ?? 0) + count($list);
        }
        $strip = '<ul class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7" aria-label="Задания по дням недели">';
        foreach ($days as $d) {
            $off = $offDays[$d] ?? null;
            $n = $dayCount[$d] ?? 0;
            $isToday = $d === $today;
            $strip .= '<li class="flex flex-col gap-2 rounded-lg border p-3 ' . ($isToday ? 'border-primary/50 bg-primary/5' : 'border-border bg-card') . '">';
            $strip .= '<p class="flex items-baseline justify-between gap-2 text-xs font-semibold uppercase text-muted-foreground">' . h(WEEKDAY_SHORT[weekday_from_date_key($d)]) . '<span class="font-display text-sm text-foreground tabular-nums">' . (int) substr($d, 8, 2) . '</span></p>';
            if ($off) {
                $strip .= '<p class="text-sm font-medium">Без заданий</p>';
                $strip .= form_open('clear_task_off_day') . hidden('id', (int) $off['id']) . hidden('week', $week) . '<button class="text-xs font-medium text-muted-foreground underline-offset-2 hover:text-foreground hover:underline">Вернуть задания</button></form>';
            } else {
                $strip .= '<p class="text-sm ' . ($n ? 'font-medium' : 'text-muted-foreground') . '">' . h($n ? plural($n, 'задание', 'задания', 'заданий') : 'Пока пусто') . '</p>';
                $strip .= form_open('set_task_off_day') . hidden('date', $d) . hidden('week', $week) . '<button class="text-xs font-medium text-muted-foreground underline-offset-2 hover:text-foreground hover:underline">' . ($isToday ? 'Сегодня без заданий' : 'Без заданий') . '</button></form>';
            }
            $strip .= '</li>';
        }
        $strip .= '</ul>';
        $nav .= $strip;

        $table = '<div class="hidden overflow-x-auto rounded-lg border border-border bg-card shadow-xs md:block"><table class="w-full min-w-[48rem] text-sm"><thead class="bg-muted/50"><tr><th scope="col" class="w-44 px-3 py-2.5 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase">Сотрудник</th>';
        foreach ($days as $d) {
            $isToday = $d === $today;
            $table .= '<th scope="col" class="px-2 py-2.5 text-center text-xs font-semibold uppercase ' . ($isToday ? 'text-primary-ink' : 'text-muted-foreground') . '">' . h(WEEKDAY_SHORT[weekday_from_date_key($d)]) . '<span class="block font-display text-sm text-foreground tabular-nums">' . (int) substr($d, 8, 2) . '</span></th>';
        }
        $table .= '</tr></thead><tbody>';
        $cards = '<div class="flex flex-col gap-3 md:hidden">';
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            $table .= '<tr class="border-t border-border align-top"><th scope="row" class="px-3 py-3 text-left font-semibold"><span class="block truncate">' . h(short_name((string) $e['name'])) . '</span><span class="text-xs font-normal text-muted-foreground">' . h(self::branchName((int) $e['homeBranchId'])) . '</span></th>';
            $cards .= '<article class="rounded-lg border border-border bg-card p-4 shadow-xs"><h3 class="font-semibold">' . h($e['name']) . ' <span class="font-normal text-muted-foreground">· ' . h(self::branchName((int) $e['homeBranchId'])) . '</span></h3><div class="mt-3 flex flex-col divide-y divide-border">';
            foreach ($days as $d) {
                $key = $eid . '|' . $d;
                $shift = $shiftMap[$key] ?? null;
                $items = $taskMap[$key] ?? [];
                $href = url_to('/manage/tasks', ['w' => $week, 'employeeId' => $eid, 'from' => $d, 'to' => $d]) . '#assign';
                $cell = '';
                if ($shift) {
                    $type = (string) $shift['shiftType'];
                    $cell .= '<span class="mb-1.5 inline-flex items-center gap-1 rounded-[4px] px-1.5 py-px text-[10px] font-bold uppercase ' . shift_tone_class($type) . '">' . icon(SHIFT_ICONS[$type], 'size-3') . h(SHIFT_LABELS[$type]) . '</span>';
                }
                foreach ($items as $task) {
                    $ok = !empty($task['done_at']);
                    $cell .= '<div class="mb-1 flex items-start gap-1 rounded-md border border-border bg-background px-1.5 py-1 text-xs leading-snug">';
                    $cell .= '<span class="mt-px shrink-0 ' . ($ok ? 'text-foreground' : 'text-muted-foreground') . '" title="' . ($ok ? 'Выполнено' : 'Не выполнено') . '">' . icon($ok ? 'check-circle' : 'clock', 'size-3.5') . '</span>';
                    $cell .= '<span class="min-w-0 flex-1 break-words ' . ($ok ? 'text-muted-foreground line-through' : '') . '">' . h($task['title']) . '</span>';
                    $cell .= form_open('delete_staff_task', 'class="shrink-0" ' . confirm_attr('Снять задание «' . $task['title'] . '»?')) . hidden('id', (int) $task['id']) . hidden('week', $week) . '<button class="text-muted-foreground hover:text-primary-ink" aria-label="Снять задание">' . icon('x', 'size-3.5') . '</button></form></div>';
                }
                $cell .= '<a class="inline-flex items-center gap-0.5 rounded-sm text-[11px] font-medium text-muted-foreground hover:text-foreground" href="' . h($href) . '" data-fill-task data-emp="' . $eid . '" data-date="' . h($d) . '">' . icon('plus', 'size-3') . '<span class="sr-only">Назначить на ' . h(format_day_month($d)) . '</span></a>';
                $table .= '<td class="border-l border-border px-1.5 py-2 ' . ($d === $today ? 'bg-primary/5' : '') . '">' . $cell . '</td>';
                $cards .= '<div class="flex flex-col gap-1.5 py-2.5 first:pt-0 last:pb-0' . ($d === $today ? ' rounded-md bg-primary/5 px-2' : '') . '"><div class="flex items-center justify-between gap-2 text-xs font-semibold capitalize"><span>' . h(format_day_short($d)) . '</span>' . ($shift ? '' : '<span class="font-medium normal-case text-muted-foreground">нет в графике</span>') . '</div>' . $cell . '</div>';
            }
            $table .= '</tr>';
            $cards .= '</div></article>';
        }
        $table .= '</tbody></table></div>';
        $cards .= '</div>';
        if (!$emps) {
            $table = empty_state('users', 'Нет сотрудников', 'Добавьте сотрудников в разделе «Сотрудники».');
            $cards = '';
        }
        $html .= '<div class="flex min-w-0 flex-col gap-4">' . $nav . $table . $cards . '</div></div>';
        $html .= self::dailyTasksAdminCard($user);
        self::layout($user, $html, ['title' => 'Задания на смены', 'width' => 'wide']);
    }

    /** Редактор шаблонов «Задания дня» — видны на «Мой день» по дню недели. */
    private static function dailyTasksAdminCard(array $user): string
    {
        $rows = Db::all('SELECT * FROM daily_tasks ORDER BY weekday, "sortOrder", id');
        $branchOpts = ['' => 'Все филиалы'] + self::branchOptions($user);
        $editId = (int) self::getStr('daily', 12);
        $edit = $editId > 0 ? Db::one('SELECT * FROM daily_tasks WHERE id = ?', [$editId]) : null;
        $body = card_head('Шаблоны «Задания дня»', 'Заготовки для формы «Назначить»: сами сотрудникам не приходят. Выберите шаблон при назначении задания на смене.', '', 'sun');
        $form = form_open('save_daily_task', 'class="mt-4 flex flex-col gap-3"');
        if ($edit) {
            $form .= hidden('id', (int) $edit['id']);
        }
        $wdOpts = [];
        foreach (WEEKDAY_LONG as $i => $name) {
            $wdOpts[(string) $i] = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        }
        $form .= '<div class="grid gap-3 sm:grid-cols-2">' . ui_field('День недели', ui_select('weekday', $wdOpts, $edit ? (string) $edit['weekday'] : '0', 'required'));
        $form .= ui_field('Филиал', ui_select('branchId', $branchOpts, $edit && $edit['branchId'] !== null ? (string) $edit['branchId'] : '')) . '</div>';
        $form .= ui_field('Название', '<input name="title" class="' . field_class() . '" required maxlength="200" value="' . h((string) ($edit['title'] ?? '')) . '">');
        $form .= ui_field('Описание', '<textarea name="description" class="' . textarea_class('min-h-20') . '" maxlength="2000">' . h((string) ($edit['description'] ?? '')) . '</textarea>');
        $form .= '<div class="flex flex-wrap items-center gap-2">';
        $form .= '<button class="' . btn_class() . '">' . icon('save', 'size-4') . ($edit ? 'Сохранить шаблон' : 'Добавить шаблон') . '</button>';
        if ($edit) {
            $form .= '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/manage/tasks') . '#daily') . '">Отмена</a>';
        }
        $form .= '</div></form>';
        $body .= $form;
        if ($rows) {
            $body .= '<ul class="mt-5 divide-y divide-border border-t border-border">';
            foreach ($rows as $r) {
                $active = boolish($r['isActive']);
                $body .= '<li class="flex flex-wrap items-start justify-between gap-3 py-3">';
                $body .= '<div class="min-w-0"><p class="text-sm font-semibold' . ($active ? '' : ' text-muted-foreground line-through') . '">' . h(WEEKDAY_SHORT[(int) $r['weekday'] % 7]) . ' · ' . h((string) $r['title']) . '</p>';
                $body .= '<p class="mt-0.5 text-xs text-muted-foreground">' . h($r['branchId'] !== null ? self::branchName((int) $r['branchId']) : 'Все филиалы');
                if (trim((string) $r['description']) !== '') {
                    $body .= ' · ' . h(mb_substr(trim((string) $r['description']), 0, 80));
                }
                $body .= '</p></div><div class="flex shrink-0 gap-1.5">';
                $body .= '<a class="' . btn_class('outline', 'xs') . '" href="' . h(url_to('/manage/tasks', ['daily' => (int) $r['id']]) . '#daily') . '">Изменить</a>';
                $body .= form_open('delete_daily_task', 'class="inline" ' . confirm_attr('Удалить шаблон «' . $r['title'] . '»?')) . hidden('id', (int) $r['id']) . '<button class="' . btn_class('danger-ghost', 'xs') . '">' . icon('trash', 'size-3.5') . '</button></form>';
                $body .= '</div></li>';
            }
            $body .= '</ul>';
        } else {
            $body .= '<p class="mt-4 text-sm text-muted-foreground">Шаблонов пока нет — добавьте первое задание дня выше.</p>';
        }
        return '<div id="daily" class="mt-8 scroll-mt-24">' . card($body, 'p-5') . '</div>';
    }
}
