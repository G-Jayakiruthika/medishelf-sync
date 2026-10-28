<?php
/* Medicine catalogue: normalising names, linking to ingredient info, matching scanned labels, importing lists. */
declare(strict_types=1);

const FORMS = [
    'tablet' => ['tablet', 'tablets', 'tab'], 'capsule' => ['capsule', 'capsules', 'cap', 'rotacap', 'rotacaps'],
    'syrup' => ['syrup', 'suspension', 'solution', 'expectorant', 'drops oral'], 'injection' => ['injection', 'infusion', 'vial'],
    'cream' => ['cream', 'gel', 'ointment', 'lotion'], 'drops' => ['eye drops', 'ear drops', 'eye/ear', 'ophthalmic', 'nasal'],
    'inhaler' => ['inhalation', 'inhaler', 'respules', 'nebuliser', 'mdi'], 'powder' => ['powder', 'sachet', 'granules'],
];

function norm(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('/[^a-z0-9.%]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/* "Amoxycillin 500mg and Potassium Clavulanate 125mg Tablets IP" -> "amoxycillin-500mg-and-potassium-clavulanate-125mg-tablets-ip" */
function slugify(string $s, int $max = 180): string
{
    $s = mb_strtolower(str_replace(['+', '%', '&'], [' and ', ' percent ', ' and '], $s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    if (strlen($s) > $max) $s = rtrim(substr($s, 0, $max), '-');
    return $s;
}

function dosage_form(string $name): ?string
{
    $n = ' ' . norm($name) . ' ';
    foreach (FORMS as $form => $words) foreach ($words as $w) if (str_contains($n, ' ' . $w)) return $form;
    return null;
}

/* All ingredient-info rows, cached per request. */
function info_rows(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = q('SELECT id, ingredient, keywords, use_text, rx, storage, note, slug FROM medicine_info')->fetchAll();
        foreach ($rows as &$r) $r['kw'] = array_values(array_filter(array_map('trim', explode(',', mb_strtolower($r['keywords'])))));
    }
    return $rows;
}

/* Which ingredients does this text mention? Returns [info_id => position of first mention]. */
function ingredients_in(string $text): array
{
    $n = ' ' . norm($text) . ' ';
    $found = [];
    foreach (info_rows() as $r) {
        foreach ($r['kw'] as $k) {
            $pos = strpos($n, ' ' . $k);
            if ($pos !== false) { $found[$r['id']] = min($found[$r['id']] ?? PHP_INT_MAX, $pos); }
        }
    }
    // A combination like "amoxicillin + clavulanate" should prefer the more specific entry.
    asort($found);
    return $found;
}

function primary_info_id(string $name): ?int
{
    $f = ingredients_in($name);
    if (!$f) return null;
    // Prefer the combination entry when its keyword appears (e.g. clavulanate)
    foreach (info_rows() as $r) if (isset($f[$r['id']]) && str_contains($r['ingredient'], '+')) return (int) $r['id'];
    return (int) array_key_first($f);
}

function strengths(string $text): array
{
    preg_match_all('/(\d+(?:\.\d+)?)\s*(mg|mcg|g|iu|ml|%)/i', $text, $m, PREG_SET_ORDER);
    return array_values(array_unique(array_map(fn($x) => rtrim(rtrim($x[1], '0'), '.') . strtolower($x[2]), $m)));
}

function info_public(?array $r): ?array
{
    if (!$r) return null;
    return ['id' => (int) $r['id'], 'ingredient' => $r['ingredient'], 'use' => $r['use_text'], 'rx' => (bool) $r['rx'], 'storage' => $r['storage'], 'note' => $r['note'], 'slug' => $r['slug'] ?? null];
}

function info_by_id(?int $id): ?array
{
    if (!$id) return null;
    foreach (info_rows() as $r) if ((int) $r['id'] === $id) return info_public($r);
    return null;
}

/* Best catalogue matches for text read from a label or QR code. */
function match_label(string $text, int $limit = 5): array
{
    $ing = ingredients_in($text);
    $str = strengths($text);
    $form = dosage_form($text);
    $cands = [];
    if ($ing) {
        $ids = array_keys($ing);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $cands = q("SELECT id, drug_code, generic_name, unit_size, dosage_form, info_id, search_text, source FROM medicine_catalog WHERE info_id IN ($ph) LIMIT 400", $ids)->fetchAll();
    }
    if (!$cands) {
        $words = array_filter(explode(' ', norm($text)), fn($w) => strlen($w) >= 5 && !ctype_digit($w));
        if ($words) {
            $cands = q('SELECT id, drug_code, generic_name, unit_size, dosage_form, info_id, search_text, source FROM medicine_catalog WHERE MATCH(search_text) AGAINST (? IN NATURAL LANGUAGE MODE) LIMIT 50', [implode(' ', array_slice($words, 0, 12))])->fetchAll();
        }
    }
    $textNorm = ' ' . norm($text) . ' ';
    foreach ($cands as &$c) {
        $score = 0;
        $rowIng = ingredients_in($c['generic_name']);
        foreach ($rowIng as $id => $_) $score += isset($ing[$id]) ? 10 : -6;       // penalise extra ingredients
        foreach (strengths($c['generic_name']) as $s) $score += in_array($s, $str, true) ? 6 : -1;
        if ($form && $c['dosage_form'] === $form) $score += 4;
        if (str_contains($textNorm, ' ' . $c['search_text'] . ' ')) $score += 20;
        $c['score'] = $score;
    }
    unset($c);
    usort($cands, fn($a, $b) => $b['score'] <=> $a['score']);
    $out = [];
    foreach (array_slice($cands, 0, $limit) as $c) {
        $out[] = ['id' => (int) $c['id'], 'name' => $c['generic_name'], 'unit' => $c['unit_size'], 'code' => $c['drug_code'], 'source' => $c['source'], 'score' => $c['score'], 'info' => info_by_id($c['info_id'] ? (int) $c['info_id'] : null)];
    }
    $primary = $ing ? info_by_id((int) array_key_first($ing)) : null;
    return ['matches' => $out, 'info' => $out[0]['info'] ?? $primary];
}

/* Typed search ("dolo", "telmisartan 40"). */
function search_catalog(string $q, int $limit = 20): array
{
    $words = array_values(array_filter(explode(' ', norm($q)), fn($w) => $w !== ''));
    if (!$words) return [];
    $where = []; $params = [];
    foreach (array_slice($words, 0, 5) as $w) { $where[] = 'search_text LIKE ?'; $params[] = '%' . $w . '%'; }
    $params[] = $limit;
    $rows = q('SELECT id, drug_code, generic_name, unit_size, info_id, source FROM medicine_catalog WHERE ' . implode(' AND ', $where) . ' ORDER BY LENGTH(generic_name) LIMIT ?', $params)->fetchAll();
    return array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['generic_name'], 'unit' => $c['unit_size'], 'code' => $c['drug_code'], 'source' => $c['source'], 'info' => info_by_id($c['info_id'] ? (int) $c['info_id'] : null)], $rows);
}

/* Insert or update catalogue rows. $rows = [[drug_code, generic_name, unit_size], ...] */
function import_catalog(array $rows, string $source): array
{
    $ins = db()->prepare('INSERT INTO medicine_catalog (source, drug_code, generic_name, unit_size, dosage_form, info_id, search_text, slug)
        VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE generic_name=VALUES(generic_name), unit_size=VALUES(unit_size),
        dosage_form=VALUES(dosage_form), info_id=VALUES(info_id), search_text=VALUES(search_text), slug=VALUES(slug)');
    $added = 0; $skipped = 0;
    db()->beginTransaction();
    foreach ($rows as $r) {
        [$code, $name, $unit] = [trim((string) ($r[0] ?? '')), trim((string) ($r[1] ?? '')), trim((string) ($r[2] ?? ''))];
        if ($name === '' || mb_strlen($name) > 300) { $skipped++; continue; }
        $code = $code === '' ? substr(sha1($name), 0, 12) : substr($code, 0, 20);
        $slug = slugify($name) . '-' . preg_replace('/[^a-z0-9]/', '', strtolower($source === 'import' ? substr(sha1($code), 0, 6) : $code));
        $ins->execute([$source, $code, $name, $unit !== '' ? mb_substr($unit, 0, 80) : null, dosage_form($name), primary_info_id($name), mb_substr(norm($name), 0, 400), $slug]);
        $added++;
    }
    db()->commit();
    return ['imported' => $added, 'skipped' => $skipped];
}

/* Parse a CSV (drug_code,generic_name,unit_size) or pipe file (s_no|drug_code|generic_name|unit_size). */
function parse_list_file(string $content): array
{
    $rows = [];
    $lines = preg_split('/\r\n|\r|\n/', $content);
    foreach ($lines as $i => $line) {
        if (trim($line) === '') continue;
        if (str_contains($line, '|')) {
            $p = array_map('trim', explode('|', $line));
            if (count($p) >= 4) $rows[] = [$p[1], $p[2], $p[3]];
            elseif (count($p) === 3) $rows[] = $p;
        } else {
            $p = str_getcsv($line);
            if ($i === 0 && preg_match('/generic|name/i', $line)) continue;   // header
            if (count($p) >= 3) $rows[] = [$p[0], $p[1], $p[2]];
            elseif (count($p) === 2) $rows[] = [$p[0], $p[1], ''];
        }
    }
    return $rows;
}

function load_info_json(string $file): int
{
    $items = json_decode((string) file_get_contents($file), true) ?: [];
    $st = db()->prepare('INSERT INTO medicine_info (ingredient, keywords, use_text, rx, storage, note, slug) VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE keywords=VALUES(keywords), use_text=VALUES(use_text), rx=VALUES(rx), storage=VALUES(storage), note=VALUES(note), slug=VALUES(slug)');
    foreach ($items as $it) $st->execute([$it['ingredient'], $it['keywords'], $it['use'], (int) $it['rx'], $it['storage'] ?: null, $it['note'] ?: null, slugify($it['ingredient'])]);
    return count($items);
}

/* Re-link every catalogue row to ingredient info (run after info changes). */
function relink_catalog(): int
{
    $rows = q('SELECT id, generic_name FROM medicine_catalog')->fetchAll();
    $st = db()->prepare('UPDATE medicine_catalog SET info_id = ? WHERE id = ?');
    foreach ($rows as $r) $st->execute([primary_info_id($r['generic_name']), $r['id']]);
    return count($rows);
}
