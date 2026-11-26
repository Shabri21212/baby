<?php
declare(strict_types=1);

require_once __DIR__ . '/bar-workspace.php';

trait PagesBar
{
    use BarWorkspace;
    /**
     * Филиалы для «Моих продаж»: с подключённым Langame, из тех, что сотрудник видит,
     * плюс те, где к нему привязана учётка Langame — свои продажи видны всегда.
     */
    private static function barBranches(array $user): array
    {
        $enabled = Langame::enabledBranchIds();
        if (!$enabled) {
            return [];
        }
        $linked = [];
        if ($user['employeeId'] !== null) {
            foreach (Db::all('SELECT DISTINCT "branchId" FROM langame_staff WHERE "employeeId" = ?', [(int) $user['employeeId']]) as $r) {
                $linked[] = (int) $r['branchId'];
            }
        }
        return array_values(array_filter(
            self::branches(),
            static fn (array $b): bool => in_array($b['id'], $enabled, true) && (sees_branch($user, $b['id']) || in_array($b['id'], $linked, true)),
        ));
    }

    private static function barBranch(array $user): ?array
    {
        $slug = (string) ($_GET['branch'] ?? '');
        $work = self::workBranchId($user);
        $pick = null;
        foreach (self::barBranches($user) as $b) {
            if ($slug !== '' && $b['slug'] === $slug) {
                return $b;
            }
            if ($pick === null || $work === $b['id']) {
                $pick = $b;
            }
        }
        return $pick;
    }

    private static function pageBar(array $user): void
    {
        $branch = self::barBranch($user);
        if (can($user, 'staff.manage') && self::getStr('view', 8) !== 'me') {
            // Аналитика — по филиалам, которые видит управляющий; подключение Langame для выбора не обязательно.
            $allowed = self::visibleBranches($user);
            $team = $branch && in_array($branch, $allowed, true) ? $branch : ($allowed[0] ?? null);
            foreach ($allowed as $candidate) {
                if ($candidate['slug'] === self::getStr('branch', 80)) {
                    $team = $candidate;
                }
            }
            if ($team) {
                Langame::maybeSync((int) $team['id']);
                self::pageBarTeam($user, $team, Langame::settings((int) $team['id']) ?? [], self::monthFromQuery());
                return;
            }
        }
        if (!$branch) {
            $html = page_head('Продажи бара');
            $action = $user['isOwner'] ? '<a class="' . btn_class() . '" href="' . h(url_to('/manage/langame')) . '">' . icon('link', 'size-4') . 'Подключить Langame</a>' : '';
            $html .= empty_state('coins', 'Langame пока не подключён', $user['isOwner'] ? 'Укажите токен в настройках филиала.' : 'Обратитесь к владельцу для подключения.', $action);
            self::layout($user, $html, ['title' => 'Продажи бара']);
            return;
        }

        Langame::maybeSync($branch['id']);
        $s = Langame::settings($branch['id']) ?? [];
        $month = self::monthFromQuery();
        $today = now_tyumen()->format('Y-m-d');
        $eid = $user['employeeId'] !== null ? (int) $user['employeeId'] : 0;
        $totals = Langame::totalsByEmployee($month['from'], $month['to'], $branch['id']);
        $todayTotals = $month['isCurrent'] ? Langame::totalsByEmployee($today, $today, $branch['id']) : [];
        $linked = $eid > 0 && (bool) Db::value('SELECT 1 FROM langame_staff WHERE "branchId" = ? AND "employeeId" = ?', [$branch['id'], $eid]);
        $mine = $totals[$eid] ?? ['revenue' => 0, 'qty' => 0, 'lines' => 0, 'earn' => 0, 'percentX10' => (int) ($s['barPercentX10'] ?? 0)];
        if (!isset($totals[$eid]) && $linked) {
            $own = Db::value('SELECT "percentX10" FROM langame_staff WHERE "branchId" = ? AND "employeeId" = ?', [$branch['id'], $eid]);
            if ($own !== null) {
                $mine['percentX10'] = (int) $own;
            }
        }
        $mineToday = $todayTotals[$eid] ?? null;
        $seeAll = can($user, 'staff.manage');

        $myBranches = self::barBranches($user);
        $branchTabs = '';
        if (count($myBranches) > 1) {
            $items = [];
            foreach ($myBranches as $b) {
                $items[] = ['href' => url_to('/bar', ['branch' => $b['slug'], 'm' => $month['key'] ?? null, 'view' => 'me']), 'label' => $b['name'], 'active' => $b['id'] === $branch['id']];
            }
            $branchTabs = segmented($items, 'Филиал');
        }

        if ($seeAll) {
            $branchTabs = self::barViewSwitch($branch, $month, 'me') . $branchTabs;
        }
        $html = page_head('Продажи бара', 'Продажи из базы · фон обновляет каждые ' . (Langame::AUTO_SYNC_SECONDS / 60) . ' мин', $branchTabs, $branch['name']);

        $hero = '<section class="relative isolate overflow-hidden hero-panel rounded-xl text-sidebar-foreground shadow-sm">';
        $hero .= '<img src="' . h(asset('img/club/lounge.webp')) . '" srcset="' . h(asset('img/club/lounge-sm.webp')) . ' 800w, ' . h(asset('img/club/lounge.webp')) . ' 1600w" sizes="(min-width: 1024px) 60vw, 100vw" alt="" aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 size-full object-cover opacity-35 [mask-image:linear-gradient(to_left,black_10%,transparent_85%)]">';
        $hero .= '<div class="flex flex-col gap-5 p-5 sm:p-7">';
        $hero .= '<div class="flex flex-wrap items-center justify-between gap-3"><p class="text-[11px] font-semibold tracking-[0.18em] text-sidebar-foreground/60 uppercase">Процент по продажам · ' . h($month['name']) . '</p>';
        $hero .= '<span class="inline-flex items-center gap-1.5 rounded-full border border-sidebar-foreground/15 px-2.5 py-1 text-xs font-medium tabular-nums">' . icon('percent', 'size-3.5') . h(pct_label($mine['percentX10'])) . ' с продаж</span></div>';
        if ($linked || $mine['revenue'] > 0) {
            $hero .= '<div class="flex flex-col gap-1"><p class="font-display text-5xl leading-none font-semibold tracking-tight tabular-nums sm:text-6xl">' . h(kop_rub($mine['earn'])) . '</p>';
            $hero .= '<p class="text-sm text-sidebar-foreground/70">с продаж на <span class="font-semibold text-sidebar-foreground tabular-nums">' . h(kop_rub($mine['revenue'])) . '</span> · ' . h(plural($mine['qty'], 'позиция', 'позиции', 'позиций')) . '</p></div>';
            if ($month['isCurrent']) {
                $hero .= '<div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-sidebar-foreground/10 pt-4 text-sm">';
                $hero .= '<span class="flex items-baseline gap-2"><span class="text-sidebar-foreground/60">Сегодня</span><span class="font-display text-lg font-semibold tabular-nums">' . h(kop_rub($mineToday['revenue'] ?? 0)) . '</span></span>';
                $hero .= '<span class="flex items-baseline gap-2"><span class="text-sidebar-foreground/60">ваши</span><span class="font-display text-lg font-semibold text-primary tabular-nums">+' . h(kop_rub($mineToday['earn'] ?? 0)) . '</span></span></div>';
            }
        } else {
            $hero .= '<div class="flex max-w-md flex-col gap-2"><p class="font-display text-2xl font-semibold text-balance">Учётка Langame ещё не привязана</p>';
            $hero .= '<p class="text-sm leading-relaxed text-sidebar-foreground/70 text-pretty">Обратитесь к владельцу для привязки учётной записи.</p></div>';
        }
        $hero .= '<p class="text-sm leading-relaxed text-sidebar-foreground/70">Процент копится весь месяц, с 1-го числа, и выплачивается вместе со второй половиной. <a class="font-semibold underline underline-offset-4" href="' . h(url_to('/salary', ['m' => $month['key']])) . '">Открыть расчёт зарплаты</a></p>';
        $hero .= '</div></section>';

        $nav = '<div class="flex items-center gap-1"><a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/bar', ['branch' => $branch['slug'], 'm' => $month['prev'], 'view' => 'me'])) . '" aria-label="Предыдущий месяц">' . icon('chevronleft') . '</a>';
        $nav .= '<span class="min-w-32 text-center font-display text-sm font-semibold">' . h($month['title']) . '</span>';
        $nav .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/bar', ['branch' => $branch['slug'], 'm' => $month['next'], 'view' => 'me'])) . '" aria-label="Следующий месяц">' . icon('chevronright') . '</a></div>';

        $sync = '<div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card px-4 py-3 text-sm shadow-xs">';
        $ok = boolish($s['lastSyncOk'] ?? 0);
        $sync .= '<span class="flex items-center gap-2 text-muted-foreground"><span class="size-2 rounded-full ' . ($ok ? 'bg-foreground/60' : 'bg-primary') . '" aria-hidden="true"></span>';
        $sync .= $ok ? 'Обновлено ' . h(format_dt((string) ($s['lastSyncAt'] ?? ''))) : 'Последнее обновление не прошло' . ($user['isOwner'] && !empty($s['lastError']) ? ': ' . h((string) $s['lastError']) : '');
        $sync .= '</span>' . form_open('sync_langame') . hidden('branchId', $branch['id']) . hidden('back', '/bar?' . http_build_query(['branch' => $branch['slug'], 'm' => $month['key'], 'view' => 'me']))
            . '<button class="' . btn_class('outline', 'sm') . '">' . icon('refresh', 'size-4') . 'Обновить</button></form></div>';

        $recent = $eid > 0 ? Langame::recentSales($eid, $month['from'], $month['to'], 15, (int) $branch['id']) : [];
        $top = $eid > 0 ? Langame::topGoods($eid, $month['from'], $month['to'], 5, (int) $branch['id']) : [];

        $list = card_head('Последние продажи', $recent ? 'Что вы пробили в этом месяце' : 'Пока ничего', '', 'file-text');
        if ($recent) {
            $list .= '<ul class="mt-4 flex flex-col divide-y divide-border">';
            foreach ($recent as $r) {
                $cancel = boolish($r['isCancelled']);
                $list .= '<li class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0' . ($cancel ? ' opacity-60' : '') . '">';
                $list .= '<span class="flex min-w-0 flex-col gap-0.5"><span class="truncate text-sm font-medium' . ($cancel ? ' line-through' : '') . '">' . h((string) ($r['goodsName'] ?? 'Товар')) . ((int) $r['qty'] > 1 ? ' <span class="text-muted-foreground">× ' . (int) $r['qty'] . '</span>' : '') . '</span>';
                $list .= '<span class="text-xs text-muted-foreground">' . h(format_dt(str_replace(' ', 'T', (string) $r['saleAt']))) . ($cancel ? ' · отменена' : (boolish($r['isCashless']) ? ' · безнал' : '')) . '</span></span>';
                $list .= '<span class="shrink-0 font-display text-sm font-semibold tabular-nums">' . h(kop_rub((int) $r['amountX100'])) . '</span></li>';
            }
            $list .= '</ul>';
        } else {
            $list .= '<p class="mt-3 text-sm leading-relaxed text-muted-foreground">Продажи появятся через пару минут после того, как вы пробьёте товар в админке Langame.</p>';
        }

        $topHtml = card_head('Чаще всего продаёте', '', '', 'star');
        if ($top) {
            $max = max(1, (int) $top[0]['amount']);
            $topHtml .= '<ol class="mt-4 flex flex-col gap-3">';
            foreach ($top as $t) {
                $w = max(4, (int) round(((int) $t['amount']) / $max * 100));
                $topHtml .= '<li class="flex flex-col gap-1.5"><div class="flex items-baseline justify-between gap-3 text-sm"><span class="truncate font-medium">' . h((string) $t['name']) . '</span><span class="shrink-0 tabular-nums text-muted-foreground">' . (int) $t['qty'] . ' шт · <span class="font-semibold text-foreground">' . h(kop_rub((int) $t['amount'])) . '</span></span></div>';
                $topHtml .= '<div class="h-1.5 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-primary" style="width:' . $w . '%"></div></div></li>';
            }
            $topHtml .= '</ol>';
        } else {
            $topHtml .= '<p class="mt-3 text-sm text-muted-foreground">Здесь будет топ ваших позиций за месяц.</p>';
        }

        $board = '';
        if ($totals) {
            uasort($totals, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
            $names = [];
            foreach (self::employees() as $e) {
                $names[(int) $e['id']] = (string) $e['name'];
            }
            $seePay = !empty($user['isOwner']);
            $board = card_head('Рейтинг за ' . $month['name'], $seePay ? 'Выручка бара и процент каждого' : 'Кто сколько продал в баре', '', 'trophy');
            $board .= '<ol class="mt-4 flex flex-col divide-y divide-border">';
            $place = 0;
            $max = max(1, (int) reset($totals)['revenue']);
            foreach ($totals as $id => $t) {
                $place++;
                $me = $id === $eid;
                $board .= '<li class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">';
                $board .= '<span class="flex size-7 shrink-0 items-center justify-center rounded-full font-display text-xs font-semibold tabular-nums ' . ($place === 1 ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground') . '">' . $place . '</span>';
                $board .= '<span class="flex min-w-0 flex-1 flex-col gap-1"><span class="flex items-baseline justify-between gap-3 text-sm"><span class="truncate ' . ($me ? 'font-semibold' : 'font-medium') . '">' . h(short_name($names[$id] ?? 'Сотрудник')) . ($me ? ' <span class="text-xs font-normal text-muted-foreground">— вы</span>' : '') . '</span>';
                $board .= '<span class="shrink-0 tabular-nums">' . h(kop_rub($t['revenue'])) . ($seePay || $me ? ' <span class="text-xs text-muted-foreground">· ' . h(kop_rub($t['earn'])) . '</span>' : '') . '</span></span>';
                $board .= '<span class="h-1 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full ' . ($me ? 'bg-primary' : 'bg-foreground/30') . '" style="width:' . max(3, (int) round($t['revenue'] / $max * 100)) . '%"></span></span></span></li>';
            }
            $board .= '</ol>';
        }

        $html .= '<div class="flex flex-col gap-6">' . $hero . $sync;
        $html .= '<section class="flex flex-col gap-4">' . section_title('Детали', $nav);
        $html .= '<div class="grid gap-3 grid-cols-2 lg:grid-cols-4">';
        $html .= stat_tile('Выручка', h(kop_rub($mine['revenue'])), 'без отменённых', '', 'coins');
        $html .= stat_tile('Позиций', h(num_fmt($mine['qty'])), '', '', 'list');
        $html .= stat_tile('Средняя позиция', h(kop_rub($mine['qty'] > 0 ? (int) round($mine['revenue'] / $mine['qty']) : 0)), '', '', 'tags');
        $html .= stat_tile('Процент по продажам', h(kop_rub($mine['earn'])), 'За весь месяц; сумма к выплате — в «Моя зарплата»', url_to('/salary', ['m' => $month['key']]), 'wallet');
        $html .= '</div></section>';
        $html .= '<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]"><div class="flex min-w-0 flex-col gap-6">' . card($list, 'p-5') . ($board !== '' ? card($board, 'p-5') : '') . '</div>' . card($topHtml, 'p-5') . '</div>';
        $html .= '</div>';

        self::layout($user, $html, ['title' => 'Продажи бара', 'width' => 'wide']);
    }

    private static function barViewSwitch(array $branch, array $month, string $active): string
    {
        return segmented([
            ['href' => url_to('/bar', ['branch' => $branch['slug'], 'm' => $month['key']]), 'label' => 'Команда', 'active' => $active === 'team'],
            ['href' => url_to('/bar', ['branch' => $branch['slug'], 'm' => $month['key'], 'view' => 'me']), 'label' => 'Мои продажи', 'active' => $active === 'me'],
        ], 'Режим');
    }

    private static function barSaleRow(array $r, array $names, bool $showWho = true): string
    {
        $who = $r['eid'] !== null && isset($names[(int) $r['eid']]) ? short_name($names[(int) $r['eid']]) : (string) ($r['staffName'] ?: 'без привязки');
        $html = '<li class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0">';
        $html .= '<span class="flex min-w-0 flex-col gap-0.5"><span class="truncate text-sm font-medium">' . h((string) ($r['goodsName'] ?? 'Товар')) . ((int) $r['qty'] > 1 ? ' <span class="text-muted-foreground">× ' . (int) $r['qty'] . '</span>' : '') . '</span>';
        $html .= '<span class="truncate text-xs text-muted-foreground">' . h(format_dt(str_replace(' ', 'T', (string) $r['saleAt']))) . ($showWho ? ' · ' . h($who) : '') . '</span></span>';
        return $html . '<span class="shrink-0 font-display text-sm font-semibold tabular-nums">' . h(kop_rub((int) $r['amountX100'])) . '</span></li>';
    }

    /** Мини-гистограмма по часам суток. */
    private static function hourBars(array $values, string $label, callable $fmt, ?int $highlightUpTo = null): string
    {
        $max = max(1, ...$values);
        $peak = array_search(max($values), $values, true);
        $html = '<div class="flex flex-col gap-2"><div class="flex h-24 items-end gap-0.5" role="img" aria-label="' . h($label) . '">';
        foreach ($values as $h => $v) {
            $pct = $v > 0 ? max(4, (int) round($v / $max * 100)) : 0;
            $future = $highlightUpTo !== null && $h > $highlightUpTo;
            $tone = $h === $peak && $v > 0 ? 'bg-primary' : ($future ? 'bg-muted' : 'bg-foreground/25');
            $html .= '<span class="flex h-full min-w-0 flex-1 items-end" title="' . h(sprintf('%02d:00 — ', $h) . $fmt($v)) . '"><span class="block w-full rounded-t-[2px] ' . $tone . '" style="height:' . ($future ? 2 : max(2, $pct)) . '%"></span></span>';
        }
        $html .= '</div><div class="flex justify-between text-[10px] text-muted-foreground tabular-nums" aria-hidden="true"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div></div>';
        return $html;
    }

    /** Сводка для владельца и управляющих: продажи каждого, отмены, загрузка клуба. */
    private static function pageBarTeam(array $user, array $branch, array $s, array $month): void
    {
        $bid = (int) $branch['id'];
        $now = now_tyumen();
        $today = $now->format('Y-m-d');
        $names = [];
        foreach (self::employees() as $e) {
            $names[(int) $e['id']] = (string) $e['name'];
        }

        $base = ['branch' => $branch['slug'], 'm' => $month['key']];
        $tab = self::getStr('tab', 12);
        if (!isset(self::barSections()[$tab])) {
            $tab = self::getStr('emp', 10) !== '' ? 'team' : 'overview';
        }
        $q = $base + ['tab' => $tab];
        $empId = (int) self::getStr('emp', 10);
        if ($tab === 'salary' && self::getStr('export', 6) === 'xlsx') {
            self::barPayrollExport($user, $month);
        }
        $html = self::barWorkspaceHead($user, $branch, $month, $s, $tab);
        $connected = boolish($s['isEnabled'] ?? false);
        $ready = $connected && (!empty($s['syncedFrom']) || boolish($s['lastSyncOk'] ?? false));
        $content = null;
        $reloadLive = false;
        if ($tab === 'salary') {
            // Ведомость не зависит от live/sync бара — не даём соседним веткам её затереть.
            $content = (!$ready ? '<p class="crm-notice">Продажи не синхронизированы. Процент с бара может быть неполным.</p>' : '') . self::barPayroll($user, $month, $q);
        } elseif (!$connected) {
            $content = self::barUnavailable($user);
        } else {
            $live = null;
            if ($tab === 'occupancy' || $tab === 'goods') {
                $live = Langame::live($bid);
                $reloadLive = $live === null || !empty($live['refreshing']);
            }
            if ($tab === 'occupancy') {
                $content = self::barOccupancy($branch, $live);
            } elseif (!$ready) {
                $content = '<section class="crm-empty"><h2>Ожидаем первую синхронизацию</h2><p>Данные подтянутся в фоне в течение минуты. Можно также нажать «Обновить продажи».</p></section>';
            } elseif ($tab === 'sales') {
                $content = self::barLedger($branch, $month, $base, !empty($user['isOwner']));
            } elseif ($tab === 'goods') {
                $content = self::barGoods($branch, $month, $live, $user);
            }
        }
        if ($content !== null) {
            self::layout($user, '<div class="crm-workspace">' . $html . '<div class="crm-content">' . $content . '</div></div>', ['title' => self::barSections()[$tab][0] . ' · Аналитика', 'width' => 'wide', 'langameReload' => $reloadLive && $tab === 'occupancy' && $live === null]);
            return;
        }
        $html .= '<div class="crm-content">';
        $stats = Langame::teamStats($bid, $month['from'], $month['to']);
        $todayStats = $month['isCurrent'] && $tab === 'overview' ? Langame::teamStats($bid, $today, $today) : [];
        $shifts = Langame::shiftCounts($bid, $month['from'], $month['to']);
        $live = null;
        $sum = ['revenue' => 0, 'qty' => 0, 'lines' => 0, 'cancelAmount' => 0, 'cancelCount' => 0, 'cashless' => 0, 'earn' => 0];
        foreach ($stats as $t) {
            foreach ($sum as $k => $_) {
                $sum[$k] += $t[$k];
            }
        }
        $todayRevenue = array_sum(array_column($todayStats, 'revenue'));
        $unlinked = $stats[0]['revenue'] ?? 0;
        $daysPassed = $month['isCurrent'] ? (int) $now->format('j') : (int) (new DateTimeImmutable($month['to']))->format('j');
        $avgDay = $daysPassed > 0 ? (int) round($sum['revenue'] / $daysPassed) : 0;

        /* --- итоги месяца --- */
        $kpi = '<div class="crm-kpis">';
        $kpi .= self::barMetric('Выручка бара', kop_rub($sum['revenue']), 'Без отмен · ' . kop_rub($avgDay) . ' в день');
        $kpi .= self::barMetric('Продано позиций', num_fmt($sum['qty']), 'Средняя стоимость ' . kop_rub($sum['qty'] > 0 ? (int) round($sum['revenue'] / $sum['qty']) : 0));
        $kpi .= self::barMetric('Безналичная оплата', $sum['revenue'] > 0 ? round($sum['cashless'] / $sum['revenue'] * 100) . '%' : '—', kop_rub($sum['cashless']));
        $kpi .= self::barMetric('Отменённые позиции', kop_rub($sum['cancelAmount']), plural($sum['cancelCount'], 'запись', 'записи', 'записей'));
        $kpi .= '</div>';
        if ($tab === 'overview') {
            $html .= $kpi;
        }

        /* --- таблица сотрудников --- */
        $rows = $stats;
        unset($rows[0]);
        uasort($rows, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        // Проценты и начисления других сотрудников видит только владелец — у каждого свой процент.
        $seePay = !empty($user['isOwner']);
        $team = card_head('Администраторы за ' . $month['name'], ($seePay ? 'Выручка и процент' : 'Выручка') . ' по выбранному филиалу · нажмите на сотрудника', '', 'users');
        if ($rows) {
            $maxRev = max(1, (int) reset($rows)['revenue']);
            $team .= '<div class="-mx-5 mt-4 overflow-x-auto"><table class="w-full min-w-[44rem] text-sm"><thead><tr class="border-y border-border bg-muted/40 text-left text-xs font-semibold text-muted-foreground">';
            foreach (array_merge(['Сотрудник', 'Выручка', 'Позиций', 'Смен', 'За смену', 'Отмены'], $seePay ? ['Начислено'] : []) as $i => $col) {
                $team .= '<th scope="col" class="px-4 py-2.5 font-semibold' . ($i > 0 ? ' text-right' : '') . '">' . $col . '</th>';
            }
            $team .= '</tr></thead><tbody class="divide-y divide-border">';
            $avatars = self::employeeAvatarUrls();
            foreach ($rows as $id => $t) {
                $n = $shifts[$id] ?? 0;
                $sel = $empId === $id;
                $href = url_to('/bar', $q + ($sel ? [] : ['emp' => $id])) . '#emp';
                $team .= '<tr class="transition-colors hover:bg-muted/40' . ($sel ? ' bg-muted/60' : '') . '">';
                $team .= '<td class="px-4 py-3"><a href="' . h($href) . '" class="flex min-w-0 flex-col gap-1.5 font-medium hover:underline"' . ($sel ? ' aria-current="true"' : '') . '><span class="flex items-center gap-2">' . avatar($names[$id] ?? 'Сотрудник', 'size-7 text-[10px]', 'ink', $avatars[$id] ?? null) . '<span class="truncate">' . h(short_name($names[$id] ?? 'Сотрудник #' . $id)) . '</span></span>';
                $team .= '<span class="block h-1 w-full max-w-40 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full bg-primary" style="width:' . max(3, (int) round($t['revenue'] / $maxRev * 100)) . '%"></span></span></a></td>';
                $team .= '<td class="px-4 py-3 text-right font-display font-semibold tabular-nums">' . h(kop_rub($t['revenue'])) . '</td>';
                $team .= '<td class="px-4 py-3 text-right tabular-nums">' . num_fmt($t['qty']) . '</td>';
                $team .= '<td class="px-4 py-3 text-right tabular-nums">' . ($n ?: '—') . '</td>';
                $team .= '<td class="px-4 py-3 text-right tabular-nums">' . ($n ? h(kop_rub((int) round($t['revenue'] / $n))) : '—') . '</td>';
                $team .= '<td class="px-4 py-3 text-right tabular-nums' . ($t['cancelCount'] >= 3 ? ' font-semibold text-primary-ink' : '') . '">' . ($t['cancelCount'] ? $t['cancelCount'] . ' · ' . h(kop_rub($t['cancelAmount'])) : '—') . '</td>';
                $team .= ($seePay ? '<td class="px-4 py-3 text-right tabular-nums">' . h(kop_rub($t['earn'])) . ' <span class="text-xs text-muted-foreground">' . h(pct_label($t['percentX10'])) . '</span></td>' : '') . '</tr>';
            }
            $team .= '</tbody></table></div>';
        } else {
            $team .= '<p class="mt-3 text-sm leading-relaxed text-muted-foreground">За ' . h($month['name']) . ' нет привязанных продаж. ' . ($user['isOwner'] ? 'Привяжите учётки Langame к сотрудникам в разделе «Подключение Langame».' : '') . '</p>';
        }

        /* --- карточка выбранного сотрудника --- */
        $detail = '';
        if ($empId > 0 && isset($names[$empId])) {
            $t = $stats[$empId] ?? ['revenue' => 0, 'qty' => 0, 'lines' => 0, 'cancelAmount' => 0, 'cancelCount' => 0, 'cashless' => 0, 'percentX10' => 0, 'earn' => 0];
            $recent = Langame::recentSales($empId, $month['from'], $month['to'], 20, $bid);
            $top = Langame::topGoods($empId, $month['from'], $month['to'], 6, $bid);
            $detail = '<div class="flex flex-wrap items-start justify-between gap-3"><div class="flex items-center gap-3">' . avatar($names[$empId], 'size-11 text-sm', 'ink', self::employeeAvatarUrls()[$empId] ?? null) . '<div class="flex flex-col"><h2 class="font-display text-lg font-semibold">' . h($names[$empId]) . '</h2><p class="text-sm text-muted-foreground">' . h($month['title']) . ' · ' . h(plural($shifts[$empId] ?? 0, 'смена', 'смены', 'смен')) . '</p></div></div>';
            $detail .= '<a class="' . btn_class('quiet', 'icon-sm') . '" href="' . h(url_to('/bar', $q)) . '" aria-label="Закрыть карточку">' . icon('x', 'size-4') . '</a></div>';
            $detail .= '<dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">';
            foreach ([['Выручка', kop_rub($t['revenue'])], ['Позиций', num_fmt($t['qty'])], ['Средняя', kop_rub($t['qty'] > 0 ? (int) round($t['revenue'] / $t['qty']) : 0)], $seePay ? ['Начислено', kop_rub($t['earn'])] : ['Смен', (string) ($shifts[$empId] ?? 0)]] as [$k, $v]) {
                $detail .= '<div class="flex flex-col gap-1 rounded-md bg-muted/50 p-3"><dt class="text-xs text-muted-foreground">' . $k . '</dt><dd class="font-display text-lg font-semibold tabular-nums">' . h($v) . '</dd></div>';
            }
            $detail .= '</dl><div class="mt-5 grid gap-6 md:grid-cols-2"><div><h3 class="text-sm font-semibold">Последние продажи</h3>';
            if ($recent) {
                $detail .= '<ul class="mt-3 flex max-h-96 flex-col divide-y divide-border overflow-y-auto pr-1 scrollbar-thin">';
                foreach ($recent as $r) {
                    $cancel = boolish($r['isCancelled']);
                    $detail .= '<li class="flex items-center justify-between gap-3 py-2' . ($cancel ? ' text-primary-ink' : '') . '"><span class="flex min-w-0 flex-col"><span class="truncate text-sm' . ($cancel ? ' line-through' : '') . '">' . h((string) ($r['goodsName'] ?? 'Товар')) . ((int) $r['qty'] > 1 ? ' × ' . (int) $r['qty'] : '') . '</span><span class="text-xs text-muted-foreground">' . h(format_dt(str_replace(' ', 'T', (string) $r['saleAt']))) . ($cancel ? ' · отменена' : '') . '</span></span><span class="shrink-0 text-sm font-semibold tabular-nums">' . h(kop_rub((int) $r['amountX100'])) . '</span></li>';
                }
                $detail .= '</ul>';
            } else {
                $detail .= '<p class="mt-2 text-sm text-muted-foreground">Продаж нет.</p>';
            }
            $detail .= '</div><div><h3 class="text-sm font-semibold">Что продаёт чаще</h3>';
            if ($top) {
                $mx = max(1, (int) $top[0]['amount']);
                $detail .= '<ol class="mt-3 flex flex-col gap-3">';
                foreach ($top as $g) {
                    $detail .= '<li class="flex flex-col gap-1.5"><div class="flex items-baseline justify-between gap-3 text-sm"><span class="truncate">' . h((string) $g['name']) . '</span><span class="shrink-0 tabular-nums text-muted-foreground">' . (int) $g['qty'] . ' шт · <span class="font-semibold text-foreground">' . h(kop_rub((int) $g['amount'])) . '</span></span></div><div class="h-1.5 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-primary" style="width:' . max(4, (int) round(((int) $g['amount']) / $mx * 100)) . '%"></div></div></li>';
                }
                $detail .= '</ol>';
            } else {
                $detail .= '<p class="mt-2 text-sm text-muted-foreground">Пока пусто.</p>';
            }
            $detail .= '</div></div>';
            $detail = card($detail, 'p-5 scroll-mt-20', 'section', 'id="emp" aria-label="Продажи сотрудника"');
        }

        /* --- на что обратить внимание --- */
        $alerts = [];
        if ($unlinked > 0) {
            $alerts[] = ['link', 'Продажи без привязки', h(kop_rub($unlinked)) . ' за месяц не привязаны ни к кому — проценты по ним не считаются.', $user['isOwner'] ? url_to('/manage/langame') : ''];
        }
        foreach ($rows as $id => $t) {
            if ($t['cancelCount'] >= 3 || ($t['revenue'] > 0 && $t['cancelAmount'] > $t['revenue'] * 0.1)) {
                $alerts[] = ['alert', 'Много отмен: ' . short_name($names[$id] ?? ''), h(plural($t['cancelCount'], 'отмена', 'отмены', 'отмен')) . ' на ' . h(kop_rub($t['cancelAmount'])) . ' — стоит проверить чеки.', url_to('/bar', $base + ['tab' => 'team', 'emp' => $id]) . '#emp'];
            }
        }
        $perShift = [];
        foreach ($rows as $id => $t) {
            if (($shifts[$id] ?? 0) >= 2) {
                $perShift[$id] = $t['revenue'] / $shifts[$id];
            }
        }
        if (count($perShift) >= 2) {
            $avgPs = array_sum($perShift) / count($perShift);
            foreach ($perShift as $id => $v) {
                if ($v < $avgPs * 0.5) {
                    $alerts[] = ['trophy', 'Слабые продажи: ' . short_name($names[$id] ?? ''), 'В среднем ' . h(kop_rub((int) round($v))) . ' за смену при среднем по команде ' . h(kop_rub((int) round($avgPs))) . '.', url_to('/bar', $base + ['tab' => 'team', 'emp' => $id]) . '#emp'];
                }
            }
        }
        foreach (($live['lowStock'] ?? []) as $g) {
            $alerts[] = ['tags', 'Заканчивается: ' . $g['name'], 'Осталось ' . (int) $g['count'] . ' шт, уходит около ' . (int) $g['perWeek'] . ' в неделю.', url_to('/bar', $base + ['tab' => 'goods'])];
        }
        if ($month['isCurrent'] && $avgDay > 0 && (int) $now->format('G') >= 20 && $todayRevenue < $avgDay * 0.5) {
            $alerts[] = ['coins', 'Тихий день в баре', 'Сегодня ' . h(kop_rub($todayRevenue)) . ' — меньше половины от среднего ' . h(kop_rub($avgDay)) . '.', ''];
        }
        $watch = card_head('На что обратить внимание', $alerts ? '' : 'Всё спокойно', '', 'bell');
        if ($alerts) {
            $watch .= '<ul class="mt-4 flex flex-col divide-y divide-border">';
            foreach (array_slice($alerts, 0, 10) as [$ic, $title, $text, $href]) {
                $inner = '<span class="mt-0.5 text-primary">' . icon($ic, 'size-4') . '</span><span class="flex min-w-0 flex-col gap-0.5"><span class="text-sm font-semibold">' . h($title) . '</span><span class="text-sm leading-relaxed text-muted-foreground">' . $text . '</span></span>';
                $watch .= '<li class="py-3 first:pt-0 last:pb-0">' . ($href !== '' ? '<a href="' . h($href) . '" class="-mx-2 flex gap-3 rounded-md px-2 py-1 transition-colors hover:bg-muted/50">' . $inner . '</a>' : '<div class="flex gap-3">' . $inner . '</div>') . '</li>';
            }
            $watch .= '</ul>';
        } else {
            $watch .= '<p class="mt-3 text-sm leading-relaxed text-muted-foreground">По синхронизированным продажам нет сигналов, требующих проверки. Остатки и загрузка доступны в отдельных вкладках.</p>';
        }
        if (!empty($live['errors']) && $user['isOwner']) {
            $watch .= '<p class="mt-4 text-xs text-muted-foreground">Не всё получено из Langame: ' . h(implode('; ', $live['errors'])) . '</p>';
        }

        $hours = $tab === 'overview' ? Langame::hourlyRevenue($bid, $month['from'], $month['to']) : [];
        $hoursHtml = '';
        if (array_sum($hours) > 0) {
            $peak = (int) array_search(max($hours), $hours, true);
            $hoursHtml = card(card_head('Когда покупают', 'Пик — с ' . sprintf('%02d:00 до %02d:00', $peak, ($peak + 1) % 24) . ', выручка бара по часам за месяц', '', 'clock') . '<div class="mt-4">' . self::hourBars($hours, 'Выручка бара по часам за месяц', static fn ($v) => kop_rub($v)) . '</div>', 'p-5');
        }

        if ($tab === 'overview') {
            $html .= '<div class="crm-split"><div class="crm-stack">' . ($hoursHtml !== '' ? $hoursHtml : card(card_head('Когда покупают', 'Нет синхронизированных продаж за месяц', '', 'clock'), 'p-5')) . '<a class="crm-drilldown" href="' . h(url_to('/bar', $base + ['tab' => 'sales'])) . '"><span>Все продажи за период</span>' . icon('arrow', 'size-4') . '</a></div>';
            $html .= card($watch, 'p-5') . '</div>';
        } else {
            $html .= '<div class="crm-kpis">' . self::barMetric('Сотрудники с продажами', (string) count($rows), 'Привязанные учётные записи') . ($seePay ? self::barMetric('Начислено с бара', kop_rub($sum['earn']), 'По настроенному проценту') : self::barMetric('Выручка бара', kop_rub($sum['revenue']), 'Без отмен')) . self::barMetric('Без привязки', kop_rub($unlinked), 'Не включено в зарплату') . self::barMetric('Период', $month['name'], 'Выбранный филиал') . '</div>';
            $html .= $detail . card($team, 'p-5');
        }

        self::layout($user, '<div class="crm-workspace">' . $html . '</div></div>', ['title' => self::barSections()[$tab][0] . ' · Аналитика', 'width' => 'wide']);
    }

    /* ---------- Зарплата за месяц ---------- */

    /**
     * Строки ведомости: смены по типам, ставка, процент с бара, штрафы. Суммы в рублях.
     * $branchIds — филиалы, чьих сотрудников показывать; null — все.
     */
    /** Процент управляющего видят только владелец и сам управляющий. */
    private static function canSeeMgrBar(array $viewer, int $employeeId): bool
    {
        return !empty($viewer['isOwner']) || (int) ($viewer['employeeId'] ?? 0) === $employeeId;
    }

    private static function barPayrollRows(array $viewer, array $month, ?array $branchIds = null): array
    {
        $rows = [];
        $paid = [];
        foreach (Db::all('SELECT "employeeId", period, amount, "paidAt", "paidByName" FROM salary_payouts WHERE month = ?', [$month['key']]) as $p) {
            $paid[(int) $p['employeeId']][(int) $p['period']] = $p;
        }
        foreach (self::employees() as $e) {
            if (!self::worksInAny($e, $branchIds)) {
                continue;
            }
            $data = Payroll::employee((int) $e['id'], $month['key']);
            if (!$data) {
                continue;
            }
            [$first, $second] = [$data['periods'][1], $data['periods'][2]];
            $mgrBar = self::canSeeMgrBar($viewer, (int) $e['id']) ? $second['mgrBar'] : 0;
            $second['total'] -= $second['mgrBar'] - $mgrBar;
            $types = ['day' => 0, 'night' => 0, 'full' => 0];
            foreach (array_merge($first['shifts'], $second['shifts']) as $shift) {
                if ($shift['started']) {
                    $types[$shift['shiftType']]++;
                }
            }
            $units = $first['units'] + $second['units'];
            $fine = $first['fines'] + $second['fines'];
            if (!boolish($e['isActive']) && $units === 0 && $fine === 0 && $second['barRev'] === 0) {
                continue;
            }
            $shiftKind = $types['night'] >= $types['day'] && $types['night'] >= $types['full']
                ? 'Ночная'
                : ($types['full'] >= $types['day'] ? 'Сутки' : 'Дневная');
            $rows[] = [
                'id' => (int) $e['id'],
                'name' => (string) $e['name'],
                'active' => boolish($e['isActive']),
                'grade' => $data['employee']['gradeTitle'] ?? '',
                'homeBranchId' => (int) ($e['homeBranchId'] ?? 0),
                'day' => $types['day'],
                'night' => $types['night'],
                'full' => $types['full'],
                'units' => $units,
                'shiftKind' => $shiftKind,
                'rate' => (int) ($data['employee']['shiftRate'] ?? 0),
                'gross' => $first['gross'] + $second['gross'],
                'barRev' => $second['barRev'],
                'barPct' => $second['barPct'],
                'bar' => $second['bar'],
                'mgrBar' => $mgrBar,
                'mgrBarPct' => $mgrBar ? $second['mgrBarPct'] : 0,
                'fines' => $fine,
                'units1' => $first['units'],
                'gross1' => $first['gross'],
                'fines1' => $first['fines'],
                'units2' => $second['units'],
                'gross2' => $second['gross'],
                'fines2' => $second['fines'],
                'half1' => $first['total'],
                'half2' => $second['total'],
                'total' => $first['total'] + $second['total'],
                'payPhone' => (string) ($data['employee']['payPhone'] ?? ''),
                'payBank' => (string) ($data['employee']['payBank'] ?? ''),
                'payNote' => (string) ($data['employee']['payNote'] ?? ''),
                'paid' => $paid[(int) $e['id']] ?? [],
            ];
        }
        usort($rows, static fn ($a, $b) => [$b['active'], $b['total']] <=> [$a['active'], $a['total']]);
        return $rows;
    }

    private static function barPayroll(array $user, array $month, array $q): string
    {
        $rows = self::barPayrollRows($user, $month, user_branch_ids($user));
        $sum = ['units' => 0, 'gross' => 0, 'bar' => 0, 'mgrBar' => 0, 'fines' => 0, 'total' => 0, 'barRev' => 0];
        foreach ($rows as $r) {
            foreach ($sum as $k => $_) {
                $sum[$k] += $r[$k];
            }
        }
        $period = format_day_month($month['from']) . ' — ' . format_day_month($month['to']);

        $html = '<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">';
        $html .= stat_tile('Начавшиеся смены', (string) $sum['units'], 'без будущих выходов · сутки за две', '', 'calendar');
        $html .= stat_tile('По ставкам', h(money_rub($sum['gross'])), h(plural($sum['units'], 'смена', 'смены', 'смен')) . ' · сутки за две', '', 'calendar');
        $html .= stat_tile('% с бара', h(money_rub($sum['bar'] + $sum['mgrBar'])), 'с продаж на ' . h(money_rub($sum['barRev'])) . ($sum['mgrBar'] ? ' · в т. ч. управляющему ' . h(money_rub($sum['mgrBar'])) : ''), '', 'coins');
        $html .= stat_tile('Штрафы', h(money_rub($sum['fines'])), '', url_to('/fines'), 'scale', $sum['fines'] > 0);
        $html .= '</div>';

        $head = '<div class="flex flex-wrap items-center justify-between gap-3">' . card_head('Ведомость за ' . $month['name'], h($period) . ' · ' . h(plural(count($rows), 'сотрудник', 'сотрудника', 'сотрудников')), '', 'file-text');
        $head .= '<div class="flex flex-wrap gap-2"><a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/manage/grades')) . '">' . icon('settings', 'size-4') . 'Ставки</a>';
        $head .= '<a class="' . btn_class('primary', 'sm') . '" href="' . h(url_to('/bar', $q + ['export' => 'xlsx'])) . '" download>' . icon('download', 'size-4') . 'Скачать Excel</a></div></div>';

        if (!$rows) {
            return $html . card($head . '<p class="mt-4 text-sm text-muted-foreground">За ' . h($month['name']) . ' нет ни смен, ни продаж.</p>', 'p-5');
        }

        $t = '<div class="-mx-5 mt-5 overflow-x-auto"><table class="w-full min-w-[56rem] text-sm"><thead><tr class="border-y border-border bg-muted/40 text-left text-xs font-semibold text-muted-foreground">';
        foreach (['Сотрудник', 'День', 'Ночь', 'Сутки', 'Ставка', 'По ставке', 'Бар', '% с бара', 'Штрафы', 'К выплате'] as $i => $c) {
            $t .= '<th scope="col" class="px-4 py-2.5 font-semibold whitespace-nowrap' . ($i > 0 ? ' text-right' : '') . '">' . $c . '</th>';
        }
        $t .= '</tr></thead><tbody class="divide-y divide-border">';
        foreach ($rows as $r) {
            $num = static fn (int $v): string => $v ? (string) $v : '<span class="text-muted-foreground/60">—</span>';
            $t .= '<tr class="transition-colors hover:bg-muted/40' . ($r['active'] ? '' : ' opacity-60') . '">';
            $t .= '<td class="px-4 py-3"><span class="flex items-center gap-3">' . avatar($r['name'], 'size-8 text-[11px]') . '<span class="flex min-w-0 flex-col gap-1"><span class="truncate font-medium">' . h(short_name($r['name'])) . '</span>';
            $t .= '<span class="text-xs text-muted-foreground">' . h($r['grade'] !== '' ? $r['grade'] : 'без уровня') . ($r['active'] ? '' : ' · в архиве') . '</span></span></span></td>';
            $t .= '<td class="px-4 py-3 text-right tabular-nums">' . $num($r['day']) . '</td>';
            $t .= '<td class="px-4 py-3 text-right tabular-nums">' . $num($r['night']) . '</td>';
            $t .= '<td class="px-4 py-3 text-right tabular-nums">' . $num($r['full']) . '</td>';
            $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums text-muted-foreground">' . ($r['rate'] ? h(money_rub($r['rate'])) : '—') . '</td>';
            $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">' . h(money_rub($r['gross'])) . '</td>';
            $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums text-muted-foreground">' . ($r['barRev'] ? h(money_rub($r['barRev'])) : '—') . '</td>';
            $barCell = $r['bar'] ? '+' . h(money_rub($r['bar'])) : ($r['mgrBar'] ? '' : '—');
            if ($r['mgrBar']) {
                $barCell .= '<span class="block text-xs text-muted-foreground">+' . h(money_rub($r['mgrBar'])) . ' · ' . h(pct_label($r['mgrBarPct'])) . ' с клубов</span>';
            }
            $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">' . $barCell . '</td>';
            $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums' . ($r['fines'] ? ' text-primary-ink' : '') . '">' . ($r['fines'] ? '−' . h(money_rub($r['fines'])) : '—') . '</td>';
            $t .= '<td class="px-4 py-3 text-right font-display font-semibold whitespace-nowrap tabular-nums">' . h(money_rub($r['total'])) . '</td></tr>';
        }
        $t .= '</tbody><tfoot><tr class="border-t-2 border-border bg-muted/40 font-semibold">';
        $t .= '<td class="px-4 py-3">Итого</td><td class="px-4 py-3 text-right tabular-nums" colspan="3">' . h(plural($sum['units'], 'смена', 'смены', 'смен')) . '</td><td></td>';
        $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">' . h(money_rub($sum['gross'])) . '</td><td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">' . h(money_rub($sum['barRev'])) . '</td>';
        $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">' . h(money_rub($sum['bar'] + $sum['mgrBar'])) . '</td><td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">' . h(money_rub($sum['fines'])) . '</td>';
        $t .= '<td class="px-4 py-3 text-right font-display whitespace-nowrap tabular-nums">' . h(money_rub($sum['total'])) . '</td></tr></tfoot></table></div>';
        $t .= '<p class="mt-4 text-xs leading-relaxed text-muted-foreground">Начисления — по начавшимся сменам и ставкам на дату выхода. Сутки — две смены. Процент с бара — за весь месяц, с 1-го числа; выплачивается со второй половиной.</p>';

        return $html . card($head . $t, 'p-5') . self::barPayoutsCard($month, $rows, $q);
    }

    /** Реестр переводов: кому, в какой банк, по какому номеру и сколько — с отметкой «переведено». */
    private static function barPayoutsCard(array $month, array $rows, array $q): string
    {
        $half = self::getStr('half', 1) === '1' ? 1 : 2;
        $key = 'half' . $half;
        $list = array_values(array_filter($rows, static fn ($r) => $r[$key] > 0 || !empty($r['paid'][$half])));
        $last = (int) substr($month['to'], 8, 2);
        $switch = '<div class="inline-flex rounded-md border border-border p-0.5" role="group" aria-label="Половина месяца">';
        foreach ([1 => '1–15', 2 => '16–' . $last] as $p => $label) {
            $on = $p === $half;
            $switch .= '<a class="rounded px-3 py-1 text-xs font-semibold ' . ($on ? 'bg-foreground text-background' : 'text-muted-foreground hover:text-foreground') . '" href="' . h(url_to('/bar', $q + ['half' => $p])) . '#payouts"' . ($on ? ' aria-current="true"' : '') . '>' . h($label) . '</a>';
        }
        $switch .= '</div>';
        $toPay = $done = 0;
        $missing = 0;
        foreach ($list as $r) {
            if (!empty($r['paid'][$half])) {
                $done += (int) $r['paid'][$half]['amount'];
            } else {
                $toPay += max(0, $r[$key]);
            }
            if ($r['payPhone'] === '') {
                $missing++;
            }
        }
        $sub = 'Осталось ' . money_rub($toPay) . ($done ? ' · переведено ' . money_rub($done) : '') . ($missing ? ' · без реквизитов: ' . $missing : '');
        $c = '<div class="flex flex-wrap items-center justify-between gap-3">' . card_head('Переводы за ' . ($half === 1 ? '1–15 ' : '16–' . $last . ' ') . mb_strtolower($month['name']), h($sub), '', 'wallet') . $switch . '</div>';
        if (!$list) {
            return '<div id="payouts" class="scroll-mt-24">' . card($c . '<p class="mt-4 text-sm text-muted-foreground">За эту половину переводить некому.</p>', 'p-5') . '</div>';
        }
        $c .= '<ul class="-mx-5 mt-4 flex flex-col divide-y divide-border border-t border-border">';
        foreach ($list as $r) {
            $paid = $r['paid'][$half] ?? null;
            $amount = $paid ? (int) $paid['amount'] : max(0, $r[$key]);
            $c .= '<li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3' . ($paid ? ' bg-muted/30' : '') . '">';
            $c .= '<span class="flex min-w-0 flex-1 basis-48 items-center gap-3">' . avatar($r['name'], 'size-8 text-[11px]') . '<span class="flex min-w-0 flex-col"><span class="truncate text-sm font-medium">' . h($r['name']) . '</span>';
            if ($r['payPhone'] !== '') {
                $c .= '<span class="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground"><span class="font-semibold text-foreground">' . h($r['payBank'] ?: 'Банк не указан') . '</span><span class="font-mono">' . h(format_phone($r['payPhone'])) . '</span>'
                    . '<button type="button" class="' . btn_class('quiet', 'sm') . ' h-6 px-1.5" data-copy="' . h('+7' . $r['payPhone']) . '" aria-label="Скопировать номер ' . h(short_name($r['name'])) . '">' . icon('copy', 'size-3.5') . '</button></span>';
                if ($r['payNote'] !== '') {
                    $c .= '<span class="text-xs text-muted-foreground">' . h($r['payNote']) . '</span>';
                }
            } else {
                $c .= '<span class="text-xs text-primary-ink">Реквизиты не заполнены — попросите указать в профиле</span>';
            }
            $c .= '</span></span>';
            $c .= '<span class="ml-auto font-display text-base font-semibold whitespace-nowrap tabular-nums">' . h(money_rub($amount)) . '</span>';
            $c .= form_open('mark_payout', 'class="shrink-0"') . hidden('employeeId', $r['id']) . hidden('month', $month['key']) . hidden('period', $half);
            $c .= $paid
                ? '<button class="' . btn_class('outline', 'sm') . '" title="' . h('Отметил ' . $paid['paidByName'] . ', ' . format_dt((string) $paid['paidAt'], false)) . '">' . icon('check', 'size-4 text-primary') . 'Переведено</button>'
                : '<button class="' . btn_class('primary', 'sm') . '"' . ($amount <= 0 ? ' disabled' : '') . '>' . icon('send', 'size-4') . 'Отметить перевод</button>';
            $c .= '</form></li>';
        }
        $c .= '</ul><p class="mt-4 text-xs leading-relaxed text-muted-foreground">Номер и банк сотрудник заполняет сам в профиле. Сумма фиксируется в момент отметки; повторное нажатие снимает отметку.</p>';
        return '<div id="payouts" class="scroll-mt-24">' . card($c, 'p-5') . '</div>';
    }

    private static function barPayrollExport(array $user, array $month): never
    {
        $rows = self::barPayrollRows($user, $month, user_branch_ids($user));
        foreach ($rows as &$row) {
            $row['bar'] += $row['mgrBar'];
        }
        unset($row);
        $byBranch = [];
        foreach ($rows as $r) {
            $bn = self::branchName((int) $r['homeBranchId']) ?: 'Без филиала';
            $byBranch[$bn][] = $r;
        }
        if (!$byBranch) {
            $byBranch['Сеть'] = [];
        }

        $c = static fn (mixed $v, int $s = 0): array => xlsx_cell($v, $s);
        $sheets = [];
        $summaryRows = [
            [$c('GAMER PRO · СВОДНАЯ ВЕДОМОСТЬ · ' . mb_strtoupper($month['title']), 4)],
            [$c('Период: ' . format_day_month($month['from']) . ' — ' . format_day_month($month['to']), 1)],
            [],
            [
                $c('Филиал', 5), $c('Админов', 5), $c('Смен', 5), $c('За смены ₽', 5),
                $c('Продажи бара ₽', 5), $c('% с бара ₽', 5), $c('Штрафы ₽', 5), $c('К ВЫПЛАТЕ ₽', 5),
            ],
        ];
        $net = ['people' => 0, 'units' => 0, 'gross' => 0, 'barRev' => 0, 'bar' => 0, 'fines' => 0, 'total' => 0];

        $lastDay = (int) substr($month['to'], 8, 2);
        foreach ($byBranch as $branchName => $list) {
            // Две выплаты: 1–15 — только смены; 16–конец — смены второй половины и процент с бара за весь месяц.
            $header = [
                [$c('КОМПЬЮТЕРНЫЙ КЛУБ GAMER PRO  ·  ФИЛИАЛ «' . mb_strtoupper($branchName) . '»', 4)],
                [$c('Расчётный период:', 1), $c($month['title'], 9), $c('1–15 — только смены · 16–' . $lastDay . ' — смены + бар за весь месяц', 14)],
                [],
                [
                    $c('СОТРУДНИК', 6), $c('', 6), $c('', 6),
                    $c('ВЫПЛАТА 1 · С 1 ПО 15 (СМЕНЫ)', 8), $c('', 8), $c('', 8), $c('', 8),
                    $c('ВЫПЛАТА 2 · С 16 ПО ' . $lastDay . ' (СМЕНЫ + БАР ЗА МЕСЯЦ)', 7), $c('', 7), $c('', 7), $c('', 7), $c('', 7), $c('', 7), $c('', 7),
                    $c('ИТОГО', 6), $c('КУДА ПЕРЕВОДИТЬ', 6), $c('', 6),
                ],
                [
                    $c('№', 5), $c('ФИО сотрудника', 5), $c('Ставка ₽', 5),
                    $c('Смен', 5), $c('За смены ₽', 5), $c('Штрафы ₽', 5), $c('К ВЫПЛАТЕ ₽', 5),
                    $c('Смен', 5), $c('За смены ₽', 5), $c('Продажи бара за месяц ₽', 5), $c('% бара', 5), $c('Бонус бара ₽', 5), $c('Штрафы ₽', 5), $c('К ВЫПЛАТЕ ₽', 5),
                    $c('За месяц ₽', 5), $c('Банк', 5), $c('Телефон', 5),
                ],
            ];
            $body = [];
            $sum = ['units1' => 0, 'gross1' => 0, 'fines1' => 0, 'half1' => 0, 'units2' => 0, 'gross2' => 0, 'barRev' => 0, 'bar' => 0, 'fines2' => 0, 'half2' => 0, 'total' => 0, 'units' => 0, 'gross' => 0, 'fines' => 0];
            $n = 0;
            foreach ($list as $r) {
                $n++;
                $body[] = [
                    $c($n, 14),
                    $c($r['name'], 9),
                    $c($r['rate'] ?: null, 10),
                    $c($r['units1'] ?: null, 9),
                    $c($r['gross1'] ?: null, 10),
                    $c($r['fines1'] ?: null, 10),
                    $c($r['half1'], 11),
                    $c($r['units2'] ?: null, 9),
                    $c($r['gross2'] ?: null, 10),
                    $c($r['barRev'] ?: null, 10),
                    $c($r['bar'] ? pct_label($r['barPct'] ?: $r['mgrBarPct']) : '—', 14),
                    $c($r['bar'] ?: null, 10),
                    $c($r['fines2'] ?: null, 10),
                    $c($r['half2'], 11),
                    $c($r['total'], 11),
                    $c($r['payBank'] !== '' ? $r['payBank'] : '—', 9),
                    $c($r['payPhone'] !== '' ? format_phone($r['payPhone']) : 'не указан', 9),
                ];
                foreach ($sum as $k => $_) {
                    $sum[$k] += $r[$k];
                }
            }
            if (!$body) {
                $body[] = [$c('Нет начислений за период', 14)];
            }
            $footer = [[
                $c('ИТОГО ПО ТОЧКЕ', 12), $c('', 12), $c('', 12),
                $c($sum['units1'], 13), $c($sum['gross1'], 13), $c($sum['fines1'], 13), $c($sum['half1'], 11),
                $c($sum['units2'], 13), $c($sum['gross2'], 13), $c($sum['barRev'], 13), $c('', 12), $c($sum['bar'], 13), $c($sum['fines2'], 13), $c($sum['half2'], 11),
                $c($sum['total'], 11), $c('', 12), $c('', 12),
            ]];
            $sheets[] = [
                'name' => mb_substr($branchName, 0, 31),
                'rows' => array_merge($header, $body, $footer),
                'widths' => [5, 28, 11, 7, 13, 11, 14, 7, 13, 16, 9, 13, 11, 14, 14, 16, 20],
                'freeze' => 5,
                'merges' => ['A1:Q1', 'C2:Q2', 'A4:C4', 'D4:G4', 'H4:N4', 'P4:Q4'],
            ];
            $summaryRows[] = [
                $c('📍 ' . $branchName, 14),
                $c(count($list), 14),
                $c($sum['units'], 10),
                $c($sum['gross'], 10),
                $c($sum['barRev'], 10),
                $c($sum['bar'], 10),
                $c($sum['fines'], 10),
                $c($sum['total'], 11),
            ];
            $net['people'] += count($list);
            foreach (['units', 'gross', 'barRev', 'bar', 'fines', 'total'] as $k) {
                $net[$k] += $sum[$k];
            }
        }

        $summaryRows[] = [
            $c('ИТОГО ПО СЕТИ', 12), $c($net['people'], 13), $c($net['units'], 13), $c($net['gross'], 13),
            $c($net['barRev'], 13), $c($net['bar'], 13), $c($net['fines'], 13), $c($net['total'], 11),
        ];
        array_unshift($sheets, [
            'name' => 'Сводка',
            'rows' => $summaryRows,
            'widths' => [22, 10, 10, 14, 15, 13, 12, 14],
            'freeze' => 4,
            'merges' => ['A1:H1'],
        ]);

        $last = (int) substr($month['to'], 8, 2);
        $pay = [
            [$c('ПЕРЕВОДЫ · ' . mb_strtoupper($month['title']), 4)],
            [$c('Кому, куда и сколько переводить. Отметки «переведено» — из CRM на момент выгрузки.', 1)],
            [],
            [$c('№', 5), $c('ФИО', 5), $c('Филиал', 5), $c('Банк', 5), $c('Телефон', 5), $c('Комментарий', 5),
                $c('1–15 ₽', 5), $c('1–15', 5), $c('16–' . $last . ' ₽', 5), $c('16–' . $last, 5), $c('ВСЕГО ₽', 5)],
        ];
        $n = 0;
        $totals = [1 => 0, 2 => 0];
        foreach ($rows as $r) {
            if ($r['total'] <= 0 && !$r['paid']) {
                continue;
            }
            $n++;
            $status = static fn (int $p): string => !empty($r['paid'][$p]) ? 'переведено' : ($r['half' . $p] > 0 ? 'к переводу' : '—');
            $pay[] = [
                $c($n, 14), $c($r['name'], 9), $c(self::branchName((int) $r['homeBranchId']) ?: '—', 9),
                $c($r['payBank'] !== '' ? $r['payBank'] : '—', 9),
                $c($r['payPhone'] !== '' ? format_phone($r['payPhone']) : 'не указан', 9),
                $c($r['payNote'], 9),
                $c(max(0, $r['half1']) ?: null, 10), $c($status(1), 14),
                $c(max(0, $r['half2']) ?: null, 10), $c($status(2), 14),
                $c($r['total'], 11),
            ];
            $totals[1] += max(0, $r['half1']);
            $totals[2] += max(0, $r['half2']);
        }
        $pay[] = [$c('ИТОГО', 12), $c('', 12), $c('', 12), $c('', 12), $c('', 12), $c('', 12),
            $c($totals[1], 13), $c('', 12), $c($totals[2], 13), $c('', 12), $c($totals[1] + $totals[2], 11)];
        $sheets[] = [
            'name' => 'Переводы',
            'rows' => $pay,
            'widths' => [5, 28, 16, 16, 20, 24, 12, 13, 12, 13, 13],
            'freeze' => 4,
            'merges' => ['A1:K1', 'A2:K2'],
        ];

        $name = 'Зарплаты_GAMER_PRO_' . $month['title'] . '.xlsx';
        xlsx_send_book($name, $sheets);
    }

    /* ---------- Подключение Langame (владелец) ---------- */

    private static function pageLangame(array $user): void
    {
        $html = page_head('Подключение Langame', '', '<a class="' . btn_class('outline') . '" href="' . h(url_to('/bar')) . '">' . icon('coins', 'size-4') . 'Продажи бара</a>');

        $empOptions = ['' => '— не привязан —'];
        $byBranch = [];
        foreach (self::employees(true) as $e) {
            $byBranch[self::branchName((int) $e['homeBranchId'])][(string) $e['id']] = (string) $e['name'];
        }
        foreach ($byBranch as $bn => $list) {
            $empOptions[$bn] = $list;
        }

        $month = self::monthFromQuery();
        foreach (self::branches() as $b) {
            $s = Langame::settings($b['id']);
            $on = $s && boolish($s['isEnabled']);
            $hasToken = $s && !empty($s['tokenEnc']);

            $status = $on ? chip('Подключено', 'ink', 'check') : ($hasToken ? chip('Выключено', 'neutral') : chip('Не настроено', 'neutral'));
            $c = card_head($b['name'], $s && $s['lastSyncAt'] ? 'Обновлено ' . h(format_dt((string) $s['lastSyncAt'])) . (!boolish($s['lastSyncOk']) && !empty($s['lastError']) ? ' · <span class="text-primary-ink">' . h((string) $s['lastError']) . '</span>' : '') : 'Синхронизаций ещё не было', $status, 'link');

            $c .= form_open('save_langame', 'class="mt-5 flex flex-col gap-4" autocomplete="off"') . hidden('branchId', $b['id']);
            $c .= ui_field('Адрес клуба в Langame', '<input name="baseUrl" type="url" inputmode="url" class="' . field_class() . '" maxlength="255" placeholder="' . h(Langame::BASE_EXAMPLE) . '" value="' . h((string) ($s['baseUrl'] ?? '')) . '">');
            $tokenHint = $hasToken ? 'Сохранён токен ' . h((string) ($s['tokenHint'] ?? '')) . '. Пустое поле — без изменений.' : 'Серверный ключ (X-Request-Token) из настроек Langame.';
            $c .= ui_field('Секретный токен', '<input name="token" type="password" class="' . field_class('font-mono') . '" maxlength="512" autocomplete="new-password" spellcheck="false" placeholder="' . ($hasToken ? '••••••••••••' : 'Вставьте токен') . '">', $tokenHint);
            $hours = [];
            for ($h = 0; $h <= 11; $h++) {
                $hours[$h] = sprintf('%02d:00–%02d:00', $h, $h + 12);
            }
            $c .= '<div class="grid gap-4 sm:grid-cols-2">';
            $c .= ui_field('Количество компьютеров', '<input name="pcCount" type="number" min="0" max="500" class="' . field_class() . '" value="' . (int) $b['pcCount'] . '" required>', 'Без консолей. 0 — не указано.');
            $c .= ui_field('Дневная смена', ui_select('dayStartHour', $hours, branch_day_start($b['id'])), 'Ночная — следующие 12 часов.');
            $c .= '</div>';
            $c .= ui_field('Не считать баром', '<input name="excludeGoods" class="' . field_class() . '" maxlength="500" autocomplete="off" placeholder="Например: ТВ" value="' . h((string) ($s['excludeGoods'] ?? '')) . '">', 'Слова через запятую. Позиции, где такое слово стоит в названии отдельно, — аренда приставки и другие услуги — не входят в выручку бара и процент сотрудников.');
            $c .= '<div class="flex flex-wrap items-end gap-4">';
            $c .= ui_field('Процент с бара по умолчанию', '<div class="relative"><input name="barPercent" type="number" inputmode="decimal" min="0" max="100" step="0.1" class="' . field_class('pr-8 tabular-nums') . '" value="' . h(rtrim(rtrim(number_format(((int) ($s['barPercentX10'] ?? 0)) / 10, 1, '.', ''), '0'), '.')) . '"><span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">%</span></div>', '', 'w-44');
            $c .= '<label class="flex cursor-pointer items-center gap-2 pb-2.5 text-sm font-medium"><input type="checkbox" name="isEnabled" value="1" class="' . check_class() . '"' . ($on || !$s ? ' checked' : '') . '>Подтягивать продажи</label></div>';
            $c .= '<div class="flex flex-wrap gap-2"><button class="' . btn_class() . '">' . icon('save', 'size-4') . 'Сохранить</button></form>';
            if ($hasToken) {
                $c .= form_open('test_langame') . hidden('branchId', $b['id']) . '<button class="' . btn_class('outline') . '">' . icon('check-circle', 'size-4') . 'Проверить связь</button></form>';
                $c .= form_open('sync_langame') . hidden('branchId', $b['id']) . hidden('back', '/manage/langame') . '<button class="' . btn_class('outline') . '">' . icon('refresh', 'size-4') . 'Синхронизировать</button></form>';
                $c .= form_open('forget_langame_token', confirm_attr('Удалить токен ' . $b['name'] . '? Подключение выключится, уже загруженные продажи останутся.')) . hidden('branchId', $b['id']) . '<button class="' . btn_class('danger-ghost') . '">' . icon('trash', 'size-4') . 'Удалить токен</button></form>';
            }
            $c .= '</div>';

            $staff = $s ? Db::all('SELECT * FROM langame_staff WHERE "branchId" = ? ORDER BY "langameName", id', [$b['id']]) : [];
            $m = '';
            if ($staff) {
                $totals = Langame::totalsByEmployee($month['from'], $month['to'], $b['id']);
                $unlinked = Langame::unlinkedRevenue($b['id'], $month['from'], $month['to']);
                $m .= card_head('Администраторы Langame', 'Пустой процент — ставка филиала', '', 'users');
                if ($unlinked > 0) {
                    $m .= '<p class="mt-4 rounded-md border border-primary/30 bg-primary/10 px-3 py-2 text-sm text-primary-ink">За ' . h($month['name']) . ' ' . h(kop_rub($unlinked)) . ' продаж ни к кому не привязано — проверьте список ниже.</p>';
                }
                $m .= form_open('link_langame_staff', 'class="mt-4 flex flex-col gap-4"') . hidden('branchId', $b['id']);
                $lastShift = Langame::lastShiftByStaff($b['id']);
                $recentCut = now_tyumen()->modify('-90 days')->format('Y-m-d');
                $hasShiftData = $lastShift !== [];
                $main = [];
                $rest = [];
                foreach ($staff as $st) {
                    $lid = (int) $st['langameUserId'];
                    $recent = isset($lastShift[$lid]) && strcmp($lastShift[$lid], $recentCut) >= 0;
                    $flagActive = boolish($st['isActive'] ?? 1) && !Langame::isPlaceholder($st);
                    $show = $st['employeeId'] !== null || $recent || (!$hasShiftData && $flagActive);
                    if ($show) {
                        $main[] = $st;
                    } else {
                        $rest[] = $st;
                    }
                }
                usort($main, static fn ($x, $y) => ($y['employeeId'] !== null) <=> ($x['employeeId'] !== null) ?: strcmp((string) ($lastShift[(int) $y['langameUserId']] ?? ''), (string) ($lastShift[(int) $x['langameUserId']] ?? '')));
                $renderRow = static function (array $st) use ($totals, $empOptions, $s, $lastShift): string {
                    $lid = (int) $st['langameUserId'];
                    $eidL = $st['employeeId'] !== null ? (int) $st['employeeId'] : null;
                    $t = $eidL !== null ? ($totals[$eidL] ?? null) : null;
                    $label = (string) ($st['langameName'] ?: 'ID ' . $lid);
                    $login = (string) ($st['langameLogin'] ?: 'ID ' . $lid);
                    $meta = [$login];
                    if (!empty($st['adminStatus'])) {
                        $meta[] = (string) $st['adminStatus'];
                    }
                    $meta[] = isset($lastShift[$lid]) ? 'смена ' . substr($lastShift[$lid], 8, 2) . '.' . substr($lastShift[$lid], 5, 2) . '.' . substr($lastShift[$lid], 2, 2) : 'смен не было';
                    $out = '<li class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-end" data-filter-text="' . h(mb_strtolower($label . ' ' . $login)) . '">';
                    $out .= '<div class="flex min-w-0 flex-col gap-0.5 sm:w-52 sm:pb-2"><span class="flex items-center gap-2 truncate text-sm font-semibold">' . h($label) . ($eidL !== null ? chip('привязан', 'ink') : '') . '</span><span class="truncate text-xs text-muted-foreground">' . h(implode(' · ', $meta)) . ($t ? ' · ' . h(kop_rub($t['revenue'])) : '') . '</span></div>';
                    $out .= ui_field('Сотрудник портала', ui_select('link[' . $lid . ']', $empOptions, $eidL !== null ? (string) $eidL : '', 'aria-label="Сотрудник для ' . h($label) . '"'), '', 'min-w-0 flex-1');
                    $pv = $st['percentX10'] !== null ? rtrim(rtrim(number_format(((int) $st['percentX10']) / 10, 1, '.', ''), '0'), '.') : '';
                    $out .= ui_field('Свой %', '<input name="pct[' . $lid . ']" type="number" inputmode="decimal" min="0" max="100" step="0.1" class="' . field_class('tabular-nums') . '" placeholder="' . h(pct_label((int) ($s['barPercentX10'] ?? 0))) . '" value="' . h($pv) . '">', '', 'w-full sm:w-24');
                    return $out . '</li>';
                };
                $listId = 'lg-staff-' . $b['id'];
                $m .= '<label class="relative block"><span class="sr-only">Поиск по учёткам Langame</span><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-muted-foreground">' . icon('search', 'size-4') . '</span><input type="search" class="' . field_class('pl-9') . '" placeholder="Найти по имени или логину" autocomplete="off" data-filter-input="' . h($listId) . '"></label>';
                $m .= '<div id="' . h($listId) . '" class="flex flex-col gap-4">';
                $m .= '<p class="text-xs font-semibold tracking-wide text-muted-foreground uppercase">Действующие · ' . count($main) . '</p>';
                $m .= $main ? '<ul class="flex flex-col divide-y divide-border">' . implode('', array_map($renderRow, $main)) . '</ul>' : '<p class="text-sm text-muted-foreground">Не нашли действующих — раскройте список ниже.</p>';
                if ($rest) {
                    $m .= '<details class="group rounded-lg border border-border" data-filter-details><summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-medium [&::-webkit-details-marker]:hidden"><span>Неактивные, удалённые и служебные · ' . count($rest) . '</span><span class="text-muted-foreground transition-transform group-open:rotate-180">' . icon('chevrondown', 'size-4') . '</span></summary>';
                    $m .= '<ul class="flex max-h-[28rem] flex-col divide-y divide-border overflow-y-auto border-t border-border px-4 py-3 scrollbar-thin">' . implode('', array_map($renderRow, $rest)) . '</ul></details>';
                }
                $m .= '</div><button class="' . btn_class() . ' sm:self-start">' . icon('save', 'size-4') . 'Сохранить привязки</button></form>';
            } elseif ($hasToken) {
                $m .= empty_state('users', 'Администраторов пока нет', 'Нажмите «Синхронизировать» — портал подтянет список учёток из Langame.');
            }

            $html .= '<section class="grid items-start gap-6 xl:grid-cols-[28rem_minmax(0,1fr)]">' . card($c, 'p-5') . ($m !== '' ? card($m, 'p-5') : '<div class="hidden xl:block"></div>') . '</section>';
        }

        $html .= '<p class="text-xs leading-relaxed text-muted-foreground text-pretty">Продажа засчитывается тому, на чьей смене в Langame она пробита. Отменённые продажи не учитываются. Процент считается от суммы продажи и автоматически добавляется к начислениям в разделе «Уровни и ставки».</p>';

        // Токен Langame уже в БД (из SQL). Cron-ключ — отдельный пароль только для автообновления на хостинге; создаём сами.
        $keyFile = dirname(__DIR__, 2) . '/storage/cron.key';
        $cronKey = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
        if ($cronKey === '') {
            $cronKey = bin2hex(random_bytes(16));
            @file_put_contents($keyFile, $cronKey . "\n", LOCK_EX);
            @chmod($keyFile, 0600);
        }
        $cronUrl = (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'ваш-домен') . url_to('/cron/langame', ['key' => $cronKey]);
        $cron = card_head('Автообновление на хостинге', 'Токен Langame из базы трогать не нужно', '', 'clock');
        $cron .= '<p class="mt-3 text-sm leading-relaxed text-muted-foreground">Это <strong>не</strong> токен Langame (он уже сохранён в SQL). Это одноразовый URL для панели reg.ru → Cron, чтобы сайт сам подтягивал продажи каждые 5 минут. Один раз скопировали — больше ничего вписывать не надо. Даже без cron вкладки открываются сразу: данные догоняют в фоне.</p>';
        $cron .= '<label class="mt-4 block text-sm font-medium">Готовый URL — вставьте в Cron</label>';
        $cron .= '<input class="' . field_class('mt-1.5 font-mono text-xs') . '" readonly value="' . h($cronUrl) . '" onclick="this.select()">';
        $cron .= '<p class="mt-2 text-xs text-muted-foreground">В reg.ru: «каждые 5 минут» → <code class="rounded bg-muted px-1">curl -fsS "…"</code></p>';
        $cron .= form_open('init_langame_cron', 'class="mt-4"') . '<button class="' . btn_class('quiet', 'sm') . '">' . icon('refresh', 'size-4') . 'Перевыпустить URL</button></form>';
        $html .= card($cron, 'p-5');

        self::layout($user, $html, ['title' => 'Подключение Langame', 'width' => 'wide']);
    }
}
