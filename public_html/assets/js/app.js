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
    if (/^[-−–]/.test(s)) { return { e: 'negative' }; }
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
    if (/^[-−–]/.test(s)) { return { msg: 'عدد القطع لا يقبل قيمًا سالبة.' }; }
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
  document.querySelectorAll('[data-action="print"]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
      ready.then(function () { window.print(); });
    });
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

  /* ---------- محرر أسطر فاتورة البيع والتحويل ---------- */
  var docForm = document.querySelector('form[data-doc-form]');
  var refreshDocForm = function () {};
  if (docForm) {
    var section = docForm.querySelector('[data-lines]');
    var withPrice = section.dataset.withPrice === '1';
    var list = section.querySelector('[data-line-list]');
    var template = section.querySelector('template[data-line-template]');
    var whSelect = docForm.querySelector('#warehouse_id');
    var nextIndex = list.querySelectorAll('[data-line]').length;

    section.querySelectorAll('[data-nojs-only]').forEach(function (el) { el.remove(); });
    var addBtn = section.querySelector('[data-line-add]');
    var addText = addBtn.textContent;
    var MAX_LINES = 50; // مثل MAX_LINES في app/lib/documents.php: الخادم يرفض المستند إذا زادت الأسطر
    addBtn.hidden = false;

    var rebuildOptions = function (line) {
      var typeSel = line.querySelector('[data-line-type]');
      var itemSel = line.querySelector('[data-line-item]');
      var selected = itemSel.value;
      var type = typeSel.value;
      var wh = whSelect.value;
      var wanted = [['', 'اختر المقاس']];
      stock.items.forEach(function (it) {
        if (type && String(it.type) !== type) { return; }
        var available = wh ? qtyIn(it, wh) : qtyAll(it);
        var isSelected = String(it.id) === selected;
        if (available <= 0 && !isSelected) { return; }
        var label = (type ? '' : it.typeName + ': ') + it.size;
        if (available <= 0) { label += ' (غير متاح في هذا المخزن)'; }
        wanted.push([String(it.id), label]);
      });
      var signature = wanted.map(function (w) { return w[0] + '=' + w[1]; }).join('|');
      if (itemSel.dataset.signature === signature) { return; }
      itemSel.dataset.signature = signature;
      while (itemSel.firstChild) { itemSel.removeChild(itemSel.firstChild); }
      wanted.forEach(function (w) {
        var o = document.createElement('option');
        o.value = w[0];
        o.textContent = w[1];
        itemSel.appendChild(o);
      });
      itemSel.value = selected;
      if (itemSel.value !== selected) { itemSel.value = ''; }
    };

    var renumber = function () {
      var lines = list.querySelectorAll('[data-line]');
      lines.forEach(function (line, i) {
        setText(line.querySelector('[data-line-no]'), C.fmtInt(i + 1));
        var rm = line.querySelector('[data-line-remove]');
        if (rm) { rm.hidden = false; }
      });
      // عند الحد الأقصى يتعطل زر الإضافة ويشرح نصه السبب، ويعود عند حذف سطر
      var full = lines.length >= MAX_LINES;
      addBtn.disabled = full;
      setText(addBtn, full ? 'الحد الأقصى ' + C.fmtInt(MAX_LINES) + ' سطرًا في المستند الواحد' : addText);
    };

    refreshDocForm = function () {
      var wh = whSelect.value;
      var lines = list.querySelectorAll('[data-line]');
      if (!hasBigInt) {
        lines.forEach(rebuildOptions);
        return;
      }
      var seen = {};
      var totQty = 0;
      var totUm3 = hasBigInt ? BigInt(0) : 0;
      var totAmount = hasBigInt ? BigInt(0) : 0;
      lines.forEach(function (line, index) {
        rebuildOptions(line);
        var itemSel = line.querySelector('[data-line-item]');
        var item = stock.byId[itemSel.value];
        var qInput = line.querySelector('[data-line-qty]');
        var pInput = line.querySelector('[data-line-price]');
        var errors = [];
        var avail = item ? (wh ? qtyIn(item, wh) : null) : null;

        if (item && hasBigInt && avail !== null) {
          setText(line.querySelector('[data-line-available]'),
            'المتاح في هذا المخزن: ' + C.fmtInt(avail) + ' قطعة (' + C.fmtVolume(BigInt(item.piece) * BigInt(avail)) + ' م³)');
        } else {
          setText(line.querySelector('[data-line-available]'), item && !wh ? 'اختر المخزن لعرض المتاح.' : '');
        }
        if (item) {
          if (seen[item.id]) {
            errors.push('هذا المقاس مكرر في السطر ' + C.fmtInt(seen[item.id]) + '. اجمع الكمية في سطر واحد.');
          } else {
            seen[item.id] = index + 1;
          }
        }
        var qty = null;
        if (qInput.value.trim() !== '') {
          var q = C.parseQuantity(qInput.value);
          if (q.msg) { errors.push(q.msg); } else { qty = q.v; }
        }
        var price = null;
        if (withPrice && pInput.value.trim() !== '') {
          var p = C.parsePrice(pInput.value);
          if (p.msg) { errors.push(p.msg); } else { price = p.v; }
        }
        if (item && qty !== null && avail !== null && qty > avail) {
          errors.push('الكمية أكبر من المتاح في المخزن (' + C.fmtInt(avail) + ' قطعة).');
        }
        var volOut = line.querySelector('[data-line-volume]');
        var amountOut = line.querySelector('[data-line-amount]');
        if (item && qty !== null && hasBigInt) {
          var lineUm3 = BigInt(item.piece) * BigInt(qty);
          setText(volOut, C.fmtVolume(lineUm3));
          totQty += qty;
          totUm3 += lineUm3;
          if (withPrice) {
            if (price !== null) {
              var amt = C.amountPiasters(lineUm3, price);
              setText(amountOut, C.fmtMoney(amt));
              totAmount += amt;
            } else {
              setText(amountOut, '-');
            }
          }
        } else {
          setText(volOut, '-');
          if (amountOut) { setText(amountOut, '-'); }
        }
        showMessages(line.querySelector('[data-line-error]'), errors);
      });
      if (hasBigInt) {
        setText(section.querySelector('[data-total-qty]'), totQty ? C.fmtInt(totQty) : '-');
        setText(section.querySelector('[data-total-volume]'), totQty ? C.fmtVolume(totUm3) : '-');
        var ta = section.querySelector('[data-total-amount]');
        if (ta) { setText(ta, totAmount > BigInt(0) ? C.fmtMoney(totAmount) : '-'); }
      }
    };

    addBtn.addEventListener('click', function () {
      if (list.querySelectorAll('[data-line]').length >= MAX_LINES) { return; }
      var frag = template.content.cloneNode(true);
      var i = String(nextIndex++);
      frag.querySelectorAll('[id], [name], [for]').forEach(function (el) {
        ['id', 'name', 'for'].forEach(function (attr) {
          var v = el.getAttribute(attr);
          if (v && v.indexOf('__i__') !== -1) { el.setAttribute(attr, v.split('__i__').join(i)); }
        });
      });
      var line = frag.querySelector('[data-line]');
      list.appendChild(frag);
      renumber();
      refreshDocForm();
      var first = line.querySelector('[data-line-type]');
      if (first) { first.focus(); }
    });
    list.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-line-remove]');
      if (!btn) { return; }
      var line = btn.closest('[data-line]');
      if (list.querySelectorAll('[data-line]').length > 1) {
        line.remove();
      } else {
        line.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        line.querySelectorAll('select').forEach(function (s) { s.value = ''; });
      }
      renumber();
      refreshDocForm();
      addBtn.focus();
    });
    /* Enter في حقل سطر لا يرسل النموذج (الزر الافتراضي المخفي كان يحفظ التحويل فورًا)،
       بل ينقل التركيز إلى الحقل التالي. الملاحظات وأزرار الحفظ الصريحة لا تتأثر */
    docForm.addEventListener('keydown', function (e) {
      var el = e.target;
      if (e.key !== 'Enter' || e.isComposing || el.tagName !== 'INPUT' || !el.closest('[data-line]')) { return; }
      if (/^(submit|button|reset|image|checkbox|radio|file)$/.test(el.type)) { return; }
      e.preventDefault();
      var fields = Array.prototype.filter.call(docForm.elements, function (f) {
        return /^(INPUT|SELECT|TEXTAREA)$/.test(f.tagName) && f.type !== 'hidden' && !f.disabled && f.getClientRects().length > 0;
      });
      var next = fields[fields.indexOf(el) + 1];
      if (next) { next.focus(); }
    });
    docForm.addEventListener('input', debounce(refreshDocForm, 120));
    docForm.addEventListener('change', refreshDocForm);
    renumber();
    refreshDocForm();
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
    var dirty = false;
    region.querySelectorAll('input, textarea, select').forEach(function (el) {
      if (dirty || el.type === 'hidden') { return; }
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
          region.replaceWith(document.importNode(fresh, true));
        }
        regionsAt[region.id] = v;
      });
      if (doc.body && doc.body.dataset) {
        C.configure({ digits: doc.body.dataset.digits, volDecimals: doc.body.dataset.volDecimals, volPad: doc.body.dataset.volPad });
      }
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
