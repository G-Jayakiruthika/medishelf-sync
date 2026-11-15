<?php
/* Data for the Python analytics (analytics/) and saving its suggestions. Used by sync.php (a shop's own token) and by
   cron.php (every shop, nightly). Only stock and sales counts leave the database: no customer data. */
declare(strict_types=1);

function analytics_history(int $branchId, int $days = 180): array
{
    $days = max(28, min(400, $days));
    $b = q('SELECT id, org_id, name, area, district, lat, lng FROM branches WHERE id = ?', [$branchId])->fetch();
    $sales = q("SELECT product_id p, DATE(created_at) d, -SUM(delta) n FROM stock_moves WHERE branch_id = ? AND reason = 'sale' AND created_at >= CURDATE() - INTERVAL ? DAY GROUP BY product_id, DATE(created_at)", [$branchId, $days])->fetchAll();
    $stock = q('SELECT product_id p, batch, exp, qty FROM stock WHERE branch_id = ? AND qty > 0', [$branchId])->fetchAll();
    $ids = array_values(array_unique(array_merge(array_column($sales, 'p'), array_column($stock, 'p'))));
    $products = $ids ? q('SELECT id, name, generic, schedule, pack, cold FROM products WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll() : [];
    // Other shops of the same business: where surplus stock could be moved before it expires
    $siblings = [];
    foreach (q('SELECT id, name, area, lat, lng FROM branches WHERE org_id = ? AND id <> ?', [$b['org_id'], $branchId])->fetchAll() as $o) {
        $o['sales'] = q("SELECT product_id p, -SUM(delta) n FROM stock_moves WHERE branch_id = ? AND reason = 'sale' AND created_at >= CURDATE() - INTERVAL 28 DAY GROUP BY product_id", [$o['id']])->fetchAll();
        $siblings[] = $o;
    }
    unset($b['org_id']);
    return ['branch' => $b, 'days' => $days, 'today' => date('Y-m-d'), 'products' => $products,
            'sales' => array_map(fn($r) => ['p' => (int) $r['p'], 'd' => $r['d'], 'n' => (int) $r['n']], $sales),
            'stock' => array_map(fn($r) => ['p' => (int) $r['p'], 'batch' => $r['batch'], 'exp' => $r['exp'], 'qty' => (int) $r['qty']], $stock), 'siblings' => $siblings];
}

/* Replaces the branch's open analytics suggestions with new ones. Returns how many were saved. */
function analytics_save(int $branchId, array $items): int
{
    $org = (int) q('SELECT org_id FROM branches WHERE id = ?', [$branchId])->fetchColumn();
    $kinds = ['reorder', 'expiry', 'transfer', 'demand', 'route'];
    db()->beginTransaction();
    q("DELETE FROM insights WHERE branch_id = ? AND status = 'open' AND kind IN ('reorder','expiry','transfer','demand','route')", [$branchId]);
    $n = 0;
    foreach (array_slice($items, 0, 300) as $it) {
        if (!is_array($it) || !in_array($it['kind'] ?? '', $kinds, true) || trim((string) ($it['title'] ?? '')) === '') continue;
        $pid = (int) ($it['product_id'] ?? 0) ?: null;
        if ($pid && !q('SELECT 1 FROM products WHERE id = ? AND org_id = ?', [$pid, $org])->fetch()) $pid = null;
        q('INSERT INTO insights (branch_id, kind, product_id, title, data) VALUES (?,?,?,?,?)', [$branchId, $it['kind'], $pid, mb_substr((string) $it['title'], 0, 200), mb_substr(json_encode($it['data'] ?? null, JSON_UNESCAPED_UNICODE), 0, 4000)]);
        $n++;
    }
    db()->commit();
    return $n;
}

/* Nightly for every active shop: write its history to a temporary file, let Python compute, save the result. */
function analytics_run_all(string $python): array
{
    $done = 0; $failed = 0; $total = 0;
    foreach (q("SELECT b.id FROM branches b JOIN orgs o ON o.id = b.org_id WHERE b.status = 'active' AND o.status = 'approved'")->fetchAll() as $r) {
        $h = analytics_history((int) $r['id']);
        if (!$h['sales'] && !$h['stock']) continue;
        $file = tempnam(sys_get_temp_dir(), 'abph');
        file_put_contents($file, json_encode($h));
        $cmd = 'cd ' . escapeshellarg(PH_DIR . '/../analytics') . ' && ' . escapeshellcmd($python) . ' -m abs_analytics.run --file ' . escapeshellarg($file) . ' --dry-run 2>/dev/null';
        $items = json_decode((string) shell_exec($cmd), true);
        unlink($file);
        if (!is_array($items)) { $failed++; continue; }
        $total += analytics_save((int) $r['id'], $items);
        $done++;
    }
    return ['shops' => $done, 'failed' => $failed, 'suggestions' => $total];
}
