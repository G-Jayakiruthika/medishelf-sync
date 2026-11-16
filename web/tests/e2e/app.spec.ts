import { expect, test, type Page } from '@playwright/test';

async function openDemo(page: Page) {
  await page.goto('./');
  await page.getByRole('button', { name: /demo|sample family/i }).first().click();
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Asha');
}
const go = async (page: Page, path: string) => {
  await page.goto('./' + path);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
};

test('sign-in page explains the app and opens the demo', async ({ page }) => {
  await page.goto('./');
  await expect(page.getByRole('heading', { name: /every medicine/i })).toBeVisible();
  await openDemo(page);
  await expect(page.getByText(/Doses today/).first()).toBeVisible();
});

test('marking a dose taken and undoing it', async ({ page }) => {
  await openDemo(page);
  const before = await page.locator('.stat b').first().innerText();
  await page.getByRole('button', { name: /Mark taken: Amlodipine/ }).click();
  await expect(page.getByText('Amlodipine marked as taken')).toBeVisible();
  await expect(page.locator('.stat b').first()).not.toHaveText(before);
  await page.getByRole('button', { name: /Undo: Amlodipine/ }).first().click();
  await expect(page.locator('.stat b').first()).toHaveText(before);
});

test('adding a medicine reads the expiry as printed on the strip', async ({ page }) => {
  await openDemo(page);
  await go(page, 'cabinet');
  await page.getByRole('button', { name: 'Add medicine' }).click();
  const dlg = page.getByRole('dialog');
  await dlg.getByLabel('Name').fill('Pantoprazole');
  await dlg.getByLabel('Expiry').fill('04/28');
  await expect(dlg.getByText(/Good until the end of Apr 2028/)).toBeVisible();
  await expect(dlg.getByText(/acidity|reflux|ulcer/i)).toBeVisible();   // medicine information appears
  await dlg.getByRole('button', { name: 'Morning', exact: true }).click();
  await dlg.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Medicine added')).toBeVisible();
  await expect(page.getByText('Pantoprazole').filter({ visible: true }).first()).toBeVisible();
});

test('bad expiry is refused with a helpful message', async ({ page }) => {
  await openDemo(page);
  await go(page, 'cabinet?add=1');
  const dlg = page.getByRole('dialog');
  await dlg.getByLabel('Name').fill('Test');
  await dlg.getByLabel('Expiry').fill('soon');
  await dlg.getByRole('button', { name: 'Save' }).click();
  await expect(dlg.getByRole('alert')).toContainText('03/27');
});

test('filters show only expired medicines', async ({ page }) => {
  await openDemo(page);
  await go(page, 'cabinet');
  await page.getByRole('button', { name: /^Expired/ }).click();
  await expect(page.getByText('Paracetamol (Dolo)').filter({ visible: true }).first()).toBeVisible();
  await expect(page.getByText('Metformin')).toHaveCount(0);
});

test('a child cannot be added without a guardian’s consent', async ({ page }) => {
  await openDemo(page);
  await go(page, 'family');
  await page.getByRole('button', { name: 'Add family member' }).click();
  const dlg = page.getByRole('dialog');
  await dlg.getByLabel('Name').fill('Meera');
  await dlg.getByLabel('Date of birth').fill('2020-05-05');
  await dlg.getByRole('button', { name: 'Save' }).click();
  await expect(dlg.getByRole('alert')).toContainText('guardian');
  await dlg.getByRole('checkbox').check();
  await dlg.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByRole('article', { name: 'Meera' })).toBeVisible();
});

test('a high reading is flagged in plain language', async ({ page }) => {
  await openDemo(page);
  await go(page, 'health');
  await page.getByRole('button', { name: 'Add reading' }).click();
  const dlg = page.getByRole('dialog');
  await dlg.getByLabel('Reading').selectOption('bp');
  await dlg.getByLabel('Upper (systolic)').fill('185');
  await dlg.getByLabel('Lower (diastolic)').fill('100');
  await expect(dlg.getByText('See a doctor today')).toBeVisible();
  await dlg.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Reading saved')).toBeVisible();
});

test('expenses add up and export as CSV', async ({ page }) => {
  await openDemo(page);
  await go(page, 'expenses');
  await page.getByRole('button', { name: 'Add', exact: true }).click();
  const dlg = page.getByRole('dialog');
  await dlg.getByLabel('Amount (₹)').fill('1,000');
  await dlg.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('₹3,030.50')).toBeVisible();
  const [file] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'CSV' }).click()]);
  expect(file.suggestedFilename()).toMatch(/medical-expenses-FY\d{4}-\d{2}\.csv/);
});

test('privacy: choices, requests, download and account deletion', async ({ page }) => {
  await openDemo(page);
  await go(page, 'privacy');
  await page.getByRole('checkbox', { name: /Health tips/ }).check();
  await expect(page.getByText('Turned on')).toBeVisible();
  await page.getByLabel('Details').fill('Please send everything you hold about me.');
  await page.getByRole('button', { name: 'Send request' }).click();
  await expect(page.getByText(/We reply within 30 days/)).toBeVisible();
  const [file] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download my data' }).click()]);
  expect(file.suggestedFilename()).toBe('aushadhbox-my-data.json');
  await page.getByRole('button', { name: 'Delete my account' }).click();
  const dlg = page.getByRole('dialog');
  await expect(dlg.getByRole('button', { name: 'Delete for ever' })).toBeDisabled();
  await dlg.getByLabel('Type DELETE to confirm').fill('DELETE');
  await dlg.getByRole('button', { name: 'Delete for ever' }).click();
  await expect(page.getByRole('heading', { name: /every medicine/i })).toBeVisible();
});

test('deep links work after a reload (GitHub Pages 404 fallback)', async ({ page }) => {
  await openDemo(page);
  await page.goto('./visits');
  await expect(page.getByRole('heading', { level: 1, name: 'Doctor visits' })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('heading', { level: 1, name: 'Doctor visits' })).toBeVisible();
});

test('every page can be reached from the navigation and has one main heading', async ({ page }) => {
  await openDemo(page);
  for (const name of ['Cabinet', 'Family', 'Health']) {
    await page.getByRole('navigation', { name: 'Main' }).filter({ visible: true }).getByRole('link', { name }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
  }
});
