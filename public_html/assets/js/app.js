/*
 * نظام مخزون الأخشاب: سكربت الواجهة. ملف واحد بلا مكتبات ولا خطوة بناء.
 *
 * WoodCalc: نسخة مطابقة لقواعد الخادم (app/lib/measure.php و format.php) للحساب الفوري بدقة كاملة
 * باستخدام BigInt. الخادم يبقى المرجع النهائي ويعيد الحساب والتحقق عند الحفظ.
 * أي تعديل في القواعد أو الرسائل هناك يُنقل هنا، واختبار التطابق في tests/ يكشف أي اختلاف.
 */
(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.WoodCalc = api;
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var UNITS = {
    mm: { label: 'مللي', decimals: 3 },
    cm: { label: 'سم', decimals: 4 },
    m: { label: 'متر', decimals: 6 }
  };
  var DIMENSIONS = [['width', 'العرض'], ['thickness', 'التخانة'], ['length', 'الطول']];
  // المتصفحات القديمة جدًا بدون BigInt: لا تعمل المعاينة الفورية، والخادم يحسب ويتحقق كالمعتاد
  var big = typeof BigInt === 'function' ? BigInt : function () { return 0; };
  var MAX_DIM_UM = big(50000000);
  var MAX_QTY = 1000000;
  var MAX_PRICE = big(1000000000);
  var SCALE = 18;
  var cfg = { digits: 'arabic', volDecimals: 'full', volPad: '0' };

  function configure(c) {
    Object.keys(c || {}).forEach(function (k) { if (c[k] !== undefined) { cfg[k] = c[k]; } });
  }

  var DIGIT_IN = { '٫': '.' };
  for (var d = 0; d < 10; d++) {
    DIGIT_IN[String.fromCharCode(0x0660 + d)] = String(d);
    DIGIT_IN[String.fromCharCode(0x06F0 + d)] = String(d);
  }

  function normalize(raw) {
    var s = String(raw).replace(/[​-‏‪-‮⁦-⁩؜﻿]/g, '');
    s = s.replace(/[٠-٩۰-۹٫]/g, function (ch) { return DIGIT_IN[ch]; });
    return s.replace(/^[\s ]+|[\s ]+$/g, '');
  }

  /* يعيد {v: BigInt} أو {e: رمز الخطأ} بنفس ترتيب الفحص في PHP */
  function parseDecimal(raw, maxDec) {
    var s = normalize(raw);
    if (s === '') { return { e: 'empty' }; }
    if (/[,،٬]/.test(s)) { return { e: 'comma' }; }
    if (/^[-\u2212\u2013]/.test(s)) { return { e: 'negative' }; }
    if (s.indexOf('/') !== -1) { return { e: 'fraction' }; }
    var m = /^(\d*)(?:\.(\d*))?$/.exec(s);
    if (!m || (m[1] === '' && (m[2] || '') === '')) { return { e: 'invalid' }; }
    var intPart = m[1].replace(/^0+/, '');
    var frac = (m[2] || '').replace(/0+$/, '');
    if (frac.length > maxDec) { return { e: 'decimals' }; }
    if (intPart.length > 15) { return { e: 'too_large' }; }
    var v = BigInt((intPart || '0') + frac.padEnd(maxDec, '0'));
    if (v === BigInt(0)) { return { e: 'zero' }; }
    return { v: v };
  }

  /* مثل digits() في PHP: العلامة العشرية «٫» وفاصل الآلاف مسافة ضيقة (U+202F) حتى لا يلتبس المبلغ */
  function digits(s) {
    s = String(s);
    if (cfg.digits !== 'arabic') { return s; }
    return s.replace(/[0-9.,]/g, function (c) {
      if (c === '.') { return '٫'; }
      if (c === ',') { return ' '; }
      return String.fromCharCode(0x0660 + c.charCodeAt(0) - 48);
    });
  }

  function group(s) {
    var parts = String(s).split('.');
    var g = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return parts.length > 1 ? g + '.' + parts[1] : g;
  }

  function toDecimal(b, scale) {
    var s = b.toString();
    if (scale <= 0) { return s; }
    while (s.length < scale + 1) { s = '0' + s; }
    return s.slice(0, -scale) + '.' + s.slice(-scale);
  }

  function trimDecimal(s) {
    if (s.indexOf('.') === -1) { return s; }
    s = s.replace(/0+$/, '').replace(/\.$/, '');
    return s === '' ? '0' : s;
  }

  /* round(b / 10^n) بتقريب النصف لأعلى */
  function halfUp(b, n) {
    if (n <= 0) { return b; }
    var p = BigInt(10) ** BigInt(n);
    return (b + p / BigInt(2)) / p;
  }

  function fmtInt(n) { return digits(group(String(n))); }

  function fmtVolume(um3) {
    var mode = cfg.volDecimals;
    if (!/^[2-6]$/.test(mode)) {
      var exact = trimDecimal(toDecimal(um3, SCALE));
      var dec = exact.indexOf('.') === -1 ? 0 : exact.split('.')[1].length;
      if (dec <= 9) { return digits(group(exact)); }
      var r9 = trimDecimal(toDecimal(halfUp(um3, SCALE - 9), 9));
      return r9 === '0' ? 'أقل من ' + digits('0.000000001') : '≈ ' + digits(group(r9));
    }
    var n = Number(mode);
    var rb = halfUp(um3, SCALE - n);
    if (um3 !== BigInt(0) && rb === BigInt(0)) {
      return 'أقل من ' + digits('0.' + '0'.repeat(n - 1) + '1');
    }
    var r = toDecimal(rb, n);
    if (cfg.volPad !== '1') { r = trimDecimal(r); }
    return digits(group(r));
  }

  function fmtMoney(piasters) { return digits(group(toDecimal(piasters, 2))); }
  function fmtMeters(um) { return trimDecimal(toDecimal(um, 6)); }

  function parseDimension(raw, unit, label) {
    if (!UNITS[unit]) { return { msg: 'اختر وحدة ' + label + '.' }; }
    var dec = UNITS[unit].decimals;
    var r = parseDecimal(raw, dec);
    if (!r.e && r.v > MAX_DIM_UM) { r = { e: 'too_large' }; }
    if (!r.e) { return { v: r.v }; }
    var ex = digits('2.5');
    switch (r.e) {
      case 'empty': return { msg: 'أدخل ' + label + '.' };
      case 'comma': return { msg: label + ': لا تستخدم الفاصلة. استخدم النقطة للكسور، مثل ' + ex };
      case 'negative': return { msg: label + ' لا يقبل قيمًا سالبة.' };
      case 'fraction': return { msg: label + ': اكتب الكسر بالنقطة العشرية، مثل ' + ex };
      case 'zero': return { msg: label + ' يجب أن يكون أكبر من صفر.' };
      case 'decimals': return { msg: label + ' بوحدة ' + UNITS[unit].label + ' يقبل ' + digits(String(dec)) + ' أرقام عشرية على الأكثر.' };
      case 'too_large': return { msg: label + ' أكبر من الحد المسموح (' + digits('50') + ' متر).' };
      default: return { msg: label + ': أدخل رقمًا موجبًا، مثل ' + digits('10') + ' أو ' + ex };
    }
  }

  function parseQuantity(raw) {
    var s = normalize(raw);
    if (s === '') { return { msg: 'أدخل عدد القطع.' }; }
    if (/^[-\u2212\u2013]/.test(s)) { return { msg: 'عدد القطع لا يقبل قيمًا سالبة.' }; }
    if (/[,،٬]/.test(s)) { return { msg: 'اكتب عدد القطع بدون فواصل.' }; }
    if (!/^\d+$/.test(s)) { return { msg: 'عدد القطع يجب أن يكون عددًا صحيحًا بدون كسور.' }; }
    s = s.replace(/^0+/, '');
    if (s === '') { return { msg: 'عدد القطع يجب أن يكون أكبر من صفر.' }; }
    if (s.length > 7 || Number(s) > MAX_QTY) {
      return { msg: 'عدد القطع أكبر من الحد المسموح في السطر الواحد (' + fmtInt(MAX_QTY) + ' قطعة).' };
    }
    return { v: Number(s) };
  }

  function parsePrice(raw) {
    var r = parseDecimal(raw, 2);
    if (!r.e && r.v > MAX_PRICE) { r = { e: 'too_large' }; }
    if (!r.e) { return { v: r.v }; }
    switch (r.e) {
      case 'empty': return { msg: 'أدخل سعر المتر المكعب.' };
      case 'comma': return { msg: 'السعر: اكتب الرقم بدون فواصل الآلاف، واستخدم النقطة للكسور، مثل ' + digits('20000.50') };
      case 'negative': return { msg: 'السعر لا يقبل قيمًا سالبة.' };
      case 'fraction': return { msg: 'السعر: اكتب الكسر بالنقطة العشرية، مثل ' + digits('20000.50') };
      case 'zero': return { msg: 'سعر المتر المكعب يجب أن يكون أكبر من صفر.' };
      case 'decimals': return { msg: 'السعر يقبل رقمين عشريين على الأكثر (القروش).' };
      case 'too_large': return { msg: 'السعر أكبر من الحد المسموح (' + fmtInt(10000000) + ' للمتر المكعب).' };
      default: return { msg: 'السعر: أدخل رقمًا موجبًا، مثل ' + digits('20000') };
    }
  }

  /* قيمة السطر بالقروش: حجم الكمية (ميكرومتر³) × السعر بالقروش ÷ 10^18، تقريب النصف لأعلى */
  function amountPiasters(totalUm3, pricePiasters) { return halfUp(totalUm3 * pricePiasters, SCALE); }

  /* ---------- توقيت التحديث التلقائي (منطق نقي بلا متصفح، يُختبر في tests/live_timing.cjs) ---------- */
  var LIVE = {
    fastMs: 2000,         // المستخدم نشط: فحص كل ثانيتين
    slowMs: 10000,        // بعد خمول طويل: فحص كل 10 ثوانٍ
    idleAfterMs: 600000,  // الخمول: 10 دقائق بلا مؤشر ولا لوحة مفاتيح ولا تمرير ولا لمس
    retryMs: 2000,        // أول انتظار بعد إخفاق، ويتضاعف مع كل إخفاق متتالٍ
    retryMaxMs: 30000     // أقصى انتظار بين المحاولات
  };

  /*
   * مهلة الفحص التالي بالمللي ثانية، أو null إذا لا يجب الفحص الآن.
   *   state.hidden   التبويب مخفي: لا فحص حتى يظهر (null)
   *   state.idleFor  المدة منذ آخر نشاط للمستخدم
   *   state.failures عدد الإخفاقات المتتالية: 2 ثم 4 ثم 8 ثم 16 ثم 30 ثانية حدًا أقصى،
   *                  ولا تقل المهلة عن مهلة الخمول حتى لا يزيد الضغط على الخادم وقت الخمول
   */
  function liveDelay(state) {
    state = state || {};
    if (state.hidden) { return null; }
    var base = Number(state.idleFor) > LIVE.idleAfterMs ? LIVE.slowMs : LIVE.fastMs;
    var failures = Math.floor(Number(state.failures) || 0);
    if (failures <= 0) { return base; }
    var retry = Math.min(LIVE.retryMaxMs, LIVE.retryMs * Math.pow(2, Math.min(failures, 16) - 1));
    return Math.max(base, retry);
  }

  return {
    UNITS: UNITS, DIMENSIONS: DIMENSIONS, configure: configure, normalize: normalize, parseDecimal: parseDecimal,
    parseDimension: parseDimension, parseQuantity: parseQuantity, parsePrice: parsePrice, amountPiasters: amountPiasters,
    digits: digits, group: group, toDecimal: toDecimal, trimDecimal: trimDecimal, halfUp: halfUp,
    fmtInt: fmtInt, fmtVolume: fmtVolume, fmtMoney: fmtMoney, fmtMeters: fmtMeters,
    LIVE: LIVE, liveDelay: liveDelay
  };
});

/* ===================== سلوك الصفحات ===================== */
(function () {
  'use strict';
  if (typeof document === 'undefined') { return; }
  var C = window.WoodCalc;
  var body = document.body;
  var hasBigInt = typeof BigInt === 'function';
  document.documentElement.classList.add('js');
  C.configure({ digits: body.dataset.digits, volDecimals: body.dataset.volDecimals, volPad: body.dataset.volPad });

  /* ---------- بيانات الأرصدة ---------- */
  var stock = { items: [], byId: {}, byKey: {} };
  function setStock(items) {
    stock.items = items || [];
    stock.byId = {};
    stock.byKey = {};
    stock.items.forEach(function (it) { stock.byId[it.id] = it; stock.byKey[it.key] = it; });
  }
  var stockEl = document.getElementById('stock-data');
  if (stockEl) {
    try { setStock(JSON.parse(stockEl.textContent)); } catch { setStock([]); }
  }
  function qtyIn(item, wh) { return item && wh ? Number(item.stock[wh] || 0) : 0; }
  function qtyAll(item) {
    if (!item) { return 0; }
    return Object.keys(item.stock).reduce(function (s, k) { return s + Number(item.stock[k]); }, 0);
  }

  function setText(el, text) { if (el && el.textContent !== text) { el.textContent = text; } }
  // أيقونة من رموز الصفحة (icon_sprite في lib/icons.php)، أو null إن لم تكن موجودة
  function svgIcon(name) {
    if (!document.getElementById('i-' + name)) { return null; }
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    var use = document.createElementNS(ns, 'use');
    use.setAttribute('href', '#i-' + name);
    svg.appendChild(use);
    return svg;
  }
  function showMessages(el, messages) {
    if (!el) { return; }
    var text = messages.join(' ');
    if (el.dataset.text === text) { return; }
    el.dataset.text = text;
    while (el.firstChild) { el.removeChild(el.firstChild); }
    messages.forEach(function (m, i) {
      if (i > 0) { el.appendChild(document.createElement('br')); }
      el.appendChild(document.createTextNode(m));
    });
    el.hidden = messages.length === 0;
  }
  function debounce(fn, ms) {
    var t = null;
    return function () { clearTimeout(t); t = setTimeout(fn, ms); };
  }

  /* ---------- تذكّر التفضيلات (الوحدات والمخزن) في المتصفح فقط ---------- */
  document.querySelectorAll('select[data-remember]').forEach(function (sel) {
    var key = 'wood.pref.' + sel.dataset.remember;
    if (sel.dataset.posted !== '1') {
      try {
        var v = localStorage.getItem(key);
        if (v && Array.prototype.some.call(sel.options, function (o) { return o.value === v; })) { sel.value = v; }
      } catch { /* التخزين غير متاح: تبقى القيمة الافتراضية */ }
    }
    sel.addEventListener('change', function () {
      try { if (sel.value) { localStorage.setItem(key, sel.value); } } catch { /* تجاهل */ }
    });
  });

  /* ---------- منع الإرسال المزدوج ---------- */
  var lastSubmitter = null;
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('button[type="submit"], input[type="submit"]') : null;
    if (b) { lastSubmitter = b; }
  }, true);
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (e.defaultPrevented || (form.getAttribute('method') || '').toLowerCase() !== 'post') { return; }
    if (form.dataset.submitting === '1') { e.preventDefault(); return; }
    form.dataset.submitting = '1';
    form.setAttribute('aria-busy', 'true');
    var sub = e.submitter || (lastSubmitter && lastSubmitter.form === form ? lastSubmitter : null);
    // الأزرار المعطلة لا تُرسل قيمتها، فتُنسخ قيمة الزر المضغوط في حقل مخفي
    if (sub && sub.name) {
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = sub.name;
      hidden.value = sub.value;
      hidden.dataset.injected = '1';
      form.appendChild(hidden);
    }
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (b) { b.disabled = true; });
    if (sub && sub.dataset.busyText) {
      sub.dataset.idleText = sub.textContent;
      sub.textContent = sub.dataset.busyText;
    }
  });
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) { return; }
    document.querySelectorAll('form[data-submitting="1"]').forEach(function (form) {
      delete form.dataset.submitting;
      form.removeAttribute('aria-busy');
      form.querySelectorAll('input[data-injected="1"]').forEach(function (i) { i.remove(); });
      form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (b) {
        b.disabled = false;
        if (b.dataset.idleText) { b.textContent = b.dataset.idleText; }
      });
    });
  });

  /* ---------- الطباعة بعد تحميل الخط ---------- */
  // بالتفويض على الصفحة: يشمل أزرار الطباعة التي تضيفها أدوات الجداول أو التحديث التلقائي لاحقًا
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-action="print"]') : null;
    if (!btn) { return; }
    var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
    ready.then(function () { window.print(); });
  });

  /* ---------- القائمة الرئيسية على الكمبيوتر (details/summary) ----------
   * بدون JavaScript تفتح المجموعة وتُغلق بالضغط فقط. هنا: مجموعة واحدة مفتوحة في كل مرة، وتُغلق عند خروج
   * الماوس منها (بمهلة قصيرة حتى لا تُغلق أثناء الحركة إلى روابطها)، أو الضغط خارجها، أو Escape. */
  var navGroups = Array.prototype.slice.call(document.querySelectorAll('.main-nav details.nav-group'));
  var navTimers = [];
  function closeNav(except) {
    navGroups.forEach(function (g) { if (g !== except) { g.open = false; } });
  }
  navGroups.forEach(function (g, i) {
    g.addEventListener('toggle', function () { if (g.open) { closeNav(g); } });
    // pointerleave للماوس فقط: مع اللمس يُطلق الحدث عند رفع الإصبع فتُغلق القائمة فور فتحها
    g.addEventListener('pointerleave', function (e) {
      if (e.pointerType !== 'mouse') { return; }
      clearTimeout(navTimers[i]);
      navTimers[i] = setTimeout(function () {
        // لا تُغلق إذا كان التركيز بلوحة المفاتيح داخل المجموعة
        if (!g.contains(document.activeElement) || document.activeElement === g.querySelector('summary')) { g.open = false; }
      }, 350);
    });
    g.addEventListener('pointerenter', function () { clearTimeout(navTimers[i]); });
    // Tab خارج المجموعة يغلقها. الضغط بالماوس خارجها يغلقه مستمع الضغط في الصفحة، والضغط في فراغ داخلها
    // (بين الروابط) يبقيها مفتوحة. الإغلاق مؤجل: إغلاق details أثناء ضغط الماوس يُسقط صفحة Chromium.
    g.addEventListener('focusout', function (e) {
      var to = e.relatedTarget;
      if (!to || g.contains(to)) { return; }
      setTimeout(function () { if (g.open && !g.contains(document.activeElement)) { g.open = false; } }, 0);
    });
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('.main-nav details.nav-group')) { closeNav(null); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    var open = navGroups.filter(function (g) { return g.open; })[0];
    if (open) {
      open.open = false;
      open.querySelector('summary').focus();
    }
  });

  /* ---------- نموذج الوارد ---------- */
  var receiveForm = document.querySelector('form[data-calc="receive"]');
  var refreshReceive = function () {};
  if (receiveForm && hasBigInt) {
    var out = function (name) { return receiveForm.querySelector('[data-out="' + name + '"]'); };
    refreshReceive = function () {
      var errors = [];
      var dims = {};
      var complete = true;
      C.DIMENSIONS.forEach(function (pair) {
        var input = receiveForm.querySelector('#' + pair[0]);
        var unit = receiveForm.querySelector('#' + pair[0] + '_unit').value;
        if (input.value.trim() === '') { complete = false; return; }
        var r = C.parseDimension(input.value, unit, pair[1]);
        if (r.msg) { errors.push(r.msg); complete = false; } else { dims[pair[0]] = r.v; }
      });
      var qInput = receiveForm.querySelector('#quantity');
      var qty = null;
      if (qInput.value.trim() !== '') {
        var q = C.parseQuantity(qInput.value);
        if (q.msg) { errors.push(q.msg); } else { qty = q.v; }
      }
      if (complete) {
        var piece = dims.width * dims.thickness * dims.length;
        setText(out('meters'), 'عرض ' + C.digits(C.fmtMeters(dims.width)) + ' × تخانة ' + C.digits(C.fmtMeters(dims.thickness))
          + ' × طول ' + C.digits(C.fmtMeters(dims.length)) + ' متر');
        setText(out('piece'), C.fmtVolume(piece));
        setText(out('total'), qty !== null ? C.fmtVolume(piece * BigInt(qty)) : '-');
        var type = receiveForm.querySelector('#wood_type_id').value;
        var wh = receiveForm.querySelector('#warehouse_id').value;
        var item = type ? stock.byKey[type + ':' + dims.width + ':' + dims.thickness + ':' + dims.length] : null;
        if (!type) {
          setText(out('existing'), '-');
        } else if (item) {
          setText(out('existing'), wh
            ? C.fmtInt(qtyIn(item, wh)) + ' قطعة في هذا المخزن، و' + C.fmtInt(qtyAll(item)) + ' في كل المخازن'
            : C.fmtInt(qtyAll(item)) + ' قطعة في كل المخازن');
        } else {
          setText(out('existing'), 'مقاس جديد');
        }
      } else {
        ['meters', 'piece', 'total', 'existing'].forEach(function (n) { setText(out(n), '-'); });
      }
      showMessages(out('error'), errors);
    };
    receiveForm.addEventListener('input', debounce(refreshReceive, 120));
    receiveForm.addEventListener('change', refreshReceive);
    refreshReceive();
  }

  /* ---------- شاشة الإدخال السريع لفاتورة البيع والتحويل (مثل الكاشير) ----------
   * الخادم يرسم محرر الأسطر الكلاسيكي (يعمل بدون JavaScript) وقالب الشاشة السريعة (forms.php: render_pos_panel).
   * هنا يحل القالب محل الأسطر: اختيار النوع، ثم المقاس (عرض × تخانة)، ثم الطول، ثم العدد والسعر، ثم «إضافة»
   * أو Enter، فيُضاف السطر إلى جدول المستند مع تعديل وحذف. الأسطر تُكتب في حقول مخفية بنفس صيغة الخادم
   * lines[i][type_id|item_id|quantity|price]، فالتحقق والحساب والحفظ على الخادم بلا تغيير.
   * الأسطر المرسلة سابقًا (خطأ في الحفظ أو «تعديل» من المراجعة) تُقرأ من المحرر الكلاسيكي مع أخطائها. */
  var docForm = document.querySelector('form[data-doc-form]');
  var refreshDocForm = function () {};
  var posTpl = docForm ? docForm.querySelector('template[data-pos-template]') : null;
  if (docForm && posTpl && !hasBigInt) {
    // متصفح قديم بلا BigInt: الشاشة السريعة لا تعمل، فيبقى المحرر الكلاسيكي كاملًا ومعه زر «أسطر إضافية»
    docForm.querySelectorAll('[data-nojs-only]').forEach(function (el) { el.removeAttribute('data-nojs-only'); });
  }
  if (docForm && posTpl && hasBigInt) {
    var section = docForm.querySelector('[data-lines]');
    var withPrice = section.dataset.withPrice === '1';
    var whSelect = docForm.querySelector('#warehouse_id');
    var MAX_LINES = 50; // مثل MAX_LINES في app/lib/documents.php
    var rows = [];      // {item, qty, priceRaw, price (BigInt قروش أو null), notes: [رسائل من الخادم]}
    var editing = -1;   // رقم السطر الجاري تعديله في اللوحة

    // الأسطر المرسلة من قبل (ومعها رسائل أخطاء الخادم لكل سطر)
    section.querySelectorAll('[data-line]').forEach(function (line) {
      var item = stock.byId[line.querySelector('[data-line-item]').value];
      var qRaw = line.querySelector('[data-line-qty]').value.trim();
      var pEl = line.querySelector('[data-line-price]');
      var pRaw = pEl ? pEl.value.trim() : '';
      if (!item && qRaw === '' && pRaw === '') { return; }
      var q = C.parseQuantity(qRaw);
      var p = withPrice ? C.parsePrice(pRaw) : { v: null };
      rows.push({
        item: item || null, qty: q.msg ? qRaw : q.v, priceRaw: pRaw, price: p.msg ? null : p.v,
        notes: Array.prototype.map.call(line.querySelectorAll('.field-error'), function (e) { return e.textContent.trim(); })
      });
    });
    var list = section.querySelector('[data-line-list]');
    list.parentNode.insertBefore(posTpl.content.cloneNode(true), list);
    list.remove();
    section.querySelectorAll('.line-actions').forEach(function (el) { el.remove(); });
    posTpl.remove();

    var pos = section.querySelector('[data-pos]');
    var q$ = function (sel) { return pos.querySelector(sel); };
    var typeSel = q$('[data-pos-type]');
    var sizeSel = q$('[data-pos-size]');
    var lenSel = q$('[data-pos-length]');
    var qtyEl = q$('[data-pos-qty]');
    var priceIn = q$('[data-pos-price]');
    var addBtn = q$('[data-pos-add]');
    var cancelBtn = q$('[data-pos-cancel]');
    var addLabel = addBtn.querySelector('[data-pos-add-label]') || addBtn;
    var addText = addLabel.textContent;
    var tbody = q$('[data-pos-rows]');
    var hiddenBox = q$('[data-pos-hidden]');
    var errBox = q$('[data-pos-error]');
    var posStatus = q$('[data-pos-status]');

    var wh = function () { return whSelect ? whSelect.value : ''; };
    var avail = function (item) { return qtyIn(item, wh()); };
    /* الكمية من صنف في الجدول، بدون السطر الجاري تعديله */
    function inCart(item, skip) {
      return rows.reduce(function (s, r, i) { return s + (i !== skip && r.item === item && typeof r.qty === 'number' ? r.qty : 0); }, 0);
    }
    function setOptions(sel, wanted, placeholder) {
      var current = sel.value;
      var sig = wanted.map(function (w) { return w[0] + '=' + w[1]; }).join('|');
      if (sel.dataset.signature !== sig) {
        sel.dataset.signature = sig;
        while (sel.firstChild) { sel.removeChild(sel.firstChild); }
        [['', placeholder]].concat(wanted).forEach(function (w) {
          var o = document.createElement('option');
          o.value = w[0];
          o.textContent = w[1];
          sel.appendChild(o);
        });
      }
      sel.value = current;
      if (sel.value !== current) { sel.value = ''; }
      // خيار واحد متاح: يُختار تلقائيًا لتوفير خطوة
      if (sel.value === '' && wanted.length === 1) { sel.value = wanted[0][0]; }
      sel.disabled = wanted.length === 0;
    }
    /* القوائم الثلاث من الأصناف المتاحة في المخزن المختار (والمختار في اللوحة يبقى ظاهرًا ولو نفد) */
    function rebuildEntry() {
      var w = wh();
      var keep = editing >= 0 && rows[editing] ? rows[editing].item : null;
      var usable = stock.items.filter(function (it) { return w && (avail(it) > 0 || it === keep); });
      var types = [];
      var seenT = {};
      usable.forEach(function (it) { if (!seenT[it.type]) { seenT[it.type] = 1; types.push([String(it.type), it.typeName]); } });
      setOptions(typeSel, types, w ? 'اختر النوع' : 'اختر المخزن أولًا');
      var sizes = [];
      var seenS = {};
      usable.forEach(function (it) {
        if (String(it.type) === typeSel.value && !seenS[it.wt]) { seenS[it.wt] = 1; sizes.push([it.wt, it.wtLabel]); }
      });
      setOptions(sizeSel, sizes, typeSel.value ? 'اختر المقاس' : 'اختر النوع أولًا');
      var lens = usable.filter(function (it) { return String(it.type) === typeSel.value && it.wt === sizeSel.value; })
        .map(function (it) { return [String(it.id), it.len + ' (متاح ' + C.fmtInt(Math.max(0, avail(it) - inCart(it, editing))) + ')']; });
      setOptions(lenSel, lens, sizeSel.value ? 'اختر الطول' : 'اختر المقاس أولًا');
      refreshEntryFigures();
    }
    function entryItem() { return stock.byId[lenSel.value] || null; }
    function refreshEntryFigures() {
      var item = entryItem();
      var availEl = q$('[data-pos-available]');
      if (!wh()) {
        setText(availEl, 'اختر المخزن أولًا لعرض الأصناف المتاحة فيه.');
      } else if (!stock.items.some(function (it) { return avail(it) > 0; })) {
        setText(availEl, 'لا توجد أصناف متاحة في هذا المخزن.');
      } else if (item) {
        var a = avail(item);
        var used = inCart(item, editing);
        setText(availEl, 'المتاح في هذا المخزن: ' + C.fmtInt(a) + ' قطعة (' + C.fmtVolume(BigInt(item.piece) * BigInt(a)) + ' م³)'
          + (used ? '، منها ' + C.fmtInt(used) + ' في هذا المستند' : '') + '. حجم القطعة: ' + C.fmtVolume(BigInt(item.piece)) + ' م³');
      } else {
        setText(availEl, '');
      }
      var q = qtyEl.value.trim() !== '' ? C.parseQuantity(qtyEl.value) : null;
      var p = priceIn && priceIn.value.trim() !== '' ? C.parsePrice(priceIn.value) : null;
      var um3 = item && q && !q.msg ? BigInt(item.piece) * BigInt(q.v) : null;
      setText(q$('[data-pos-volume]'), um3 !== null ? C.fmtVolume(um3) : '-');
      var amountEl = q$('[data-pos-amount]');
      if (amountEl) { setText(amountEl, um3 !== null && p && !p.msg ? C.fmtMoney(C.amountPiasters(um3, p.v)) : '-'); }
    }
    /* الرسالة مع الحقل المسبب لها: aria-invalid عليه وحده */
    function showError(msg, field) {
      showMessages(errBox, msg ? [msg] : []);
      [typeSel, sizeSel, lenSel, qtyEl, priceIn].forEach(function (el) {
        if (el) { el.setAttribute('aria-invalid', msg && el === (field || qtyEl) ? 'true' : 'false'); }
      });
    }
    function describe(r) { return (r.item ? r.item.typeName + '، ' + r.item.wtLabel + '، طول ' + r.item.len : 'صنف'); }

    /* «إضافة» أو «حفظ التعديل»: الصنف المكرر بنفس السعر تُجمع كميته في سطره */
    function commit() {
      var item = entryItem();
      if (!wh()) { showError('اختر المخزن أولًا.', typeSel); (whSelect || typeSel).focus(); return; }
      if (!item) {
        var missing = typeSel.value === '' ? typeSel : sizeSel.value === '' ? sizeSel : lenSel;
        showError('اختر النوع ثم المقاس ثم الطول.', missing);
        missing.focus();
        return;
      }
      var q = C.parseQuantity(qtyEl.value);
      if (q.msg) { showError(q.msg, qtyEl); qtyEl.focus(); return; }
      var p = { v: null };
      if (withPrice) {
        p = C.parsePrice(priceIn.value);
        if (p.msg) { showError(p.msg, priceIn); priceIn.focus(); return; }
      }
      var priceRaw = withPrice ? C.normalize(priceIn.value) : '';
      var target = editing;
      var dup = -1;
      rows.forEach(function (r, i) { if (i !== editing && r.item === item) { dup = i; } });
      var qty = q.v;
      if (dup >= 0) {
        if (withPrice && rows[dup].price !== p.v) {
          showError('هذا الصنف موجود في السطر ' + C.fmtInt(dup + 1) + ' بسعر مختلف. عدّل ذلك السطر بدل إضافته مرة أخرى.', priceIn);
          return;
        }
        qty += typeof rows[dup].qty === 'number' ? rows[dup].qty : 0;
      }
      var a = avail(item);
      if (qty > a) {
        showError('الكمية أكبر من المتاح في المخزن (' + C.fmtInt(a) + ' قطعة' + (dup >= 0 ? '، والسطر ' + C.fmtInt(dup + 1) + ' فيه ' + C.fmtInt(qty - q.v) : '') + ').');
        qtyEl.focus();
        return;
      }
      var row = { item: item, qty: qty, priceRaw: priceRaw, price: p.v, notes: [] };
      if (dup >= 0) {
        rows[dup] = row;
        if (target >= 0) { rows.splice(target, 1); }
      } else if (target >= 0) {
        rows[target] = row;
      } else {
        if (rows.length >= MAX_LINES) { showError('الحد الأقصى ' + C.fmtInt(MAX_LINES) + ' سطرًا في المستند الواحد.', lenSel); return; }
        rows.push(row);
      }
      setText(posStatus, (target >= 0 ? 'عُدّل: ' : 'أُضيف: ') + describe(row) + '، ' + C.fmtInt(qty) + ' قطعة');
      resetEntry(true);
      render();
      (sizeSel.disabled ? typeSel : lenSel.disabled ? sizeSel : lenSel).focus();
    }
    /* بعد الإضافة يبقى النوع والمقاس (والسعر) لإدخال طول آخر بسرعة */
    function resetEntry(keepTypeSize) {
      editing = -1;
      setText(q$('[data-pos-title]'), 'إضافة صنف');
      setText(addLabel, addText);
      cancelBtn.hidden = true;
      if (!keepTypeSize) { typeSel.value = ''; sizeSel.value = ''; }
      lenSel.value = '';
      qtyEl.value = '';
      showError('');
      rebuildEntry();
    }
    function startEdit(i) {
      var r = rows[i];
      editing = i;
      setText(q$('[data-pos-title]'), 'تعديل السطر ' + C.fmtInt(i + 1));
      setText(addLabel, 'حفظ التعديل');
      cancelBtn.hidden = false;
      if (r.item) {
        rebuildEntry();
        typeSel.value = String(r.item.type);
        rebuildEntry();
        sizeSel.value = r.item.wt;
        rebuildEntry();
        lenSel.value = String(r.item.id);
      }
      qtyEl.value = typeof r.qty === 'number' ? String(r.qty) : String(r.qty || '');
      if (priceIn) { priceIn.value = r.priceRaw; }
      showError('');
      rebuildEntry();
      qtyEl.focus();
      qtyEl.select();
    }

    function cell(tr, label, text, cls) {
      var td = document.createElement('td');
      if (cls) { td.className = cls; }
      td.setAttribute('data-label', label);
      td.textContent = text;
      tr.appendChild(td);
      return td;
    }
    /* جدول المستند والحقول المخفية والإجماليات */
    function render() {
      // التحديث التلقائي يعيد رسم الجدول: يبقى التركيز على نفس زر السطر (لوحة المفاتيح وقارئ الشاشة)
      var focused = document.activeElement && tbody.contains(document.activeElement) && document.activeElement.dataset.posAction
        ? [document.activeElement.dataset.posRow, document.activeElement.dataset.posAction] : null;
      while (tbody.firstChild) { tbody.removeChild(tbody.firstChild); }
      while (hiddenBox.firstChild) { hiddenBox.removeChild(hiddenBox.firstChild); }
      var totQty = 0;
      var totUm3 = BigInt(0);
      var totAmount = BigInt(0);
      rows.forEach(function (r, i) {
        var tr = document.createElement('tr');
        if (i === editing) { tr.className = 'is-editing'; }
        var qtyOk = typeof r.qty === 'number';
        var um3 = r.item && qtyOk ? BigInt(r.item.piece) * BigInt(r.qty) : null;
        cell(tr, 'السطر', C.fmtInt(i + 1), 'num');
        cell(tr, 'النوع', r.item ? r.item.typeName : '-');
        cell(tr, 'المقاس', r.item ? r.item.wtLabel : '-');
        cell(tr, 'الطول', r.item ? r.item.len : '-');
        cell(tr, 'العدد', qtyOk ? C.fmtInt(r.qty) : String(r.qty || '-'), 'num');
        cell(tr, 'الحجم (م³)', um3 !== null ? C.fmtVolume(um3) : '-', 'num');
        if (withPrice) {
          cell(tr, 'سعر المتر المكعب', r.price !== null ? C.fmtMoney(r.price) : (r.priceRaw || '-'), 'num');
          var amt = um3 !== null && r.price !== null ? C.amountPiasters(um3, r.price) : null;
          cell(tr, 'القيمة', amt !== null ? C.fmtMoney(amt) : '-', 'num');
          if (amt !== null) { totAmount += amt; }
        }
        var act = document.createElement('td');
        act.className = 'cell-actions';
        var box = document.createElement('div');
        box.className = 'row-actions';
        [['تعديل', 'edit'], ['حذف', 'delete']].forEach(function (b) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn btn-quiet' + (b[1] === 'delete' ? ' btn-danger-quiet' : '');
          btn.dataset.posRow = String(i);
          btn.dataset.posAction = b[1];
          var ic = svgIcon(b[1]);
          if (ic) { btn.appendChild(ic); }
          btn.appendChild(document.createTextNode(b[0]));
          btn.setAttribute('aria-label', b[0] + ' السطر ' + C.fmtInt(i + 1) + ': ' + describe(r));
          box.appendChild(btn);
        });
        act.appendChild(box);
        tr.appendChild(act);
        tbody.appendChild(tr);
        // تنبيهات السطر: رسائل الخادم السابقة، أو كمية أكبر من المتاح بعد تغيير المخزن أو تحديث الأرصدة
        var notes = r.notes.slice();
        if (r.item && qtyOk && r.qty > avail(r.item) && wh()) {
          notes.push('الكمية أكبر من المتاح في هذا المخزن (' + C.fmtInt(avail(r.item)) + ' قطعة).');
        }
        if (notes.length) {
          var ntr = document.createElement('tr');
          ntr.className = 'pos-row-note';
          var ntd = document.createElement('td');
          ntd.colSpan = withPrice ? 9 : 7;
          ntd.className = 'calc-error';
          ntd.textContent = 'السطر ' + C.fmtInt(i + 1) + ': ' + notes.join(' ');
          ntr.appendChild(ntd);
          tbody.appendChild(ntr);
        }
        if (qtyOk) { totQty += r.qty; }
        if (um3 !== null) { totUm3 += um3; }
        var fields = { type_id: r.item ? String(r.item.type) : '', item_id: r.item ? String(r.item.id) : '', quantity: String(r.qty) };
        if (withPrice) { fields.price = r.priceRaw; }
        Object.keys(fields).forEach(function (f) {
          var h = document.createElement('input');
          h.type = 'hidden';
          h.name = 'lines[' + i + '][' + f + ']';
          h.value = fields[f];
          hiddenBox.appendChild(h);
        });
      });
      q$('[data-pos-empty]').hidden = rows.length > 0;
      q$('.pos-cart-wrap').hidden = rows.length === 0;
      setText(section.querySelector('[data-total-qty]'), totQty ? C.fmtInt(totQty) : '-');
      setText(section.querySelector('[data-total-volume]'), totQty ? C.fmtVolume(totUm3) : '-');
      var ta = section.querySelector('[data-total-amount]');
      if (ta) { setText(ta, totAmount > BigInt(0) ? C.fmtMoney(totAmount) : '-'); }
      if (focused) {
        var refocus = tbody.querySelector('[data-pos-row="' + focused[0] + '"][data-pos-action="' + focused[1] + '"]');
        if (refocus) { refocus.focus(); }
      }
      var full = rows.length >= MAX_LINES && editing < 0;
      addBtn.disabled = full;
      if (full) { setText(addLabel, 'الحد الأقصى ' + C.fmtInt(MAX_LINES) + ' سطرًا'); } else if (editing < 0) { setText(addLabel, addText); }
    }

    tbody.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-pos-action]');
      if (!btn) { return; }
      var i = Number(btn.dataset.posRow);
      if (btn.dataset.posAction === 'edit') {
        startEdit(i);
        render();
        return;
      }
      var removed = rows.splice(i, 1)[0];
      if (editing === i) {
        resetEntry(true);
      } else if (editing > i) {
        editing--;
        setText(q$('[data-pos-title]'), 'تعديل السطر ' + C.fmtInt(editing + 1));
      }
      setText(posStatus, 'حُذف: ' + describe(removed));
      render();
      rebuildEntry();
      var next = tbody.querySelector('[data-pos-action="delete"][data-pos-row="' + Math.min(i, rows.length - 1) + '"]');
      (next || typeSel).focus();
    });
    addBtn.addEventListener('click', commit);
    cancelBtn.addEventListener('click', function () { resetEntry(false); render(); typeSel.focus(); });
    typeSel.addEventListener('change', function () { sizeSel.value = ''; lenSel.value = ''; rebuildEntry(); (sizeSel.disabled ? typeSel : sizeSel).focus(); });
    sizeSel.addEventListener('change', function () { lenSel.value = ''; rebuildEntry(); if (lenSel.value) { qtyEl.focus(); } else { lenSel.focus(); } });
    lenSel.addEventListener('change', function () { refreshEntryFigures(); if (lenSel.value) { qtyEl.focus(); } });
    [qtyEl, priceIn].forEach(function (el) {
      if (!el) { return; }
      el.addEventListener('input', debounce(refreshEntryFigures, 80));
      el.addEventListener('change', refreshEntryFigures);
      // Enter في العدد أو السعر يضيف السطر ولا يرسل النموذج
      el.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.isComposing) { return; }
        e.preventDefault();
        if (el === qtyEl && priceIn && priceIn.value.trim() === '') { priceIn.focus(); return; }
        commit();
      });
    });
    [typeSel, sizeSel, lenSel].forEach(function (el) {
      el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
    });
    if (whSelect) { whSelect.addEventListener('change', function () { rebuildEntry(); render(); }); }

    /* قبل المراجعة أو الحفظ: لا يُرسل مستند فارغ، ولا يضيع صنف مكتوب في اللوحة لم يُضف بعد */
    docForm.addEventListener('submit', function (e) {
      var sub = e.submitter || lastSubmitter;
      if (sub && sub.value === 'more_lines') { return; }
      // صنف معلق = عدد مكتوب أو تعديل لم يُحفظ (اختيار القوائم وحده تصفح، وقد يُختار الخيار الوحيد تلقائيًا)
      if (editing >= 0 || qtyEl.value.trim() !== '') {
        e.preventDefault();
        showError(editing >= 0 ? 'احفظ تعديل السطر أو ألغه قبل المتابعة.' : 'في لوحة الإدخال صنف لم يُضف بعد. اضغط «إضافة» أو امسح عدد القطع.');
        qtyEl.focus();
        return;
      }
      if (!rows.length) {
        e.preventDefault();
        showError('أضف صنفًا واحدًا على الأقل.', typeSel);
        (typeSel.disabled ? (whSelect || typeSel) : typeSel).focus();
      }
    });

    /* بعد تحديث الأرصدة تلقائيًا تصبح كائنات الأصناف جديدة: تُربط الأسطر بها حتى يبقى جمع المكرر وفحص المتاح
     * مع ما في الجدول وتنبيه تجاوز المتاح وتعديل السطر صحيحة */
    refreshDocForm = function () {
      rows.forEach(function (r) { if (r.item) { r.item = stock.byId[r.item.id] || r.item; } });
      rebuildEntry();
      render();
    };
    rebuildEntry();
    render();
  }

  /* ---------- التحديث التلقائي ----------
   * لا يوجد دفع من الخادم (WebSockets) على الاستضافة المشتركة، فالصفحة تسأل عن رقم إصدار البيانات
   * بطلب خفيف جدًا، وتجلب المحتوى فقط عندما يتغير الرقم:
   *  - كل ثانيتين والمستخدم نشط، وكل 10 ثوانٍ بعد 10 دقائق بلا نشاط، ولا شيء والتبويب مخفي.
   *  - فورًا (طلب واحد مهما تزامنت الأحداث) عند ظهور التبويب، والتركيز على النافذة، وعودة الاتصال،
   *    وأول نشاط بعد الخمول، وعندما يعلن تبويب آخر في نفس المتصفح عن إصدار جديد (BroadcastChannel).
   *  - بعد الإخفاق تتباعد المحاولات حتى 30 ثانية (WoodCalc.liveDelay)، ولا يبدأ طلب قبل انتهاء السابق.
   *  - لا يُعتبر الإصدار مقروءًا إلا بعد تحديث المناطق الحية والأرصدة كليهما بنجاح كامل.
   */
  if (body.dataset.liveVersion === undefined || !window.fetch || !window.DOMParser) { return; }
  var version = body.dataset.liveVersion; // الإصدار المعروض فعلًا في الصفحة
  var known = version;                    // أحدث إصدار عرفه هذا التبويب (بفحصه أو من تبويب آخر)
  var apiUrl = body.dataset.api;
  var statusBox = document.getElementById('live-status');
  var SOON_MS = 100;        // نافذة تجميع الأحداث المتزامنة (الظهور مع التركيز مثلًا) في طلب واحد
  var TIMEOUT_MS = 20000;   // طلب معلق يُلغى ويُحسب إخفاقًا، حتى لا يوقف التحديث
  var failures = 0;
  var stopped = false;
  var running = false;
  var again = false;        // طُلب فحص فوري أثناء طلب جارٍ: يُفحص مرة أخرى بعده مباشرة
  var timer = null;
  var lastActivity = Date.now();
  var regionsAt = {};       // الإصدار الذي حُدّثت إليه كل منطقة حية (بمعرّفها)
  var stockAt = null;       // الإصدار الذي حُدّثت إليه بيانات الأرصدة
  var channel = null;

  function setStatus(text, loginLink) {
    if (!statusBox) { return; }
    while (statusBox.firstChild) { statusBox.removeChild(statusBox.firstChild); }
    if (!text) { statusBox.hidden = true; return; }
    statusBox.appendChild(document.createTextNode(text + ' '));
    if (loginLink) {
      var a = document.createElement('a');
      a.href = body.dataset.login;
      a.textContent = 'تسجيل الدخول';
      statusBox.appendChild(a);
    }
    statusBox.hidden = false;
  }

  /* القائمة تغيرت عن اختيارها الافتراضي: الخيار المحدد بـ selected في الصفحة (الأخير إن تعدد)،
     وإلا فأول خيار غير معطل، كما يختار المتصفح في القائمة المنسدلة العادية */
  function selectChanged(sel) {
    var opts = Array.prototype.slice.call(sel.options);
    if (sel.multiple) {
      return opts.some(function (o) { return o.selected !== o.defaultSelected; });
    }
    var def = -1;
    opts.forEach(function (o, i) { if (o.defaultSelected) { def = i; } });
    if (def === -1 && sel.size <= 1) {
      def = opts.findIndex(function (o) { return !o.disabled; });
    }
    return sel.selectedIndex !== def;
  }

  /* منطقة فيها تركيز (على أي عنصر: حقل أو رابط أو زر) أو إدخال لم يُحفظ لا تُستبدل،
     حتى لا يضيع ما يكتبه المستخدم ولا يفقد مستخدم لوحة المفاتيح موضعه */
  function isBusy(region) {
    var active = document.activeElement;
    if (active && active !== body && region.contains(active)) { return true; }
    // منطقة تعرض خطأ حفظ أو إلغاء لم يُصحح بعد: لا تُستبدل حتى لا تختفي الرسالة وما كتبه المستخدم
    if (region.querySelector('.alert-error, [aria-invalid="true"]')) { return true; }
    // نص محدد داخل المنطقة (للنسخ مثلًا) لا يضيع بالتحديث
    var sel = window.getSelection ? window.getSelection() : null;
    if (sel && !sel.isCollapsed && sel.anchorNode && region.contains(sel.anchorNode)) { return true; }
    var dirty = false;
    region.querySelectorAll('input, textarea, select').forEach(function (el) {
      // أدوات الجداول (البحث السريع والأعمدة) تحفظ حالتها وتعيد تطبيقها بعد الاستبدال (tables.js)
      if (dirty || el.type === 'hidden' || el.closest('[data-tools-slot]')) { return; }
      if (el.type === 'checkbox' || el.type === 'radio') {
        dirty = el.checked !== el.defaultChecked;
      } else if (el.tagName === 'SELECT') {
        dirty = selectChanged(el);
      } else {
        dirty = el.value !== el.defaultValue;
      }
    });
    return dirty;
  }

  /* طلب تحديث: X-Live حتى لا يمدد الجلسة ولا يستهلك رسائل التنبيه، ويُلغى إذا تأخر */
  function liveFetch(url, type) {
    var ctrl = window.AbortController ? new AbortController() : null;
    var t = ctrl ? setTimeout(function () { ctrl.abort(); }, TIMEOUT_MS) : null;
    var opts = { headers: { 'X-Live': '1' }, credentials: 'same-origin', cache: 'no-store' };
    if (ctrl) { opts.signal = ctrl.signal; }
    return fetch(url, opts)
      .then(function (res) {
        if (res.status === 401) { throw new Error('auth'); }
        if (!res.ok) { throw new Error('http'); }
        return type === 'json' ? res.json() : res.text();
      })
      .then(function (data) { clearTimeout(t); return data; }, function (err) { clearTimeout(t); throw err; });
  }

  /*
   * يحدّث المناطق الحية إلى الإصدار v من نسخة جديدة من نفس الصفحة. يعيد false إذا تأجلت منطقة.
   * تُتذكر المناطق المحدّثة إلى v، فالمؤجلة وحدها تبقى معلقة. ما دام الإصدار لم يتغير وكل المعلقة
   * مشغولة، يكتفي كل فحص بإعادة فحص الانشغال محليًا ولا تُجلب الصفحة، ثم تُجلب مرة واحدة عند تحررها.
   */
  function refreshRegions(v) {
    var regions = Array.prototype.filter.call(document.querySelectorAll('[data-live][id]'), function (r) {
      return regionsAt[r.id] !== v;
    });
    if (!regions.length) { return Promise.resolve(true); }
    if (regions.every(isBusy)) { return Promise.resolve(false); }
    return liveFetch(window.location.href, 'text').then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var complete = true;
      regions.forEach(function (region) {
        if (isBusy(region)) { complete = false; return; }
        var fresh = doc.getElementById(region.id);
        if (!fresh) {
          region.remove();
        } else {
          var next = document.importNode(fresh, true);
          // نفس المحتوى: لا داعي للاستبدال (يحافظ على التركيز والتحديد)
          if (!region.isEqualNode(next)) { region.replaceWith(next); }
        }
        regionsAt[region.id] = v;
      });
      if (doc.body && doc.body.dataset) {
        C.configure({ digits: doc.body.dataset.digits, volDecimals: doc.body.dataset.volDecimals, volPad: doc.body.dataset.volPad });
      }
      document.dispatchEvent(new window.CustomEvent('wood:regions-updated'));
      return complete;
    });
  }

  function refreshStock(v) {
    if (!stockEl || stockAt === v) { return Promise.resolve(true); }
    return liveFetch(apiUrl + '&op=stock', 'json').then(function (data) {
      setStock(data.items);
      refreshReceive();
      refreshDocForm();
      stockAt = v;
      return true;
    });
  }

  /* ---------- الإعلان بين تبويبات نفس المتصفح ----------
   * كل تبويب يعلن الإصدار الذي يراه أول مرة فقط (known)، ولا يعيد إعلان ما سمعه، فلا تتبادل التبويبات
   * الرسائل بلا نهاية. الرسالة لا تحدّث الصفحة مباشرة، بل تطلب فحصًا فوريًا من الخادم. */
  function announce(v) {
    if (!channel) { return; }
    try { channel.postMessage({ v: v }); } catch { /* تجاهل: الفحص الدوري يكفي */ }
  }
  function onMessage(e) {
    var v = e.data && (typeof e.data.v === 'string' || typeof e.data.v === 'number') ? String(e.data.v) : null;
    if (v === null || v === version || v === known) { return; }
    known = v;
    checkSoon();
  }
  function openChannel() {
    if (channel || !window.BroadcastChannel) { return; }
    try {
      channel = new BroadcastChannel('wood-live');
      channel.onmessage = onMessage;
    } catch {
      channel = null;
    }
  }
  function closeChannel() {
    if (!channel) { return; }
    try { channel.close(); } catch { /* تجاهل */ }
    channel = null;
  }

  /* ---------- الجدولة ---------- */
  function schedule(ms) {
    clearTimeout(timer);
    timer = null;
    if (stopped) { return; }
    var delay = ms !== undefined ? ms : C.liveDelay({ hidden: document.hidden, failures: failures, idleFor: Date.now() - lastActivity });
    if (delay !== null) { timer = setTimeout(tick, delay); }
  }

  /* فحص فوري: الأحداث المتقاربة تُجمع في طلب واحد، وأثناء طلب جارٍ يُفحص مرة أخرى بعده */
  function checkSoon() {
    if (stopped) { return; }
    if (running) { again = true; return; }
    schedule(SOON_MS);
  }

  function tick() {
    timer = null;
    if (stopped || running || document.hidden) { return; }
    running = true;
    again = false;
    liveFetch(apiUrl + '&op=version', 'json')
      .then(function (data) {
        if (!data || (typeof data.v !== 'string' && typeof data.v !== 'number')) { throw new Error('http'); }
        var v = String(data.v);
        if (v === version) { known = v; return; }
        if (v !== known) { known = v; announce(v); }
        return Promise.all([refreshRegions(v), refreshStock(v)]).then(function (results) {
          if (results[0] && results[1]) { version = v; }
        });
      })
      .then(function () {
        failures = 0;
        setStatus('');
      }, function (err) {
        if (err && err.message === 'auth') {
          stopped = true;
          closeChannel();
          setStatus('انتهت الجلسة، وتوقف التحديث التلقائي.', true);
          return;
        }
        failures++;
        if (failures >= 3) { setStatus('تعذر الاتصال بالخادم. تُعاد المحاولة تلقائيًا.'); }
      })
      .then(function () {
        running = false;
        schedule(again ? SOON_MS : undefined);
      });
  }

  /* نشاط المستخدم: يعيد الفحص السريع، وأول نشاط بعد الخمول يفحص فورًا */
  function noteActivity() {
    var wasIdle = Date.now() - lastActivity > C.LIVE.idleAfterMs;
    lastActivity = Date.now();
    if (wasIdle) { checkSoon(); }
  }
  ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart', 'scroll'].forEach(function (type) {
    document.addEventListener(type, noteActivity, { capture: true, passive: true });
  });

  function wake() {
    lastActivity = Date.now();
    checkSoon();
  }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      clearTimeout(timer);
      timer = null;
      return;
    }
    wake();
  });
  window.addEventListener('focus', wake);
  window.addEventListener('online', checkSoon);
  // صفحة عائدة من ذاكرة الرجوع (bfcache): القناة أُغلقت عند المغادرة حتى لا تمنع التخزين
  window.addEventListener('pagehide', closeChannel);
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted || stopped) { return; }
    openChannel();
    wake();
  });

  openChannel();
  announce(version); // صفحة جديدة (بعد حفظ مثلًا): التبويبات الأخرى تفحص فورًا
  schedule();
})();
