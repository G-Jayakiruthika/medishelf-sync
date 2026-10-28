<?php
/* SEO pages for the Jan Aushadhi medicine list and the ingredient guides.
   Medicines without an ingredient guide are marked noindex so Google only sees pages with real content. */

const MED_PER_PAGE = 60;

function medicines_index(): void
{
    $qText = trim((string) ($_GET['q'] ?? ''));
    $letter = strtoupper(substr((string) ($_GET['letter'] ?? ''), 0, 1));
    if ($letter !== '' && !preg_match('/^[A-Z#]$/', $letter)) $letter = '';
    $pg = max(1, (int) ($_GET['page'] ?? 1));
    $where = '1=1'; $args = [];
    if ($qText !== '') {
        $where = '(c.search_text LIKE ? OR i.ingredient LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(mb_substr($qText, 0, 60))) . '%';
        $args = [$like, $like];
    } elseif ($letter === '#') {
        $where = "c.generic_name NOT REGEXP '^[A-Za-z]'";
    } elseif ($letter !== '') {
        $where = 'c.generic_name LIKE ?'; $args = [$letter . '%'];
    }
    $total = (int) q("SELECT COUNT(*) FROM medicine_catalog c LEFT JOIN medicine_info i ON i.id = c.info_id WHERE $where", $args)->fetchColumn();
    $pages = max(1, (int) ceil($total / MED_PER_PAGE));
    $pg = min($pg, $pages);
    $rows = q("SELECT c.generic_name, c.slug, c.unit_size, c.dosage_form, i.ingredient FROM medicine_catalog c LEFT JOIN medicine_info i ON i.id = c.info_id
               WHERE $where ORDER BY c.generic_name LIMIT " . MED_PER_PAGE . ' OFFSET ' . (($pg - 1) * MED_PER_PAGE), $args)->fetchAll();
    $all = (int) q('SELECT COUNT(*) FROM medicine_catalog')->fetchColumn();

    $qs = fn(int $n) => '/medicines?' . http_build_query(array_filter(['q' => $qText, 'letter' => $letter, 'page' => $n > 1 ? $n : null]));
    $canonical = base_url() . ($qText !== '' ? '/medicines' : $qs($pg));
    render('medicines', compact('rows', 'total', 'pages', 'pg', 'qText', 'letter', 'all', 'qs'), [
        'title' => ($qText !== '' ? 'Search: ' . $qText . ' · ' : ($letter ? "Medicines starting with $letter · " : 'Jan Aushadhi medicine list (' . number_format($all) . ') · ')) . site_name(),
        'description' => 'Browse ' . number_format($all) . ' quality generic medicines from the Pradhan Mantri Bhartiya Janaushadhi Pariyojana list, with uses, storage and expiry tips.',
        'canonical' => $canonical, 'robots' => $qText !== '' ? 'noindex, follow' : 'index, follow', 'nav' => 'navMedicines',
        'jsonld' => [breadcrumbs([['Home', '/'], ['Medicines', '/medicines']])],
    ]);
}

function medicine_page(string $slug): void
{
    $m = catalog_by_slug($slug);
    if (!$m) { not_found(); return; }
    $related = [];
    if ($m['info_id']) $related = q('SELECT generic_name, slug, unit_size FROM medicine_catalog WHERE info_id = ? AND id <> ? ORDER BY generic_name LIMIT 12', [$m['info_id'], $m['id']])->fetchAll();
    $hasGuide = !empty($m['ingredient']);
    $desc = $hasGuide
        ? $m['generic_name'] . ': ' . $m['use_text'] . '. Jan Aushadhi generic' . ($m['unit_size'] ? ', pack ' . $m['unit_size'] : '') . '. How to store it and check expiry.'
        : $m['generic_name'] . ' — Jan Aushadhi generic medicine' . ($m['unit_size'] ? ', pack ' . $m['unit_size'] : '') . '. How to store it and check expiry.';
    $drug = ['@context' => 'https://schema.org', '@type' => 'Drug', 'name' => $m['generic_name'], 'url' => base_url() . '/medicines/' . $m['slug'],
             'isAvailableGenerically' => true, 'prescriptionStatus' => ($hasGuide && !$m['rx']) ? 'https://schema.org/OTC' : 'https://schema.org/PrescriptionOnly'];
    if ($hasGuide) { $drug['activeIngredient'] = $m['ingredient']; $drug['description'] = $m['use_text']; }
    if ($m['dosage_form']) $drug['dosageForm'] = $m['dosage_form'];
    render('medicine', ['m' => $m, 'related' => $related, 'hasGuide' => $hasGuide], [
        'title' => seo_title($m['generic_name'], $hasGuide ? ' — uses, storage & expiry' : ''),
        'description' => mb_substr($desc, 0, 300), 'canonical' => base_url() . '/medicines/' . $m['slug'], 'og_type' => 'article',
        'robots' => $hasGuide ? 'index, follow' : 'noindex, follow', 'nav' => 'navMedicines',
        'jsonld' => [$drug, breadcrumbs([['Home', '/'], ['Medicines', '/medicines'], [$m['generic_name'], '/medicines/' . $m['slug']]])],
    ]);
}

function ingredients_index(): void
{
    $rows = q('SELECT i.ingredient, i.slug, i.use_text, i.rx, COUNT(c.id) AS n FROM medicine_info i LEFT JOIN medicine_catalog c ON c.info_id = i.id GROUP BY i.id, i.ingredient, i.slug, i.use_text, i.rx ORDER BY i.ingredient')->fetchAll();
    render('ingredients', ['rows' => $rows], [
        'title' => 'Medicine ingredient guides (' . count($rows) . ') · ' . site_name(),
        'description' => 'Plain-language guides to ' . count($rows) . ' common medicine ingredients in India: what they are used for, how to store them, and safety notes.',
        'canonical' => base_url() . '/ingredients', 'nav' => 'navMedicines',
        'jsonld' => [breadcrumbs([['Home', '/'], ['Ingredient guides', '/ingredients']])],
    ]);
}

function ingredient_page(string $slug): void
{
    $i = info_by_slug($slug);
    if (!$i) { not_found(); return; }
    $meds = q('SELECT generic_name, slug, unit_size FROM medicine_catalog WHERE info_id = ? ORDER BY generic_name LIMIT 200', [$i['id']])->fetchAll();
    $others = q('SELECT ingredient, slug FROM medicine_info WHERE id <> ? ORDER BY RAND() LIMIT 8', [$i['id']])->fetchAll();
    $faq = [
        ['What is ' . $i['ingredient'] . ' used for?', $i['use_text'] . '.'],
        ['Do I need a prescription for ' . $i['ingredient'] . '?', $i['rx'] ? 'Yes. In India it is a prescription medicine. Take it only as your doctor advises.' : 'Many ' . $i['ingredient'] . ' products can be bought without a prescription. Follow the label, and ask a pharmacist if unsure.'],
        ['How should I store ' . $i['ingredient'] . '?', ($i['storage'] ?: 'Below 30 °C, in a dry place away from sunlight') . '. Keep it in the original strip or bottle, out of reach of children.'],
        ['Can I take ' . $i['ingredient'] . ' after the expiry date?', 'No. After the expiry month the medicine may not work as it should. Return it to a pharmacy take-back point or dispose of it safely.'],
    ];
    render('ingredient', ['i' => $i, 'meds' => $meds, 'others' => $others, 'faq' => $faq], [
        'title' => $i['ingredient'] . ': uses, storage and safety · ' . site_name(),
        'description' => $i['ingredient'] . ' — ' . $i['use_text'] . '. Storage, safety notes and ' . count($meds) . ' Jan Aushadhi generic products.',
        'canonical' => base_url() . '/ingredients/' . $i['slug'], 'og_type' => 'article', 'nav' => 'navMedicines',
        'jsonld' => [
            ['@context' => 'https://schema.org', '@type' => 'Drug', 'name' => $i['ingredient'], 'description' => $i['use_text'], 'url' => base_url() . '/ingredients/' . $i['slug'],
             'prescriptionStatus' => $i['rx'] ? 'https://schema.org/PrescriptionOnly' : 'https://schema.org/OTC'],
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faq)],
            breadcrumbs([['Home', '/'], ['Ingredient guides', '/ingredients'], [$i['ingredient'], '/ingredients/' . $i['slug']]]),
        ],
    ]);
}

/* Keep titles within ~70 characters so Google shows them in full: drop the suffix first, then shorten the name. */
function seo_title(string $name, string $suffix): string
{
    $site = ' · ' . site_name();
    foreach ([$suffix . $site, $site, ''] as $tail) if (mb_strlen($name . $tail) <= 70) return $name . $tail;
    return mb_strimwidth($name, 0, 69, '…');
}
