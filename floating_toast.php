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
    max-width: min(340px, calc(100vw - 40px));
}
.ftc-toast {
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
}
.ftc-toast .ftc-msg { flex: 1; word-break: break-word; }
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
.ftc-toast.ftc-success { border-left-color: #10b981; }
.ftc-toast.ftc-success .ftc-badge { background: #10b981; }
.ftc-toast.ftc-error, .ftc-toast.ftc-danger { border-left-color: #ef4444; }
.ftc-toast.ftc-error .ftc-badge, .ftc-toast.ftc-danger .ftc-badge { background: #ef4444; }
.ftc-toast.ftc-warning { border-left-color: #EAB308; }
.ftc-toast.ftc-warning .ftc-badge { background: #EAB308; }
.ftc-toast.ftc-info { border-left-color: #3b82f6; }
.ftc-toast.ftc-info .ftc-badge { background: #3b82f6; }
</style>
<script>
(function () {
    if (window.__ftcInstalled) return;
    window.__ftcInstalled = true;

    var GLYPHS = { success: '\u2713', error: '\u2715', danger: '\u2715', warning: '!', info: 'i' };

    function getContainer() {
        var c = document.getElementById('floatingToastContainer');
        if (!c) {
            c = document.createElement('div');
            c.id = 'floatingToastContainer';
            c.className = 'ftc-container';
            (document.body || document.documentElement).appendChild(c);
        }
        return c;
    }

    window.showToast = function (message, type, duration) {
        type = GLYPHS[type] ? type : 'info';
        duration = duration || ((type === 'error' || type === 'danger') ? 6000 : 4000);
        // Strip leading status emojis — the toast badge already shows the glyph
        message = String(message).replace(/^[\u2705\u274C\u26A0\u2139\u{1F512}\u{1F4A1}\u{1F6A8}\u{1F514}\u{1F389}\u{26D4}\u{2757}\u{2753}\uFE0F\u00A0\s]+/u, '');
        var toast = document.createElement('div');
        toast.className = 'ftc-toast ftc-' + type;
        var badge = document.createElement('span');
        badge.className = 'ftc-badge';
        badge.textContent = GLYPHS[type];
        var msg = document.createElement('div');
        msg.className = 'ftc-msg';
        msg.textContent = message;
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'ftc-close';
        close.textContent = '\u00d7';
        close.setAttribute('aria-label', 'Close');
        toast.appendChild(badge);
        toast.appendChild(msg);
        toast.appendChild(close);
        getContainer().appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('ftc-show'); });
        var dismissed = false;
        var dismiss = function () {
            if (dismissed) return;
            dismissed = true;
            toast.classList.remove('ftc-show');
            toast.classList.add('ftc-hide');
            setTimeout(function () { toast.remove(); }, 300);
        };
        close.addEventListener('click', dismiss);
        setTimeout(dismiss, duration);
    };

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
        document.addEventListener('DOMContentLoaded', function () { startWatching(); shimSwal(); });
    } else {
        startWatching();
        shimSwal();
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