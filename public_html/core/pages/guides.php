<?php
/* Guides (blog articles) index and article pages. */

function guides_index(): void
{
    render('guides', ['list' => guides()], [
        'title' => 'Medicine safety guides for Indian families · ' . site_name(),
        'description' => 'Practical guides: reading expiry dates, disposing of old medicines, the red line on strips, storing medicines in summer and saving with Jan Aushadhi generics.',
        'canonical' => base_url() . '/guides', 'nav' => 'navGuides',
        'jsonld' => [breadcrumbs([['Home', '/'], ['Guides', '/guides']])],
    ]);
}

function guide_page(string $slug): void
{
    $g = guides()[$slug] ?? null;
    if (!$g) { not_found(); return; }
    $html = str_replace('{SITE}', e(site_name()), $g['html']);
    $more = array_slice(array_filter(guides(), fn($k) => $k !== $slug, ARRAY_FILTER_USE_KEY), 0, 3, true);
    $url = base_url() . '/guides/' . $slug;
    render('guide', ['g' => $g, 'slug' => $slug, 'html' => $html, 'more' => $more], [
        'title' => $g['title'] . ' · ' . site_name(), 'description' => $g['desc'], 'canonical' => $url, 'og_type' => 'article', 'nav' => 'navGuides',
        'jsonld' => [
            ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $g['title'], 'description' => $g['desc'], 'datePublished' => $g['date'], 'dateModified' => $g['date'],
             'inLanguage' => 'en-IN', 'mainEntityOfPage' => $url, 'image' => base_url() . '/img/og-default.png',
             'author' => ['@type' => 'Organization', 'name' => site_name()], 'publisher' => ['@type' => 'Organization', 'name' => site_name(), 'logo' => ['@type' => 'ImageObject', 'url' => base_url() . '/icons/icon-512.png']]],
            breadcrumbs([['Home', '/'], ['Guides', '/guides'], [$g['title'], '/guides/' . $slug]]),
        ],
    ]);
}
