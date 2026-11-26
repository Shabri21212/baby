<?php
declare(strict_types=1);

trait PagesGuests
{
    private static function guestStatusTabs(string $path, string $status, array $cnt, array $q): string
    {
        $tabs = [
            'all' => ['label' => 'Все', 'href' => url_to($path, $q), 'count' => array_sum($cnt)],
            'pending' => ['label' => 'Ждут решения', 'href' => url_to($path, $q + ['status' => 'pending']), 'count' => $cnt['pending'] ?? 0],
            'credited' => ['label' => 'Зачислено', 'href' => url_to($path, $q + ['status' => 'credited']), 'count' => $cnt['credited'] ?? 0],
            'rejected' => ['label' => 'Отказ', 'href' => url_to($path, $q + ['status' => 'rejected']), 'count' => $cnt['rejected'] ?? 0],
        ];
        return tabs_nav($tabs, $status, 'Статус');
    }

    /** Колонки журнала, по которым ищется текст (номер телефона ищется всегда). Заодно это список допустимых таблиц. */
    private static function guestTextCols(string $table): array
    {
        return match ($table) {
            'review_bonuses' => ['note'],
            'compensations' => ['reason', 'actionsTaken'],
        };
    }

    /**
     * Одна страница журнала гостей с учётом филиалов, статуса и поиска. $branchIds = null — все филиалы.
     * Без поиска страница берётся из базы через LIMIT/OFFSET. С поиском строки отбирает guest_row_matches() в PHP:
     * регистр кириллицы SQLite и MySQL сравнивают по-разному, а журнал — это сотни строк, а не миллионы.
     * Счётчики по статусам считаются с теми же филиалом и поиском: вкладки показывают, где лежит найденное.
     *
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, total: int, page: int, pages: int}
     */
    private static function guestJournal(string $table, ?array $branchIds, string $status, string $search, int $page, int $perPage = GUEST_PAGE_SIZE): array
    {
        $textCols = self::guestTextCols($table);
        $perPage = max(1, $perPage);
        $scope = $branchIds !== null ? ' WHERE t."branchId" IN (' . self::idList($branchIds) . ')' : '';
        $params = [];
        // id в сортировке обязателен: у перенесённых строк время совпадает, и без него они «прыгали» бы между страницами.
        $order = ' ORDER BY t."createdAt" DESC, t.id DESC';
        $select = 'SELECT t.*, b.name AS "branchName" FROM ' . $table . ' t LEFT JOIN branches b ON b.id = t."branchId"';
        $needle = guest_search_needle($search);
        $counts = [];
        $found = null;

        if ($needle['text'] === '') {
            foreach (Db::all('SELECT t.status, COUNT(*) AS n FROM ' . $table . ' t' . $scope . ' GROUP BY t.status', $params) as $r) {
                $counts[(string) $r['status']] = (int) $r['n'];
            }
            $total = $status === 'all' ? array_sum($counts) : ($counts[$status] ?? 0);
        } else {
            $cols = 't.id, t.status, t."guestPhone"';
            foreach ($textCols as $col) {
                $cols .= ', t."' . $col . '"';
            }
            $found = [];
            foreach (Db::all('SELECT ' . $cols . ' FROM ' . $table . ' t' . $scope . $order, $params) as $r) {
                if (!guest_row_matches($r, $needle, $textCols)) {
                    continue;
                }
                $rowStatus = (string) $r['status'];
                $counts[$rowStatus] = ($counts[$rowStatus] ?? 0) + 1;
                if ($status === 'all' || $rowStatus === $status) {
                    $found[] = (int) $r['id'];
                }
            }
            $total = count($found);
        }

        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;
        if ($total === 0) {
            $rows = [];
        } elseif ($found !== null) {
            $rows = Db::all($select . ' WHERE t.id IN (' . implode(',', array_slice($found, $offset, $perPage)) . ')' . $order);
        } else {
            if ($status !== 'all') {
                $scope .= ($scope === '' ? ' WHERE ' : ' AND ') . 't.status = ?';
                $params[] = $status;
            }
            $rows = Db::all($select . $scope . $order . ' LIMIT ' . $perPage . ' OFFSET ' . $offset, $params);
        }
        return ['rows' => $rows, 'counts' => $counts, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Журнал гостей для страницы: читает ?status=, ?branch=, ?q= и ?page=, собирает вкладки, поиск и листание.
     * Поиск и листание ведут на #journal: на телефоне журнал стоит под формой, и без якоря
     * после каждого перехода страницу пришлось бы прокручивать заново.
     *
     * Сотрудник видит заявки только своих филиалов: остальные не попадают ни в список, ни в счётчики.
     *
     * @return array{rows: list<array<string, mixed>>, search: string, tabs: string, branchNav: string, searchForm: string, pager: string}
     */
    private static function guestJournalView(array $user, string $table, string $path, string $placeholder): array
    {
        $status = self::getStr('status', 16);
        $status = in_array($status, ['pending', 'credited', 'rejected'], true) ? $status : 'all';
        $search = self::getStr('q', 64);
        $filters = ['status' => $status !== 'all' ? $status : null, 'q' => $search];
        [$branch, $branchNav, $allowed] = self::branchFilter($user, $path, $filters);
        $filters['branch'] = $branch && self::getStr('branch', 64) !== '' ? $branch['slug'] : null;
        $journal = self::guestJournal($table, $branch ? [(int) $branch['id']] : $allowed, $status, $search, (int) self::getStr('page', 6));

        $form = '<form method="get" action="' . h(url_to($path) . '#journal') . '" role="search" class="flex flex-wrap items-center gap-2">';
        foreach (['status', 'branch'] as $key) {
            if ($filters[$key] !== null) {
                $form .= hidden($key, $filters[$key]);
            }
        }
        $form .= search_input('name="q" value="' . h($search) . '" maxlength="64" autocomplete="off" enterkeyhint="search"', $placeholder, 'min-w-0 flex-1 basis-56 sm:max-w-sm');
        $form .= '<button class="' . btn_class('outline') . '">Найти</button>';
        if ($search !== '') {
            $form .= '<a class="' . btn_class('quiet') . '" href="' . h(url_to($path, ['q' => null] + $filters) . '#journal') . '">' . icon('x', 'size-4') . 'Сбросить</a>';
        }
        $form .= '</form>';

        return [
            'rows' => $journal['rows'],
            'search' => $search,
            'tabs' => self::guestStatusTabs($path, $status, $journal['counts'], ['branch' => $filters['branch'], 'q' => $search]),
            'branchNav' => $branchNav,
            'searchForm' => $form,
            'pager' => pager(
                $journal['page'],
                $journal['pages'],
                $journal['total'],
                GUEST_PAGE_SIZE,
                static fn (int $p): string => url_to($path, $filters + ['page' => $p > 1 ? $p : null]) . '#journal',
                'Страницы журнала',
            ),
        ];
    }

    /**
     * Ячейка в две строки: значение и приглушённая подпись под ним.
     * На телефоне строка таблицы — карточка: там обе строки прижаты вправо, а длинная подпись переносится.
     * В таблице подпись не переносится, иначе ширина колонки менялась бы от страницы к странице.
     */
    private static function stackCell(string $main, string $sub): string
    {
        if ($sub === '') {
            return $main;
        }
        return '<div class="flex min-w-0 flex-col items-end gap-1 sm:items-start">' . $main . '<span class="text-xs break-words text-muted-foreground sm:whitespace-nowrap">' . h($sub) . '</span></div>';
    }

    private static function decideForm(string $action, int $id): string
    {
        return form_open($action, 'class="flex flex-wrap gap-1.5"') . hidden('id', $id)
            . '<button name="decision" value="credited" class="' . btn_class('ink', 'xs') . '" ' . confirm_attr('Бонусы уже начислены в Langame? Подтвердить отметку в CRM?') . '>' . icon('check', 'size-3.5') . 'Отметить зачисление</button>'
            . '<button name="decision" value="rejected" class="' . btn_class('danger', 'xs') . '" ' . confirm_attr('Отказать в бонусах?') . '>Отказ</button></form>';
    }

    private static function phoneCell(string $phone): string
    {
        return '<span class="font-mono text-[13px] whitespace-nowrap tabular-nums">' . h(normalize_phone($phone) ?? $phone) . '</span>';
    }

    /* ---------------- Бонусы за отзывы ---------------- */

    private static function pageReviews(array $user): void
    {
        $promo = self::portal_json('review_promo', DEFAULT_REVIEW_PROMO);
        $journal = self::guestJournalView($user, 'review_bonuses', '/reviews', 'Поиск по телефону или промокоду');
        $branchOptions = self::branchOptions($user);
        $known = [];
        // По возрастанию даты: если номер получал бонус несколько раз, в подсказке формы остаётся последний.
        $knownScope = $branchOptions ? ' AND "branchId" IN (' . self::idList(array_keys($branchOptions)) . ')' : ' AND 1 = 0';
        foreach (Db::all('SELECT "branchId", "guestPhone", status, "createdAt" FROM review_bonuses WHERE status != \'rejected\'' . $knownScope . ' ORDER BY "createdAt", id') as $r) {
            $digits = normalize_phone((string) $r['guestPhone']) ?? (string) $r['guestPhone'];
            $known[(int) $r['branchId']][$digits] = (REVIEW_STATUS[$r['status']] ?? $r['status']) . ', ' . format_dt((string) $r['createdAt'], false);
        }
        $actions = can($user, 'content.edit') ? '<a class="' . btn_class('outline') . '" href="' . h(url_to('/reviews/edit')) . '">' . icon('sliders', 'size-4') . 'Условия акции</a>' : '';
        $html = page_head('Бонусы за отзывы', h((string) $promo['intro']), $actions);
        $html .= '<p class="text-sm leading-relaxed text-muted-foreground">Бонусы начисляются вручную в Langame.</p>';
        $html .= '<div class="grid items-start gap-6 lg:grid-cols-[21rem_minmax(0,1fr)]">';

        $prePhone = normalize_phone(self::getStr('phone', 32));
        $preBranch = (int) self::getStr('branchId', 12);
        if ($preBranch <= 0 || !isset($branchOptions[$preBranch])) {
            $preBranch = (int) (self::workBranchId($user) ?? 0);
        }
        $textBonus = (int) $promo['textBonus'];
        $photoBonus = (int) ($promo['photoBonus'] ?? 0);
        $form = form_open('save_review', 'class="flex flex-col gap-4" data-review-form data-submit-once');
        $form .= '<div class="flex items-start justify-between gap-3"><h2 class="font-display text-base font-semibold">Новая заявка</h2><span class="font-display text-2xl leading-none font-semibold text-primary-ink tabular-nums" data-review-amount>+' . $textBonus . '<span class="block font-sans text-sm font-medium">бонусов</span></span></div>';
        $form .= ui_field('Филиал', ui_select('branchId', $branchOptions, $preBranch ?: self::workBranchId($user), 'required data-review-branch'));
        $kind = '<div class="flex flex-col gap-2" role="radiogroup" aria-label="Тип отзыва">';
        $kind .= '<label class="flex cursor-pointer items-center gap-2.5 rounded-md border border-border px-3 py-2.5"><input type="radio" name="reviewKind" value="text" class="' . check_class() . '" checked data-review-kind data-bonus="' . $textBonus . '"><span class="text-sm">Текст / без фото · <span class="font-semibold tabular-nums">+' . $textBonus . '</span></span></label>';
        if ($photoBonus > 0) {
            $kind .= '<label class="flex cursor-pointer items-center gap-2.5 rounded-md border border-border px-3 py-2.5"><input type="radio" name="reviewKind" value="photo" class="' . check_class() . '" data-review-kind data-bonus="' . $photoBonus . '"><span class="text-sm">С фото · <span class="font-semibold tabular-nums">+' . $photoBonus . '</span></span></label>';
        }
        $kind .= '</div>';
        $form .= ui_field('Тип отзыва', $kind, $photoBonus > 0 ? 'Фото — отдельная сумма из условий акции.' : 'Бонус за фото в условиях акции = 0 — пункт скрыт.');
        $form .= ui_field('Телефон гостя', '<input name="guestPhone" class="' . field_class('font-mono') . '" required inputmode="tel" autocomplete="off" placeholder="9001234567" data-review-phone value="' . h($prePhone ?: '') . '">');
        $form .= '<p class="-mt-2 min-h-5 text-xs leading-relaxed text-muted-foreground" data-phone-hint aria-live="polite">Бонус даётся один раз на филиал — номер проверится сам.</p>';
        $form .= '<script type="application/json" data-review-known>' . json_encode((object) $known, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        $form .= '<button class="' . btn_class() . '">' . icon('send', 'size-4') . 'Отправить на зачисление</button></form>';
        $html .= card($branchOptions ? $form : self::noBranchNote(), 'p-5 lg:sticky lg:top-8');

        $html .= '<div id="journal" class="flex min-w-0 scroll-mt-20 flex-col gap-4">';
        $html .= '<div class="flex flex-col gap-3 2xl:flex-row 2xl:items-end 2xl:justify-between">' . $journal['tabs'] . $journal['branchNav'] . '</div>';
        $html .= $journal['searchForm'];
        if (!$journal['rows']) {
            $html .= $journal['search'] !== ''
                ? empty_state('search', 'Ничего не нашлось', 'По запросу «' . $journal['search'] . '» заявок нет. Проверьте номер или загляните на другие вкладки и филиалы.')
                : empty_state('star', 'Заявок нет', 'Когда гость оставит отзыв, добавьте его номер в форме слева.');
        } else {
            $canAny = can($user, 'guests.decide');
            $html .= table_open(['Когда', 'Филиал', 'Телефон', 'Бонусов', 'Статус', 'Добавил(а)'] + ($canAny ? [6 => ['', 'w-0']] : []));
            foreach ($journal['rows'] as $r) {
                $imported = ($r['reviewKind'] ?? '') === ReviewImport::KIND;
                $decidedBy = $r['status'] !== 'pending' ? trim((string) ($r['decidedByName'] ?? '')) : '';
                $html .= '<tr class="border-t border-border">';
                // У перенесённых строк известна только дата активации — время не показываем.
                $html .= td('Когда', '<span class="whitespace-nowrap">' . h(format_dt((string) $r['createdAt'], !$imported)) . '</span>', 'text-muted-foreground');
                $html .= td('Филиал', h((string) ($r['branchName'] ?? '—')));
                $html .= td('Телефон', self::stackCell(self::phoneCell((string) $r['guestPhone']), trim((string) ($r['note'] ?? ''))));
                $html .= td('Бонусов', '<span class="font-semibold tabular-nums">' . num_fmt((int) $r['bonusAmount']) . '</span>');
                $html .= td('Статус', self::stackCell(status_chip((string) $r['status']), $decidedBy !== '' ? short_name($decidedBy) : ''));
                $html .= td('Добавил(а)', h(short_name((string) $r['createdByName'])), 'text-muted-foreground');
                if ($canAny) {
                    $html .= td('', $r['status'] === 'pending' && can($user, 'guests.decide', (int) $r['branchId']) ? self::decideForm('decide_review', (int) $r['id']) : '');
                }
                $html .= '</tr>';
            }
            $html .= table_close();
        }
        $html .= $journal['pager'] . '</div></div>';
        self::layout($user, $html, ['title' => 'Бонусы за отзывы', 'width' => 'wide']);
    }

    private static function pageReviewsEdit(array $user): void
    {
        $promo = self::portal_json('review_promo', DEFAULT_REVIEW_PROMO);
        $html = back_link(url_to('/reviews'), 'Бонусы за отзывы');
        $html .= page_head('Условия акции за отзывы', 'Текст видят все сотрудники над формой заявки. Сумма подставляется в каждую новую заявку.');
        $form = form_open('save_promo', 'class="flex flex-col gap-5"');
        $form .= ui_field('Условия для сотрудников', '<textarea name="intro" class="' . textarea_class('min-h-32') . '" maxlength="2000" required>' . h((string) $promo['intro']) . '</textarea>');
        $form .= '<div class="grid gap-4 sm:grid-cols-2">';
        $form .= ui_field('Бонус за отзыв', '<input name="textBonus" type="number" min="1" max="100000" class="' . field_class() . '" value="' . (int) $promo['textBonus'] . '" required>', 'Без фото / текст.');
        $form .= ui_field('Бонус за отзыв с фото', '<input name="photoBonus" type="number" min="0" max="100000" class="' . field_class() . '" value="' . (int) ($promo['photoBonus'] ?? 0) . '">', '0 — пункт «с фото» скрыт в форме заявки.');
        $form .= '</div>';
        $form .= '<div class="flex justify-end gap-2"><a class="' . btn_class('ghost') . '" href="' . h(url_to('/reviews')) . '">Отмена</a><button class="' . btn_class() . '">' . icon('save', 'size-4') . 'Сохранить</button></div></form>';
        $html .= card($form, 'p-5 sm:p-6');
        self::layout($user, $html, ['title' => 'Условия акции', 'width' => 'narrow']);
    }

    /* ---------------- Компенсации ---------------- */

    private static function pageCompensations(array $user): void
    {
        $journal = self::guestJournalView($user, 'compensations', '/compensations', 'Поиск по телефону или причине');
        $branchOptions = self::branchOptions($user);
        $html = page_head('Компенсации гостям', 'Учёт компенсаций. Начисление бонусов — вручную в Langame.');
        $html .= '<div class="grid items-start gap-6 lg:grid-cols-[21rem_minmax(0,1fr)]">';

        $prePhone = normalize_phone(self::getStr('phone', 32));
        $preBranch = (int) self::getStr('branchId', 12);
        if ($preBranch <= 0 || !isset($branchOptions[$preBranch])) {
            $preBranch = (int) (self::workBranchId($user) ?? 0);
        }
        $form = form_open('save_comp', 'class="flex flex-col gap-4" data-submit-once');
        $form .= '<h2 class="font-display text-base font-semibold">Новая компенсация</h2>';
        $form .= '<div class="grid grid-cols-2 gap-3">' . ui_field('Филиал', ui_select('branchId', $branchOptions, $preBranch ?: self::workBranchId($user), 'required'));
        $form .= ui_field('Бонусов', '<input name="bonusAmount" type="number" min="1" max="5000" class="' . field_class() . '" required placeholder="до 5000">') . '</div>';
        $form .= ui_field('Телефон гостя', '<input name="guestPhone" class="' . field_class('font-mono') . '" required inputmode="tel" autocomplete="off" placeholder="9001234567" value="' . h($prePhone ?: '') . '">');
        $form .= ui_field('Что случилось', '<textarea name="reason" class="' . textarea_class('min-h-20') . '" required minlength="5" maxlength="1000" placeholder="Например: ПК завис на 40 минут"></textarea>');
        $form .= ui_field('Что сделали', '<textarea name="actionsTaken" class="' . textarea_class('min-h-16') . '" maxlength="1000" placeholder="Пересадили, вернули время…"></textarea>');
        $form .= '<button class="' . btn_class() . '">' . icon('send', 'size-4') . 'Записать</button></form>';
        $html .= card($branchOptions ? $form : self::noBranchNote(), 'p-5 lg:sticky lg:top-8');

        $html .= '<div id="journal" class="flex min-w-0 scroll-mt-20 flex-col gap-4">';
        $html .= '<div class="flex flex-col gap-3 2xl:flex-row 2xl:items-end 2xl:justify-between">' . $journal['tabs'] . $journal['branchNav'] . '</div>';
        $html .= $journal['searchForm'];
        if (!$journal['rows']) {
            $html .= $journal['search'] !== ''
                ? empty_state('search', 'Ничего не нашлось', 'По запросу «' . $journal['search'] . '» записей нет. Проверьте запрос или загляните на другие вкладки и филиалы.')
                : empty_state('coins', 'Записей нет');
        } else {
            $canAny = can($user, 'guests.decide');
            $html .= table_open(['Когда', 'Филиал', 'Телефон', 'Причина и что сделали', 'Бонусов', 'Статус'] + ($canAny ? [6 => ['', 'w-0']] : []));
            foreach ($journal['rows'] as $r) {
                $done = trim((string) $r['actionsTaken']);
                $html .= '<tr class="border-t border-border">';
                $html .= td('Когда', self::stackCell('<span class="whitespace-nowrap">' . h(format_dt((string) $r['createdAt'])) . '</span>', short_name((string) $r['createdByName'])), 'text-muted-foreground');
                $html .= td('Филиал', h((string) ($r['branchName'] ?? '—')));
                $html .= td('Телефон', self::phoneCell((string) $r['guestPhone']));
                $html .= td('Причина', '<p class="max-w-md leading-relaxed text-pretty break-words">' . h($r['reason']) . '</p>' . ($done !== '' ? '<p class="mt-1 max-w-md text-xs leading-relaxed text-muted-foreground break-words">' . icon('check', 'mr-1 inline size-3') . h($done) . '</p>' : ''));
                $html .= td('Бонусов', '<span class="font-semibold tabular-nums">' . num_fmt((int) $r['bonusAmount']) . '</span>');
                $html .= td('Статус', status_chip((string) $r['status']));
                if ($canAny) {
                    $html .= td('', $r['status'] === 'pending' && can($user, 'guests.decide', (int) $r['branchId']) ? self::decideForm('decide_comp', (int) $r['id']) : '');
                }
                $html .= '</tr>';
            }
            $html .= table_close();
        }
        $html .= $journal['pager'] . '</div></div>';
        self::layout($user, $html, ['title' => 'Компенсации', 'width' => 'wide']);
    }

    /* ---------------- Бонусная система ---------------- */

    private static function loyaltyTiers(): array
    {
        return Db::all('SELECT * FROM loyalty_tiers ORDER BY "sortOrder", "minHours", id');
    }

    private static function pageLoyalty(array $user): void
    {
        redirect_to('/tariffs');
    }

    /** Тарифы, стоимость времени и кэшбек — один экран. */
    private static function bonusSystemHtml(array $user): string
    {
        $tiers = self::loyaltyTiers();
        $tariffs = self::portal_json('tariffs', DEFAULT_TARIFFS);
        $edit = can($user, 'content.edit') ? '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/loyalty/edit')) . '">' . icon('pencil', 'size-4') . 'Уровни</a>' : '';
        $html = '<div id="bonus" class="scroll-mt-24">' . section_title('Бонусная система', $edit) . '</div>';
        $html .= '<p class="-mt-2 text-sm text-muted-foreground">Кэшбек возвращается бонусами с пополнения. Уровень гостя зависит от сыгранных часов. Стоимость времени считается по базовому тарифу этого же прайса.</p>';
        $html .= '<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">';
        if (!$tiers) {
            $html .= '<div class="lg:col-start-1 lg:row-span-2 lg:row-start-1">' . empty_state('percent', 'Уровни не настроены', 'Добавьте уровни, чтобы считать кэшбек.') . '</div>';
        } else {
            $first = $tiers[0];
            $calc = card_head('Кэшбек с пополнения', 'Выберите уровень гостя и введите сумму.', '', 'gift');
            $calc .= '<div class="mt-5 flex flex-col gap-4">' . ui_field('Сумма пополнения, ₽', '<input class="' . field_class('h-11 text-lg tabular-nums') . '" inputmode="numeric" autocomplete="off" placeholder="1000" data-topup>');
            $calc .= '<div class="flex flex-wrap gap-1.5" role="group" aria-label="Быстрая сумма">';
            foreach ([300, 500, 1000, 2000] as $preset) {
                $calc .= '<button type="button" class="' . btn_class('outline', 'xs') . ' tabular-nums" data-topup-preset="' . $preset . '">' . h(num_fmt($preset)) . '</button>';
            }
            $calc .= '</div><div class="flex flex-col gap-1.5 rounded-lg bg-muted/60 px-4 py-3.5" aria-live="polite"><span class="text-xs font-semibold tracking-wide text-muted-foreground uppercase">Вернётся бонусами</span>';
            $calc .= '<output class="font-display text-3xl leading-none font-semibold text-primary-ink tabular-nums" data-cashback>—</output>';
            $calc .= '<span class="text-sm text-muted-foreground">Уровень <span class="font-semibold text-foreground" data-tier-name>' . h((string) $first['title']) . '</span> · кэшбек <span class="tabular-nums" data-tier-percent>' . (int) $first['cashbackPercent'] . '</span>%</span></div></div>';
            $html .= card($calc, 'p-5 lg:col-start-2', 'section', 'data-cashback-calc');

            $list = '<div class="flex items-baseline justify-between gap-3 border-b border-border px-4 py-3"><h2 id="tiers-title" class="text-sm font-semibold">Уровень гостя</h2><span class="text-xs text-muted-foreground">сыгранные часы · кэшбек</span></div>';
            $list .= '<div class="flex flex-col" role="radiogroup" aria-labelledby="tiers-title" data-tiers>';
            foreach ($tiers as $i => $t) {
                $range = $t['maxHours'] === null || $t['maxHours'] === '' ? 'от ' . (int) $t['minHours'] . ' ч' : (int) $t['minHours'] . '–' . (int) $t['maxHours'] . ' ч';
                $list .= '<button type="button" role="radio" aria-checked="' . ($i === 0 ? 'true' : 'false') . '" tabindex="' . ($i === 0 ? '0' : '-1') . '" class="tier-card" data-tier data-percent="' . (int) $t['cashbackPercent'] . '" data-title="' . h((string) $t['title']) . '">';
                $list .= '<span class="tier-radio" aria-hidden="true"></span>';
                $list .= '<span class="flex min-w-0 flex-col gap-0.5"><span class="flex flex-wrap items-baseline gap-x-2"><span class="font-semibold">' . h((string) $t['title']) . '</span><span class="text-xs text-muted-foreground tabular-nums">' . h($range) . '</span></span>';
                $list .= '<span class="flex items-center gap-1.5 text-xs text-muted-foreground">' . icon('cake', 'size-3.5') . 'В день рождения ' . h(money_rub((int) $t['birthdayBonus'])) . '</span></span>';
                $list .= '<span class="flex flex-col items-end gap-1"><span class="font-display text-xl leading-none font-semibold tabular-nums">' . (int) $t['cashbackPercent'] . '%</span><span class="text-xs font-semibold text-primary-ink tabular-nums" data-tier-cash hidden></span></span></button>';
            }
            $html .= card($list . '</div>', 'overflow-hidden lg:col-start-1 lg:row-span-2 lg:row-start-1');
        }
        $html .= '</div>';
        $time = card_head('Стоимость времени', 'Поминутно по цене часа на базовом тарифе', '', 'timer');
        $branchesT = $tariffs['branches'] ?? [];
        $time .= '<div class="mt-5 flex flex-col gap-4" data-time-calc>';
        if (count($branchesT) > 1) {
            $time .= '<div class="flex flex-wrap gap-1 self-start rounded-lg border border-border bg-muted/40 p-1" role="tablist">';
            foreach ($branchesT as $i => $b) {
                $time .= '<button type="button" role="tab" class="seg-btn" aria-selected="' . ($i === 0 ? 'true' : 'false') . '" data-branch-tab="' . $i . '">' . h((string) $b['branchName']) . '</button>';
            }
            $time .= '</div>';
        }
        $time .= '<div class="flex gap-2"><input class="' . field_class('h-11 text-lg tabular-nums') . '" inputmode="numeric" placeholder="90" aria-label="Длительность" data-duration>' . ui_select('', ['minutes' => 'минут', 'hours' => 'часов'], 'minutes', 'aria-label="Единицы" data-unit', 'h-11 w-32') . '</div>';
        foreach ($branchesT as $i => $b) {
            $time .= '<div class="grid gap-2 sm:grid-cols-3' . ($i === 0 ? '' : ' hidden') . '" data-branch-panel="' . $i . '">';
            foreach (($b['zones'] ?? []) as $zi => $z) {
                $hour = (int) ($b['packages'][0]['prices'][$zi] ?? 0);
                $time .= '<div class="flex flex-col gap-1 rounded-md border border-border px-3 py-2.5"><span class="text-sm font-semibold">' . h((string) $z) . '</span><span class="text-xs text-muted-foreground">' . $hour . ' ₽/час</span><span class="font-display text-xl font-semibold tabular-nums" data-zone-price data-hour="' . $hour . '">—</span></div>';
            }
            $time .= '</div>';
        }
        $time .= '</div>';
        return '<div id="time" class="scroll-mt-24">' . card($time, 'p-5') . '</div>' . $html;
    }

    private static function pageLoyaltyEdit(array $user): void
    {
        $tiers = self::loyaltyTiers();
        $html = back_link(url_to('/tariffs') . '#bonus', 'Тарифы и бонусы');
        $html .= page_head('Уровни лояльности', 'Пустое «до» — уровень без верхней границы. Изменения сразу видят все сотрудники.');
        $row = static function (?array $t): string {
            $id = $t ? (int) $t['id'] : 0;
            $f = form_open('save_loyalty', 'class="grid grid-cols-2 items-end gap-3 sm:grid-cols-[minmax(0,1.4fr)_repeat(4,minmax(0,1fr))_auto]"') . hidden('id', $id);
            $f .= ui_field('Название', '<input name="title" class="' . field_class() . '" value="' . h((string) ($t['title'] ?? '')) . '" required maxlength="80"' . ($t ? '' : ' placeholder="Новый уровень"') . '>', '', 'col-span-2 sm:col-span-1');
            $f .= ui_field('От, часов', '<input name="minHours" type="number" min="0" class="' . field_class() . '" value="' . h((string) ($t['minHours'] ?? '0')) . '">');
            $f .= ui_field('До, часов', '<input name="maxHours" type="number" min="0" class="' . field_class() . '" value="' . h((string) ($t['maxHours'] ?? '')) . '" placeholder="∞">');
            $f .= ui_field('Кэшбек, %', '<input name="cashbackPercent" type="number" min="0" max="100" class="' . field_class() . '" value="' . h((string) ($t['cashbackPercent'] ?? '0')) . '">');
            $f .= ui_field('ДР, ₽', '<input name="birthdayBonus" type="number" min="0" class="' . field_class() . '" value="' . h((string) ($t['birthdayBonus'] ?? '0')) . '">');
            $f .= '<button class="' . btn_class($t ? 'outline' : 'primary') . ' col-span-2 sm:col-span-1">' . icon($t ? 'save' : 'plus', 'size-4') . ($t ? 'Сохранить' : 'Добавить') . '</button></form>';
            return card($f, 'p-4');
        };
        $html .= '<div class="flex flex-col gap-3">';
        foreach ($tiers as $t) {
            $html .= $row($t);
        }
        $html .= '</div>' . section_title('Добавить уровень') . $row(null);
        self::layout($user, $html, ['title' => 'Уровни лояльности']);
    }

    /* ---------- Гости клуба (Langame) ---------- */

    /**
     * Заявки CRM по номеру. $onlyBranchIds — сузить до филиала экрана (поиск по своей точке).
     * @param list<int>|null $onlyBranchIds
     * @return array{reviews: list<array<string,mixed>>, comps: list<array<string,mixed>>}
     */
    private static function guestClubHistory(string $rawPhone, array $user, ?array $onlyBranchIds = null): array
    {
        $digits = normalize_phone($rawPhone) ?? (preg_replace('/\D+/', '', $rawPhone) ?? '');
        if (strlen($digits) < 7) {
            return ['reviews' => [], 'comps' => []];
        }
        $allowed = [];
        foreach (self::visibleBranches($user) as $b) {
            $allowed[] = (int) $b['id'];
        }
        if ($onlyBranchIds !== null) {
            $want = array_flip(array_map('intval', $onlyBranchIds));
            $allowed = array_values(array_filter($allowed, static fn (int $id): bool => isset($want[$id])));
        }
        if (!$allowed) {
            return ['reviews' => [], 'comps' => []];
        }
        $in = self::idList($allowed);
        $like = '%' . $digits;
        $reviews = Db::all(
            'SELECT t.status, t."bonusAmount", t."createdAt", t.note, b.name AS "branchName"
             FROM review_bonuses t LEFT JOIN branches b ON b.id = t."branchId"
             WHERE t."branchId" IN (' . $in . ') AND (t."guestPhone" = ? OR t."guestPhone" LIKE ?)
             ORDER BY t."createdAt" DESC, t.id DESC LIMIT 8',
            [$digits, $like],
        );
        $comps = Db::all(
            'SELECT t.status, t."bonusAmount", t."createdAt", t.reason, b.name AS "branchName"
             FROM compensations t LEFT JOIN branches b ON b.id = t."branchId"
             WHERE t."branchId" IN (' . $in . ') AND (t."guestPhone" = ? OR t."guestPhone" LIKE ?)
             ORDER BY t."createdAt" DESC, t.id DESC LIMIT 8',
            [$digits, $like],
        );
        return ['reviews' => $reviews, 'comps' => $comps];
    }

    private static function guestMetric(string $label, string $value, string $hint = ''): string
    {
        $html = '<div class="flex min-h-[4.25rem] flex-col rounded-lg border border-border bg-card/80 px-3 py-2.5">';
        $html .= '<p class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">' . h($label) . '</p>';
        $html .= '<p class="mt-1 text-sm font-semibold tabular-nums leading-snug">' . $value . '</p>';
        $html .= '<p class="mt-auto pt-0.5 text-xs text-muted-foreground' . ($hint === '' ? ' invisible' : '') . '">' . ($hint !== '' ? $hint : '—') . '</p>';
        return $html . '</div>';
    }

    private static function pageGuestsClub(array $user): void
    {
        $enabled = Langame::enabledBranchIds();
        $visible = array_values(array_filter(self::visibleBranches($user), static fn ($b) => in_array($b['id'], $enabled, true)));
        if (!$visible) {
            $action = $user['isOwner'] ? '<a class="' . btn_class() . '" href="' . h(url_to('/manage/langame')) . '">' . icon('link', 'size-4') . 'Подключить Langame</a>' : '';
            self::layout($user, page_head('Гости клуба') . empty_state('search', 'Нет подключённых филиалов', 'Поиск гостей появится после подключения Langame.', $action), ['title' => 'Гости клуба']);
            return;
        }
        $branch = self::pickBranch($user, $visible) ?? $visible[0];
        $q = trim(self::getStr('q', 80));
        $results = [];
        $searchError = '';
        if ($q !== '') {
            try {
                $results = Langame::searchGuests((int) $branch['id'], $q, 25);
            } catch (LangameError $e) {
                $searchError = $e->getMessage();
            }
        }
        $multi = count($visible) > 1;
        $branchNav = '';
        if ($multi) {
            $items = [];
            foreach ($visible as $b) {
                $items[] = ['href' => url_to('/guests/club', ['branch' => $b['slug'], 'q' => $q ?: null]), 'label' => $b['name'], 'active' => $b['id'] === $branch['id']];
            }
            $branchNav = segmented($items, 'Филиал для поиска');
        }

        $scopeHint = $multi
            ? 'Сейчас смотрите «' . h((string) $branch['name']) . '». Переключайте вкладку выше, чтобы искать на другом своём филиале.'
            : 'Поиск только по филиалу «' . h((string) $branch['name']) . '».';
        $cashLink = '<a class="' . btn_class('outline') . '" href="' . h(url_to('/cash', ['branch' => $branch['slug']])) . '">' . icon('wallet', 'size-4') . 'Касса клуба</a>';
        $html = page_head('Гости клуба', $scopeHint, $cashLink, $branch['name']);
        $html .= $branchNav;

        $search = '<form method="get" action="' . h(url_to('/guests/club')) . '" class="flex flex-col gap-3">' . hidden('branch', $branch['slug']);
        $search .= ui_field('Имя или телефон', '<div class="relative"><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-muted-foreground">' . icon('search', 'size-4') . '</span><input name="q" value="' . h($q) . '" class="' . field_class('pl-9') . '" maxlength="80" placeholder="9001234567 или имя" autofocus inputmode="tel"></div>', 'Запрос уходит в Langame филиала «' . $branch['name'] . '».');
        $search .= '<button class="' . btn_class() . ' sm:self-start">' . icon('search', 'size-4') . 'Найти · ' . h($branch['name']) . '</button></form>';
        if ($searchError !== '') {
            $search .= '<p class="mt-3 text-sm text-primary-ink" role="alert">' . h($searchError) . '</p>';
        }
        if ($q !== '' && !$searchError) {
            if (!$results) {
                $search .= '<p class="mt-4 text-sm text-muted-foreground">Никого не нашли по запросу «' . h($q) . '».</p>';
            } else {
                $search .= '<ul class="mt-4 flex flex-col gap-4">';
                foreach ($results as $g) {
                    $phoneRaw = (string) $g['phone'];
                    $phone = $phoneRaw !== '' ? (normalize_phone($phoneRaw) ?? $phoneRaw) : '—';
                    $bal = $g['balance'] !== null ? h(money_rub((int) round($g['balance']))) : '—';
                    $bonus = $g['bonus'] !== null ? h(money_rub((int) round($g['bonus']))) : '—';
                    $age = $g['age'] !== null ? h(plural((int) $g['age'], 'год', 'года', 'лет')) : '—';
                    $bdayHint = $g['birthday'] ? h(format_day_month(substr((string) $g['birthday'], 0, 10))) : '';
                    $reg = !empty($g['registeredAt']) ? h(format_dt(str_replace(' ', 'T', (string) $g['registeredAt']))) : '—';
                    $last = !empty($g['lastActiveAt']) ? h(format_dt(str_replace(' ', 'T', (string) $g['lastActiveAt']))) : '—';
                    $gender = !empty($g['gender']) ? h((string) $g['gender']) : '';
                    $search .= '<li class="rounded-xl border border-border bg-muted/20 p-4">';
                    $search .= '<div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><p class="font-display text-base font-semibold">' . h($g['name']) . '</p>';
                    $search .= '<p class="mt-1 text-xs text-muted-foreground"><span class="font-mono tabular-nums">' . h($phone) . '</span> · ID ' . (int) $g['id'] . ($gender !== '' ? ' · ' . $gender : '') . '</p></div></div>';
                    $search .= '<div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">';
                    $search .= self::guestMetric('Баланс', $bal);
                    $search .= self::guestMetric('Бонусы', $bonus);
                    $search .= self::guestMetric('Возраст', $age, $bdayHint);
                    $search .= self::guestMetric('Регистрация', $reg);
                    $search .= self::guestMetric('Посл. авторизация', $last);
                    $search .= '</div>';
                    $hist = self::guestClubHistory($phoneRaw !== '' ? $phoneRaw : $q, $user, [(int) $branch['id']]);
                    $search .= '<div class="mt-3 grid items-stretch gap-2 sm:grid-cols-2">';
                    $search .= self::guestClubHistoryBox('Бонусы за отзыв', $hist['reviews'], '/reviews', 'star');
                    $search .= self::guestClubHistoryBox('Компенсации', $hist['comps'], '/compensations', 'link');
                    $search .= '</div>';
                    $phoneDigits = normalize_phone($phoneRaw !== '' ? $phoneRaw : $q);
                    if ($phoneDigits) {
                        $search .= '<div class="mt-3 flex flex-wrap gap-2 border-t border-border pt-3">';
                        $search .= '<a class="' . btn_class('primary', 'sm') . '" href="' . h(url_to('/reviews', ['phone' => $phoneDigits, 'branchId' => (int) $branch['id']])) . '">' . icon('star', 'size-3.5') . 'Бонус за отзыв</a>';
                        $search .= '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/compensations', ['phone' => $phoneDigits, 'branchId' => (int) $branch['id']])) . '">' . icon('link', 'size-3.5') . 'Компенсация</a>';
                        $search .= '</div>';
                    }
                    $search .= '</li>';
                }
                $search .= '</ul>';
            }
        } else {
            $search .= '<p class="mt-4 text-sm leading-relaxed text-muted-foreground">Телефон можно ввести как +7…, 8… или просто с 9. Покажем баланс, возраст, даты и заявки CRM.</p>';
        }
        $html .= card(card_head('Поиск гостя', 'Langame + журнал CRM', '', 'search') . '<div class="mt-4">' . $search . '</div>', 'p-5');
        self::layout($user, $html, ['title' => 'Гости клуба', 'width' => 'wide']);
    }

    /** @param list<array<string,mixed>> $rows */
    private static function guestClubHistoryBox(string $title, array $rows, string $href, string $iconName): string
    {
        $box = '<div class="flex h-full min-h-[5.5rem] flex-col rounded-lg border border-border px-3 py-3">';
        $box .= '<div class="flex items-center justify-between gap-2">';
        $box .= '<p class="flex min-w-0 items-center gap-1.5 text-xs font-semibold"><span class="inline-flex size-4 shrink-0 items-center justify-center text-muted-foreground" aria-hidden="true">' . icon($iconName, 'size-3.5') . '</span><span class="truncate">' . h($title) . '</span></p>';
        $box .= '<a class="shrink-0 text-[11px] font-medium text-muted-foreground hover:text-foreground" href="' . h(url_to($href)) . '">журнал</a></div>';
        if (!$rows) {
            return $box . '<p class="mt-auto pt-3 text-xs text-muted-foreground">Не получал</p></div>';
        }
        $box .= '<ul class="mt-2 flex flex-1 flex-col gap-1.5">';
        foreach ($rows as $r) {
            $st = REVIEW_STATUS[(string) $r['status']] ?? (string) $r['status'];
            $extra = trim((string) ($r['reason'] ?? $r['note'] ?? ''));
            $box .= '<li class="text-xs leading-snug"><span class="font-medium">' . h((string) ($r['branchName'] ?? '—')) . '</span>';
            $box .= ' · ' . h($st) . ' · <span class="tabular-nums">+' . num_fmt((int) $r['bonusAmount']) . '</span>';
            $box .= '<span class="block text-muted-foreground">' . h(format_dt((string) $r['createdAt'], false)) . ($extra !== '' ? ' · ' . h(mb_substr($extra, 0, 48)) : '') . '</span></li>';
        }
        return $box . '</ul></div>';
    }

}
