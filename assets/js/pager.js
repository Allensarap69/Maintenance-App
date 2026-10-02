/**
 * Client-side pager for pages whose lists are filtered in JS
 * (manage_bookings, manage_mechanics, manage_customers_motorcycles).
 *
 * It paginates only the items the page's own filter has left visible
 * (inline style.display !== 'none'), and re-paginates automatically
 * when filters mutate the DOM — no changes to existing filter code
 * are needed.
 *
 * Usage:
 *   <script src="assets/js/pager.js" defer></script>
 *   attachPager({
 *       items:     '.mb-booking-card',   // selector of row/card elements
 *       container: '#bookingGrid',       // list wrapper (controls go after it)
 *       perPage:   12                    // optional, default 10
 *   });
 */
(function () {
    'use strict';

    var counter = 0;

    window.attachPager = function (opts) {
        var listEl = typeof opts.container === 'string'
            ? document.querySelector(opts.container)
            : opts.container;
        if (!listEl) return null;

        var perPage = opts.perPage || 10;
        var page = 1;
        var id = 'pg-' + (++counter);

        // Controls element, inserted right after the list container
        var nav = document.createElement('nav');
        nav.className = 'pager';
        nav.setAttribute('aria-label', 'Pagination');
        nav.id = id;
        listEl.insertAdjacentElement('afterend', nav);

        function items() {
            var nodes = typeof opts.items === 'function'
                ? opts.items()
                : listEl.querySelectorAll(opts.items);
            return Array.prototype.slice.call(nodes);
        }

        // Items the page's own JS filters consider visible — checks
        // computed style so both inline display and class-based hiding
        // (e.g. .hidden-match) count. pg-off is briefly lifted to get
        // the underlying filter state.
        function isFilterVisible(el) {
            var had = el.classList.contains('pg-off');
            if (had) el.classList.remove('pg-off');
            var vis = getComputedStyle(el).display !== 'none';
            if (had) el.classList.add('pg-off');
            return vis;
        }

        function candidates() {
            return items().filter(isFilterVisible);
        }

        function buildControls(totalPages, from, to, total) {
            nav.innerHTML = '';
            var info = document.createElement('span');
            info.className = 'pager-info';
            info.textContent = total === 0
                ? 'Nothing to show'
                : 'Showing ' + from + '\u2013' + to + ' of ' + total;
            nav.appendChild(info);

            var wrap = document.createElement('div');
            wrap.className = 'pager-nav';

            function btn(label, target, disabled) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'pager-btn' + (disabled ? ' pg-disabled' : '');
                b.innerHTML = label;
                if (disabled) b.disabled = true;
                else b.addEventListener('click', function () { go(target); });
                return b;
            }
            function num(n) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'pager-page' + (n === page ? ' pg-current' : '');
                b.textContent = n;
                if (n === page) b.setAttribute('aria-current', 'page');
                else b.addEventListener('click', function () { go(n); });
                return b;
            }

            wrap.appendChild(btn('&laquo; Prev', page - 1, page <= 1));

            // Windowed numbers: first, last, current +/- 2
            var nums = [1, totalPages];
            for (var i = page - 2; i <= page + 2; i++) {
                if (i >= 1 && i <= totalPages) nums.push(i);
            }
            nums = nums.filter(function (v, i, a) { return a.indexOf(v) === i; })
                       .sort(function (a, b) { return a - b; });
            var prev = 0;
            nums.forEach(function (n) {
                if (prev && n - prev > 1) {
                    var e = document.createElement('span');
                    e.className = 'pager-ellipsis';
                    e.textContent = '\u2026';
                    wrap.appendChild(e);
                }
                wrap.appendChild(num(n));
                prev = n;
            });

            wrap.appendChild(btn('Next &raquo;', page + 1, page >= totalPages));
            nav.appendChild(wrap);
        }

        function apply() {
            var cands = candidates();
            var total = cands.length;
            var totalPages = Math.max(1, Math.ceil(total / perPage));
            if (page > totalPages) page = totalPages;

            var start = (page - 1) * perPage;
            var end = start + perPage;
            var hideSet = new Set();
            cands.forEach(function (el, idx) {
                if (idx < start || idx >= end) hideSet.add(el);
            });

            // Diff-based updates: classList only mutates when the state
            // actually changes, so steady-state applies emit no
            // mutations and the observer converges instead of looping.
            items().forEach(function (el) {
                if (hideSet.has(el)) el.classList.add('pg-off');
                else el.classList.remove('pg-off');
            });

            nav.style.display = total === 0 ? 'none' : '';
            buildControls(
                totalPages,
                total === 0 ? 0 : start + 1,
                Math.min(end, total),
                total
            );
        }

        function go(n) {
            var totalPages = Math.max(1, Math.ceil(candidates().length / perPage));
            page = Math.min(Math.max(1, n), totalPages);
            apply();
        }

        // Debounced refresh whenever filters toggle inline display/classes
        var t = null;
        function schedule() {
            clearTimeout(t);
            t = setTimeout(function () { page = 1; apply(); }, 60);
        }

        var mo = new MutationObserver(function () { schedule(); });
        mo.observe(listEl, {
            attributes: true,
            attributeFilter: ['style', 'class'],
            childList: true,
            subtree: true
        });

        apply();

        return { refresh: apply, setPage: go };
    };
})();
