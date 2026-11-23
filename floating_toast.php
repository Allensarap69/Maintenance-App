<?php
// Floating toast notification component (global).
// - Included via the shared sidebar templates, so it is active on every page.
// - Auto-converts rendered Bootstrap `.alert-dismissible` blocks into floating
//   toasts (including alerts injected later via JS/AJAX).
// - Shims Swal.fire: notification-style calls become toasts, while dialogs
//   that need a decision (cancel button, deny button, input, preConfirm)
//   still render as SweetAlert modals.
// - To fire a toast from PHP on page load, set $toast_msg and $toast_type
//   ('success'|'error'|'warning'|'info'|'danger') BEFORE including this file.
//   Works even on repeat includes.
// - To fire a toast from JS anytime: showToast('message', 'success');
// - To keep an inline .alert on the page, add data-no-toast to it.
$__toast_msg = isset($toast_msg) ? (string)$toast_msg : '';
$__toast_type = isset($toast_type) ? (string)$toast_type : 'success';
// One-shot toast queued before a redirect: $_SESSION['flash_toast'] = ['msg' => ..., 'type' => ...].
$__flash_toast = null;
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['flash_toast']['msg'])) {
    $__flash_toast = $_SESSION['flash_toast'];
    unset($_SESSION['flash_toast']);
}
?>
<?php if (!defined('FLOATING_TOAST_INCLUDED')): ?>
<?php define('FLOATING_TOAST_INCLUDED', true); ?>
<style>
.ftc-container {
    position: fixed;
    top: 80px;
    right: 20px;
    z-index: 1200;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    pointer-events: none;
    width: min(360px, calc(100vw - 40px));
}
.ftc-toast {
    position: relative;
    overflow: hidden;
    pointer-events: auto;
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #64748b;
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.14);
    padding: 0.7rem 0.85rem;
    font-size: 0.82rem;
    color: #1e293b;
    opacity: 0;
    transform: translateX(40px);
    transition: opacity 0.25s ease, transform 0.25s ease;
}
.ftc-toast.ftc-show { opacity: 1; transform: translateX(0); }
.ftc-toast.ftc-hide { opacity: 0; transform: translateX(40px); }
.ftc-badge {
    flex-shrink: 0;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 700;
    color: #ffffff;
    line-height: 1;
    background: #64748b;
}
.ftc-toast .ftc-body { flex: 1; min-width: 0; word-break: break-word; }
.ftc-toast .ftc-title { display: block; font-weight: 700; margin-bottom: 1px; }
.ftc-toast .ftc-action {
    margin-top: 0.4rem;
    background: none;
    border: 1px solid currentColor;
    border-radius: 6px;
    padding: 0.15rem 0.6rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: #1d4ed8;
    cursor: pointer;
}
.ftc-toast .ftc-action:hover { background: rgba(59, 130, 246, 0.08); }
.ftc-toast .ftc-close {
    background: none;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    font-size: 1rem;
    line-height: 1;
    padding: 0;
}
.ftc-toast .ftc-close:hover { color: #475569; }
.ftc-progress {
    position: absolute;
    left: 0;
    bottom: 0;
    height: 3px;
    width: 100%;
    background: currentColor;
    opacity: 0.25;
    transform-origin: left;
}
.ftc-toast.ftc-success { border-left-color: #10b981; }
.ftc-toast.ftc-success .ftc-badge, .ftc-toast.ftc-success .ftc-progress { background: #10b981; }
.ftc-toast.ftc-error, .ftc-toast.ftc-danger { border-left-color: #ef4444; }
.ftc-toast.ftc-error .ftc-badge, .ftc-toast.ftc-danger .ftc-badge,
.ftc-toast.ftc-error .ftc-progress, .ftc-toast.ftc-danger .ftc-progress { background: #ef4444; }
.ftc-toast.ftc-warning { border-left-color: #EAB308; }
.ftc-toast.ftc-warning .ftc-badge, .ftc-toast.ftc-warning .ftc-progress { background: #EAB308; }
.ftc-toast.ftc-info { border-left-color: #3b82f6; }
.ftc-toast.ftc-info .ftc-badge, .ftc-toast.ftc-info .ftc-progress { background: #3b82f6; }
.ftc-toast.ftc-tip { border-left-color: #8b5cf6; background: #faf5ff; }
.ftc-toast.ftc-tip .ftc-badge, .ftc-toast.ftc-tip .ftc-progress { background: #8b5cf6; }
.ftc-toast.ftc-loading { border-left-color: #3b82f6; }
.ftc-toast.ftc-loading .ftc-badge {
    background: transparent;
    border: 2px solid #bfdbfe;
    border-top-color: #3b82f6;
    animation: ftc-spin 0.8s linear infinite;
}
@keyframes ftc-spin { to { transform: rotate(360deg); } }

.ftc-offline {
    position: fixed;
    left: 50%;
    bottom: 20px;
    z-index: 1210;
    transform: translate(-50%, 120%);
    opacity: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    max-width: calc(100vw - 32px);
    padding: 0.55rem 1rem;
    border-radius: 999px;
    background: #1e293b;
    color: #f8fafc;
    font-size: 0.82rem;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
    transition: transform 0.25s ease, opacity 0.25s ease;
    pointer-events: none;
}
.ftc-offline.ftc-show { transform: translate(-50%, 0); opacity: 1; }
.ftc-offline::before {
    content: '';
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #f59e0b;
    flex-shrink: 0;
}

.ftc-btn-loading { pointer-events: none; opacity: 0.75; cursor: progress; }
.ftc-btn-spinner {
    display: inline-block;
    width: 0.9em;
    height: 0.9em;
    margin-right: 0.45em;
    vertical-align: -0.1em;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: ftc-spin 0.7s linear infinite;
}

html[data-theme="dark"] .ftc-toast { background: #1e293b; border-color: #334155; color: #e2e8f0; }
html[data-theme="dark"] .ftc-toast.ftc-tip { background: #2e1065; }
html[data-theme="dark"] .ftc-toast .ftc-action { color: #93c5fd; }
html[data-theme="dark"] .ftc-toast .ftc-close:hover { color: #e2e8f0; }
html[data-theme="dark"] .ftc-offline { background: #f8fafc; color: #0f172a; }

@media (max-width: 576px) {
    .ftc-container { top: 70px; right: 12px; width: calc(100vw - 24px); }
}
@media (prefers-reduced-motion: reduce) {
    .ftc-toast, .ftc-offline { transition: none; }
    .ftc-toast.ftc-loading .ftc-badge, .ftc-btn-spinner { animation-duration: 2.4s; }
}
</style>
<script>
(function () {
    if (window.__ftcInstalled) return;
    window.__ftcInstalled = true;

    var GLYPHS = { success: '\u2713', error: '\u2715', danger: '\u2715', warning: '!', info: 'i', tip: '\u2605', loading: '' };
    var MAX_VISIBLE = 5;
    var byId = {};

    function getContainer() {
        var c = document.getElementById('floatingToastContainer');
        if (!c) {
            c = document.createElement('div');
            c.id = 'floatingToastContainer';
            c.className = 'ftc-container';
            c.setAttribute('aria-live', 'polite');
            (document.body || document.documentElement).appendChild(c);
        }
        return c;
    }

    function cleanMessage(message) {
        // Strip leading status emojis — the toast badge already shows the glyph
        return String(message == null ? '' : message).replace(/^[\u2705\u274C\u26A0\u2139\u{1F512}\u{1F4A1}\u{1F6A8}\u{1F514}\u{1F389}\u{26D4}\u{2757}\u{2753}\uFE0F\u00A0\s]+/u, '');
    }

    function defaultDuration(type) {
        if (type === 'loading') return 0;
        if (type === 'error' || type === 'danger') return 6000;
        if (type === 'tip') return 8000;
        return 4000;
    }

    // showToast(message, type, durationOrOptions)
    //   options: { title, duration (0 = stay), id (replace a toast with the same id),
    //              action: { label, onClick } }
    // Returns { update(message, type, options), dismiss() }.
    window.showToast = function (message, type, opts) {
        opts = (opts && typeof opts === 'object') ? opts : { duration: opts };
        type = GLYPHS.hasOwnProperty(type) ? type : 'info';

        if (opts.id && byId[opts.id]) {
            byId[opts.id].update(message, type, opts);
            return byId[opts.id];
        }

        var container = getContainer();
        while (container.children.length >= MAX_VISIBLE) container.firstChild.remove();

        var toast = document.createElement('div');
        var badge = document.createElement('span');
        badge.className = 'ftc-badge';
        badge.setAttribute('aria-hidden', 'true');
        var body = document.createElement('div');
        body.className = 'ftc-body';
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'ftc-close';
        close.textContent = '\u00d7';
        close.setAttribute('aria-label', 'Dismiss notification');
        var progress = document.createElement('span');
        progress.className = 'ftc-progress';
        toast.appendChild(badge);
        toast.appendChild(body);
        toast.appendChild(close);
        toast.appendChild(progress);
        container.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('ftc-show'); });

        var timer = null, remaining = 0, startedAt = 0, dismissed = false, hovering = false;
        var handle = {};

        function stopTimer() {
            if (timer) { clearTimeout(timer); timer = null; remaining -= Date.now() - startedAt; }
            progress.style.transition = 'none';
            progress.style.transform = 'scaleX(' + Math.max(0, remaining / handle.total || 0) + ')';
        }
        function startTimer() {
            if (!remaining || remaining <= 0 || hovering) return;
            startedAt = Date.now();
            timer = setTimeout(handle.dismiss, remaining);
            progress.style.transition = 'transform ' + remaining + 'ms linear';
            requestAnimationFrame(function () { progress.style.transform = 'scaleX(0)'; });
        }

        handle.dismiss = function () {
            if (dismissed) return;
            dismissed = true;
            if (timer) clearTimeout(timer);
            if (opts.id && byId[opts.id] === handle) delete byId[opts.id];
            toast.classList.remove('ftc-show');
            toast.classList.add('ftc-hide');
            setTimeout(function () { toast.remove(); }, 300);
        };

        handle.update = function (newMessage, newType, newOpts) {
            newOpts = (newOpts && typeof newOpts === 'object') ? newOpts : { duration: newOpts };
            newType = GLYPHS.hasOwnProperty(newType) ? newType : type;
            type = newType;
            toast.className = 'ftc-toast ftc-' + type + (toast.classList.contains('ftc-show') ? ' ftc-show' : '');
            var urgent = type === 'error' || type === 'danger';
            toast.setAttribute('role', urgent ? 'alert' : 'status');
            badge.textContent = GLYPHS[type];
            body.textContent = '';
            if (newOpts.title) {
                var t = document.createElement('strong');
                t.className = 'ftc-title';
                t.textContent = newOpts.title;
                body.appendChild(t);
            }
            var m = document.createElement('div');
            m.className = 'ftc-msg';
            m.textContent = cleanMessage(newMessage);
            body.appendChild(m);
            if (newOpts.action && newOpts.action.label) {
                var a = document.createElement('button');
                a.type = 'button';
                a.className = 'ftc-action';
                a.textContent = newOpts.action.label;
                a.addEventListener('click', function () {
                    handle.dismiss();
                    if (typeof newOpts.action.onClick === 'function') newOpts.action.onClick();
                });
                body.appendChild(a);
            }
            if (timer) { clearTimeout(timer); timer = null; }
            var d = newOpts.duration;
            if (d == null) d = defaultDuration(type);
            if (newOpts.action && newOpts.duration == null && d) d = Math.max(d, 8000);
            handle.total = remaining = d;
            progress.style.display = d ? '' : 'none';
            progress.style.transition = 'none';
            progress.style.transform = 'scaleX(1)';
            startTimer();
            return handle;
        };

        toast.addEventListener('mouseenter', function () { hovering = true; stopTimer(); });
        toast.addEventListener('mouseleave', function () { hovering = false; startTimer(); });
        toast.addEventListener('focusin', function () { hovering = true; stopTimer(); });
        toast.addEventListener('focusout', function () { hovering = false; startTimer(); });
        close.addEventListener('click', handle.dismiss);

        if (opts.id) byId[opts.id] = handle;
        handle.update(message, type, opts);
        return handle;
    };
    window.dismissToast = function (id) { if (byId[id]) byId[id].dismiss(); };

    // --- Offline banner + "back online" toast ---
    function setupNetworkStatus() {
        var banner = document.createElement('div');
        banner.className = 'ftc-offline';
        banner.setAttribute('role', 'status');
        banner.textContent = 'You\u2019re offline. Changes won\u2019t be saved until your connection is back.';
        document.body.appendChild(banner);
        var wasOffline = false;
        function sync() {
            var offline = navigator.onLine === false;
            banner.classList.toggle('ftc-show', offline);
            if (offline) wasOffline = true;
            else if (wasOffline) {
                wasOffline = false;
                window.dismissToast('ftc-server');
                window.showToast('You\u2019re back online.', 'success', { id: 'ftc-network', duration: 3000 });
            }
        }
        window.addEventListener('online', sync);
        window.addEventListener('offline', sync);
        sync();
    }

    // --- "Can't reach the server" toast for failed same-origin requests ---
    if (window.fetch && !window.fetch.__ftcWrapped) {
        var origFetch = window.fetch;
        var wrapped = function (input, init) {
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            var sameOrigin = true;
            try { sameOrigin = new URL(url, location.href).origin === location.origin; } catch (e) {}
            var p = origFetch.apply(this, arguments);
            if (!sameOrigin) return p;
            return p.then(function (res) {
                var type = (res.headers.get('Content-Type') || '').toLowerCase();
                // JSON errors are handled by the page; a crash page (HTML 5xx) isn't.
                if (res.status >= 500 && type.indexOf('json') === -1) {
                    window.showToast('Something went wrong on our side. Please try again in a moment.', 'error', {
                        id: 'ftc-server', title: 'Server error'
                    });
                } else {
                    window.dismissToast('ftc-server');
                }
                return res;
            }, function (err) {
                var aborted = err && err.name === 'AbortError';
                if (!aborted && navigator.onLine !== false) {
                    window.showToast('Some information may be out of date.', 'warning', {
                        id: 'ftc-server', title: 'Can\u2019t reach the server', duration: 0,
                        action: { label: 'Reload page', onClick: function () { location.reload(); } }
                    });
                }
                throw err;
            });
        };
        wrapped.__ftcWrapped = true;
        window.fetch = wrapped;
    }

    // --- Submit buttons: spinner + no double submit while a POST form navigates ---
    function submitterFor(form, e) {
        var s = e.submitter;
        if (s && s.form === form) return s;
        return form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
    }
    function resetButton(btn) {
        if (!btn || !btn.classList.contains('ftc-btn-loading')) return;
        btn.classList.remove('ftc-btn-loading');
        btn.removeAttribute('aria-busy');
        var sp = btn.querySelector('.ftc-btn-spinner');
        if (sp) sp.remove();
    }
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        if (form.dataset.ftcSubmitting === '1') { e.preventDefault(); return; }
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'post' || form.hasAttribute('data-no-loading')) return;
        if ((form.getAttribute('target') || '_self') !== '_self') return;
        var btn = submitterFor(form, e);
        // Wait for every other submit handler; if one cancels, leave the form alone.
        setTimeout(function () {
            if (e.defaultPrevented) return;
            form.dataset.ftcSubmitting = '1';
            if (btn && btn.tagName === 'BUTTON' && !btn.disabled && !btn.classList.contains('ftc-btn-loading')) {
                btn.classList.add('ftc-btn-loading');
                btn.setAttribute('aria-busy', 'true');
                var sp = document.createElement('span');
                sp.className = 'ftc-btn-spinner';
                sp.setAttribute('aria-hidden', 'true');
                btn.insertBefore(sp, btn.firstChild);
            }
            // Downloads and other responses that don't leave the page.
            setTimeout(function () { delete form.dataset.ftcSubmitting; resetButton(btn); }, 15000);
        }, 0);
    }, true);
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('form[data-ftc-submitting]').forEach(function (f) { delete f.dataset.ftcSubmitting; });
        document.querySelectorAll('.ftc-btn-loading').forEach(resetButton);
    });

    // --- Convert rendered Bootstrap alerts into toasts ---
    function shouldConvert(el) {
        if (!el || !el.classList || !el.classList.contains('alert')) return false;
        if (el.hasAttribute('data-no-toast')) return false;
        if (el.closest('.modal')) return false;
        return el.classList.contains('alert-dismissible') || !!el.querySelector('.btn-close');
    }

    function convertAlert(el) {
        if (el.dataset.ftcDone) return;
        el.dataset.ftcDone = '1';
        var type = 'info';
        if (el.classList.contains('alert-success')) type = 'success';
        else if (el.classList.contains('alert-danger')) type = 'error';
        else if (el.classList.contains('alert-warning')) type = 'warning';
        var clone = el.cloneNode(true);
        var btn = clone.querySelector('.btn-close');
        if (btn) btn.remove();
        var text = (clone.textContent || '').replace(/\s+/g, ' ').trim();
        el.remove();
        if (text) window.showToast(text, type);
    }

    function scan(root) {
        if (!root.querySelectorAll) return;
        var nodes = root.querySelectorAll('.alert');
        for (var i = 0; i < nodes.length; i++) {
            if (shouldConvert(nodes[i])) convertAlert(nodes[i]);
        }
    }

    function startWatching() {
        scan(document);
        if (!document.body) return;
        var mo = new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                for (var i = 0; i < m.addedNodes.length; i++) {
                    var n = m.addedNodes[i];
                    if (n.nodeType !== 1) continue;
                    if (shouldConvert(n)) { convertAlert(n); continue; }
                    scan(n);
                }
            });
        });
        mo.observe(document.body, { childList: true, subtree: true });
    }

    // --- Shim Swal.fire: plain notifications become toasts ---
    function shimSwal() {
        if (!window.Swal || window.Swal.__ftcShimmed || !window.Swal.fire) return;
        var origFire = window.Swal.fire.bind(window.Swal);
        window.Swal.__ftcShimmed = true;
        window.Swal.fire = function () {
            var args = arguments;
            var opts = args[0];
            if (typeof opts === 'string') {
                opts = { title: args[0], text: args[1], icon: args[2] };
            }
            opts = opts || {};
            var interactive = opts.showCancelButton || opts.showDenyButton ||
                              opts.input || opts.preConfirm || opts.toast === true ||
                              (opts.showConfirmButton === false && !opts.timer);
            if (interactive) {
                return origFire.apply(window.Swal, args);
            }
            var typeMap = { success: 'success', error: 'error', warning: 'warning', info: 'info', question: 'info' };
            var type = typeMap[opts.icon] || 'info';
            var text = '';
            if (opts.html) {
                var tmp = document.createElement('div');
                tmp.innerHTML = opts.html;
                text = (tmp.textContent || '').trim();
            }
            if (!text) {
                text = (opts.title && opts.text) ? (opts.title + ' \u2014 ' + opts.text)
                     : (opts.text || opts.title || 'Done');
            }
            window.showToast(text, type);
            return Promise.resolve({ isConfirmed: true, isDenied: false, isDismissed: false, value: true });
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { startWatching(); shimSwal(); setupNetworkStatus(); });
    } else {
        startWatching();
        shimSwal();
        setupNetworkStatus();
    }
    // Retry briefly in case sweetalert2 loads late (async/defer/per-page)
    var tries = 0;
    var t = setInterval(function () {
        if (window.Swal || ++tries > 20) { shimSwal(); clearInterval(t); }
    }, 250);
})();
</script>
<?php endif; ?>
<?php if ($__toast_msg !== ''): ?>
<script>
(function () {
    var fire = function () {
        if (window.showToast) {
            window.showToast(
                <?= json_encode($__toast_msg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                <?= json_encode($__toast_type) ?>
            );
        }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fire);
    } else {
        fire();
    }
})();
</script>
<?php endif; ?>
<?php if ($__flash_toast): ?>
<script>
(function () {
    var fire = function () {
        if (window.showToast) {
            window.showToast(
                <?= json_encode((string)$__flash_toast['msg'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                <?= json_encode((string)($__flash_toast['type'] ?? 'success')) ?>
            );
        }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fire);
    } else {
        fire();
    }
})();
</script>
<?php endif; ?>
