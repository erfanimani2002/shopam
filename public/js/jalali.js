/* Jalali <-> Gregorian conversion + date helpers.
 * Port of the same algorithm as app/Helpers/Jalali.php (jalaali-js), so the
 * UI shows Jalali while the DB stores Gregorian. No build step, ES5 only. */
'use strict';

var Jalali = (function () {
  var BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181,
    1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

  function div(a, b) { return Math.trunc(a / b); }
  function mod(a, b) { return a - Math.trunc(a / b) * b; }

  function jalCal(jy) {
    var bl = BREAKS.length, gy = jy + 621, leapJ = -14, jp = BREAKS[0], jump = 0, i, jm, n, leapG, march, leap;
    if (jy < jp || jy >= BREAKS[bl - 1]) throw new Error('Invalid Jalaali year: ' + jy);
    for (i = 1; i < bl; i++) {
      jm = BREAKS[i];
      jump = jm - jp;
      if (jy < jm) break;
      leapJ = leapJ + div(jump, 33) * 8 + div(mod(jump, 33), 4);
      jp = jm;
    }
    n = jy - jp;
    leapJ = leapJ + div(n, 33) * 8 + div(mod(n, 33) + 3, 4);
    if (mod(jump, 33) === 4 && jump - n === 4) leapJ += 1;
    leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
    march = 20 + leapJ - leapG;
    if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33;
    leap = mod(mod(n + 1, 33) - 1, 4);
    if (leap === -1) leap = 4;
    return { leap: leap, gy: gy, march: march };
  }

  function g2d(gy, gm, gd) {
    var d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4)
      + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408;
    return d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
  }

  function d2g(jd) {
    var j = 4 * jd + 139361631;
    j = j + div(div(4 * jd + 183187720, 146097) * 3, 4) * 4 - 3908;
    var i = div(mod(j, 1461), 4) * 5 + 308;
    var gd = div(mod(i, 153), 5) + 1;
    var gm = mod(div(i, 153), 12) + 1;
    var gy = div(j, 1461) - 100100 + div(8 - gm, 6);
    return [gy, gm, gd];
  }

  function j2d(jy, jm, jd) {
    var r = jalCal(jy);
    return g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
  }

  function d2j(jd) {
    var gy = d2g(jd)[0], jy = gy - 621, r = jalCal(jy), jdn1f = g2d(gy, 3, r.march), k = jd - jdn1f;
    if (k >= 0) {
      if (k <= 185) return [jy, 1 + div(k, 31), mod(k, 31) + 1];
      k -= 186;
    } else {
      jy -= 1;
      k += 179;
      if (r.leap === 1) k += 1;
    }
    return [jy, 7 + div(k, 30), mod(k, 30) + 1];
  }

  function toJalali(gy, gm, gd) { return d2j(g2d(gy, gm, gd)); }
  function toGregorian(jy, jm, jd) { return d2g(j2d(jy, jm, jd)); }
  function isLeap(jy) { return jalCal(jy).leap === 0; }
  function daysInMonth(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    return isLeap(jy) ? 30 : 29;
  }
  function isValid(jy, jm, jd) {
    if (jy < 1 || jm < 1 || jm > 12 || jd < 1) return false;
    return jd <= daysInMonth(jy, jm);
  }
  function pad2(n) { return (n < 10 ? '0' : '') + n; }

  /** "1403/05/12" (or 1403-05-12, Persian digits) -> "2024-08-02", else null. */
  function toSqlDate(input) {
    var s = Format.normalizeDigits(String(input == null ? '' : input)).trim();
    var m = s.match(/^(\d{3,4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
    if (!m) return null;
    var jy = +m[1], jm = +m[2], jd = +m[3];
    if (!isValid(jy, jm, jd)) return null;
    var g = toGregorian(jy, jm, jd);
    return g[0] + '-' + pad2(g[1]) + '-' + pad2(g[2]);
  }

  /** "2024-08-02" -> "1403/05/12", else null. */
  function fromSqlDate(sql) {
    var m = String(sql == null ? '' : sql).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return null;
    var j = toJalali(+m[1], +m[2], +m[3]);
    return j[0] + '/' + pad2(j[1]) + '/' + pad2(j[2]);
  }

  /** "2024-08-02" -> "۱۴۰۳/۰۵/۱۲" for display. */
  function display(sql) {
    var j = fromSqlDate(sql);
    return j ? Format.toPersianDigits(j) : '—';
  }

  return { toJalali: toJalali, toGregorian: toGregorian, isLeap: isLeap, daysInMonth: daysInMonth,
    isValid: isValid, toSqlDate: toSqlDate, fromSqlDate: fromSqlDate, display: display };
})();
