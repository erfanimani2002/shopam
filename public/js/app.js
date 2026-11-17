/* App core: base paths (sub-folder safe), API helper, session, theme, shell.
 * No hardcoded absolute paths: everything derives from the page's own URL,
 * so the app works from / as well as from any cPanel sub-folder. */
'use strict';

var APP = (function () {
  var app = {};

  /** Folder that contains index.html — works from any sub-folder. */
  app.base = new URL('.', location.href).href;
  app.apiBase = app.base + 'api/';

  app.user = null;
  app.tenant = null;
  app.csrfToken = '';
  app.schema = [];          // tenant's product field schema (from products_list)
  app.canSeeCost = false;
  app.role = '';

  app.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  app.url = function (path) { return app.base + String(path || ''); };

  /* Low-level JSON call. Rejects nothing; resolves {ok, data|error}. */
  app.call = function (action, opts) {
    opts = opts || {};
    var url = app.apiBase + '?action=' + encodeURIComponent(action);
    if (opts.query) {
      Object.keys(opts.query).forEach(function (k) {
        url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(opts.query[k]);
      });
    }
    return fetch(url, {
      method: opts.method || 'GET',
      headers: opts.method === 'POST' && !(opts.body instanceof FormData)
        ? { 'Content-Type': 'application/json' } : undefined,
      body: opts.body instanceof FormData ? opts.body
        : (opts.method === 'POST' ? JSON.stringify(opts.body || {}) : undefined),
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).catch(function () {
      return { ok: false, error: { code: 'SERVER_ERROR', message: 'خطای ارتباط با سرور' } };
    });
  };

  /** GET without CSRF. */
  app.get = function (action, query) { return app.call(action, { query: query }); };

  /** POST with the session CSRF token; on CSRF_INVALID retries once fresh. */
  app.post = function (action, body, retry) {
    var run = function (token) {
      return app.call(action, { method: 'POST', body: Object.assign({}, body, { csrf_token: token }) });
    };
    return run(app.csrfToken).then(function (j) {
      if (j.ok) return j;
      if (j.error && j.error.code === 'CSRF_INVALID' && !retry) {
        return app.get('csrf').then(function (c) {
          if (c.ok) app.csrfToken = c.data.csrf_token;
          return app.post(action, body, true);
        });
      }
      return j;
    });
  };

  /** Multipart upload with progress (XHR — fetch has no upload progress). */
  app.upload = function (action, formData, onProgress) {
    return new Promise(function (resolve) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', app.apiBase + '?action=' + encodeURIComponent(action));
      xhr.responseType = 'json';
      xhr.upload.onprogress = function (e) {
        if (onProgress && e.lengthComputable) onProgress(e.loaded / e.total);
      };
      xhr.onerror = function () {
        resolve({ ok: false, error: { code: 'SERVER_ERROR', message: 'خطای ارتباط با سرور' } });
      };
      xhr.onload = function () { resolve(xhr.response || { ok: false, error: { code: 'SERVER_ERROR', message: 'خطای سرور' } }); };
      xhr.send(formData);
    });
  };

  app.toast = function (message, ok) {
    var el = document.getElementById('toast');
    el.textContent = message;
    el.classList.toggle('bad', !ok);
    el.classList.remove('hidden');
    clearTimeout(app._toastTimer);
    app._toastTimer = setTimeout(function () { el.classList.add('hidden'); }, 3500);
  };

  app.showError = function (id, j) {
    var el = document.getElementById(id);
    if (!el) return;
    el.textContent = (j && j.error && j.error.message) ? j.error.message : 'خطای نامشخص';
    el.classList.remove('hidden');
  };

  /* Theme: stored choice wins, otherwise follow the system. */
  app.initTheme = function () {
    var theme = localStorage.getItem('shopam-theme');
    if (theme) document.documentElement.setAttribute('data-theme', theme);
    document.getElementById('btn-theme').addEventListener('click', function () {
      var cur = document.documentElement.getAttribute('data-theme');
      var dark = cur ? cur === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
      theme = dark ? 'light' : 'dark';
      if (dark) document.documentElement.setAttribute('data-theme', 'dark');
      else document.documentElement.removeAttribute('data-theme');
      localStorage.setItem('shopam-theme', theme);
    });
  };

  app.imageUrl = function (image, medium) {
    if (!image || !image.path) return '';
    var p = medium ? (image.medium_path || image.path) : image.path;
    return app.url(p);
  };
  app.tenantId = 0;

  app.logout = function () {
    app.post('logout', {}).then(function () { location.reload(); });
  };

  /* Called after login or a successful me: swap login card for the shell. */
  app.initShell = function (data) {
    app.user = data.user;
    app.tenant = data.tenant;
    app.csrfToken = data.csrf_token || '';
    app.canSeeCost = !!data.user.can_see_cost;
    app.role = data.user.role;
    app.tenantId = data.tenant.id;

    document.getElementById('login-card').classList.add('hidden');
    var shell = document.getElementById('shell');
    shell.classList.remove('hidden');
    document.getElementById('shop-name').textContent = data.tenant.name;
    if (data.tenant.subscription_status !== 'active') {
      var warn = document.getElementById('sub-warning');
      warn.textContent = 'اشتراک فروشگاه منقضی شده است؛ فقط مشاهده و خروجی گرفتن فعال است.';
      warn.classList.remove('hidden');
    }
    Products.init();
  };

  app.boot = function () {
    app.initTheme();
    document.getElementById('btn-logout').addEventListener('click', app.logout);
    app.get('me').then(function (j) {
      if (j.ok) app.initShell(j.data);
      else document.getElementById('login-card').classList.remove('hidden');
    });
  };

  return app;
})();
