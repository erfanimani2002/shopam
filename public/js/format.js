/* Persian digit / money formatting helpers. ES5 only, no dependencies. */
'use strict';

var Format = (function () {
  var PERSIAN = '۰۱۲۳۴۵۶۷۸۹';
  var MAP = { '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
    '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
    '٬': '', '،': '', '٫': '.', ' ': '', '-': '-', ',': '' };

  function normalizeDigits(s) {
    // ASCII ',' (thousands separator) is removed too, so "۱,۲۳۴,۵۶۷" and
    // "1,234,567" both parse. '٫' (Persian decimal mark) becomes '.'.
    return String(s == null ? '' : s).replace(/[۰-۹٠-٩٬،٫, ]/g, function (c) {
      return MAP[c] !== undefined ? MAP[c] : c;
    });
  }

  function toPersianDigits(s) {
    return String(s == null ? '' : s).replace(/[0-9]/g, function (d) { return PERSIAN[+d]; });
  }

  /** Thousands separators: 1234567 -> "1,234,567" (then persian digits). */
  function group(s) {
    s = String(s == null ? '' : s);
    var neg = s.charAt(0) === '-';
    var parts = s.replace(/^-/, '').split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (neg ? '-' : '') + parts.join('.');
  }

  /** Money for display: 9000000 -> "۹,۰۰۰,۰۰۰" (Toman). */
  function money(n) {
    var num = Number(normalizeDigits(n));
    if (!isFinite(num)) return '—';
    return toPersianDigits(group(Math.round(num)));
  }

  /** Parse any user input (Persian digits, separators) to an integer. */
  function toInt(s) {
    var n = Number(normalizeDigits(s));
    return isFinite(n) ? Math.round(n) : NaN;
  }

  function toFloat(s) {
    var n = Number(normalizeDigits(s));
    return isFinite(n) ? n : NaN;
  }

  return { normalizeDigits: normalizeDigits, toPersianDigits: toPersianDigits,
    group: group, money: money, toInt: toInt, toFloat: toFloat };
})();
