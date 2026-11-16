<?php
/* Blog / guide articles. To add one: copy a block, give it a new slug, and it appears on /guides and in the sitemap.
   Keep facts sourced; list sources at the end of each article. */
return [
'how-to-read-expiry-date-on-medicine' => [
    'title' => 'How to read the expiry date on an Indian medicine strip',
    'desc' => 'Exp., Mfg., B.No. and MRP explained. What "Exp 03/2027" really means, where to find it on strips, bottles and tubes, and what the QR code tells you.',
    'date' => '2026-10-20', 'minutes' => 4, 'tag' => 'Expiry',
    'html' => <<<HTML
<p>Every medicine sold in India must print its batch number, manufacturing date and expiry date. They are often tiny, embossed into foil, or hidden on the edge of a strip. Here is how to find and read them.</p>
<h2>The four lines to look for</h2>
<table><thead><tr><th>On the pack</th><th>Means</th></tr></thead><tbody>
<tr><td><b>B.No.</b> or <b>Batch No.</b></td><td>The production batch. Useful if there is ever a recall or a side-effect report.</td></tr>
<tr><td><b>Mfg.</b> or <b>Mfd.</b> or <b>MFD</b></td><td>When it was made.</td></tr>
<tr><td><b>Exp.</b> or <b>Expiry</b> or <b>Use before</b></td><td>The last month the maker guarantees it is safe and works fully.</td></tr>
<tr><td><b>M.R.P. ₹… incl. of all taxes</b></td><td>The most a shop may charge you.</td></tr>
</tbody></table>
<h2>What "Exp 03/2027" means</h2>
<p>When only a month and year are printed, the medicine is good until the <b>last day of that month</b>. So <b>03/2027</b> means you can use it until 31 March 2027. From 1 April 2027 it is expired.</p>
<p>Some packs print a full date such as <b>15/03/2027</b>. Then that exact day is the limit.</p>
<h2>Where to find it</h2>
<ul><li><b>Strips and blister packs:</b> on the back, usually at one end. If the strip has been cut, the date may be on another piece, so keep the box or write the date on each piece with a marker.</li><li><b>Bottles:</b> on the label or embossed on the bottom or the cap.</li><li><b>Tubes:</b> pressed into the crimped end.</li><li><b>Injections and vials:</b> on the small label and the outer box.</li></ul>
<h2>Opened packs expire sooner</h2>
<p>The printed date is for an unopened pack stored correctly. Once opened, some medicines spoil earlier:</p>
<ul><li><b>Eye and ear drops:</b> usually 4 weeks after opening, unless the label says otherwise.</li><li><b>Syrups and suspensions:</b> check the label. Antibiotic suspensions mixed with water often last only 7–14 days, sometimes in the fridge.</li><li><b>Insulin pens in use:</b> often 4 weeks at room temperature. Check the leaflet.</li></ul>
<p>Write the opening date on the bottle with a marker.</p>
<h2>The QR code on medicine packs</h2>
<p>Since 1 August 2023, India requires a QR code on the packs of 300 top-selling brands (Schedule H2 of the Drugs Rules). Scanning it shows the product name, manufacturer, batch, manufacturing and expiry date. It helps you spot fake medicines: if the details do not match the pack, show it to your pharmacist.</p>
<h2>Let your phone do it</h2>
<p>{SITE} reads these labels and QR codes with your phone camera, saves the expiry, and reminds you before each medicine runs out of date, in 10 Indian languages.</p>
<h3>Sources</h3>
<ul><li>CDSCO, FAQs on QR code for drug formulations (July 2023)</li><li>Drugs Rules, 1945 (labelling provisions)</li></ul>
HTML],

'how-to-dispose-of-expired-medicines-in-india' => [
    'title' => 'How to throw away expired medicines safely in India',
    'desc' => 'What CDSCO advises: take-back points first, the 17 medicines that should be flushed, and what never to do with old tablets, syrups and inhalers.',
    'date' => '2026-10-18', 'minutes' => 5, 'tag' => 'Safety',
    'html' => <<<HTML
<p>Expired medicines left in a drawer get taken by mistake, picked up by children, or end up in rivers and soil. In 2025 the Central Drugs Standard Control Organisation (CDSCO) published guidance on disposing of expired and unused medicines. Here is what it means for a family.</p>
<h2>1. Use a take-back point if there is one</h2>
<p>Take-back and proper incineration are the preferred methods for most medicines. Ask your pharmacy, a government hospital pharmacy or your local body whether they collect old medicines. Some states run their own drives, such as Kerala's nPROUD programme.</p>
<h2>2. The 17 medicines CDSCO says to flush</h2>
<p>For a small group of powerful medicines, the danger of someone taking them by mistake is greater than the harm to the environment. CDSCO advises flushing these down the toilet if no take-back is available:</p>
<p>Fentanyl, fentanyl citrate, diazepam, buprenorphine, buprenorphine hydrochloride, morphine sulphate, methadone hydrochloride, hydromorphone hydrochloride, hydrocodone bitartrate, tapentadol, oxycodone hydrochloride, oxycodone, oxymorphone hydrochloride, sodium oxybate, tramadol, methylphenidate and meperidine hydrochloride.</p>
<p class="note">Do <b>not</b> flush other medicines. Most medicines in a home cabinet should go to a take-back point.</p>
<h2>3. If there is no take-back point</h2>
<ul><li>Keep tablets in their strip or put loose ones in a sealed bag or container so they cannot be picked out.</li><li>Scratch out your name and prescription details on bottles and boxes.</li><li>Give it to your municipal waste collector separately and tell them it is medicine, or hold it until a collection drive.</li></ul>
<h2>What never to do</h2>
<ul><li>Do not burn medicines or inhalers. Inhalers can burst.</li><li>Do not pour syrups into drains or open ground.</li><li>Do not give expired medicines to anyone, including animals.</li><li>Do not keep "just in case" medicines past expiry. They may not work when you need them.</li></ul>
<h2>Make it a habit</h2>
<p>Check your cabinet once a month. {SITE} shows a red handle on every expired medicine and a list of what to return, so the job takes two minutes.</p>
<h3>Sources</h3>
<ul><li>CDSCO, Guidance document on disposal of expired/unused drugs (2025)</li><li>India TV News and The South First reporting on the CDSCO list (July 2025)</li></ul>
HTML],

'red-line-on-medicine-strip-meaning' => [
    'title' => 'What the red line on a medicine strip means',
    'desc' => 'The vertical red line on antibiotics and other medicines is a warning: do not take without a doctor\'s prescription. Here is why it matters.',
    'date' => '2026-10-14', 'minutes' => 3, 'tag' => 'Safety',
    'html' => <<<HTML
<p>Look closely at a strip of antibiotics in India and you may see a thick <b style="color:var(--bad)">vertical red line</b> printed on it. It is not decoration.</p>
<h2>The meaning</h2>
<p>The red line marks medicines that should be sold and taken <b>only with a doctor's prescription</b>, most often antibiotics. The Ministry of Health ran the "Medicines with the Red Line" campaign to make this easy to recognise, even for people who cannot read the small print.</p>
<h2>Why it matters: antibiotic resistance</h2>
<p>Taking antibiotics without need, at the wrong dose, or stopping early helps bacteria become resistant. Resistant infections are harder and costlier to treat. India has some of the highest antibiotic use in the world.</p>
<ul><li>Do not buy red-line medicines without a prescription.</li><li>Do not use leftover antibiotics from a previous illness or another person.</li><li>Finish the course your doctor prescribes, even if you feel better.</li><li>Do not pressure your doctor for antibiotics for a cold or flu. They do not work against viruses.</li></ul>
<h2>Other marks you may see</h2>
<ul><li><b>Rx</b> in a corner: prescription medicine.</li><li><b>NRx</b>: a narcotic or psychotropic medicine with stricter rules.</li><li><b>Schedule H / H1</b> warning text: sell only on prescription.</li></ul>
<p>{SITE} marks prescription medicines with an Rx tag in your cabinet so the whole family knows not to take them casually.</p>
<h3>Sources</h3>
<ul><li>Ministry of Health and Family Welfare, "Medicines with the Red Line" awareness campaign</li><li>Drugs Rules, 1945, Schedules H and H1</li></ul>
HTML],

'how-to-store-medicines-in-indian-summer' => [
    'title' => 'How to store medicines in Indian summer heat',
    'desc' => 'Most medicines must stay below 25–30 °C. Where to keep them at home when it is 40 °C outside, and which ones need the fridge.',
    'date' => '2026-10-10', 'minutes' => 4, 'tag' => 'Storage',
    'html' => <<<HTML
<p>Most labels say <b>"Store below 30 °C"</b> or <b>"below 25 °C"</b>. In much of India, rooms reach 35–40 °C in April, May and June. Heat and humidity can make medicines weaker before their expiry date.</p>
<h2>Where to keep medicines at home</h2>
<ul><li><b>Best:</b> a closed cupboard in a bedroom or inner room, away from outside walls and windows.</li><li><b>Avoid:</b> the kitchen (heat and steam), bathroom (humidity), window sills, the top of the fridge (warm air) and car dashboards or two-wheeler boxes.</li><li>Keep them in the original strip or bottle with the cap tight. Do not move tablets into open boxes.</li><li>Keep silica gel sachets in bottles; do not throw them away.</li></ul>
<h2>Medicines that need the fridge (2–8 °C)</h2>
<ul><li>Unopened insulin pens and vials</li><li>Some eye drops, vaccines and injections</li><li>Some antibiotic syrups after mixing</li><li>Some probiotics</li></ul>
<p>Put them in the main compartment, not the door (temperature swings) and never in the freezer. Frozen insulin must be thrown away. During power cuts, keep the fridge closed.</p>
<h2>Travelling</h2>
<ul><li>Carry medicines in hand luggage, not a hot car boot or checked bag.</li><li>For insulin, use a cooling pouch.</li></ul>
<h2>Signs a medicine has been damaged</h2>
<p>Tablets that are crumbling, sticky, discoloured or smell unusual, capsules stuck together, creams that have separated, or syrups that have changed colour. Do not use them, even if the date is fine.</p>
<p>{SITE} shows each ingredient's storage advice when you add a medicine to your cabinet.</p>
<h3>Sources</h3>
<ul><li>Storage statements on product labels, as required by the Drugs Rules, 1945</li><li>Manufacturer leaflets for insulin products</li></ul>
HTML],

'jan-aushadhi-generic-medicines-guide' => [
    'title' => 'Jan Aushadhi: how to save on medicines with generics',
    'desc' => 'What Pradhan Mantri Bhartiya Janaushadhi Kendras are, how much you can save, whether generics are as good as brands, and how to find your medicine.',
    'date' => '2026-10-06', 'minutes' => 5, 'tag' => 'Save money',
    'html' => <<<HTML
<p>The <b>Pradhan Mantri Bhartiya Janaushadhi Pariyojana (PMBJP)</b> is a Government of India scheme that sells quality generic medicines at low prices through Jan Aushadhi Kendras. By November 2025 more than 17,600 Kendras had opened across India, and the government aims for 25,000 by March 2027.</p>
<h2>What is a generic medicine?</h2>
<p>A generic contains the same active ingredient, in the same strength and form, as a branded medicine. "Paracetamol 650 mg tablet" is the generic; brand names are what companies call their own version.</p>
<h2>Are they as good?</h2>
<p>Jan Aushadhi medicines are bought from suppliers that meet WHO-GMP standards, and each batch is tested in accredited laboratories before it is sold. The low price comes from no brand marketing, not lower quality.</p>
<h2>How much can you save?</h2>
<p>The government says Jan Aushadhi prices are 50–80% lower than average branded prices. For a family on long-term medicines for diabetes or blood pressure, that can add up to thousands of rupees a year.</p>
<h2>How to buy</h2>
<ol><li>Find the ingredient and strength on your prescription or current strip (for example, <i>Metformin 500 mg</i>).</li><li>Search it in our <a href="/medicines">Jan Aushadhi medicine list</a> to see if a generic exists.</li><li>Find your nearest Kendra with the official Janaushadhi Sugam app.</li><li>Ask your doctor first before switching, especially for medicines where small differences matter (thyroid, epilepsy, blood thinners).</li></ol>
<p>When you add a medicine to {SITE}, it shows you the matching Jan Aushadhi generic automatically.</p>
<h3>Sources</h3>
<ul><li>Pharmaceuticals &amp; Medical Devices Bureau of India (PMBI), janaushadhi.gov.in</li><li>Government reply on Kendra numbers (December 2025) and target of 25,000 by March 2027</li><li>PMBJP product list, December 2025</li></ul>
HTML],
];
