<?php
/* Products and stock per branch and batch, with a ledger of every change.
   Stock arrives three ways: typed at the counter, a CSV export from the shop's billing software (Marg, GoFrugal,
   Medeil, Tally...), or the Java sync agent that watches that export folder on the shop's computer. */
declare(strict_types=1);
require_once __DIR__ . '/compliance.php';

function product_search_text(string $name, ?string $generic): string { return mb_strtolower(trim($name . ' ' . ($generic ?? '')) . ' ' . preg_replace('/[^a-z0-9 ]+/i', ' ', $name . ' ' . $generic)); }

/* Find or create a product of this business by barcode, then name. */
function product_upsert(int $orgId, array $p): int
{
    $name = trim(mb_substr((string) ($p['name'] ?? ''), 0, 160));
    if (mb_strlen($name) < 2) fail('bad_name', 'Enter the medicine name.');
    $barcode = preg_replace('/\D/', '', (string) ($p['barcode'] ?? '')) ?: null;
    $row = $barcode ? q('SELECT id FROM products WHERE org_id = ? AND barcode = ?', [$orgId, $barcode])->fetch() : null;
    if (!$row) $row = q('SELECT id FROM products WHERE org_id = ? AND name = ?', [$orgId, $name])->fetch();
    $generic = isset($p['generic']) ? mb_substr(trim((string) $p['generic']), 0, 160) : null;
    $given = in_array($p['schedule'] ?? '', SCHEDULES, true) ? $p['schedule'] : null;   // a schedule only changes when it is given explicitly
    $sched = $given ?? schedule_guess($name . ' ' . $generic);
    $mrp = isset($p['mrp']) && $p['mrp'] !== '' && $p['mrp'] !== null ? round((float) $p['mrp'], 2) : null;
    $cold = !empty($p['cold']) || preg_match('/insulin|vaccine|human mixtard|lantus|actrapid/i', $name) ? 1 : 0;
    if ($row) {
        q('UPDATE products SET generic = COALESCE(?, generic), barcode = COALESCE(?, barcode), schedule = COALESCE(?, schedule), pack = COALESCE(?, pack), mrp = COALESCE(?, mrp), cold = GREATEST(cold, ?),
           catalog_id = COALESCE(?, catalog_id), search = ? WHERE id = ?',
          [$generic, $barcode, $given, $p['pack'] ?? null, $mrp, $cold, isset($p['catalog_id']) ? ((int) $p['catalog_id'] ?: null) : null, product_search_text($name, $generic), $row['id']]);
        return (int) $row['id'];
    }
    q('INSERT INTO products (org_id, name, generic, catalog_id, barcode, schedule, pack, mrp, cold, search) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [$orgId, $name, $generic, (int) ($p['catalog_id'] ?? 0) ?: null, $barcode, $sched, $p['pack'] ?? null, $mrp, $cold, product_search_text($name, $generic)]);
    return (int) db()->lastInsertId();
}

/* Change stock and write the ledger. $delta in selling units. Stock never goes below zero. */
function stock_move(int $branchId, int $productId, string $batch, int $delta, string $reason, ?string $ref = null, ?int $staffId = null, ?string $exp = null, ?float $price = null): void
{
    $batch = mb_substr(trim($batch), 0, 40);
    q('INSERT INTO stock (branch_id, product_id, batch, exp, qty, price) VALUES (?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE qty = GREATEST(0, qty + VALUES(qty)), exp = COALESCE(VALUES(exp), exp), price = COALESCE(VALUES(price), price)',
      [$branchId, $productId, $batch, $exp, $delta, $price]);
    q('INSERT INTO stock_moves (branch_id, product_id, batch, delta, reason, ref, staff_id) VALUES (?,?,?,?,?,?,?)', [$branchId, $productId, $batch, $delta, $reason, $ref, $staffId]);
}

/* Set the counted quantity of a batch (from a stock count or a billing-software export). */
function stock_set(int $branchId, int $productId, string $batch, int $qty, ?string $exp, ?float $price, string $reason = 'count', ?int $staffId = null): void
{
    $cur = (int) (q('SELECT qty FROM stock WHERE branch_id = ? AND product_id = ? AND batch = ?', [$branchId, $productId, $batch])->fetchColumn() ?: 0);
    stock_move($branchId, $productId, $batch, max(0, $qty) - $cur, $reason, null, $staffId, $exp, $price);
}

/* Sell from the batches that expire first (FEFO). Returns the batches used. Expired batches are never sold. */
function stock_take(int $branchId, int $productId, int $qty, string $ref, ?int $staffId = null): array
{
    $used = []; $left = $qty;
    foreach (q('SELECT batch, exp, qty, price FROM stock WHERE branch_id = ? AND product_id = ? AND qty > 0 AND (exp IS NULL OR exp >= CURDATE()) ORDER BY exp IS NULL, exp, id FOR UPDATE', [$branchId, $productId])->fetchAll() as $b) {
        if ($left <= 0) break;
        $n = min($left, (int) $b['qty']);
        stock_move($branchId, $productId, $b['batch'], -$n, 'sale', $ref, $staffId);
        $used[] = ['batch' => $b['batch'], 'exp' => $b['exp'], 'qty' => $n, 'price' => $b['price'] !== null ? (float) $b['price'] : null];
        $left -= $n;
    }
    return ['batches' => $used, 'short' => $left];
}

function stock_available(int $branchId, int $productId): int
{
    return (int) q('SELECT COALESCE(SUM(qty), 0) FROM stock WHERE branch_id = ? AND product_id = ? AND (exp IS NULL OR exp >= CURDATE())', [$branchId, $productId])->fetchColumn();
}

function exp_from(string $s): ?string
{
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{1,2})(?:-\d{1,2})?$/', $s, $m)) [$y, $mo] = [(int) $m[1], (int) $m[2]];
    elseif (preg_match('/^(\d{1,2})[\/\-.](\d{2,4})$/', $s, $m)) [$mo, $y] = [(int) $m[1], (int) $m[2]];
    elseif (preg_match('/^\d{1,2}[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $s, $m)) [$mo, $y] = [(int) $m[1], (int) $m[2]];
    elseif (preg_match('/^([A-Za-z]{3})[A-Za-z]*[\s\-\/]*(\d{2,4})$/', $s, $m)) { $mo = (int) date('n', strtotime('1 ' . $m[1] . ' 2000')); $y = (int) $m[2]; }
    else return null;
    if ($y < 100) $y += 2000;
    if ($mo < 1 || $mo > 12 || $y < 2000 || $y > 2100) return null;
    return date('Y-m-t', mktime(0, 0, 0, $mo, 1, $y));
}

/* Columns found in common billing-software exports. */
const CSV_COLS = [
    'name'    => ['item name', 'product name', 'item', 'product', 'name', 'medicine', 'description', 'itemname', 'productname'],
    'generic' => ['generic', 'composition', 'salt', 'content', 'molecule'],
    'batch'   => ['batch', 'batch no', 'batchno', 'batch number', 'b.no', 'bno'],
    'exp'     => ['expiry', 'exp', 'exp date', 'expiry date', 'expdate', 'exp.dt', 'expiry dt'],
    'qty'     => ['qty', 'quantity', 'stock', 'closing stock', 'cl stock', 'clqty', 'balance', 'closing qty', 'stock qty'],
    'mrp'     => ['mrp', 'm.r.p', 'mrp rs'],
    'price'   => ['rate', 'sale rate', 'selling price', 'srate', 'price', 'sale price'],
    'barcode' => ['barcode', 'ean', 'gtin', 'bar code'],
    'pack'    => ['pack', 'packing', 'pack size', 'unit'],
    'schedule'=> ['schedule', 'sch', 'drug schedule'],
];

/* Import a CSV of the whole stock (full count): batches missing from the file are set to zero. */
function stock_import_csv(int $orgId, int $branchId, string $csv, ?int $staffId = null, bool $full = true): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $lines = preg_split('/\r\n|\n|\r/', trim($csv));
    if (count($lines) < 2) fail('empty_csv', 'The file has no rows.');
    $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : (substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',');
    $head = array_map(fn($h) => strtolower(trim(preg_replace('/\s+/', ' ', $h), " \"'")), str_getcsv($lines[0], $sep, '"', ''));
    $col = [];
    foreach (CSV_COLS as $k => $names) foreach ($head as $i => $h) if (in_array($h, $names, true)) { $col[$k] = $i; break; }
    if (!isset($col['name'], $col['qty'])) fail('bad_csv', 'The file needs at least an item name column and a quantity column. Found: ' . implode(', ', array_slice($head, 0, 12)));
    $seen = []; $rows = 0; $skipped = 0; $priceFixed = 0;
    foreach (array_slice($lines, 1, 20000) as $line) {
        if (trim($line) === '') continue;
        $c = str_getcsv($line, $sep, '"', '');
        $get = fn($k) => isset($col[$k], $c[$col[$k]]) ? trim($c[$col[$k]]) : '';
        $name = $get('name'); $qty = (int) round((float) str_replace(',', '', $get('qty')));
        if (mb_strlen($name) < 2) { $skipped++; continue; }
        $mrp = $get('mrp') !== '' ? (float) str_replace(',', '', $get('mrp')) : null;
        $price = $get('price') !== '' ? (float) str_replace(',', '', $get('price')) : null;
        if ($price !== null && $mrp !== null && $price > $mrp) { $price = $mrp; $priceFixed++; }   // never above MRP
        $pid = product_upsert($orgId, ['name' => $name, 'generic' => $get('generic') ?: null, 'barcode' => $get('barcode'), 'mrp' => $mrp, 'pack' => $get('pack') ?: null,
                                       'schedule' => strtoupper($get('schedule')) ?: null]);
        $batch = $get('batch');
        stock_set($branchId, $pid, $batch, max(0, $qty), exp_from($get('exp')), $price, 'sync', $staffId);
        $seen[$pid . '|' . $batch] = true; $rows++;
    }
    $zeroed = 0;
    if ($full && $rows > 0) foreach (q('SELECT product_id, batch FROM stock WHERE branch_id = ? AND qty > 0', [$branchId])->fetchAll() as $s)
        if (!isset($seen[$s['product_id'] . '|' . $s['batch']])) { stock_set($branchId, (int) $s['product_id'], $s['batch'], 0, null, null, 'sync', $staffId); $zeroed++; }
    return ['rows' => $rows, 'skipped' => $skipped, 'zeroed' => $zeroed, 'priceCapped' => $priceFixed];
}

/* Stock list for the portal, with what needs attention. */
function stock_list(int $branchId, string $qText = '', string $filter = 'all', int $limit = 100): array
{
    $where = 's.branch_id = ?'; $args = [$branchId];
    if ($qText !== '') { $where .= ' AND (p.name LIKE ? OR p.generic LIKE ? OR p.barcode = ?)'; array_push($args, '%' . $qText . '%', '%' . $qText . '%', $qText); }
    if ($filter === 'expiring') $where .= ' AND s.qty > 0 AND s.exp IS NOT NULL AND s.exp < CURDATE() + INTERVAL 90 DAY';
    if ($filter === 'expired') $where .= ' AND s.qty > 0 AND s.exp < CURDATE()';
    if ($filter === 'out') $where .= ' AND s.qty = 0';
    if ($filter === 'cold') $where .= ' AND p.cold = 1';
    return array_map(fn($r) => ['stockId' => (int) $r['id'], 'productId' => (int) $r['product_id'], 'name' => $r['name'], 'generic' => $r['generic'], 'schedule' => $r['schedule'],
        'batch' => $r['batch'], 'exp' => $r['exp'], 'qty' => (int) $r['qty'], 'price' => $r['price'] !== null ? (float) $r['price'] : null, 'mrp' => $r['mrp'] !== null ? (float) $r['mrp'] : null,
        'cold' => (bool) $r['cold'], 'barcode' => $r['barcode']],
        q("SELECT s.*, p.name, p.generic, p.schedule, p.mrp, p.cold, p.barcode FROM stock s JOIN products p ON p.id = s.product_id WHERE $where ORDER BY p.name, s.exp LIMIT " . max(1, min(500, $limit)), $args)->fetchAll());
}

/* Match free text (a name from a prescription, an SMS or the family app) to this business's products. */
function product_match(int $orgId, string $name, ?int $catalogId = null): ?array
{
    if ($catalogId) { $r = q('SELECT * FROM products WHERE org_id = ? AND catalog_id = ? LIMIT 1', [$orgId, $catalogId])->fetch(); if ($r) return $r; }
    $clean = trim(preg_replace('/\b(tab|tabs|tablet|tablets|cap|caps|capsule|syp|syrup|inj|injection|strip|strips)\b\.?/i', '', $name));
    if (mb_strlen($clean) < 2) return null;
    $r = q('SELECT * FROM products WHERE org_id = ? AND name = ? LIMIT 1', [$orgId, $clean])->fetch();
    if ($r) return $r;
    $words = array_values(array_filter(preg_split('/\s+/', mb_strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $clean))), fn($w) => mb_strlen($w) >= 2));
    if (!$words) return null;
    $like = implode(' AND ', array_fill(0, count($words), 'search LIKE ?'));
    return q("SELECT * FROM products WHERE org_id = ? AND $like ORDER BY LENGTH(name) LIMIT 1", array_merge([$orgId], array_map(fn($w) => '%' . $w . '%', array_slice($words, 0, 4))))->fetch() ?: null;
}
