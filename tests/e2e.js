// End-to-end browser test of the main customer journey (needs Playwright and payments_demo => true on a TEST install).
//   node tests/e2e.js http://127.0.0.1:8080
const { chromium } = require('playwright');
const B = process.argv[2] || 'http://127.0.0.1:8080';
let pass = 0, fail = 0;
const ok = (c, m) => { if (c) { pass++; console.log('  ✓ ' + m); } else { fail++; console.log('  ✗ ' + m); } };

async function journey(browser, label, viewport) {
  console.log(label);
  const ctx = await browser.newContext({ viewport });
  await ctx.route(/fonts\.g|cdn\.jsdelivr|googletagmanager|checkout\.razorpay/, r => r.abort());
  const p = await ctx.newPage();
  const errors = []; p.on('pageerror', e => errors.push(e.message));
  const tracked = []; p.on('request', r => { if (r.url().includes('action=track')) tracked.push(r.url()); });

  await p.goto(B + '/');
  ok(await p.isVisible('.consent'), 'cookie banner shows on first visit');
  await p.waitForTimeout(300);
  ok(tracked.length === 0, 'nothing tracked before consent');
  await p.click('.consent .c-accept');
  await p.waitForTimeout(500);
  ok(!(await p.isVisible('.consent')), 'banner closes after Accept');
  ok(tracked.length === 1, 'page view tracked after Accept');
  const ck = (await ctx.cookies()).find(c => c.name === 'ms_consent');
  ok(ck && ck.value.startsWith('1.1.1.'), 'consent cookie stores the choice');

  await p.goto(B + '/ta/');
  ok((await p.getAttribute('html', 'lang')) === 'ta-IN', 'Tamil home page');
  await p.goto(B + '/medicines?q=metformin');
  ok((await p.locator('.med-list li').count()) > 3, 'medicine search returns results');

  await p.goto(B + '/');
  await p.click('.hero-ctas a[data-track="cta_hero"]');
  await p.waitForSelector('#signupForm:not([hidden])');
  ok(true, 'Start free opens the sign-up form');
  await p.fill('#suName', 'E2E User'); await p.fill('#suEmail', 'e2e' + Date.now() + Math.floor(Math.random() * 1e4) + '@test.in');
  await p.fill('#suPass', 'secret123'); await p.check('#suConsent');
  await p.click('#signupForm button[type=submit]');
  await p.waitForSelector('#tourDialog[open]');
  ok(true, 'welcome tour opens after sign-up');
  for (let i = 0; i < 4; i++) await p.click('#tourNext');
  await p.waitForTimeout(300);
  ok(await p.isVisible('#view-scan'), 'tour ends on the Scan screen');

  await p.click('#btnSample'); await p.waitForTimeout(2500);
  const name = await p.inputValue('#fName');
  ok(name.length > 2, 'sample pack read: ' + name);
  ok(/^\d{4}-\d{2}$/.test(await p.inputValue('#fExp')), 'expiry date read from the label');
  await p.click('#btnSave'); await p.waitForTimeout(800);
  ok((await p.textContent('#statTotal')).trim() === '1', 'medicine saved to cabinet');


  // Whole prescription from text: review list, usual schedules, saved in one go
  await p.click('#bottomnav [data-go="scan"], #tabs [data-go="scan"] >> visible=true'); await p.waitForTimeout(300);
  await p.click('details.paste-rx summary');
  await p.fill('#rxPasteText', 'Rx\n1. Tab Dolo 650 1-0-1 x 5 days after food\n2. Tab Metformin 500 mg\n3. Cap Amoxicillin 500 mg TDS x 5 days');
  await p.click('#rxPasteBtn'); await p.waitForSelector('#rxDialog[open]'); await p.waitForTimeout(1200);
  ok((await p.$$('#rxList .rx-row')).length === 3, 'prescription review lists 3 medicines');
  ok(await p.isVisible('#rxList .rx-tag'), 'usual schedule filled for the medicine without instructions');
  await p.click('#rxSave'); await p.waitForTimeout(1000);
  ok((await p.textContent('#statTotal')).trim() === '4', 'all 3 saved in one tap');

  // One sentence
  await p.click('#bottomnav [data-go="scan"], #tabs [data-go="scan"] >> visible=true'); await p.waitForTimeout(300);
  await p.fill('#quickInput', 'Add Cetirizine 10 mg at night for 5 days'); await p.click('#quickForm button[type=submit]'); await p.waitForTimeout(1200);
  ok((await p.textContent('#statTotal')).trim() === '5', 'medicine added from one typed sentence');
  await p.fill('#quickInput', 'Add Crocin 500 at night'); await p.click('#quickForm button[type=submit]'); await p.waitForTimeout(1500);
  await p.click('#bottomnav [data-go="cabinet"], #tabs [data-go="cabinet"] >> visible=true'); await p.waitForTimeout(300);
  ok(await p.isVisible('#safetyCard'), 'safety check warns: Dolo and Crocin are the same medicine');
  await p.click('#bottomnav [data-go="scan"], #tabs [data-go="scan"] >> visible=true'); await p.waitForTimeout(200);

  // Whole time slot in one tap, then the shopping list
  await p.click('#bottomnav [data-go="doses"], #tabs [data-go="doses"] >> visible=true'); await p.waitForTimeout(400);
  const allBtn = await p.$('[data-all="m"]');
  ok(!!allBtn, '"All taken" button for the morning');
  if (allBtn) { await allBtn.click(); await p.waitForTimeout(800); ok((await p.$$('[data-slot="m"] .packet.done')).length >= 2, 'all morning doses ticked at once'); }
  const skipBtn = await p.$('[data-skip]');
  ok(!!skipBtn, 'Skip button next to a dose');
  if (skipBtn) { await skipBtn.click(); await p.waitForSelector('#skipDialog[open]'); await p.click('[data-reason="out"]'); await p.waitForTimeout(800);
    ok((await p.$$('.packet.skipped')).length >= 1, 'skipped dose shows its reason'); }
  ok(await p.isVisible('#adhCard'), 'last 7 days report shows');
  await p.click('#bottomnav [data-go="cabinet"], #tabs [data-go="cabinet"] >> visible=true'); await p.waitForTimeout(300);
  await p.click('#btnShop'); await p.waitForSelector('#shopDialog[open]');
  ok((await p.$$('#shopList li')).length >= 1, 'shopping list opens');
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  await p.click('#bottomnav [data-go="account"], #tabs [data-go="account"] >> visible=true');
  await p.waitForTimeout(800);
  ok((await p.textContent('#planBadge')).trim().length > 0, 'plan card shows');
  await p.click('#btnInvite'); await p.waitForTimeout(400);
  ok(await p.isVisible('#upgradeDialog[open]'), 'invite on Free plan opens upgrade dialog');
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  await p.fill('#bxName', 'Test pillbox'); await p.click('#boxAdd button[type=submit]'); await p.waitForTimeout(800);
  ok(/^\d{6}$/.test(((await p.textContent('.box-item .code')) || '').trim()), 'pillbox gets a 6-digit pairing code');
  await p.click('[data-boxtest]'); await p.waitForTimeout(1200);
  ok((await p.$$('.box-events li')).length >= 1, 'lid opening without hardware appears in the list');
  await p.click('#btnInvite'); await p.waitForTimeout(400);
  await p.click('#btnPay'); await p.waitForTimeout(1500);
  ok((await p.textContent('#planBadge')).includes('Premium'), 'demo payment makes the account Premium');
  await p.click('#btnInvite'); await p.waitForTimeout(500);
  ok(/^[A-Z0-9]{8}$/.test((await p.textContent('#inviteCode')).trim()), 'invite code created');

  await p.selectOption('#langSelect', 'hi'); await p.waitForTimeout(400);
  ok((await p.textContent('#planBadge')).includes('प्रीमियम'), 'app switches to Hindi');
  const ov = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
  ok(!ov, 'no horizontal scrolling');
  ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch();
  await journey(browser, 'Phone (390 px)', { width: 390, height: 844 });
  await journey(browser, 'Desktop (1366 px)', { width: 1366, height: 900 });
  await browser.close();
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
