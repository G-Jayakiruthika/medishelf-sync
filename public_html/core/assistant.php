<?php
/* The in-app assistant's server side. Without AI (or without the person's consent) it answers from the
   medicine information in the database. With AI and consent, it answers questions about the family's
   own cabinet in their language, inside strict medical guardrails. */
declare(strict_types=1);
require_once __DIR__ . '/ai.php';

const LANG_NAMES = ['en' => 'English', 'hi' => 'Hindi', 'bn' => 'Bengali', 'mr' => 'Marathi', 'te' => 'Telugu', 'ta' => 'Tamil', 'gu' => 'Gujarati', 'kn' => 'Kannada', 'ml' => 'Malayalam', 'pa' => 'Punjabi'];

function assistant_context(int $hid): array
{
    $members = array_column(q('SELECT id, name FROM members WHERE user_id = ?', [$hid])->fetchAll(), 'name', 'id');
    $rows = q('SELECT c.name, c.member_id, c.dose_times, c.exp_month, c.stock_count, c.per_dose, c.course_end, c.notes, i.ingredient, i.use_text
               FROM cabinet_items c LEFT JOIN medicine_info i ON i.id = c.info_id WHERE c.user_id = ? ORDER BY c.name LIMIT 60', [$hid])->fetchAll();
    $slot = ['m' => 'morning', 'a' => 'afternoon', 'e' => 'evening', 'b' => 'bedtime'];
    return array_map(fn($r) => array_filter([
        'medicine' => $r['name'], 'for' => $r['member_id'] && isset($members[$r['member_id']]) ? $members[$r['member_id']] : 'family',
        'contains' => $r['ingredient'], 'usedFor' => $r['use_text'],
        'when' => $r['dose_times'] ? implode(', ', array_map(fn($s) => $slot[$s] ?? $s, explode(',', $r['dose_times']))) : 'only when needed',
        'tabletsPerDose' => (int) $r['per_dose'] > 1 ? (int) $r['per_dose'] : null, 'tabletsLeft' => $r['stock_count'],
        'expires' => $r['exp_month'] ? substr($r['exp_month'], 0, 7) . ($r['exp_month'] < date('Y-m-01') ? ' (EXPIRED)' : '') : null,
        'courseEnds' => $r['course_end'], 'notes' => $r['notes'] ?: null,
    ], fn($v) => $v !== null && $v !== ''), $rows);
}

/* Answer from the database alone: "what is X for", "what does X contain". */
function assistant_rules(string $qText, int $hid): ?string
{
    $ql = ' ' . mb_strtolower($qText) . ' ';
    foreach (q('SELECT c.name, i.ingredient, i.use_text, i.storage, i.note FROM cabinet_items c JOIN medicine_info i ON i.id = c.info_id WHERE c.user_id = ?', [$hid])->fetchAll() as $r) {
        $first = mb_strtolower(preg_split('/[\s(]+/', $r['name'])[0]);
        if (mb_strlen($first) >= 4 && str_contains($ql, $first))
            return "{$r['name']} contains {$r['ingredient']}. It is used for: {$r['use_text']}." . ($r['storage'] ? " Storage: {$r['storage']}." : '') . ' Ask your doctor or pharmacist before changing how you take it.';
    }
    foreach (q('SELECT ingredient, keywords, use_text, storage FROM medicine_info')->fetchAll() as $r) {
        $words = array_filter(array_map('trim', explode(',', mb_strtolower($r['keywords'] . ',' . $r['ingredient']))), fn($w) => mb_strlen($w) >= 4);
        foreach ($words as $w) if (str_contains($ql, ' ' . $w)) return "{$r['ingredient']} is used for: {$r['use_text']}." . ($r['storage'] ? " Storage: {$r['storage']}." : '') . ' Ask your doctor or pharmacist about doses.';
    }
    return null;
}

function assistant_answer(array $user, int $hid, string $question): array
{
    $question = trim(mb_substr($question, 0, 400));
    if ($question === '') return ['answer' => '', 'source' => 'none'];
    if (!empty($user['ai_opt_in']) && ai_enabled()) {
        $lang = LANG_NAMES[$user['language'] ?? 'en'] ?? 'English';
        $ans = ai_text('assistant',
            "You are the helper inside AushadhBox, a family medicine cabinet app in India. Answer in $lang, in at most 4 short sentences, for an older person reading on a phone.\n" .
            "Use ONLY the family's cabinet data given and well-established general facts about what a medicine is for.\n" .
            "Never tell anyone to start, stop or change a medicine or a dose, and never give a dose that is not already recorded in the cabinet. Never diagnose.\n" .
            "For symptoms, side effects, pregnancy, children or combining medicines: say to ask their doctor or pharmacist. For emergencies (overdose, chest pain, trouble breathing, a child swallowed tablets): say to call 112 or go to hospital now.\n" .
            "If the answer is not in the data, say so plainly.\n" . AI_UNTRUSTED_RULE,
            "Today: " . date('j M Y') . "\nFamily cabinet: " . json_encode(assistant_context($hid), JSON_UNESCAPED_UNICODE) . "\n\n" . ai_untrusted('family question', $question), 350);
        if ($ans) return ['answer' => $ans, 'source' => 'ai'];
    }
    $r = assistant_rules($question, $hid);
    return $r ? ['answer' => $r, 'source' => 'info'] : ['answer' => '', 'source' => 'none'];
}
