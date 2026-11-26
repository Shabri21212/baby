<?php
declare(strict_types=1);

/**
 * Позиции Langame, которые не относятся к бару: аренда приставки и другие услуги.
 * Они пробиваются тем же товарным чеком, но не должны попадать в выручку бара и в процент сотрудника.
 *
 * Правило филиала — слова через запятую (langame_settings.excludeGoods). Позиция исключается,
 * если слово стоит в названии отдельно: «ТВ» убирает «ТВ 1 час», но не трогает «Твикс».
 * Отметка хранится у продажи (bar_sales.isExcluded), поэтому отчёты фильтруют её одним условием.
 */
final class BarRules
{
    /** Слова правила в том виде, как их ввёл владелец, без повторов. @return string[] */
    public static function words(?string $rule): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/u', (string) $rule) ?: [] as $part) {
            $word = trim(preg_replace('/\s+/u', ' ', $part) ?? '');
            if ($word !== '') {
                $out[mb_strtolower($word)] ??= $word;
            }
        }
        return array_values($out);
    }

    /** @param string[] $words */
    public static function matches(string $name, array $words): bool
    {
        $name = mb_strtolower($name);
        foreach ($words as $word) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote(mb_strtolower($word), '/') . '(?![\p{L}\p{N}])/u', $name)) {
                return true;
            }
        }
        return false;
    }

    /** Пересчитывает отметку «не бар» у всех продаж филиала. Возвращает число исключённых позиций каталога. */
    public static function apply(PDO $pdo, int $branchId): int
    {
        $st = $pdo->prepare('SELECT "excludeGoods" FROM langame_settings WHERE "branchId" = ?');
        $st->execute([$branchId]);
        $rule = $st->fetchColumn();
        $words = self::words($rule === false ? null : (string) $rule);
        $ids = [];
        if ($words) {
            $st = $pdo->prepare('SELECT "extId", name FROM langame_products WHERE "branchId" = ?');
            $st->execute([$branchId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $product) {
                if (self::matches((string) $product['name'], $words)) {
                    $ids[] = (int) $product['extId'];
                }
            }
        }
        $keep = $ids ? ' AND "goodsId" NOT IN (' . implode(',', $ids) . ')' : '';
        $pdo->prepare('UPDATE bar_sales SET "isExcluded" = 0 WHERE "branchId" = ? AND "isExcluded" = 1' . $keep)->execute([$branchId]);
        foreach (array_chunk($ids, 200) as $chunk) {
            $pdo->prepare('UPDATE bar_sales SET "isExcluded" = 1 WHERE "branchId" = ? AND "isExcluded" = 0 AND "goodsId" IN (' . implode(',', $chunk) . ')')->execute([$branchId]);
        }
        return count($ids);
    }
}
