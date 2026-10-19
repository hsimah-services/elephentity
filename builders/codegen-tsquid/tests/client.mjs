// Point at a built tsquid runtime and the payload exported by integration.php.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const { readInitialData, withInitialData } = await import(pathToFileURL(process.argv[2]));
const payload = readFileSync(process.argv[3], 'utf8');
globalThis.document = { getElementById: id => id === 'tsquid-data' ? { textContent: payload } : null };
const data = readInitialData();
let fetches = 0;
const fetch = withInitialData(() => { ++fetches; return { data: { fallback: true } }; }, data);
assert.deepEqual(fetch({ name: 'ProfileQuery' }, {}), { data: { viewer: { id: 'Alice', name: 'Alice' } } });
assert.equal(fetches, 0);
fetch({ name: 'ProfileQuery' }, {});
assert.equal(fetches, 1);
await new Promise(resolve => setTimeout(resolve, 5));
fetch({ name: 'ProfileQuery' }, {});
assert.equal(fetches, 2);
console.log('PHP payload accepted and consumed synchronously by the actual tsquid client; later requests fetch.');
