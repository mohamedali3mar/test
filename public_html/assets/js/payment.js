/* حقول الدفع في البيع والوارد: يظهر المبلغ المدفوع في الدفع الجزئي فقط،
   وفي الوارد تظهر تفاصيل الدفع عند إدخال التكلفة. بدون JavaScript تظهر كل الحقول. */
(function () {
  'use strict';

  function costInput(box) {
    var id = box.getAttribute('data-payment-cost');
    return id ? document.getElementById(id) : null;
  }

  function update(box) {
    var checked = box.querySelector('[data-payment-type]:checked');
    var paid = box.querySelector('[data-paid-field]');
    if (paid) {
      paid.hidden = !checked || checked.value !== 'partial';
    }
    var cost = costInput(box);
    var details = box.querySelector('[data-payment-details]');
    if (cost && details) {
      details.hidden = cost.value.trim() === '';
    }
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-payment]'), function (box) {
      update(box);
      box.addEventListener('change', function () { update(box); });
      var cost = costInput(box);
      if (cost) {
        cost.addEventListener('input', function () { update(box); });
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
