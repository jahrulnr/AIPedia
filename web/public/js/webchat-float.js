/**
 * Dashboard floating launcher — last thread + New (lazy) + full-page link.
 * Open float does NOT create a thread.
 */
(function () {
    'use strict';

    const root = document.getElementById('wmFloat');
    if (!root) return;

    const launcher = root.querySelector('[data-wm-float-toggle]');
    const panel = root.querySelector('[data-wm-float-panel]');
    const closeBtn = root.querySelector('[data-wm-float-close]');
    const openFull = root.querySelector('[data-wm-float-full]');
    const newBtn = root.querySelector('[data-wm-float-new]');
    const badge = root.querySelector('[data-wm-float-badge]');
    const fullUrl = root.getAttribute('data-full-url') || '/aipedia/webchat';

    function setOpen(open) {
        root.classList.toggle('is-open', open);
        if (launcher) launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (panel) panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open && badge) badge.hidden = true;
        if (open) {
            const input = root.querySelector('#chatInput');
            if (input) setTimeout(function () { input.focus(); }, 220);
        }
    }

    if (launcher) {
        launcher.addEventListener('click', function () {
            setOpen(!root.classList.contains('is-open'));
        });
    }
    if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false); });
    if (openFull) {
        openFull.addEventListener('click', function () {
            window.location.href = fullUrl;
        });
    }
    if (newBtn) {
        newBtn.addEventListener('click', function () {
            if (window.webchatUi && typeof window.webchatUi.newChat === 'function') {
                window.webchatUi.newChat();
            } else {
                const btn = document.getElementById('chatNew');
                if (btn) btn.click();
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && root.classList.contains('is-open')) setOpen(false);
    });

    document.addEventListener('mousedown', function (e) {
        if (!root.classList.contains('is-open')) return;
        if (!root.contains(e.target)) setOpen(false);
    });
})();
