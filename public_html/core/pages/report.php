<?php
/* Printable medicine report for the doctor (Family Premium). Opened from the app: /report */
require_once APP_DIR . '/plans.php';
header('X-Robots-Tag: noindex');
header('Cache-Control: private, no-store');
$u = current_user();
if (!$u) { header('Location: /app#signin'); return; }
if (!is_premium($u)) { header('Location: /app#upgrade'); return; }
$hid = hid($u);
$member = isset($_GET['member']) ? (int) $_GET['member'] : 0;
$members = q('SELECT id, name, relation, allergies FROM members WHERE user_id = ? ORDER BY is_self DESC, id', [$hid])->fetchAll();
$args = [$hid]; $where = 'c.user_id = ?';
if ($member) { $where .= ' AND c.member_id = ?'; $args[] = $member; }
$items = q("SELECT c.*, m.name AS member, i.ingredient, i.use_text, i.rx FROM cabinet_items c
            LEFT JOIN members m ON m.id = c.member_id LEFT JOIN medicine_info i ON i.id = c.info_id
            WHERE $where ORDER BY m.name IS NULL, m.name, c.exp_month", $args)->fetchAll();
$slotNames = ['m' => 'Morning', 'a' => 'Afternoon', 'e' => 'Evening', 'b' => 'Bedtime'];
$status = function (string $exp): array {
    $end = date('Y-m-t', strtotime($exp));
    $days = (int) floor((strtotime($end) - strtotime(date('Y-m-d'))) / 86400);
    return $days < 0 ? ['Expired', 'var(--bad)'] : ($days <= 30 ? ['Expires soon', 'var(--warn)'] : ['OK', 'var(--ok)']);
};
$taken = q('SELECT c.name, COUNT(*) n FROM dose_logs d JOIN cabinet_items c ON c.id = d.item_id WHERE d.user_id = ? AND d.taken_on >= CURDATE() - INTERVAL 29 DAY GROUP BY c.id, c.name ORDER BY n DESC LIMIT 20', [$hid])->fetchAll();
ob_start();
?>
<div class="report">
  <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <a class="btn btn-ghost btn-sm" href="/app#account">← Back to app</a>
    <form method="get" style="display:flex;gap:8px;align-items:center"><label for="rm" class="muted">Person</label>
      <select id="rm" name="member" data-autosubmit style="padding:8px 12px;border-radius:10px;border:1px solid var(--line);font:inherit">
        <option value="0">Everyone</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>"<?= $member === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?>
      </select></form>
    <button class="btn btn-primary btn-sm" type="button" data-print>Print or save as PDF</button>
  </div>
  <header style="display:grid;gap:6px;border-bottom:2px solid var(--ink);padding-bottom:12px">
    <h1 style="font-size:1.8rem">Medicine report</h1>
    <p class="muted"><?= e(site_name()) ?> · Prepared <?= date('j M Y') ?> for <?= e($u['name']) ?><?= $member ? ' · ' . e(array_column($members, 'name', 'id')[$member] ?? '') : '' ?></p>
  </header>
  <?php $people = $member ? array_filter($members, fn($m) => (int) $m['id'] === $member) : $members; $al = array_filter($people, fn($m) => trim((string) $m['allergies']) !== ''); ?>
  <?php if ($al): ?><section style="padding:0"><h2 style="font-size:1.2rem">Allergies</h2><table><tbody><?php foreach ($al as $m): ?><tr><th style="width:30%"><?= e($m['name']) ?></th><td><?= e($m['allergies']) ?></td></tr><?php endforeach; ?></tbody></table></section><?php endif; ?>
  <section style="padding:0">
    <h2 style="font-size:1.2rem">Medicines (<?= count($items) ?>)</h2>
    <?php if (!$items): ?><p class="muted">No medicines saved yet.</p><?php else: ?>
    <table>
      <thead><tr><th>Medicine</th><th>For</th><th>When</th><th>Batch</th><th>Expiry</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($items as $i): [$st, $col] = $status($i['exp_month']); ?>
        <tr><td><b><?= e($i['name']) ?></b><?= $i['ingredient'] ? '<br><small class="muted">' . e($i['ingredient']) . ($i['rx'] ? ' · Rx' : '') . '</small>' : '' ?><?= $i['notes'] ? '<br><small>' . e($i['notes']) . '</small>' : '' ?></td>
          <td><?= e($i['member'] ?? 'Family') ?></td>
          <td><?= e(implode(', ', array_map(fn($s) => $slotNames[$s] ?? $s, array_filter(explode(',', (string) $i['dose_times']))))) ?: '—' ?></td>
          <td><?= e($i['batch_no'] ?? '—') ?></td>
          <td><?= e(date('M Y', strtotime($i['exp_month']))) ?></td>
          <td><b style="color:<?= $col ?>"><?= $st ?></b></td></tr>
      <?php endforeach; ?></tbody>
    </table>
    <?php endif; ?>
  </section>
  <?php if ($taken): ?>
  <section style="padding:0"><h2 style="font-size:1.2rem">Doses ticked as taken, last 30 days</h2>
    <table><tbody><?php foreach ($taken as $t): ?><tr><td><?= e($t['name']) ?></td><td style="width:20%"><?= (int) $t['n'] ?></td></tr><?php endforeach; ?></tbody></table></section>
  <?php endif; ?>
  <p class="muted" style="font-size:.85rem">This list was entered by the family and may be incomplete. It is not a prescription. Please confirm doses with the patient.</p>
</div>
<?php
$content = ob_get_clean();
render_raw($content, ['title' => 'Medicine report · ' . site_name(), 'robots' => 'noindex, nofollow', 'body_class' => 'report']);
