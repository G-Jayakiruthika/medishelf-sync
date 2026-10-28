<?php
/* Import a medicine list into the catalogue.
   Usage (run from the project folder):
     php tools/import_list.php path/to/list.pdf  janaushadhi-2025-12     (official PMBJP PDF; needs pdftotext)
     php tools/import_list.php path/to/list.csv  my-source               (CSV: drug_code,generic_name,unit_size)
   The official PDF has top-aligned cells (default). For PDFs with vertically centred cells add --align=auto.
   Add --dry-run to only print what would be imported, or --out=file.csv to save the parsed rows. */
declare(strict_types=1);
require __DIR__ . '/../public_html/core/bootstrap.php';
require __DIR__ . '/../public_html/core/catalog.php';

$args = array_values(array_filter($argv, fn($a) => !str_starts_with($a, '--')));
$dry = in_array('--dry-run', $argv, true);
$outFile = null;
foreach ($argv as $a) if (str_starts_with($a, '--out=')) $outFile = substr($a, 6);
[$_, $file, $source] = $args + [null, null, 'import'];
if (!$file || !is_file($file)) { fwrite(STDERR, "File not found. Usage: php tools/import_list.php <file.pdf|file.csv> <source> [--dry-run] [--out=rows.csv]\n"); exit(1); }

if (preg_match('/\.pdf$/i', $file)) {
    $txt = shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' - 2>/dev/null');
    if (!$txt) { fwrite(STDERR, "Could not read the PDF. Install poppler-utils (pdftotext).\n"); exit(1); }
    $align = in_array('--align=auto', $argv, true) ? 'auto' : 'top';
    $rows = parse_pmbjp_text($txt, $align);
} else {
    $rows = parse_list_file((string) file_get_contents($file));
}

echo count($rows) . " rows parsed from " . basename($file) . "\n";
if ($outFile) {
    $fh = fopen($outFile, 'w');
    fputcsv($fh, ['drug_code', 'generic_name', 'unit_size']);
    foreach ($rows as $r) fputcsv($fh, $r);
    fclose($fh);
    echo "Saved to $outFile\n";
}
if ($dry) { foreach (array_slice($rows, 0, 10) as $r) echo implode(' | ', $r), "\n"; exit(0); }
$res = import_catalog($rows, $source);
echo "Imported {$res['imported']}, skipped {$res['skipped']} into source '$source'.\n";

/* The PMBJP annexure is a table: S.No | Drug Code | Generic Name | Unit Size.
   Long names wrap. Depending on the PDF, the extra lines sit below the numbered line (top-aligned cells)
   or above and below it (vertically centred cells). Lines between two numbered rows are given to the
   previous row if its name still looks unfinished, otherwise to the next row. Check with --dry-run / --out. */
function parse_pmbjp_text(string $txt, string $align = 'top'): array
{
    $rows = []; $cur = null; $pending = []; $unitCol = 0;
    $flush = function (bool $toNext) use (&$cur, &$pending, &$rows) {
        if (!$pending) return '';
        $text = []; $unit = '';
        foreach ($pending as $parts) { if ($parts[0] !== '') $text[] = $parts[0]; if (count($parts) >= 2) $unit = trim($unit . ' ' . end($parts)); }
        $pending = [];
        if ($toNext || !$cur) return [implode(' ', $text), $unit];
        $cur[1] .= ' ' . implode(' ', $text);
        if ($unit !== '') $cur[2] = trim($cur[2] . ' ' . $unit);
        return '';
    };
    foreach (preg_split('/\R/', $txt) as $line) {
        if (preg_match('/information was given|Release ID|Visitor Counter|^\s*\*\*\*/i', $line)) break;   // press-release footer
        if (trim($line) === '' || preg_match('/^\s*(Code|•|\x{2022})\s*$/u', $line) || preg_match('/S\.?\s*No|Drug\s*Code|Generic\s*Name|Unit\s*Size|^\s*Page\s+\d+|Annex|generic medicines under PMBJP/i', $line)) continue;
        if (preg_match('/^\s*(\d{1,4})\s+(\d{1,5})\s+(.+?)(?:\s{2,}(\S.*?))?\s*$/u', $line, $m, PREG_OFFSET_CAPTURE)) {
            if (isset($m[4]) && $m[4][1] > 0) $unitCol = $m[4][1];
            $m = array_map(fn($g) => $g[0], $m);
            $prefix = ['', ''];
            if ($pending) {
                $toNext = $cur === null || ($align === 'auto' && name_looks_complete($cur[1]));
                $r = $flush($toNext);
                if ($toNext) $prefix = $r;
            }
            if ($cur) $rows[] = $cur;
            $cur = [$m[2], trim($prefix[0] . ' ' . trim($m[3])), trim(($m[4] ?? '') !== '' ? $m[4] : $prefix[1])];
        } else {
            // A wrapped line that starts in the Unit Size column belongs to the unit, not the name.
            $indent = strlen($line) - strlen(ltrim($line));
            if ($unitCol > 0 && $indent >= $unitCol - 3) $pending[] = ['', trim($line)];
            else $pending[] = preg_split('/\s{2,}/', trim($line));
        }
    }
    if ($pending) $flush(false);
    if ($cur) $rows[] = $cur;
    return array_map(fn($r) => [$r[0], trim(preg_replace('/\s+/', ' ', $r[1])), trim(preg_replace('/\s+/', ' ', $r[2]))], $rows);
}

function name_looks_complete(string $name): bool
{
    return (bool) preg_match('/(tablets?|capsules?|injection|infusion|syrup|suspension|solution|cream|gel|ointment|lotion|drops|spray|inhaler|inhalation|respules|powder|granules|sachet|shampoo|soap|ip|\d\s*(mg|mcg|g|ml|iu|%)|\)|w\/w|w\/v)\s*$/i', trim($name));
}
