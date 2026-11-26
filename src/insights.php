<?php
declare(strict_types=1);

/**
 * Аналитика по данным, уже синхронизированным из Langame (bar_sales, langame_shifts).
 * Только локальные запросы: страница не ждёт API и открывается сразу.
 */
final class Insights
{
    /** @param int[] $ids */
    private static function in(array $ids): string
    {
        return implode(',', array_fill(0, max(1, count($ids)), '?'));
    }

    /** @param int[] $ids */
    private static function safe(callable $fn, array $ids, mixed $empty = []): mixed
    {
        if (!$ids) {
            return $empty;
        }
        try {
            return $fn();
        } catch (PDOException) {
            return $empty;
        }
    }

    /**
     * Средняя выручка по дню недели и часу. Делим на число таких дней в периоде,
     * чтобы пятница с четырьмя вхождениями не выглядела вдвое сильнее месяца с двумя.
     * @param int[] $branchIds
     * @return array{grid: array<int, array<int, int>>, max: int, peak: ?array{0:int,1:int,2:int}, quiet: ?array{0:int,1:int,2:int}}
     */
    public static function heatmap(array $branchIds, string $from, string $to): array
    {
        $empty = ['grid' => [], 'max' => 0, 'peak' => null, 'quiet' => null];
        $rows = self::safe(static fn () => Db::all(
            'SELECT SUBSTR("saleAt", 1, 10) AS d, SUBSTR("saleAt", 12, 2) AS hh, SUM("amountX100") AS a
             FROM bar_sales WHERE "branchId" IN (' . self::in($branchIds) . ') AND "isCancelled" = 0 AND "isExcluded" = 0
               AND "saleAt" >= ? AND "saleAt" <= ? GROUP BY d, hh',
            [...$branchIds, $from, $to . ' 23:59:59'],
        ), $branchIds);
        if (!$rows) {
            return $empty;
        }
        $sum = [];
        $days = [];
        foreach ($rows as $r) {
            $ts = strtotime((string) $r['d']);
            if ($ts === false) {
                continue;
            }
            $wd = ((int) date('N', $ts)) - 1;
            $hour = (int) $r['hh'];
            $sum[$wd][$hour] = ($sum[$wd][$hour] ?? 0) + (int) $r['a'];
            $days[$wd][(string) $r['d']] = true;
        }
        $grid = [];
        $max = 0;
        $peak = null;
        $quiet = null;
        foreach ($sum as $wd => $hours) {
            $n = max(1, count($days[$wd] ?? []));
            foreach ($hours as $hour => $a) {
                $avg = intdiv($a, $n);
                $grid[$wd][$hour] = $avg;
                $max = max($max, $avg);
                if ($peak === null || $avg > $peak[2]) {
                    $peak = [$wd, $hour, $avg];
                }
                if ($hour >= 10 && $hour <= 23 && ($quiet === null || $avg < $quiet[2])) {
                    $quiet = [$wd, $hour, $avg];
                }
            }
        }
        return ['grid' => $grid, 'max' => $max, 'peak' => $peak, 'quiet' => $quiet];
    }

    /**
     * Сводка по филиалам за период.
     * @param int[] $branchIds
     * @return array<int, array{revenue:int, lines:int, qty:int, shifts:int, cashless:int, cancelAmount:int, cancelCount:int}>
     */
    public static function branchTotals(array $branchIds, string $from, string $to): array
    {
        $rows = self::safe(static fn () => Db::all(
            'SELECT "branchId" AS b,
                    SUM(CASE WHEN "isCancelled" = 0 THEN "amountX100" ELSE 0 END) AS revenue,
                    SUM(CASE WHEN "isCancelled" = 0 THEN 1 ELSE 0 END) AS lines,
                    SUM(CASE WHEN "isCancelled" = 0 THEN qty ELSE 0 END) AS qty,
                    COUNT(DISTINCT "shiftExtId") AS shifts,
                    SUM(CASE WHEN "isCancelled" = 0 AND "isCashless" = 1 THEN "amountX100" ELSE 0 END) AS cashless,
                    SUM(CASE WHEN "isCancelled" = 1 THEN "amountX100" ELSE 0 END) AS "cancelAmount",
                    SUM(CASE WHEN "isCancelled" = 1 THEN 1 ELSE 0 END) AS "cancelCount"
             FROM bar_sales WHERE "branchId" IN (' . self::in($branchIds) . ') AND "isExcluded" = 0
               AND "saleAt" >= ? AND "saleAt" <= ? GROUP BY "branchId"',
            [...$branchIds, $from, $to . ' 23:59:59'],
        ), $branchIds);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['b']] = [
                'revenue' => (int) $r['revenue'], 'lines' => (int) $r['lines'], 'qty' => (int) $r['qty'],
                'shifts' => (int) $r['shifts'], 'cashless' => (int) $r['cashless'],
                'cancelAmount' => (int) $r['cancelAmount'], 'cancelCount' => (int) $r['cancelCount'],
            ];
        }
        return $out;
    }

    /**
     * Товары: продажи в периоде против прошлого периода той же длины.
     * @param int[] $branchIds
     * @return array{top: list<array>, rising: list<array>, falling: list<array>}
     */
    public static function goodsMovers(array $branchIds, string $from, string $to, string $prevFrom, string $prevTo): array
    {
        $empty = ['top' => [], 'rising' => [], 'falling' => []];
        $rows = self::safe(static fn () => Db::all(
            'SELECT COALESCE(p.name, \'Товар\') AS name,
                    SUM(CASE WHEN s."saleAt" >= ? THEN s.qty ELSE 0 END) AS qty,
                    SUM(CASE WHEN s."saleAt" >= ? THEN s."amountX100" ELSE 0 END) AS amount,
                    SUM(CASE WHEN s."saleAt" < ? THEN s.qty ELSE 0 END) AS "prevQty"
             FROM bar_sales s LEFT JOIN langame_products p ON p."branchId" = s."branchId" AND p."extId" = s."goodsId"
             WHERE s."branchId" IN (' . self::in($branchIds) . ') AND s."isCancelled" = 0 AND s."isExcluded" = 0
               AND s."saleAt" >= ? AND s."saleAt" <= ?
             GROUP BY COALESCE(p.name, \'Товар\')',
            [$from, $from, $from, ...$branchIds, $prevFrom, $to . ' 23:59:59'],
        ), $branchIds);
        if (!$rows) {
            return $empty;
        }
        $items = array_map(static fn (array $r): array => [
            'name' => (string) $r['name'], 'qty' => (int) $r['qty'], 'amount' => (int) $r['amount'],
            'prevQty' => (int) $r['prevQty'], 'delta' => (int) $r['qty'] - (int) $r['prevQty'],
        ], $rows);
        $top = $items;
        usort($top, static fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $movers = array_values(array_filter($items, static fn ($i) => $i['qty'] + $i['prevQty'] >= 5));
        $rising = array_values(array_filter($movers, static fn ($i) => $i['delta'] > 0));
        usort($rising, static fn ($a, $b) => $b['delta'] <=> $a['delta']);
        $falling = array_values(array_filter($movers, static fn ($i) => $i['delta'] < 0));
        usort($falling, static fn ($a, $b) => $a['delta'] <=> $b['delta']);
        return ['top' => array_slice($top, 0, 8), 'rising' => array_slice($rising, 0, 5), 'falling' => array_slice($falling, 0, 5)];
    }

    /**
     * Отмены по сотрудникам: количество, сумма и доля от всех пробитых позиций.
     * @param int[] $branchIds
     * @return list<array{eid:int, count:int, amount:int, lines:int}>
     */
    public static function cancelsByStaff(array $branchIds, string $from, string $to): array
    {
        $rows = self::safe(static fn () => Db::all(
            'SELECT COALESCE(l."employeeId", 0) AS eid,
                    SUM(CASE WHEN s."isCancelled" = 1 THEN 1 ELSE 0 END) AS cnt,
                    SUM(CASE WHEN s."isCancelled" = 1 THEN s."amountX100" ELSE 0 END) AS amount,
                    COUNT(*) AS lines
             FROM bar_sales s
             LEFT JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
             LEFT JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
             WHERE s."branchId" IN (' . self::in($branchIds) . ') AND s."isExcluded" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?
             GROUP BY COALESCE(l."employeeId", 0)',
            [...$branchIds, $from, $to . ' 23:59:59'],
        ), $branchIds);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['eid' => (int) $r['eid'], 'count' => (int) $r['cnt'], 'amount' => (int) $r['amount'], 'lines' => (int) $r['lines']];
        }
        usort($out, static fn ($a, $b) => [$b['count'], $b['amount']] <=> [$a['count'], $a['amount']]);
        return $out;
    }

    /**
     * Смены Langame: длительность, инкассации, комментарии при закрытии.
     * @param int[] $branchIds
     * @return array{count:int, avgMinutes:int, longest:?array, incass:int, incassCount:int, notes:list<array>}
     */
    public static function shiftStats(array $branchIds, string $from, string $to): array
    {
        $empty = ['count' => 0, 'avgMinutes' => 0, 'longest' => null, 'incass' => 0, 'incassCount' => 0, 'notes' => []];
        $rows = self::safe(static fn () => Db::all(
            'SELECT w."branchId" AS b, w."dateStart" AS ds, w."dateStop" AS de, w."cashIncass" AS inc, w."closeMessage" AS msg, l."employeeId" AS eid
             FROM langame_shifts w
             LEFT JOIN langame_staff l ON l."branchId" = w."branchId" AND l."langameUserId" = w."langameUserId"
             WHERE w."branchId" IN (' . self::in($branchIds) . ') AND w."dateStart" >= ? AND w."dateStart" <= ?
             ORDER BY w."dateStart" DESC',
            [...$branchIds, $from, $to . ' 23:59:59'],
        ), $branchIds);
        if (!$rows) {
            return $empty;
        }
        $minutes = [];
        $longest = null;
        $incass = 0;
        $incassCount = 0;
        $notes = [];
        foreach ($rows as $r) {
            $s = strtotime((string) $r['ds']);
            $e = $r['de'] ? strtotime((string) $r['de']) : false;
            if ($s !== false && $e !== false && $e > $s) {
                $m = intdiv($e - $s, 60);
                if ($m <= 36 * 60) {
                    $minutes[] = $m;
                    if ($longest === null || $m > $longest['minutes']) {
                        $longest = ['minutes' => $m, 'eid' => (int) $r['eid'], 'branchId' => (int) $r['b'], 'start' => (string) $r['ds']];
                    }
                }
            }
            if ((int) $r['inc'] > 0) {
                $incass += (int) $r['inc'];
                $incassCount++;
            }
            $msg = trim((string) $r['msg']);
            if ($msg !== '' && count($notes) < 6) {
                $notes[] = ['text' => $msg, 'eid' => (int) $r['eid'], 'branchId' => (int) $r['b'], 'start' => (string) $r['ds']];
            }
        }
        return [
            'count' => count($rows),
            'avgMinutes' => $minutes ? intdiv(array_sum($minutes), count($minutes)) : 0,
            'longest' => $longest,
            'incass' => $incass,
            'incassCount' => $incassCount,
            'notes' => $notes,
        ];
    }

    /**
     * Личные рекорды администратора: лучшая смена за всё время, любимый товар
     * и «золотой час» за месяц, выручка против прошлого месяца.
     * @return array{best:?array{amount:int, date:string, branchId:int}, favorite:?array{name:string, qty:int}, hour:?int, revenue:int, prevRevenue:int, shifts:int}
     */
    public static function personal(int $employeeId, string $from, string $to, string $prevFrom, string $prevTo): array
    {
        $out = ['best' => null, 'favorite' => null, 'hour' => null, 'revenue' => 0, 'prevRevenue' => 0, 'shifts' => 0];
        if ($employeeId <= 0) {
            return $out;
        }
        $join = 'FROM bar_sales s
                 JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 WHERE l."employeeId" = ? AND s."isCancelled" = 0 AND s."isExcluded" = 0';
        try {
            $best = Db::one(
                'SELECT s."branchId" AS b, MIN(s."saleAt") AS d, SUM(s."amountX100") AS a ' . $join . '
                 GROUP BY s."branchId", s."shiftExtId" ORDER BY a DESC LIMIT 1',
                [$employeeId],
            );
            if ($best) {
                $out['best'] = ['amount' => (int) $best['a'], 'date' => substr((string) $best['d'], 0, 10), 'branchId' => (int) $best['b']];
            }
            $fav = Db::one(
                'SELECT COALESCE(p.name, \'Товар\') AS name, SUM(s.qty) AS q
                 FROM bar_sales s
                 JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 LEFT JOIN langame_products p ON p."branchId" = s."branchId" AND p."extId" = s."goodsId"
                 WHERE l."employeeId" = ? AND s."isCancelled" = 0 AND s."isExcluded" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?
                 GROUP BY COALESCE(p.name, \'Товар\') ORDER BY q DESC LIMIT 1',
                [$employeeId, $from, $to . ' 23:59:59'],
            );
            if ($fav) {
                $out['favorite'] = ['name' => (string) $fav['name'], 'qty' => (int) $fav['q']];
            }
            $hour = Db::one(
                'SELECT SUBSTR(s."saleAt", 12, 2) AS hh, SUM(s."amountX100") AS a ' . $join . ' AND s."saleAt" >= ? AND s."saleAt" <= ?
                 GROUP BY hh ORDER BY a DESC LIMIT 1',
                [$employeeId, $from, $to . ' 23:59:59'],
            );
            if ($hour) {
                $out['hour'] = (int) $hour['hh'];
            }
            $month = Db::one(
                'SELECT SUM(CASE WHEN s."saleAt" >= ? THEN s."amountX100" ELSE 0 END) AS cur,
                        SUM(CASE WHEN s."saleAt" < ? THEN s."amountX100" ELSE 0 END) AS prev,
                        COUNT(DISTINCT CASE WHEN s."saleAt" >= ? THEN s."branchId" || \':\' || s."shiftExtId" END) AS shifts ' . $join . '
                   AND s."saleAt" >= ? AND s."saleAt" <= ?',
                [$from, $from, $from, $employeeId, $prevFrom, $to . ' 23:59:59'],
            );
            if ($month) {
                $out['revenue'] = (int) $month['cur'];
                $out['prevRevenue'] = (int) $month['prev'];
                $out['shifts'] = (int) $month['shifts'];
            }
        } catch (PDOException) {
            return $out;
        }
        return $out;
    }
}
