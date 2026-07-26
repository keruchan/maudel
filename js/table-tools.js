/**
 * ============================================================
 * File     : js/table-tools.js
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : One reusable "search + filter + pagination" enhancer applied to
 *            every data table across the system. Operates purely on the
 *            already-rendered DOM — PHP keeps fetching and printing rows
 *            exactly as it does today; this just wraps the result.
 *
 * Deliberately client-side, matching this codebase's vanilla-PHP/no-build
 * philosophy: no per-page query-string plumbing, no server round trip per
 * keystroke. SKed's tables are barangay/municipality-scale (tens to a few
 * hundred rows), not the kind of volume that needs server-side pagination.
 *
 * Usage — one line per table, right before </body>:
 *
 *   new SkedTableTools('#membersTable', {
 *       pageSize: 10,
 *       filters: [{ label: 'Status' }],     // matched against <th> text
 *   });
 *
 * Filter columns are found by matching a <thead> <th>'s text against
 * `label` (case-insensitive) rather than a hardcoded index, so a page can't
 * silently filter the wrong column if a table gains/loses a column later.
 * If a cell contains a `.badge` (the standard status/type/scope pattern
 * throughout this app), the badge's own text is used as that row's filter
 * value — not the cell's full text — so a status cell that ALSO carries a
 * secondary note (e.g. sk/events.php's "Needs closing out" hint) still
 * filters on just "Published"/"Closed"/etc., not the concatenation of both.
 *
 * The CSS classes here (.tt-toolbar, .tt-search, .tt-filter, .tt-count,
 * .tt-footer, .tt-pager, .tt-ellipsis) already existed in dashboard.css,
 * ported from the source design system ahead of this feature being wired up
 * — same situation the notification bell and analytics chart classes were
 * in before those got built out.
 * ============================================================ */
(function (global) {
    'use strict';

    function cellFilterValue(cell) {
        if (!cell) { return ''; }
        var explicit = cell.getAttribute('data-tt-value');
        if (explicit !== null) { return explicit.trim(); }
        var badge = cell.querySelector('.badge');
        if (badge) { return badge.textContent.trim(); }
        return cell.textContent.trim();
    }

    function SkedTableTools(tableSelector, options) {
        this.table = typeof tableSelector === 'string' ? document.querySelector(tableSelector) : tableSelector;
        if (!this.table || !this.table.tBodies.length) { return; }

        this.opts = Object.assign({
            pageSize: 10,
            search: true,
            searchPlaceholder: 'Search…',
            filters: [],
            emptyMessage: 'No matching rows.'
        }, options || {});

        this.page = 1;
        this.filterSelects = [];
        this.allRows = Array.prototype.slice.call(this.table.tBodies[0].rows);
        if (!this.allRows.length) { return; } // nothing to enhance — page already shows its own "no X yet" state

        this.buildToolbar();
        this.buildFooter();
        this.applyFilters();
    }

    SkedTableTools.prototype.anchorEl = function () {
        return this.table.closest('.table-responsive') || this.table;
    };

    SkedTableTools.prototype.resolveColumnIndex = function (label) {
        var headRow = this.table.tHead ? this.table.tHead.rows[0] : null;
        if (!headRow) { return -1; }
        for (var i = 0; i < headRow.cells.length; i++) {
            if (headRow.cells[i].textContent.trim().toLowerCase() === label.trim().toLowerCase()) { return i; }
        }
        return -1;
    };

    SkedTableTools.prototype.buildToolbar = function () {
        var self = this;
        var toolbar = document.createElement('div');
        toolbar.className = 'tt-toolbar';

        if (this.opts.search) {
            var wrap = document.createElement('div');
            wrap.className = 'tt-search';
            var icon = document.createElement('i');
            icon.className = 'bi bi-search';
            var input = document.createElement('input');
            input.type = 'search';
            input.className = 'form-control form-control-sm';
            input.placeholder = this.opts.searchPlaceholder;
            input.setAttribute('aria-label', 'Search table');
            input.addEventListener('input', function () { self.page = 1; self.applyFilters(); });
            wrap.appendChild(icon);
            wrap.appendChild(input);
            toolbar.appendChild(wrap);
            this.searchInput = input;
        }

        this.opts.filters.forEach(function (f) {
            var col = self.resolveColumnIndex(f.label);
            if (col === -1) { return; } // header not found on this table — skip rather than break

            var values = {};
            self.allRows.forEach(function (tr) {
                var v = cellFilterValue(tr.children[col]);
                if (v !== '') { values[v] = true; }
            });
            if (!Object.keys(values).length) { return; } // nothing to filter by

            var select = document.createElement('select');
            select.className = 'form-select form-select-sm tt-filter';
            select.setAttribute('aria-label', 'Filter by ' + f.label);
            var allOpt = document.createElement('option');
            allOpt.value = '';
            allOpt.textContent = 'All ' + f.label;
            select.appendChild(allOpt);
            Object.keys(values).sort().forEach(function (v) {
                var opt = document.createElement('option');
                opt.value = v;
                opt.textContent = v;
                select.appendChild(opt);
            });
            select.addEventListener('change', function () { self.page = 1; self.applyFilters(); });
            toolbar.appendChild(select);
            self.filterSelects.push({ select: select, column: col });
        });

        var count = document.createElement('span');
        count.className = 'tt-count';
        toolbar.appendChild(count);
        this.countEl = count;

        var anchor = this.anchorEl();
        anchor.parentNode.insertBefore(toolbar, anchor);
    };

    SkedTableTools.prototype.buildFooter = function () {
        var footer = document.createElement('div');
        footer.className = 'tt-footer';
        var pager = document.createElement('div');
        pager.className = 'tt-pager';
        footer.appendChild(pager);
        var anchor = this.anchorEl();
        anchor.parentNode.insertBefore(footer, anchor.nextSibling);
        this.pager = pager;
    };

    SkedTableTools.prototype.applyFilters = function () {
        var q = this.searchInput ? this.searchInput.value.trim().toLowerCase() : '';
        var active = this.filterSelects
            .map(function (f) { return { column: f.column, value: f.select.value }; })
            .filter(function (f) { return f.value !== ''; });

        this.filtered = this.allRows.filter(function (tr) {
            if (q && tr.textContent.toLowerCase().indexOf(q) === -1) { return false; }
            for (var i = 0; i < active.length; i++) {
                if (cellFilterValue(tr.children[active[i].column]) !== active[i].value) { return false; }
            }
            return true;
        });

        this.renderPage();
    };

    SkedTableTools.prototype.toggleEmptyRow = function (show) {
        if (!this.emptyRow) {
            var headRow = this.table.tHead ? this.table.tHead.rows[0] : null;
            var colCount = headRow ? headRow.cells.length : (this.allRows[0] ? this.allRows[0].children.length : 1);
            var tr = document.createElement('tr');
            tr.className = 'tt-empty-row';
            var td = document.createElement('td');
            td.colSpan = colCount;
            td.className = 'text-center text-secondary py-4';
            td.textContent = this.opts.emptyMessage;
            tr.appendChild(td);
            this.emptyRow = tr;
        }
        if (show) {
            if (!this.emptyRow.parentNode) { this.table.tBodies[0].appendChild(this.emptyRow); }
        } else if (this.emptyRow.parentNode) {
            this.emptyRow.parentNode.removeChild(this.emptyRow);
        }
    };

    SkedTableTools.prototype.renderPage = function () {
        var pageSize = this.opts.pageSize;
        var total = this.filtered.length;
        var totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (this.page > totalPages) { this.page = totalPages; }
        if (this.page < 1) { this.page = 1; }

        this.allRows.forEach(function (tr) { tr.style.display = 'none'; });
        var start = (this.page - 1) * pageSize;
        this.filtered.slice(start, start + pageSize).forEach(function (tr) { tr.style.display = ''; });

        this.toggleEmptyRow(total === 0);

        if (this.countEl) {
            this.countEl.textContent = total === 0
                ? '0 results'
                : 'Showing ' + (start + 1) + '–' + Math.min(start + pageSize, total) + ' of ' + total;
        }

        this.renderPager(totalPages);
    };

    SkedTableTools.prototype.computePageWindow = function (current, total) {
        var delta = 1;
        var range = [];
        for (var i = 1; i <= total; i++) {
            if (i === 1 || i === total || (i >= current - delta && i <= current + delta)) { range.push(i); }
        }
        var withDots = [];
        var prev = null;
        range.forEach(function (p) {
            if (prev !== null && p - prev > 1) { withDots.push('...'); }
            withDots.push(p);
            prev = p;
        });
        return withDots;
    };

    SkedTableTools.prototype.renderPager = function (totalPages) {
        var self = this;
        this.pager.innerHTML = '';
        if (totalPages <= 1) { return; }

        function makeBtn(label, page, opts) {
            opts = opts || {};
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm ' + (opts.active ? 'btn-sked' : 'btn-outline-secondary');
            b.innerHTML = label;
            if (opts.disabled) { b.disabled = true; }
            if (!opts.disabled && !opts.active) {
                b.addEventListener('click', function () { self.page = page; self.renderPage(); });
            }
            return b;
        }

        this.pager.appendChild(makeBtn('<i class="bi bi-chevron-left"></i>', this.page - 1, { disabled: this.page <= 1 }));
        this.computePageWindow(this.page, totalPages).forEach(function (p) {
            if (p === '...') {
                var span = document.createElement('span');
                span.className = 'tt-ellipsis';
                span.textContent = '…';
                self.pager.appendChild(span);
            } else {
                self.pager.appendChild(makeBtn(String(p), p, { active: p === self.page }));
            }
        });
        this.pager.appendChild(makeBtn('<i class="bi bi-chevron-right"></i>', this.page + 1, { disabled: this.page >= totalPages }));
    };

    global.SkedTableTools = SkedTableTools;
})(window);
