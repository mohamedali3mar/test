/*
 * فحص الواجهة المتجاوبة على مقاسات شاشات متعددة (Chromium عبر Playwright).
 * يسجل الدخول ثم يفتح كل صفحة على كل مقاس ويتحقق من:
 *   - عدم وجود تمرير أفقي للصفحة، وعدم خروج أي عنصر عن عرض الشاشة (إلا داخل إطار جدول يتمرر)
 *   - الأزرار بين 36 و44 بكسل دائمًا، وكل عناصر اللمس 44 بكسل على الأقل على عرض 720 فأقل
 *   - مسافة 8 بكسل على الأقل بين عناصر اللمس المتجاورة على الموبايل
 *   - حجم خط الحقول 16 على الموبايل (لا تكبير تلقائي في iOS)
 *   - اسم مقروء لكل حقل، وأحجام الخط من السلم فقط
 *   - قائمة الموبايل (details/summary) تفتح وتعرض كل الروابط وزر الخروج
 *   - الجداول الرئيسية تتحول إلى صفوف مكدسة مع اسم العمود على الموبايل، وتبقى جداول على الكمبيوتر
 * ويحفظ لقطة كاملة لكل صفحة في tests/output/screens/responsive/<العرض>-<الصفحة>.png
 *
 * يحتاج موقعًا مثبتًا فيه بيانات (أنواع ومخازن ووارد وفواتير بيع).
 * التشغيل: WOOD_UI_BASE=http://localhost:8096/ node tests/ui_responsive.mjs
 * متغيرات اختيارية: WOOD_UI_USER وWOOD_UI_PASS وWOOD_UI_OUT
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = (process.env.WOOD_UI_BASE || 'http://localhost:8080/').replace(/\/?$/, '/');
const USER = process.env.WOOD_UI_USER || 'admin';
const PASS = process.env.WOOD_UI_PASS || 'Correct-Horse-9';
const OUT = process.env.WOOD_UI_OUT || path.join(path.dirname(fileURLToPath(import.meta.url)), 'output', 'screens', 'responsive');
mkdirSync(OUT, { recursive: true });

const MOBILE_MAX = 720;
const VIEWPORTS = [
  { width: 360, height: 740 },
  { width: 390, height: 844 },
  { width: 412, height: 915 },
  { width: 768, height: 1024 },
  { width: 1024, height: 768 },
  { width: 1366, height: 768 },
  { width: 1920, height: 1080 },
];
const NAV_LABELS = ['المخزون', 'إضافة وارد', 'فاتورة بيع', 'تحويل بين المخازن', 'الفواتير والحركات', 'أنواع الخشب', 'المخازن', 'الإعدادات',
  'العملاء', 'الموردون', 'السندات', 'الخزائن', 'لوحة التحكم', 'التقارير', 'المراقبة', 'المستخدمون'];
// القائمة على الكمبيوتر مجمعة (details/summary): المجموعات ظاهرة، والروابط داخلها تظهر عند الفتح
const NAV_GROUP_LABELS = ['المخزون', 'المبيعات', 'الحسابات', 'التقارير', 'الإدارة'];

let pass = 0;
const fails = [];
function check(label, ok, detail = '') {
  if (ok) { pass++; return; }
  fails.push(label + (detail ? ' -> ' + detail : ''));
  console.log('  FAIL  ' + label + (detail ? '  -> ' + detail : ''));
}

const browser = await chromium.launch();
const problems = [];
function watch(page, name) {
  page.on('console', (m) => { if (m.type() === 'error') { problems.push(`${name}: console ${m.text()}`); } });
  page.on('pageerror', (e) => problems.push(`${name}: pageerror ${e.message}`));
  page.on('dialog', (d) => { problems.push(`${name}: dialog ${d.message()}`); d.dismiss(); });
}

/* ---------- الدخول واختيار مستند للفحص ---------- */
const loginCtx = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'ar-EG' });
const lp = await loginCtx.newPage();
watch(lp, 'login');
await lp.goto(BASE + 'index.php?r=login');
if (await lp.locator('#password').count()) {
  await lp.fill('#username', USER);
  await lp.fill('#password', PASS);
  await lp.click('button:has-text("دخول")');
}
if (!lp.url().includes('r=inventory')) {
  console.log('تعذر الدخول. تأكد من WOOD_UI_BASE وWOOD_UI_USER وWOOD_UI_PASS وأن الموقع مثبت.');
  await browser.close();
  process.exit(2);
}
const storageState = await loginCtx.storageState();

// أكثر فاتورة بيع سارية أسطرًا (لعرض جدول أسطر حقيقي)، وإلا أول مستند
await lp.goto(BASE + 'index.php?r=documents');
const docs = await lp.evaluate(() => [...document.querySelectorAll('#live-documents tbody tr')].map((tr) => {
  const a = tr.querySelector('a[href*="r=document"]');
  const cells = [...tr.cells].map((c) => c.textContent.trim());
  const digits = (s) => Number((s || '').replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/\D/g, '') || 0);
  return { id: a ? new URL(a.href).searchParams.get('id') : null, label: a ? a.textContent.trim() : '', cancelled: tr.classList.contains('row-cancelled'), lines: digits(cells[4]) };
}).filter((d) => d.id));
await loginCtx.close();
check('يوجد مستندات للفحص (شغّل بيانات تجريبية أولًا)', docs.length > 0);
const sales = docs.filter((d) => d.label.includes('بيع') && !d.cancelled).sort((a, b) => b.lines - a.lines);
const docId = (sales[0] || docs[0] || { id: '1' }).id;
const firstOf = (word) => (docs.find((d) => d.label.includes(word)) || {}).id;
const doneIds = { receive: firstOf('وارد'), sell: sales[0] && sales[0].id, transfer: firstOf('تحويل') };

const PAGES = [
  { name: 'inventory', route: 'inventory', nav: 'المخزون' },
  { name: 'receive', route: 'receive', nav: 'إضافة وارد' },
  { name: 'sell', route: 'sell', nav: 'فاتورة بيع' },
  { name: 'sell-review', route: 'sell', nav: 'فاتورة بيع', review: true },
  { name: 'transfer', route: 'transfer', nav: 'تحويل بين المخازن' },
  { name: 'documents', route: 'documents', nav: 'الفواتير والحركات' },
  { name: 'document', route: 'document&id=' + docId, nav: 'الفواتير والحركات' },
  { name: 'print', route: 'print&id=' + docId, nav: 'الفواتير والحركات' },
  { name: 'types', route: 'types', nav: 'أنواع الخشب' },
  { name: 'types-edit', route: 'types&edit=FIRST', nav: 'أنواع الخشب' },
  { name: 'warehouses', route: 'warehouses', nav: 'المخازن' },
  { name: 'settings', route: 'settings', nav: 'الإعدادات' },
  { name: 'reports-sales', route: 'reports&report=sales_period', nav: 'التقارير' },
  { name: 'reports-cash', route: 'reports&report=cash_summary', nav: 'التقارير' },
  { name: 'dashboard', route: 'dashboard', nav: 'لوحة التحكم' },
  // حالات عدم وجود نتائج
  { name: 'inventory-empty', route: 'inventory&q=zzzz-no-match', nav: 'المخزون' },
  { name: 'documents-empty', route: 'documents&q=zzzz-no-match', nav: 'الفواتير والحركات' },
  // صناديق التأكيد بعد الحفظ (عرض فقط، لا يُحفظ شيء)
  ...Object.entries(doneIds).filter(([, id]) => id).map(([r, id]) => (
    { name: r + '-done', route: `${r}&done=${id}`, nav: { receive: 'إضافة وارد', sell: 'فاتورة بيع', transfer: 'تحويل بين المخازن' }[r] })),
];

/* ---------- الفحص داخل الصفحة ---------- */
function audit({ vw, mobileMax }) {
  const out = [];
  const mobile = vw <= mobileMax;
  const allowed = new Set([12, 14, 16, 20, 24, 32]);
  const desc = (el) => {
    const t = (el.textContent || el.getAttribute('aria-label') || el.name || '').trim().replace(/\s+/g, ' ').slice(0, 30);
    return `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : ''}${t ? ' "' + t + '"' : ''}`;
  };
  // checkVisibility يستبعد أيضًا محتوى details المغلق (content-visibility: hidden)
  const shown = (el) => {
    if (!el.getClientRects().length) { return false; }
    if (el.checkVisibility && !el.checkVisibility({ visibilityProperty: true })) { return false; }
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden') { return false; }
    return !el.closest('.visually-hidden, .default-submit, [hidden], template');
  };

  // 1) أحجام الخط من السلم فقط (والنص المولد قبل الخلايا في الجداول المكدسة)
  for (const el of document.querySelectorAll('body *')) {
    if (el.closest('select, template, script, style') || !el.getClientRects().length) { continue; }
    const cs = getComputedStyle(el);
    const ownText = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim());
    if (ownText && !allowed.has(parseFloat(cs.fontSize))) { out.push(`font-size ${cs.fontSize}: ${desc(el)}`); }
    const before = getComputedStyle(el, '::before');
    if (before.content && before.content !== 'none' && before.content !== 'normal' && before.content !== '""' && !allowed.has(parseFloat(before.fontSize))) {
      out.push(`::before font-size ${before.fontSize}: ${desc(el)}`);
    }
  }

  // 2) لا عنصر أعرض من الشاشة أو خارجها، إلا داخل إطار يتمرر أفقيًا
  const clippedByFrame = (el) => {
    for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
      const ox = getComputedStyle(p).overflowX;
      if (ox !== 'visible') {
        const r = p.getBoundingClientRect();
        return r.left >= -1 && r.right <= vw + 1;
      }
    }
    return false;
  };
  let wide = 0;
  for (const el of document.querySelectorAll('body *')) {
    if (!shown(el) || el.classList.contains('skip-link')) { continue; }
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) { continue; }
    if ((r.width > vw + 1 || r.right > vw + 1 || r.left < -1) && !clippedByFrame(el)) {
      if (wide++ < 5) { out.push(`outside viewport (${Math.round(r.left)}..${Math.round(r.right)}): ${desc(el)}`); }
    }
  }

  // 3) ارتفاع الأزرار وعناصر اللمس
  for (const b of document.querySelectorAll('.btn')) {
    if (!shown(b)) { continue; }
    const h = Math.round(b.getBoundingClientRect().height);
    if (h < 36 || h > 44) { out.push(`.btn height ${h}: ${desc(b)}`); }
  }
  const targets = [];
  if (mobile) {
    const sel = 'button, input, select, textarea, summary, .row-actions a, .main-nav a, .nav-menu a, .table-stack td a, .table-stack th a, .pager a, .empty a';
    for (const el of document.querySelectorAll(sel)) {
      if (!shown(el) || el.type === 'hidden') { continue; }
      const box = (el.type === 'checkbox' || el.type === 'radio') ? (el.closest('.field-check') || el.closest('label') || el) : el;
      const r = box.getBoundingClientRect();
      if (Math.round(r.height) < 44) { out.push(`touch target ${Math.round(r.height)}px: ${desc(el)}`); }
      if (/^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName) && el.type !== 'checkbox' && el.type !== 'radio' && parseFloat(getComputedStyle(el).fontSize) < 16) {
        out.push(`input font-size ${getComputedStyle(el).fontSize} (iOS zoom): ${desc(el)}`);
      }
      if (!targets.some((t) => t.box === box)) { targets.push({ el, box, r }); }
    }
    // 4) مسافة 8 بكسل على الأقل بين عناصر اللمس المتجاورة
    let close = 0;
    for (let i = 0; i < targets.length; i++) {
      for (let j = i + 1; j < targets.length; j++) {
        const a = targets[i].r;
        const b = targets[j].r;
        if (targets[i].box.contains(targets[j].box) || targets[j].box.contains(targets[i].box)) { continue; }
        const dx = Math.max(0, Math.max(a.left, b.left) - Math.min(a.right, b.right));
        const dy = Math.max(0, Math.max(a.top, b.top) - Math.min(a.bottom, b.bottom));
        if ((dy === 0 && dx < 7.5) || (dx === 0 && dy < 7.5)) {
          if (close++ < 5) { out.push(`touch targets ${Math.round(Math.max(dx, dy))}px apart: ${desc(targets[i].el)} / ${desc(targets[j].el)}`); }
        }
      }
    }
  }

  // 5) اسم مقروء لكل حقل
  for (const el of document.querySelectorAll('input, select, textarea')) {
    if (el.type === 'hidden' || !shown(el)) { continue; }
    const named = (el.labels && [...el.labels].some((l) => l.textContent.trim()))
      || (el.getAttribute('aria-label') || '').trim()
      || (el.getAttribute('aria-labelledby') || '').split(/\s+/).some((id) => id && document.getElementById(id) && document.getElementById(id).textContent.trim());
    if (!named) { out.push(`no accessible name: ${desc(el)}`); }
  }

  // 6) الجداول المكدسة: صفوف مستقلة مع اسم العمود على الموبايل، وجدول عادي على الكمبيوتر
  for (const wrap of document.querySelectorAll('.table-stack')) {
    if (!shown(wrap)) { continue; }
    const table = wrap.querySelector('table');
    const tr = table.querySelector('tbody tr');
    if (!tr) { continue; }
    const display = getComputedStyle(tr).display;
    if (mobile) {
      if (display === 'table-row') { out.push(`table not stacked on mobile: ${desc(table.querySelector('caption') || table)}`); }
      for (const cell of table.querySelectorAll('tbody td, tfoot td')) {
        if (!shown(cell) || !cell.textContent.trim() || cell.classList.contains('cell-actions')) { continue; }
        const label = getComputedStyle(cell, '::before').content;
        if (!cell.dataset.label || label === 'none' || label === '""') { out.push(`stacked cell without column name: ${desc(cell)}`); }
      }
      if (getComputedStyle(wrap).overflowX !== 'visible' && wrap.scrollWidth > wrap.clientWidth + 1) { out.push('stacked table still scrolls sideways'); }
    } else if (display !== 'table-row') {
      out.push(`table not a table on desktop (${display})`);
    }
  }

  // 7) لا شرطة طويلة في النص
  if (/[\u2014\u2013]/.test(document.body.innerText)) { out.push('long dash in UI text'); }
  return out;
}

/* ---------- قائمة التنقل ---------- */
async function checkMenu(page, vp, pageDef, shot) {
  const mobile = vp.width <= MOBILE_MAX;
  const state = await page.evaluate(() => {
    const vis = (el) => !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'
      && (!el.checkVisibility || el.checkVisibility({ visibilityProperty: true }));
    const details = document.querySelector('.site-header details.nav-menu');
    const summary = details && details.querySelector('summary');
    const deskNav = document.querySelector('.site-header .main-nav');
    const logout = document.querySelector('.site-header .logout-form button');
    const header = document.querySelector('.site-header .header-row');
    return {
      details: vis(details), summary: summary ? summary.textContent.replace(/\s+/g, ' ').trim() : '',
      open: details ? details.open : false,
      deskLinks: deskNav ? [...deskNav.querySelectorAll('a')].map((a) => a.textContent.trim()) : [],
      deskGroups: deskNav ? [...deskNav.querySelectorAll('details.nav-group > summary')].filter(vis).map((x) => x.textContent.trim()) : [],
      deskCurrent: deskNav ? [...deskNav.querySelectorAll('a[aria-current="page"]')].map((a) => a.textContent.trim()) : [],
      deskVisibleLinks: deskNav ? [...deskNav.querySelectorAll('a')].filter(vis).length : 0,
      menuLinks: details ? [...details.querySelectorAll('a')].filter(vis).map((a) => a.textContent.trim()) : [],
      current: [...document.querySelectorAll('.site-header a[aria-current="page"]')].filter(vis).map((a) => a.textContent.trim()),
      logout: vis(logout),
      headerHeight: header ? Math.round(header.getBoundingClientRect().height) : 0,
    };
  });
  const tag = `${vp.width} ${pageDef.name}`;
  check(`${tag}: logout reachable`, state.logout || mobile);
  if (!mobile) {
    check(`${tag}: horizontal nav groups visible`, NAV_GROUP_LABELS.every((l) => state.deskGroups.includes(l)), state.deskGroups.join(','));
    check(`${tag}: nav groups hold all links (collapsed)`, NAV_LABELS.every((l) => state.deskLinks.includes(l)) && state.deskVisibleLinks === 0, state.deskLinks.join(','));
    check(`${tag}: menu toggle hidden on desktop`, !state.details);
    check(`${tag}: current page marked`, state.deskCurrent.length === 1 && state.deskCurrent[0] === pageDef.nav, state.deskCurrent.join(','));
    return;
  }
  check(`${tag}: menu toggle visible`, state.details && !state.open);
  check(`${tag}: toggle names القائمة and the current page`, state.summary.includes('القائمة') && state.summary.includes(pageDef.nav), state.summary);
  check(`${tag}: links collapsed before opening`, state.deskVisibleLinks === 0 && state.menuLinks.length === 0, state.menuLinks.join(','));
  check(`${tag}: logout visible`, state.logout);
  if (!state.details) { return; }
  await page.click('.site-header details.nav-menu > summary');
  const opened = await page.evaluate(() => {
    const vis = (el) => !!el && el.getClientRects().length > 0 && (!el.checkVisibility || el.checkVisibility({ visibilityProperty: true }));
    const details = document.querySelector('.site-header details.nav-menu');
    const links = [...details.querySelectorAll('a')].filter(vis);
    return {
      open: details.open,
      links: links.map((a) => a.textContent.trim()),
      heights: links.map((a) => Math.round(a.getBoundingClientRect().height)),
      current: links.filter((a) => a.getAttribute('aria-current') === 'page').map((a) => a.textContent.trim()),
      logout: vis(document.querySelector('.site-header .logout-form button')),
      overflow: Math.max(document.documentElement.scrollWidth, window.innerWidth) - document.documentElement.clientWidth,
    };
  });
  check(`${tag}: menu opens`, opened.open);
  check(`${tag}: menu shows all links`, NAV_LABELS.every((l) => opened.links.includes(l)), opened.links.join(','));
  check(`${tag}: menu links >= 44px`, opened.heights.every((h) => h >= 44), opened.heights.join(','));
  check(`${tag}: current page marked in menu`, opened.current.length === 1 && opened.current[0] === pageDef.nav, opened.current.join(','));
  check(`${tag}: logout reachable with menu open`, opened.logout);
  check(`${tag}: no horizontal overflow with menu open`, opened.overflow <= 1, `${opened.overflow}px`);
  if (shot) {
    await page.screenshot({ path: path.join(OUT, `${vp.width}-menu-open.png`), fullPage: false });
  }
  await page.click('.site-header details.nav-menu > summary');
}

/* ---------- المرور على المقاسات والصفحات ---------- */
const matrix = [];
for (const vp of VIEWPORTS) {
  const touch = vp.width < 1024;
  const ctx = await browser.newContext({
    viewport: vp, isMobile: touch, hasTouch: touch, locale: 'ar-EG', storageState,
  });
  const page = await ctx.newPage();
  watch(page, String(vp.width));
  let firstTypeId = null;
  for (const p of PAGES) {
    let route = p.route;
    if (route.includes('edit=FIRST')) {
      if (!firstTypeId) {
        await page.goto(BASE + 'index.php?r=types');
        const href = await page.locator('#live-catalog a[href*="edit="]').first().getAttribute('href').catch(() => null);
        firstTypeId = href ? new URL(href, BASE).searchParams.get('edit') : '1';
      }
      route = route.replace('FIRST', firstTypeId);
    }
    await page.goto(BASE + 'index.php?r=' + route);
    if (p.review) {
      // مراجعة فاتورة بسطرين من المخزن المختار (لا يُحفظ شيء)
      const wh = await page.locator('#warehouse_id option[value]:not([value=""])').first().getAttribute('value').catch(() => null);
      if (wh) {
        await page.selectOption('#warehouse_id', wh);
        await page.waitForTimeout(150);
        for (let i = 0; i < 2; i++) {
          const line = page.locator('[data-line]').nth(i);
          if ((await line.locator('[data-line-item] option').count()) > i + 1) {
            await line.locator('[data-line-item]').selectOption({ index: i + 1 });
            await line.locator('[data-line-qty]').fill('1');
            await line.locator('[data-line-price]').fill('15000');
          }
        }
        await page.click('button:has-text("مراجعة الفاتورة")');
      }
      check(`${vp.width} sell-review: review page opened`, (await page.locator('h1').first().textContent()).includes('مراجعة'));
    }
    if (p.name.endsWith('-done')) {
      check(`${vp.width} ${p.name}: confirmation box shown`, (await page.locator('.confirm-box').count()) === 1);
    }
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(150);
    const overflow = await page.evaluate((w) => Math.max(document.documentElement.scrollWidth, window.innerWidth, document.body.scrollWidth) - w, vp.width);
    check(`${vp.width} ${p.name}: no page-level horizontal overflow`, overflow <= 1, `${overflow}px`);
    const issues = await page.evaluate(audit, { vw: vp.width, mobileMax: MOBILE_MAX });
    issues.forEach((i) => check(`${vp.width} ${p.name}: ${i}`, false));
    if (!issues.length) { pass++; }
    await page.screenshot({ path: path.join(OUT, `${vp.width}-${p.name}.png`), fullPage: true });
    const before = fails.length;
    await checkMenu(page, vp, p, p.name === 'inventory');
    matrix.push({ vp: vp.width, page: p.name, ok: overflow <= 1 && !issues.length && fails.length === before });
  }
  await ctx.close();
}

check('no JavaScript, CSP or dialog errors', problems.length === 0, problems.slice(0, 5).join(' | '));
await browser.close();

/* ---------- ملخص المصفوفة ---------- */
const names = PAGES.map((p) => p.name);
console.log('\n' + 'viewport'.padEnd(10) + names.map((n) => n.padEnd(17)).join(''));
for (const vp of VIEWPORTS) {
  console.log(String(vp.width).padEnd(10) + names.map((n) => (matrix.find((m) => m.vp === vp.width && m.page === n)?.ok ? 'ok' : 'FAIL').padEnd(17)).join(''));
}
console.log(`\nUI responsive: ${pass} passed, ${fails.length} failed. Screenshots: ${OUT}`);
process.exit(fails.length ? 1 : 0);
