/* Products screen: card grid, search, filters, sort, pagination, selection. */
'use strict';

var Products = (function () {
  var state = { q: '', category: '', archived: '0', low_stock: false, expiring: false,
    page: 1, per_page: 20, sort: 'created', dir: 'desc' };
  var items = [];       // current page items
  var selected = {};    // id -> true (bulk price selection)
  var el = {};

  function $(id) { return document.getElementById(id); }

  function init() {
    el = { grid: $('products-grid'), pager: $('products-pager'), count: $('products-count'),
      search: $('f-search'), category: $('f-category'), lowStock: $('f-low-stock'),
      expiring: $('f-expiring'), archived: $('f-archived'), sort: $('f-sort'),
      add: $('btn-add-product'), bulk: $('btn-bulk-price') };

    var debounce;
    el.search.addEventListener('input', function () {
      clearTimeout(debounce);
      debounce = setTimeout(function () {
        state.q = el.search.value.trim();
        state.page = 1;
        load();
      }, 300);
    });
    ['category', 'archived', 'sort'].forEach(function (k) {
      el[k].addEventListener('change', function () {
        state[k === 'archived' ? 'archived' : k] = el[k].value;
        state.page = 1;
        load();
      });
    });
    el.lowStock.addEventListener('change', function () { state.low_stock = el.lowStock.checked; state.page = 1; load(); });
    el.expiring.addEventListener('change', function () { state.expiring = el.expiring.checked; state.page = 1; load(); });
    el.add.addEventListener('click', function () { ProductDialog.open(null); });
    el.bulk.addEventListener('click', function () { BulkPrice.open(selectedIds()); });

    load();
  }

  function selectedIds() {
    return Object.keys(selected).filter(function (k) { return selected[k]; }).map(Number);
  }

  function load() {
    el.grid.innerHTML = '<div class="muted loading">در حال بارگذاری…</div>';
    var query = { page: state.page, per_page: state.per_page, sort: state.sort, dir: state.dir,
      q: state.q, category: state.category, archived: state.archived };
    if (state.low_stock) query.low_stock = 1;
    if (state.expiring) query.expiring_soon = 1;
    APP.get('products_list', query).then(function (j) {
      try {
        if (!j.ok) {
          if (j.error && j.error.code === 'SESSION_EXPIRED') { location.reload(); return; }
          items = [];
          el.grid.innerHTML = '<div class="muted empty">' +
            APP.esc((j.error && j.error.message) || 'خطا در بارگذاری') + '</div>';
          el.pager.innerHTML = '';
          return;
        }
        APP.schema = j.data.schema || APP.schema;
        items = j.data.list.items || [];
        window._lastProducts = items; // used by the bulk-price preview
        render(j.data.list);
      } catch (e) {
        console.error('products render failed', e);
        items = [];
        el.grid.innerHTML = '<div class="muted empty">خطا در نمایش محصولات</div>';
        APP.toast('خطا در نمایش محصولات', false);
      } finally {
        // The loading placeholder must never survive: every path above
        // replaces the grid, this removes it even if one is added twice.
        var loading = el.grid.querySelector('.loading');
        if (loading) loading.remove();
      }
    }, function (e) {
      // Rejection path (network-level failures are normally already mapped
      // to {ok:false} by APP.call, but never leave the loading state).
      console.error('products load failed', e);
      el.grid.innerHTML = '<div class="muted empty">خطا در بارگذاری محصولات</div>';
    });
  }

  function render(list) {
    APP.schema = APP.schema || [];
    if (items.length === 0) {
      el.grid.innerHTML = '<div class="muted empty">محصولی یافت نشد.</div>';
      el.pager.innerHTML = '';
      el.count.textContent = '';
      return;
    }
    el.count.textContent = 'مجموع: ' + Format.toPersianDigits(list.total) + ' محصول';
    // One broken product must never block the whole grid.
    el.grid.innerHTML = items.map(function (p) {
      try {
        return card(p);
      } catch (e) {
        console.error('product card render failed (id=' + (p && p.id) + ')', e);
        return '<div class="product-card broken"><div class="body">'
          + '<div class="name muted">نمایش این محصول ممکن نیست</div></div></div>';
      }
    }).join('');
    wireCards();
    renderPager(list);
    var bulk = $('btn-bulk-price');
    bulk.disabled = selectedIds().length === 0;
  }

  function card(p) {
    var img = p.cover
      ? '<img class="cover" loading="lazy" src="' + APP.esc(APP.imageUrl(p.cover)) + '" alt="">'
      : '<div class="cover placeholder"></div>';
    var brand = '';
    APP.schema.forEach(function (f) {
      if (f.in_list && f.type !== 'number' && p.extra_fields && p.extra_fields[f.key] !== undefined) {
        brand = '<div class="muted small">' + APP.esc(f.label) + ': ' + APP.esc(p.extra_fields[f.key]) + '</div>';
      }
    });
    var badges = '';
    if (p.expired) badges += '<span class="badge expired">منقضی شده</span>';
    else if (p.expiring) badges += '<span class="badge expiring">نزدیک انقضا</span>';
    // THE single source of truth: the server-computed is_low_stock flag
    // (product threshold if > 0, else the shop default). Never recompute here.
    if (p.is_low_stock) badges += '<span class="badge low">موجودی کم</span>';

    var cost = APP.canSeeCost
      ? '<div class="cost small">خرید: ' + Format.money(p.buy_price) + ' تومان | سود واحد: '
        + Format.money((p.sell_price || 0) - (p.buy_price || 0)) + ' تومان</div>'
      : '';

    return '<div class="product-card' + (p.archived ? ' archived' : '') + '" data-id="' + p.id + '">'
      + '<label class="select"><input type="checkbox" class="pick" data-id="' + p.id + '"></label>'
      + img
      + '<div class="body">'
      + '<div class="name">' + APP.esc(p.name) + '</div>'
      + brand
      + '<div class="price">' + Format.money(p.sell_price) + ' تومان</div>'
      + '<div class="muted small">موجودی: ' + Format.toPersianDigits(p.stock) + '</div>'
      + badges
      + cost
      + '</div>'
      + '<div class="actions">'
      + '<button class="mini edit" data-id="' + p.id + '">ویرایش</button>'
      + (p.archived
        ? '<button class="mini unarchive" data-id="' + p.id + '">بازگردانی</button>'
        : '<button class="mini archive" data-id="' + p.id + '">بایگانی</button>')
      + '</div></div>';
  }

  function wireCards() {
    el.grid.querySelectorAll('.pick').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var id = +cb.getAttribute('data-id');
        if (cb.checked) selected[id] = true; else delete selected[id];
        $('btn-bulk-price').disabled = selectedIds().length === 0;
      });
    });
    el.grid.querySelectorAll('.edit').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = +b.getAttribute('data-id');
        var p = items.filter(function (x) { return x.id === id; })[0];
        ProductDialog.open(p);
      });
    });
    el.grid.querySelectorAll('.archive, .unarchive').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = +b.getAttribute('data-id');
        var action = b.classList.contains('archive') ? 'product_archive' : 'product_unarchive';
        APP.post(action, { id: id }).then(function (j) {
          if (j.ok) { APP.toast('انجام شد', true); load(); }
          else APP.toast(j.error.message || 'خطا', false);
        });
      });
    });
  }

  function renderPager(list) {
    if (list.total_pages <= 1) { el.pager.innerHTML = ''; return; }
    var html = '<button class="mini" id="pg-prev"' + (list.page <= 1 ? ' disabled' : '') + '>قبلی</button>'
      + '<span class="muted small">صفحه ' + Format.toPersianDigits(list.page) + ' از ' + Format.toPersianDigits(list.total_pages) + '</span>'
      + '<button class="mini" id="pg-next"' + (list.page >= list.total_pages ? ' disabled' : '') + '>بعدی</button>';
    el.pager.innerHTML = html;
    $('pg-prev').addEventListener('click', function () { state.page--; load(); });
    $('pg-next').addEventListener('click', function () { state.page++; load(); });
  }

  function reload() { load(); }

  return { init: init, reload: reload, state: state, card: card };
})();
