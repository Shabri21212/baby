<?php
declare(strict_types=1);

foreach (['shifts', 'handover', 'club', 'guests', 'rules', 'exams', 'course', 'manage', 'bar'] as $part) {
    require __DIR__ . '/pages/' . $part . '.php';
    require __DIR__ . '/actions/' . $part . '.php';
}
require __DIR__ . '/pages/cash.php';
require_once __DIR__ . '/insights.php';
require_once __DIR__ . '/pages/insights.php';

require_once __DIR__ . '/pages/salary.php';

final class Portal
{
    use PagesSalary;
    use PagesInsights;
    use PagesShifts, PagesHandover, PagesClub, PagesGuests, PagesRules, PagesExams, PagesCourse, PagesManage, PagesBar, PagesCash;
    use ActionsShifts, ActionsHandover, ActionsClub, ActionsGuests, ActionsRules, ActionsExams, ActionsCourse, ActionsManage, ActionsBar;

    /** path => [метод, право] */
    private const ROUTES = [
        '/' => ['pageHome', null],
        '/shift' => ['pageHandover', null],
        '/cash' => ['pageCash', 'page.cash'],
        '/insights' => ['pageInsights', 'page.insights'],
        '/shift/reconcile' => ['pageShiftReconcile', 'page.reconcile'],
        '/shift/swaps' => ['pageShiftSwaps', 'page.swaps'],
        '/shift/missing' => ['pageShiftMissing', 'page.missing'],
        '/schedule' => ['pageSchedule', null],
        '/cleaning' => ['pageCleaning', 'page.cleaning'],
        '/issues' => ['pageIssues', 'page.issues'],
        '/news' => ['pageNews', 'page.news'],
        '/loyalty' => ['pageLoyalty', 'page.tariffs'],
        '/loyalty/edit' => ['pageLoyaltyEdit', 'content.edit'],
        '/guests/club' => ['pageGuestsClub', 'page.guests'],
        '/reviews' => ['pageReviews', 'page.reviews'],
        '/reviews/edit' => ['pageReviewsEdit', 'content.edit'],
        '/compensations' => ['pageCompensations', 'page.compensations'],
        '/tariffs' => ['pageTariffs', 'page.tariffs'],
        '/tariffs/edit' => ['pageTariffsEdit', 'content.edit'],
        '/fines' => ['pageFines', 'page.fines'],
        '/fines/assign' => ['pageFinesAssign', 'fines.manage'],
        '/fines/edit' => ['pageFinesEdit', 'fines.manage'],
        '/wiki/new' => ['pageWikiNew', 'wiki.edit'],
        '/exams' => ['pageCourse', 'page.exams'],
        '/exams/tests' => ['pageExams', 'page.exams'],
        '/exams/settings' => ['pageCourseSettings', 'page.exams'],
        '/account' => ['pageAccount', null],
        '/salary' => ['pageSalary', null],
        '/manage/journal' => ['pageJournal', 'journal.view'],
        '/manage/employees' => ['pageEmployees', 'staff.manage'],
        '/manage/grades' => ['pageGrades', 'staff.manage'],
        '/manage/tasks' => ['pageTasks', 'tasks.assign'],
        '/manage/accounts' => ['pageAccounts', 'owner'],
        '/manage/exams' => ['pageManageExams', 'exams.manage'],
        '/manage/database' => ['pageDatabase', 'owner'],
        '/manage/langame' => ['pageLangame', 'owner'],
        '/manage/checklist' => ['pageChecklistEdit', 'owner'],
        '/manage/notify' => ['pageNotifySettings', 'owner'],
        '/bar' => ['pageBar', 'page.bar'],
    ];

    private const ACTIONS = [
        'complete_task' => 'doCompleteTask',
        'uncomplete_task' => 'doUncompleteTask',
        'complete_daily_task' => 'doCompleteDailyTask',
        'uncomplete_daily_task' => 'doUncompleteDailyTask',
        'save_daily_task' => 'doSaveDailyTask',
        'delete_daily_task' => 'doDeleteDailyTask',
        'save_presence' => 'doSavePresence',
        'delete_presence' => 'doDeletePresence',
        'set_task_off_day' => 'doSetTaskOffDay',
        'clear_task_off_day' => 'doClearTaskOffDay',
        'save_handover' => 'doSaveHandover',
        'save_shift' => 'doSaveShift',
        'save_day' => 'doSaveDay',
        'save_shifts_bulk' => 'doSaveShiftsBulk',
        'delete_shift' => 'doDeleteShift',
        'copy_week' => 'doCopyWeek',
        'toggle_clean' => 'doToggleClean',
        'assign_seats' => 'doAssignSeats',
        'assign_staff_task' => 'doAssignStaffTask',
        'delete_staff_task' => 'doDeleteStaffTask',
        'save_incident' => 'doSaveIncident',
        'incident_status' => 'doIncidentStatus',
        'save_news' => 'doSaveNews',
        'read_news' => 'doReadNews',
        'pin_news' => 'doPinNews',
        'delete_news' => 'doDeleteNews',
        'save_review' => 'doSaveReview',
        'decide_review' => 'doDecideReview',
        'save_comp' => 'doSaveComp',
        'decide_comp' => 'doDecideComp',
        'save_loyalty' => 'doSaveLoyalty',
        'save_promo' => 'doSavePromo',
        'save_wiki' => 'doSaveWiki',
        'create_wiki' => 'doCreateWiki',
        'delete_wiki' => 'doDeleteWiki',
        'save_tariffs' => 'doSaveTariffs',
        'save_fine_rule' => 'doSaveFineRule',
        'delete_fine_rule' => 'doDeleteFineRule',
        'assign_fine' => 'doAssignFine',
        'cancel_fine' => 'doCancelFine',
        'create_exam' => 'doCreateExam',
        'save_exam' => 'doSaveExam',
        'delete_exam' => 'doDeleteExam',
        'start_attempt' => 'doStartAttempt',
        'submit_attempt' => 'doSubmitAttempt',
        'course_progress' => 'doCourseProgress',
        'complete_step' => 'doCompleteStep',
        'submit_equator' => 'doSubmitEquator',
        'reset_course_progress' => 'doResetCourseProgress',
        'save_course_module' => 'doSaveCourseModule',
        'move_course_module' => 'doMoveCourseModule',
        'toggle_course_module' => 'doToggleCourseModule',
        'delete_course_module' => 'doDeleteCourseModule',
        'create_course_chapter' => 'doCreateCourseChapter',
        'save_course_meta' => 'doSaveCourseMeta',
        'move_course_chapter' => 'doMoveCourseChapter',
        'toggle_course_chapter' => 'doToggleCourseChapter',
        'delete_course_chapter' => 'doDeleteCourseChapter',
        'create_course_step' => 'doCreateCourseStep',
        'save_course_step' => 'doSaveCourseStep',
        'delete_course_step' => 'doDeleteCourseStep',
        'move_course_step' => 'doMoveCourseStep',
        'import_course' => 'doImportCourse',
        'upload_course_image' => 'doUploadCourseImage',
        'save_course_settings' => 'doSaveCourseSettings',
        'save_employee' => 'doSaveEmployee',
        'update_employee' => 'doUpdateEmployee',
        'mark_payout' => 'doMarkPayout',
        'save_pay_details' => 'doSavePayDetails',
        'save_mgr_bar' => 'doSaveMgrBar',
        'toggle_employee' => 'doToggleEmployee',
        'delete_employee' => 'doDeleteEmployee',
        'assign_grade' => 'doAssignGrade',
        'save_grade' => 'doSaveGrade',
        'save_langame' => 'doSaveLangame',
        'test_langame' => 'doTestLangame',
        'sync_langame' => 'doSyncLangame',
        'refresh_langame' => 'doRefreshLangame',
        'link_langame_staff' => 'doLinkLangameStaff',
        'forget_langame_token' => 'doForgetLangameToken',
        'init_langame_cron' => 'doInitLangameCron',
        'delete_grade' => 'doDeleteGrade',
        'save_account' => 'doSaveAccount',
        'update_access' => 'doUpdateAccess',
        'reset_password' => 'doResetPassword',
        'delete_account' => 'doDeleteAccount',
        'change_password' => 'doChangePassword',
        'save_avatar' => 'doSaveAvatar',
        'remove_avatar' => 'doRemoveAvatar',
        'save_my_prefs' => 'doSaveMyPrefs',
        'save_user_prefs' => 'doSaveUserPrefs',
        'save_telegram_bot' => 'doSaveTelegramBot',
        'test_telegram' => 'doTestTelegram',
        'request_shift_swap' => 'doRequestShiftSwap',
        'claim_shift_swap' => 'doClaimShiftSwap',
        'cancel_shift_swap' => 'doCancelShiftSwap',
        'save_checklist_item' => 'doSaveChecklistItem',
        'delete_checklist_item' => 'doDeleteChecklistItem',
        'stock_assign_task' => 'doStockAssignTask',
        'download_dump' => 'doDownloadDump',
    ];

    private static ?array $countsCache = null;
    private static ?array $branchesCache = null;

    public static function run(): void
    {
        send_security_headers();
        start_session();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = request_path();
        if ($method === 'POST') {
            $action = (string) ($_POST['_action'] ?? '');
            if ($path === '/sign-in' && $action === 'login') {
                self::doLogin();
                return;
            }
            if ($action === 'refresh_langame' || $action === 'course_progress') {
                // Фоновый fetch: при битом CSRF отвечаем JSON, без redirect/HTML.
                $csrfOk = hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['_csrf'] ?? ''));
                if (!$csrfOk) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(419);
                    echo json_encode(['ok' => false, 'errors' => ['csrf']], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            } else {
                csrf_check();
            }
            if ($action === 'logout') {
                self::logout();
            }
            $user = self::user();
            if (!$user) {
                if ($action === 'refresh_langame' || $action === 'course_progress') {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(401);
                    echo json_encode(['ok' => false, 'errors' => ['auth']], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                redirect_to('/sign-in');
            }
            $handler = self::ACTIONS[$action] ?? null;
            if ($handler === null) {
                redirect_to($path);
            }
            if ($action === 'refresh_langame') {
                // Запрос к Langame идёт до 15 секунд на филиал. Пока он держит файл сессии,
                // любая другая вкладка того же пользователя ждёт его целиком — отсюда «не открывается».
                session_write_close();
            }
            self::$handler($user);
            return;
        }
        self::get($path);
    }

    private static function get(string $path): void
    {
        if ($path === '/sign-in') {
            self::pageSignIn();
            return;
        }
        if ($path === '/cron/langame') {
            self::pageCronLangame();
            return;
        }
        if (preg_match('#^/media/avatar/([a-zA-Z0-9_-]+)$#', $path, $m)) {
            Avatars::serve($m[1]);
        }
        if (preg_match('#^/media/course/([A-Za-z0-9._-]+)$#', $path, $m)) {
            CourseMedia::serve($m[1]);
        }
        if ($path === '/sign-out') {
            self::logout();
        }
        $user = self::user();
        if (!$user) {
            redirect_to('/sign-in');
        }
        if (isset(self::ROUTES[$path])) {
            [$page, $perm] = self::ROUTES[$path];
            if ($perm !== null) {
                self::requirePerm($user, $perm);
            }
            self::$page($user);
            return;
        }
        if ($path === '/wiki') {
            self::requirePerm($user, 'page.wiki');
            $slug = self::firstArticleSlug($user);
            if ($slug) {
                redirect_to('/wiki/' . rawurlencode($slug));
            }
            self::pageWikiEmpty($user);
            return;
        }
        if (preg_match('#^/wiki/([^/]+)/edit$#', $path, $m)) {
            self::requirePerm($user, 'wiki.edit');
            self::pageWikiEdit($user, rawurldecode($m[1]));
            return;
        }
        if (preg_match('#^/wiki/([^/]+)$#', $path, $m)) {
            self::requirePerm($user, 'page.wiki');
            $slug = rawurldecode($m[1]);
            if ($slug === 'fines') {
                redirect_to('/fines');
            }
            self::pageWiki($user, $slug);
            return;
        }
        if (preg_match('#^/exams/chapter/(\d+)/edit$#', $path, $m)) {
            // Редактор главы нужен и тому, кто правит тексты, и тому, кто меняет структуру.
            self::requirePerm($user, can_course($user, 'structure') ? 'course.structure' : 'course.edit');
            self::pageCourseChapterEdit($user, (int) $m[1]);
            return;
        }
        if (preg_match('#^/exams/chapter/(\d+)$#', $path, $m)) {
            self::requirePerm($user, 'page.exams');
            self::pageCourseChapter($user, (int) $m[1]);
            return;
        }
        if (preg_match('#^/exams/([a-z0-9-]+)/edit$#', $path, $m)) {
            self::requirePerm($user, 'exams.manage');
            self::pageExamEdit($user, $m[1]);
            return;
        }
        if (preg_match('#^/exams/([a-z0-9-]+)/attempt/(\d+)$#', $path, $m)) {
            self::requirePerm($user, 'page.exams');
            self::pageExamAttempt($user, $m[1], (int) $m[2]);
            return;
        }
        if (preg_match('#^/exams/([a-z0-9-]+)/result/(\d+)$#', $path, $m)) {
            self::requirePerm($user, 'page.exams');
            self::pageExamResult($user, $m[1], (int) $m[2]);
            return;
        }
        if (preg_match('#^/exams/([a-z0-9-]+)$#', $path, $m)) {
            self::requirePerm($user, 'page.exams');
            self::pageExamStart($user, $m[1]);
            return;
        }
        self::notFound($user);
    }

    private static function notFound(array $user, string $text = 'Такой страницы нет — возможно, ссылка устарела.'): void
    {
        http_response_code(404);
        self::layout($user, '<div class="py-10">' . empty_state('search', 'Страница не найдена', $text, '<a class="' . btn_class('outline') . '" href="' . h(url_to('/')) . '">' . icon('home') . 'На главную</a>') . '</div>', ['title' => 'Не найдено', 'width' => 'narrow']);
    }

    /** HTTP-cron для reg.ru: /cron/langame?key=… (ключ только из storage/cron.key). */
    private static function pageCronLangame(): void
    {
        $keyFile = dirname(__DIR__) . '/storage/cron.key';
        $secret = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
        $given = (string) ($_GET['key'] ?? '');
        if ($secret === '' || !hash_equals($secret, $given)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo $secret === '' ? "Cron key missing. Create storage/cron.key or run: php scripts/cron-langame.php --init-key\n" : "Forbidden\n";
            exit;
        }
        @set_time_limit(180);
        $result = Langame::refreshNow(Langame::enabledBranchIds(), true);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function logout(): never
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        redirect_to('/sign-in');
    }

    private static function user(): ?array
    {
        static $cached = false;
        if ($cached !== false) {
            return $cached;
        }
        $id = $_SESSION['uid'] ?? null;
        if (!$id) {
            return $cached = null;
        }
        try {
            $u = Db::one('SELECT id, name, email, role, permissions, "branchIds", image, prefs FROM "user" WHERE id = ?', [$id]);
        } catch (PDOException) {
            try {
                $u = Db::one('SELECT id, name, email, role, permissions, "branchIds", image FROM "user" WHERE id = ?', [$id]);
            } catch (PDOException) {
                try {
                    $u = Db::one('SELECT id, name, email, role, permissions, "branchIds" FROM "user" WHERE id = ?', [$id]);
                } catch (PDOException) {
                    $u = Db::one('SELECT id, name, email, role FROM "user" WHERE id = ?', [$id]);
                }
            }
        }
        if (!$u) {
            return $cached = null;
        }
        $emp = Db::one('SELECT id, name, "homeBranchId", "gradeId" FROM employees WHERE "userId" = ?', [$u['id']]);
        $role = normalize_role($u['role'] ?? 'admin');
        $email = (string) $u['email'];
        $login = str_ends_with($email, '@' . LOGIN_DOMAIN) ? substr($email, 0, -strlen('@' . LOGIN_DOMAIN)) : $email;
        $home = $emp ? (int) $emp['homeBranchId'] : null;
        $image = isset($u['image']) ? (string) $u['image'] : '';
        return $cached = [
            'id' => (string) $u['id'],
            'name' => (string) $u['name'],
            'login' => $login,
            'image' => $image !== '' ? $image : null,
            'role' => $role,
            'roleLabel' => role_label($role),
            'isOwner' => $role === 'owner',
            'perms' => perms_from_row($u),
            'branchIds' => branch_ids_from_row($u, $home),
            'shiftBranchIds' => $emp ? self::shiftBranchIdsToday((int) $emp['id']) : [],
            'employeeId' => $emp ? (int) $emp['id'] : null,
            'employeeName' => $emp['name'] ?? null,
            'homeBranchId' => $home,
            'gradeId' => $emp && $emp['gradeId'] !== null ? (int) $emp['gradeId'] : null,
            'prefs' => Notify::prefs($u),
        ];
    }

    /** Карта employeeId → URL аватара (для таблиц команды). */
    private static function employeeAvatarUrls(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        try {
            foreach (Db::all('SELECT e.id AS eid, u.id AS uid, u.image FROM employees e JOIN "user" u ON u.id = e."userId" WHERE u.image IS NOT NULL AND u.image != \'\'') as $r) {
                $map[(int) $r['eid']] = avatar_url((string) $r['uid'], (string) $r['image']);
            }
        } catch (PDOException) {
        }
        return $map;
    }

    private static function avatarFor(array $user, string $class = 'size-9 text-xs', string $tone = 'ink'): string
    {
        return avatar(self::displayName($user), $class, $tone, avatar_url($user['id'] ?? null, $user['image'] ?? null));
    }

    /**
     * Филиалы, где у сотрудника смена сегодня (или ещё идёт вчерашняя ночная). Идущая смена — первой:
     * её филиал подставляется в формы по умолчанию.
     * @return int[]
     */
    private static function shiftBranchIdsToday(int $employeeId): array
    {
        $now = now_tyumen();
        $today = date_key($now);
        $rows = Db::all(
            'SELECT date, "branchId", "shiftType" FROM shifts WHERE "employeeId" = ? AND date IN (?, ?)',
            [$employeeId, $now->modify('-1 day')->format('Y-m-d'), $today],
        );
        $live = [];
        $rest = [];
        foreach ($rows as $r) {
            $date = substr((string) $r['date'], 0, 10);
            $bid = (int) $r['branchId'];
            if (shift_state($date, (string) $r['shiftType'], $now, $bid)['state'] === 'live') {
                $live[$bid] = true;
            } elseif ($date === $today) {
                $rest[$bid] = true;
            }
        }
        return array_keys($live + $rest);
    }

    private static function displayName(array $user): string
    {
        if (($user['role'] ?? '') === 'owner') {
            return 'Руководство';
        }
        return (string) ($user['employeeName'] ?? $user['name']);
    }

    private static function requirePerm(array $user, string $perm, ?int $branchId = null): void
    {
        if (can($user, $perm, $branchId)) {
            return;
        }
        http_response_code(403);
        $text = $perm === 'owner'
            ? 'Раздел доступен только владельцу.'
            : 'Нужно право «' . (PERMISSIONS[$perm]['label'] ?? $perm) . '». Попросите владельца выдать его в разделе «Доступы и права».';
        self::layout($user, '<div class="py-10">' . empty_state('lock', 'Нет доступа', $text, '<a class="' . btn_class('outline') . '" href="' . h(url_to('/')) . '">' . icon('home') . 'На главную</a>') . '</div>', ['title' => 'Нет доступа', 'width' => 'narrow']);
        exit;
    }

    /** Для POST: без права — сообщение и возврат на страницу. */
    private static function permOrFail(array $user, string $perm, ?int $branchId = null, string $back = '/'): void
    {
        if (can($user, $perm, $branchId)) {
            return;
        }
        $label = PERMISSIONS[$perm]['label'] ?? 'это действие';
        $scope = perm_branches($user, $perm);
        $limited = $scope !== null && $branchId !== null && in_array($perm, $user['perms'] ?? [], true) && !in_array($branchId, $scope, true);
        $msg = $limited
            ? 'Ваше право «' . $label . '» действует только в ' . ($scope ? self::branchNames($scope) : 'ваших филиалах, а они не назначены')
            : 'Недостаточно прав: «' . $label . '»';
        flash_set('error', $msg);
        redirect_back($back);
    }

    private static function postInt(string $key): int
    {
        return (int) ($_POST[$key] ?? 0);
    }

    private static function postStr(string $key, int $max = 2000): string
    {
        $v = $_POST[$key] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }

    private static function getStr(string $key, int $max = 120): string
    {
        $v = $_GET[$key] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }

    private static function doLogin(): void
    {
        $login = strtolower(trim((string) ($_POST['login'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $_SESSION['last_login'] = mb_substr($login, 0, 64);
        if ($login === '' || $password === '') {
            flash_set('error', 'Введите логин и пароль');
            redirect_to('/sign-in');
        }
        $ip = client_ip();
        $now = time();
        try {
            if (random_int(1, 20) === 1) {
                Db::exec('DELETE FROM login_attempts WHERE "createdAt" < ?', [$now - 86400]);
            }
            $byIp = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND "createdAt" > ?', [$ip, $now - 900]);
            $byLogin = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE login = ? AND "createdAt" > ?', [$login, $now - 900]);
        } catch (PDOException) {
            $byIp = $byLogin = 0;
        }
        if ($byIp >= 20 || $byLogin >= 8) {
            flash_set('error', 'Слишком много неудачных попыток. Подождите 15 минут или попросите владельца сбросить пароль.');
            redirect_to('/sign-in');
        }
        $u = Db::one('SELECT id, role FROM "user" WHERE email = ?', [$login . '@' . LOGIN_DOMAIN]);
        $acc = $u ? Db::one('SELECT id, password, password_php FROM account WHERE "userId" = ? AND "providerId" = ?', [$u['id'], 'credential']) : null;
        $ok = false;
        if ($acc) {
            if (!empty($acc['password_php']) && password_verify($password, (string) $acc['password_php'])) {
                $ok = true;
                if (password_needs_rehash((string) $acc['password_php'], PASSWORD_DEFAULT)) {
                    Db::exec('UPDATE account SET password_php = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $acc['id']]);
                }
            } elseif (!empty($acc['password'])) {
                @ini_set('memory_limit', '256M');
                $ok = Scrypt::verifyBetterAuth((string) $acc['password'], $password);
                if ($ok) {
                    Db::exec('UPDATE account SET password_php = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $acc['id']]);
                }
            }
        }
        if (!$ok) {
            try {
                Db::exec('INSERT INTO login_attempts (ip, login, "createdAt") VALUES (?,?,?)', [$ip, $login, $now]);
            } catch (PDOException) {
            }
            flash_set('error', 'Неверный логин или пароль');
            redirect_to('/sign-in');
        }
        try {
            Db::exec('DELETE FROM login_attempts WHERE login = ?', [$login]);
        } catch (PDOException) {
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        unset($_SESSION['last_login']);
        redirect_to('/');
    }

    /* ---------- общие данные ---------- */

    private static function counts(array $user): array
    {
        if (self::$countsCache !== null) {
            return self::$countsCache;
        }
        $news = (int) Db::value(
            'SELECT COUNT(*) FROM announcements a
             WHERE a."isDraft" = 0 AND NOT EXISTS (
               SELECT 1 FROM announcement_reads r WHERE r."announcementId" = a.id AND r."userId" = ?
             )',
            [$user['id']],
        );
        $visible = user_branch_ids($user);
        $issues = (int) Db::value('SELECT COUNT(*) FROM incidents WHERE status != ?' . ($visible !== null ? ' AND "branchId" IN (' . self::idList($visible) . ')' : ''), ['done']);
        $reviews = 0;
        $comps = 0;
        if (can($user, 'guests.decide')) {
            $scope = perm_branches($user, 'guests.decide');
            $where = $scope !== null ? ' AND "branchId" IN (' . self::idList($scope) . ')' : '';
            $reviews = (int) Db::value('SELECT COUNT(*) FROM review_bonuses WHERE status = ?' . $where, ['pending']);
            $comps = (int) Db::value('SELECT COUNT(*) FROM compensations WHERE status = ?' . $where, ['pending']);
        }
        return self::$countsCache = ['news' => $news, 'issues' => $issues, 'reviews' => $reviews, 'compensations' => $comps];
    }

    private static function branches(): array
    {
        return self::$branchesCache ??= array_map(static function ($b) {
            $b['id'] = (int) $b['id'];
            $b['pcCount'] = (int) $b['pcCount'];
            return $b;
        }, Db::all('SELECT id, name, slug, "pcCount" FROM branches ORDER BY "sortOrder", id'));
    }

    private static function branchName(?int $id): string
    {
        foreach (self::branches() as $b) {
            if ($b['id'] === $id) {
                return (string) $b['name'];
            }
        }
        return '—';
    }

    private static function branchById(int $id): ?array
    {
        foreach (self::branches() as $b) {
            if ($b['id'] === $id) {
                return $b;
            }
        }
        return null;
    }

    /** Список id для SQL «IN (…)»; для пустого списка — значение, которого нет ни у одной строки. */
    private static function idList(array $ids): string
    {
        return $ids ? implode(',', array_map('intval', $ids)) : '-1';
    }

    /** «Гондатти, Киевская» в порядке филиалов. */
    private static function branchNames(array $ids): string
    {
        $names = [];
        foreach (self::branches() as $b) {
            if (in_array($b['id'], $ids, true)) {
                $names[] = (string) $b['name'];
            }
        }
        return implode(', ', $names);
    }

    /** Филиалы, данные которых видит пользователь: закреплённые за ним и филиал сегодняшней смены. */
    private static function visibleBranches(array $user): array
    {
        $ids = user_branch_ids($user);
        if ($ids === null) {
            return self::branches();
        }
        return array_values(array_filter(self::branches(), static fn (array $b): bool => in_array($b['id'], $ids, true)));
    }

    /** Филиал, где пользователь работает сейчас: сегодняшняя смена, иначе филиал его карточки. */
    private static function workBranchId(array $user): ?int
    {
        return $user['shiftBranchIds'][0] ?? $user['homeBranchId'] ?? null;
    }

    /**
     * Филиал из ?branch=slug, иначе рабочий филиал пользователя, иначе первый.
     * Выбор идёт среди $among; по умолчанию — среди филиалов, которые пользователь видит.
     */
    private static function pickBranch(array $user, ?array $among = null): ?array
    {
        $branches = $among ?? self::visibleBranches($user);
        if (!$branches) {
            return null;
        }
        $slug = self::getStr('branch', 64);
        foreach ($branches as $b) {
            if ($slug !== '' && $b['slug'] === $slug) {
                return $b;
            }
        }
        $work = self::workBranchId($user);
        foreach ($branches as $b) {
            if ($b['id'] === $work) {
                return $b;
            }
        }
        return $branches[0];
    }

    /** Переключатель филиалов; если выбирать не из чего — просто название текущего. */
    private static function branchSwitch(string $path, array $current, array $branches, array $query = []): string
    {
        if (count($branches) < 2) {
            return chip((string) $current['name'], 'outline', 'building');
        }
        $items = [];
        foreach ($branches as $b) {
            $items[] = [
                'href' => url_to($path, array_merge($query, ['branch' => $b['slug']])),
                'label' => $b['name'],
                'active' => $b['id'] === $current['id'],
            ];
        }
        return segmented($items, 'Филиал');
    }

    /**
     * Филиалы, которые владелец явно выдал учётным записям сотрудников: [employeeId => int[]|null].
     * null — все филиалы. Учётки без своего списка сюда не попадают: они работают в филиале карточки.
     */
    private static function employeeExtraBranches(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        try {
            $rows = Db::all('SELECT e.id, e."homeBranchId", u.role, u."branchIds" FROM employees e JOIN "user" u ON u.id = e."userId" WHERE u."branchIds" IS NOT NULL');
        } catch (PDOException) {
            $rows = [];
        }
        foreach ($rows as $r) {
            if (normalize_role($r['role']) !== 'owner') {
                $map[(int) $r['id']] = branch_ids_from_row($r, (int) $r['homeBranchId']);
            }
        }
        return $map;
    }

    /** Свой ли сотрудник для филиала: это филиал его карточки или филиал, выданный его учётной записи. */
    private static function worksAt(array $emp, int $branchId): bool
    {
        if ((int) $emp['homeBranchId'] === $branchId) {
            return true;
        }
        $extra = self::employeeExtraBranches();
        $id = (int) $emp['id'];
        return array_key_exists($id, $extra) && ($extra[$id] === null || in_array($branchId, $extra[$id], true));
    }

    /** $branchIds = null — любой филиал. */
    private static function worksInAny(array $emp, ?array $branchIds): bool
    {
        if ($branchIds === null) {
            return true;
        }
        foreach ($branchIds as $bid) {
            if (self::worksAt($emp, (int) $bid)) {
                return true;
            }
        }
        return false;
    }

    private static function employees(bool $activeOnly = false): array
    {
        $rows = Db::all('SELECT id, name, "homeBranchId", "userId", "isActive", "gradeId", "sortOrder" FROM employees ORDER BY "homeBranchId", "sortOrder", id');
        if ($activeOnly) {
            $rows = array_values(array_filter($rows, static fn ($e) => boolish($e['isActive'])));
        }
        return $rows;
    }

    /** Сотрудники, сгруппированные по филиалам — для <select> с optgroup. */
    private static function employeeOptions(?int $onlyBranch = null, ?int $firstBranch = null): array
    {
        $groups = [];
        $branches = self::branches();
        if ($firstBranch !== null) {
            usort($branches, static fn ($a, $b) => ($b['id'] === $firstBranch) <=> ($a['id'] === $firstBranch));
        }
        foreach ($branches as $b) {
            if ($onlyBranch !== null && $b['id'] !== $onlyBranch) {
                continue;
            }
            $list = [];
            foreach (self::employees(true) as $e) {
                if ((int) $e['homeBranchId'] === $b['id']) {
                    $list[(int) $e['id']] = (string) $e['name'];
                }
            }
            if ($list) {
                $groups[$b['name']] = $list;
            }
        }
        return $groups;
    }

    private static function portal_json(string $key, array $default): array
    {
        $row = Db::one('SELECT "valueJson" FROM portal_content WHERE "key" = ?', [$key]);
        if (!$row) {
            return $default;
        }
        $decoded = json_decode((string) $row['valueJson'], true);
        if (!is_array($decoded)) {
            return $default;
        }
        return $key === 'tariffs' ? $decoded + $default : array_replace_recursive($default, $decoded);
    }

    private static function save_portal_json(string $key, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (Db::one('SELECT "key" FROM portal_content WHERE "key" = ?', [$key])) {
            Db::exec('UPDATE portal_content SET "valueJson" = ?, "updatedAt" = ? WHERE "key" = ?', [$json, now_iso(), $key]);
        } else {
            Db::exec('INSERT INTO portal_content ("key", "valueJson", "updatedAt") VALUES (?,?,?)', [$key, $json, now_iso()]);
        }
    }

    /** Смены сотрудников за вчера и сегодня, которые идут прямо сейчас. */
    private static function liveShifts(?int $employeeId = null): array
    {
        $now = now_tyumen();
        $today = date_key($now);
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $params = [$yesterday, $today];
        $extra = '';
        if ($employeeId !== null) {
            $extra = ' AND s."employeeId" = ?';
            $params[] = $employeeId;
        }
        $rows = Db::all(
            'SELECT s.id, s.date, s."branchId", b.name AS "branchName", s."shiftType", s."employeeId", e.name AS "employeeName", e."homeBranchId"
             FROM shifts s
             JOIN employees e ON e.id = s."employeeId"
             JOIN branches b ON b.id = s."branchId"
             WHERE s.date IN (?, ?)' . $extra . '
             ORDER BY b."sortOrder", e."sortOrder", s.date',
            $params,
        );
        $out = [];
        foreach ($rows as $r) {
            $r['date'] = substr((string) $r['date'], 0, 10);
            $st = shift_state($r['date'], (string) $r['shiftType'], $now, (int) $r['branchId']);
            if ($st['state'] === 'live') {
                $r['state'] = $st;
                $out[] = $r;
            }
        }
        return $out;
    }

    private static function currentShiftFor(?int $employeeId): ?array
    {
        if (!$employeeId) {
            return null;
        }
        return self::liveShifts($employeeId)[0] ?? null;
    }

    /** Ближайшие смены сотрудника, которые ещё не закончились. */
    private static function upcomingShiftsFor(int $employeeId, int $days = 14): array
    {
        $now = now_tyumen();
        $from = $now->modify('-1 day')->format('Y-m-d');
        $to = $now->modify('+' . $days . ' days')->format('Y-m-d');
        $rows = Db::all(
            'SELECT s.id, s.date, s."branchId", b.name AS "branchName", s."shiftType", s."employeeId"
             FROM shifts s JOIN branches b ON b.id = s."branchId"
             WHERE s."employeeId" = ? AND s.date >= ? AND s.date <= ?
             ORDER BY s.date',
            [$employeeId, $from, $to],
        );
        $out = [];
        foreach ($rows as $r) {
            $r['date'] = substr((string) $r['date'], 0, 10);
            $st = shift_state($r['date'], (string) $r['shiftType'], $now, (int) $r['branchId']);
            if ($st['state'] !== 'done') {
                $r['state'] = $st;
                $out[] = $r;
            }
        }
        return $out;
    }

    private static function staffTasksFor(int $employeeId, string $date, ?string $userId = null): array
    {
        if ($userId) {
            return Db::all(
                'SELECT t.*, (SELECT d."completedAt" FROM staff_task_done d WHERE d."taskId" = t.id AND d."userId" = ? LIMIT 1) AS done_at
                 FROM staff_tasks t WHERE t."employeeId" = ? AND t.date = ? ORDER BY t.id',
                [$userId, $employeeId, $date],
            );
        }
        return Db::all(
            'SELECT t.*, (SELECT d."completedAt" FROM staff_task_done d WHERE d."taskId" = t.id LIMIT 1) AS done_at
             FROM staff_tasks t WHERE t."employeeId" = ? AND t.date = ? ORDER BY t.id',
            [$employeeId, $date],
        );
    }

    /* ---------- разметка ---------- */

    private static function layout(array $user, string $inner, array $opts = []): void
    {
        self::html($user, $inner, $opts);
    }

    private static function html(?array $user, string $inner, array $opts = []): void
    {
        $path = request_path();
        $current = find_nav_item($path);
        $title = (string) ($opts['title'] ?? ($current['label'] ?? 'Портал'));
        $flash = flash_take();
        $base = app_base();
        header('Content-Type: text/html; charset=utf-8');
        $out = '<!DOCTYPE html><html lang="ru" class="bg-background"><head>';
        $out .= '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        $out .= '<meta name="color-scheme" content="light dark"><meta name="theme-color" content="#f4f4f5"><meta name="robots" content="noindex, nofollow">';
        $out .= '<title>' . h($title . ' · ' . APP_NAME) . '</title>';
        $out .= '<link rel="icon" href="' . h(asset('img/icon-light-32x32.png')) . '" sizes="32x32">';
        $out .= '<link rel="apple-touch-icon" href="' . h(asset('img/apple-icon.png')) . '">';
        $out .= '<link rel="manifest" href="' . h(asset('manifest.webmanifest')) . '">';
        $out .= '<link rel="preload" href="' . h($base . '/assets/fonts/geist-cyrillic.woff2') . '" as="font" type="font/woff2" crossorigin>';
        $out .= '<link rel="preload" href="' . h($base . '/assets/fonts/unbounded-cyrillic.woff2') . '" as="font" type="font/woff2" crossorigin>';
        $out .= '<link rel="stylesheet" href="' . h(asset('css/app.css')) . '">';
        foreach ((array) ($opts['styles'] ?? []) as $style) {
            if (is_string($style) && $style !== '') {
                $out .= '<link rel="stylesheet" href="' . h(asset($style)) . '">';
            }
        }
        $out .= '<script>' . THEME_BOOT . '(function(){var m=document.querySelector(\'meta[name="theme-color"]\');if(m&&document.documentElement.classList.contains("dark"))m.content="#111113"})();</script>';
        $newYear = $user !== null && self::newYearOn();
        $out .= '</head><body class="min-h-dvh font-sans text-foreground antialiased' . ($user ? ' app-canvas' : '') . ($newYear ? ' is-newyear' : '') . '">';
        $out .= '<a href="#main" class="skip-link">Перейти к содержимому</a>';
        if ($newYear) {
            // Снег рисует assets/js/newyear.js; при «Уменьшить движение» холст остаётся пустым.
            $out .= '<canvas class="ny-snow" aria-hidden="true" data-ny-snow></canvas>';
        }
        if ($user) {
            $out .= self::shell($user, $path, $title, $inner, (string) ($opts['width'] ?? 'default'));
        } else {
            $out .= $inner;
        }
        if ($flash) {
            $isError = ($flash['type'] ?? '') === 'error';
            $out .= '<div class="pointer-events-none fixed inset-x-0 top-0 z-[70] flex flex-col items-center gap-2 p-3 lg:items-end lg:p-5">';
            $out .= '<div class="toast pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-popover px-4 py-3 text-sm text-popover-foreground shadow-lg ' . ($isError ? 'border-primary/40' : 'border-border') . '" role="' . ($isError ? 'alert' : 'status') . '" data-toast>';
            $out .= '<span class="mt-0.5 ' . ($isError ? 'text-primary-ink' : 'text-foreground') . '">' . icon($isError ? 'alert' : 'check-circle', 'size-4') . '</span>';
            $out .= '<p class="min-w-0 flex-1 leading-relaxed">' . h($flash['msg']) . '</p>';
            $out .= '<button type="button" class="-mt-1 -mr-2 ' . btn_class('quiet', 'icon-sm') . '" data-toast-close aria-label="Закрыть уведомление">' . icon('x', 'size-4') . '</button></div></div>';
        }
        $gp = ['csrf' => csrf_token(), 'base' => $base];
        $pending = Langame::pendingBranchIds();
        $pollBranches = array_values(array_filter(array_map('intval', (array) ($opts['langameBranches'] ?? [])), static fn ($id) => $id > 0));
        if ($pending) {
            $gp['langameRefresh'] = $pending;
        } elseif ($pollBranches) {
            // Ветки для кнопки «Обновить» и авто-опроса без принудительной очереди на каждый заход.
            $gp['langameBranches'] = $pollBranches;
        }
        if (!empty($opts['langameReload'])) {
            $gp['langameReload'] = true;
        }
        if (!empty($opts['langameForce'])) {
            $gp['langameForce'] = true;
        }
        $pollMs = (int) ($opts['langamePollMs'] ?? 0);
        if ($pollMs > 0) {
            $gp['langamePollMs'] = $pollMs;
            if (empty($gp['langameBranches']) && empty($gp['langameRefresh']) && $pollBranches) {
                $gp['langameBranches'] = $pollBranches;
            }
        }
        $out .= '<script>window.GP=' . json_encode($gp, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';</script>';
        foreach (array_merge(['app', 'pages'], $user ? ['motion'] : [], (array) ($opts['scripts'] ?? []), $newYear ? ['newyear'] : []) as $script) {
            $out .= '<script src="' . h(asset('js/' . $script . '.js')) . '" defer></script>';
        }
        $out .= '</body></html>';
        echo $out;
    }

    /** Новогоднее оформление включается в «Настройках курса» (Course::settings()['newYear']). */
    private static function newYearOn(): bool
    {
        return Course::settings()['newYear'];
    }

    /** Небольшая гирлянда над содержимым страницы: провод фестонами и мигающие лампочки. */
    private static function newYearGarland(): string
    {
        $bulbs = '';
        for ($i = 0; $i < 24; $i++) {
            $bulbs .= '<span class="ny-bulb" style="--i:' . $i . '"></span>';
        }
        return '<div class="ny-garland" aria-hidden="true"><div class="ny-garland__wire"></div>' . $bulbs . '</div>';
    }

    private static function shell(array $user, string $path, string $title, string $inner, string $width): string
    {
        $counts = self::counts($user);
        $widthClass = [
            'narrow' => 'max-w-3xl',
            'default' => 'max-w-6xl',
            'wide' => 'max-w-[88rem]',
        ][$width] ?? 'max-w-6xl';
        // Компактный режим применяем до первой отрисовки, иначе панель «прыгнет» после загрузки скриптов.
        $out = '<script>try{if(localStorage.getItem("gp-sb-rail")==="1")document.documentElement.classList.add("sb-rail")}catch(e){}</script>';
        $out .= '<div class="ambient" aria-hidden="true"></div>';
        $out .= '<div class="sb-shell min-h-dvh">';
        $out .= '<aside class="sb sb--desk" data-sidebar aria-label="Навигация">' . self::sidebar($user, $counts, $path, false) . '</aside>';
        $out .= '<script>' . self::sidebarBoot() . '</script>';

        $out .= '<header class="glass-bar sticky top-0 z-30 flex h-14 items-center gap-3 px-4 pt-[env(safe-area-inset-top)] lg:hidden">';
        $out .= '<a href="' . h(url_to('/')) . '" class="flex shrink-0 items-center text-foreground" aria-label="GAMER PRO — на главную">' . brand_logo('h-3 w-auto') . '</a>';
        $out .= '<span class="h-4 w-px shrink-0 bg-border" aria-hidden="true"></span>';
        $out .= '<p class="min-w-0 flex-1 truncate text-sm font-semibold">' . h($title) . '</p>';
        $out .= '<button type="button" class="' . btn_class('quiet', 'icon-sm') . '" data-theme-toggle aria-label="Сменить тему">' . icon('moon', 'size-4') . '</button>';
        $out .= '<a href="' . h(url_to('/account')) . '" class="shrink-0 rounded-md" aria-label="Мой профиль">' . self::avatarFor($user, 'size-8 text-[11px]') . '</a>';
        $out .= '</header>';
        if (self::newYearOn()) {
            $out .= self::newYearGarland();
        }

        $out .= '<main id="main" tabindex="-1" class="page-main mx-auto flex w-full ' . $widthClass . ' flex-col gap-6 px-4 pt-5 pb-32 outline-none sm:px-6 lg:gap-8 lg:px-10 lg:pt-9 lg:pb-14">' . $inner . '</main>';

        $out .= '<nav class="dock lg:hidden" aria-label="Быстрые разделы"><ul class="dock-list" data-pill-group>';
        $active = find_nav_item($path);
        foreach (MOBILE_TABS as $tab) {
            $on = $active && $active['href'] === $tab['href'];
            $badge = !empty($tab['badge']) ? (int) ($counts[$tab['badge']] ?? 0) : 0;
            $out .= '<li><a href="' . h(url_to($tab['href'])) . '" class="dock-item"' . ($on ? ' aria-current="page"' : '') . '>';
            $out .= '<span class="relative">' . icon($tab['icon'], 'size-5');
            if ($badge > 0) {
                $out .= '<span class="absolute -top-1.5 -right-2.5 min-w-4 rounded-full bg-primary px-1 text-center text-[10px] leading-4 font-bold text-primary-foreground tabular-nums">' . ($badge > 99 ? '99+' : $badge) . '</span>';
            }
            $out .= '</span>' . h($tab['label']) . '</a></li>';
        }
        $out .= '<li><button type="button" class="dock-item w-full" data-open-nav aria-expanded="false" aria-controls="nav-drawer">' . icon('menu', 'size-5') . 'Меню</button></li>';
        $out .= '</ul></nav>';

        $out .= '<div id="nav-drawer" class="fixed inset-0 z-50 hidden lg:hidden" data-nav-drawer role="dialog" aria-modal="true" aria-label="Меню портала">';
        $out .= '<button type="button" class="drawer-scrim absolute inset-0 size-full" data-close-nav aria-label="Закрыть меню" tabindex="-1"></button>';
        $out .= '<div class="drawer-panel sb sb--drawer pt-[env(safe-area-inset-top)]">' . self::sidebar($user, $counts, $path, true) . '</div></div>';
        return $out . '</div>';
    }

    /**
     * Восстанавливает до первой отрисовки: раскрытые группы, прокрутку меню и «перетекание»
     * подложки активного пункта с места, где она была на предыдущей странице.
     */
    private static function sidebarBoot(): string
    {
        return '(function(){try{var a=document.currentScript.previousElementSibling;'
            . 'var s=JSON.parse(localStorage.getItem("gp-nav-groups")||"{}");'
            . 'a.querySelectorAll("[data-sb-group]:not([data-pinned]):not([data-has-active])").forEach(function(g){var k=g.getAttribute("data-sb-group");'
            . 'if(Object.prototype.hasOwnProperty.call(s,k)){var o=s[k]?"true":"false";g.setAttribute("data-open",o);var b=g.querySelector(".sb-group-head");if(b)b.setAttribute("aria-expanded",o)}});'
            . 'var n=a.querySelector(".sb-nav"),y=sessionStorage.getItem("gp-nav-scroll");if(n&&y)n.scrollTop=+y;'
            . 'var r=sessionStorage.getItem("gp-pill");sessionStorage.removeItem("gp-pill");'
            . 'if(!r||matchMedia("(prefers-reduced-motion: reduce)").matches)return;r=JSON.parse(r);'
            . 'var p=a.querySelector("[aria-current=page] .sb-pill");if(!p||!p.animate)return;var q=p.getBoundingClientRect();if(!q.width)return;'
            . 'p.animate([{transform:"translate("+(r.x-q.x)+"px,"+(r.y-q.y)+"px) scale("+(r.w/q.width)+","+(r.h/q.height)+")"},{transform:"none"}],'
            . '{duration:560,easing:"cubic-bezier(0.22, 1, 0.36, 1)"})}catch(e){}})();';
    }

    private static function sidebar(array $user, array $counts, string $path, bool $drawer): string
    {
        $pfx = $drawer ? 'd' : 's';
        $html = '<div class="sb-head">';
        $html .= '<a href="' . h(url_to('/')) . '" class="sb-brand" aria-label="GAMER PRO — на главную">'
            . '<span class="sb-brand-full">' . brand_logo('h-3.5 w-auto') . '</span>'
            . '<span class="sb-brand-mini" aria-hidden="true">G<b>P</b></span></a>';
        $html .= '<span class="sb-tag">CRM</span>';
        if ($drawer) {
            $html .= '<button type="button" class="sb-icon-btn ml-auto" data-close-nav aria-label="Закрыть меню">' . icon('x', 'size-5') . '</button>';
        }
        $html .= '</div>';
        if (!$drawer) {
            $html .= '<button type="button" class="sb-rail-toggle" data-sb-rail aria-pressed="false" aria-label="Свернуть панель" data-tip="Свернуть панель" data-tip-always>' . icon('panel-left', 'size-3.5') . '</button>';
        }
        $html .= '<nav class="sb-nav scrollbar-thin" aria-label="Разделы портала" data-pill-group>';
        $activeItem = find_nav_item($path);
        foreach (NAV_GROUPS as $group) {
            $items = array_values(array_filter($group['items'], static fn ($it) => empty($it['perm']) || can($user, $it['perm'])));
            if (!$items) {
                continue;
            }
            $id = (string) $group['id'];
            $pinned = !empty($group['pinned']);
            $hasActive = false;
            $groupCount = 0;
            foreach ($items as $it) {
                $hasActive = $hasActive || ($activeItem && $activeItem['href'] === $it['href']);
                $groupCount += !empty($it['badge']) ? (int) ($counts[$it['badge']] ?? 0) : 0;
            }
            $open = $pinned || $hasActive;
            $bodyId = $pfx . '-g-' . $id;
            $html .= '<section class="sb-group" data-sb-group="' . h($id) . '" data-open="' . ($open ? 'true' : 'false') . '"'
                . ($pinned ? ' data-pinned' : '') . ($hasActive ? ' data-has-active' : '') . '>';
            if ($pinned) {
                $html .= '<p class="sb-group-head is-static"><span class="sb-group-label">' . h($group['label']) . '</span></p>';
            } else {
                $html .= '<button type="button" class="sb-group-head" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . h($bodyId) . '">'
                    . '<span class="sb-group-label">' . h($group['label']) . '</span>'
                    . ($groupCount > 0 ? '<span class="sb-group-count">' . ($groupCount > 99 ? '99+' : $groupCount) . '<span class="sr-only"> новых</span></span>' : '')
                    . icon('chevrondown', 'sb-chevron size-3.5') . '</button>';
            }
            $html .= '<div class="sb-group-body" id="' . h($bodyId) . '"><div class="sb-group-inner"><ul class="sb-list">';
            foreach ($items as $i => $item) {
                $active = $activeItem && $activeItem['href'] === $item['href'];
                $count = !empty($item['badge']) ? (int) ($counts[$item['badge']] ?? 0) : 0;
                $tip = $item['label'] . ($count > 0 ? ' · ' . $count : '');
                $html .= '<li style="--i:' . $i . '"><a href="' . h(url_to($item['href'])) . '" class="sb-link" data-tip="' . h($tip) . '"' . ($active ? ' aria-current="page"' : '') . '>';
                $html .= '<span class="sb-pill" aria-hidden="true"></span>';
                $html .= '<span class="sb-ico">' . icon($item['icon'], 'size-4') . '</span>';
                $html .= '<span class="sb-label">' . h($item['label']) . '</span>';
                if ($count > 0) {
                    $html .= '<span class="sb-badge">' . ($count > 99 ? '99+' : $count) . '<span class="sr-only"> новых</span></span>';
                }
                $html .= '</a></li>';
            }
            $html .= '</ul></div></div></section>';
        }
        $html .= '</nav>';
        $name = self::displayName($user);
        $sub = $user['roleLabel'] . ($user['homeBranchId'] ? ' · ' . self::branchName($user['homeBranchId']) : '');
        $html .= '<div class="sb-foot">';
        $html .= '<a href="' . h(url_to('/account')) . '" class="sb-user" data-tip="' . h(short_name($name)) . '">';
        $html .= self::avatarFor($user, 'size-9 text-xs', 'sidebar');
        $html .= '<span class="sb-user-text"><span class="truncate text-sm font-semibold text-sidebar-accent-foreground">' . h(short_name($name)) . '</span><span class="truncate text-xs text-sidebar-muted">' . h($sub) . '</span></span></a>';
        $html .= '<div class="sb-foot-actions">';
        $html .= '<button type="button" class="sb-icon-btn" data-theme-toggle aria-label="Сменить тему" data-tip="Сменить тему" data-tip-always>' . icon('moon', 'size-4') . '</button>';
        $html .= form_open('logout', 'action="' . h(url_to('/sign-out')) . '" class="contents"') . '<button class="sb-icon-btn is-danger" aria-label="Выйти" data-tip="Выйти" data-tip-always>' . icon('logout', 'size-4') . '</button></form>';
        $html .= '</div></div>';
        return $html;
    }

    private static function pageSignIn(): void
    {
        if (self::user()) {
            redirect_to('/');
        }
        $flash = flash_take();
        $err = ($flash['type'] ?? '') === 'error' ? (string) $flash['msg'] : '';
        $last = (string) ($_SESSION['last_login'] ?? '');
        $branches = implode(' · ', array_map(static fn ($b) => $b['name'], self::branches()));
        $html = '<main id="main" class="grid min-h-dvh lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">';
        $html .= '<div class="relative hidden overflow-hidden bg-sidebar text-sidebar-accent-foreground lg:block">';
        $html .= '<img src="' . h(asset('img/club/hall-row.webp')) . '" srcset="' . h(asset('img/club/hall-row-sm.webp')) . ' 800w, ' . h(asset('img/club/hall-row.webp')) . ' 1600w" sizes="55vw" alt="Игровой зал GAMER PRO: ряд ПК с RGB-подсветкой и кресла" class="absolute inset-0 size-full object-cover opacity-80" fetchpriority="high">';
        $html .= '<div class="absolute inset-0 bg-linear-to-t from-sidebar via-sidebar/40 to-sidebar/10" aria-hidden="true"></div>';
        $html .= '<div class="relative flex h-full flex-col justify-between p-10 xl:p-14">';
        $html .= '<span class="text-sidebar-accent-foreground">' . brand_logo('h-9 w-auto', true) . '</span>';
        $html .= '<div class="flex max-w-md flex-col gap-4">' . '<p class="flex items-center gap-2 text-[11px] font-semibold tracking-[0.18em] text-sidebar-foreground uppercase"><span class="cut-mark" aria-hidden="true"></span>Портал сотрудников</p>';
        $html .= '<p class="font-display text-3xl leading-tight font-semibold text-balance xl:text-4xl">Смены, регламенты и гости — в одном окне</p>';
        if ($branches !== '') {
            $html .= '<p class="text-sm text-sidebar-foreground">' . h($branches) . '</p>';
        }
        $html .= '</div></div></div>';
        $html .= '<div class="flex flex-col justify-center px-5 py-12 sm:px-10">';
        $html .= '<div class="mx-auto flex w-full max-w-sm flex-col gap-8">';
        $html .= '<span class="text-foreground lg:hidden">' . brand_logo('h-8 w-auto', true) . '</span>';
        $html .= '<div class="flex flex-col gap-2"><h1 class="font-display text-2xl font-semibold tracking-tight">Вход в CRM</h1><p class="text-sm leading-relaxed text-muted-foreground">Логин и пароль выдаёт владелец клуба. Если не получается войти — напишите ему.</p></div>';
        $html .= '<form method="post" class="flex flex-col gap-5" data-submit-once>' . csrf_field() . hidden('_action', 'login');
        $html .= ui_field('Логин', '<input name="login" class="' . field_class('h-11') . '" required autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" value="' . h($last) . '"' . ($last === '' ? ' autofocus' : '') . '>');
        $html .= '<label class="flex flex-col gap-1.5"><span class="text-sm font-medium">Пароль</span><span class="relative block"><input name="password" type="password" class="' . field_class('h-11 pr-11') . '" required autocomplete="current-password"' . ($last !== '' ? ' autofocus' : '') . '>';
        $html .= '<button type="button" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-muted-foreground hover:text-foreground" data-toggle-pass aria-label="Показать пароль" aria-pressed="false">' . icon('eye') . '</button></span></label>';
        if ($err !== '') {
            $html .= '<p role="alert" class="flex items-start gap-2 rounded-md border border-primary/30 bg-primary/5 px-3 py-2.5 text-sm text-primary-ink">' . icon('alert', 'mt-0.5 size-4 shrink-0') . h($err) . '</p>';
        }
        $html .= '<button class="' . btn_class('primary', 'lg') . ' w-full">Войти ' . icon('arrow', 'size-4') . '</button></form>';
        $html .= '</div></div></main>';
        self::html(null, $html, ['title' => 'Вход']);
    }
}
