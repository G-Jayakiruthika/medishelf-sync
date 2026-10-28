# SEO and growth plan

## 1. What is already built
| Item | Detail |
|---|---|
| Indexable pages | 20 localized pages (home + pricing × 10 languages), 10 static pages, 5 articles, 74 ingredient guides, 737 medicine pages with guides. **846 URLs in the sitemap.** |
| Thin pages kept out | 1,374 medicine pages without an ingredient guide are live (useful for visitors) but marked `noindex` so Google judges the site on its best pages. Adding guides for more ingredients automatically makes their medicines indexable. |
| Technical | Server-rendered HTML, clean URLs, canonical tags, hreflang for 10 languages + x-default, 301 for wrong language URLs, 404 page, robots.txt, XML sitemap, fast pages (≈ 20 KB), mobile-first, HTTPS. |
| Structured data | Organization, WebSite with search box, SoftwareApplication with prices, Product (pricing), FAQPage (home, pricing, every ingredient), Drug (medicine and ingredient pages), Article (guides), BreadcrumbList. |
| Sharing | Open Graph + Twitter card tags, 1200×630 social image. |
| Conversion | Every content page ends with a "Start free" box. Button clicks are measured (with consent) in the admin dashboard. |

## 2. First week after launch
1. Submit `sitemap.xml` in **Google Search Console** and **Bing Webmaster Tools**.
2. Create a **Google Business Profile** only if you have a public business address; otherwise skip.
3. Add the site link to your WhatsApp Business profile, Instagram bio, LinkedIn and YouTube channel.
4. Share with 20–30 friends and family; ask them to add real medicines and tell you what is confusing. Fix the top 3 complaints.

## 3. Content plan (one article per week)
Search terms Indian families actually type. Each article: 600–1,000 words, plain language, sources at the end, one image, link to 2–3 medicine/ingredient pages. Add them in `core/content/guides.php`.

| Month | Articles |
|---|---|
| 1 | Can I take a medicine one month after expiry? · Medicine kit for a newborn · What does "Store below 25 °C" mean in Chennai summer? · How to read a doctor's prescription |
| 2 | Medicines to carry for Sabarimala / Kailash / Char Dham yatra · Monsoon medicine storage · Insulin storage during power cuts · Generic vs branded: real price examples |
| 3 | Elderly parents' medicine checklist · How to report a side effect to PvPI · School first-aid kit · How to check a medicine QR code |
| Ongoing | Translate the 5 best-performing articles into Tamil and Hindi first (most searches), then other languages. |

## 4. Growth channels (low cost)
| Channel | Idea | Cost |
|---|---|---|
| WhatsApp | Share each new article in family and community groups with `?utm_source=whatsapp`. Invite links from Premium families spread the app naturally. | Free |
| YouTube Shorts / Instagram Reels | 30-second "Where is the expiry date on this strip?" videos in Tamil/Hindi, using the included guide video style. | Free |
| Pharmacies and clinics | Print an A5 poster with a QR code to the site: "Scan, track expiry free". Offer local pharmacists a free Premium year for putting it up. | ₹500–1,000 printing |
| Resident welfare associations, senior citizen clubs | 20-minute demo: "Clean your medicine box". | Free |
| Google Ads (later) | Only after organic pages convert; target "medicine expiry reminder app" in Tamil Nadu first, ₹200/day cap. | Paid |

Use UTM tags on every shared link (`?utm_source=instagram&utm_campaign=reel1`); the admin dashboard shows which sources bring visitors.

## 5. Numbers to watch monthly (Admin dashboard)
| Metric | Healthy early target |
|---|---|
| Visitors (accepted analytics) | Grows month on month |
| Sign-ups / visitors | 3–5 % |
| Premium families / users | 2–5 % |
| Most visited pages | Write more articles like the top ones |
| Button clicks `cta_medicine`, `cta_guide` | Shows which content brings sign-ups |
| Cookie accept rate | Typically 40–70 %; real traffic is higher than the dashboard shows |

## 6. Pricing experiments
Prices live in `config.php` (`price_month`, `price_year`). Try one change at a time for a month: e.g. ₹399 → ₹299/year, or a festival offer. Keep the refund promise; it raises trust more than it costs.

## 7. Things to avoid
- Copying medicine descriptions from other websites (duplicate content hurts rankings and may infringe copyright).
- Health claims ("cures", "guaranteed"). Keep to storage, expiry and safety facts with sources.
- Buying backlinks or fake reviews.
- Showing ads inside a health app; it breaks the privacy promise.
