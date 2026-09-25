const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TRANSTRADE_BASE_URL || 'https://app.transtradeinternational.com';
const USERNAME = process.env.TRANSTRADE_QA_USERNAME;
const PASSWORD = process.env.TRANSTRADE_QA_PASSWORD;

test('AMT is the only remaining live Export family', async ({ page }) => {
  test.setTimeout(180_000);
  await page.goto(BASE_URL + '/login.php', { waitUntil: 'domcontentloaded', timeout: 60_000 });
  await page.getByLabel('Username').fill(USERNAME);
  await page.getByLabel('Password').fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.goto(BASE_URL + '/module.php?id=exports', { waitUntil: 'domcontentloaded', timeout: 60_000 });
  await expect(page.getByRole('heading', { name: 'TG/AMT/13' })).toBeVisible({ timeout: 60_000 });
  await expect(page.getByRole('heading', { name: /^(TG\/ASAS\/60|TG\/LGT\/02A|TTI\/APE\/01)$/ })).toHaveCount(0);
  await page.getByRole('button', { name: 'Sales Contracts' }).click();
  const rows = page.locator('main tbody tr');
  await expect(rows).toHaveCount(1);
  await expect(rows.first()).toContainText('TG/AMT/13');
  await expect(rows.first()).toContainText('AMT ENTERPRISE');
  await page.getByRole('button', { name: 'FI Register' }).click();
  await expect(page.locator('main .panel').first().locator('tbody tr')).toHaveCount(0);
});

