const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const { webcrypto, createHash } = require('crypto');

const source = fs.readFileSync('frontend/assets/js/transactions.js', 'utf8');
const utils = fs.readFileSync('frontend/assets/js/utils.js', 'utf8');
const context = {
    window: { crypto: webcrypto }, console, TextEncoder, Uint8Array, Set,
    document: {}, URL, Blob, CustomEvent: class CustomEvent {}
};
vm.createContext(context);
vm.runInContext(source, context);
const manager = new context.window.TransactionsManager();

(async () => {
    const csv = 'Date,Type,Amount\n2026-08-25,expense,10\n';
    const fingerprint = await manager._csvFingerprint(csv);
    assert.strictEqual(fingerprint, createHash('sha256').update(csv).digest('hex'), 'frontend SHA-256 batch identity differs from backend-compatible SHA-256');
    const unicodeCsv = `${csv}2026-08-25,expense,20,खर्च\n`;
    assert.strictEqual(await manager._csvFingerprint(unicodeCsv), createHash('sha256').update(unicodeCsv).digest('hex'), 'UTF-8 CSV fingerprint is not stable');
    assert(source.includes("'/transactions/import/preview'"), 'authoritative preview endpoint is not used');
    assert(source.includes("'/transactions/import'"), 'dedicated import endpoint is not used');
    const importStart = source.indexOf('async importCSV()');
    const importEnd = source.indexOf('    downloadImportTemplate()', importStart);
    const importBlock = source.slice(importStart, importEnd);
    assert(importStart >= 0 && importEnd > importStart, 'CSV import execution block was not found');
    assert(!importBlock.includes("post('/transactions'"), 'CSV execution still posts financial rows individually');
    assert(utils.includes('if (formulaSafe && /^[=+\\-@]/.test(text))'), 'shared CSV formula neutralization is missing');
    assert(source.includes('return CSVUtils.cell(value)'), 'transaction imports/exports do not delegate to shared CSV safety');
    assert(source.includes('row.map(value => this._csvCell(value))'), 'transaction export does not use the shared safe CSV cell helper');
    assert(importBlock.includes('row.map(value => this._csvCell(value))'), 'failed-row export does not use the shared safe CSV cell helper');
    assert(!importBlock.includes('OfflineStorage'), 'CSV import was routed through offline storage');
    console.log('PASS: frontend uses deterministic SHA-256, dedicated online endpoints, structured diagnostics, and safe failure export');
})().catch(error => { console.error(error); process.exitCode = 1; });
