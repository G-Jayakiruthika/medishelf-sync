<?php
/* API for the stock-sync agent (sync-agent/, Java) running on the shop's billing computer.
   It watches the folder where the billing software (Marg, GoFrugal, Medeil, Tally...) exports the closing stock and uploads it.
   POST sync.php?action=stock   header Authorization: Bearer abs_...   body {csv, full, file, sha256}  → {rows, skipped, zeroed}
   POST sync.php?action=ping    → {ok, branch}  (checks the token) */
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
default: fail('unknown_action', 'Unknown action.', 404);
}
