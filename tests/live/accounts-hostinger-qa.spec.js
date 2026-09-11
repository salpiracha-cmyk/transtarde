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
  await activate(page.getByRole('button', { name: 'Sign in' }));
  await expect.poll(() => new URL(page.url()).pathname, { timeout: 30_000 }).not.toBe('/login.php');
  if (new URL(page.url()).pathname !== '/accounts/index.php') {
    await page.goto(`${BASE_URL}/accounts/index.php`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  }
}

async function activate(locator) {
  await expect(locator).toBeVisible({ timeout: 30_000 });
  await locator.evaluate(element => element.click());
}

async function backHome(page) {
  await activate(page.getByRole('button', { name: /Accounts Home/i }));
  await expect(page.getByRole('heading', { name: 'Transtrade International' })).toBeVisible();
}

test('authenticated Accounts live smoke: all approved workspaces render without posting data', async ({ page }) => {
  test.setTimeout(300_000);
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error)));
  await signIn(page);

  await expect(page).toHaveTitle(/Transtrade Accounts/i);
  await expect(page.getByRole('heading', { name: 'Transtrade International' })).toBeVisible();
  await expect(page.locator('.entityBtn[data-entity="TTI"]')).toBeVisible();

  await activate(page.getByRole('button', { name: /Expenses & Overheads/i }));
  await expect(page.getByRole('heading', { name: 'Expenses & Overheads' })).toBeVisible();
  for (const key of ['utility', 'card', 'rent', 'salary', 'donations', 'reimburse', 'general']) {
    await expect(page.locator(`[data-expense="${key}"]`), `${key} expense control must be visible`).toBeVisible();
  }

  await activate(page.getByRole('button', { name: /Expenses & Overheads/i }));
  await activate(page.getByRole('button', { name: /Salaries & Staff/i }));
  await expect(page.getByText('Add Salary Master', { exact: false })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText(/Salary advances are not used/i)).toBeVisible();
  await expect(page.getByText('Salary Master', { exact: true })).toBeVisible();
  await expect(page.getByText('Talha', { exact: true }).first()).toBeVisible({ timeout: 30_000 });
  await backHome(page);

  await activate(page.getByRole('button', { name: /Expenses & Overheads/i }));
  await activate(page.getByRole('button', { name: /^Donations/i }));
  await expect(page.getByRole('heading', { name: 'Record Donation' })).toBeVisible({ timeout: 30_000 });
  for (const ledger of ['Zakat Ledger', 'Sadqa Ledger', 'Fi Sabilillah Ledger']) {
    await expect(page.getByText(ledger, { exact: true }).first()).toBeVisible();
  }
  await backHome(page);

  await activate(page.getByRole('button', { name: /Expenses & Overheads/i }));
  await activate(page.getByRole('button', { name: /Rent & Recurring/i }));
  await expect(page.getByText('Add Rent Master', { exact: false })).toBeVisible({ timeout: 30_000 });
  await backHome(page);

  await activate(page.getByRole('button', { name: /^Purchases/i }));
  await activate(page.getByRole('button', { name: /Other Purchase/i }));
  await expect(page.getByRole('heading', { name: 'Other Purchase' })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole('heading', { name: 'Fixed Asset Register' })).toBeVisible();
  await backHome(page);

  await activate(page.getByRole('button', { name: /Journal Voucher/i }));
  await expect(page.getByRole('heading', { name: 'Journal Voucher' })).toBeVisible({ timeout: 30_000 });
  await backHome(page);

  await activate(page.getByRole('button', { name: /^Reports/i }));
  await expect(page.getByText(/General Ledger/i).first()).toBeVisible({ timeout: 30_000 });

  expect(pageErrors, 'Accounts must not raise uncaught browser errors').toEqual([]);
});
