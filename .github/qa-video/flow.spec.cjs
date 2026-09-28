const { test, expect } = require('@playwright/test');
const base = 'http://127.0.0.1:8765';
const ref = 'QA/TTI/LGT/07';

async function caption(page, title, detail, ms = 1700) {
  await page.evaluate(({ title, detail }) => {
    document.querySelector('#qaVideoCaption')?.remove();
    const box = document.createElement('aside');
    box.id = 'qaVideoCaption';
    box.style.cssText = 'position:fixed;z-index:999999;bottom:14px;left:14px;max-width:720px;background:#12364f;color:white;border:2px solid #63cda0;border-radius:12px;padding:13px 17px;font:18px/1.35 Arial;box-shadow:0 7px 28px #0007';
    box.innerHTML = '<b>' + title + '</b><br><span style="font-size:15px">' + detail + '</span>';
    document.body.appendChild(box);
  }, { title, detail });
  await page.waitForTimeout(ms);
}

async function login(page) {
  await page.goto(base + '/login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="username"]').fill('qa.video');
  await page.locator('input[name="password"]').fill(process.env.QA_VIDEO_PASSWORD);
  await page.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/accounts\/index\.php/, { timeout: 20000 });
}

async function chooseAction(page, name) {
  for (let i = 0; i < 5; i++) {
    const open = page.locator('.tt-layer:not([hidden]) .tt-window-close');
    if (!await open.count()) break;
    await open.last().click();
  }
  const back = page.locator('#ttDeskWork .tt-back-areas');
  if (await back.isVisible().catch(() => false)) await back.click();
  await page.locator('[data-tt-area="exports"]').click();
  await page.locator('#ttDeskWork .tt-action').filter({ hasText: name }).first().click();
}

async function openBill(page, kind) {
  await chooseAction(page, kind);
  if (kind === 'Freight Forwarder / Shipping') await page.locator('#ttFreightInvoice').click();
  await page.locator('#ttBillShipmentQuery').fill(ref);
  await page.locator('#ttBillShipmentGo').click();
  await expect(page.locator('[data-tt-pick-shipment]').first()).toBeVisible();
  await caption(page, 'Exports link', 'The contract, B/L, invoice, loading programme and containers came from the seeded Exports shipment.');
  await page.locator('[data-tt-pick-shipment]').first().click();
  await expect(page.locator('#ttShipmentBillEntry')).toBeVisible();
}

async function closeLayer(page) {
  await page.locator('.tt-layer:visible .tt-window-close').last().click();
  await page.waitForTimeout(400);
}

test('Exports, Milling and Accounts: linked local QA walkthrough', async ({ page }) => {
  test.setTimeout(180000);
  page.setDefaultTimeout(12000);
  await login(page);
  await caption(page, 'Three-module QA walkthrough', 'Disposable localhost copy. The shipment facts are based on the supplied TTI/LGT/07 invoice; QA references and supplier charges are illustrative.');

  await page.goto(base + '/module.php?id=exports', { waitUntil: 'domcontentloaded' });
  await caption(page, 'Exports', 'The QA/TTI/LGT/07 contract and lot are the source for Accounts shipment lookup.');
  await page.locator('#homeSearch').fill(ref);
  await page.waitForTimeout(1000);
  await expect(page.locator('[data-open-lot="QA-SHIP-LGT-01"]')).toBeVisible();
  await caption(page, 'Shipment reference', 'Commercial invoice USD 108,000 and customer Ladoo General Trading LLC mirror the supplied invoice. QA prefixes prevent confusion with business records.');

  await page.goto(base + '/module.php?id=milling', { waitUntil: 'domcontentloaded' });
  await page.locator('.mill-card').first().click();
  await page.locator('.tile').filter({ hasText: 'Export Loading' }).first().click();
  await expect(page.locator('#shipmentBody')).toContainText('marvarid');
  await expect(page.locator('#shipmentBody')).toContainText('1350');
  await caption(page, 'Milling export loading', 'The Exports loading instruction for the QA lot appears at TTI Rice Mills: two representative containers at 27 MT.');

  await page.locator('#shipmentBody tr[data-shipment-id]').first().click();
  await expect(page.locator('#instructionBanner')).toContainText(ref);
  for (const [number, truck, seal] of [
    ['QAVU000001-6', 'QA-KHI-1001', 'QA-SEAL-01'],
    ['QAVU000002-1', 'QA-KHI-1002', 'QA-SEAL-02']
  ]) {
    await page.locator('.tt-container-main').fill(number.slice(0, 10));
    await page.locator('.tt-container-check').fill(number.slice(11));
    await page.locator('#contTruck').fill(truck);
    await page.locator('#contWeight').fill('27000');
    await page.locator('#contBags').fill('1350');
    await page.locator('#contSeal').fill(seal);
    await page.locator('#contDriver').fill('QA DRIVER');
    await caption(page, 'Milling container actual', number + ': 1,350 bags and 27,000 kg. This is an illustrative test container.');
    await page.locator('#saveContainerBtn').click();
    await expect(page.locator('#containerTable')).toContainText(number);
  }
  await page.goto(base + '/module.php?id=exports', { waitUntil: 'domcontentloaded' });
  await expect.poll(async () => page.evaluate(async () => {
    const response = await fetch('api/operations.mysql.php?r=' + Date.now());
    const data = await response.json();
    const root = JSON.parse(data.values?.transtrade_export_v3_operational || '{}');
    const lot = (root.shipments || []).find(x => x.id === 'QA-SHIP-LGT-01');
    return (lot?.millActuals || []).length;
  }), { timeout: 20000 }).toBe(2);
  await caption(page, 'Milling return to Exports', 'Both container actuals now appear against the same QA lot in the shared Exports record.');

  await page.goto(base + '/accounts/index.php', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-tt-area="exports"]')).toBeVisible();
  await caption(page, 'Accounts', 'Open Export Receipts & Payments to record the agreed freight and supplier invoices.');

  await chooseAction(page, 'Freight Forwarder / Shipping');
  await page.locator('#ttFreightAgreementOpen').click();
  await page.locator('#ttFreightShipmentQuery').fill(ref);
  await expect(page.locator('[data-freight-shipment]').first()).toBeVisible();
  await page.locator('[data-freight-shipment]').last().click();
  await page.locator('[name="forwarder"]').fill('Paklink QA Forwarder');
  await page.locator('[name="ratePerContainer"]').fill('1200');
  await page.locator('[name="containerCount"]').fill('2');
  await caption(page, 'Freight agreed', 'USD 1,200 per container is an illustrative planning rate. The agreement does not create a journal.');
  await page.locator('#ttFreightAgreementForm button[type="submit"]').click();
  await page.waitForTimeout(700);
  await closeLayer(page);
  await closeLayer(page); // Close the parent Freight chooser as well.

  await openBill(page, 'Freight Forwarder / Shipping');
  await page.locator('#ttShipmentBillVendor').fill('Paklink QA Forwarder');
  await page.locator('[name="invoiceNo"]').fill('QA-PAKLINK-001');
  await page.locator('[name="exchangeRate"]').fill('280');
  let line = page.locator('.tt-shipment-charge').first();
  await line.locator('[data-description]').fill('Ocean freight');
  await line.locator('[data-basis]').selectOption('PER_CONTAINER');
  await line.locator('[data-currency]').selectOption('USD');
  await line.locator('[data-amount]').fill('1200');
  await page.locator('#ttShipmentBillAdd').click();
  line = page.locator('.tt-shipment-charge').last();
  await line.locator('[data-description]').fill('Document charge');
  await line.locator('[data-currency]').selectOption('PKR');
  await line.locator('[data-amount]').fill('5000');
  await caption(page, 'Freight bill', 'USD rate × 2 containers at invoice exchange rate 280, plus a PKR charge. Both rates are illustrative.');
  await page.locator('#ttShipmentBillEntry button[type="submit"]').click();
  await expect(page.getByText('Supplier bill posted')).toBeVisible();
  await caption(page, 'Freight voucher', 'The posting number and journal appear after saving. The supplier liability is now in the isolated Accounts ledger.');
  await closeLayer(page);

  for (const item of [
    ['Clearing Agent','QA Clearing Agent','QA-CLEAR-001',18000],
    ['Fumigation Bill','QA Fumigator','QA-FUM-001',8000],
    ['Inspection Bill','QA Inspector','QA-INSP-001',11000]
  ]) {
    await openBill(page, item[0]);
    await page.locator('#ttShipmentBillVendor').fill(item[1]);
    await page.locator('[name="invoiceNo"]').fill(item[2]);
    const first=page.locator('.tt-shipment-charge').first();
    await first.locator('[data-amount]').fill(String(item[3]));
    await page.locator('#ttShipmentBillAdd').click();
    const extra=page.locator('.tt-shipment-charge').last();
    await extra.locator('[data-description]').fill('Additional service charge');
    await extra.locator('[data-amount]').fill('1200');
    await caption(page, item[0], 'Linked service bill with an additional line; amounts are illustrative.');
    await page.locator('#ttShipmentBillEntry button[type="submit"]').click();
    await expect(page.getByText('Supplier bill posted')).toBeVisible();
    await caption(page, 'Posted voucher', 'The bill and journal are now linked to the same QA shipment.');
    await closeLayer(page);
  }

  await openBill(page, 'Transport Bill');
  await page.locator('#ttShipmentBillVendor').fill('QA Transporter');
  await page.locator('[name="invoiceNo"]').fill('QA-TRANSPORT-001');
  await page.locator('[name="rate"]').fill('25000');
  await page.locator('#ttShipmentBillAdd').click();
  let transportExtra = page.locator('.tt-shipment-charge').last();
  await transportExtra.locator('[data-description]').fill('Toll and handling');
  await transportExtra.locator('[data-amount]').fill('1500');
  await caption(page, 'Transport', 'The two loaded containers under QA-LP-LGT-07 are billed at an illustrative PKR 25,000 per container plus one charge.');
  await page.locator('#ttShipmentBillEntry button[type="submit"]').click();
  await expect(page.getByText('Supplier bill posted')).toBeVisible();
  await caption(page, 'Transport voucher', 'The loading programme controls available containers and the journal credits the transporter.');
  await closeLayer(page);

  const snapshot = await page.evaluate(async () => {
    const r=await fetch('../api/accounts_workflows_v1.php?entity=TTI&section=services');
    return await r.json();
  });
  expect(snapshot.bills.filter(x=>x.shipmentId==='QA-SHIP-LGT-01')).toHaveLength(3);
  await caption(page, 'Accounts result', 'Freight, clearing, fumigation and inspection bills produced separate supplier liabilities and journals.');
});
