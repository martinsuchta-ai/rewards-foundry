/* shell.js — shared admin-UI plumbing.
 *
 * Exposes globals on `window.RF`:
 *   RF.api(path, opts)       — fetch wrapper that auto-attaches the
 *                              admin session header and parses JSON
 *   RF.requireSession(cb)    — probe /api/admin/auth.php?action=probe;
 *                              on 401 redirect to /admin/login. Calls
 *                              cb(profile) on success.
 *   RF.logout()              — POST logout + redirect to /admin/login
 *   RF.esc(s)                — HTML-escape a string for innerHTML
 *   RF.fmtDate(iso)          — local "MMM D, HH:mm" formatter
 *
 * The session token is stored in BOTH localStorage (so other admin
 * pages on the same origin can read it) and a HttpOnly cookie (set
 * by /api/admin/auth.php on login, so cross-origin fetches with
 * credentials: 'include' still authenticate even if localStorage is
 * cleared). The header is the primary auth path; the cookie is the
 * belt-and-braces fallback.
 */

(function () {
  var RF = {};
  var LS_KEY = 'rewards_admin_session';

  function getToken() {
    try {
      var raw = localStorage.getItem(LS_KEY);
      if (!raw) return '';
      var j = JSON.parse(raw);
      return (j && j.token) || '';
    } catch (_e) { return ''; }
  }

  function setToken(token, expiresAt) {
    try {
      localStorage.setItem(LS_KEY, JSON.stringify({
        token: token,
        expires_at: expiresAt
      }));
    } catch (_e) {}
  }

  function clearToken() {
    try { localStorage.removeItem(LS_KEY); } catch (_e) {}
  }

  RF.api = function (path, opts) {
    opts = opts || {};
    opts.headers = opts.headers || {};
    if (!opts.headers['Content-Type'] && opts.body) {
      opts.headers['Content-Type'] = 'application/json';
    }
    var token = getToken();
    if (token) opts.headers['X-Admin-Session'] = token;
    /* credentials: 'include' so the cookie is sent — same-origin
       always, cross-origin when allow-credentials is on. */
    opts.credentials = opts.credentials || 'include';
    return fetch(path, opts).then(function (r) {
      var ct = r.headers.get('content-type') || '';
      if (ct.indexOf('application/json') === 0) {
        return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; });
      }
      return r.text().then(function (t) { return { ok: r.ok, status: r.status, body: t }; });
    });
  };

  RF.requireSession = function (cb) {
    RF.api('/api/admin/auth.php?action=probe').then(function (res) {
      if (res.status === 401 || !res.body || !res.body.ok) {
        clearToken();
        window.location.href = '/admin/login';
        return;
      }
      if (typeof cb === 'function') cb(res.body);
    }).catch(function () {
      clearToken();
      window.location.href = '/admin/login';
    });
  };

  RF.logout = function () {
    RF.api('/api/admin/auth.php?action=logout', { method: 'POST' })
      .catch(function () { /* ignore */ })
      .then(function () {
        clearToken();
        window.location.href = '/admin/login';
      });
  };

  RF.esc = function (s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  };

  RF.fmtDate = function (iso) {
    if (!iso) return '';
    try {
      var d = new Date(String(iso).replace(' ', 'T') + 'Z');
      if (isNaN(d.getTime())) return iso;
      var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      var hh = String(d.getHours()).padStart(2, '0');
      var mm = String(d.getMinutes()).padStart(2, '0');
      return months[d.getMonth()] + ' ' + d.getDate() + ', ' + hh + ':' + mm;
    } catch (_e) { return iso; }
  };

  /* Login callers stash the freshly-issued token. */
  RF.setSession = function (token, expiresAt) { setToken(token, expiresAt); };
  RF.clearSession = clearToken;

  window.RF = RF;
})();
