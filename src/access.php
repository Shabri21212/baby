<?php
declare(strict_types=1);

/**
 * Права учётных записей. scoped — право действует только в филиалах учётной записи
 * (поле branchIds у пользователя): например, старший админ Киевской составляет график
 * только Киевской, а сотрудник с двумя филиалами — обоих.
 */
/**
 * Вкладки «по умолчанию» для работы на смене. Без них меню было бы пустым.
 * Кассу бизнеса и контроль смен сюда не кладём — это не для обычного админа.
 */
const PAGE_STAFF_DEFAULTS = [
    'page.swaps', 'page.bar', 'page.cleaning', 'page.issues', 'page.news',
    'page.guests', 'page.reviews', 'page.compensations',
    'page.wiki', 'page.tariffs', 'page.fines', 'page.exams',
];

const PERMISSIONS = [
    'schedule.view_all' => ['group' => 'Смены', 'label' => 'Видеть графики всех филиалов', 'hint' => 'Без этого права сотрудник видит графики только своих филиалов и свои подмены. Редактирование выдаётся отдельно.', 'scoped' => false],
    'schedule.edit' => ['group' => 'Смены', 'label' => 'Составлять график смен', 'hint' => 'Ставить, менять и убирать смены, копировать неделю', 'scoped' => true],
    'tasks.assign' => ['group' => 'Смены', 'label' => 'Назначать задания на смену', 'hint' => 'Раздел «Задания на смены»', 'scoped' => true],
    'cleaning.assign' => ['group' => 'Смены', 'label' => 'Распределять чистку клавиатур', 'hint' => 'Закреплять места и снимать чужие отметки', 'scoped' => true],
    'issues.resolve' => ['group' => 'Клуб', 'label' => 'Вести поломки', 'hint' => 'Брать в работу и закрывать заявки', 'scoped' => true],
    'news.publish' => ['group' => 'Клуб', 'label' => 'Публиковать объявления', 'hint' => 'Писать, закреплять и удалять, видеть кто прочитал', 'scoped' => false],
    'guests.decide' => ['group' => 'Гости', 'label' => 'Зачислять бонусы и компенсации', 'hint' => 'Подтверждать или отклонять заявки', 'scoped' => true],
    'content.edit' => ['group' => 'Регламенты', 'label' => 'Менять тарифы и бонусную систему', 'hint' => 'Прайс, уровни кэшбека, условия акции за отзывы', 'scoped' => false],
    'wiki.edit' => ['group' => 'Регламенты', 'label' => 'Редактировать базу знаний', 'hint' => 'Создавать, править и удалять статьи', 'scoped' => false],
    'fines.manage' => ['group' => 'Регламенты', 'label' => 'Назначать штрафы', 'hint' => 'Выписывать и отменять штрафы, править лист', 'scoped' => false],
    'exams.manage' => ['group' => 'Регламенты', 'label' => 'Управлять экзаменами', 'hint' => 'Создавать тесты и смотреть результаты сотрудников', 'scoped' => false],
    // Курс обучения: полный доступ или отдельные части. Просмотр курса — вкладка «Обучение».
    'course.manage' => ['group' => 'Обучение', 'label' => 'Курс: полный доступ', 'hint' => 'Всё ниже сразу, плюс импорт курса из файла. Для главного редактора курса', 'scoped' => false],
    'course.edit' => ['group' => 'Обучение', 'label' => 'Курс: тексты и фото', 'hint' => 'Править текст шагов, вставлять картинки, ситуации и вопросы. Структуру курса не меняет', 'scoped' => false],
    'course.structure' => ['group' => 'Обучение', 'label' => 'Курс: модули и главы', 'hint' => 'Создавать, переименовывать, скрывать, удалять и переставлять модули, главы и шаги', 'scoped' => false],
    'course.settings' => ['group' => 'Обучение', 'label' => 'Курс: настройки и оформление', 'hint' => 'Название и правила курса, порядок прохождения, праздничное оформление портала', 'scoped' => false],
    'staff.manage' => ['group' => 'Управление', 'label' => 'Сотрудники, уровни и ставки', 'hint' => 'Карточки, филиалы, уровни и ставки за смену', 'scoped' => false],
    'journal.view' => ['group' => 'Управление', 'label' => 'Журнал действий', 'hint' => 'Кто, что и когда изменил в портале', 'scoped' => false],
    // Вкладки: снять галочку — пункта не будет в меню у этого человека.
    'page.cash' => ['group' => 'Вкладки', 'label' => 'Касса клуба', 'hint' => 'Нал и безнал бизнеса по дням. Не процент с бара — цифры клуба. Обычным админам не выдавайте.', 'scoped' => true],
    'page.reconcile' => ['group' => 'Вкладки', 'label' => 'Контроль смен', 'hint' => 'Кто по графику, кто открыл Langame, кто сдал смену в CRM', 'scoped' => true],
    'page.missing' => ['group' => 'Вкладки', 'label' => 'Не сдали смену', 'hint' => 'Список пропущенных сдач', 'scoped' => true],
    'page.swaps' => ['group' => 'Вкладки', 'label' => 'Подмены', 'hint' => 'Раздел обмена сменами', 'scoped' => false],
    'page.bar' => ['group' => 'Вкладки', 'label' => 'Продажи и аналитика', 'hint' => 'Бар, рейтинг продаж сотрудников', 'scoped' => true],
    'page.cleaning' => ['group' => 'Вкладки', 'label' => 'Чистка клавиатур', 'hint' => 'Раздел чистки', 'scoped' => true],
    'page.issues' => ['group' => 'Вкладки', 'label' => 'Поломки', 'hint' => 'Журнал поломок', 'scoped' => true],
    'page.news' => ['group' => 'Вкладки', 'label' => 'Объявления', 'hint' => 'Читать ленту объявлений', 'scoped' => false],
    'page.guests' => ['group' => 'Вкладки', 'label' => 'Гости клуба', 'hint' => 'Поиск гостя в Langame', 'scoped' => true],
    'page.reviews' => ['group' => 'Вкладки', 'label' => 'Бонусы за отзывы', 'hint' => 'Заявки на бонус за отзыв', 'scoped' => true],
    'page.compensations' => ['group' => 'Вкладки', 'label' => 'Компенсации', 'hint' => 'Заявки на компенсации', 'scoped' => true],
    'page.wiki' => ['group' => 'Вкладки', 'label' => 'База знаний', 'hint' => 'Читать статьи', 'scoped' => false],
    'page.tariffs' => ['group' => 'Вкладки', 'label' => 'Тарифы и бонусы', 'hint' => 'Прайс и кэшбек', 'scoped' => false],
    'page.fines' => ['group' => 'Вкладки', 'label' => 'Штрафы', 'hint' => 'Лист и свои штрафы', 'scoped' => false],
    'page.exams' => ['group' => 'Вкладки', 'label' => 'Обучение', 'hint' => 'Главы курса и финальный экзамен', 'scoped' => false],
    'page.insights' => ['group' => 'Аналитика', 'label' => 'Вкладка «Аналитика сети»', 'hint' => 'Сама вкладка. Что внутри видно — решают галочки ниже', 'scoped' => true],
    'insight.heatmap' => ['group' => 'Аналитика', 'label' => 'Тепловая карта выручки', 'hint' => 'В какие дни недели и часы бар продаёт больше всего', 'scoped' => true],
    'insight.branches' => ['group' => 'Аналитика', 'label' => 'Сравнение филиалов', 'hint' => 'Выручка, средний чек и рост к прошлому месяцу по каждому клубу', 'scoped' => true],
    'insight.payments' => ['group' => 'Аналитика', 'label' => 'Нал и безнал бара', 'hint' => 'Доля безналичной оплаты по филиалам', 'scoped' => true],
    'insight.goods' => ['group' => 'Аналитика', 'label' => 'Хиты и падение спроса', 'hint' => 'Какие товары растут, а какие перестали брать', 'scoped' => true],
    'insight.cancels' => ['group' => 'Аналитика', 'label' => 'Отмены чеков по сотрудникам', 'hint' => 'Кто чаще всех отменяет продажи. Чувствительные данные — выдавайте точечно', 'scoped' => true],
    'insight.shifts' => ['group' => 'Аналитика', 'label' => 'Смены Langame: длина и инкассации', 'hint' => 'Сколько часов в среднем длится смена, инкассации и комментарии при закрытии', 'scoped' => true],
    'stats.network_top' => ['group' => 'Статистика админа', 'label' => 'Общий топ администраторов', 'hint' => 'Рейтинг продаж по всем филиалам на «Моём дне»', 'scoped' => false],
    'stats.see_revenue' => ['group' => 'Статистика админа', 'label' => 'Суммы коллег в топе', 'hint' => 'Без галочки в топе видны только места и смены, без рублей', 'scoped' => false],
    'stats.personal' => ['group' => 'Статистика админа', 'label' => 'Мои рекорды', 'hint' => 'Личная карточка: лучшая смена, любимый товар, золотой час, рост к прошлому месяцу', 'scoped' => false],
];

/**
 * Права, появившиеся после того, как у части учётных записей уже были ручные наборы.
 * Старый ручной набор без метки PERMS_MARKER получает их из шаблона роли — иначе
 * новые разделы молча пропали бы у всех, кому владелец когда-то правил права.
 */
const PERMS_ADDED_V2 = [
    'page.insights', 'insight.heatmap', 'insight.branches', 'insight.payments', 'insight.goods', 'insight.cancels', 'insight.shifts',
    'stats.network_top', 'stats.see_revenue', 'stats.personal',
];
const PERMS_MARKER = '~v2';
const STATS_DEFAULTS = ['stats.network_top', 'stats.see_revenue', 'stats.personal'];

const ROLES = [
    'owner' => [
        'label' => 'Владелец',
        'hint' => 'Всё, включая доступы и права других',
        'perms' => '*',
    ],
    'manager' => [
        'label' => 'Управляющий',
        'hint' => 'Всё, кроме доступов и паролей',
        'perms' => [
            'schedule.view_all', 'schedule.edit', 'tasks.assign', 'cleaning.assign', 'issues.resolve', 'news.publish',
            'guests.decide', 'content.edit', 'wiki.edit', 'fines.manage', 'exams.manage', 'course.manage', 'staff.manage', 'journal.view',
            'page.cash', 'page.reconcile', 'page.missing',
            'page.swaps', 'page.bar', 'page.cleaning', 'page.issues', 'page.news',
            'page.guests', 'page.reviews', 'page.compensations',
            'page.wiki', 'page.tariffs', 'page.fines', 'page.exams',
            'page.insights', 'insight.heatmap', 'insight.branches', 'insight.payments', 'insight.goods', 'insight.cancels', 'insight.shifts',
            ...STATS_DEFAULTS,
        ],
    ],
    'senior' => [
        'label' => 'Старший администратор',
        'hint' => 'График, задания, чистка, поломки и объявления',
        'perms' => [
            'schedule.edit', 'tasks.assign', 'cleaning.assign', 'issues.resolve', 'news.publish',
            'page.reconcile', 'page.missing',
            'page.swaps', 'page.bar', 'page.cleaning', 'page.issues', 'page.news',
            'page.guests', 'page.reviews', 'page.compensations',
            'page.wiki', 'page.tariffs', 'page.fines', 'page.exams',
            'page.insights', 'insight.heatmap', 'insight.goods',
            ...STATS_DEFAULTS,
        ],
    ],
    'admin' => [
        'label' => 'Администратор',
        'hint' => 'Смена и бар, без кассы бизнеса и управления',
        'perms' => [...PAGE_STAFF_DEFAULTS, ...STATS_DEFAULTS],
    ],
];

function normalize_role(?string $role): string
{
    return isset(ROLES[(string) $role]) ? (string) $role : 'admin';
}

function role_label(?string $role): string
{
    return ROLES[normalize_role($role)]['label'];
}

function role_preset(string $role): array
{
    $perms = ROLES[normalize_role($role)]['perms'];
    return $perms === '*' ? array_keys(PERMISSIONS) : $perms;
}

/** Права из строки "user": явный список, если задан, иначе шаблон роли. */
function perms_from_row(array $row): array
{
    $role = normalize_role($row['role'] ?? 'admin');
    if ($role === 'owner') {
        return array_keys(PERMISSIONS);
    }
    $raw = $row['permissions'] ?? null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $perms = array_values(array_intersect(array_keys(PERMISSIONS), $decoded));
            // Старые ручные наборы ещё без page.* — иначе у сотрудника пропадут вкладки.
            $hasPage = false;
            foreach ($perms as $p) {
                if (str_starts_with($p, 'page.')) {
                    $hasPage = true;
                    break;
                }
            }
            if (!$hasPage) {
                $rolePages = array_values(array_filter(role_preset($role), static fn (string $p): bool => str_starts_with($p, 'page.')));
                $perms = array_values(array_unique(array_merge($perms, $rolePages)));
            }
            if (!in_array(PERMS_MARKER, $decoded, true)) {
                $perms = array_values(array_unique(array_merge($perms, array_intersect(role_preset($role), PERMS_ADDED_V2))));
            }
            return $perms;
        }
    }
    return role_preset($role);
}

/**
 * Филиалы по умолчанию, пока владелец не выбрал их сам: владелец и управляющий работают
 * со всеми, остальные — с филиалом своей карточки сотрудника. Без карточки — ни с одним.
 * @return int[]|null null — все филиалы
 */
function default_branch_ids(string $role, ?int $homeBranchId): ?array
{
    if ($role === 'owner' || $role === 'manager') {
        return null;
    }
    return $homeBranchId ? [$homeBranchId] : [];
}

/**
 * Филиалы учётной записи из колонки "user"."branchIds":
 * пусто — по правилу роли, '*' — все, JSON-массив — выбранные владельцем.
 * @return int[]|null null — все филиалы
 */
function branch_ids_from_row(array $row, ?int $homeBranchId): ?array
{
    $role = normalize_role($row['role'] ?? 'admin');
    if ($role === 'owner') {
        return null;
    }
    $raw = $row['branchIds'] ?? null;
    if ($raw === '*') {
        return null;
    }
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_unique(array_filter(array_map('intval', $decoded), static fn (int $id): bool => $id > 0)));
        }
    }
    return default_branch_ids($role, $homeBranchId);
}

/**
 * Филиалы, закреплённые за пользователем: в них действуют права с пометкой «филиал».
 * @return int[]|null null — все филиалы
 */
function user_attached_branch_ids(array $user): ?array
{
    if (!empty($user['isOwner'])) {
        return null;
    }
    if (array_key_exists('branchIds', $user)) {
        return $user['branchIds'];
    }
    $home = (int) ($user['homeBranchId'] ?? 0);
    return $home > 0 ? [$home] : [];
}

/**
 * Филиалы, данные которых пользователь видит: закреплённые и те, где у него смена сегодня —
 * на подмене в чужом филиале нужно оформлять гостей и поломки этого филиала.
 * @return int[]|null null — все филиалы
 */
function user_branch_ids(array $user): ?array
{
    $ids = user_attached_branch_ids($user);
    if ($ids === null) {
        return null;
    }
    return array_values(array_unique(array_merge($ids, array_map('intval', $user['shiftBranchIds'] ?? []))));
}

function sees_branch(array $user, int $branchId): bool
{
    $ids = user_branch_ids($user);
    return $ids === null || in_array($branchId, $ids, true);
}

/**
 * Проверка права. Если передан филиал, а право действует по филиалам,
 * оно разрешено только в филиалах, закреплённых за пользователем.
 */
function can(array $user, string $perm, ?int $branchId = null): bool
{
    if (!empty($user['isOwner'])) {
        return true;
    }
    if ($perm === 'owner' || !in_array($perm, $user['perms'] ?? [], true)) {
        return false;
    }
    if ($perm === 'schedule.edit' && $branchId !== null && !can_view_schedule($user, $branchId)) {
        return false;
    }
    if ($branchId !== null && !empty(PERMISSIONS[$perm]['scoped'])) {
        $ids = user_attached_branch_ids($user);
        return $ids === null || in_array($branchId, $ids, true);
    }
    return true;
}

/** Части редактора курса, которые можно выдать отдельно. */
const COURSE_AREAS = ['edit', 'structure', 'settings'];

/** Право на часть курса: course.manage покрывает всё, course.<area> — только свою часть. */
function can_course(array $user, string $area): bool
{
    if (!in_array($area, COURSE_AREAS, true)) {
        return false;
    }
    return can($user, 'course.manage') || can($user, 'course.' . $area);
}

/** Есть хоть какое-то право на редактирование курса — видит курс целиком, без замков. */
function can_course_any(array $user): bool
{
    foreach (COURSE_AREAS as $area) {
        if (can_course($user, $area)) {
            return true;
        }
    }
    return false;
}

function can_any(array $user, array $perms): bool
{
    foreach ($perms as $p) {
        if (can($user, $p)) {
            return true;
        }
    }
    return false;
}

/**
 * Филиалы, в которых у пользователя действует право $perm.
 * @return int[]|null null — все филиалы
 */
function perm_branches(array $user, string $perm): ?array
{
    if (!empty($user['isOwner']) || empty(PERMISSIONS[$perm]['scoped'])) {
        return null;
    }
    return user_attached_branch_ids($user);
}

/** Просмотр не расширяет права редактирования; без привязки к филиалу доступ закрыт. */
function can_view_schedule(array $user, int $branchId): bool
{
    return can($user, 'schedule.view_all') || ($branchId > 0 && sees_branch($user, $branchId));
}

const AUDIT_ENTITIES = [
    'schedule' => 'График',
    'handover' => 'Приём и сдача',
    'tasks' => 'Задания',
    'cleaning' => 'Чистка',
    'issues' => 'Поломки',
    'news' => 'Объявления',
    'guests' => 'Гости',
    'content' => 'Тарифы и акции',
    'wiki' => 'База знаний',
    'fines' => 'Штрафы',
    'exams' => 'Экзамены и тесты',
    'course' => 'Обучение',
    'staff' => 'Сотрудники',
    'access' => 'Доступы',
];

function audit(array $user, string $entity, string $action, string $summary, string $href = '/'): void
{
    try {
        Db::exec(
            'INSERT INTO audit_log ("actorUserId","actorName","actorRole",entity,action,summary,href,"createdAt") VALUES (?,?,?,?,?,?,?,?)',
            [
                (string) $user['id'],
                mb_substr((string) ($user['employeeName'] ?? $user['name']), 0, 120),
                (string) ($user['role'] ?? 'admin'),
                $entity,
                mb_substr($action, 0, 40),
                mb_substr($summary, 0, 500),
                mb_substr($href, 0, 255),
                now_iso(),
            ],
        );
        if (random_int(1, 200) === 1) {
            Db::exec('DELETE FROM audit_log WHERE "createdAt" < ?', [now_tyumen()->modify('-365 days')->format('Y-m-d')]);
        }
    } catch (Throwable $e) {
        error_log('[gamerpro] audit failed: ' . $e->getMessage());
    }
}
