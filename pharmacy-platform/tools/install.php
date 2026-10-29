<?php
/* Creates the tables and the first network admin. Run once from the command line:
     php tools/install.php admin@example.in "Admin Name" 'a-strong-password'          (production)
     php tools/install.php admin@example.in "Admin Name" 'password' --demo             (adds a demo network around Erode and Coimbatore)
   Safe to run again: tables use IF NOT EXISTS, the admin is updated. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/stock.php';
require_once dirname(__DIR__) . '/core/orders.php';

[$email, $name, $pass] = [$argv[1] ?? '', $argv[2] ?? 'Network admin', $argv[3] ?? ''];
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 10) { fwrite(STDERR, "Usage: php tools/install.php EMAIL \"NAME\" PASSWORD(10+ chars) [--demo]\n"); exit(1); }
$sql = file_get_contents(dirname(__DIR__) . '/core/database/schema.sql');
foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) db()->exec($stmt);
q('INSERT INTO admins (name, email, password_hash) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash)', [$name, strtolower($email), password_hash($pass, PASSWORD_DEFAULT)]);
echo "Tables ready. Admin: $email (two-factor sign-in is set up at the first sign-in at /admin).\n";
if (!in_array('--demo', $argv, true)) exit(0);

/* ---------- Demo network: a city chain, a semi-urban business with two shops, a village pharmacy and a Jan Aushadhi Kendra ---------- */
if (q("SELECT 1 FROM branches WHERE code = 'SRSA2K7Q'")->fetch()) { echo "Demo data already there.\n"; exit(0); }
$pw = password_hash('demo1234', PASSWORD_DEFAULT);
$orgs = [
  ['Sri Sai Medicals', '33AABCS1234F1Z5', [['SRSA2K7Q', 'Sri Sai Medicals, Gandhipuram', 'retail', 'TN-CBE-20-4471', 'TN-CBE-20B-4471', '14 Cross Cut Road, Gandhipuram', 'Gandhipuram', 'Coimbatore', '641012', 11.0176, 76.9674, 'urban', '9840012345', 'srisai@okaxis', 'Mon-Sat 08:00-22:00; Sun 09:00-13:00']],
   'Sai Kumar', 'owner@srisai.test'],
  ['Amman Pharmacy', null, [['AMPR4N9X', 'Amman Pharmacy, Perundurai', 'retail', 'TN-ERD-20-1188', null, 'Bus stand road', 'Perundurai', 'Erode', '638052', 11.2756, 77.5875, 'semiurban', '9842211188', '9842211188@upi', 'Mon-Sun 08:30-21:30'],
                                   ['AMCH7T3W', 'Amman Pharmacy, Chennimalai', 'retail', 'TN-ERD-20-1190', null, 'Temple street', 'Chennimalai', 'Erode', '638051', 11.1640, 77.6040, 'semiurban', '9842211190', null, 'Mon-Sat 09:00-21:00']],
   'Meena Ramasamy', 'owner@amman.test'],
  ['Arogya Medicals', null, [['ARKV8M2P', 'Arogya Medicals, Kavindapadi', 'retail', 'TN-ERD-20-2207', null, 'Main road, near panchayat office', 'Kavindapadi', 'Erode', '638455', 11.4262, 77.5540, 'rural', '9790022207', null, 'Mon-Sat 08:00-20:00']],
   'Velu K', 'owner@arogya.test'],
  ['Jan Aushadhi Kendra Erode', null, [['JAER5C6V', 'Pradhan Mantri Bhartiya Janaushadhi Kendra, Erode', 'janaushadhi', 'TN-ERD-20-3301', null, 'Brough Road', 'Erode', 'Erode', '638001', 11.3410, 77.7172, 'urban', '9443033301', 'janaushadhi.erode@sbi', 'Mon-Sat 09:00-21:00']],
   'R. Lakshmi', 'owner@jak.test'],
];
$products = [   // name, generic, schedule, pack, mrp, cold
  ['Dolo 650', 'Paracetamol 650 mg', 'OTC', '15 tablets', 33.6, 0], ['Crocin Advance 500', 'Paracetamol 500 mg', 'OTC', '20 tablets', 30.2, 0],
  ['Glycomet 500 SR', 'Metformin 500 mg', 'H', '10 tablets', 28.0, 0], ['Telma 40', 'Telmisartan 40 mg', 'H', '15 tablets', 236.0, 0],
  ['Amlong 5', 'Amlodipine 5 mg', 'H', '15 tablets', 98.0, 0], ['Atorva 10', 'Atorvastatin 10 mg', 'H', '15 tablets', 128.0, 0],
  ['Pan 40', 'Pantoprazole 40 mg', 'H', '15 tablets', 155.0, 0], ['Azithral 500', 'Azithromycin 500 mg', 'H', '5 tablets', 119.5, 0],
  ['Augmentin 625 Duo', 'Amoxicillin + Clavulanic acid', 'H', '10 tablets', 223.0, 0], ['Taxim-O 200', 'Cefixime 200 mg', 'H1', '10 tablets', 109.0, 0],
  ['Alprax 0.25', 'Alprazolam 0.25 mg', 'H1', '15 tablets', 34.0, 0], ['Thyronorm 50', 'Levothyroxine 50 mcg', 'H', '120 tablets', 175.0, 0],
  ['Human Mixtard 30/70', 'Insulin human 30/70', 'H', '10 ml vial', 162.0, 1], ['Lantus SoloStar', 'Insulin glargine', 'H', '3 ml pen', 785.0, 1],
  ['Asthalin Inhaler', 'Salbutamol 100 mcg', 'H', '200 doses', 154.0, 0], ['Levipil 500', 'Levetiracetam 500 mg', 'H', '10 tablets', 182.0, 0],
  ['Electral ORS', 'Oral rehydration salts', 'OTC', '21.8 g sachet', 22.0, 0], ['Shelcal 500', 'Calcium + Vitamin D3', 'OTC', '15 tablets', 128.0, 0],
  ['Ecosprin 75', 'Aspirin 75 mg', 'H', '14 tablets', 5.4, 0], ['Clopilet 75', 'Clopidogrel 75 mg', 'H', '15 tablets', 96.0, 0],
  ['Metformin 500 (Jan Aushadhi)', 'Metformin 500 mg', 'H', '10 tablets', 6.5, 0], ['Paracetamol 650 (Jan Aushadhi)', 'Paracetamol 650 mg', 'OTC', '10 tablets', 9.0, 0],
];
$branchIds = [];
foreach ($orgs as [$oname, $gst, $branches, $owner, $oemail]) {
    q("INSERT INTO orgs (name, gstin, status, approved_at) VALUES (?,?, 'approved', NOW())", [$oname, $gst]); $org = (int) db()->lastInsertId();
    foreach ($branches as $i => [$code, $bn, $kind, $lic, $whole, $addr, $village, $district, $pin, $lat, $lng, $area, $phone, $upi, $hours]) {
        q('INSERT INTO branches (org_id, code, name, kind, licence_retail, licence_whole, address, village, district, pincode, lat, lng, area, phone, upi_vpa, upi_name, hours, pharmacist, radius_km, max_km)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$org, $code, $bn, $kind, $lic, $whole, $addr, $village, $district, $pin, $lat, $lng, $area, $phone, $upi, $upi ? $oname : null, $hours,
           ['R. Kannan B.Pharm', 'S. Priya D.Pharm', 'M. Arun D.Pharm', 'K. Devi B.Pharm', 'P. Raj D.Pharm'][count($branchIds) % 5], $area === 'rural' ? 8 : 5, $area === 'rural' ? 35 : 20]);
        $bid = (int) db()->lastInsertId(); $branchIds[$code] = $bid;
        foreach ($products as $k => [$pn, $gen, $sch, $pack, $mrp, $cold]) {
            if ($kind === 'janaushadhi' && !str_contains($pn, 'Jan Aushadhi') && $k % 3) continue;
            if ($kind !== 'janaushadhi' && str_contains($pn, 'Jan Aushadhi')) continue;
            if ($area === 'rural' && in_array($pn, ['Lantus SoloStar', 'Levipil 500', 'Taxim-O 200'], true)) continue;   // the village shop doesn't keep these
            if ($code === 'AMCH7T3W' && in_array($pn, ['Human Mixtard 30/70', 'Asthalin Inhaler'], true)) continue;
            $pid = product_upsert($org, ['name' => $pn, 'generic' => $gen, 'schedule' => $sch, 'pack' => $pack, 'mrp' => $mrp, 'cold' => $cold]);
            $qty = [24, 40, 12, 6, 60, 3][($k + $bid) % 6];
            stock_move($bid, $pid, 'B' . strtoupper(substr(md5($pn . $bid), 0, 5)), $qty, 'purchase', 'demo', null, date('Y-m-t', strtotime('+' . (2 + ($k * 7 + $bid) % 26) . ' month')), round($mrp * 0.95, 2));
            if ($k % 7 === 0) stock_move($bid, $pid, 'X' . strtoupper(substr(md5($pn), 0, 4)), 4, 'purchase', 'demo', null, date('Y-m-t', strtotime('+1 month')), round($mrp * 0.9, 2));   // expiring soon
        }
    }
    q('INSERT INTO staff (org_id, name, email, phone, password_hash, role) VALUES (?,?,?,?,?,?)', [$org, $owner, $oemail, null, $pw, 'owner']);
}
$amman = (int) q("SELECT org_id FROM branches WHERE code = 'AMPR4N9X'")->fetchColumn();
q('INSERT INTO staff (org_id, branch_id, name, phone, password_hash, role) VALUES (?,?,?,?,?,?)', [$amman, $branchIds['AMPR4N9X'], 'Karthik (delivery)', '9876500011', $pw, 'rider']);
q('INSERT INTO staff (org_id, branch_id, name, phone, password_hash, role) VALUES (?,?,?,?,?,?)', [$amman, $branchIds['AMPR4N9X'], 'Divya (counter)', '9876500012', $pw, 'cashier']);
// Links: the village shop and the Perundurai shop help each other; Perundurai also links with the Jan Aushadhi Kendra.
q("INSERT INTO links (from_branch, to_branch, status, share_stock, referral_pct, responded_at) VALUES (?,?, 'active', 1, 2.5, NOW())", [$branchIds['ARKV8M2P'], $branchIds['AMPR4N9X']]);
q("INSERT INTO links (from_branch, to_branch, status, share_stock, referral_pct, responded_at) VALUES (?,?, 'active', 1, 2.0, NOW())", [$branchIds['AMPR4N9X'], $branchIds['JAER5C6V']]);
q("INSERT INTO links (from_branch, to_branch, status, share_stock, referral_pct) VALUES (?,?, 'pending', 1, 2.0)", [$branchIds['SRSA2K7Q'], $branchIds['AMPR4N9X']]);
// Village pickup points for the rural shop's rounds
foreach ([['Selvi (ASHA worker)', 'asha', 'Odathurai', 'Selvi', '9786501234', 11.4500, 77.5290, 'Tue,Fri'], ['Murugan Stores', 'shop', 'Nallagoundenpalayam', 'Murugan', '9786505678', 11.4050, 77.5110, 'Wed,Sat'],
          ['Panchayat office', 'panchayat', 'Kavindapadi', null, null, 11.4262, 77.5545, 'Mon-Sat']] as $p)
    q('INSERT INTO pickup_points (branch_id, name, kind, village, contact, phone, lat, lng, days) VALUES (?,?,?,?,?,?,?,?,?)', array_merge([$branchIds['ARKV8M2P']], $p));
// Walk-in customers with a khata, points and a referral
$c1 = customer_upsert(['name' => 'Palaniswamy', 'phone' => '9876543210', 'village' => 'Odathurai', 'district' => 'Erode', 'lat' => 11.4492, 'lng' => 77.5301, 'sms_ok' => 1, 'address' => '3/45 Mariamman Kovil Street, Odathurai']);
$c2 = customer_upsert(['name' => 'Saraswathi', 'phone' => '9876543211', 'village' => 'Kavindapadi', 'district' => 'Erode', 'lat' => 11.4290, 'lng' => 77.5571, 'sms_ok' => 1]);
$arogya = branch($branchIds['ARKV8M2P']);
khata_credit($arogya, $c1, 640.0, null, null, 'Monthly BP and sugar tablets');
khata_credit($arogya, $c2, 210.0, null, null, 'Fever medicines for grandson');
q("INSERT INTO points (org_id, customer_id, delta, reason) VALUES (?,?,?, 'bonus')", [$arogya['org_id'], $c1, 25]);
referral_join((int) $arogya['org_id'], $c2, (string) q('SELECT ref_code FROM customers WHERE id = ?', [$c1])->fetchColumn());
// A fridge thermometer that has been reporting
q("INSERT INTO devices (branch_id, name, token_hash, min_c, max_c, last_c, battery, last_seen) VALUES (?, 'Insulin fridge', ?, 2, 8, 5.4, 88, NOW())", [$branchIds['AMPR4N9X'], hash('sha256', 'demo-device')]);
$dev = (int) db()->lastInsertId();
for ($m = 24 * 60; $m >= 0; $m -= 30) q('INSERT INTO readings (device_id, temp_c, at) VALUES (?,?, NOW() - INTERVAL ? MINUTE)', [$dev, round(4.8 + sin($m / 180) * 1.1 + ($m > 600 && $m < 660 ? 3.9 : 0), 1), $m]);
echo "Demo network added: 4 businesses, 5 shops. Owners sign in with owner@srisai.test, owner@amman.test, owner@arogya.test, owner@jak.test (password demo1234).\n";
echo "Rider: phone 9876500011, cashier: 9876500012 (password demo1234). Shop codes: " . implode(', ', array_keys($branchIds)) . "\n";
