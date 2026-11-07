const {test} = require('node:test');
const assert = require('node:assert/strict');
const {manifest, auditArchivedPacks} = require('./archive_read_acceptance.cjs');
const body = Buffer.from('%PDF-1.7\nfixture\n%%EOF\n');
function indexes() {
  return ['TTI', 'BRM'].map((entity, i) => ({entity, rows: [{shipmentId: entity + '-S1', contractRef: entity + '-C1', lotRef: 'LOT1', documents: [{
    category: 'shipment-archive', id: 'archive:' + String(i + 1).repeat(32), size: body.length,
    downloadUrl: '/api/shipment_archive_file.php?id=' + String(i + 1).repeat(32), name: 'Archived shipment document pack', uploadedAt: '2026-10-19T00:00:00Z'
  }]}]}));
}
const good = () => ({status: 200, contentType: 'application/pdf', contentLength: String(body.length), body});
test('each company original is read once and the final index is rechecked', async () => {
  let reads = 0; const calls = [], verified = [];
  const r = await auditArchivedPacks({readIndexes: async () => {reads++; return indexes();},
    download: async d => {calls.push(d.id); return good();}, onVerified: r => verified.push(r)});
  assert.equal(reads, 2); assert.equal(new Set(calls).size, 2); assert.equal(r.verifiedDownloads, 2);
  assert.equal(r.bytes, 2 * body.length); assert.equal(r.documentWrites, 0);
  assert.equal(verified.length, 2); assert(verified.every(r => /^[a-f0-9]{64}$/.test(r.sha256)));
});
for (const [label, change] of [
  ['404', r => r.status = 404], ['409', r => r.status = 409],
  ['wrong MIME', r => r.contentType = 'text/html'], ['short download', r => r.body = body.subarray(0, 5)],
  ['wrong header length', r => r.contentLength = '1'], ['HTML bytes', r => r.body = Buffer.alloc(body.length, 'x')]
]) test('unavailable or invalid original cannot pass: ' + label, async () => {
  const response = good(); change(response);
  await assert.rejects(auditArchivedPacks({readIndexes: async () => indexes(), download: async () => response}));
});
test('changed source metadata cannot produce a passing final report', async () => {
  let reads = 0;
  await assert.rejects(auditArchivedPacks({readIndexes: async () => {const r = indexes(); if (++reads === 2) r[0].rows[0].documents[0].name += ' changed'; return r;}, download: async () => good()}), /changed during audit/);
});
for (const [label, change] of [
  ['external URL', d => d.downloadUrl = 'https://invalid.example/steal'],
  ['another file ID', d => d.downloadUrl = '/api/shipment_archive_file.php?id=' + 'f'.repeat(32)],
  ['oversized file', d => d.size = 33 * 1024 * 1024], ['negative size', d => d.size = -1]
]) test('invalid manifest is refused before any download: ' + label, async () => {
  const r = indexes(); change(r[0].rows[0].documents[0]); let calls = 0;
  await assert.rejects(auditArchivedPacks({readIndexes: async () => r, download: async () => {calls++; return good();}}));
  assert.equal(calls, 0);
});
test('duplicate shipment/file identities and an empty positive dataset fail explicitly', () => {
  const r = indexes(); r[0].rows[0].documents.push({...r[0].rows[0].documents[0]});
  assert.throws(() => manifest(r), /Duplicate document/);
  assert.throws(() => manifest([{entity: 'TTI', rows: []}]), /No positive archives/);
});
