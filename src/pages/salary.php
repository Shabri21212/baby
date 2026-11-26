<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/payroll.php';

trait PagesSalary
{
    private static function pageSalary(array $user): void
    {
        $month = self::monthFromQuery();
        $eid = (int) ($user['employeeId'] ?? 0);
        $isOwner = !empty($user['isOwner']);
        $viewEid = $isOwner ? (int) ($_GET['employee'] ?? 0) : 0;
        if ($isOwner && ($viewEid > 0 || $eid === 0)) {
            self::pageSalaryOwner($user, $month, $viewEid);
            return;
        }
        // Страница сразу из кэша CRM; Langame обновляется кнопкой или каждые 5 минут в фоне.
        $langameBranches = [];
        $lastSyncHint = '';
        if ($eid) {
            foreach (Db::all('SELECT DISTINCT l."branchId", s."lastSyncAt" FROM langame_staff l LEFT JOIN langame_settings s ON s."branchId" = l."branchId" WHERE l."employeeId" = ?', [$eid]) as $link) {
                $bid = (int) $link['branchId'];
                if ($bid > 0) {
                    $langameBranches[] = $bid;
                }
                if (!empty($link['lastSyncAt']) && ($lastSyncHint === '' || (string) $link['lastSyncAt'] > $lastSyncHint)) {
                    $lastSyncHint = (string) $link['lastSyncAt'];
                }
            }
            $langameBranches = array_values(array_unique($langameBranches));
        }
        $data = $eid ? Payroll::employee($eid, $month['key']) : null;
        if (!$data) {
            $action = can($user, 'staff.manage') ? '<a class="' . btn_class('outline') . '" href="' . h(url_to('/bar', ['tab' => 'salary'])) . '">Ведомость сотрудников</a>' : '';
            self::layout($user, page_head('Моя зарплата') . empty_state('wallet', 'Нет привязанной карточки сотрудника', 'Для личного расчёта владелец должен связать ваш доступ с карточкой сотрудника.', $action), ['title' => 'Моя зарплата']);
            return;
        }
        $selected = self::getStr('period', 1);
        $period = in_array($selected, ['1', '2'], true) ? (int) $selected : ($month['isCurrent'] && (int) $data['at']->format('j') <= 15 ? 1 : 2);
        $p = $data['periods'][$period];
        $nav = '<div class="flex flex-wrap items-center gap-2"><a class="' . btn_class('outline', 'icon-sm') . '" aria-label="Предыдущий месяц" href="' . h(url_to('/salary', ['m' => $month['prev'], 'period' => $period])) . '">' . icon('chevronleft') . '</a><span class="text-sm font-semibold">' . h($month['title']) . '</span><a class="' . btn_class('outline', 'icon-sm') . '" aria-label="Следующий месяц" href="' . h(url_to('/salary', ['m' => $month['next'], 'period' => $period])) . '">' . icon('chevronright') . '</a>';
        if ($langameBranches && $month['isCurrent']) {
            $nav .= '<button type="button" class="' . btn_class('outline', 'sm') . '" data-langame-refresh data-force="1" title="Подтянуть продажи из Langame">' . icon('refresh', 'size-3.5') . 'Обновить продажи</button>';
        }
        $nav .= '</div>';
        $html = page_head('Моя зарплата', '', $nav, 'Личное');
        $html .= '<p class="text-xs text-muted-foreground" data-langame-status' . ($lastSyncHint !== '' ? '' : ' hidden') . '>';
        $html .= $lastSyncHint !== '' ? 'Продажи Langame: ' . h(format_dt($lastSyncHint)) . ' · автообновление каждые 5 мин' : 'Обновляем продажи из Langame…';
        $html .= '</p>';
        $html .= segmented([
            ['href' => url_to('/salary', ['m' => $month['key'], 'period' => 1]), 'label' => '1–15 · ставка', 'active' => $period === 1],
            ['href' => url_to('/salary', ['m' => $month['key'], 'period' => 2]), 'label' => '16–' . substr($month['to'], 8, 2) . ' · ставка + процент', 'active' => $period === 2],
        ], 'Период начисления');
        $hero = '<div class="flex flex-col gap-5"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-sm text-muted-foreground">Расчётно к выплате · ' . h(format_day_month($p['from']) . ' — ' . format_day_month($p['to'])) . '</p><p class="mt-2 font-display text-3xl font-semibold tracking-tight tabular-nums sm:text-4xl">' . h(money_rub($p['total'])) . '</p></div>' . chip('На ' . $data['at']->format('d.m H:i'), 'outline', 'clock') . '</div>';
        $hero .= '<p class="text-sm text-muted-foreground">Началось ' . $p['units'] . ' из ' . $p['planned'] . ' смен по графику. Сутки считаются за две.</p>';
        $hero .= progress_bar($p['planned'] ? (int) round($p['units'] / $p['planned'] * 100) : 0, 'Доля начавшихся смен');
        // Процент копится с первого числа и выплачивается со второй половиной, поэтому виден в обоих периодах.
        $bar = $data['periods'][2];
        $hero .= '<div class="grid gap-4 border-t border-border pt-4 sm:grid-cols-3">';
        foreach ([
            ['За смены', money_rub($p['gross']), ''],
            ['Процент с продаж', money_rub($bar['bar'] + $bar['mgrBar']), ($bar['mgrBar'] > 0 ? 'в т.ч. ' . money_rub($bar['mgrBar']) . ' как управляющему · ' : '') . ($period === 1 ? 'за месяц · войдёт в выплату 16–' . substr($month['to'], 8, 2) : 'за весь месяц, с 1-го числа')],
            ['Штрафы', money_rub($p['fines']), ''],
        ] as [$label, $value, $note]) {
            $hero .= '<div><p class="text-sm text-muted-foreground">' . h($label) . '</p><p class="mt-1 text-lg font-semibold tabular-nums">' . h($value) . '</p>' . ($note !== '' ? '<p class="mt-1 text-xs leading-relaxed text-muted-foreground">' . h($note) . '</p>' : '') . '</div>';
        }
        $hero .= '</div></div>';
        $html .= card($hero, 'p-5 sm:p-6');
        $html .= self::payDetailsCard($eid);
        $html .= self::salaryBarCard($bar, $month);
        if ($bar['mgrBar'] > 0) {
            $html .= self::salaryMgrBarCard($bar);
        }
        if ($data['warnings']) {
            $html .= '<div class="rounded-lg border border-border bg-muted p-4 text-sm text-foreground" role="status"><p class="font-semibold">Процент может быть неполным</p><ul class="mt-2 list-inside list-disc">';
            foreach ($data['warnings'] as $warning) {
                $html .= '<li>' . h($warning) . '</li>';
            }
            $html .= '</ul><p class="mt-2">Обновите продажи в разделе «Продажи и аналитика».</p></div>';
        }
        $html .= self::salaryFinesCard($p['deductions'], $p['fines']);
        $body = card_head('Смены и начисления', 'За запланированные смены: ' . h(money_rub($p['future'])), '', 'calendar');
        if (!$p['shifts']) {
            $body .= '<p class="mt-4 text-sm text-muted-foreground">В этом периоде смен пока нет.</p>';
        } else {
            $body .= self::salaryShiftList($p['shifts']);
        }
        $html .= card($body, 'p-5');
        self::layout($user, $html, [
            'title' => 'Моя зарплата',
            'width' => 'narrow',
            'langameBranches' => $langameBranches,
            'langamePollMs' => $langameBranches && $month['isCurrent'] ? 300000 : 0,
            'langameReload' => true,
        ]);
    }

    /** Из чего сложился процент с продаж: выручка бара, ставка и начисление по каждому филиалу. */
    private static function salaryBarCard(array $bar, array $month): string
    {
        $link = '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/bar', ['m' => $month['key'], 'view' => 'me'])) . '">Мои продажи ' . icon('arrow') . '</a>';
        $body = card_head('Процент с продаж', 'Копится весь месяц, с 1-го числа. Выплачивается вместе со второй половиной.', $link, 'percent');
        if (!$bar['barByBranch']) {
            return card($body . '<p class="mt-4 text-sm leading-relaxed text-muted-foreground">За ' . h($month['name']) . ' продаж бара на ваших сменах пока нет. Они появятся через несколько минут после того, как вы пробьёте товар в Langame.</p>', 'p-5');
        }
        $body .= '<ul class="mt-4 divide-y divide-border">';
        foreach ($bar['barByBranch'] as $row) {
            $body .= '<li class="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0"><div class="min-w-0"><p class="text-sm font-semibold">' . h($row['branchName']) . '</p>';
            $body .= '<p class="mt-1 text-sm text-muted-foreground">' . h(pct_label((int) $row['pct'])) . ' с продаж на <span class="tabular-nums">' . h(money_rub($row['revenue'])) . '</span></p></div>';
            $body .= '<span class="shrink-0 text-sm font-semibold tabular-nums">+' . h(money_rub($row['earned'])) . '</span></li>';
        }
        $body .= '</ul>';
        if (count($bar['barByBranch']) > 1) {
            $body .= '<p class="mt-4 flex items-center justify-between gap-4 border-t border-border pt-4 text-sm font-semibold"><span>Итого за месяц</span><span class="tabular-nums">+' . h(money_rub($bar['bar'])) . '</span></p>';
        }
        return card($body, 'p-5');
    }

    private static function salaryFinesCard(array $fines, int $total): string
    {
        if (!$fines) {
            return '';
        }
        $body = card_head('Назначенные штрафы', h('Удержания за период: ' . money_rub($total)), '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/fines')) . '">Подробнее ' . icon('arrow') . '</a>', 'scale');
        $body .= '<ul class="mt-4 divide-y divide-border">';
        foreach ($fines as $fine) {
            $body .= '<li class="flex items-start justify-between gap-4 py-3"><div class="min-w-0"><p class="text-sm leading-relaxed break-words">' . h((string) $fine['reason']) . '</p><p class="mt-1 text-sm text-muted-foreground">' . h(format_dt((string) $fine['createdAt'])) . '</p></div><span class="shrink-0 text-sm font-semibold text-primary-ink tabular-nums">−' . h(money_rub((int) $fine['amount'])) . '</span></li>';
        }
        return card($body . '</ul>', 'p-5');
    }

    /** Список смен периода: обычные смены графика и отметки присутствия управляющего. */
    private static function salaryShiftList(array $shifts): string
    {
        $html = '<ul class="mt-4 divide-y divide-border">';
        foreach ($shifts as $s) {
            $isPresence = $s['shiftType'] === 'presence';
            $title = format_day_month((string) $s['date']) . ' · ' . ($isPresence ? 'Присутствие в клубе' : SHIFT_LABELS[$s['shiftType']]);
            $hint = $isPresence
                ? $s['branchName'] . ' · ' . $s['startTime'] . '–' . $s['endTime']
                : $s['branchName'] . ' · ' . shift_hint((string) $s['shiftType'], (int) $s['branchId']);
            $state = $s['state'] === 'live' ? 'Смена идёт · начислено' : ($s['started'] ? 'Начислено' : 'Запланировано · не начислено');
            $html .= '<li class="flex items-start justify-between gap-4 py-4"><div class="min-w-0"><p class="flex items-center gap-2 text-sm font-semibold">' . ($isPresence ? icon('briefcase', 'size-3.5 text-primary-ink') : '') . h($title) . '</p>';
            $html .= '<p class="mt-1 text-sm text-muted-foreground">' . h($hint) . '</p>';
            if ($isPresence && trim((string) $s['activity']) !== '') {
                $html .= '<p class="mt-1.5 text-sm leading-relaxed text-pretty break-words">' . h((string) $s['activity']) . '</p>';
            }
            $html .= '<p class="mt-1 text-sm text-muted-foreground">' . h($state) . '</p></div>';
            $html .= '<div class="shrink-0 text-right"><p class="text-sm font-semibold tabular-nums">' . h(money_rub($s['started'] ? $s['amount'] : 0)) . '</p><p class="mt-1 text-sm text-muted-foreground">' . h(money_rub($s['rate'])) . ($s['shiftType'] === 'full' ? ' × 2' : ' / смена') . '</p></div></li>';
        }
        return $html . '</ul>';
    }

    /** Ведомость владельца: все сотрудники месяца и подробный разбор по одному. */
    private static function pageSalaryOwner(array $user, array $month, int $viewEid): void
    {
        $emps = Db::all('SELECT e.id, e.name, e."homeBranchId", e."isActive", u.role FROM employees e LEFT JOIN "user" u ON u.id = e."userId" WHERE e."isActive" = 1 ORDER BY e."sortOrder", e.name');
        $nav = '<div class="flex flex-wrap items-center gap-2"><a class="' . btn_class('outline', 'icon-sm') . '" aria-label="Предыдущий месяц" href="' . h(url_to('/salary', ['m' => $month['prev'], 'employee' => $viewEid ?: null])) . '">' . icon('chevronleft') . '</a><span class="text-sm font-semibold">' . h($month['title']) . '</span><a class="' . btn_class('outline', 'icon-sm') . '" aria-label="Следующий месяц" href="' . h(url_to('/salary', ['m' => $month['next'], 'employee' => $viewEid ?: null])) . '">' . icon('chevronright') . '</a></div>';
        $rows = [];
        foreach ($emps as $e) {
            $d = Payroll::employee((int) $e['id'], $month['key']);
            if ($d) {
                $rows[(int) $e['id']] = $d + ['role' => (string) ($e['role'] ?? '')];
            }
        }
        if ($viewEid > 0 && isset($rows[$viewEid])) {
            self::salaryOwnerDetail($user, $month, $rows[$viewEid], $nav, $emps, $viewEid);
            return;
        }
        $html = page_head('Зарплаты', 'Расчёт по графику, присутствию управляющих, проценту с бара и штрафам. Нажмите на сотрудника — откроется подробный разбор.', $nav, 'Владелец');
        $sum = ['p1' => 0, 'p2' => 0, 'bar' => 0, 'fines' => 0];
        foreach ($rows as $d) {
            $sum['p1'] += $d['periods'][1]['total'];
            $sum['p2'] += $d['periods'][2]['total'];
            $sum['bar'] += $d['periods'][2]['bar'];
            $sum['fines'] += $d['periods'][1]['fines'] + $d['periods'][2]['fines'];
        }
        $stats = '<div class="grid gap-4 sm:grid-cols-4">';
        foreach ([['Фонд за 1–15', $sum['p1']], ['Фонд за 16–' . substr($month['to'], 8, 2), $sum['p2']], ['Процент с бара', $sum['bar']], ['Штрафы', $sum['fines']]] as [$label, $value]) {
            $stats .= '<div><p class="text-sm text-muted-foreground">' . h($label) . '</p><p class="mt-1 font-display text-xl font-semibold tabular-nums">' . h(money_rub($value)) . '</p></div>';
        }
        $html .= card($stats . '</div>', 'p-5');
        if (!$rows) {
            $html .= empty_state('users', 'Нет активных сотрудников', 'Добавьте сотрудников в разделе «Сотрудники».');
        } else {
            $list = '<ul class="divide-y divide-border">';
            foreach ($rows as $id => $d) {
                $e = $d['employee'];
                $month1 = $d['periods'][1];
                $month2 = $d['periods'][2];
                $units = $month1['units'] + $month2['units'];
                $planned = $month1['planned'] + $month2['planned'];
                $total = $month1['total'] + $month2['total'];
                $roleTag = $d['role'] === 'manager' ? chip('Управляющий', 'outline', 'briefcase') : '';
                $list .= '<li><a class="flex items-center justify-between gap-4 px-5 py-4 transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none" href="' . h(url_to('/salary', ['m' => $month['key'], 'employee' => $id])) . '">';
                $list .= '<div class="min-w-0"><p class="flex flex-wrap items-center gap-2 text-sm font-semibold">' . h((string) $e['name']) . $roleTag . '</p>';
                $list .= '<p class="mt-1 text-sm text-muted-foreground">' . h(($e['gradeTitle'] ?? 'Без грейда') . ' · ' . $units . ' из ' . $planned . ' смен · бар +' . money_rub($month2['bar'])) . '</p></div>';
                $list .= '<div class="flex shrink-0 items-center gap-3"><div class="text-right"><p class="text-sm font-semibold tabular-nums">' . h(money_rub($total)) . '</p><p class="mt-0.5 text-xs text-muted-foreground tabular-nums">' . h(money_rub($month1['total']) . ' + ' . money_rub($month2['total'])) . '</p></div>' . icon('chevronright', 'size-4 text-muted-foreground') . '</div></a></li>';
            }
            $html .= card($list . '</ul>', 'p-0 overflow-hidden');
        }
        self::layout($user, $html, ['title' => 'Зарплаты', 'width' => 'narrow']);
    }

    private static function salaryOwnerDetail(array $user, array $month, array $data, string $nav, array $emps, int $viewEid): void
    {
        $e = $data['employee'];
        $opts = [];
        foreach ($emps as $row) {
            $opts[(string) $row['id']] = (string) $row['name'];
        }
        $picker = '<form method="get" action="/salary" class="flex items-center gap-2">' . hidden('m', $month['key']) . ui_select('employee', $opts, (string) $viewEid, 'aria-label="Сотрудник" onchange="this.form.submit()"') . '<noscript><button class="' . btn_class('outline', 'sm') . '">Открыть</button></noscript></form>';
        $back = '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/salary', ['m' => $month['key']])) . '">' . icon('chevronleft', 'size-3.5') . 'Все сотрудники</a>';
        $html = page_head((string) $e['name'], h(($e['gradeTitle'] ?? 'Без грейда') . ' · ставка ' . money_rub((int) ($e['shiftRate'] ?? 0)) . ' за смену'), '<div class="flex flex-wrap items-center gap-2">' . $back . $picker . $nav . '</div>', 'Зарплата сотрудника');
        $p1 = $data['periods'][1];
        $p2 = $data['periods'][2];
        $allShifts = array_merge($p1['shifts'], $p2['shifts']);
        $presence = array_values(array_filter($allShifts, static fn ($s) => $s['shiftType'] === 'presence'));
        $byType = [];
        foreach ($allShifts as $s) {
            if ($s['started']) {
                $byType[$s['shiftType']] = ($byType[$s['shiftType']] ?? 0) + 1;
            }
        }
        $hero = '<div class="grid gap-5 sm:grid-cols-3">';
        foreach ([[1, $p1], [2, $p2]] as [$k, $p]) {
            $hero .= '<div><p class="text-sm text-muted-foreground">' . h(format_day_month($p['from']) . ' — ' . format_day_month($p['to'])) . '</p><p class="mt-1 font-display text-2xl font-semibold tabular-nums">' . h(money_rub($p['total'])) . '</p><p class="mt-1 text-xs leading-relaxed text-muted-foreground">' . h('смены ' . money_rub($p['gross']) . ($k === 2 ? ' · бар +' . money_rub($p['bar']) : '') . ($p['fines'] ? ' · штрафы −' . money_rub($p['fines']) : '')) . '</p></div>';
        }
        $hero .= '<div><p class="text-sm text-muted-foreground">Итого за месяц</p><p class="mt-1 font-display text-2xl font-semibold tabular-nums text-primary-ink">' . h(money_rub($p1['total'] + $p2['total'])) . '</p><p class="mt-1 text-xs text-muted-foreground">' . h('запланировано ещё ' . money_rub($p1['future'] + $p2['future'])) . '</p></div></div>';
        $mix = [];
        foreach ($byType as $type => $n) {
            $mix[] = ($type === 'presence' ? 'присутствие' : mb_strtolower(SHIFT_LABELS[$type] ?? $type)) . ' — ' . $n;
        }
        if ($mix) {
            $hero .= '<p class="mt-5 border-t border-border pt-4 text-sm text-muted-foreground">Отработано: ' . h(implode(', ', $mix)) . '</p>';
        }
        $html .= card($hero, 'p-5 sm:p-6');
        if ($data['warnings']) {
            $html .= '<div class="rounded-lg border border-border bg-muted p-4 text-sm" role="status"><p class="font-semibold">Процент может быть неполным</p><ul class="mt-2 list-inside list-disc">';
            foreach ($data['warnings'] as $w) {
                $html .= '<li>' . h($w) . '</li>';
            }
            $html .= '</ul></div>';
        }
        $html .= self::salaryBarCard($p2, $month);
        if ($presence) {
            $minutes = 0;
            foreach ($presence as $s) {
                $minutes += presence_minutes((string) $s['startTime'], (string) $s['endTime']);
            }
            $body = card_head('Присутствие в клубе', h(plural(count($presence), 'отметка', 'отметки', 'отметок') . ' · ' . presence_hours_label($minutes)), '', 'briefcase');
            $html .= card($body . self::salaryShiftList($presence), 'p-5');
        }
        $html .= self::salaryFinesCard(array_merge($p1['deductions'], $p2['deductions']), $p1['fines'] + $p2['fines']);
        $work = array_values(array_filter($allShifts, static fn ($s) => $s['shiftType'] !== 'presence'));
        $body = card_head('Смены по графику', h(plural(count($work), 'смена', 'смены', 'смен') . ' за месяц'), '', 'calendar');
        $html .= card($body . ($work ? self::salaryShiftList($work) : '<p class="mt-4 text-sm text-muted-foreground">В этом месяце смен по графику нет.</p>'), 'p-5');
        self::layout($user, $html, ['title' => 'Зарплата · ' . $e['name'], 'width' => 'narrow']);
    }

    /** Анкета для перевода: телефон по СБП и банк. Видит и заполняет сам сотрудник. */
    private static function payDetailsCard(int $eid): string
    {
        $e = Db::one('SELECT "payPhone", "payBank", "payNote", "payUpdatedAt" FROM employees WHERE id = ?', [$eid]);
        if (!$e) {
            return '';
        }
        $phone = (string) ($e['payPhone'] ?? '');
        $bank = (string) ($e['payBank'] ?? '');
        $filled = $phone !== '' && $bank !== '';
        $sub = $filled
            ? 'Переводим на ' . h(format_phone($phone)) . ' · ' . h($bank)
            : 'Заполните, чтобы зарплату перевели без уточнений в личке';
        $badge = $filled ? chip('Заполнено', 'outline', 'check') : chip('Не заполнено', 'brand', 'alert');
        $body = '<div class="flex flex-wrap items-start justify-between gap-3">' . card_head('Куда переводить зарплату', $sub, '', 'wallet') . $badge . '</div>';
        $banks = ['' => 'Выберите банк'];
        foreach (Payroll::BANKS as $b) {
            $banks[$b] = $b;
        }
        $f = form_open('save_pay_details', 'class="mt-4 flex flex-col gap-4" data-submit-once');
        $f .= '<div class="grid gap-4 sm:grid-cols-2">';
        $f .= ui_field('Телефон для СБП', '<input name="payPhone" type="tel" inputmode="tel" autocomplete="tel" class="' . field_class() . '" maxlength="18" placeholder="+7 900 000-00-00" value="' . h($phone !== '' ? format_phone($phone) : '') . '" required>', 'Номер, к которому привязан банк');
        $f .= ui_field('Банк', ui_select('payBank', $banks, $bank, 'required'));
        $f .= '</div>';
        $f .= ui_field('Комментарий', '<input name="payNote" class="' . field_class() . '" maxlength="191" placeholder="Например: получатель — Иванова М., мама" value="' . h((string) ($e['payNote'] ?? '')) . '">', 'Если карта не на ваше имя — укажите получателя');
        $f .= '<div class="flex flex-wrap items-center justify-between gap-3"><p class="text-xs text-muted-foreground">' . (!empty($e['payUpdatedAt']) ? 'Обновлено ' . h(format_dt((string) $e['payUpdatedAt'], false)) : 'Видят только вы и тот, кто делает переводы') . '</p>';
        $f .= '<button class="' . btn_class($filled ? 'outline' : 'primary') . '">' . icon('save', 'size-4') . 'Сохранить</button></div></form>';
        return card($body . $f, 'p-5');
    }

    /** Процент управляющего с общей выручки бара — показывается только самому управляющему и владельцу. */
    private static function salaryMgrBarCard(array $bar): string
    {
        $pct = rtrim(rtrim(number_format($bar['mgrBarPct'] / 10, 1, ',', ''), '0'), ',');
        $body = card_head('Процент управляющего', 'Общие продажи бара за весь месяц · ' . h($pct) . '%', '', 'percent');
        $body .= '<ul class="mt-4 flex flex-col divide-y divide-border border-t border-border">';
        foreach ($bar['mgrBarByBranch'] as $r) {
            $body .= '<li class="flex items-baseline justify-between gap-3 py-2.5 text-sm"><span>' . h($r['branchName']) . ' <span class="text-muted-foreground">· выручка ' . h(money_rub($r['revenue'])) . '</span></span><span class="font-semibold tabular-nums">+' . h(money_rub($r['earned'])) . '</span></li>';
        }
        $body .= '<li class="flex items-baseline justify-between gap-3 py-2.5 text-sm font-semibold"><span>Итого · выручка ' . h(money_rub($bar['mgrBarRev'])) . '</span><span class="tabular-nums">+' . h(money_rub($bar['mgrBar'])) . '</span></li></ul>';
        $body .= '<p class="mt-2 text-xs leading-relaxed text-muted-foreground">Выплачивается вместе со второй половиной месяца. Администраторы эту сумму не видят.</p>';
        return card($body, 'p-5');
    }

    private static function personalPayCard(array $user): string
    {
        $eid = (int) ($user['employeeId'] ?? 0);
        $now = now_tyumen();
        $data = $eid ? Payroll::employee($eid, $now->format('Y-m'), $now) : null;
        if (!$data) {
            return '';
        }
        $period = (int) $now->format('j') <= 15 ? 1 : 2;
        $p = $data['periods'][$period];
        $body = card_head('Моя зарплата', h(format_day_month($p['from']) . ' — ' . format_day_month($p['to'])), '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/salary')) . '">Расчёт ' . icon('arrow') . '</a>', 'wallet');
        $body .= '<p class="mt-4 font-display text-2xl font-semibold tabular-nums">' . h(money_rub($p['total'])) . '</p><p class="mt-2 text-sm text-muted-foreground">Начислено за ' . h(plural($p['units'], 'смену', 'смены', 'смен')) . ($period === 2 && $p['bar'] > 0 ? ' и процент с продаж' : '') . '.</p>';
        $monthBar = (int) $data['periods'][2]['bar'] + (int) $data['periods'][2]['mgrBar'];
        if ($period === 1 && $monthBar > 0) {
            $body .= '<p class="mt-3 flex items-baseline justify-between gap-3 border-t border-border pt-3 text-sm"><span class="text-muted-foreground">Процент с продаж за месяц</span><span class="font-semibold tabular-nums">+' . h(money_rub($monthBar)) . '</span></p><p class="mt-1 text-xs leading-relaxed text-muted-foreground">Войдёт в выплату за вторую половину.</p>';
        }
        $fines = array_merge($data['periods'][1]['deductions'], $data['periods'][2]['deductions']);
        return card($body, 'p-5') . self::salaryFinesCard($fines, $data['periods'][1]['fines'] + $data['periods'][2]['fines']);
    }
}
