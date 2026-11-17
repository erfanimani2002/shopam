/* Pure product-form parsing — NO DOM. Shared by ProductDialog and the
 * regression tests in tests.html. Rules (agreed spec):
 *   - an EMPTY optional field means 0;
 *   - a non-empty value that is not a clean non-negative integer raises a
 *     Persian validation error — it is NEVER silently turned into 0;
 *   - buy_price is validated only when the user can see cost;
 *   - Toman values are integers: "12.5" and "-5" are invalid, thousands
 *     separators (ASCII ',' and Persian '٬') and Persian digits are fine. */
'use strict';

var ProductForm = (function () {

  /** Clean non-negative integer from any input, or:
   *  null  -> the field was empty (caller decides the default), or
   *  NaN   -> present but unparsable (garbage/negative/decimal). */
  function strictInt(raw) {
    var t = Format.normalizeDigits(String(raw == null ? '' : raw)).trim();
    if (t === '') return null;
    if (!/^\d+$/.test(t)) return NaN;
    var n = Number(t);
    return isFinite(n) ? n : NaN;
  }

  /**
   * Parse the product dialog's core fields. `values` is a plain map of raw
   * strings (or whatever the inputs contain); `canSeeCost` gates buy_price.
   *
   * @returns {{ok: true, body: object} | {ok: false, error: string}}
   */
  function parseCore(values, canSeeCost) {
    var str = function (v) { return String(v == null ? '' : v); };
    var intField = function (raw, label) {
      var n = strictInt(raw);
      if (n === null) return 0;              // empty optional field -> 0
      if (isNaN(n)) throw label + ' نامعتبر است.';
      return n;
    };
    try {
      var body = {
        name: str(values.name).trim(),
        category: str(values.category).trim(),
        sku: str(values.sku).trim(),
        sell_price: intField(values.sell_price, 'قیمت فروش'),
        stock: intField(values.stock, 'موجودی'),
        low_stock_threshold: intField(values.low_stock_threshold, 'حد هشدار موجودی')
      };
      if (canSeeCost) {
        body.buy_price = intField(values.buy_price, 'قیمت خرید');
      }
      return { ok: true, body: body };
    } catch (e) {
      return { ok: false, error: typeof e === 'string' ? e : 'خطا در ورودی‌ها' };
    }
  }

  return { parseCore: parseCore, strictInt: strictInt };
})();
