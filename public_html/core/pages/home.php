<?php
/* Home page (available in all 10 languages). */
$p = prices();
$samples = [];
try {
    $samples = q("SELECT c.generic_name, c.slug, i.ingredient FROM medicine_catalog c JOIN medicine_info i ON i.id = c.info_id
                  WHERE c.id IN (SELECT MIN(c2.id) FROM medicine_catalog c2 JOIN medicine_info i2 ON i2.id = c2.info_id
                                 WHERE i2.slug IN ('paracetamol','metformin','amlodipine','cetirizine','pantoprazole','atorvastatin') GROUP BY i2.id)
                  ORDER BY i.ingredient LIMIT 5")->fetchAll();
} catch (Throwable $e) { /* catalog not imported yet */ }

$faq = [];
for ($i = 1; $i <= 5; $i++) $faq[] = [st('q' . $i), st('a' . $i)];

$ld = [
    ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => config('business_name') ?: site_name(), 'url' => base_url() . '/', 'logo' => base_url() . '/icons/icon-512.png',
     'email' => config('support_email') ?: null, 'contactPoint' => ['@type' => 'ContactPoint', 'contactType' => 'customer support', 'email' => config('support_email') ?: null, 'availableLanguage' => array_values(array_map(fn($l) => $l[0], SITE_LANGS))]],
    ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => site_name(), 'url' => base_url() . '/', 'inLanguage' => SITE_LANGS[site_lang()][1],
     'potentialAction' => ['@type' => 'SearchAction', 'target' => base_url() . '/medicines?q={search_term_string}', 'query-input' => 'required name=search_term_string']],
    ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => site_name(), 'applicationCategory' => 'HealthApplication', 'operatingSystem' => 'Android, iOS, Web',
     'offers' => [['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'INR', 'name' => 'Free'], ['@type' => 'Offer', 'price' => (string) $p['month'], 'priceCurrency' => 'INR', 'name' => 'Family Premium (monthly)']]],
    ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faq)],
];

render('home', ['p' => $p, 'samples' => $samples, 'faq' => $faq], [
    'title' => st('heroTitle') . ' · ' . site_name(),
    'description' => st('heroSub'),
    'canonical' => base_url() . url('', site_lang()),
    'alternates' => true, 'page' => '', 'jsonld' => $ld, 'body_class' => 'home',
]);
