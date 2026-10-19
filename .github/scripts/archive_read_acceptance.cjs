const assert = require('node:assert/strict');
const {createHash} = require('node:crypto');

function manifest(indexes) {
  const packs = new Map();
  for (const {entity, rows} of indexes) {
    assert(['TTI', 'BRM'].includes(entity));
    assert(Array.isArray(rows));
    assert.equal(new Set(rows.map(r => r.shipmentId)).size, rows.length, 'Duplicate shipment rows');
    for (const row of rows) {
      const documents = row.documents || [];
      assert.equal(new Set(documents.map(d => d.id)).size, documents.length, 'Duplicate document in shipment');
      for (const doc of documents.filter(d => d.category === 'shipment-archive')) {
        assert.match(doc.id, /^archive:[a-f0-9]{32}$/);
        assert.equal(doc.downloadUrl, '/api/shipment_archive_file.php?id=' + doc.id.slice(8), 'Noncanonical download target');
        assert(Number.isSafeInteger(doc.size) && doc.size > 0 && doc.size <= 32 * 1024 * 1024, 'Pack size outside audit bound');
        const item = {entity, shipmentId: row.shipmentId, contractRef: row.contractRef,
          lotRef: row.lotRef, id: doc.id, downloadUrl: doc.downloadUrl, size: doc.size,
          uploadedAt: doc.uploadedAt, name: doc.name};
        assert(!packs.has(doc.id), 'Archive appears against multiple shipment rows');
        packs.set(doc.id, item);
      }
    }
  }
  const result = [...packs.values()].sort((a, b) => a.id.localeCompare(b.id));
  assert(result.length > 0 && result.length <= 128, 'No positive archives or audit count bound exceeded');
  assert(result.reduce((n, d) => n + d.size, 0) <= 256 * 1024 * 1024, 'Total archive audit byte bound exceeded');
  return result;
}

async function auditArchivedPacks({readIndexes, download, onVerified = () => {}}) {
  const before = manifest(await readIndexes());
  let bytes = 0;
  const receipts = [];
  for (const doc of before) {
    const response = await download(doc);
    assert.equal(response.status, 200, 'Original archive unavailable: ' + doc.id);
    assert.match(response.contentType || '', /^application\/pdf(?:;|$)/i);
    assert.equal(Number(response.contentLength), doc.size, 'Content-Length differs from saved metadata');
    assert(Buffer.isBuffer(response.body));
    assert.equal(response.body.length, doc.size, 'Downloaded length differs from saved metadata');
    assert.equal(response.body.subarray(0, 5).toString(), '%PDF-', 'Original bytes are not a PDF');
    const receipt = {id: doc.id, size: doc.size, sha256: createHash('sha256').update(response.body).digest('hex')};
    receipts.push(receipt); bytes += doc.size;
    await onVerified(receipt);
  }
  assert.deepEqual(manifest(await readIndexes()), before, 'Original archive index changed during audit; review and rerun');
  return {listedPacks: before.length, verifiedDownloads: receipts.length, bytes,
    receiptsSha256: createHash('sha256').update(JSON.stringify(receipts)).digest('hex'), documentWrites: 0};
}

module.exports = {manifest, auditArchivedPacks};
