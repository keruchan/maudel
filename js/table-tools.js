/**
 * ============================================================
 * File     : js/table-tools.js
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : One reusable search + filter + sort + pagination enhancer for
 *            data tables across SKed.
 *
 * PHP keeps fetching and rendering rows exactly as it does today; this helper
 * wraps the already-rendered table. Existing page filters remain opt-in via
 * the `filters` option. Sorting is enabled by default for every header cell:
 * first click sorts ascending, second click sorts descending.
 * ============================================================ */
(function (global) {
    'use strict';

    function normalizeText(value) {
        return (value || '').replace(/\s+/g, ' ').trim();
    }

    function selectedControlText(control) {
        if (!control) { return ''; }
        if (control.tagName === 'SELECT') {
            return control.selectedOptions && control.selectedOptions.length
                ? Array.prototype.map.call(control.selectedOptions, function (opt) { return opt.textContent; }).join(' ')
                : '';
        }
        if ((control.type === 'checkbox' || control.type === 'radio') && !control.checked) {
            return '';
        }
        return control.value || '';
    }

    function formControlText(cell) {
        return normalizeText(Array.prototype.map.call(
            cell.querySelectorAll('input, select, textarea'),
            selectedControlText
        ).join(' '));
    }

    function cellTextValue(cell) {
        if (!cell) { return ''; }
        var explicit = cell.getAttribute('data-tt-value');
        if (explicit !== null) { return normalizeText(explicit); }
        var text = normalizeText(cell.textContent);
        return text !== '' ? text : formControlText(cell);
    }

    function rowTextValue(row) {
        return normalizeText(Array.prototype.map.call(row.children, cellTextValue).join(' '));
    }

    function cellFilterValue(cell) {
        if (!cell) { return ''; }
        var explicit = cell.getAttribute('data-tt-value');
        if (explicit !== null) { return normalizeText(explicit); }
        var badge = cell.querySelector('.badge');
        if (badge) { return normalizeText(badge.textContent); }
        return cellTextValue(cell);
    }

    function cellSortValue(cell) {
        if (!cell) { return ''; }
        var explicit = cell.getAttribute('data-tt-sort');
        if (explicit !== null) { return normalizeText(explicit); }
        return cellTextValue(cell);
    }

    function parseComparable(value) {
        var text = normalizeText(value);
        if (text === '') {
            return { type: 'blank', value: '' };
        }

        var date = Date.parse(text);
        if (!isNaN(date) && /[a-zA-Z]{3,}|\d{4}-\d{1,2}-\d{1,2}|\d{1,2}\/\d{1,2}\/\d{2,4}/.test(text)) {
            return { type: 'date', value: date };
        }

        var numeric = text.replace(/[,%]/g, '').replace(/^[^\d+\-.]+\s*/, '');
        if (/^[-+]?\d+(\.\d+)?$/.test(numeric)) {
            return { type: 'number', value: parseFloat(numeric) };
        }

        var leadingNumber = text.match(/^[-+]?\d[\d,]*(\.\d+)?/);
        if (leadingNumber) {
            return { type: 'number', value: parseFloat(leadingNumber[0].replace(/,/g, '')) };
        }

        return { type: 'text', value: text.toLowerCase() };
    }

    function compareValues(a, b, direction) {
        var av = parseComparable(a);
        var bv = parseComparable(b);

        if (av.type === 'blank' && bv.type === 'blank') { return 0; }
        if (av.type === 'blank') { return 1; }
        if (bv.type === 'blank') { return -1; }

        var result;
        if (av.type === bv.type && av.type !== 'text') {
            result = av.value === bv.value ? 0 : (av.value < bv.value ? -1 : 1);
        } else {
            result = String(av.value).localeCompare(String(bv.value), undefined, { numeric: true, sensitivity: 'base' });
        }

        return direction === 'desc' ? result * -1 : result;
    }

    function SkedTableTools(tableSelector, options) {
        this.table = typeof tableSelector === 'string' ? document.querySelector(tableSelector) : tableSelector;
        if (!this.table || !this.table.tBodies.length) { return; }
        if (this.table.getAttribute('data-tt-enhanced') === '1') { return; }

        this.opts = Object.assign({
            pageSize: 10,
            search: true,
            searchPlaceholder: 'Search...',
            filters: [],
            sortable: true,
            emptyMessage: 'No matching rows.'
        }, options || {});

        this.page = 1;
        this.sortState = { column: -1, direction: 'asc' };
        this.filterSelects = [];
        this.allRows = Array.prototype.slice.call(this.table.tBodies[0].rows);
        this.allRows.forEach(function (row, index) {
            row.setAttribute('data-tt-original-index', String(index));
        });
        if (!this.allRows.length) { return; }

        this.table.setAttribute('data-tt-enhanced', '1');
        this.buildSorting();
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
            if (normalizeText(headRow.cells[i].textContent).toLowerCase() === normalizeText(label).toLowerCase()) {
                return i;
            }
        }
        return -1;
    };

    SkedTableTools.prototype.buildSorting = function () {
        var self = this;
        if (!this.opts.sortable || !this.table.tHead || !this.table.tHead.rows.length) { return; }

        Array.prototype.forEach.call(this.table.tHead.rows[0].cells, function (th, column) {
            if (th.hasAttribute('data-tt-nosort')) { return; }
            th.classList.add('tt-sortable');
            th.tabIndex = 0;
            th.setAttribute('aria-sort', 'none');
            th.setAttribute('title', 'Sort by ' + normalizeText(th.textContent));

            var indicator = document.createElement('span');
            indicator.className = 'tt-sort-indicator';
            indicator.setAttribute('aria-hidden', 'true');
            th.appendChild(indicator);

            function activate() {
                self.toggleSort(column);
            }

            th.addEventListener('click', activate);
            th.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    activate();
                }
            });
        });
    };

    SkedTableTools.prototype.toggleSort = function (column) {
        if (this.sortState.column === column) {
            this.sortState.direction = this.sortState.direction === 'asc' ? 'desc' : 'asc';
        } else {
            this.sortState.column = column;
            this.sortState.direction = 'asc';
        }
        this.page = 1;
        this.updateSortHeaders();
        this.applyFilters();
    };

    SkedTableTools.prototype.updateSortHeaders = function () {
        var self = this;
        if (!this.table.tHead || !this.table.tHead.rows.length) { return; }
        Array.prototype.forEach.call(this.table.tHead.rows[0].cells, function (th, column) {
            if (!th.classList.contains('tt-sortable')) { return; }
            var active = self.sortState.column === column;
            th.classList.toggle('tt-sort-active', active);
            th.classList.toggle('tt-sort-desc', active && self.sortState.direction === 'desc');
            th.setAttribute('aria-sort', active ? (self.sortState.direction === 'asc' ? 'ascending' : 'descending') : 'none');
        });
    };

    SkedTableTools.prototype.applySort = function () {
        var self = this;
        if (this.sortState.column < 0) { return; }
        this.filtered.sort(function (a, b) {
            var result = compareValues(
                cellSortValue(a.children[self.sortState.column]),
                cellSortValue(b.children[self.sortState.column]),
                self.sortState.direction
            );
            if (result !== 0) { return result; }
            return parseInt(a.getAttribute('data-tt-original-index') || '0', 10)
                - parseInt(b.getAttribute('data-tt-original-index') || '0', 10);
        });
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
            if (col === -1) { return; }

            var values = {};
            self.allRows.forEach(function (tr) {
                var v = cellFilterValue(tr.children[col]);
                if (v !== '') { values[v] = true; }
            });
            if (!Object.keys(values).length) { return; }

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
            if (q && rowTextValue(tr).toLowerCase().indexOf(q) === -1) { return false; }
            for (var i = 0; i < active.length; i++) {
                if (cellFilterValue(tr.children[active[i].column]) !== active[i].value) { return false; }
            }
            return true;
        });

        this.applySort();
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

        var body = this.table.tBodies[0];
        this.allRows.forEach(function (tr) { tr.style.display = 'none'; });
        var start = (this.page - 1) * pageSize;
        this.filtered.forEach(function (tr) { body.appendChild(tr); });
        this.filtered.slice(start, start + pageSize).forEach(function (tr) {
            tr.style.display = '';
            body.appendChild(tr);
        });

        this.toggleEmptyRow(total === 0);

        if (this.countEl) {
            this.countEl.textContent = total === 0
                ? '0 results'
                : 'Showing ' + (start + 1) + '-' + Math.min(start + pageSize, total) + ' of ' + total;
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
                span.textContent = '...';
                self.pager.appendChild(span);
            } else {
                self.pager.appendChild(makeBtn(String(p), p, { active: p === self.page }));
            }
        });
        this.pager.appendChild(makeBtn('<i class="bi bi-chevron-right"></i>', this.page + 1, { disabled: this.page >= totalPages }));
    };

    function autoEnhanceTables(root) {
        Array.prototype.forEach.call((root || document).querySelectorAll('table'), function (table) {
            if (table.closest('[data-tt-skip]')) { return; }
            if (!table.tHead || !table.tBodies.length) { return; }
            var opts = {};
            var pageSize = parseInt(table.getAttribute('data-tt-page-size') || '', 10);
            if (!isNaN(pageSize) && pageSize > 0) { opts.pageSize = pageSize; }
            new SkedTableTools(table, opts);
        });
    }

    global.SkedTableTools = SkedTableTools;
    global.SkedTableToolsAuto = autoEnhanceTables;
})(window);
