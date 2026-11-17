/* Add/Edit product dialog. The form is GENERATED from the tenant's field
 * schema (APP.schema) — no cosmetics field is hardcoded here. Buy price
 * appears only for users with can_see_cost. Persian digits accepted. */
'use strict';

var ProductDialog = (function () {
  var current = null;   // product being edited (null = create)
  function $(id) { return document.getElementById(id); }

  function open(product) {
    current = product;
    $('dlg-title').textContent = product ? 'ویرایش محصول' : 'محصول جدید';
    $('dlg-error').classList.add('hidden');
    buildForm(product);
    ProductImages.mount(product ? product.id : 0);
    $('product-dialog').classList.remove('hidden');
  }

  function close() {
    $('product-dialog').classList.add('hidden');
    current = null;
    Products.reload();
  }

  function buildForm(p) {
    var box = $('dlg-form');
    var schema = APP.schema || [];
    var extra = (p && p.extra_fields) || {};
    var html = '';

    html += fieldHtml('name', 'نام محصول *', p ? p.name : '', 'text');
    html += fieldHtml('category', 'دسته‌بندی', p ? p.category : '', 'text');
    html += fieldHtml('sku', 'بارکد/SKU', p ? p.sku : '', 'text', 'ltr');

    if (APP.canSeeCost) {
      html += fieldHtml('buy_price', 'قیمت خرید (تومان)', p ? Format.toPersianDigits(Format.group(p.buy_price || 0)) : '', 'number');
    }
    html += fieldHtml('sell_price', 'قیمت فروش (تومان)', p ? Format.toPersianDigits(Format.group(p.sell_price || 0)) : '', 'number');
    html += fieldHtml('stock', 'موجودی', p ? Format.toPersianDigits(p.stock) : '', 'number');
    html += fieldHtml('low_stock_threshold', 'حد هشدار موجودی (۰ = تنظیم فروشگاه)', p ? Format.toPersianDigits(p.low_stock_threshold || 0) : '', 'number');

    // Data-driven extra fields from the tenant's preset schema.
    schema.forEach(function (f) {
      var value = extra[f.key];
      var shown = value === undefined || value === null ? '' : value;
      if (f.type === 'date' && shown) shown = Jalali.fromSqlDate(shown) || '';
      if (f.type === 'number' && shown !== '') shown = Format.toPersianDigits(Format.group(shown));
      var key = 'extra_' + f.key;
      if (f.type === 'choice') {
        html += '<label for="' + key + '">' + APP.esc(f.label) + '</label><select id="' + key + '">';
        html += '<option value=""></option>';
        (f.options || []).forEach(function (o) {
          html += '<option value="' + APP.esc(o) + '"' + (shown === o ? ' selected' : '') + '>' + APP.esc(o) + '</option>';
        });
        html += '</select>';
      } else {
        html += fieldHtml(key, APP.esc(f.label) + (f.required ? ' *' : ''), shown,
          f.type === 'date' ? 'jalali' : (f.type === 'number' ? 'number' : 'text'),
          f.key === 'barcode' ? 'ltr' : '');
      }
    });

    box.innerHTML = html;

    // Thousands separators while typing numeric inputs.
    box.querySelectorAll('input[data-kind="number"]').forEach(function (input) {
      input.addEventListener('input', function () {
        var digits = Format.normalizeDigits(input.value).replace(/[^\d]/g, '');
        input.value = digits === '' ? '' : Format.toPersianDigits(Format.group(digits));
      });
    });
    // Jalali date inputs validate on blur.
    box.querySelectorAll('input[data-kind="jalali"]').forEach(function (input) {
      input.addEventListener('blur', function () {
        if (input.value.trim() === '') return;
        if (!Jalali.toSqlDate(input.value)) {
          input.classList.add('bad');
          APP.toast('تاریخ را به شکل ۱۴۰۳/۰۵/۱۲ وارد کنید', false);
        } else {
          input.classList.remove('bad');
        }
      });
    });
  }

  function fieldHtml(key, label, value, kind, dir) {
    return '<label for="' + key + '">' + label + '</label>'
      + '<input id="' + key + '" data-kind="' + kind + '"' + (dir ? ' dir="' + dir + '"' : '')
      + ' value="' + APP.esc(value) + '" autocomplete="off">';
  }

  function collect() {
    var schema = APP.schema || [];
    var res = ProductForm.parseCore({
      name: $('name').value,
      category: $('category').value,
      sku: $('sku').value,
      sell_price: $('sell_price').value,
      stock: $('stock').value,
      low_stock_threshold: $('low_stock_threshold').value,
      buy_price: APP.canSeeCost ? $('buy_price').value : undefined
    }, APP.canSeeCost);
    if (!res.ok) throw res.error;   // save() shows it in the dialog
    var body = res.body;

    var extra = {};
    schema.forEach(function (f) {
      var input = $('extra_' + f.key);
      if (!input) return;
      var raw = input.value.trim();
      if (raw === '') return;
      if (f.type === 'number') {
        var n = Format.toFloat(raw);
        if (isNaN(n)) throw '«' + f.label + '» عدد نیست.';
        extra[f.key] = n;
      } else if (f.type === 'date') {
        var g = Jalali.toSqlDate(raw);
        if (!g) throw 'تاریخ «' + f.label + '» نامعتبر است.';
        extra[f.key] = g;      // sent as Gregorian, shown as Jalali
      } else {
        extra[f.key] = raw;
      }
    });
    body.extra_fields = extra;
    return body;
  }

  function save() {
    var err = $('dlg-error');
    err.classList.add('hidden');
    var body;
    try {
      body = collect();
    } catch (msg) {
      err.textContent = typeof msg === 'string' ? msg : 'خطا در ورودی‌ها';
      err.classList.remove('hidden');
      return;
    }
    var action = current ? 'product_update' : 'product_create';
    if (current) body.id = current.id;
    APP.post(action, body).then(function (j) {
      if (!j.ok) {
        err.textContent = (j.error && j.error.message) || 'خطا';
        err.classList.remove('hidden');
        return;
      }
      APP.toast(current ? 'محصول به‌روزرسانی شد' : 'محصول ذخیره شد — حالا می‌توانید تصویر اضافه کنید', true);
      current = j.data.product;
      $('dlg-title').textContent = 'ویرایش محصول';
      buildForm(current);
      ProductImages.mount(current.id);
    });
  }

  return { open: open, close: close, save: save };
})();
