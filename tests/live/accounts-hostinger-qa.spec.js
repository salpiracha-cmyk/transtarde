const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TRANSTRADE_BASE_URL || 'https://app.transtradeinternational.com';
const QA_USERNAME = process.env.TRANSTRADE_QA_USERNAME;
const QA_PASSWORD = process.env.TRANSTRADE_QA_PASSWORD;

async function signIn(page) {
  expect(QA_USERNAME, 'TRANSTRADE_QA_USERNAME is required').toBeTruthy();
  expect(QA_PASSWORD, 'TRANSTRADE_QA_PASSWORD is required').toBeTruthy();
  await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.getByLabel('Username').fill(QA_USERNAME);
  await page.getByLabel('Password').fill(QA_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect.poll(() => new URL(page.url()).pathname, { timeout: 30_000 }).not.toBe('/login.php');
  if (new URL(page.url()).pathname !== '/accounts/index.php') {
    await page.goto(`${BASE_URL}/accounts/index.php`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  }
}

async function backHome(page) {
  await page.getByRole('button', { name: /Accounts Home/i }).click();
  await expect(page.getByRole('heading', { name: 'Transtrade International' })).toBeVisible();
}

test('authenticated Accounts live smoke: all approved workspaces render without posting data', async ({ page }) => {
  test.setTimeout(180_000);
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error)));
  await signIn(page);

  await expect(page).toHaveTitle(/Transtrade Accounts/i);
  await expect(page.getByRole('heading', { name: 'Transtrade International' })).toBeVisible();
  await expect(page.locator('.entityBtn[data-entity="TTI"]')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Log out' })).toBeVisible();

  await page.getByRole('button', { name: /Expenses & Overheads/i }).click();
  await expect(page.getByRole('heading', { name: 'Expenses & Overheads' })).toBeVisible();
  for (const label of ['Utilities & Bills', 'Credit Cards', 'Rent & Recurring', 'Salaries & Staff', 'Donations', 'Reimburse Someone', 'Office / Mill Expense']) {
    await expect(page.getByRole('button', { name: new RegExp(label, 'i') })).toBeVisible();
  }

  await page.getByRole('button', { name: /Salaries & Staff/i }).click();
  await expect(page.getByText('Add Salary Master', { exact: false })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText(/Salary advances are not used/i)).toBeVisible();
  await expect(page.getByText('Salary Master', { exact: true })).toBeVisible();
  await expect(page.getByText('Talha', { exact: true }).first()).toBeVisible({ timeout: 30_000 });
  await backHome(page);

  await page.getByRole('button', { name: /Expenses & Overheads/i }).click();
  await page.getByRole('button', { name: /^Donations/i }).click();
  await expect(page.getByRole('heading', { name: 'Record Donation' })).toBeVisible({ timeout: 30_000 });
  for (const ledger of ['Zakat Ledger', 'Sadqa Ledger', 'Fi Sabilillah Ledger']) {
    await expect(page.getByText(ledger, { exact: true }).first()).toBeVisible();
  }
  await backHome(page);

  await page.getByRole('button', { name: /Expenses & Overheads/i }).click();
  await page.getByRole('button', { name: /Rent & Recurring/i }).click();
  await expect(page.getByText('Add Rent Master', { exact: false })).toBeVisible({ timeout: 30_000 });
  await backHome(page);

  await page.getByRole('button', { name: /^Purchases/i }).click();
  await page.getByRole('button', { name: /Other Purchase/i }).click();
  await expect(page.getByRole('heading', { name: 'Other Purchase' })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole('heading', { name: 'Fixed Asset Register' })).toBeVisible();
  await backHome(page);

  await page.getByRole('button', { name: /Journal Voucher/i }).click();
  await expect(page.getByRole('heading', { name: 'Journal Voucher' })).toBeVisible({ timeout: 30_000 });
  await backHome(page);

  await page.getByRole('button', { name: /^Reports/i }).click();
  await expect(page.getByText(/General Ledger/i).first()).toBeVisible({ timeout: 30_000 });

  expect(pageErrors, 'Accounts must not raise uncaught browser errors').toEqual([]);
});
