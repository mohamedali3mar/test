/*
 * اختبار توقيت التحديث التلقائي: WoodCalc.liveDelay دالة نقية تحدد مهلة الفحص التالي
 * (النشاط والخمول والتبويب المخفي والتباعد بعد الإخفاق). لا تحتاج متصفحًا ولا قاعدة بيانات.
 * التشغيل: node tests/live_timing.cjs
 */
'use strict';
const path = require('path');
const C = require(path.join(__dirname, '..', 'public_html', 'assets', 'js', 'app.js'));

let pass = 0;
const fails = [];
function eq(label, expected, actual) {
  if (expected === actual) { pass++; } else { fails.push(`${label}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`); }
}
const MIN = 60 * 1000;
const delay = (idleFor, failures, hidden) => C.liveDelay({ idleFor, failures, hidden });

// الثوابت المتفق عليها
eq('fast interval', 2000, C.LIVE.fastMs);
eq('slow interval', 10000, C.LIVE.slowMs);
eq('idle threshold 10 min', 10 * MIN, C.LIVE.idleAfterMs);

// النشاط والخمول
eq('active user polls every 2s', 2000, delay(0, 0));
eq('active 9 min ago still fast', 2000, delay(9 * MIN, 0));
eq('exactly 10 min is not idle yet', 2000, delay(10 * MIN, 0));
eq('idle > 10 min slows to 10s', 10000, delay(10 * MIN + 1, 0));
eq('idle 3 hours stays at 10s', 10000, delay(180 * MIN, 0));
eq('empty state defaults to fast', 2000, C.liveDelay());
eq('missing fields default to fast', 2000, C.liveDelay({}));

// التبويب المخفي: لا فحص
eq('hidden tab does not poll', null, delay(0, 0, true));
eq('hidden tab does not poll even after failures', null, delay(0, 4, true));
eq('hidden idle tab does not poll', null, delay(60 * MIN, 0, true));

// التباعد بعد الإخفاق: ٢ ثم ٤ ثم ٨ ثم ١٦ ثم ٣٠ حدًا أقصى
[2000, 4000, 8000, 16000, 30000, 30000, 30000].forEach((ms, i) => {
  eq(`active, ${i + 1} consecutive failure(s)`, ms, delay(0, i + 1));
});
eq('very many failures capped at 30s', 30000, delay(0, 5000));
eq('negative failures treated as none', 2000, delay(0, -3));
eq('non-numeric failures treated as none', 2000, C.liveDelay({ failures: 'x' }));

// وقت الخمول لا يقل الانتظار بعد الإخفاق عن مهلة الخمول
[10000, 10000, 10000, 16000, 30000, 30000].forEach((ms, i) => {
  eq(`idle, ${i + 1} consecutive failure(s)`, ms, delay(11 * MIN, i + 1));
});

// النجاح بعد الإخفاق يعيد المهلة العادية (المتصل يصفّر العداد)
eq('reset after success', 2000, delay(0, 0));

console.log(`\nLive timing: ${pass} passed, ${fails.length} failed`);
fails.forEach((f) => console.log('  - ' + f));
process.exit(fails.length ? 1 : 0);
