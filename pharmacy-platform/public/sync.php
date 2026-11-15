<?php
/* API for the stock-sync agent (sync-agent/, Java) running on the shop's billing computer.
   It watches the folder where the billing software (Marg, GoFrugal, Medeil, Tally...) exports the closing stock and uploads it.
   POST sync.php?action=stock   header Authorization: Bearer abs_...   body {csv, full, file, sha256}  → {rows, skipped, zeroed}
   POST sync.php?action=ping    → {ok, branch}  (checks the token)
   The Python analytics (analytics/) uses the same token:
   POST sync.php?action=history  {days}       → {branch, products, sales:[{p, d, n}], stock:[{p, batch, exp, qty}], siblings:[...]}
   POST sync.php?action=insights {items:[{kind, product_id, title, data}]} → {saved}  (replaces this branch's open analytics suggestions) */
declare(strict_types=1);
require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/stock.php';
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('method', 'POST only.', 405);
$tok = preg_replace('/^Bearer\s+/i', '', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
$t = preg_match('/^abs_[a-f0-9]{48}$/', $tok) ? q('SELECT t.*, b.org_id, b.name bname FROM sync_tokens t JOIN branches b ON b.id = t.branch_id WHERE t.token_hash = ?', [hash('sha256', $tok)])->fetch() : null;
if (!$t) { limit('syncbad:' . client_ip(), 20, 10); fail('unauthorised', 'Unknown or revoked sync token. Make a new one in the portal (Stock → Billing software sync).', 401); }
$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 8 * 1024 * 1024) fail('too_big', 'Upload is larger than 8 MB.', 413);
$in = json_decode($raw, true) ?: [];
switch ((string) ($_GET['action'] ?? '')) {
case 'ping': q('UPDATE sync_tokens SET last_used = NOW() WHERE id = ?', [$t['id']]); json_out(['ok' => true, 'branch' => $t['bname']]);
case 'stock':
    limit('sync:' . $t['id'], 30, 60);
    $csv = (string) ($in['csv'] ?? '');
    if (isset($in['sha256']) && !hash_equals((string) $in['sha256'], hash('sha256', $csv))) fail('corrupt', 'The upload was damaged on the way. The agent will retry.', 422);
    $r = stock_import_csv((int) $t['org_id'], (int) $t['branch_id'], $csv, null, !empty($in['full']));
    q('UPDATE sync_tokens SET last_used = NOW(), last_rows = ? WHERE id = ?', [$r['rows'], $t['id']]);
    audit('system', (int) $t['id'], (int) $t['org_id'], (int) $t['branch_id'], 'sync_stock', mb_substr((string) ($in['file'] ?? ''), 0, 80) . ' ' . json_encode($r));
    json_out(['ok' => true] + $r);
case 'history':   // sales and stock for demand forecasts, reorder and expiry suggestions (no customer data)
    limit('synchist:' . $t['id'], 20, 60);
    $days = max(28, min(400, (int) ($in['days'] ?? 180)));
    $b = q('SELECT id, name, area, district, lat, lng FROM branches WHERE id = ?', [$t['branch_id']])->fetch();
    $sales = q("SELECT product_id p, DATE(created_at) d, -SUM(delta) n FROM stock_moves WHERE branch_id = ? AND reason = 'sale' AND created_at >= CURDATE() - INTERVAL ? DAY GROUP BY product_id, DATE(created_at)", [$t['branch_id'], $days])->fetchAll();
    $stock = q('SELECT product_id p, batch, exp, qty FROM stock WHERE branch_id = ? AND qty > 0', [$t['branch_id']])->fetchAll();
    $ids = array_values(array_unique(array_merge(array_column($sales, 'p'), array_column($stock, 'p'))));
    $products = $ids ? q('SELECT id, name, generic, schedule, pack, cold FROM products WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll() : [];
    // Other shops of the same business: where surplus stock could be moved before it expires
    $siblings = [];
    foreach (q('SELECT id, name, area, lat, lng FROM branches WHERE org_id = ? AND id <> ?', [$t['org_id'], $t['branch_id']])->fetchAll() as $o) {
        $o['sales'] = q("SELECT product_id p, -SUM(delta) n FROM stock_moves WHERE branch_id = ? AND reason = 'sale' AND created_at >= CURDATE() - INTERVAL 28 DAY GROUP BY product_id", [$o['id']])->fetchAll();
        $siblings[] = $o;
    }
    json_out(['branch' => $b, 'days' => $days, 'today' => date('Y-m-d'), 'products' => $products,
              'sales' => array_map(fn($r) => ['p' => (int) $r['p'], 'd' => $r['d'], 'n' => (int) $r['n']], $sales),
              'stock' => array_map(fn($r) => ['p' => (int) $r['p'], 'batch' => $r['batch'], 'exp' => $r['exp'], 'qty' => (int) $r['qty']], $stock), 'siblings' => $siblings]);
case 'insights':
    limit('syncins:' . $t['id'], 20, 60);
    $items = array_slice(is_array($in['items'] ?? null) ? $in['items'] : [], 0, 300);
    $kinds = ['reorder', 'expiry', 'transfer', 'demand', 'route'];
    db()->beginTransaction();
    q("DELETE FROM insights WHERE branch_id = ? AND status = 'open' AND kind IN ('reorder','expiry','transfer','demand','route')", [$t['branch_id']]);
    $n = 0;
    foreach ($items as $it) {
        if (!is_array($it) || !in_array($it['kind'] ?? '', $kinds, true) || trim((string) ($it['title'] ?? '')) === '') continue;
        $pid = (int) ($it['product_id'] ?? 0) ?: null;
        if ($pid && !q('SELECT 1 FROM products WHERE id = ? AND org_id = ?', [$pid, $t['org_id']])->fetch()) $pid = null;
        q('INSERT INTO insights (branch_id, kind, product_id, title, data) VALUES (?,?,?,?,?)', [$t['branch_id'], $it['kind'], $pid, mb_substr((string) $it['title'], 0, 200), mb_substr(json_encode($it['data'] ?? null, JSON_UNESCAPED_UNICODE), 0, 4000)]);
        $n++;
    }
    db()->commit();
    audit('system', (int) $t['id'], (int) $t['org_id'], (int) $t['branch_id'], 'analytics', "$n suggestions");
    json_out(['ok' => true, 'saved' => $n]);
default: fail('unknown_action', 'Unknown action.', 404);
}
