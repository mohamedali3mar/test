/*
 * أدوات الجداول: بحث سريع في الصفوف الظاهرة، واختيار الأعمدة الظاهرة (يتذكرها المتصفح)، وزر الطباعة،
 * وإرسال الأعمدة الظاهرة مع أزرار التصدير. الترتيب والتصدير نفسهما على الخادم ويعملان بدون هذا الملف.
 *
 * الشريط يرسمه الخادم ([data-table-tools] في lib/tables.php)، وهذا الملف يضيف أدواته داخل [data-tools-slot].
 * المناطق الحية تُستبدل بعد التحديث التلقائي (app.js يطلق الحدث wood:regions-updated)، فتُعاد تهيئة
 * الشرائط الجديدة وتطبيق نفس الأعمدة ونص البحث عليها.
 */
(function () {
  'use strict';
  if (typeof document === 'undefined') { return; }
  var C = window.WoodCalc;
  var queries = {}; // نص البحث لكل جدول، يبقى بعد استبدال المنطقة الحية

  function fmtInt(n) { return C ? C.fmtInt(n) : String(n); }

  function loadHidden(key) {
    try {
      var v = JSON.parse(localStorage.getItem('wood.cols.' + key) || '[]');
      return Array.isArray(v) ? v.filter(function (k) { return typeof k === 'string'; }) : [];
    } catch { return []; }
  }
  function saveHidden(key, hidden) {
    try { localStorage.setItem('wood.cols.' + key, JSON.stringify(hidden)); } catch { /* التخزين غير متاح: يبقى الاختيار لهذه الصفحة فقط */ }
  }

  /* نص للمقارنة: أرقام إنجليزية، بلا فواصل آلاف (المسافة الضيقة أو الفاصلة)، وأحرف صغيرة ومسافات موحدة */
  function norm(s) {
    return String(s)
      .replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
      .replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); })
      .replace(/٫/g, '.')
      .replace(/[ ,،]/g, '')
      .replace(/[\s ]+/g, ' ')
      .trim()
      .toLowerCase();
  }

  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (text !== undefined) { e.textContent = text; }
    return e;
  }

  /* يخفي خانات الأعمدة المخفية، ويصغّر الخانات الممتدة (colspan) على عدد أعمدتها الظاهرة */
  function applyColumns(table, hidden) {
    table.querySelectorAll('[data-col]').forEach(function (cell) {
      cell.hidden = hidden.indexOf(cell.dataset.col) !== -1;
    });
    table.querySelectorAll('[data-span]').forEach(function (cell) {
      var keys = cell.dataset.span.split(' ');
      var visible = keys.filter(function (k) { return hidden.indexOf(k) === -1; }).length;
      cell.colSpan = Math.max(1, visible);
    });
  }

  /* البحث السريع: يُخفي الصفوف التي لا تحتوي النص في أعمدتها الظاهرة. الإجمالي الفرعي يظهر مع صفوفه */
  function applySearch(table, query, statusEl) {
    var q = norm(query);
    var total = 0;
    var shown = 0;
    Array.prototype.forEach.call(table.tBodies, function (tbody) {
      var anyInBody = false;
      var subtotals = [];
      Array.prototype.forEach.call(tbody.rows, function (row) {
        if (row.classList.contains('row-subtotal')) { subtotals.push(row); return; }
        if (row.querySelector('[data-empty-row]')) { return; }
        total++;
        var text = '';
        Array.prototype.forEach.call(row.cells, function (cell) { if (!cell.hidden) { text += ' ' + cell.textContent; } });
        var match = q === '' || norm(text).indexOf(q) !== -1;
        row.hidden = !match;
        if (match) { shown++; anyInBody = true; }
      });
      subtotals.forEach(function (row) { row.hidden = !anyInBody; });
    });
    if (!statusEl) { return; }
    if (q === '') {
      statusEl.textContent = '';
      statusEl.hidden = true;
    } else {
      statusEl.textContent = shown === 0
        ? 'لا توجد صفوف مطابقة للبحث في هذه الصفحة.'
        : 'الصفوف الظاهرة: ' + fmtInt(shown) + ' من ' + fmtInt(total) + '. الإجماليات لكل صفوف الجدول.';
      statusEl.hidden = false;
    }
  }

  function setup(tools) {
    if (tools.dataset.ready === '1') { return; }
    var table = document.getElementById(tools.dataset.table);
    var slot = tools.querySelector('[data-tools-slot]');
    if (!table || !slot) { return; }
    tools.dataset.ready = '1';
    var key = tools.dataset.tableTools;
    var slug = key.replace(/[^a-z0-9]+/gi, '-');
    var cols = [];
    try { cols = JSON.parse(tools.dataset.columns || '[]'); } catch { cols = []; }
    var present = cols.map(function (c) { return c.key; });
    var hidden = loadHidden(key).filter(function (k) {
      return present.indexOf(k) !== -1 && !cols.some(function (c) { return c.key === k && c.required; });
    });

    // بحث سريع
    var search = el('div', { 'class': 'field table-search' });
    var sid = 'table-q-' + slug;
    search.appendChild(el('label', { 'for': sid }, 'بحث سريع في الجدول'));
    var input = el('input', { type: 'search', id: sid, maxlength: '100', autocomplete: 'off' });
    input.value = queries[key] || '';
    search.appendChild(input);
    slot.appendChild(search);

    var status = el('p', { 'class': 'hint table-search-status', role: 'status' });
    status.hidden = true;

    // الأعمدة الظاهرة
    if (cols.length > 1) {
      var details = el('details', { 'class': 'table-columns' });
      details.appendChild(el('summary', { 'class': 'btn' }, 'الأعمدة'));
      var panel = el('fieldset', { 'class': 'table-columns-panel' });
      panel.appendChild(el('legend', {}, 'الأعمدة الظاهرة'));
      cols.forEach(function (c, i) {
        var row = el('div', { 'class': 'field-check' });
        var id = 'col-' + slug + '-' + i;
        var cb = el('input', { type: 'checkbox', id: id, value: c.key });
        cb.checked = hidden.indexOf(c.key) === -1;
        cb.defaultChecked = cb.checked;
        if (c.required) { cb.disabled = true; }
        row.appendChild(cb);
        row.appendChild(el('label', { 'for': id }, c.label + (c.required ? ' (دائمًا)' : '')));
        panel.appendChild(row);
      });
      panel.addEventListener('change', function (e) {
        if (!e.target.matches('input[type="checkbox"]')) { return; }
        e.target.defaultChecked = e.target.checked;
        hidden = Array.prototype.filter.call(panel.querySelectorAll('input[type="checkbox"]'), function (cb) { return !cb.checked; })
          .map(function (cb) { return cb.value; });
        saveHidden(key, hidden);
        applyColumns(table, hidden);
        applySearch(table, input.value, status);
      });
      details.appendChild(panel);
      slot.appendChild(details);
    }

    if (tools.dataset.print === '1') {
      slot.appendChild(el('button', { type: 'button', 'class': 'btn', 'data-action': 'print' }, 'طباعة'));
    }

    tools.appendChild(status);

    input.addEventListener('input', function () {
      queries[key] = input.value;
      applySearch(table, input.value, status);
    });
    // Enter في البحث لا يرسل أي نموذج
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });

    // التصدير بالأعمدة الظاهرة على الشاشة
    var exportForm = tools.querySelector('[data-export-form]');
    if (exportForm) {
      exportForm.addEventListener('submit', function () {
        var colsInput = exportForm.querySelector('[data-export-cols]');
        if (colsInput) {
          colsInput.value = hidden.length ? present.filter(function (k) { return hidden.indexOf(k) === -1; }).join(',') : '';
        }
      });
    }

    applyColumns(table, hidden);
    applySearch(table, input.value, status);
  }

  function enhance() { document.querySelectorAll('[data-table-tools]').forEach(setup); }

  // قائمة الأعمدة تُغلق عند الضغط خارجها أو بزر Escape
  document.addEventListener('click', function (e) {
    document.querySelectorAll('details.table-columns[open]').forEach(function (d) {
      if (!d.contains(e.target)) { d.open = false; }
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    document.querySelectorAll('details.table-columns[open]').forEach(function (d) {
      d.open = false;
      d.querySelector('summary').focus();
    });
  });

  document.addEventListener('wood:regions-updated', enhance);
  enhance();
})();
