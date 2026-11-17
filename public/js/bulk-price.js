/* Bulk price update dialog: percent or fixed delta, applied to the selected
 * products, with a client-side preview of a few results before applying. */
'use strict';

var BulkPrice = (function () {
  var ids = [];
  function $(id) { return document.getElementById(id); }

  function open(selected) {
    ids = selected;
    if (!ids.length) { APP.toast('ابتدا محصولاتی را انتخاب کنید', false); return; }
    $('bp-error').classList.add('hidden');
    $('bp-value').value = '';
    $('bp-preview').innerHTML = '';
    $('bp-include-buy').classList.toggle('hidden', !APP.canSeeCost);
    $('bp-count').textContent = 'تعداد انتخاب‌شده: ' + Format.toPersianDigits(ids.length);
    $('bulk-dialog').classList.remove('hidden');
    preview();
  }

  function close() { $('bulk-dialog').classList.add('hidden'); }

  function mode() { return $('bp-mode').value; }
  function value() {
    var v = Format.toFloat($('bp-value').value);
    return isNaN(v) ? null : v;
  }

  function preview() {
    var v = value();
    var box = $('bp-preview');
    if (v === null) { box.innerHTML = '<div class="muted small">مقدار را وارد کنید…</div>'; return; }
    var step = 1000; // approximate preview; the server rounds with the shop's step
    var rows = [];
    var source = window._lastProducts || [];
    var count = 0;
    source.forEach(function (p) {
      if (count >= 5 || ids.indexOf(p.id) === -1) return;
      count++;
      var sell = p.sell_price || 0;
      var news = mode() === 'percent'
        ? Math.max(0, Math.round((sell * (1 + v / 100)) / step) * step)
        : Math.max(0, Math.round((sell + v) / step) * step);
      rows.push(APP.esc(p.name) + ': ' + Format.money(sell) + ' ← ' + Format.money(news) + ' تومان');
      if (APP.canSeeCost && $('bp-include-buy-input').checked) {
        var buy = p.buy_price || 0;
        var nb = mode() === 'percent'
          ? Math.max(0, Math.round((buy * (1 + v / 100)) / step) * step)
          : Math.max(0, Math.round((buy + v) / step) * step);
        rows[rows.length - 1] += ' (خرید: ' + Format.money(buy) + ' ← ' + Format.money(nb) + ')';
      }
    });
    box.innerHTML = rows.map(function (r) { return '<div class="small">' + r + '</div>'; }).join('');
  }

  function apply() {
    var v = value();
    if (v === null) {
      APP.showError('bp-error', { error: { message: 'مقدار تغییر قیمت را وارد کنید' } });
      return;
    }
    APP.post('products_bulk_price', {
      mode: mode(), value: v, ids: ids,
      include_buy_price: APP.canSeeCost && $('bp-include-buy-input').checked ? 1 : 0
    }).then(function (j) {
      if (!j.ok) { APP.showError('bp-error', j); return; }
      APP.toast(Format.toPersianDigits(j.data.updated) + ' محصول به‌روزرسانی شد', true);
      close();
      Products.reload();
    });
  }

  function init() {
    $('bp-mode').addEventListener('change', preview);
    $('bp-value').addEventListener('input', function () {
      var digits = Format.normalizeDigits($('bp-value').value);
      $('bp-value').value = digits;
      preview();
    });
    $('bp-include-buy-input').addEventListener('change', preview);
    $('btn-bp-cancel').addEventListener('click', close);
    $('btn-bp-apply').addEventListener('click', apply);
  }

  return { open: open, init: init };
})();
