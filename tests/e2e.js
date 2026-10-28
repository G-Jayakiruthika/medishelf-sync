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

  await p.click('#bottomnav [data-go="account"], #tabs [data-go="account"] >> visible=true');
  await p.waitForTimeout(800);
  ok((await p.textContent('#planBadge')).trim().length > 0, 'plan card shows');
  await p.click('#btnInvite'); await p.waitForTimeout(400);
  ok(await p.isVisible('#upgradeDialog[open]'), 'invite on Free plan opens upgrade dialog');
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
