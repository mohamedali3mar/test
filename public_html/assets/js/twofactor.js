/*
 * التحقق بخطوتين: يرسم رمز QR لرابط otpauth في صفحة الإعدادات أثناء التفعيل.
 * الرابط يأتي من الخاصية data-otpauth، والرسم على canvas بواجهات DOM فقط (بدون innerHTML).
 * المكتبة: assets/js/vendor/qrcode.js (qrcode-generator، ترخيص MIT). بدون JavaScript يبقى المفتاح اليدوي متاحًا.
 */
(function () {
  'use strict';

  var QUIET = 4; // الهامش الأبيض حول الرمز بعدد الوحدات، كما يتطلب معيار QR
  var SCALE = 4; // بكسل لكل وحدة

  function draw(box) {
    var uri = box.getAttribute('data-otpauth');
    if (!uri || typeof qrcode !== 'function') {
      return;
    }
    var qr = qrcode(0, 'M');
    qr.addData(uri);
    qr.make();
    var count = qr.getModuleCount();
    var size = (count + QUIET * 2) * SCALE;
    var canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', box.getAttribute('data-label') || '');
    var ctx = canvas.getContext('2d');
    if (!ctx) {
      return;
    }
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#000000';
    for (var r = 0; r < count; r++) {
      for (var c = 0; c < count; c++) {
        if (qr.isDark(r, c)) {
          ctx.fillRect((c + QUIET) * SCALE, (r + QUIET) * SCALE, SCALE, SCALE);
        }
      }
    }
    box.appendChild(canvas);
    box.hidden = false;
  }

  function init() {
    var boxes = document.querySelectorAll('[data-otpauth]');
    for (var i = 0; i < boxes.length; i++) {
      try {
        draw(boxes[i]);
      } catch {
        // يبقى المفتاح اليدوي والرابط ظاهرين إذا تعذر الرسم
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
