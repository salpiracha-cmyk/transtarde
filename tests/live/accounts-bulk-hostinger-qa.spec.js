const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TRANSTRADE_BASE_URL || 'https://app.transtradeinternational.com';
const QA_USERNAME = process.env.TRANSTRADE_QA_USERNAME;
const QA_PASSWORD = process.env.TRANSTRADE_QA_PASSWORD;
const today = new Date().toISOString().slice(0, 10);
const month = today.slice(0, 7);

const values = value => Array.isArray(value) ? value : Object.values(value || {});
const groupDuplicates = (rows, keyOf) => {
  const groups = new Map();
  for (const row of rows) {
    const key = keyOf(row);
    if (!key) continue;
    const bucket = groups.get(key) || [];
    bucket.push(row);
    groups.set(key, bucket);
  }
  return [...groups.entries()].filter(([, bucket]) => bucket.length > 1)
    .map(([key, bucket]) => ({ key, count: bucket.length, ids: bucket.map(x => x.id || x.journalId || x.reference || '') }));
};

async function authenticate(request) {
  expect(QA_USERNAME, 'TRANSTRADE_QA_USERNAME is required').toBeTruthy();
  expect(QA_PASSWORD, 'TRANSTRADE_QA_PASSWORD is required').toBeTruthy();
  const login = await request.get(`${BASE_URL}/login.php`, { timeout: 30_000 });
  expect(login.status(), 'Login page must be reachable').toBe(200);
  const html = await login.text();
  const token = html.match(/name="csrf" value="([^"]+)"/)?.[1];
  expect(token, 'Login CSRF token must be present').toBeTruthy();
  const signed = await request.post(`${BASE_URL}/login.php`, {
    form: { csrf: token, username: QA_USERNAME, password: QA_PASSWORD },
    timeout: 30_000
  });
  expect(signed.status(), 'QA login must succeed').toBe(200);
  const accounts = await request.get(`${BASE_URL}/accounts/index.php`, { timeout: 30_000 });
  expect(accounts.status(), 'Accounts must be accessible after login').toBe(200);
  const body = await accounts.text();
  const accessRaw = body.match(/window\.TT_ACCOUNT_ACCESS=(\{.*?\});<\/script>/s)?.[1];
  expect(accessRaw, 'Accounts access bootstrap must be present').toBeTruthy();
  return JSON.parse(accessRaw).csrf;
}

async function jsonCall(request, path, options = {}) {
  const response = await request.fetch(`${BASE_URL}${path}`, { timeout: 45_000, ...options });
  const text = await response.text();
  let body = {};
  try { body = JSON.parse(text); } catch {}
  return { status: response.status(), body, text: text.slice(0, 500) };
}

test('bulk Accounts audit: locking, duplicates, entity isolation and three-module connectivity', async ({ request }, testInfo) => {
  test.setTimeout(12 * 60_000);
  const csrf = await authenticate(request);
  const prefix = `TEST-DUMMY-ACCOUNTS-BULK-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  const createdJournalIds = [];
  const results = { prefix, created: [], cleanup: [], endpointChecks: {}, findings: {} };

  try {
    const cases = [];
    for (let i = 1; i <= 4; i++) {
      cases.push({
        label: `MILLING-RECEIPT-SIM-${i}`,
        payload: {
          action: 'post_event', csrf, entity: 'TTI', date: today,
          eventType: 'COMMODITY_RECEIPT_ACCEPTED',
          sourceKey: `${prefix}|MILLING|${i}`,
          reference: `${prefix}-MILLING-${i}`,
          narration: `TEST / DUMMY — Milling to Accounts duplicate stress ${i}`,
          amount: 10 + i, commodity: 'RICE',
          meta: { testDummy: true, sourceModule: 'Milling', payableWeightKg: 40 + i, soda: 'TEST-DUMMY-NOT-BILLABLE' }
        }
      });
      cases.push({
        label: `EXPORT-SALE-SIM-${i}`,
        payload: {
          action: 'post_event', csrf, entity: 'TTI', date: today,
          eventType: 'EXPORT_SALE_RECOGNIZED',
          sourceKey: `${prefix}|EXPORTS|${i}`,
          reference: `${prefix}-EXPORTS-${i}`,
          narration: `TEST / DUMMY — Exports to Accounts duplicate stress ${i}`,
          amount: 20 + i,
          meta: { testDummy: true, sourceModule: 'Exports' }
        }
      });
      cases.push({
        label: `ACCOUNTS-UTILITY-${i}`,
        payload: {
          action: 'post_event', csrf, entity: 'TTI', date: today,
          eventType: 'UTILITY_PAYMENT',
          sourceKey: `${prefix}|ACCOUNTS|${i}`,
          reference: `${prefix}-ACCOUNTS-${i}`,
          narration: `TEST / DUMMY — Accounts duplicate stress ${i}`,
          amount: 30 + i, expenseAccount: '6100', payAccount: '1120',
          meta: { testDummy: true, sourceModule: 'Accounts' }
        }
      });
    }

    for (const item of cases) {
      const pair = await Promise.all([
        jsonCall(request, '/api/accounts.php', { method: 'POST', data: item.payload }),
        jsonCall(request, '/api/accounts.php', { method: 'POST', data: item.payload })
      ]);
      const statuses = pair.map(x => x.status).sort((a, b) => a - b);
      expect(statuses, `${item.label}: concurrent duplicate submissions must yield one success and one duplicate rejection`).toEqual([200, 409]);
      const success = pair.find(x => x.status === 200);
      expect(success.body.ok).toBeTruthy();
      expect(success.body.journal.totalDebit).toBe(success.body.journal.totalCredit);
      createdJournalIds.push(success.body.journal.id);
      results.created.push({ label: item.label, sourceKey: item.payload.sourceKey, statuses, journalId: success.body.journal.id });
    }

    const retiredDirect = await jsonCall(request, '/api/accounts.php', {
      method: 'POST',
      data: {
        action: 'post_journal', csrf, entity: 'TTI', date: today,
        sourceKey: `${prefix}|DIRECT-JV`, reference: `${prefix}-DIRECT-JV`,
        narration: 'TEST / DUMMY — direct posting must stay retired',
        lines: [{ account: '6900', debit: 1, credit: 0 }, { account: '2100', debit: 0, credit: 1 }]
      }
    });
    expect(retiredDirect.status).toBe(410);
    results.endpointChecks.directJournalRetired = retiredDirect.status;

    const endpoints = {
      accounts: '/api/accounts.php?entity=TTI',
      accountsReports: `/api/accounts_reports.php?entity=TTI&from=${today.slice(0, 4)}-01-01&asOf=${today}`,
      millingLoadingBridge: '/api/accounts_workflows_v1.php?entity=TTI&section=transport',
      millingReceiptBridge: '/api/commodity_bills.php?entity=TTI',
      exportsAccountingBridge: '/api/export_accounting.php?entity=TTI',
      salary: `/api/rent_salary_v2.php?entity=TTI&month=${month}`,
      donations: '/api/donations.php?entity=TTI',
      expenses: `/api/expenses_v1.php?entity=TTI&month=${month}`
    };
    const snapshots = {};
    for (const [name, path] of Object.entries(endpoints)) {
      const response = await jsonCall(request, path);
      expect(response.status, `${name} endpoint status`).toBe(200);
      expect(response.body.ok, `${name} endpoint payload`).toBeTruthy();
      snapshots[name] = response.body;
      results.endpointChecks[name] = { status: response.status, revision: response.body.revision ?? null };
    }

    const journals = values(snapshots.accounts.journals);
    const events = values(snapshots.accounts.events);
    const journalById = new Map(journals.map(j => [j.id, j]));
    const unbalanced = journals.filter(j => Math.abs(Number(j.totalDebit || 0) - Number(j.totalCredit || 0)) > 0.005);
    const orphanEvents = events.filter(e => !journalById.has(e.journalId));
    const eventJournalEntityMismatch = events.filter(e => journalById.has(e.journalId) && journalById.get(e.journalId).entity !== e.entity);
    const duplicateJournalSources = groupDuplicates(
      journals.filter(j => j.sourceType !== 'REVERSAL' && j.meta?.sourceKey && !String(j.meta.sourceKey).startsWith(prefix)),
      j => `${j.entity}|${j.sourceType}|${j.meta.sourceKey}`
    );
    const potentialSemanticDuplicates = groupDuplicates(
      journals.filter(j => j.sourceType !== 'REVERSAL' && !String(j.reference || '').startsWith(prefix)),
      j => `${j.entity}|${j.date}|${j.sourceType}|${String(j.reference || '').trim().toUpperCase()}|${Number(j.totalDebit || 0).toFixed(2)}`
    );

    const bills = values(snapshots.millingReceiptBridge.bills);
    const receiptOwners = new Map();
    for (const bill of bills) {
      for (const key of values(bill.sourceKeys)) {
        const owners = receiptOwners.get(String(key)) || [];
        owners.push(bill.id);
        receiptOwners.set(String(key), owners);
      }
    }
    const receiptsAllocatedToMultipleBills = [...receiptOwners.entries()]
      .filter(([, owners]) => new Set(owners).size > 1)
      .map(([sourceKey, owners]) => ({ sourceKey, billIds: [...new Set(owners)] }));

    const candidates = values(snapshots.exportsAccountingBridge.candidates);
    const duplicateExportCandidates = groupDuplicates(candidates, c => `${c.entity}|${c.candidateType}|${c.sourceKey}`);
    const programmes = values(snapshots.millingLoadingBridge.loadingProgrammes);
    const duplicateLoadingProgrammes = groupDuplicates(programmes, p => `${p.entity}|${p.loadingProgrammeNo}`);

    const report = snapshots.accountsReports;
    results.findings = {
      journalCount: journals.length,
      eventCount: events.length,
      unbalanced,
      orphanEvents,
      eventJournalEntityMismatch,
      duplicateJournalSources,
      potentialSemanticDuplicates,
      receiptsAllocatedToMultipleBills,
      duplicateExportCandidates,
      duplicateLoadingProgrammes,
      trialBalanceBalanced: report.trialBalance?.balanced,
      trialBalanceDifference: Number(report.trialBalance?.totalDebit || 0) - Number(report.trialBalance?.totalCredit || 0),
      salaryMasterCount: values(snapshots.salary.salaryMasters).length,
      donationCount: values(snapshots.donations.donations).length,
      expenseEndpointRevision: snapshots.expenses.revision ?? null
    };

    expect(unbalanced, 'Every posted journal must balance').toEqual([]);
    expect(orphanEvents, 'Every source event must point to a journal').toEqual([]);
    expect(eventJournalEntityMismatch, 'Event and journal legal entities must match').toEqual([]);
    expect(duplicateJournalSources, 'One source key must not create multiple original journals').toEqual([]);
    expect(receiptsAllocatedToMultipleBills, 'One Milling receipt must not be billed twice').toEqual([]);
    expect(duplicateExportCandidates, 'One Export source must not create duplicate candidates').toEqual([]);
    expect(duplicateLoadingProgrammes, 'One loading programme must not be duplicated').toEqual([]);
    expect(report.trialBalance?.balanced, 'TTI trial balance must remain balanced').toBeTruthy();

    for (const entity of ['BRM', 'TG']) {
      const response = await jsonCall(request, `/api/accounts.php?entity=${entity}`);
      results.endpointChecks[`entity-${entity}`] = { status: response.status, ok: Boolean(response.body.ok) };
      if (response.status === 200) {
        const foreignEvents = values(response.body.events).filter(e => String(e.sourceKey || '').startsWith(prefix));
        expect(foreignEvents, `TTI test sources must not leak into ${entity}`).toEqual([]);
      } else {
        expect([403], `${entity} must be accessible or explicitly forbidden`).toContain(response.status);
      }
    }
  } finally {
    for (const journalId of createdJournalIds) {
      const reversed = await jsonCall(request, '/api/accounts.php', {
        method: 'POST',
        data: { action: 'reverse_journal', csrf, journalId, reason: `TEST / DUMMY bulk audit cleanup — ${prefix}` }
      });
      results.cleanup.push({ journalId, status: reversed.status, reversalJournalId: reversed.body.journal?.id || null });
      expect([200, 409], `Cleanup reversal for ${journalId}`).toContain(reversed.status);
    }
    await testInfo.attach('accounts-bulk-audit.json', {
      body: Buffer.from(JSON.stringify(results, null, 2)),
      contentType: 'application/json'
    });
    console.log('ACCOUNTS_BULK_AUDIT=' + JSON.stringify(results));
  }
});
