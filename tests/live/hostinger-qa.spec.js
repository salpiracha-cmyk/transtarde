// Each automatic run uses its GitHub run ID to create isolated, clearly labelled QA records.
const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TRANSTRADE_BASE_URL || 'https://app.transtradeinternational.com';
const QA_USERNAME = process.env.TRANSTRADE_QA_USERNAME;
const QA_PASSWORD = process.env.TRANSTRADE_QA_PASSWORD;
const RUN_ID = String(process.env.GITHUB_RUN_ID || Date.now());

function isoContainer(prefix, serialNumber) {
  const values = {};
  let value = 10;
  for (let code = 65; code <= 90; code += 1) {
    while (value % 11 === 0) value += 1;
    values[String.fromCharCode(code)] = value;
    value += 1;
  }
  const body = `${prefix}${String(serialNumber).padStart(6, '0').slice(-6)}`;
  const sum = [...body].reduce((total, char, index) => {
    const numeric = /\d/.test(char) ? Number(char) : values[char];
    return total + numeric * (2 ** index);
  }, 0);
  const remainder = sum % 11;
  return `${body}-${remainder === 10 ? 0 : remainder}`;
}

async function waitForSharedSave(page) {
  await expect(page.locator('#saveBadge')).toContainText(/Saved to shared system/i, { timeout: 35_000 });
}

async function fillMillContainerNumber(page, value) {
  const raw = String(value).toUpperCase().replace(/[^A-Z0-9]/g, '');
  await page.locator('.tt-container-main').fill(raw.slice(0, 10));
  await page.locator('.tt-container-check').fill(raw.slice(10, 11));
  await expect(page.locator('#contNo')).toHaveValue(`${raw.slice(0, 10)}-${raw.slice(10, 11)}`);
}

test.use({
  viewport: { width: 1440, height: 1000 },
  trace: 'retain-on-failure',
  screenshot: 'only-on-failure',
  video: 'retain-on-failure',
});

test('manual Hostinger QA: Export instruction to Mill and container return', async ({ page }, testInfo) => {
  test.setTimeout(240_000);
  expect(QA_USERNAME, 'TRANSTRADE_QA_USERNAME GitHub secret is required').toBeTruthy();
  expect(QA_PASSWORD, 'TRANSTRADE_QA_PASSWORD GitHub secret is required').toBeTruthy();

  const suffix = RUN_ID.slice(-8);
  const customerName = `QA GITHUB ${suffix}`;
  const customerCode = `Q${suffix.slice(-5)}`;
  const contractRef = `TTI/QA/GH-${suffix}`;
  const brand = `QA LIVE ${suffix}`;
  const supplier = `QA BAG SUPPLIER ${suffix}`;
  const lotRef = `LOT-GH-${suffix}`;
  const serialBase = Number(RUN_ID.slice(-6)) % 999_998;
  const containerOne = isoContainer('TGHU', serialBase || 1);
  const containerTwo = isoContainer('TGHU', (serialBase || 1) + 1);
  const tomorrow = new Date(Date.now() + 30 * 86400_000).toISOString().slice(0, 10);

  page.on('popup', async popup => popup.close().catch(() => {}));
  page.on('dialog', async dialog => dialog.dismiss());

  await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel('Username').fill(QA_USERNAME);
  await page.getByLabel('Password').fill(QA_PASSWORD);
  await Promise.all([
    page.waitForURL(url => !url.pathname.endsWith('/login.php'), { timeout: 30_000 }),
    page.getByRole('button', { name: 'Sign in' }).click(),
  ]);
  expect(page.url(), 'QA user must not land in Super Admin').toMatch(/module\.php\?id=(milling|exports)|staff-home\.php/);
  expect(page.url(), 'QA account must not land in Super Admin Control Centre').not.toMatch(/\/index\.php$/);

  await page.goto(`${BASE_URL}/module.php?id=exports`, { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'TRANSTRADE EXPORTS' })).toBeVisible();
  await expect(page.locator('#refreshMillUpdates')).toHaveCount(0);
  await expect(page.getByRole('button', { name: /MASTER DATA/i })).toHaveCount(1);
  await page.screenshot({ path: testInfo.outputPath('01-exports-desktop.png'), fullPage: true });

  await page.setViewportSize({ width: 390, height: 844 });
  const mobileOverflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(mobileOverflow, 'Exports mobile layout must not overflow horizontally').toBeLessThanOrEqual(2);
  await page.screenshot({ path: testInfo.outputPath('02-exports-mobile.png'), fullPage: true });
  await page.setViewportSize({ width: 1440, height: 1000 });

  await page.getByRole('button', { name: /NEW SALES CONTRACT/i }).click();
  await page.locator('#addCustomer').click();
  await page.locator('#mCustName').fill(customerName);
  await page.locator('#mCustCode').fill(customerCode);
  await page.locator('#mCustAddress').fill('QA ONLY — automated Hostinger live-test customer');
  await page.locator('#mCustCountry').fill('Pakistan');
  await page.locator('#saveNewCustomer').click();
  await page.locator('#cRef').fill(contractRef);
  await page.locator('#nextStep').click();

  await page.locator('#cProduct').selectOption({ index: 1 });
  await page.locator('#cBroken').fill('5');
  await page.locator('#cFinish').fill('Silky polished and sortexed — QA TEST');
  await page.locator('#nextStep').click();

  await page.locator('#cContainers').fill('2');
  await page.locator('#cWeightPer').fill('26');
  await page.locator('#cShipmentDate').fill(tomorrow);
  await page.locator('#cPOD').fill('Jebel Ali');
  await page.locator('#cPODCountry').fill('United Arab Emirates');
  await page.locator('#nextStep').click();

  await page.locator('#addPacking').click();
  await page.locator('#mPackType').fill('PP Bags');
  await page.locator('#mPackSize').fill('25');
  await page.locator('#mPackBrand').fill(brand);
  await page.locator('#mPackTare').fill('80');
  await page.locator('#mPackContainers').fill('2');
  await page.locator('#mPackWeight').fill('26');
  await page.locator('#mPackExtra').fill('1');
  await page.locator('#savePacking').click();
  await page.locator('#nextStep').click();

  await page.locator('#cIncoterm').selectOption('FOB');
  await page.locator('[data-contract-rate="0"]').fill('400');
  await page.locator('#nextStep').click();
  await page.locator('#cPayment').selectOption('ADV100');
  await page.locator('#nextStep').click();
  await page.locator('#nextStep').click();
  await page.locator('#issueContract').click();
  await expect(page.getByText(contractRef, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
  await waitForSharedSave(page);

  const processCard = page.locator('article.contractCard').filter({ hasText: contractRef });
  await processCard.getByRole('button', { name: 'Shipment Process' }).click();
  await page.locator('[data-workspace="contract"]').click();
  await page.locator('#markReceived').click();

  await page.locator('[data-workspace="bags"]').click();
  await page.locator('#addSupplier').click();
  await page.locator('#newSupplierName').fill(supplier);
  await page.locator('#saveSupplier').click();
  await page.locator('#boSupplier').selectOption({ label: supplier });
  await page.locator('[data-bo-art="0"]').setInputFiles('tests/live/qa-bag-mark.png');
  await expect(page.getByText('APPROVED ON UPLOAD')).toBeVisible({ timeout: 30_000 });
  await page.locator('#generatePO').click();
  await waitForSharedSave(page);
  const poPrintModal = page.locator('.modalBackdrop').filter({ hasText: /Print \/ Save/i }).last();
  await expect(poPrintModal, 'Bag PO print preview must open after the explicit save').toBeVisible();
  await poPrintModal.locator('[data-modal-close]').first().click();
  await expect(poPrintModal).toHaveCount(0);

  await page.locator('[data-workspace="production"]').click();
  await page.locator('#sendPI').click();
  await waitForSharedSave(page);

  await page.locator('[data-workspace="loading"]').click();
  await page.locator('#liLot').fill(lotRef);
  await page.locator('[data-li-name="0"]').fill('TTI Rice Mills');
  await page.locator('[data-li-type="0"]').selectOption('TTI');
  await page.locator('[data-li-cont="0"]').fill('2');
  await page.locator('[data-li-weight="0"]').fill('26');
  if (await page.locator('#liPhysicalContainers').count()) await page.locator('#liPhysicalContainers').fill('2');
  await page.locator('#sendLoading').click();
  await expect(page.getByRole('heading', { name: new RegExp(lotRef) })).toBeVisible({ timeout: 30_000 });
  await waitForSharedSave(page);
  await page.screenshot({ path: testInfo.outputPath('03-loading-instruction-sent.png'), fullPage: true });

  await page.goto(`${BASE_URL}/module.php?id=milling`, { waitUntil: 'domcontentloaded' });
  if (await page.locator('.mill-card').count()) await page.locator('.mill-card').filter({ hasText: /TTI Rice Mills/i }).first().click();
  await page.locator('.tile[onclick="openPanel(\'export\')"]').click();
  await expect(page.getByText(brand, { exact: false }).first()).toBeVisible({ timeout: 30_000 });
  const shipmentRow = page.locator('#shipmentBody tr[data-shipment-id]').filter({ hasText: brand }).first();
  await shipmentRow.click();
  await expect(page.locator('#instructionBanner')).toContainText(lotRef);

  await fillMillContainerNumber(page, containerOne);
  await page.locator('#contTruck').fill('KHI-1001');
  await page.locator('#contWeight').fill('26000');
  await page.locator('#contBags').fill('1040');
  await page.locator('#saveContainerBtn').click();
  await expect(page.locator('#containerFeedback')).toContainText('Seal Number is required');
  await page.locator('#contSeal').fill(`QA-S-${suffix}-1`);
  await page.locator('#contDriver').fill('QA DRIVER');
  await page.locator('#saveContainerBtn').click();
  await expect(page.locator('#containerTable')).toContainText(containerOne, { timeout: 35_000 });

  await fillMillContainerNumber(page, containerOne);
  await page.locator('#contTruck').fill('KHI-1002');
  await page.locator('#contWeight').fill('26000');
  await page.locator('#contBags').fill('1040');
  await page.locator('#contSeal').fill(`QA-S-${suffix}-2`);
  await page.locator('#saveContainerBtn').click();
  await expect(page.locator('#containerFeedback')).toContainText(/already exists|Changing only the check digit/i);
  await fillMillContainerNumber(page, containerTwo);
  await page.locator('#saveContainerBtn').click();
  await expect(page.locator('#containerTable')).toContainText(containerTwo, { timeout: 35_000 });
  await page.screenshot({ path: testInfo.outputPath('04-milling-containers-saved.png'), fullPage: true });

  await page.goto(`${BASE_URL}/module.php?id=exports`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(({ contractRef, lotRef, numbers }) => {
    const root = JSON.parse(localStorage.getItem('transtrade_export_v3_operational') || '{}');
    const lot = (root.shipments || []).find(x => x.contractRef === contractRef && x.lotId === lotRef);
    const actuals = lot?.millActuals || [];
    return numbers.every(number => actuals.some(x => x.number === number));
  }, { contractRef, lotRef, numbers: [containerOne, containerTwo] }, { timeout: 35_000 });

  await page.locator('#homeSearch').fill(contractRef);
  const returnedCard = page.locator('article.contractCard').filter({ hasText: contractRef });
  await returnedCard.locator('[data-open-lot]').first().click();

  // Customs is a separate workflow and must not display Mill container/seal actuals.
  await page.locator('[data-workspace="customs"]').click();
  await expect(page.getByText('MILL CONTAINER ACTUALS', { exact: true })).toHaveCount(0);
  await expect(page.getByText(containerOne, { exact: false })).toHaveCount(0);
  await expect(page.getByText(containerTwo, { exact: false })).toHaveCount(0);

  // The exact Mill return belongs in the B/L Draft workflow.
  await page.locator('[data-workspace="bl"]').click();
  await expect(page.getByRole('heading', { name: 'B/L DOCUMENTS' })).toBeVisible();
  await expect(page.getByText(containerOne, { exact: false }).first()).toBeVisible();
  await expect(page.getByText(containerTwo, { exact: false }).first()).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('05-bl-draft-container-return.png'), fullPage: true });
});
