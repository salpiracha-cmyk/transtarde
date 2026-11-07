<?php
declare(strict_types=1);

function mr_pdf_number(mixed $value): string {
    return $value === null ? '-' : number_format((float)$value, 3);
}

/** Render canonical recovery sections as paginated landscape tables. */
function mr_pdf_render(array $report): string {
    $pages = [];
    $stream = '';
    $y = 0;
    $width = 770;
    $write = static function (float $x, float $y, string $text, int $size = 9, string $font = 'F1') use (&$stream): void {
        $text = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) ?: ($text === '0' ? '0' : '');
        $text = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $text);
        $stream .= "BT /$font $size Tf 1 0 0 1 $x $y Tm ($text) Tj ET\n";
    };
    $page = static function () use (&$pages, &$stream, &$y, $write, $report): void {
        if ($stream !== '') $pages[] = $stream;
        $stream = '';
        $write(36, 552, 'TRANSTRADE INTERNATIONAL', 22, 'F2');
        $filters = $report['filters'];
        $title = $report['title'] ?? 'Daily Recovery';
        $write(36, 531, $title.' | '.$report['entity'].' | '.($filters['from'] ?? '').' to '.($filters['to'] ?? ''), 11);
        $write(755, 531, 'Page '.(count($pages) + 1));
        $stream .= "0.17 0.35 0.29 RG 36 522 m 806 522 l S\n";
        $y = 505;
        if (!empty($report['reference'])) {
            $write(36, $y, (string)$report['reference'], 10, 'F2');
            $y -= 20;
        }
    };
    $wrap = static function (array $cells, array $widths): array {
        $wrapped = [];
        foreach ($cells as $i => $value) {
            $wrapped[] = explode("\n", wordwrap(str_replace(["\r","\n"], ' ', (string)$value), max(1, (int)(($widths[$i] - 10) / 5.4)), "\n", true));
        }
        return $wrapped;
    };
    $heightOf = static fn(array $wrapped): int => 10 + 11 * max(array_map('count', $wrapped));
    $row = static function (array $wrapped, array $widths, bool $header = false) use (&$stream, &$y, $write, $width, $heightOf): void {
        $height = $heightOf($wrapped);
        if ($header) $stream .= '0.91 0.95 0.93 rg 36 '.($y-$height)." $width $height re f 0 g\n";
        $stream .= '0.72 0.79 0.75 RG 36 '.($y-$height)." $width $height re S\n";
        $x = 36;
        foreach ($wrapped as $i => $lines) {
            if ($i > 0) $stream .= "$x $y m $x ".($y-$height)." l S\n";
            foreach ($lines as $j => $line) $write($x+5, $y-13-11*$j, $line, 9, 'F3');
            $x += $widths[$i];
        }
        $y -= $height;
    };
    $table = static function (string $title, array $headings, array $widths, array $rows) use ($page, $row, $write, $wrap, $heightOf, &$y): void {
        if (!$rows) return;
        $start = static function () use ($title, $headings, $widths, $row, $write, $wrap, &$y): void {
            $write(36, $y, $title, 11, 'F2');
            $y -= 8;
            $row($wrap($headings, $widths), $widths, true);
        };
        if ($y < 135) $page();
        $start();
        foreach ($rows as $cells) {
            $wrapped = $wrap($cells, $widths);
            if ($y - $heightOf($wrapped) < 36) { $page(); $start(); }
            $row($wrapped, $widths);
        }
        $y -= 20;
    };
    $page();
    if (isset($report['tables'])) {
        foreach ($report['tables'] as $section) {
            $table($section['title'], $section['headers'], $section['widths'], $section['rows']);
        }
    } else {
    $totals = static fn(array $g): array => array_map('mr_pdf_number', [$g['inputKg'], $g['readyKg'], $g['byProductKg'], $g['outputKg'], $g['recoveryPct'], $g['differenceKg']]);
    $summaryHeaders = ['Date', 'Input kg', 'Ready kg', 'By-products kg', 'Output kg', 'Recovery %', 'Difference kg'];
    $summaryWidths = [110,110,110,110,110,100,120];
    $table('Recovery summary', $summaryHeaders, $summaryWidths, [
        ...array_map(static fn($g) => [$g['key'], ...$totals($g)], $report['groups']),
        ['Total', ...$totals($report['totals'])],
    ]);
    foreach ($report['groups'] as $group) {
        $table($group['key'].' | Shift details', ['Shift / status','Mill / variety','Start / end','Hours','Downtime / notes'], [120,185,105,65,295],
            array_map(static fn($r) => [
                $r['shift'].' / '.($r['shiftComplete'] ? 'Complete' : 'In progress'),
                $r['mill'].' / '.$r['variety'], implode(' - ', array_filter([$r['start'], $r['end']])),
                mr_pdf_number($r['hours']), implode(' / ', array_filter([$r['downtimeReason'], $r['reason'], $r['varianceReason']])),
            ], $group['runs']));
        foreach (['raw'=>'Raw rice input / stock consumption','finished'=>'Ready rice','byproducts'=>'By-products'] as $section => $label) {
            $table($group['key'].' | '.$label, ['Shift / lot','Product','Brand / order','Bags','Pack kg','Gross kg','Less kg','Net kg','Ratio %'], [105,130,130,55,60,75,70,75,70],
                array_map(static fn($r) => [
                    trim($r['shift'].' '.$r['lotRef']), $r['product'], implode(' / ', array_filter([$r['brand'], $r['contractRef']])),
                    ...array_map('mr_pdf_number', [$r['bags'], $r['bagWeight'] ?? null, $r['grossKg'], $r['lessKg'], $r['kg'], $r['recoveryPct']]),
                ], $group[$section]));
        }
    }
    }
    $pages[] = $stream;
    $objects = ['', '', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>'];
    $kids = [];
    foreach ($pages as $content) {
        $id = count($objects) + 1;
        $kids[] = "$id 0 R";
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents '.($id+1).' 0 R >>';
        $objects[] = '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream';
    }
    $objects[0] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[1] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i+1)." 0 obj\n$object\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
    foreach ($offsets as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
    return $pdf."trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}
