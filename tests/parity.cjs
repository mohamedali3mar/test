/*
 * اختبار تطابق JavaScript مع PHP: يقرأ tests/output/parity.json (يُنشئه tests/unit.php)
 * ويتحقق أن WoodCalc في assets/js/app.js يعطي نفس القيم ونفس الرسائل حرفيًا.
 * التشغيل: node tests/parity.cjs
 */
'use strict';
const path = require('path');
const fs = require('fs');
const C = require(path.join(__dirname, '..', 'public_html', 'assets', 'js', 'app.js'));
const cases = JSON.parse(fs.readFileSync(path.join(__dirname, 'output', 'parity.json'), 'utf8'));

let pass = 0;
const fails = [];
function eq(label, expected, actual) {
  if (expected === actual) { pass++; } else { fails.push(`${label}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`); }
}

for (const entry of cases) {
  const c = entry.config;
  C.configure({ digits: c.digits, volDecimals: c.volume_decimals, volPad: c.volume_pad });
  const tag = `[${c.digits}/${c.volume_decimals}/${c.volume_pad}]`;
  for (const [raw, unit, v, err] of entry.dims) {
    const r = C.parseDimension(raw, unit, 'الطول');
    eq(`${tag} dim ${JSON.stringify(raw)} ${unit} value`, v, r.v === undefined ? null : r.v.toString());
    eq(`${tag} dim ${JSON.stringify(raw)} ${unit} message`, err, r.msg === undefined ? null : r.msg);
  }
  for (const [raw, q, err] of entry.qty) {
    const r = C.parseQuantity(raw);
    eq(`${tag} qty ${JSON.stringify(raw)} value`, q, r.v === undefined ? null : r.v);
    eq(`${tag} qty ${JSON.stringify(raw)} message`, err, r.msg === undefined ? null : r.msg);
  }
  for (const [raw, p, err] of entry.price) {
    const r = C.parsePrice(raw);
    eq(`${tag} price ${JSON.stringify(raw)} value`, p, r.v === undefined ? null : r.v.toString());
    eq(`${tag} price ${JSON.stringify(raw)} message`, err, r.msg === undefined ? null : r.msg);
  }
  for (const [um3, expected] of entry.volume) {
    eq(`${tag} volume ${um3}`, expected, C.fmtVolume(BigInt(um3)));
  }
  for (const [piasters, expected] of entry.money) {
    eq(`${tag} money ${piasters}`, expected, C.fmtMoney(BigInt(piasters)));
  }
}
// مثال المتطلبات في JavaScript نفسه
C.configure({ digits: 'western', volDecimals: 'full', volPad: '0' });
const piece = 100000n * 50000n * 3000000n;
eq('example piece 0.015', '0.015', C.fmtVolume(piece));
eq('example total 0.15', '0.15', C.fmtVolume(piece * 10n));
eq('example amount 3,000.00', '3,000.00', C.fmtMoney(C.amountPiasters(piece * 10n, 2000000n)));
eq('half-up 3.13', '3.13', C.fmtMoney(C.amountPiasters(12500n * 12500n * 1000000n, 2000000n)));

console.log(`\nJS parity: ${pass} passed, ${fails.length} failed`);
fails.slice(0, 40).forEach((f) => console.log('  - ' + f));
process.exit(fails.length ? 1 : 0);
