<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty(Db::class, 'pdo'))->setValue(null, $pdo);
$pdo->exec('CREATE TABLE bar_sales (id INTEGER PRIMARY KEY, branchId INTEGER, extId INTEGER, saleAt TEXT, goodsId INTEGER, shiftExtId INTEGER, qty INTEGER, amountX100 INTEGER, unitPriceX100 INTEGER, isCancelled INTEGER, isCashless INTEGER, isExcluded INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE UNIQUE INDEX bar_sales_branch_ext ON bar_sales (branchId, extId)');
$pdo->exec('CREATE TABLE langame_shifts (branchId INTEGER, extId INTEGER, langameUserId INTEGER)');
$pdo->exec('CREATE TABLE langame_staff (branchId INTEGER, langameUserId INTEGER, employeeId INTEGER, langameName TEXT)');
$pdo->exec('CREATE TABLE langame_products (branchId INTEGER, extId INTEGER, name TEXT)');
$pdo->exec('CREATE TABLE employees (id INTEGER, name TEXT)');
$pdo->exec("INSERT INTO employees VALUES (1, 'Анна Тестовая')");
foreach ([1, 2] as $bid) {
    Db::exec('INSERT INTO langame_shifts VALUES (?, 10, 1)', [$bid]);
    Db::exec('INSERT INTO langame_staff VALUES (?, 1, 1, ?)', [$bid, 'Тест']);
    Db::exec('INSERT INTO langame_products VALUES (?, 1, ?)', [$bid, 'Кофе <test>']);
}
for ($i = 1; $i <= 28; $i++) {
    Db::exec('INSERT INTO bar_sales (id, branchId, extId, saleAt, goodsId, shiftExtId, qty, amountX100, unitPriceX100, isCancelled, isCashless, isExcluded) VALUES (?, ?, ?, ?, 1, 10, 1, 10000, 10000, ?, 1, 0)', [$i, $i === 28 ? 2 : 1, $i, $i === 27 ? '2026-10-01 12:00:00' : '2026-11-10 12:00:00', $i === 26 ? 1 : 0]);
}
$checks = 0;
function check(bool $value, string $label): void
{
    global $checks;
    if (!$value) {
        throw new RuntimeException($label);
    }
    $checks++;
}
$all = Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'all', '', 1);
check($all['total'] === 26 && count($all['rows']) === 25, 'Branch, date scope and page size');
$last = Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'all', '', 999);
check($last['page'] === 2 && count($last['rows']) === 1, 'Clamp page to actual last page');
check($all['rows'][0]['isCancelled'] === 1, 'Stable latest-first order');
check(Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'cancelled', '', 1)['total'] === 1, 'Cancellation filter');
check(Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'completed', '', 1)['total'] === 25, 'Completed filter');
check(Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'all', 'кофе', 1)['total'] === 26, 'Case-insensitive Cyrillic search');
check(Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'all', 'анна', 1)['total'] === 26, 'Employee search');
foreach (['%', '_', "' OR 1=1 --"] as $search) {
    check(Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'all', $search, 1)['total'] === 0, 'Literal safe search');
}
check(count(Langame::recentSales(1, '2026-11-01', '2026-11-30', 100, 2)) === 1, 'Employee detail branch scope');
check((int) Langame::topGoods(1, '2026-11-01', '2026-11-30', 5, 2)[0]['qty'] === 1, 'Employee product scope');
$month = ['key' => '2026-11', 'from' => '2026-11-01', 'to' => '2026-11-30'];
$branch = ['id' => 1, 'slug' => 'test', 'name' => 'Тест', 'pcCount' => 30];
$ledger = (new ReflectionMethod(Portal::class, 'barLedger'))->invoke(null, $branch, $month, ['branch' => 'test', 'm' => '2026-11']);
check(str_contains($ledger, '&lt;test&gt;') && !str_contains($ledger, '<test>'), 'Escaped API product names');
check(str_contains($ledger, 'Далее') && str_contains($ledger, 'Журнал продаж'), 'Ledger rendered');
$stock = (new ReflectionMethod(Portal::class, 'barGoods'))->invoke(null, $branch, $month, null);
check(str_contains($stock, 'Остатки не получены'), 'Unavailable stock is not zero');
$occupancy = new ReflectionMethod(Portal::class, 'barOccupancy');
check(str_contains($occupancy->invoke(null, $branch, null), 'Нет данных о сессиях'), 'Unavailable occupancy');
$live = ['at' => time(), 'active' => 12, 'sessionsToday' => 20, 'guestsToday' => 18, 'bookedToday' => 2, 'avgMinutes' => 70, 'hourly' => array_fill(0, 24, 3)];
check(str_contains($occupancy->invoke(null, $branch, $live), 'Загрузка 40%'), 'Occupancy uses configured capacity');
check(str_contains($occupancy->invoke(null, $branch, $live + ['sessionsPartial' => true]), 'только часть сессий'), 'Partial API response is explicit');
Db::exec('UPDATE bar_sales SET isExcluded = 1 WHERE id = 1');
check(Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'all', '', 1)['total'] === 25, 'Non-bar position leaves the ledger');
check((int) Langame::branchTopGoods(1, '2026-11-01', '2026-11-30')[0]['qty'] === 24, 'Non-bar position leaves revenue totals');
Db::upsert('bar_sales', ['branchId' => 1, 'extId' => 9001, 'saleAt' => '2026-11-10 15:00:00', 'goodsId' => 1, 'shiftExtId' => 10, 'unitPriceX100' => 5000, 'qty' => 2, 'amountX100' => 10000, 'isCancelled' => 0, 'isCashless' => 1], ['branchId', 'extId'], ['saleAt', 'goodsId', 'shiftExtId', 'unitPriceX100', 'qty', 'amountX100', 'isCancelled', 'isCashless']);
Db::upsert('bar_sales', ['branchId' => 1, 'extId' => 9001, 'saleAt' => '2026-11-10 16:00:00', 'goodsId' => 1, 'shiftExtId' => 10, 'unitPriceX100' => 5000, 'qty' => 3, 'amountX100' => 15000, 'isCancelled' => 0, 'isCashless' => 1], ['branchId', 'extId'], ['saleAt', 'goodsId', 'shiftExtId', 'unitPriceX100', 'qty', 'amountX100', 'isCancelled', 'isCashless']);
check((int) Db::value('SELECT COUNT(*) FROM bar_sales WHERE "extId" = 9001') === 1, 'Upsert inserts once');
check((int) Db::value('SELECT qty FROM bar_sales WHERE "extId" = 9001') === 3, 'Upsert updates qty');
fwrite(STDOUT, $checks . " analytics checks passed; isolated in-memory database.\n");
