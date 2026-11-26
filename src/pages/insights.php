<?php
declare(strict_types=1);

trait PagesInsights
{
    private const INSIGHT_BLOCKS = ['insight.heatmap', 'insight.branches', 'insight.payments', 'insight.goods', 'insight.cancels', 'insight.shifts'];

    private static function wholeRub(int $kop): string
    {
        return kop_rub((int) round($kop / 100) * 100);
    }

    private static function pageInsights(array $user): void
    {
        $enabled = Langame::enabledBranchIds();
        $visible = array_values(array_filter(
            self::visibleBranches($user),
            static fn ($b): bool => in_array($b['id'], $enabled, true) && can($user, 'page.insights', (int) $b['id']),
        ));
        $title = 'Аналитика сети';
        if (!$visible) {
            self::layout($user, page_head($title) . empty_state('database', 'Нет данных', 'Langame не подключён, либо вкладка не выдана на ваши филиалы.'), ['title' => $title]);
            return;
        }
        $blocks = array_values(array_filter(self::INSIGHT_BLOCKS, static fn ($p) => can($user, $p)));
        if (!$blocks) {
            self::layout($user, page_head($title) . empty_state('lock', 'Пока пусто', 'Владелец ещё не включил вам ни одного блока аналитики.'), ['title' => $title]);
            return;
        }

        $month = self::monthFromQuery();
        $slug = self::getStr('branch', 64);
        $scope = null;
        foreach ($visible as $b) {
            if ($b['slug'] === $slug) {
                $scope = $b;
            }
        }
        $branchIds = $scope ? [(int) $scope['id']] : array_map(static fn ($b) => (int) $b['id'], $visible);
        $first = new DateTimeImmutable($month['from']);
        $prevFrom = $first->modify('-1 month')->format('Y-m-01');
        $prevTo = $first->modify('-1 day')->format('Y-m-d');

        $qBase = ['branch' => $scope['slug'] ?? null, 'm' => $month['isCurrent'] ? null : $month['key']];
        $items = [['href' => url_to('/insights', array_merge($qBase, ['branch' => null])), 'label' => 'Все филиалы', 'active' => $scope === null]];
        if (count($visible) > 1) {
            foreach ($visible as $b) {
                $items[] = ['href' => url_to('/insights', array_merge($qBase, ['branch' => $b['slug']])), 'label' => $b['name'], 'active' => $scope && $scope['id'] === $b['id']];
            }
        }
        $monthNav = '<div class="flex items-center gap-1">'
            . '<a class="' . btn_class('ghost', 'icon') . '" href="' . h(url_to('/insights', array_merge($qBase, ['m' => $month['prev']]))) . '" aria-label="Прошлый месяц">' . icon('chevronleft', 'size-4') . '</a>'
            . '<span class="min-w-32 text-center text-sm font-semibold">' . h($month['title']) . '</span>'
            . ($month['isCurrent'] ? '<span class="size-9" aria-hidden="true"></span>' : '<a class="' . btn_class('ghost', 'icon') . '" href="' . h(url_to('/insights', array_merge($qBase, ['m' => $month['next']]))) . '" aria-label="Следующий месяц">' . icon('chevronright', 'size-4') . '</a>')
            . '</div>';

        $html = page_head($title, 'Считается по продажам бара и сменам, которые уже подтянулись из Langame — открывается сразу, без ожидания API.', $monthNav);
        if (count($items) > 1) {
            $html .= '<div class="mb-6 overflow-x-auto">' . segmented($items, 'Филиал') . '</div>';
        }

        $names = [];
        foreach (Db::all('SELECT id, name FROM employees') as $e) {
            $names[(int) $e['id']] = short_name((string) $e['name']);
        }
        $who = static fn (int $eid): string => $eid > 0 ? ($names[$eid] ?? 'Сотрудник') : 'Без привязки';

        $totals = (in_array('insight.branches', $blocks, true) || in_array('insight.payments', $blocks, true))
            ? Insights::branchTotals($branchIds, $month['from'], $month['to']) : [];
        $prevTotals = in_array('insight.branches', $blocks, true) ? Insights::branchTotals($branchIds, $prevFrom, $prevTo) : [];

        $html .= '<div class="grid gap-6 lg:grid-cols-2">';
        if (in_array('insight.heatmap', $blocks, true)) {
            $html .= '<div class="lg:col-span-2">' . self::insightHeatmap(Insights::heatmap($branchIds, $month['from'], $month['to'])) . '</div>';
        }
        if (in_array('insight.branches', $blocks, true)) {
            $html .= self::insightBranches($totals, $prevTotals, $month['isCurrent']);
        }
        if (in_array('insight.payments', $blocks, true)) {
            $html .= self::insightPayments($totals);
        }
        if (in_array('insight.goods', $blocks, true)) {
            $html .= '<div class="lg:col-span-2">' . self::insightGoods(Insights::goodsMovers($branchIds, $month['from'], $month['to'], $prevFrom, $prevTo)) . '</div>';
        }
        if (in_array('insight.cancels', $blocks, true)) {
            $html .= self::insightCancels(Insights::cancelsByStaff($branchIds, $month['from'], $month['to']), $who);
        }
        if (in_array('insight.shifts', $blocks, true)) {
            $html .= self::insightShifts(Insights::shiftStats($branchIds, $month['from'], $month['to']), $who);
        }
        $html .= '</div>';
        if ($user['isOwner'] ?? false) {
            $html .= '<p class="mt-6 text-xs leading-relaxed text-muted-foreground">Каждый блок включается отдельно в <a class="font-medium underline underline-offset-2 hover:text-foreground" href="' . h(url_to('/manage/accounts')) . '">Доступах и правах</a> — группа «Аналитика».</p>';
        }
        self::layout($user, $html, ['title' => $title]);
    }

    private static function insightHeatmap(array $hm): string
    {
        $body = card_head('Когда бар зарабатывает', 'Средняя выручка за час по дням недели', '', 'timer');
        if (!$hm['grid']) {
            return card($body . empty_state('database', 'Продаж пока нет', '', '', 'mt-4'), 'p-5');
        }
        $hours = range(0, 23);
        $body .= '<div class="mt-4 overflow-x-auto"><table class="w-full min-w-[640px] border-separate" style="border-spacing:3px"><caption class="sr-only">Выручка по дням недели и часам</caption><thead><tr><th class="w-8" scope="col"><span class="sr-only">День</span></th>';
        foreach ($hours as $hr) {
            $body .= '<th scope="col" class="text-[10px] font-medium text-muted-foreground tabular-nums">' . ($hr % 3 === 0 ? sprintf('%02d', $hr) : '') . '</th>';
        }
        $body .= '</tr></thead><tbody>';
        foreach (WEEKDAY_SHORT as $wd => $label) {
            $body .= '<tr><th scope="row" class="pr-1 text-left text-xs font-medium ' . ($wd >= 5 ? 'text-primary' : 'text-muted-foreground') . '">' . h($label) . '</th>';
            foreach ($hours as $hr) {
                $v = (int) ($hm['grid'][$wd][$hr] ?? 0);
                $pct = $hm['max'] > 0 ? (int) round($v / $hm['max'] * 100) : 0;
                $style = $v > 0 ? 'background-color:color-mix(in oklab, var(--color-primary) ' . max(8, $pct) . '%, var(--color-muted))' : '';
                $body .= '<td class="h-6 rounded-[3px] ' . ($v > 0 ? '' : 'bg-muted/50') . '" style="' . $style . '" title="' . h($label . ' ' . sprintf('%02d:00', $hr) . ' · ' . self::wholeRub($v)) . '"><span class="sr-only">' . h(self::wholeRub($v)) . '</span></td>';
            }
            $body .= '</tr>';
        }
        $body .= '</tbody></table></div>';
        $facts = [];
        if ($hm['peak']) {
            $facts[] = ['Пик', WEEKDAY_SHORT[$hm['peak'][0]] . ', ' . sprintf('%02d:00', $hm['peak'][1]), self::wholeRub($hm['peak'][2]) . ' в час'];
        }
        if ($hm['quiet']) {
            $facts[] = ['Самый тихий час (10–23)', WEEKDAY_SHORT[$hm['quiet'][0]] . ', ' . sprintf('%02d:00', $hm['quiet'][1]), 'время для акции'];
        }
        if ($facts) {
            $body .= '<dl class="mt-4 grid gap-3 border-t border-border pt-4 sm:grid-cols-2">';
            foreach ($facts as [$l, $v, $hint]) {
                $body .= '<div><dt class="text-xs text-muted-foreground">' . h($l) . '</dt><dd class="mt-1 text-sm font-semibold">' . h($v) . ' <span class="font-normal text-muted-foreground">· ' . h($hint) . '</span></dd></div>';
            }
            $body .= '</dl>';
        }
        return card($body, 'p-5');
    }

    private static function insightBranches(array $totals, array $prev, bool $isCurrent): string
    {
        $body = card_head('Филиалы', $isCurrent ? 'Месяц к прошлому месяцу целиком' : 'К предыдущему месяцу', '', 'building');
        if (!$totals) {
            return card($body . empty_state('building', 'Нет продаж', '', '', 'mt-4'), 'p-5');
        }
        uasort($totals, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $max = max(1, max(array_column($totals, 'revenue')));
        $body .= '<ul class="mt-4 flex flex-col gap-4">';
        foreach ($totals as $bid => $t) {
            $p = (int) ($prev[$bid]['revenue'] ?? 0);
            $delta = $p > 0 ? (int) round(($t['revenue'] - $p) / $p * 100) : null;
            $avg = $t['lines'] ? intdiv($t['revenue'], $t['lines']) : 0;
            $perShift = $t['shifts'] ? intdiv($t['revenue'], $t['shifts']) : 0;
            $body .= '<li><div class="flex items-baseline justify-between gap-3"><span class="truncate text-sm font-semibold">' . h(self::branchName($bid)) . '</span>';
            $body .= '<span class="flex shrink-0 items-baseline gap-2"><span class="font-display text-sm font-semibold tabular-nums">' . h(self::wholeRub($t['revenue'])) . '</span>';
            if ($delta !== null) {
                $body .= '<span class="text-xs font-semibold tabular-nums ' . ($delta >= 0 ? 'text-success' : 'text-destructive') . '">' . ($delta >= 0 ? '+' : '') . $delta . '%</span>';
            }
            $body .= '</span></div>';
            $body .= '<span class="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full bg-primary" style="width:' . max(3, (int) round($t['revenue'] / $max * 100)) . '%"></span></span>';
            $body .= '<p class="mt-1.5 text-xs text-muted-foreground tabular-nums">' . h(self::wholeRub($perShift) . ' за смену · позиция в среднем ' . self::wholeRub($avg) . ' · ' . plural($t['qty'], 'товар', 'товара', 'товаров')) . '</p></li>';
        }
        return card($body . '</ul>', 'p-5');
    }

    private static function insightPayments(array $totals): string
    {
        $body = card_head('Нал и безнал', 'Как платят гости в баре', '', 'wallet');
        if (!$totals) {
            return card($body . empty_state('wallet', 'Нет продаж', '', '', 'mt-4'), 'p-5');
        }
        $allRev = array_sum(array_column($totals, 'revenue'));
        $allLess = array_sum(array_column($totals, 'cashless'));
        $share = $allRev ? (int) round($allLess / $allRev * 100) : 0;
        $body .= '<div class="mt-4 flex items-end gap-3"><span class="font-display text-4xl font-semibold tabular-nums">' . $share . '%</span><span class="pb-1 text-sm text-muted-foreground">оплат картой и QR по сети</span></div>';
        $body .= '<ul class="mt-5 flex flex-col gap-3">';
        foreach ($totals as $bid => $t) {
            $s = $t['revenue'] ? (int) round($t['cashless'] / $t['revenue'] * 100) : 0;
            $body .= '<li><div class="flex justify-between gap-3 text-sm"><span class="truncate font-medium">' . h(self::branchName($bid)) . '</span><span class="shrink-0 tabular-nums text-muted-foreground">' . $s . '% безнал · ' . (100 - $s) . '% нал</span></div>';
            $body .= '<span class="mt-1.5 flex h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true"><span class="block h-full bg-primary" style="width:' . $s . '%"></span><span class="block h-full bg-foreground/30" style="width:' . (100 - $s) . '%"></span></span></li>';
        }
        $body .= '</ul><p class="mt-4 flex items-center gap-4 text-xs text-muted-foreground"><span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-primary"></span>Безнал</span><span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-foreground/30"></span>Наличные</span></p>';
        return card($body, 'p-5');
    }

    private static function insightGoods(array $g): string
    {
        $body = card_head('Товары', 'Хиты месяца и что изменилось к прошлому', '', 'tags');
        if (!$g['top']) {
            return card($body . empty_state('tags', 'Нет продаж', '', '', 'mt-4'), 'p-5');
        }
        $list = static function (string $title, array $rows, string $tone, bool $showDelta): string {
            $h = '<div class="min-w-0"><p class="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">' . h($title) . '</p>';
            if (!$rows) {
                return $h . '<p class="mt-2 text-sm text-muted-foreground">Ничего заметного</p></div>';
            }
            $h .= '<ol class="mt-2 flex flex-col divide-y divide-border">';
            foreach ($rows as $r) {
                $right = $showDelta
                    ? '<span class="shrink-0 text-xs font-semibold tabular-nums ' . $tone . '">' . ($r['delta'] > 0 ? '+' : '') . $r['delta'] . ' шт</span>'
                    : '<span class="shrink-0 text-xs tabular-nums text-muted-foreground">' . h($r['qty'] . ' шт · ' . self::wholeRub($r['amount'])) . '</span>';
                $h .= '<li class="flex items-center justify-between gap-3 py-2"><span class="truncate text-sm">' . h($r['name']) . '</span>' . $right . '</li>';
            }
            return $h . '</ol></div>';
        };
        $body .= '<div class="mt-4 grid gap-6 md:grid-cols-3">';
        $body .= $list('Хиты по выручке', $g['top'], '', false);
        $body .= $list('Растут', $g['rising'], 'text-success', true);
        $body .= $list('Падают', $g['falling'], 'text-destructive', true);
        return card($body . '</div>', 'p-5');
    }

    private static function insightCancels(array $rows, callable $who): string
    {
        $body = card_head('Отмены чеков', 'Кто и сколько отменил за месяц', '', 'alert');
        $rows = array_values(array_filter($rows, static fn ($r) => $r['count'] > 0));
        if (!$rows) {
            return card($body . empty_state('check', 'Отмен не было', 'Ни одной отменённой продажи за период.', '', 'mt-4'), 'p-5');
        }
        $body .= '<ul class="mt-4 flex flex-col divide-y divide-border">';
        foreach (array_slice($rows, 0, 8) as $r) {
            $rate = $r['lines'] ? round($r['count'] / $r['lines'] * 100, 1) : 0;
            $body .= '<li class="flex items-center justify-between gap-3 py-2.5"><span class="min-w-0"><span class="block truncate text-sm font-semibold">' . h($who($r['eid'])) . '</span><span class="block text-xs text-muted-foreground tabular-nums">' . h(str_replace('.', ',', (string) $rate)) . '% от пробитых позиций</span></span>';
            $body .= '<span class="shrink-0 text-right"><span class="block text-sm font-semibold tabular-nums ' . ($rate >= 3 ? 'text-destructive' : '') . '">' . h(plural($r['count'], 'отмена', 'отмены', 'отмен')) . '</span><span class="block text-xs text-muted-foreground tabular-nums">' . h(self::wholeRub($r['amount'])) . '</span></span></li>';
        }
        return card($body . '</ul>', 'p-5');
    }

    private static function insightShifts(array $s, callable $who): string
    {
        $body = card_head('Смены в Langame', 'Длина, инкассации и что пишут при закрытии', '', 'clock');
        if (!$s['count']) {
            return card($body . empty_state('clock', 'Смен нет', '', '', 'mt-4'), 'p-5');
        }
        $hm = static fn (int $m): string => intdiv($m, 60) . ' ч ' . sprintf('%02d', $m % 60) . ' мин';
        $body .= '<dl class="mt-4 grid grid-cols-2 gap-3">';
        $tiles = [
            ['Смен', (string) $s['count']],
            ['В среднем', $hm($s['avgMinutes'])],
            ['Инкассаций', (string) $s['incassCount']],
            ['Сдано в инкассацию', money_rub($s['incass'])],
        ];
        foreach ($tiles as [$l, $v]) {
            $body .= '<div class="rounded-lg border border-border p-3"><dt class="text-xs text-muted-foreground">' . h($l) . '</dt><dd class="mt-1 font-display text-lg font-semibold tabular-nums">' . h($v) . '</dd></div>';
        }
        $body .= '</dl>';
        if ($s['longest']) {
            $l = $s['longest'];
            $body .= '<p class="mt-4 text-sm text-muted-foreground">Самая длинная смена — <span class="font-semibold text-foreground">' . h($who($l['eid'])) . '</span>, ' . h($hm($l['minutes']) . ' · ' . self::branchName($l['branchId']) . ' · ' . substr($l['start'], 0, 10)) . '</p>';
        }
        if ($s['notes']) {
            $body .= '<div class="mt-4 border-t border-border pt-4"><p class="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">Комментарии при закрытии</p><ul class="mt-2 flex flex-col gap-2">';
            foreach ($s['notes'] as $n) {
                $body .= '<li class="rounded-md bg-muted/50 px-3 py-2 text-sm leading-relaxed"><span class="text-pretty">' . h(mb_strimwidth($n['text'], 0, 220, '…')) . '</span><span class="mt-1 block text-xs text-muted-foreground">' . h($who($n['eid']) . ' · ' . self::branchName($n['branchId']) . ' · ' . substr($n['start'], 0, 10)) . '</span></li>';
            }
            $body .= '</ul></div>';
        }
        return card($body, 'p-5');
    }
}
