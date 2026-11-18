/* Company Portal - UI enhancements + partial navigation (PJAX) */
(function () {
  'use strict';

  function initPage() {
    // Password visibility toggle (login page)
    const toggle = document.getElementById('togglePassword');
    const password = document.getElementById('password');
    if (toggle && password && !toggle.dataset.bound) {
      toggle.dataset.bound = '1';
      toggle.addEventListener('click', function () {
        const isPassword = password.type === 'password';
        password.type = isPassword ? 'text' : 'password';
        const icon = toggle.querySelector('i');
        if (icon) icon.className = isPassword ? 'ti ti-eye-off' : 'ti ti-eye';
      });
    }

    // Prevent double submission of forms
    document.querySelectorAll('form').forEach(function (form) {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      form.addEventListener('submit', function () {
        const submit = form.querySelector('button[type="submit"]');
        if (submit && !submit.disabled) setTimeout(function () { submit.disabled = true; }, 10);
      });
    });

    // Auto-dismiss success alerts after 6s
    document.querySelectorAll('.alert-success').forEach(function (alert) {
      if (alert.dataset.bound) return;
      alert.dataset.bound = '1';
      setTimeout(function () {
        if (window.bootstrap && bootstrap.Alert) {
          try { bootstrap.Alert.getOrCreateInstance(alert).close(); } catch (e) {}
        }
      }, 6000);
    });
  }

  initPage();

  // ---------- Partial navigation ----------
  const BASE = (document.querySelector('base') && document.querySelector('base').href) || (location.origin + '/');
  function sameOrigin(url) {
    try { const u = new URL(url, location.href); return u.origin === location.origin; } catch (e) { return false; }
  }
  function isNavigableLink(a) {
    if (!a || !a.getAttribute('href')) return false;
    const href = a.getAttribute('href');
    if (href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) return false;
    if (a.target && a.target !== '' && a.target !== '_self') return false;
    if (a.hasAttribute('download')) return false;
    if (a.dataset.noPjax !== undefined) return false;
    return sameOrigin(href);
  }

  // progress bar
  let bar = null;
  function showProgress() {
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'pjax-progress';
      bar.style.cssText = 'position:fixed;top:0;left:0;height:3px;width:0;background:#6366f1;z-index:9999;transition:width .2s,opacity .3s;';
      document.body.appendChild(bar);
    }
    bar.style.opacity = '1';
    bar.style.width = '30%';
    requestAnimationFrame(function () { if (bar) bar.style.width = '70%'; });
  }
  function hideProgress() {
    if (bar) { bar.style.width = '100%'; setTimeout(function () { if (bar) { bar.style.opacity = '0'; bar.style.width = '0'; } }, 300); }
  }

  function updateSidebarActive(path) {
    const links = document.querySelectorAll('.sidebar-menu .nav-link');
    // find longest matching prefix
    let best = null, bestLen = -1;
    links.forEach(function (l) {
      try {
        const u = new URL(l.getAttribute('href'), location.href);
        const lp = u.pathname.replace(/\/+$/, '') || '/';
        if (path === lp || path.startsWith(lp + '/')) {
          if (lp.length > bestLen) { bestLen = lp.length; best = l; }
        }
      } catch (e) {}
    });
    links.forEach(function (l) { l.classList.remove('active'); });
    if (best) best.classList.add('active');
  }

  function executeScripts(container) {
    const scripts = container.querySelectorAll('script');
    scripts.forEach(function (oldScript) {
      const newScript = document.createElement('script');
      if (oldScript.src) {
        newScript.src = oldScript.src;
        newScript.async = false;
      } else {
        newScript.textContent = oldScript.textContent;
      }
      document.body.appendChild(newScript);
      oldScript.remove();
    });
  }

  async function navigate(url, push) {
    if (!sameOrigin(url)) { location.href = url; return; }
    const u = new URL(url, location.href);
    if (u.pathname === location.pathname && u.search === location.search && !push) return;
    showProgress();
    try {
      const fetchUrl = new URL(u.href);
      fetchUrl.searchParams.set('partial', '1');
      const res = await fetch(fetchUrl.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const ct = res.headers.get('content-type') || '';
      if (!ct.includes('text/html')) { location.href = url; return; }
      const html = await res.text();
      const title = res.headers.get('X-Page-Title');
      const main = document.querySelector('main.app-main');
      if (!main) throw new Error('no main');
      main.innerHTML = html;
      executeScripts(main);
      if (title) document.title = title;
      if (push !== false) history.pushState({ url: u.href }, '', u.href);
      updateSidebarActive(u.pathname);
      initPage();
      window.scrollTo(0, 0);
    } catch (e) {
      // fallback to full load
      location.href = url;
    } finally {
      hideProgress();
    }
  }

  document.addEventListener('click', function (e) {
    const a = e.target && e.target.closest ? e.target.closest('a') : null;
    if (!a || !isNavigableLink(a)) return;
    // skip if modifier keys
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    navigate(a.href, true);
  });

  window.addEventListener('popstate', function () {
    navigate(location.href, false);
  });

  // Forms: submit via fetch then navigate to redirect target if possible
  document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!form || form.dataset.noPjax !== undefined) return;
    // only handle GET forms or standard POST? Keep POST normal to avoid complexity, but try AJAX for POST too.
    // We'll let forms submit normally (full reload) for reliability.
  }, true);
})();
