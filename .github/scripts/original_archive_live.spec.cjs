const {test, expect} = require('@playwright/test');
const {auditArchivedPacks} = require('./archive_read_acceptance.cjs');
const BASE = 'https://app.transtradeinternational.com';
test('every indexed TTI/BRM original archive downloads with verified saved length', async ({page, context}) => {
  test.setTimeout(240000);
  const attempts = [];
  await context.route('**/*', route => {
    const r = route.request(), url = new URL(r.url());
    if (!['GET', 'HEAD'].includes(r.method()) && !(url.origin === BASE && url.pathname === '/login.php' && r.method() === 'POST')) {
      if (url.pathname !== '/api/session_activity.php') attempts.push(url.pathname);
      return route.abort();
    }
    return route.continue();
  });
  expect(process.env.TRANSTRADE_QA_USERNAME).toBeTruthy(); expect(process.env.TRANSTRADE_QA_PASSWORD).toBeTruthy();
  await page.goto(BASE + '/login.php', {waitUntil: 'domcontentloaded', timeout: 90000});
  await page.locator('[name=username]').fill(process.env.TRANSTRADE_QA_USERNAME);
  await page.locator('[name=password]').fill(process.env.TRANSTRADE_QA_PASSWORD);
  await page.locator('button[type=submit]').click();
  await expect(page).toHaveURL(/\/accounts\/(?:index.php)?$/, {timeout: 30000});
  const to = new Intl.DateTimeFormat('en-CA', {timeZone: 'Asia/Karachi', year: 'numeric', month: '2-digit', day: '2-digit'}).format(new Date());
  const result = await auditArchivedPacks({
    readIndexes: async () => {
      const indexes = [];
      for (const entity of ['TTI', 'BRM']) {
        const response = await page.request.get(BASE + '/api/accounts_sales_tax.php?' + new URLSearchParams({entity, to}), {timeout: 30000});
        expect(response.status()).toBe(200); const data = await response.json();
        expect(data.ok).toBe(true); expect(data.entity).toBe(entity);
        indexes.push({entity, rows: data.rows}); await response.dispose();
      }
      return indexes;
    },
    download: async doc => {
      const response = await page.request.get(BASE + doc.downloadUrl, {timeout: 30000, maxRedirects: 0});
      try {return {status: response.status(), contentType: response.headers()['content-type'],
        contentLength: response.headers()['content-length'], body: await response.body()};}
      finally {await response.dispose();}
    },
    onVerified: receipt => console.log('ORIGINAL_ARCHIVE_BYTES_OK ' + JSON.stringify(receipt))
  });
  expect(attempts).toEqual([]);
  console.log('ALL_INDEXED_ARCHIVES_READ_OK ' + JSON.stringify({...result, companies: ['TTI', 'BRM'],
    sourceHashCheck: 'Existing protected endpoint independently verifies saved SHA-256 before serving bytes',
    boundary: 'Indexed dated archive revisions only; does not certify current issued CI/PL wording, individual attachments or physical printing'}));
});
