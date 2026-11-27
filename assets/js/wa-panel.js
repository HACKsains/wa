/**
 * ==============================================================================
 * ILMUNA WA BOT — AJAX LAZY-LOAD & IN-PLACE MUTATION ENGINE (ZERO PAGE RELOAD)
 * ==============================================================================
 */
(function (window) {
    'use strict';

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function escapeHtml(str) {
        if (window.Ilmuna && typeof window.Ilmuna.escape === 'function') {
            return window.Ilmuna.escape(str);
        }
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    async function request(url, options) {
        const opts = Object.assign({ method: 'GET', headers: {} }, options || {});
        opts.headers['X-Requested-With'] = 'XMLHttpRequest';
        opts.headers['Accept'] = 'application/json';
        if (opts.method.toUpperCase() !== 'GET') {
            opts.headers['X-CSRF-Token'] = getCsrfToken();
        }
        const resp = await fetch(url, opts);
        let json = null;
        try {
            json = await resp.json();
        } catch (e) {
            throw new Error('Respon server bukan JSON valid (HTTP ' + resp.status + ')');
        }
        if (resp.status === 401 && json && json.data && json.data.redirect) {
            if (window.Ilmuna) {
                Ilmuna.warning('Sesi Berakhir', json.message || 'Silakan masuk ulang melalui Margo One SSO.');
            }
            setTimeout(function () { window.location.href = json.data.redirect; }, 900);
            return json;
        }
        return json;
    }

    function get(url) {
        return request(url, { method: 'GET' });
    }

    function post(url, payload) {
        return request(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            body: JSON.stringify(payload || {})
        });
    }

    function flashRow(el) {
        if (!el) return;
        el.classList.remove('row-flash');
        void el.offsetWidth;
        el.classList.add('row-flash');
    }

    function removeRow(el, onDone) {
        if (!el) return;
        el.classList.add('row-smooth-out');
        setTimeout(function () {
            el.remove();
            if (typeof onDone === 'function') onDone();
        }, 310);
    }

    function copyText(text, label) {
        if (!navigator.clipboard) {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            if (window.Ilmuna) Ilmuna.success('Disalin', (label || 'Teks') + ' disalin ke clipboard.');
            return;
        }
        navigator.clipboard.writeText(text).then(function () {
            if (window.Ilmuna) Ilmuna.success('Disalin', (label || 'Teks') + ' berhasil disalin ke clipboard.');
        }).catch(function () {
            if (window.Ilmuna) Ilmuna.warning('Salin Manual', 'Tidak dapat menyalin otomatis.');
        });
    }

    function initDropdowns() {
        document.querySelectorAll('[data-dropdown-toggle]').forEach(function (btn) {
            const targetId = btn.getAttribute('data-dropdown-toggle');
            const menu = document.getElementById(targetId);
            if (!menu) return;
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                document.querySelectorAll('.ilmuna-nav-dropdown.is-open').forEach(function (m) {
                    if (m !== menu) m.classList.remove('is-open');
                });
                menu.classList.toggle('is-open');
            });
        });
        document.addEventListener('click', function () {
            document.querySelectorAll('.ilmuna-nav-dropdown.is-open').forEach(function (m) {
                m.classList.remove('is-open');
            });
        });
    }

    function initTabs(containerSelector, onTabChange) {
        const wrap = document.querySelector(containerSelector);
        if (!wrap) return;
        const btns = wrap.querySelectorAll('.curriculum-tab-btn');
        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const tab = this.getAttribute('data-tab');
                btns.forEach(function (b) { b.classList.remove('is-active'); });
                this.classList.add('is-active');
                document.querySelectorAll('.tab-panel').forEach(function (p) {
                    p.classList.toggle('is-active', p.getAttribute('data-tab-panel') === tab);
                });
                if (typeof onTabChange === 'function') onTabChange(tab);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initDropdowns();
        // Mobile sheet toggle
        const openBtn = document.getElementById('btnOpenMobileSheet');
        const backdrop = document.getElementById('mobileNavSheetBackdrop');
        if (openBtn && backdrop) {
            openBtn.addEventListener('click', function () { backdrop.classList.add('is-open'); });
            backdrop.addEventListener('click', function (e) {
                if (e.target === backdrop) backdrop.classList.remove('is-open');
            });
        }
    });

    window.WaPanel = {
        get: get,
        post: post,
        escape: escapeHtml,
        flashRow: flashRow,
        removeRow: removeRow,
        copyText: copyText,
        initTabs: initTabs,
        csrf: getCsrfToken
    };
})(window);
