<?php
declare(strict_types=1);

trait PagesCash
{
    private static function pageCash(array $user): void
    {
        $enabled = Langame::enabledBranchIds();
        $visible = array_values(array_filter(
            self::visibleBranches($user),
            static function ($b) use ($user, $enabled): bool {
                return in_array($b['id'], $enabled, true) && can($user, 'page.cash', (int) $b['id']);
            },
        ));
        if (!$visible) {
            $action = $user['isOwner'] ? '<a class="' . btn_class() . '" href="' . h(url_to('/manage/langame')) . '">' . icon('link', 'size-4') . 'Подключить Langame</a>' : '';
            self::layout($user, page_head('Касса клуба') . empty_state('wallet', 'Нет филиалов', 'Либо Langame не подключён, либо право «Касса клуба» не выдано на ваши филиалы.', $action), ['title' => 'Касса клуба']);
            return;
        }
        $branch = self::pickBranch($user, $visible) ?? $visible[0];

        $month = self::monthFromQuery();
        $today = date_key(now_tyumen());
        $rawDay = self::getStr('d', 10);
        if (is_date_key($rawDay) && $rawDay >= $month['from'] && $rawDay <= $month['to'] && $rawDay <= $today) {
            $day = $rawDay;
        } elseif ($today >= $month['from'] && $today <= $month['to']) {
            $day = $today;
        } else {
            $day = $month['to'] < $today ? $month['to'] : $month['from'];
        }
        $bid = (int) $branch['id'];
        Langame::requestRefresh($bid);
        $report = Langame::registerReport($bid, $month['from'], $month['to']);
        $byDay = [];
        foreach ($report['shifts'] as $s) {
            $d = (string) $s['day'];
            if (!isset($byDay[$d])) {
                $byDay[$d] = ['nal' => 0, 'bez' => 0, 'shifts' => 0, 'open' => 0];
            }
            $byDay[$d]['nal'] += (int) $s['cashNal'];
            $byDay[$d]['bez'] += (int) $s['cashBeznal'];
            $byDay[$d]['shifts']++;
            if (!empty($s['open'])) {
                $byDay[$d]['open']++;
            }
        }
        $dayShifts = array_values(array_filter($report['shifts'], static fn ($s) => $s['day'] === $day));
        $dayNal = (int) ($byDay[$day]['nal'] ?? 0);
        $dayBez = (int) ($byDay[$day]['bez'] ?? 0);

        $people = $report['people'];
        $nal = 0;
        $bez = 0;
        foreach ($people as $p) {
            $nal += (int) $p['nal'];
            $bez += (int) $p['bez'];
        }

        $qBase = ['branch' => $branch['slug'], 'm' => $month['isCurrent'] ? null : $month['key']];
        $href = static function (array $extra) use ($qBase): string {
            return url_to('/cash', array_merge($qBase, $extra));
        };

        $actions = '<button type="button" class="' . btn_class('outline', 'sm') . '" data-langame-refresh data-force="1">' . icon('refresh', 'size-3.5') . 'Обновить из Langame</button>';
        $html = page_head(
            'Касса клуба',
            'Наличные и безнал только «' . h((string) $branch['name']) . '». Выберите день в сетке месяца. Обычным администраторам этот раздел не показывают — здесь цифры бизнеса, не продажи бара.',
            $actions,
            $branch['name'],
        );
        $html .= self::branchSwitch('/cash', $branch, $visible, ['m' => $month['isCurrent'] ? null : $month['key'], 'd' => $day !== $today ? $day : null]);

        $nav = '<div class="flex flex-wrap items-center gap-2">';
        $nav .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h($href(['m' => $month['prev']])) . '" aria-label="Предыдущий месяц">' . icon('chevronleft') . '</a>';
        $nav .= '<span class="min-w-36 text-center font-display text-lg font-semibold tracking-tight">' . h($month['title']) . '</span>';
        $nextDisabled = $month['key'] >= now_tyumen()->format('Y-m');
        $nav .= $nextDisabled
            ? '<span class="' . btn_class('outline', 'icon-sm') . ' pointer-events-none opacity-40" aria-hidden="true">' . icon('chevronright') . '</span>'
            : '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h($href(['m' => $month['next']])) . '" aria-label="Следующий месяц">' . icon('chevronright') . '</a>';
        if (!$month['isCurrent']) {
            $nav .= '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/cash', ['branch' => $branch['slug']])) . '">Этот месяц</a>';
        }
        $nav .= '</div>';
        $html .= $nav;
        $html .= '<p class="text-xs text-muted-foreground" data-langame-status hidden>Обновляем кассу из Langame…</p>';

        $html .= '<div class="grid gap-3 sm:grid-cols-3">';
        $html .= stat_tile('Нал за ' . format_day_month($day), h(money_rub($dayNal)), $day === $today ? 'Выбранный день · сегодня' : 'Выбранный день', '', 'wallet');
        $html .= stat_tile('Безнал за день', h(money_rub($dayBez)), '', '', 'coins');
        $html .= stat_tile('Нал за месяц', h(money_rub($nal)), 'Безнал месяца ' . money_rub($bez), '', 'calendar');
        $html .= '</div>';

        $html .= '<div class="grid items-start gap-6 lg:grid-cols-2">';
        $html .= self::cashMonthGrid($branch, $month, $day, $today, $byDay, $href);
        $html .= self::cashDayDetail($branch, $day, $today, $dayShifts);
        $html .= '</div>';

        $table = '<div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-muted/50 text-muted-foreground"><tr>';
        $table .= '<th class="px-4 py-2 text-left text-xs font-semibold tracking-wide uppercase">#</th>';
        $table .= '<th class="px-4 py-2 text-left text-xs font-semibold tracking-wide uppercase">Администратор</th>';
        $table .= '<th class="px-4 py-2 text-right text-xs font-semibold tracking-wide uppercase">Смен</th>';
        $table .= '<th class="px-4 py-2 text-right text-xs font-semibold tracking-wide uppercase">Наличные</th>';
        $table .= '<th class="px-4 py-2 text-right text-xs font-semibold tracking-wide uppercase">Безнал</th>';
        $table .= '</tr></thead><tbody>';
        if (!$people) {
            $table .= '<tr><td colspan="5" class="px-4 py-8 text-center text-sm text-muted-foreground">За этот месяц смен Langame ещё нет.</td></tr>';
        }
        foreach ($people as $i => $p) {
            $table .= '<tr class="border-t border-border">';
            $table .= '<td class="px-4 py-2.5 tabular-nums text-muted-foreground">' . ($i + 1) . '</td>';
            $table .= '<td class="px-4 py-2.5 font-medium">' . h(short_name((string) $p['name'])) . ((int) $p['open'] > 0 ? ' <span class="text-xs font-normal text-muted-foreground">· смена открыта</span>' : '') . '</td>';
            $table .= '<td class="px-4 py-2.5 text-right tabular-nums">' . (int) $p['shifts'] . '</td>';
            $table .= '<td class="px-4 py-2.5 text-right font-display font-semibold tabular-nums">' . h(money_rub((int) $p['nal'])) . '</td>';
            $table .= '<td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">' . h(money_rub((int) $p['bez'])) . '</td>';
            $table .= '</tr>';
        }
        $table .= '</tbody></table></div>';
        $html .= card(card_head('Итог по людям за месяц', h($month['title'] . ' · ' . $branch['name']), '', 'users') . $table, 'overflow-hidden');

        $html .= '<p class="text-xs leading-relaxed text-muted-foreground">Нал и безнал пишутся при закрытии смены в Langame Admin. Пока смена открыта, за день нал часто 0 — откройте день после закрытия. Продажи бара сотрудники смотрят во вкладке «Продажи бара», не здесь.</p>';

        self::layout($user, $html, [
            'title' => 'Касса клуба',
            'width' => 'wide',
            'langameBranches' => [$bid],
        ]);
    }

    /** @param array<string, array{nal: int, bez: int, shifts: int, open: int}> $byDay */
    private static function cashMonthGrid(array $branch, array $month, string $day, string $today, array $byDay, callable $href): string
    {
        $lastDay = (int) substr($month['to'], 8, 2);
        $lead = weekday_from_date_key($month['from']);
        $grid = 'display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:0.35rem';
        $body = card_head('Дни месяца', 'Семь колонок, нажмите день', '', 'calendar');
        $body .= '<div class="mt-4 text-center text-[10px] font-semibold tracking-wide text-muted-foreground uppercase" style="' . $grid . '">';
        foreach (WEEKDAY_SHORT as $i => $wd) {
            $body .= '<div' . ($i >= 5 ? ' class="text-primary-ink"' : '') . '>' . h($wd) . '</div>';
        }
        $body .= '</div><div class="mt-1" style="' . $grid . '">';
        for ($i = 0; $i < $lead; $i++) {
            $body .= '<div aria-hidden="true"></div>';
        }
        for ($d = 1; $d <= $lastDay; $d++) {
            $key = sprintf('%s-%02d', $month['key'], $d);
            $future = $key > $today;
            $stats = $byDay[$key] ?? null;
            $active = $key === $day;
            $cls = 'flex min-h-[3.4rem] flex-col rounded-md border px-1.5 py-1.5 text-left ';
            if ($future) {
                $cls .= 'pointer-events-none border-transparent opacity-35';
            } elseif ($active) {
                $cls .= 'border-primary bg-primary/10';
            } else {
                $cls .= 'border-border bg-card hover:bg-muted/40';
            }
            $inner = '<span class="text-xs font-semibold tabular-nums' . ($key === $today ? ' text-primary-ink' : '') . '">' . $d . '</span>';
            if ($stats && !$future && ((int) $stats['nal'] > 0 || (int) $stats['bez'] > 0)) {
                $inner .= '<span class="mt-auto font-display text-[11px] font-semibold leading-tight tabular-nums">' . h(money_rub((int) $stats['nal'])) . '</span>';
            } elseif ($stats && !$future && (int) $stats['open'] > 0) {
                $inner .= '<span class="mt-auto text-[10px] text-muted-foreground">открыта</span>';
            }
            if ($future) {
                $body .= '<div class="' . $cls . '">' . $inner . '</div>';
            } else {
                $body .= '<a class="' . $cls . '" href="' . h($href(['d' => $key === $today ? null : $key])) . '">' . $inner . '</a>';
            }
        }
        $body .= '</div>';
        return card($body, 'p-5');
    }

    /** @param list<array<string,mixed>> $shifts */
    private static function cashDayDetail(array $branch, string $day, string $today, array $shifts): string
    {
        $body = card_head(
            format_day_month($day) . ($day === $today ? ' · сегодня' : ''),
            'Смены Langame · ' . $branch['name'],
            '',
            'clock',
        );
        if (!$shifts) {
            $body .= '<p class="mt-4 text-sm text-muted-foreground">В этот день смен в Langame не было.</p>';
            return card($body, 'p-5');
        }
        $body .= '<table class="mt-4 w-full text-sm"><thead class="text-muted-foreground"><tr>';
        $body .= '<th class="pb-2 text-left text-xs font-semibold tracking-wide uppercase">Кто</th>';
        $body .= '<th class="pb-2 text-right text-xs font-semibold tracking-wide uppercase">Нал</th>';
        $body .= '<th class="pb-2 text-right text-xs font-semibold tracking-wide uppercase">Безнал</th>';
        $body .= '</tr></thead><tbody>';
        foreach ($shifts as $s) {
            $from = substr((string) $s['dateStart'], 11, 5);
            $to = $s['open'] ? 'сейчас' : substr((string) $s['dateStop'], 11, 5);
            $body .= '<tr class="border-t border-border">';
            $body .= '<td class="py-2.5 pr-3"><span class="font-medium">' . h(short_name((string) $s['staffName'])) . '</span>';
            $body .= '<span class="block text-xs text-muted-foreground tabular-nums">' . h($from) . '–' . h($to) . '</span></td>';
            if ($s['open']) {
                $body .= '<td class="py-2.5 text-right text-xs text-muted-foreground" colspan="2">после закрытия</td>';
            } else {
                $body .= '<td class="py-2.5 text-right font-display font-semibold tabular-nums">' . h(money_rub((int) $s['cashNal'])) . '</td>';
                $body .= '<td class="py-2.5 text-right tabular-nums text-muted-foreground">' . h(money_rub((int) $s['cashBeznal'])) . '</td>';
            }
            $body .= '</tr>';
        }
        return card($body . '</tbody></table>', 'p-5');
    }
}
