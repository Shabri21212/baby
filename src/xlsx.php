<?php

declare(strict_types=1);

/**
 * Запись .xlsx без расширений PHP: несжатый zip + OOXML.
 *
 * Простой режим: xlsx_build($name, $rows, $widths) — первая и последняя строки жирные.
 * Красивый режим: xlsx_build_book($sheets) — несколько листов, заливки, границы.
 *
 * Ячейка: scalar|null или ['v' => mixed, 's' => int styleId].
 */

function xlsx_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

function xlsx_esc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** @param list<list<mixed>> $rows */
function xlsx_build(string $sheetName, array $rows, array $widths = []): string
{
    $styled = [];
    $last = count($rows);
    foreach (array_values($rows) as $r => $row) {
        $line = [];
        $style = $r === 0 ? 1 : ($r === $last - 1 && $last > 2 ? 1 : 0);
        foreach (array_values($row) as $v) {
            if (is_array($v) && array_key_exists('v', $v)) {
                $line[] = $v;
            } else {
                $line[] = ['v' => $v, 's' => is_int($v) || is_float($v) ? ($style ? 3 : 2) : $style];
            }
        }
        $styled[] = $line;
    }
    return xlsx_build_book([['name' => $sheetName, 'rows' => $styled, 'widths' => $widths, 'freeze' => 1]]);
}

/**
 * @param list<array{name: string, rows: list<list<mixed>>, widths?: list<float|int>, freeze?: int, merges?: list<string>}> $sheets
 */
function xlsx_build_book(array $sheets): string
{
    if (!$sheets) {
        $sheets = [['name' => 'Лист1', 'rows' => []]];
    }

    $sheetXml = [];
    foreach ($sheets as $si => $sheet) {
        $widths = $sheet['widths'] ?? [];
        $cols = '';
        foreach ($widths as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
        }
        $data = '';
        foreach (array_values($sheet['rows'] ?? []) as $r => $row) {
            $data .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $cell) {
                if (!is_array($cell) || !array_key_exists('v', $cell)) {
                    $cell = ['v' => $cell, 's' => 0];
                }
                $v = $cell['v'];
                if ($v === null || $v === '') {
                    if (!empty($cell['s'])) {
                        $data .= '<c r="' . xlsx_col($c) . ($r + 1) . '" s="' . (int) $cell['s'] . '"/>';
                    }
                    continue;
                }
                $ref = xlsx_col($c) . ($r + 1);
                $s = (int) ($cell['s'] ?? 0);
                if (is_int($v) || is_float($v)) {
                    $data .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $v . '</v></c>';
                } else {
                    $data .= '<c r="' . $ref . '" t="inlineStr" s="' . $s . '"><is><t xml:space="preserve">' . xlsx_esc((string) $v) . '</t></is></c>';
                }
            }
            $data .= '</row>';
        }
        $freeze = (int) ($sheet['freeze'] ?? 0);
        $views = $freeze > 0
            ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $freeze . '" topLeftCell="A' . ($freeze + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            : '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
        $mergeXml = '';
        $merges = $sheet['merges'] ?? [];
        if ($merges) {
            $mergeXml = '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $m) {
                $mergeXml .= '<mergeCell ref="' . xlsx_esc((string) $m) . '"/>';
            }
            $mergeXml .= '</mergeCells>';
        }
        $sheetXml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $views
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $data . '</sheetData>'
            . $mergeXml
            . '</worksheet>';
    }

    // Styles: 0 default, 1 bold, 2 number, 3 bold number,
    // 4 title dark, 5 header dark white, 6 section teal, 7 section rose, 8 section green,
    // 9 input yellow, 10 money, 11 money bold, 12 total dark, 13 pay green, 14 muted zebra
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2">'
        . '<numFmt numFmtId="164" formatCode="#,##0"/>'
        . '<numFmt numFmtId="165" formatCode="0%"/>'
        . '</numFmts>'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/><color rgb="FF0F172A"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF0F172A"/></font>'
        . '<font><b/><sz val="14"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'
        . '</fonts>'
        . '<fills count="10">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF115E59"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF881337"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF065F46"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEF9C3"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border/>'
        . '<border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="15">'
        . '<xf/>' // 0
        . '<xf fontId="1" applyFont="1"/>' // 1 bold
        . '<xf numFmtId="164" applyNumberFormat="1"/>' // 2 money
        . '<xf numFmtId="164" fontId="1" applyNumberFormat="1" applyFont="1"/>' // 3 bold money
        . '<xf fontId="2" fillId="2" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>' // 4 title
        . '<xf fontId="3" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 5 header
        . '<xf fontId="3" fillId="3" applyFont="1" applyFill="1"/>' // 6 section teal
        . '<xf fontId="3" fillId="4" applyFont="1" applyFill="1"/>' // 7 section rose
        . '<xf fontId="3" fillId="5" applyFont="1" applyFill="1"/>' // 8 section green
        . '<xf fillId="6" borderId="1" applyFill="1" applyBorder="1"/>' // 9 yellow
        . '<xf numFmtId="164" fillId="9" borderId="1" applyNumberFormat="1" applyFill="1" applyBorder="1"/>' // 10 money zebra
        . '<xf numFmtId="164" fontId="1" fillId="7" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>' // 11 pay
        . '<xf fontId="3" fillId="8" applyFont="1" applyFill="1"/>' // 12 total label
        . '<xf numFmtId="164" fontId="3" fillId="8" applyNumberFormat="1" applyFont="1" applyFill="1"/>' // 13 total money dark
        . '<xf borderId="1" applyBorder="1"/>' // 14 bordered
        . '</cellXfs></styleSheet>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . implode('', array_map(static fn (int $i): string => '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($sheets)))
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/styles.xml' => $styles,
    ];

    $wbSheets = '';
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheets as $i => $sheet) {
        $n = $i + 1;
        $name = mb_substr((string) ($sheet['name'] ?? ('Лист' . $n)), 0, 31);
        $wbSheets .= '<sheet name="' . xlsx_esc($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $files['xl/worksheets/sheet' . $n . '.xml'] = $sheetXml[$i];
    }
    $wbRels .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>';
    $files['xl/_rels/workbook.xml.rels'] = $wbRels;

    $zip = '';
    $central = '';
    $dosTime = 0;
    $dosDate = ((int) date('Y') - 1980) << 9 | (int) date('n') << 5 | (int) date('j');
    foreach ($files as $name => $body) {
        $crc = crc32($body);
        $len = strlen($body);
        $offset = strlen($zip);
        $zip .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $len, $len, strlen($name), 0) . $name . $body;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    return $zip . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($zip), 0);
}

function xlsx_send(string $filename, string $sheetName, array $rows, array $widths = []): never
{
    $styled = [];
    $last = count($rows);
    foreach (array_values($rows) as $r => $row) {
        $style = $r === 0 ? 1 : ($r === $last - 1 && $last > 2 ? 1 : 0);
        $line = [];
        foreach (array_values($row) as $v) {
            if (is_array($v) && array_key_exists('v', $v)) {
                $line[] = $v;
            } else {
                $line[] = ['v' => $v, 's' => is_int($v) || is_float($v) ? ($style ? 3 : 2) : $style];
            }
        }
        $styled[] = $line;
    }
    xlsx_send_book($filename, [['name' => $sheetName, 'rows' => $styled, 'widths' => $widths, 'freeze' => 1]]);
}

/** @param list<array{name: string, rows: list, widths?: list, freeze?: int, merges?: list}> $sheets */
function xlsx_send_book(string $filename, array $sheets): never
{
    $body = xlsx_build_book($sheets);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"export.xlsx\"; filename*=UTF-8''" . rawurlencode($filename));
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: private, no-store');
    echo $body;
    exit;
}

function xlsx_cell(mixed $v, int $style = 0): array
{
    return ['v' => $v, 's' => $style];
}
