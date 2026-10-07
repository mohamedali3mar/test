/*
 * اختبار المتصفح الشامل (Chromium عبر Playwright) على خادم Apache المحلي:
 * التثبيت، الدخول، الوارد، فاتورة البيع، التحويل، الطباعة، منع الضغط المزدوج، التحديث التلقائي
 * بين نافذتين، العربية وRTL وخط Cairo، الموبايل، وقواعد التصميم.
 * التشغيل: tests/deploy.sh && node tests/e2e.mjs
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = 'http://localhost:8080/';
const OUT = path.join(path.dirname(fileURLToPath(import.meta.url)), 'output', 'screens');
mkdirSync(OUT, { recursive: true });
const ADMIN = { user: 'admin', pass: 'Correct-Horse-9' };
// ٣٠٠٠٫٠٠ بالأرقام العربية: فاصل الآلاف مسافة ضيقة (U+202F)
const AMOUNT_3000 = '٣\u202F٠٠٠٫٠٠';

let pass = 0;
const fails = [];
function check(label, ok, detail = '') {
  if (ok) { pass++; console.log('  PASS  ' + label); } else { fails.push(label + (detail ? ' -> ' + detail : '')); console.log('  FAIL  ' + label + (detail ? '  -> ' + detail : '')); }
}
function section(t) { console.log('\n== ' + t); }
function sql(q) { return execSync(`mariadb -uroot wood_e2e -N -e ${JSON.stringify(q)}`).toString().trim(); }
const text = async (page, sel) => ((await page.locator(sel).first().textContent()) || '').trim();
const settle = (page) => page.waitForTimeout(400);

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'ar-EG', acceptDownloads: true });
const problems = [];
function watch(page, name) {
  page.on('console', (m) => { if (m.type() === 'error') { problems.push(`${name}: console ${m.text()}`); } });
  page.on('pageerror', (e) => problems.push(`${name}: pageerror ${e.message}`));
  page.on('dialog', (d) => { problems.push(`${name}: dialog ${d.message()}`); d.dismiss(); });
}
const page = await context.newPage();
watch(page, 'main');

/* ---------------------------------------------------------------- */
section('التثبيت والدخول');
await page.goto(BASE + 'install.php');
await page.fill('#install_key', 'wrong-key-1234567890');
await page.fill('#username', ADMIN.user);
await page.fill('#password', ADMIN.pass);
await page.fill('#password_confirm', ADMIN.pass);
await page.click('button:has-text("تثبيت")');
check('مفتاح تثبيت خاطئ مرفوض', (await page.content()).includes('مفتاح التثبيت غير صحيح'));
await page.fill('#install_key', 'k7Hq2Zp9Lw4Xv1Nb8Rt5');
await page.fill('#company_name', 'شركة النيل للأخشاب');
await page.fill('#password', ADMIN.pass);
await page.fill('#password_confirm', ADMIN.pass);
await page.click('button:has-text("تثبيت")');
check('التثبيت نجح', (await page.content()).includes('تم التثبيت'));
check('ملف install.php حذف نفسه بعد التثبيت', (await page.request.get(BASE + 'install.php')).status() === 404);

await page.goto(BASE);
check('الصفحات المحمية تحوّل إلى الدخول', page.url().includes('r=login'));
await page.fill('#username', ADMIN.user);
await page.fill('#password', 'wrong-password');
await page.click('button:has-text("دخول")');
check('كلمة مرور خاطئة مرفوضة برسالة عربية', (await page.content()).includes('اسم المستخدم أو كلمة المرور غير صحيحة'));
await page.fill('#username', ADMIN.user);
await page.fill('#password', ADMIN.pass);
await page.click('button:has-text("دخول")');
check('الدخول نجح وفتح المخزون', page.url().includes('r=inventory'));

section('العربية وRTL وخط Cairo');
check('lang=ar و dir=rtl', (await page.getAttribute('html', 'lang')) === 'ar' && (await page.getAttribute('html', 'dir')) === 'rtl');
const fontOk = await page.evaluate(async () => {
  await document.fonts.ready;
  const loaded = [...document.fonts].filter((f) => f.family.replace(/"/g, '') === 'Cairo' && f.status === 'loaded').length;
  return { loaded, check: document.fonts.check('16px Cairo', 'نص عربي'), family: getComputedStyle(document.body).fontFamily };
});
check('خط Cairo محمّل فعليًا', fontOk.loaded > 0 && fontOk.check && fontOk.family.includes('Cairo'), JSON.stringify(fontOk));
check('محاذاة النص لليمين', (await page.evaluate(() => getComputedStyle(document.body).textAlign)) === 'right');

/* ---------------------------------------------------------------- */
section('أنواع الخشب والمخازن');
await page.goto(BASE + 'index.php?r=types');
for (const name of ['موسكي', 'زان']) {
  await page.fill('#new-name', name);
  await page.click('button:has-text("إضافة")');
}
await page.fill('#new-name', '  موسكي  ');
await page.click('button:has-text("إضافة")');
check('اسم مكرر بمسافات زائدة مرفوض', (await page.content()).includes('هذا النوع موجود بالفعل'));
await page.fill('#new-name', '<img src=x onerror=alert(1)>');
await page.click('button:has-text("إضافة")');
check('نص خبيث يظهر كنص عادي', (await page.locator('td', { hasText: '<img src=x onerror=alert(1)>' }).count()) === 1);
await page.goto(BASE + 'index.php?r=warehouses');
await page.fill('#new-name', 'مخزن الفرع');
await page.click('button:has-text("إضافة")');
check('إضافة مخزن ثانٍ', (await page.locator('td', { hasText: 'مخزن الفرع' }).count()) === 1);

/* ---------------------------------------------------------------- */
section('الوارد مع المعاينة الحية');
await page.goto(BASE + 'index.php?r=receive');
await page.selectOption('#warehouse_id', { label: 'المخزن الرئيسي' });
await page.selectOption('#wood_type_id', { label: 'موسكي' });
await page.fill('#width', '10');
await page.selectOption('#width_unit', 'cm');
await page.fill('#thickness', '٥٠');
await page.selectOption('#thickness_unit', 'mm');
await page.fill('#length', '3');
await page.selectOption('#length_unit', 'm');
await page.fill('#quantity', '١٠');
await settle(page);
check('حجم القطعة ٠٫٠١٥', (await text(page, '[data-out="piece"]')) === '٠٫٠١٥', await text(page, '[data-out="piece"]'));
check('حجم الكمية ٠٫١٥', (await text(page, '[data-out="total"]')) === '٠٫١٥', await text(page, '[data-out="total"]'));
check('الأبعاد بالمتر', (await text(page, '[data-out="meters"]')) === 'عرض ٠٫١ × تخانة ٠٫٠٥ × طول ٣ متر', await text(page, '[data-out="meters"]'));
check('مقاس جديد', (await text(page, '[data-out="existing"]')) === 'مقاس جديد');
await page.screenshot({ path: path.join(OUT, 'receive-desktop.png'), fullPage: true });
await page.click('button:has-text("حفظ الوارد")');
await page.waitForURL(/done=/);
check('تأكيد واضح: وارد رقم ١', (await text(page, '#done-title')).includes('وارد رقم ١'));
check('الحقول الثابتة محفوظة للإدخال التالي', (await page.inputValue('#wood_type_id')) !== '' && (await page.inputValue('#width_unit')) === 'cm');
await page.fill('#width', '100');
await page.selectOption('#width_unit', 'mm');
await page.fill('#thickness', '5');
await page.selectOption('#thickness_unit', 'cm');
await page.fill('#length', '300');
await page.selectOption('#length_unit', 'cm');
await page.fill('#quantity', '90');
await settle(page);
check('نفس المقاس بوحدات أخرى يتعرف على الصنف الموجود', (await text(page, '[data-out="existing"]')).startsWith('١٠ قطعة في هذا المخزن'), await text(page, '[data-out="existing"]'));
await page.click('button:has-text("حفظ الوارد")');
await page.waitForURL(/done=/);
check('الرصيد بعد الإضافة ١٠٠', (await page.locator('.confirm-box').textContent()).includes('١٠٠ قطعة'));
await page.fill('#width', '-5');
await page.fill('#thickness', '5');
await page.fill('#length', '3');
await page.fill('#quantity', '2.5');
await settle(page);
check('خطأ فوري في الواجهة للقيم السالبة', (await text(page, '[data-out="error"]')).includes('لا يقبل قيمًا سالبة'));
await page.click('button:has-text("حفظ الوارد")');
check('الخادم يرفض أيضًا ويعرض الرسائل بجوار الحقول', (await page.locator('#err-width').textContent()).includes('سالبة')
  && (await page.locator('#err-quantity').textContent()).includes('بدون كسور'));
check('لم يُسجل وارد ثالث', sql("SELECT COUNT(*) FROM documents WHERE kind='in'") === '2');

section('تذكّر الوحدات في المتصفح');
await page.goto(BASE + 'index.php?r=receive&clear=1');
await page.selectOption('#length_unit', 'cm');
await page.goto(BASE + 'index.php?r=receive');
check('آخر وحدة مختارة تُستعاد', (await page.inputValue('#length_unit')) === 'cm');
await page.selectOption('#length_unit', 'm');

/* ---------------------------------------------------------------- */
section('فاتورة البيع: الحساب والمراجعة ومنع الضغط المزدوج');
await page.goto(BASE + 'index.php?r=sell');
await page.selectOption('#warehouse_id', { label: 'المخزن الرئيسي' });
let line = page.locator('[data-line]').first();
await line.locator('[data-line-type]').selectOption({ label: 'موسكي' });
await line.locator('[data-line-item]').selectOption({ index: 1 });
await settle(page);
check('المتاح يظهر عند اختيار المقاس', (await line.locator('[data-line-available]').textContent()).includes('١٠٠ قطعة'));
await line.locator('[data-line-qty]').fill('10');
await line.locator('[data-line-price]').fill('20000');
await settle(page);
check('حجم السطر ٠٫١٥', (await line.locator('[data-line-volume]').textContent()) === '٠٫١٥');
check('قيمة السطر ٣٠٠٠٫٠٠', (await line.locator('[data-line-amount]').textContent()) === AMOUNT_3000);
check('إجمالي الفاتورة ٣٠٠٠٫٠٠', (await text(page, '[data-total-amount]')) === AMOUNT_3000);
const line2 = page.locator('[data-line]').nth(1);
await line2.locator('[data-line-item]').selectOption({ index: 1 });
await line2.locator('[data-line-qty]').fill('101');
await settle(page);
const l2err = await line2.locator('[data-line-error]').textContent();
check('تحذير فوري: مقاس مكرر وكمية أكبر من المتاح', l2err.includes('مكرر') && l2err.includes('أكبر من المتاح'), l2err);
await line2.locator('[data-line-remove]').click();
check('حذف السطر', (await page.locator('[data-line]').count()) === 2);
await page.click('[data-line-add]');
check('إضافة سطر', (await page.locator('[data-line]').count()) === 3);
await page.fill('#party_name', 'مؤسسة البناء الحديث');
await page.screenshot({ path: path.join(OUT, 'sell-desktop.png'), fullPage: true });
await page.click('button:has-text("مراجعة الفاتورة")');
check('صفحة المراجعة على الخادم', (await text(page, 'h1')) === 'مراجعة فاتورة البيع' && (await page.content()).includes(AMOUNT_3000));
await page.dblclick('button:has-text("تأكيد البيع")');
await page.waitForURL(/r=sell&done=/);
await settle(page);
check('الضغط المزدوج سجل فاتورة واحدة فقط', sql("SELECT COUNT(*) FROM documents WHERE kind='sale'") === '1');
check('تأكيد: بيع رقم ١', (await text(page, '#done-title')).includes('بيع رقم ١'));
check('الرصيد في القاعدة ٩٠', sql('SELECT qty_on_hand FROM stock LIMIT 1') === '90');

section('الفاتورة المطبوعة');
await page.click('a:has-text("طباعة الفاتورة")');
const printText = await page.locator('.print-doc').textContent();
check('الفاتورة تحتوي الشركة والرقم والعميل والقيمة', printText.includes('شركة النيل للأخشاب') && printText.includes('فاتورة بيع')
  && printText.includes('مؤسسة البناء الحديث') && printText.includes(AMOUNT_3000) && printText.includes('١٠\u00a0سم'));
await page.emulateMedia({ media: 'print' });
const hidden = await page.evaluate(() => ['.site-header', '.print-toolbar'].map((s) => getComputedStyle(document.querySelector(s)).display));
check('القوائم والأزرار مخفية عند الطباعة', hidden.every((d) => d === 'none'), JSON.stringify(hidden));
check('خط Cairo في الطباعة', (await page.evaluate(() => getComputedStyle(document.querySelector('.print-doc')).fontFamily)).includes('Cairo'));
await page.pdf({ path: path.join(OUT, 'invoice-a4.pdf'), format: 'A4', printBackground: true });
await page.screenshot({ path: path.join(OUT, 'invoice-print.png'), fullPage: true });
await page.emulateMedia({ media: 'screen' });
const saleUrl = page.url();

/* ---------------------------------------------------------------- */
section('التحويل بين المخازن');
await page.goto(BASE + 'index.php?r=transfer');
await page.selectOption('#warehouse_id', { label: 'المخزن الرئيسي' });
await page.selectOption('#to_warehouse_id', { label: 'مخزن الفرع' });
line = page.locator('[data-line]').first();
await line.locator('[data-line-item]').selectOption({ index: 1 });
await line.locator('[data-line-qty]').fill('20');
await settle(page);
check('حجم سطر التحويل ٠٫٣', (await line.locator('[data-line-volume]').textContent()) === '٠٫٣');
await page.click('button:has-text("حفظ التحويل")');
await page.waitForURL(/done=/);
check('تأكيد: تحويل رقم ١', (await text(page, '#done-title')).includes('تحويل رقم ١'));
check('الأرصدة بعد التحويل ٧٠ و٢٠', sql('SELECT GROUP_CONCAT(qty_on_hand ORDER BY warehouse_id) FROM stock') === '70,20');

/* ---------------------------------------------------------------- */
section('التحديث التلقائي بدون إعادة تحميل');
const viewer = await context.newPage();
watch(viewer, 'viewer');
await viewer.goto(BASE + 'index.php?r=inventory&warehouse=1');
const qtyCell = 'table.inventory-table tbody tr:first-child td:nth-child(5)';
check('المخزون في النافذة الثانية يعرض ٧٠', (await text(viewer, qtyCell)) === '٧٠');
const seller = await context.newPage();
watch(seller, 'seller');
await seller.goto(BASE + 'index.php?r=sell');
await seller.selectOption('#warehouse_id', { label: 'المخزن الرئيسي' });
await seller.locator('[data-line] [data-line-item]').first().selectOption({ index: 1 });
await settle(seller);
check('نموذج البيع المفتوح يعرض المتاح ٧٠', (await seller.locator('[data-line-available]').first().textContent()).includes('٧٠ قطعة'));
// بيع 5 من النافذة الأولى
await page.goto(BASE + 'index.php?r=sell');
await page.selectOption('#warehouse_id', { label: 'المخزن الرئيسي' });
line = page.locator('[data-line]').first();
await line.locator('[data-line-item]').selectOption({ index: 1 });
await line.locator('[data-line-qty]').fill('5');
await line.locator('[data-line-price]').fill('21000');
await page.click('button:has-text("مراجعة الفاتورة")');
await page.click('button:has-text("تأكيد البيع")');
await page.waitForURL(/done=/);
const sale2 = new URL(page.url()).searchParams.get('done');
let t0 = Date.now();
await viewer.waitForFunction((sel) => document.querySelector(sel).textContent.trim() === '٦٥', qtyCell, { timeout: 15000 }).catch(() => {});
check('جدول المخزون في النافذة الأخرى تحدّث تلقائيًا إلى ٦٥', (await text(viewer, qtyCell)) === '٦٥', `${Date.now() - t0}ms`);
await seller.waitForFunction(() => document.querySelector('[data-line-available]').textContent.includes('٦٥'), null, { timeout: 15000 }).catch(() => {});
check('المتاح في نموذج البيع المفتوح تحدّث تلقائيًا إلى ٦٥', (await seller.locator('[data-line-available]').first().textContent()).includes('٦٥ قطعة'));
await seller.locator('[data-line] [data-line-qty]').first().fill('66');
await settle(seller);
check('ما كتبه المستخدم في النموذج لم يُمسح بالتحديث', (await seller.locator('[data-line] [data-line-qty]').first().inputValue()) === '66');
check('تحذير التجاوز بناءً على الرصيد المحدث', (await seller.locator('[data-line-error]').first().textContent()).includes('٦٥'));

await viewer.goto(BASE + 'index.php?r=print&id=' + sale2);
check('الفاتورة الثانية سارية في النافذة الأخرى', (await viewer.locator('.print-cancelled').count()) === 0);
await page.goto(BASE + 'index.php?r=document&id=' + sale2);
await page.fill('#reason', 'تجربة الإلغاء');
await page.check('#confirm');
await page.click('button:has-text("إلغاء المستند")');
check('الإلغاء نجح', (await page.content()).includes('تم إلغاء'));
t0 = Date.now();
await viewer.waitForSelector('.print-cancelled', { timeout: 15000 }).catch(() => {});
check('الفاتورة المفتوحة في نافذة أخرى أظهرت «ملغاة» تلقائيًا', (await viewer.locator('.print-cancelled').count()) === 1, `${Date.now() - t0}ms`);
check('الكمية عادت مرة واحدة (٧٠)', sql('SELECT qty_on_hand FROM stock WHERE warehouse_id=1') === '70');
await page.goto(BASE + 'index.php?r=document&id=' + sale2);
check('المستند الملغى باقٍ في السجل بدون نموذج إلغاء', (await page.locator('#live-cancel').count()) === 0 && (await page.content()).includes('ملغى منذ'));

/* ---------------------------------------------------------------- */
section('أدوات الجداول: البحث السريع والأعمدة والتصدير والطباعة');
await page.goto(BASE + 'index.php?r=inventory');
await page.evaluate(() => localStorage.removeItem('wood.cols.inventory'));
await page.goto(BASE + 'index.php?r=inventory');
const tools = '[data-table-tools="inventory"]';
check('tables.js أضاف البحث والأعمدة والطباعة', (await page.locator(tools + ' input[type="search"]').count()) === 1
  && (await page.locator(tools + ' details.table-columns > summary').count()) === 1 && (await page.locator(tools + ' button[data-action="print"]').count()) === 1);
check('البحث السريع له تسمية', (await page.locator(tools + ' label', { hasText: 'بحث سريع في الجدول' }).count()) === 1);
const visibleRows = () => page.evaluate(() => [...document.querySelectorAll('#stock-table tbody tr:not(.row-subtotal)')].filter((r) => !r.hidden).length);
const allRows = await visibleRows();
await page.fill(tools + ' input[type="search"]', 'زان');
check('بحث بلا نتائج يخفي الصفوف ويقول ذلك', (await visibleRows()) === 0 && (await text(page, tools + ' .table-search-status')).includes('لا توجد صفوف مطابقة'));
check('الإجمالي الفرعي يختفي مع صفوف نوعه', (await page.evaluate(() => [...document.querySelectorAll('#stock-table .row-subtotal')].every((r) => r.hidden))));
await page.fill(tools + ' input[type="search"]', '90');
check('البحث بأرقام إنجليزية يطابق الأرقام العربية المعروضة (٩٠)', (await visibleRows()) === allRows && (await text(page, tools + ' .table-search-status')).startsWith('الصفوف الظاهرة'));
await page.fill(tools + ' input[type="search"]', '');
check('مسح البحث يعيد كل الصفوف', (await visibleRows()) === allRows && (await page.locator(tools + ' .table-search-status').isHidden()));

await page.click(tools + ' details.table-columns > summary');
check('العمود الأول لا يمكن إخفاؤه', await page.locator(tools + ' .table-columns-panel input[value="type"]').isDisabled());
await page.uncheck(tools + ' .table-columns-panel input[value="width"]');
const widthHidden = await page.evaluate(() => [...document.querySelectorAll('#stock-table [data-col="width"]')].every((c) => c.hidden));
const span = await page.evaluate(() => document.querySelector('#stock-table .row-subtotal th[data-span]').colSpan);
check('إخفاء عمود العرض من الرأس والصفوف', widthHidden);
check('خانة الإجمالي الممتدة تصغر إلى 3 أعمدة', span === 3, String(span));
await page.keyboard.press('Escape');
check('Escape يغلق قائمة الأعمدة', !(await page.locator(tools + ' details.table-columns').getAttribute('open')));
await page.goto(BASE + 'index.php?r=inventory');
check('المتصفح يتذكر الأعمدة بعد إعادة التحميل', await page.evaluate(() => document.querySelector('#stock-table th[data-col="width"]').hidden));
const [dl] = await Promise.all([page.waitForEvent('download'), page.click(tools + ' button[name="format"][value="csv"]')]);
const csvText = readFileSync(await dl.path(), 'utf8');
const csvHead = csvText.replace(/^﻿/, '').split('\r\n')[0];
check('التصدير بالأعمدة الظاهرة على الشاشة', csvHead.startsWith('النوع,التخانة') && !csvHead.includes('العرض'), csvHead);
check('اسم ملف التصدير', dl.suggestedFilename().startsWith('المخزون') && dl.suggestedFilename().endsWith('.csv'), dl.suggestedFilename());

// التحديث التلقائي يحافظ على البحث والأعمدة المخفية
await page.fill(tools + ' input[type="search"]', 'موسكي');
await page.locator('h1').click();
const p2 = await context.newPage();
watch(p2, 'p2');
await p2.goto(BASE + 'index.php?r=receive');
await p2.selectOption('#warehouse_id', { label: 'المخزن الرئيسي' });
await p2.selectOption('#wood_type_id', { label: 'موسكي' });
await p2.fill('#width', '10');
await p2.selectOption('#width_unit', 'cm');
await p2.fill('#thickness', '50');
await p2.selectOption('#thickness_unit', 'mm');
await p2.fill('#length', '3');
await p2.selectOption('#length_unit', 'm');
await p2.fill('#quantity', '5');
await p2.click('button:has-text("حفظ الوارد")');
await p2.waitForURL(/done=/);
await page.waitForFunction(() => document.querySelector('#stock-table tbody tr td[data-col="qty"]').textContent.trim() === '٩٥', null, { timeout: 15000 }).catch(() => {});
check('الجدول تحدّث تلقائيًا (٩٥)', (await text(page, '#stock-table tbody tr td[data-col="qty"]')) === '٩٥');
check('بعد التحديث: العمود المخفي ما زال مخفيًا', await page.evaluate(() => [...document.querySelectorAll('#stock-table [data-col="width"]')].every((c) => c.hidden)));
check('بعد التحديث: نص البحث باقٍ ومطبق', (await page.inputValue(tools + ' input[type="search"]')) === 'موسكي'
  && (await text(page, tools + ' .table-search-status')).startsWith('الصفوف الظاهرة'));
await p2.close();
await page.click(tools + ' details.table-columns > summary');
await page.check(tools + ' .table-columns-panel input[value="width"]');
await page.keyboard.press('Escape');

await page.emulateMedia({ media: 'print' });
const printState = await page.evaluate(() => ['.table-tools', '.filters', '.print-heading'].map((s) => getComputedStyle(document.querySelector(s)).display));
check('الطباعة: الأدوات والتصفية مخفية، ووصف التصفية ظاهر', printState[0] === 'none' && printState[1] === 'none' && printState[2] !== 'none', JSON.stringify(printState));
await page.emulateMedia({ media: 'screen' });

await page.goto(BASE + 'index.php?r=documents');
await page.click('#documents-table th[data-col="qty"] a.sort-link');
check('الترتيب من رأس العمود في السجل', page.url().includes('sort=qty%3Aasc') && (await page.getAttribute('#documents-table th[data-col="qty"]', 'aria-sort')) === 'ascending');
await page.goto(BASE + 'index.php?r=reports&report=sales_period');
const [dl2] = await Promise.all([page.waitForEvent('download'), page.click('[data-table-tools="report.sales_period"] button[value="xlsx"]')]);
check('تقرير المبيعات إلى Excel من المتصفح', readFileSync(await dl2.path()).subarray(0, 2).toString() === 'PK' && dl2.suggestedFilename().endsWith('.xlsx'), dl2.suggestedFilename());
await page.goto(BASE + 'index.php?r=print&id=' + new URL(saleUrl).searchParams.get('id'));
const [dl3] = await Promise.all([page.waitForEvent('download'), page.click('a:has-text("تحميل PDF")')]);
check('تحميل PDF الفاتورة من صفحة الطباعة', readFileSync(await dl3.path()).subarray(0, 5).toString() === '%PDF-' && dl3.suggestedFilename().endsWith('.pdf'), dl3.suggestedFilename());

/* ---------------------------------------------------------------- */
section('إمكانية الوصول وقواعد التصميم (كمبيوتر)');
const pages = ['inventory', 'receive', 'sell', 'transfer', 'documents', 'types', 'warehouses', 'settings', 'document&id=1', 'print&id=' + new URL(saleUrl).searchParams.get('id'),
  'account', 'users', 'users&edit=1', 'monitor', 'reports&report=sales_period', 'reports&report=cash_summary', 'dashboard'];
for (const r of pages) {
  await page.goto(BASE + 'index.php?r=' + r);
  await settle(page);
  const audit = await page.evaluate(() => {
    const out = [];
    const allowed = new Set([12, 14, 16, 20, 24, 32]);
    for (const el of document.querySelectorAll('body *')) {
      if (el.closest('select, template, script, style') || !el.getClientRects().length) { continue; }
      const cs = getComputedStyle(el);
      const ownText = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim());
      if (ownText && !allowed.has(parseFloat(cs.fontSize))) { out.push(`font ${el.tagName} ${cs.fontSize}`); }
      if (cs.backgroundImage !== 'none') { out.push(`background-image ${el.tagName}`); }
      if (cs.boxShadow !== 'none' && el !== document.activeElement) { out.push(`shadow ${el.tagName}.${el.className}`); }
    }
    for (const b of document.querySelectorAll('.btn')) {
      if (!b.getClientRects().length) { continue; }
      const h = Math.round(b.getBoundingClientRect().height);
      if (h < 36 || h > 44) { out.push(`button height ${h}`); }
    }
    const unlabeled = [...document.querySelectorAll('input, select, textarea')]
      .filter((el) => el.type !== 'hidden' && el.getClientRects().length && !el.classList.contains('default-submit'))
      .filter((el) => !(el.labels && el.labels.length) && !el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby'));
    unlabeled.forEach((el) => out.push(`no label ${el.name || el.id}`));
    if (/[—–]/.test(document.body.innerText)) { out.push('long dash in UI text'); }
    const main = document.querySelector('main.container');
    if (parseFloat(getComputedStyle(main).maxWidth) > 1280) { out.push('container too wide'); }
    return out;
  });
  check(`قواعد التصميم والتسميات: ${r}`, audit.length === 0, audit.slice(0, 6).join(' | '));
}
await page.goto(BASE + 'index.php?r=receive');
await page.keyboard.press('Tab');
await page.keyboard.press('Tab');
const focusStyle = await page.evaluate(() => { const cs = getComputedStyle(document.activeElement); return cs.outlineStyle + ' ' + cs.boxShadow; });
check('التركيز بلوحة المفاتيح مرئي', !/^none none$/.test(focusStyle), focusStyle);

/* ---------------------------------------------------------------- */
for (const vp of [{ width: 390, height: 844, name: 'mobile390' }, { width: 360, height: 800, name: 'mobile360' }]) {
  section(`الموبايل ${vp.width}px`);
  const mctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, isMobile: true, hasTouch: true, storageState: await context.storageState() });
  const m = await mctx.newPage();
  watch(m, vp.name);
  for (const r of pages) {
    await m.goto(BASE + 'index.php?r=' + r);
    await settle(m);
    const overflow = await m.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check(`بدون تمرير أفقي للصفحة: ${r}`, overflow <= 1, `overflow=${overflow}px`);
    await m.screenshot({ path: path.join(OUT, `${vp.name}-${r.replace(/[^a-z0-9]+/gi, '_')}.png`), fullPage: true });
  }
  await mctx.close();
}
await page.goto(BASE + 'index.php?r=inventory');
await page.screenshot({ path: path.join(OUT, 'inventory-desktop.png'), fullPage: true });
await page.goto(BASE + 'index.php?r=documents');
await page.screenshot({ path: path.join(OUT, 'documents-desktop.png'), fullPage: true });

section('أخطاء المتصفح');
check('لا أخطاء JavaScript ولا CSP ولا نوافذ تنبيه', problems.length === 0, problems.slice(0, 5).join(' | '));

await browser.close();
console.log(`\nE2E: ${pass} passed, ${fails.length} failed`);
fails.forEach((f) => console.log('  - ' + f));
process.exit(fails.length ? 1 : 0);
