// Each automatic run uses its GitHub run ID to create isolated, clearly labelled QA records.
const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TRANSTRADE_BASE_URL || 'https://app.transtradeinternational.com';
const QA_USERNAME = process.env.TRANSTRADE_QA_USERNAME;
const QA_PASSWORD = process.env.TRANSTRADE_QA_PASSWORD;
const RUN_ID = String(process.env.GITHUB_RUN_ID || Date.now());
const RUN_ATTEMPT = String(process.env.GITHUB_RUN_ATTEMPT || '1');
const RUN_TOKEN = `${RUN_ID}${RUN_ATTEMPT}`.replace(/\D/g, '');

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
  await expect(page.locator('#saveBadge')).toContainText(/\bSaved\b/i, { timeout: 35_000 });
}

async function gotoLive(page, url, options = {}) {
  let lastError;
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    try {
      return await page.goto(url, options);
    } catch (error) {
      lastError = error;
      if (attempt < 3) await page.waitForTimeout(1_000 * attempt);
    }
  }
  throw lastError;
}

async function fillMillContainerNumber(page, value) {
  const raw = String(value).toUpperCase().replace(/[^A-Z0-9]/g, '');
  await page.locator('.tt-container-main').fill(raw.slice(0, 10));
  await page.locator('.tt-container-check').fill(raw.slice(10, 11));
  await expect(page.locator('#contNo')).toHaveValue(`${raw.slice(0, 10)}-${raw.slice(10, 11)}`);
}

async function signInQa(page) {
  expect(QA_USERNAME, 'TRANSTRADE_QA_USERNAME GitHub secret is required').toBeTruthy();
  expect(QA_PASSWORD, 'TRANSTRADE_QA_PASSWORD GitHub secret is required').toBeTruthy();
  await gotoLive(page, `${BASE_URL}/login.php`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel('Username').fill(QA_USERNAME);
  await page.getByLabel('Password').fill(QA_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect.poll(
    () => new URL(page.url()).pathname,
    { message: 'QA sign-in must leave the login page', timeout: 30_000 },
  ).not.toBe('/login.php');
  const landing = new URL(page.url());
  expect(landing.pathname, 'QA account must not land in Super Admin Control Centre').not.toBe('/index.php');
  expect(`${landing.pathname}${landing.search}`, 'QA user must land in an authorized staff module').toMatch(
    /module\.php\?id=(milling|exports)|staff-home\.php|\/accounts\/index\.php/,
  );
}

test.use({
  viewport: { width: 1440, height: 1000 },
  trace: 'retain-on-failure',
  screenshot: 'only-on-failure',
  video: 'retain-on-failure',
});

async function createBulkQaShipment(page, { suffix, index, lotRef, contractRef, brand }) {
  const customerName = `QA BULK ${suffix} ${index}`;
  const customerCode = `QB${index}${suffix.slice(-3)}`;
  const supplier = `QA BULK BAG SUPPLIER ${suffix} ${index}`;
  const shipmentDate = new Date(Date.now() + (30 + index) * 86400_000).toISOString().slice(0, 10);

  await gotoLive(page, `${BASE_URL}/module.php?id=exports`, { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('button', { name: /NEW SALES CONTRACT/i })).toBeVisible();
  await page.getByRole('button', { name: /NEW SALES CONTRACT/i }).click();
  await page.locator('#addCustomer').click();
  await page.locator('#mCustName').fill(customerName);
  await page.locator('#mCustCode').fill(customerCode);
  await page.locator('#mCustAddress').fill('TEST / DUMMY — automated multi-shipment bulk QA only');
  await page.locator('#mCustCountry').fill('Pakistan');
  await page.locator('#saveNewCustomer').click();
  await page.locator('#cRef').fill(contractRef);
  await page.locator('#nextStep').click();

  await page.locator('#cProductIdentity').selectOption({ index: 1 });
  await page.locator('#cBrokenContract').fill('5');
  await page.locator('#cFinishContract').selectOption({ index: 0 });
  await page.locator('#nextStep').click();

  await page.locator('#cContainers').fill('1');
  await page.locator('#cWeightPer').fill('26');
  await page.locator('#cShipmentDate').fill(shipmentDate);
  await page.locator('#cPOD').fill('Jebel Ali');
  await page.locator('#cPODCountry').fill('United Arab Emirates');
  await page.locator('#nextStep').click();

  await page.locator('#addPacking').click();
  await page.locator('#mPackType').selectOption({ label: 'P.P. Bags' });
  await page.locator('#mPackSize').fill('25');
  await page.locator('#mPackBrand').fill(brand);
  await page.locator('#mPackTare').fill('80');
  await page.locator('#mPackContainers').fill('1');
  await page.locator('#mPackExtra').fill('1');
  await page.locator('#nextStep').click();

  await page.locator('#cIncoterm').selectOption('FOB');
  await page.locator('[data-contract-rate="0"]').fill(String(400 + index));
  await page.locator('#nextStep').click();
  await page.locator('#cPayment').selectOption('ADV100');
  await page.locator('#nextStep').click();
  await page.locator('#cSignedDeadline').fill(shipmentDate);
  await page.locator('#cPaymentDeadline').fill(shipmentDate);
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
  await expect(page.locator('img.artPreview').first()).toBeVisible({ timeout: 30_000 });
  await page.locator('#generatePO').click();
  await waitForSharedSave(page);

  await page.locator('[data-workspace="production"]').click();
  await page.locator('#sendPI').click();
  await waitForSharedSave(page);

  await page.locator('[data-workspace="loading"]').click();
  await page.locator('#liDate').fill(shipmentDate);
  await page.locator('#liProgramme').fill(`QA-LP-${suffix}-${index}`);
  await page.locator('[data-li-name="0"]').fill('TTI Rice Mills');
  await page.locator('[data-li-cont="0"]').fill('1');
  await page.locator('[data-li-weight="0"]').fill('26');
  if (await page.locator('#liPhysicalContainers').count()) await page.locator('#liPhysicalContainers').fill('1');
  await page.locator('#liIntendedVessel').fill(`QA VESSEL ${suffix}`);
  await page.locator('#liShippingLine').fill(`QA SHIPPING LINE ${suffix}`);
  await page.locator('#sendLoading').click();
  await waitForSharedSave(page);
}


test('manual Hostinger QA: Export instruction to Mill and container return', async ({ page }, testInfo) => {
  test.setTimeout(300_000);
  const exportPageErrors = [];
  page.on('pageerror', error => exportPageErrors.push(String(error)));
  const suffix = RUN_TOKEN.slice(-8);
  const customerName = `QA GITHUB ${suffix}`;
  const customerCode = `Q${suffix.slice(-5)}`;
  const contractRef = `TTI/QA/GH-${suffix}`;
  const brand = `QA LIVE ${suffix}`;
  const supplier = `QA BAG SUPPLIER ${suffix}`;
  const lotRef = `${contractRef}/L01`;
  const serialBase = Number(RUN_TOKEN.slice(-6)) % 999_998;
  const containerOne = isoContainer('TGHU', serialBase || 1);
  const containerTwo = isoContainer('TGHU', (serialBase || 1) + 1);
  const tomorrow = new Date(Date.now() + 30 * 86400_000).toISOString().slice(0, 10);

  page.on('popup', async popup => popup.close().catch(() => {}));
  page.on('dialog', async dialog => dialog.dismiss().catch(() => {}));

  await signInQa(page);

  await gotoLive(page, `${BASE_URL}/module.php?id=exports`, { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('button', { name: /NEW SALES CONTRACT/i })).toBeVisible();
  await expect(page.locator('.ttBrandLogo').first(), 'approved Transtrade logo must render').toBeVisible();
  await expect(page.locator('#refreshMillUpdates')).toHaveCount(0);
  await expect(page.getByRole('button', { name: /MASTER DATA/i })).toHaveCount(1);
  await page.screenshot({ path: testInfo.outputPath('01-exports-desktop.png'), fullPage: true });

  await page.setViewportSize({ width: 390, height: 844 });
  const mobileOverflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(mobileOverflow, 'Exports mobile layout must not overflow horizontally').toBeLessThanOrEqual(2);
  await page.screenshot({ path: testInfo.outputPath('02-exports-mobile.png'), fullPage: true });
  await page.setViewportSize({ width: 1440, height: 1000 });

  await page.getByRole('button', { name: /NEW SALES CONTRACT/i }).click();
  await expect(page.locator('.contractFormPane'), 'Sales Contract entry pane must render').toBeVisible();
  await expect(page.locator('.contractPreviewPane'), 'Sales Contract live preview pane must render beside entry').toBeVisible();
  await page.locator('#addCustomer').click();
  await page.locator('#mCustName').fill(customerName);
  await page.locator('#mCustCode').fill(customerCode);
  await page.locator('#mCustAddress').fill('QA ONLY — automated Hostinger live-test customer');
  await page.locator('#mCustCountry').fill('Pakistan');
  await page.locator('#saveNewCustomer').click();
  await page.locator('#cRef').fill(contractRef);
  await page.locator('#nextStep').click();

  await page.locator('#cProductIdentity').selectOption({ index: 1 });
  await page.locator('#cBrokenContract').fill('5');
  await page.locator('#cFinishContract').selectOption({ index: 0 });
  await page.locator('#nextStep').click();

  await page.locator('#cContainers').fill('2');
  await page.locator('#cWeightPer').fill('26');
  await page.locator('#cShipmentDate').fill(tomorrow);
  await page.locator('#cPOD').fill('Jebel Ali');
  await page.locator('#cPODCountry').fill('United Arab Emirates');
  await page.locator('#nextStep').click();

  await page.locator('#addPacking').click();
  await expect(page.locator('#mPackType option', { hasText: 'P.P. Bags' }), 'managed Packing Type dropdown must contain P.P. Bags').toHaveCount(1);
  await page.locator('#mPackType').selectOption({ label: 'P.P. Bags' });
  await page.locator('#mPackSize').fill('25');
  await page.locator('#mPackBrand').fill(brand);
  await page.locator('#mPackTare').fill('80');
  await page.locator('#mPackContainers').fill('2');
  await page.locator('#mPackExtra').fill('1');
  await page.locator('#nextStep').click();

  await page.locator('#cIncoterm').selectOption('FOB');
  await page.locator('[data-contract-rate="0"]').fill('400');
  await page.locator('#nextStep').click();
  await page.locator('#cPayment').selectOption('ADV100');
  await page.locator('#nextStep').click();
  await page.locator('#cSignedDeadline').fill(tomorrow);
  await page.locator('#cPaymentDeadline').fill(tomorrow);
  await page.locator('#nextStep').click();
  const contractPreview = page.locator('#salesContractPreview');
  await expect(contractPreview).toContainText('PORT OF LOADING');
  await expect(contractPreview).toContainText('PORT OF DISCHARGE');
  await expect(contractPreview).toContainText('INSURANCE');
  await expect(contractPreview).toContainText('PACKING / BRAND-MARKING');
  await expect(contractPreview).toContainText('PACKED IN NEW SINGLE P.P. BAGS OF 25 KG EACH');
  await expect(contractPreview).toContainText(brand);
  await expect(contractPreview).toContainText('FOB UNIT PRICE');
  await expect(contractPreview).toContainText('TOTAL CONTRACT VALUE');
  await expect(contractPreview).toContainText('AMOUNT IN WORDS');
  await expect(contractPreview).toContainText('OTHER TERMS AND CONDITIONS');
  await expect(contractPreview).toContainText('DOCUMENTS TO BE PRESENTED FOR NEGOTIATION');
  const previewPages = await contractPreview.locator('.salesContractPage').count();
  expect(previewPages, 'Sales Contract preview must paginate from content').toBeGreaterThanOrEqual(2);
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
  await expect(page.locator('img.artPreview').first()).toBeVisible({ timeout: 30_000 });
  await page.locator('#generatePO').click();
  await waitForSharedSave(page);

  await page.locator('[data-workspace="production"]').click();
  await page.locator('#sendPI').click();
  await waitForSharedSave(page);

  await page.locator('[data-workspace="loading"]').click();
  await page.locator('#liDate').fill(tomorrow);
  await page.locator('#liProgramme').fill(`QA-LP-${suffix}`);
  await page.locator('[data-li-name="0"]').fill('TTI Rice Mills');
  await page.locator('[data-li-cont="0"]').fill('2');
  await page.locator('[data-li-weight="0"]').fill('26');
  if (await page.locator('#liPhysicalContainers').count()) await page.locator('#liPhysicalContainers').fill('2');
  await page.locator('#liIntendedVessel').fill(`QA VESSEL ${suffix}`);
  await page.locator('#liShippingLine').fill(`QA SHIPPING LINE ${suffix}`);
  await page.locator('#sendLoading').click();
  await waitForSharedSave(page);
  await page.screenshot({ path: testInfo.outputPath('03-loading-instruction-sent.png'), fullPage: true });

  await gotoLive(page, `${BASE_URL}/module.php?id=milling`, { waitUntil: 'domcontentloaded' });
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

  await gotoLive(page, `${BASE_URL}/module.php?id=exports`, { waitUntil: 'domcontentloaded' });
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

  // Every lot workspace must open and render after the round trip.
  for (const workspace of ['customs', 'bl', 'commercial', 'coo', 'certs', 'cover', 'output', 'history']) {
    const control = page.locator(`[data-workspace="${workspace}"]`);
    if (await control.count()) {
      await control.click();
      await expect(page.locator('.workspaceDetail').first(), `${workspace} workspace must render`).toBeVisible();
      expect((await page.locator('.workspaceDetail').first().innerText()).trim().length).toBeGreaterThan(10);
    }
  }

  // Open every Exports navigation page and every report/register icon.
  for (const nav of ['home', 'contracts', 'completed', 'cancelled', 'fi', 'reports']) {
    const control = page.locator(`[data-nav="${nav}"]`);
    await expect(control, `${nav} navigation icon must exist`).toBeVisible();
    await control.click();
    await expect(control).toHaveClass(/active/);
    expect((await page.locator('#main').innerText()).trim().length).toBeGreaterThan(10);
    if (nav === 'reports') {
      const reportControls = page.locator('[data-report]');
      const reportCount = await reportControls.count();
      expect(reportCount, 'all Export report/register icons must be present').toBeGreaterThanOrEqual(15);
      for (let i = 0; i < reportCount; i += 1) {
        await reportControls.nth(i).click();
        await expect(page.locator('#reportDetail h3')).toBeVisible();
      }
    }
  }

  const masterData = page.locator('#ttMasterTop');
  await expect(masterData, 'single Master Data icon must be available').toBeVisible();
  await masterData.click();
  await expect(page.locator('#ttCustomerMasterOverlay')).toBeVisible();
  const masterIcons = page.locator('[data-cm-master]');
  await expect(masterIcons.first(), 'Master Data categories must finish loading').toBeVisible({ timeout: 20_000 });
  const masterCount = await masterIcons.count();
  expect(masterCount, 'Master Data categories must render').toBeGreaterThanOrEqual(8);
  for (let i = 0; i < masterCount; i += 1) {
    await masterIcons.nth(i).click();
    await expect(page.locator('#ttCmMasterDetail')).not.toHaveText('');
  }
  await page.locator('[data-cm-close]').click();
  expect(exportPageErrors, 'Exports pages must not throw JavaScript errors').toEqual([]);
});

test('live bulk QA: automatic lot references and isolated B/L returns', async ({ page }, testInfo) => {
  test.setTimeout(720_000);
  const errors = [];
  page.on('pageerror', error => errors.push(String(error)));
  page.on('popup', async popup => popup.close().catch(() => {}));
  page.on('dialog', async dialog => dialog.dismiss().catch(() => {}));

  const suffix = RUN_TOKEN.slice(-7);
  const sameLot = `LOT-SAME-${suffix}`;
  const differentLot = `LOT-DIFF-${suffix}`;
  const serialBase = (Number(RUN_TOKEN.slice(-6)) + 100) % 999_996 || 100;
  const shipments = [
    {
      index: 1,
      contractRef: `TTI/QA/BULK-${suffix}-A`,
      lotRef: `TTI/QA/BULK-${suffix}-A/L01`,
      brand: `QA DUMMY BULK ${suffix} A`,
      container: isoContainer('QABU', serialBase),
    },
    {
      index: 2,
      contractRef: `TTI/QA/BULK-${suffix}-B`,
      lotRef: `TTI/QA/BULK-${suffix}-B/L01`,
      brand: `QA DUMMY BULK ${suffix} B`,
      container: isoContainer('QACU', serialBase + 1),
    },
    {
      index: 3,
      contractRef: `TTI/QA/BULK-${suffix}-C`,
      lotRef: `TTI/QA/BULK-${suffix}-C/L01`,
      brand: `QA DUMMY BULK ${suffix} C`,
      container: isoContainer('QADU', serialBase + 2),
    },
  ];

  await signInQa(page);
  for (const shipment of shipments) {
    await createBulkQaShipment(page, { suffix, ...shipment });
  }
  await page.screenshot({ path: testInfo.outputPath('09-three-loading-instructions.png'), fullPage: true });

  await gotoLive(page, `${BASE_URL}/module.php?id=milling`, { waitUntil: 'domcontentloaded' });
  if (await page.locator('.mill-card').count()) {
    await page.locator('.mill-card').filter({ hasText: /TTI Rice Mills/i }).first().click();
  }
  await page.locator(".tile[onclick=\"openPanel('export')\"]").click();

  const processed = [];
  for (const shipment of [shipments[1], shipments[2], shipments[0]]) {
    const row = page.locator('#shipmentBody tr[data-shipment-id]').filter({ hasText: shipment.brand }).first();
    await expect(row, `${shipment.contractRef} loading instruction must reach Milling`).toBeVisible({ timeout: 35_000 });
    await row.click();
    await expect(page.locator('#instructionBanner')).toContainText(shipment.lotRef);

    const beforeText = await page.locator('#containerTable').innerText();
    for (const otherContainer of processed) {
      expect(beforeText, `container from another shipment must not leak into ${shipment.contractRef}`).not.toContain(otherContainer);
    }

    await fillMillContainerNumber(page, shipment.container);
    await page.locator('#contTruck').fill(`QA-${suffix}-${shipment.index}`);
    await page.locator('#contWeight').fill('26000');
    await page.locator('#contBags').fill('1040');
    await page.locator('#contSeal').fill(`QA-DUMMY-SEAL-${suffix}-${shipment.index}`);
    await page.locator('#contDriver').fill(`QA DUMMY DRIVER ${shipment.index}`);
    await page.locator('#saveContainerBtn').click();
    await expect(page.locator('#containerTable')).toContainText(shipment.container, { timeout: 35_000 });

    const afterText = await page.locator('#containerTable').innerText();
    for (const otherContainer of processed) {
      expect(afterText, `saved container must remain isolated to ${shipment.contractRef}`).not.toContain(otherContainer);
    }
    processed.push(shipment.container);
  }
  await page.screenshot({ path: testInfo.outputPath('10-three-milling-container-returns.png'), fullPage: true });

  await gotoLive(page, `${BASE_URL}/module.php?id=exports`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(rows => {
    const root = JSON.parse(localStorage.getItem('transtrade_export_v3_operational') || '{}');
    return rows.every(row => {
      const exact = (root.shipments || []).filter(x => x.contractRef === row.contractRef && x.lotId === row.lotRef);
      if (exact.length !== 1) return false;
      const actuals = exact[0].millActuals || [];
      return actuals.some(x => x.number === row.container)
        && rows.filter(other => other.contractRef !== row.contractRef)
          .every(other => !actuals.some(x => x.number === other.container));
    });
  }, shipments.map(({ contractRef, lotRef, container }) => ({ contractRef, lotRef, container })), { timeout: 40_000 });

  for (const shipment of shipments) {
    await gotoLive(page, `${BASE_URL}/module.php?id=exports`, { waitUntil: 'domcontentloaded' });
    await page.locator('#homeSearch').fill(shipment.contractRef);
    const card = page.locator('article.contractCard').filter({ hasText: shipment.contractRef });
    await expect(card).toBeVisible();
    await card.locator('[data-open-lot]').first().click();

    await page.locator('[data-workspace="customs"]').click();
    const customsText = await page.locator('.workspaceDetail').first().innerText();
    expect(customsText, 'Customs must remain separate from Milling container actuals').not.toContain(shipment.container);

    await page.locator('[data-workspace="bl"]').click();
    await expect(page.getByRole('heading', { name: 'B/L DOCUMENTS' })).toBeVisible();
    const blText = await page.locator('.workspaceDetail').first().innerText();
    expect(blText, `B/L Draft must receive ${shipment.container}`).toContain(shipment.container);
    for (const other of shipments.filter(x => x.contractRef !== shipment.contractRef)) {
      expect(blText, `B/L Draft for ${shipment.contractRef} must exclude ${other.container}`).not.toContain(other.container);
    }
  }
  await page.screenshot({ path: testInfo.outputPath('11-isolated-bl-draft-return.png'), fullPage: true });
  expect(errors, 'multi-shipment bulk QA must not throw JavaScript errors').toEqual([]);
});

test('automatic bulk QA: every Milling page plus 15 Arrivals and Pohanch records', async ({ page, browser }, testInfo) => {
  test.setTimeout(480_000);
  const suffix = RUN_TOKEN.slice(-6);
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error)));
  page.on('popup', async popup => popup.close().catch(() => {}));
  page.on('dialog', async dialog => dialog.dismiss().catch(() => {}));

  await signInQa(page);
  await gotoLive(page, `${BASE_URL}/module.php?id=milling`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.mill-card, #home').first()).toBeVisible();
  if (await page.locator('.mill-card').count()) {
    await page.locator('.mill-card').filter({ hasText: /TTI Rice Mills/i }).first().click();
  }
  await expect(page.locator('#home')).toHaveClass(/active/);

  const pageMap = [
    ['stock', 'STOCK'],
    ['queue', 'Arrival List'],
    ['arrival', 'Arrival / Pohanch'],
    ['newbags', 'New Export Bags'],
    ['instructions', 'Exports Specifications'],
    ['production', 'Production'],
    ['export', 'Export Loading'],
    ['local', 'Local Sales'],
    ['petty', 'Petty Cash'],
    ['labour', 'Labour / Mill Expenses'],
    ['oldbags', 'USED BAGS'],
    ['reports', 'Reports'],
    ['masters', 'Master'],
    ['users', 'User'],
  ];
  for (const [panel, label] of pageMap) {
    const control = page.locator(`[onclick="openPanel('${panel}')"]`).filter({ visible: true }).first();
    if (await control.count()) {
      await control.click();
      await expect(page.locator(`#${panel}`), `${label} page must become active`).toHaveClass(/active/);
      const heading = page.locator(`#${panel} h2, #${panel} h3`).first();
      if (await heading.count()) await expect(heading).toBeVisible();
      const back = page.locator(`#${panel} .back`).first();
      if (await back.count()) await back.click();
      if (!await page.locator('#home').getAttribute('class').then(v => /active/.test(v || ''))) {
        const homeControl = page.locator(`[onclick="openPanel('home')"]`).filter({ visible: true }).first();
        if (await homeControl.count()) await homeControl.click();
      }
    }
  }

  // Verify the Ex-Mill icon itself opens its dedicated workspace.
  const changeMill = page.getByText(/Change Mill/i).first();
  if (await changeMill.count()) await changeMill.click();
  const exMill = page.locator('.mill-card').filter({ hasText: /^\s*🚚?\s*Ex-Mill/i }).first();
  if (await exMill.count()) {
    await exMill.click();
    await expect(page.locator('#exmill')).toHaveClass(/active/);
    const back = page.locator('#exmill .back').first();
    if (await back.count()) await back.click();
  }
  if (await page.locator('.mill-card').count()) {
    await page.locator('.mill-card').filter({ hasText: /TTI Rice Mills/i }).first().click();
  }

  await page.locator(`[onclick="openPanel('queue')"]`).filter({ visible: true }).first().click();
  const trucks = [];
  for (let i = 1; i <= 15; i += 1) {
    const truck = `QA-${suffix}${String(i).padStart(2, '0')}`;
    trucks.push(truck);
    await page.locator('#qVehicle').fill(truck);
    await page.locator('#qBags').fill(String(500 + i));
    await page.locator('#qWeight').fill(String(25_000 + i * 10));
    await page.locator('#qParty').fill(`QA BULK PARTY ${suffix}`);
    await page.locator('#qBroker').fill(`QA BULK BROKER ${suffix}`);
    await page.locator('#qStation').fill('QA KARACHI');
    await page.locator('#qBroken').fill('5');
    await page.locator('#queueSaveBtn').click();
    await expect(page.locator('#queueTable')).toContainText(truck);
  }
  await page.screenshot({ path: testInfo.outputPath('06-milling-15-arrivals.png'), fullPage: true });

  await page.locator('#queue .back').first().click();
  await page.locator(`[onclick="openPanel('arrival')"]`).filter({ visible: true }).first().click();
  for (let i = 0; i < trucks.length; i += 1) {
    await page.locator('#arrivalQueue').selectOption({ label: trucks[i] });
    await page.locator('#arrivalSoda').selectOption('26092');
    await page.locator('#karachiWeight').fill(String(24_900 + i * 10));
    await page.getByRole('button', { name: 'Save Pohanch' }).click();
    await expect(page.locator('#pohanchFeedback')).toContainText('Pohanch saved');
  }
  for (const truck of trucks) await expect(page.locator('#unprintedSlips')).toContainText(truck);
  await page.screenshot({ path: testInfo.outputPath('07-milling-15-pohanch.png'), fullPage: true });

  // Verify persistence from a clean browser context, not the page's own localStorage cache.
  const queueDate = await page.locator('#queueViewDate').inputValue();
  await page.waitForTimeout(4_000);
  const verifyContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const verifyPage = await verifyContext.newPage();
  await signInQa(verifyPage);
  await verifyPage.goto(`${BASE_URL}/module.php?id=milling`, { waitUntil: 'domcontentloaded' });
  if (await verifyPage.locator('.mill-card').count()) {
    await verifyPage.locator('.mill-card').filter({ hasText: /TTI Rice Mills/i }).first().click();
  }
  await verifyPage.locator(`[onclick="openPanel('queue')"]`).filter({ visible: true }).first().click();
  await verifyPage.locator('#queueViewDate').fill(queueDate);
  await verifyPage.locator('#queueViewDate').dispatchEvent('change');
  for (const truck of trucks) await expect(verifyPage.locator('#queueTable')).toContainText(truck);
  await verifyPage.locator('#queue .back').first().click();
  await verifyPage.locator(`[onclick="openPanel('arrival')"]`).filter({ visible: true }).first().click();
  for (const truck of trucks) await expect(verifyPage.locator('#unprintedSlips')).toContainText(truck);
  await verifyPage.screenshot({ path: testInfo.outputPath('08-milling-shared-reload.png'), fullPage: true });
  await verifyContext.close();

  expect(pageErrors, 'Milling pages must not throw JavaScript errors').toEqual([]);
});


test('live deletion survives sign-out and sign-in', async ({ page }) => {
  test.setTimeout(180_000);
  page.on('popup', async popup => popup.close().catch(() => {}));
  page.on('dialog', dialog => dialog.accept().catch(() => {}));

  const suffix = RUN_TOKEN.slice(-8);
  const customerName = `QA DELETE ${suffix}`;
  const contractRef = `TTI/QA/DELETE-${suffix}`;
  const shipmentDate = new Date(Date.now() + 30 * 86400_000).toISOString().slice(0, 10);

  await signInQa(page);
  await gotoLive(page, `${BASE_URL}/module.php?id=exports`, { waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: /NEW SALES CONTRACT/i }).click();
  await page.locator('#addCustomer').click();
  await page.locator('#mCustName').fill(customerName);
  await page.locator('#mCustCode').fill(`QD${suffix.slice(-4)}`);
  await page.locator('#mCustAddress').fill('TEST / DUMMY — persistent deletion QA only');
  await page.locator('#mCustCountry').fill('Pakistan');
  await page.locator('#saveNewCustomer').click();
  await page.locator('#cRef').fill(contractRef);
  await page.locator('#nextStep').click();

  await page.locator('#cProductIdentity').selectOption({ index: 1 });
  await page.locator('#cBrokenContract').fill('5');
  await page.locator('#cFinishContract').selectOption({ index: 0 });
  await page.locator('#nextStep').click();

  await page.locator('#cContainers').fill('1');
  await page.locator('#cWeightPer').fill('26');
  await page.locator('#cShipmentDate').fill(shipmentDate);
  await page.locator('#cPOD').fill('Jebel Ali');
  await page.locator('#cPODCountry').fill('United Arab Emirates');
  await page.locator('#nextStep').click();

  await page.locator('#addPacking').click();
  await page.locator('#mPackType').selectOption({ label: 'P.P. Bags' });
  await page.locator('#mPackSize').fill('25');
  await page.locator('#mPackBrand').fill(`QA DELETE BRAND ${suffix}`);
  await page.locator('#mPackTare').fill('80');
  await page.locator('#mPackContainers').fill('1');
  await page.locator('#mPackExtra').fill('1');
  await page.locator('#nextStep').click();

  await page.locator('#cIncoterm').selectOption('CFR');
  await page.locator('[data-contract-rate="0"]').fill('400');
  await page.locator('[data-freight="0"]').fill('20');
  await page.locator('#nextStep').click();
  await page.locator('#cPayment').selectOption('ADV100');
  await page.locator('#nextStep').click();
  await page.locator('#cSignedDeadline').fill(shipmentDate);
  await page.locator('#cPaymentDeadline').fill(shipmentDate);
  await page.locator('#nextStep').click();
  await page.locator('#issueContract').click();
  await waitForSharedSave(page);

  let card = page.locator('article.contractCard').filter({ hasText: contractRef });
  await expect(card).toHaveCount(1);
  await card.getByRole('button', { name: /Cancel \/ Delete Shipment/i }).click();
  await page.getByRole('button', { name: /Yes — Delete Shipment/i }).click();
  await expect(page.locator('#shipmentDeleteError')).toHaveCount(0);
  await expect(page.locator('article.contractCard').filter({ hasText: contractRef })).toHaveCount(0, { timeout: 35_000 });
  await waitForSharedSave(page);

  await gotoLive(page, `${BASE_URL}/logout.php`, { waitUntil: 'domcontentloaded' });
  await signInQa(page);
  await gotoLive(page, `${BASE_URL}/module.php?id=exports`, { waitUntil: 'domcontentloaded' });
  card = page.locator('article.contractCard').filter({ hasText: contractRef });
  await expect(card, 'server-confirmed deleted shipment must not return after a new login').toHaveCount(0);
});
