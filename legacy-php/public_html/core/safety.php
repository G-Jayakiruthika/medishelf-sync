<?php
/* Automatic medicine safety checks for each person in the family:
   - the same medicine in two brands (double dose), two medicines of the same kind,
   - well-known risky combinations, medicines that must be taken hours apart,
   - a medicine that matches the person's recorded allergy.
   Conservative on purpose: every alert says to check with a doctor or pharmacist. Messages are codes
   with parameters, translated in the app (i18n-autopilot.js). */
declare(strict_types=1);

const MED_CLASSES = [
    'nsaid' => ['Ibuprofen', 'Aceclofenac', 'Diclofenac', 'Etoricoxib', 'Nimesulide', 'Aspirin'],
    'antihistamine' => ['Cetirizine', 'Levocetirizine', 'Fexofenadine', 'Chlorpheniramine'],
    'ppi' => ['Pantoprazole', 'Omeprazole', 'Rabeprazole'],
    'h2' => ['Famotidine', 'Ranitidine'],
    'penicillin' => ['Amoxicillin', 'Amoxicillin + Clavulanate'],
    'cephalosporin' => ['Cefixime', 'Cefpodoxime', 'Cefuroxime', 'Cephalexin'],
    'quinolone' => ['Ciprofloxacin', 'Ofloxacin', 'Levofloxacin', 'Norfloxacin'],
    'sulfonylurea' => ['Glimepiride', 'Gliclazide'],
    'gliptin' => ['Vildagliptin', 'Sitagliptin'],
    'sglt2' => ['Dapagliflozin', 'Empagliflozin'],
    'bp_raas' => ['Telmisartan', 'Losartan', 'Ramipril'],
    'betablocker' => ['Atenolol', 'Metoprolol'],
    'statin' => ['Atorvastatin', 'Rosuvastatin'],
    'benzo' => ['Alprazolam', 'Clonazepam'],
    'minerals' => ['Antacid', 'Calcium + Vitamin D3', 'Iron + Folic acid'],
];
const SAME_KIND_CLASSES = ['nsaid', 'antihistamine', 'ppi', 'h2', 'penicillin', 'cephalosporin', 'quinolone', 'sulfonylurea', 'gliptin', 'sglt2', 'bp_raas', 'betablocker', 'statin', 'benzo'];
/* [A (class or ingredient), B, level, reason code] */
const MED_PAIRS = [
    ['nsaid', 'Clopidogrel', 'high', 'bleed'],
    ['nsaid', 'bp_raas', 'medium', 'kidney'],
    ['Clopidogrel', 'Omeprazole', 'medium', 'clopi'],
    ['Tramadol', 'benzo', 'high', 'breathing'],
    ['Tramadol', 'Escitalopram', 'high', 'serotonin'],
    ['benzo', 'Chlorpheniramine', 'medium', 'drowsy'],
];
/* Take apart: [A, B, hours] — only flagged when both are due at the same time of day. */
const MED_GAPS = [['quinolone', 'minerals', 2], ['Doxycycline', 'minerals', 2], ['Levothyroxine', 'minerals', 4]];
const ALLERGY_WORDS = [
    'penicillin' => 'penicillin', 'amoxicillin' => 'penicillin', 'augmentin' => 'penicillin',
    'cephalosporin' => 'cephalosporin', 'nsaid' => 'nsaid', 'aspirin' => 'nsaid', 'ibuprofen' => 'nsaid', 'painkiller' => 'nsaid', 'diclofenac' => 'nsaid',
    'quinolone' => 'quinolone', 'cipro' => 'quinolone', 'sulfa' => null,
];

function med_class_of(string $ingredient): ?string
{
    foreach (MED_CLASSES as $c => $list) if (in_array($ingredient, $list, true)) return $c;
    return null;
}
function med_matches(string $ingredient, string $spec): bool
{
    return $ingredient === $spec || (isset(MED_CLASSES[$spec]) && in_array($ingredient, MED_CLASSES[$spec], true));
}

function safety_alerts(int $hid): array
{
    $today = date('Y-m-d');
    $items = q("SELECT c.id, c.name, c.member_id, c.dose_times, c.exp_month, c.course_end, i.ingredient
                FROM cabinet_items c LEFT JOIN medicine_info i ON i.id = c.info_id WHERE c.user_id = ?", [$hid])->fetchAll();
    $members = [];
    foreach (q('SELECT id, name, allergies FROM members WHERE user_id = ?', [$hid])->fetchAll() as $m) $members[(int) $m['id']] = $m;
    $byPerson = [];
    foreach ($items as $i) if ($i['ingredient']) $byPerson[(int) ($i['member_id'] ?? 0)][] = $i;
    $alerts = []; $seen = [];
    $push = function (array $a) use (&$alerts, &$seen) { $k = $a['code'] . ':' . implode(',', $a['items']); if (!isset($seen[$k])) { $seen[$k] = 1; $alerts[] = $a; } };
    $inUse = fn($i) => $i['dose_times'] !== '' && ($i['course_end'] === null || $i['course_end'] >= $today) && ($i['exp_month'] === null || date('Y-m-t', strtotime($i['exp_month'])) >= $today);
    foreach ($byPerson as $pid => $list) {
        $who = $pid && isset($members[$pid]) ? $members[$pid]['name'] : null;
        // Allergy (any medicine of theirs, in use or not)
        $allergy = mb_strtolower((string) ($pid && isset($members[$pid]) ? $members[$pid]['allergies'] : ''));
        if ($allergy !== '') foreach ($list as $i) {
            $ing = $i['ingredient']; $hit = null;
            if (str_contains($allergy, mb_strtolower(explode(' + ', $ing)[0]))) $hit = $ing;
            foreach (ALLERGY_WORDS as $w => $cls) if ($cls && str_contains($allergy, $w) && med_matches($ing, $cls)) $hit = $w;
            if ($hit) $push(['level' => 'high', 'code' => 'allergy', 'memberId' => $pid ?: null, 'items' => [(int) $i['id']], 'p' => ['who' => $who ?? '', 'allergy' => $hit, 'a' => $i['name'], 'ing' => $ing]]);
        }
        $use = array_values(array_filter($list, $inUse));
        for ($x = 0; $x < count($use); $x++) for ($y = $x + 1; $y < count($use); $y++) {
            $a = $use[$x]; $b = $use[$y]; $ia = $a['ingredient']; $ib = $b['ingredient'];
            $ids = [(int) $a['id'], (int) $b['id']];
            $base = ['memberId' => $pid ?: null, 'items' => $ids, 'p' => ['who' => $who ?? '', 'a' => $a['name'], 'b' => $b['name'], 'ing' => $ia]];
            if ($ia === $ib) { $push(['level' => 'high', 'code' => 'same'] + $base); continue; }
            $ca = med_class_of($ia); $cb = med_class_of($ib);
            if ($ca && $ca === $cb && in_array($ca, SAME_KIND_CLASSES, true)) $push(['level' => 'medium', 'code' => 'kind', 'p' => $base['p'] + ['cls' => $ca]] + $base);
            foreach (MED_PAIRS as [$pa, $pb, $lvl, $why])
                if ((med_matches($ia, $pa) && med_matches($ib, $pb)) || (med_matches($ia, $pb) && med_matches($ib, $pa))) $push(['level' => $lvl, 'code' => 'pair', 'p' => $base['p'] + ['why' => $why]] + $base);
            $shared = array_values(array_intersect(explode(',', $a['dose_times']), explode(',', $b['dose_times'])));
            if ($shared) foreach (MED_GAPS as [$ga, $gb, $h])
                if ((med_matches($ia, $ga) && med_matches($ib, $gb)) || (med_matches($ia, $gb) && med_matches($ib, $ga))) $push(['level' => 'low', 'code' => 'gap', 'p' => $base['p'] + ['h' => $h, 'slot' => $shared[0]]] + $base);
        }
    }
    usort($alerts, fn($p, $q) => array_search($p['level'], ['high', 'medium', 'low']) <=> array_search($q['level'], ['high', 'medium', 'low']));
    return $alerts;
}
