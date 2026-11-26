<?php
declare(strict_types=1);

final class Payroll
{
    public const BANKS = ['Сбербанк', 'Т-Банк', 'Альфа-Банк', 'ВТБ', 'Озон Банк', 'Яндекс Банк', 'Газпромбанк', 'Райффайзенбанк', 'Совкомбанк', 'Почта Банк', 'МТС Банк', 'Россельхозбанк', 'Другой банк'];

    /**
     * Расчёт по началу смены в Тюмени, не подтверждение фактической выплаты.
     * Процент с бара копится весь месяц, с первого числа, и выплачивается вместе со второй половиной:
     * он лежит в periods[2] и виден с первого дня, а не только после 16-го.
     */
    public static function employee(int $employeeId, string $month, ?DateTimeImmutable $now = null): ?array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('Неверный месяц');
        }
        $now = ($now ?? now_tyumen())->setTimezone(new DateTimeZone(APP_TZ));
        $first = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone(APP_TZ));
        $end = $first->modify('first day of next month');
        $employee = Db::one('SELECT e.*, g.title AS "gradeTitle", g."shiftRate" FROM employees e LEFT JOIN employee_grades g ON g.id = e."gradeId" WHERE e.id = ?', [$employeeId]);
        if (!$employee) {
            return null;
        }
        $periods = [];
        foreach ([1 => ['01', '15'], 2 => ['16', $first->format('t')]] as $key => [$from, $to]) {
            $periods[$key] = ['from' => $month . '-' . $from, 'to' => $month . '-' . $to, 'units' => 0, 'planned' => 0, 'gross' => 0, 'future' => 0, 'bar' => 0, 'barRev' => 0, 'barPct' => 0, 'barByBranch' => [], 'mgrBar' => 0, 'mgrBarRev' => 0, 'mgrBarPct' => 0, 'mgrBarByBranch' => [], 'fines' => 0, 'total' => 0, 'shifts' => [], 'deductions' => []];
        }
        $history = Db::all('SELECT "shiftRate", "changedAt" FROM employee_grade_history WHERE "employeeId" = ? ORDER BY "changedAt", id', [$employeeId]);
        usort($history, static fn ($a, $b) => (new DateTimeImmutable((string) $a['changedAt'])) <=> (new DateTimeImmutable((string) $b['changedAt'])));
        $shifts = Db::all('SELECT s.id, s.date, s."shiftType", s."branchId", b.name AS "branchName" FROM shifts s JOIN branches b ON b.id = s."branchId" WHERE s."employeeId" = ? AND s.date >= ? AND s.date < ? ORDER BY s.date, s."shiftType"', [$employeeId, $first->format('Y-m-d'), $end->format('Y-m-d')]);
        $branchIds = [(int) $employee['homeBranchId']];
        foreach ($shifts as $shift) {
            $date = substr((string) $shift['date'], 0, 10);
            $state = shift_state($date, (string) $shift['shiftType'], $now, (int) $shift['branchId']);
            $period = (int) substr($date, 8, 2) <= 15 ? 1 : 2;
            $units = shift_weight((string) $shift['shiftType']);
            $started = $state['start'] <= $now;
            $rate = (int) ($employee['shiftRate'] ?? 0);
            if ($started && $history) {
                // Самая ранняя известная ставка — резерв для импортированных смен до начала истории.
                $rate = (int) $history[0]['shiftRate'];
                foreach ($history as $change) {
                    if (new DateTimeImmutable((string) $change['changedAt']) <= $state['start']) {
                        $rate = (int) $change['shiftRate'];
                    }
                }
            }
            $amount = $rate * $units;
            $periods[$period]['planned'] += $units;
            $periods[$period][$started ? 'gross' : 'future'] += $amount;
            if ($started) {
                $periods[$period]['units'] += $units;
            }
            $periods[$period]['shifts'][] = $shift + ['started' => $started, 'rate' => $rate, 'amount' => $amount, 'state' => $state['state']];
            $branchIds[] = (int) $shift['branchId'];
        }
        // Присутствие управляющего: одна отметка — одна смена по текущей ставке на дату отметки.
        $presence = Db::all('SELECT p.*, b.name AS "branchName" FROM presence_logs p LEFT JOIN branches b ON b.id = p."branchId" WHERE p."employeeId" = ? AND p.date >= ? AND p.date < ? ORDER BY p.date, p."startTime"', [$employeeId, $first->format('Y-m-d'), $end->format('Y-m-d')]);
        foreach ($presence as $row) {
            $date = substr((string) $row['date'], 0, 10);
            $startAt = new DateTimeImmutable($date . ' ' . ((string) $row['startTime'] ?: '00:00') . ':00', new DateTimeZone(APP_TZ));
            $started = $startAt <= $now;
            $rate = (int) ($employee['shiftRate'] ?? 0);
            foreach ($history as $change) {
                if (new DateTimeImmutable((string) $change['changedAt']) <= $startAt) {
                    $rate = (int) $change['shiftRate'];
                }
            }
            $period = (int) substr($date, 8, 2) <= 15 ? 1 : 2;
            $periods[$period]['planned'] += 1;
            $periods[$period][$started ? 'gross' : 'future'] += $rate;
            if ($started) {
                $periods[$period]['units'] += 1;
            }
            $periods[$period]['shifts'][] = [
                'id' => (int) $row['id'], 'date' => $date, 'shiftType' => 'presence', 'branchId' => (int) $row['branchId'],
                'branchName' => (string) ($row['branchName'] ?? 'Клуб'), 'started' => $started, 'rate' => $rate, 'amount' => $rate,
                'state' => $started ? 'done' : 'planned', 'startTime' => (string) $row['startTime'], 'endTime' => (string) $row['endTime'],
                'activity' => (string) $row['activity'],
            ];
        }
        foreach ($periods as &$sortPeriod) {
            usort($sortPeriod['shifts'], static fn ($a, $b) => strcmp(substr((string) $a['date'], 0, 10), substr((string) $b['date'], 0, 10)));
        }
        unset($sortPeriod);
        $fines = Db::all('SELECT id, reason, amount, "createdAt" FROM employee_fines WHERE "employeeId" = ? AND "createdAt" >= ? AND "createdAt" < ? ORDER BY "createdAt" DESC', [$employeeId, $first->modify('-1 day')->format('Y-m-d'), $end->modify('+1 day')->format('Y-m-d')]);
        foreach ($fines as $fine) {
            $at = (new DateTimeImmutable((string) $fine['createdAt']))->setTimezone(new DateTimeZone(APP_TZ));
            if ($at > $now || $at < $first || $at >= $end) {
                continue;
            }
            $period = (int) $at->format('j') <= 15 ? 1 : 2;
            $periods[$period]['fines'] += (int) $fine['amount'];
            $periods[$period]['deductions'][] = $fine;
        }
        $commissionStart = $first;
        $cutoff = min($now, $end->modify('-1 second'));
        if ($cutoff >= $commissionStart) {
            $sales = Db::all('SELECT s."branchId" AS bid, b.name AS "branchName", l."percentX10" AS pct, st."barPercentX10" AS def, SUM(s."amountX100") AS revenue
                FROM bar_sales s
                JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                JOIN langame_settings st ON st."branchId" = s."branchId"
                LEFT JOIN branches b ON b.id = s."branchId"
                WHERE l."employeeId" = ? AND s."isCancelled" = 0 AND s."isExcluded" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?
                GROUP BY s."branchId", b.name, l."percentX10", st."barPercentX10"
                ORDER BY s."branchId"', [$employeeId, $commissionStart->format('Y-m-d H:i:s'), $cutoff->format('Y-m-d H:i:s')]);
            $earned = $revenue = 0;
            $byBranch = [];
            foreach ($sales as $sale) {
                $pct = (int) ($sale['pct'] ?? $sale['def']);
                $rev = (int) $sale['revenue'];
                $part = (int) round($rev * $pct / 1000);
                $revenue += $rev;
                $earned += $part;
                $bid = (int) $sale['bid'];
                $byBranch[$bid] ??= ['branchId' => $bid, 'branchName' => (string) ($sale['branchName'] ?? 'Филиал'), 'revenue' => 0, 'earned' => 0, 'pct' => $pct];
                $byBranch[$bid]['revenue'] += $rev;
                $byBranch[$bid]['earned'] += $part;
                $byBranch[$bid]['pct'] = $pct;
            }
            // Процент управляющего — с общих продаж бара выбранных клубов за весь месяц, кто бы ни продавал.
            // Управляющему платится только он: личный процент с собственных продаж не начисляется.
            $mgrPct = (int) ($employee['mgrBarPctX10'] ?? 0);
            $mgrBranches = self::branchList((string) ($employee['mgrBarBranches'] ?? ''));
            if (!($mgrPct > 0 && $mgrBranches)) {
                $periods[2]['bar'] = (int) round($earned / 100);
                $periods[2]['barRev'] = (int) round($revenue / 100);
                $periods[2]['barPct'] = $revenue > 0 ? (int) round($earned / $revenue * 1000) : 0;
                foreach ($byBranch as $row) {
                    $periods[2]['barByBranch'][] = ['branchId' => $row['branchId'], 'branchName' => $row['branchName'], 'revenue' => (int) round($row['revenue'] / 100), 'earned' => (int) round($row['earned'] / 100), 'pct' => $row['pct']];
                }
            }
            if ($mgrPct > 0 && $mgrBranches) {
                $marks = implode(',', array_fill(0, count($mgrBranches), '?'));
                $rows = Db::all('SELECT s."branchId" AS bid, b.name AS "branchName", SUM(s."amountX100") AS revenue
                    FROM bar_sales s LEFT JOIN branches b ON b.id = s."branchId"
                    WHERE s."branchId" IN (' . $marks . ') AND s."isCancelled" = 0 AND s."isExcluded" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?
                    GROUP BY s."branchId", b.name ORDER BY s."branchId"', [...$mgrBranches, $commissionStart->format('Y-m-d H:i:s'), $cutoff->format('Y-m-d H:i:s')]);
                $mgrRev = $mgrEarned = 0;
                foreach ($rows as $row) {
                    $rev = (int) $row['revenue'];
                    $part = (int) round($rev * $mgrPct / 1000);
                    $mgrRev += $rev;
                    $mgrEarned += $part;
                    $periods[2]['mgrBarByBranch'][] = ['branchId' => (int) $row['bid'], 'branchName' => (string) ($row['branchName'] ?? 'Филиал'), 'revenue' => (int) round($rev / 100), 'earned' => (int) round($part / 100)];
                }
                $periods[2]['mgrBar'] = (int) round($mgrEarned / 100);
                $periods[2]['mgrBarRev'] = (int) round($mgrRev / 100);
                $periods[2]['mgrBarPct'] = $mgrPct;
            }
        }
        $warnings = [];
        foreach (array_unique(array_filter($branchIds)) as $bid) {
            $settings = Db::one('SELECT b.name, s."isEnabled", s."lastSyncOk", s."lastSyncAt", s."syncedFrom" FROM branches b LEFT JOIN langame_settings s ON s."branchId" = b.id WHERE b.id = ?', [$bid]);
            $linked = Db::value('SELECT 1 FROM langame_staff WHERE "employeeId" = ? AND "branchId" = ?', [$employeeId, $bid]);
            if (!$settings || !$settings['isEnabled'] || !$linked || !$settings['lastSyncOk'] || empty($settings['syncedFrom']) || substr((string) $settings['syncedFrom'], 0, 10) > $commissionStart->format('Y-m-d')) {
                $warnings[] = ($settings['name'] ?? 'Филиал') . ': продажи или привязка Langame неполные';
            } elseif (empty($settings['lastSyncAt']) || $now->getTimestamp() - (new DateTimeImmutable((string) $settings['lastSyncAt']))->getTimestamp() > 900) {
                $warnings[] = $settings['name'] . ': продажи требуют обновления';
            }
        }
        foreach ($periods as &$period) {
            $period['total'] = $period['gross'] + $period['bar'] + $period['mgrBar'] - $period['fines'];
        }
        unset($period);
        return ['employee' => $employee, 'periods' => $periods, 'warnings' => $warnings, 'at' => $now];
    }

    /** @return list<int> */
    public static function branchList(string $csv): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $csv)), static fn (int $id) => $id > 0)));
        sort($ids);
        return $ids;
    }
}
