<?php
/* Indian drug-sale rules the platform enforces (Drugs and Cosmetics Rules, 1945, and the Health Ministry's
   doorstep-delivery notification of March 2020, G.S.R. 220(E)). This is guidance built into the software;
   each pharmacy remains responsible for its licence conditions.

   - Home delivery: only by a retail licensee (Form 20/21), only to customers in the same revenue district.
   - Schedule H: a prescription is needed (presented within 30 days of issue for long-term treatment, 7 days for short illness).
   - Schedule H1, Schedule X and narcotic / psychotropic (NDPS) medicines: never by delivery or remote order; at the counter only,
     with the prescription and the H1 register.
   - Moving stock to another business is a wholesale sale (needs Form 20B/21B). Branches of the same business may transfer.
   - Price may not exceed the MRP printed on the pack. */
declare(strict_types=1);

const SCHEDULES = ['OTC', 'G', 'H', 'H1', 'X', 'NDPS'];
const COUNTER_ONLY = ['H1', 'X', 'NDPS'];

/* Common ingredients by schedule (a starting list; pharmacies can correct any product). Lower case, matched as words. */
const SCHEDULE_HINTS = [
    'NDPS' => ['morphine', 'codeine', 'fentanyl', 'pethidine', 'buprenorphine', 'pentazocine', 'methadone'],
    'X'    => ['methylphenidate', 'amphetamine', 'secobarbital', 'pentobarbital', 'ketamine'],
    'H1'   => ['alprazolam', 'clonazepam', 'diazepam', 'lorazepam', 'nitrazepam', 'zolpidem', 'tramadol', 'tapentadol', 'midazolam',
               'cefixime', 'cefpodoxime', 'ceftriaxone', 'cefoperazone', 'cefepime', 'meropenem', 'imipenem', 'ertapenem', 'faropenem',
               'levofloxacin', 'moxifloxacin', 'gemifloxacin', 'linezolid', 'isoniazid', 'rifampicin', 'pyrazinamide', 'ethambutol', 'bedaquiline',
               'clofazimine', 'colistin', 'tigecycline', 'polymyxin', 'chlordiazepoxide', 'dextropropoxyphene', 'thiacetazone', 'zopiclone'],
    'H'    => ['amoxicillin', 'azithromycin', 'ciprofloxacin', 'ofloxacin', 'norfloxacin', 'doxycycline', 'metformin', 'glimepiride', 'gliclazide',
               'sitagliptin', 'vildagliptin', 'insulin', 'amlodipine', 'telmisartan', 'losartan', 'atenolol', 'metoprolol', 'atorvastatin', 'rosuvastatin',
               'clopidogrel', 'warfarin', 'levothyroxine', 'thyroxine', 'prednisolone', 'dexamethasone', 'methylprednisolone', 'omeprazole', 'pantoprazole',
               'rabeprazole', 'esomeprazole', 'diclofenac', 'aceclofenac', 'ibuprofen', 'naproxen', 'etoricoxib', 'sertraline', 'escitalopram', 'fluoxetine',
               'amitriptyline', 'gabapentin', 'pregabalin', 'montelukast', 'salbutamol', 'budesonide', 'furosemide', 'spironolactone', 'hydrochlorothiazide',
               'metronidazole', 'albendazole', 'fluconazole', 'acyclovir', 'ondansetron', 'domperidone', 'ranitidine', 'famotidine', 'cetirizine', 'levocetirizine'],
    'OTC'  => ['paracetamol', 'ors', 'oral rehydration', 'calcium', 'vitamin', 'zinc', 'antacid', 'povidone', 'menthol', 'iron folic'],
];

function schedule_guess(string $nameOrGeneric): string
{
    $t = ' ' . strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $nameOrGeneric)) . ' ';
    foreach (['NDPS', 'X', 'H1', 'H', 'OTC'] as $s) foreach (SCHEDULE_HINTS[$s] as $w) if (str_contains($t, ' ' . $w)) return $s;
    return 'H';   // unknown medicine: treat as prescription-only to be safe
}

/* Can these items be sold this way? Returns ['ok' => bool, 'rx' => bool, 'blocked' => [names], 'reasons' => [...]]. */
function sale_rules(array $items, string $kind, ?string $customerDistrict, string $branchDistrict, string $source = 'app'): array
{
    $rx = false; $blocked = []; $reasons = [];
    $remote = $kind !== 'pickup' || in_array($source, ['app', 'sms', 'missedcall', 'asha', 'phone', 'refill'], true);
    foreach ($items as $it) {
        $s = $it['schedule'] ?? 'H';
        if (in_array($s, ['H', 'H1', 'X', 'NDPS'], true)) $rx = true;
        if (in_array($s, COUNTER_ONLY, true) && $kind !== 'pickup') { $blocked[] = $it['name']; }
    }
    if ($blocked) $reasons[] = 'Schedule H1, X and narcotic medicines cannot be delivered. Buy them at the counter with the prescription: ' . implode(', ', $blocked) . '.';
    if ($kind !== 'pickup' && $customerDistrict !== null && $customerDistrict !== '' && norm_district($customerDistrict) !== norm_district($branchDistrict))
        $reasons[] = "Home delivery is allowed only inside the shop's district ($branchDistrict). Choose a pharmacy in your district or pick up at the counter.";
    $counterOnlyRemote = $remote && $kind === 'pickup' && array_filter($items, fn($i) => in_array($i['schedule'] ?? '', COUNTER_ONLY, true));
    return ['ok' => !$blocked && count($reasons) === 0, 'rx' => $rx, 'blocked' => $blocked, 'reasons' => $reasons,
            'counterCheck' => (bool) $counterOnlyRemote];   // pickup orders for H1/X: the shop must see the original prescription at the counter
}

function norm_district(string $d): string { return strtolower(preg_replace('/[^a-z]/i', '', str_ireplace(['district', 'dist.', 'rural', 'urban'], '', $d))); }

/* A prescription is valid for this sale: chronic within 30 days of issue, acute within 7. */
function rx_valid(?string $issued, bool $chronic): bool
{
    if (!$issued || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issued) || $issued > date('Y-m-d')) return false;
    return $issued >= date('Y-m-d', strtotime('-' . ($chronic ? 30 : 7) . ' day'));
}

/* Stock may move to another business only with a wholesale licence on the sending side. */
function transfer_allowed(array $from, array $to): array
{
    if ((int) $from['org_id'] === (int) $to['org_id']) return ['ok' => true, 'how' => 'branch'];
    if (!empty($from['licence_whole'])) return ['ok' => true, 'how' => 'wholesale'];
    return ['ok' => false, 'how' => 'refer', 'why' => 'Moving stock to another business needs a wholesale licence (Form 20B/21B). Pass the order to that pharmacy instead: it sells and delivers to the customer, and you receive the referral share.'];
}

function price_ok(?float $price, ?float $mrp): bool { return $price === null || $mrp === null || $price <= $mrp + 0.001; }
