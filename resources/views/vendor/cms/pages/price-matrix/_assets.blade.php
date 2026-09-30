{{--
    Styles and behaviour for the price cells (_cell.blade.php), shared by the Price
    matrix page and the product editor's Variations card. Include once per page, then
    call pmGrid(form) for each form that holds cells. The form needs a `.pm-save`
    button, a `.pm-dirty-count` label and a hidden `.pm-changes` input; each row needs
    data-id (variation id) and data-cost.
--}}
<style>
    /* The CMS theme gives every input/select in a table cell min-width:110px, and a
       percentage-width .form-control inside an auto-layout table lets the browser
       hand the spare width to the wrong column. Fixed widths keep the grid tight. */
    html body #content .pm-table { width: auto; }
    html body #content .pm-table td, html body #content .pm-table th { vertical-align: middle; white-space: nowrap; }
    html body #content .pm-table tbody tr td { height: auto; }
    /* Supplier names run to 200+ characters; they wrap instead of widening the grid. */
    html body #content .pm-table td.pm-name { white-space: normal; min-width: 220px; max-width: 320px; }
    html body #content .pm-table tr.pm-group td { white-space: normal; }
    html body #content .pm-table td .pm-mode { width: 52px; min-width: 0; padding: 0 4px; }
    html body #content .pm-table td .pm-value { width: 92px; min-width: 0; }
    html body #content .pm-table td .pm-check, html body #content .pm-table td .pm-product-check { min-width: 0; width: auto; }
    .pm-cell.pm-dirty { background: rgba(255, 193, 7, .15); }
    .pm-result { font-size: 12px; min-height: 18px; }
    .pm-loss { color: #dc3545; font-weight: bold; }
    .pm-group td { background: rgba(0, 0, 0, .03); font-weight: bold; }
</style>
<script>
    (function () {
        if (window.pmGrid) return;

        function list(root, selector) {
            return Array.prototype.slice.call(root.querySelectorAll(selector));
        }

        // Same arithmetic as ProductsVariation::computeSellingPrice(); display only —
        // the server recomputes every price itself.
        function selling(cost, pct) {
            return Math.round(cost * (1 + pct / 100) * 100) / 100;
        }

        function cellState(td) {
            var mode = td.querySelector('.pm-mode').value;
            var raw = td.querySelector('.pm-value').value;
            return { mode: mode, value: mode === 'clear' ? '' : raw };
        }

        function isDirty(td) {
            var s = cellState(td);
            var origMode = td.dataset.origMode;
            var origValue = td.dataset.origValue;

            // A public cell that inherits the default renders as "—".
            if (td.dataset.column === 'public' && origMode === 'percent' && origValue === '') {
                origMode = 'clear';
            }
            if (s.mode !== origMode) return true;
            if (s.mode === 'clear') return false;
            return s.value === '' ? false : parseFloat(s.value) !== parseFloat(origValue || 'NaN');
        }

        function render(td) {
            var cost = td.closest('tr').dataset.cost;
            var s = cellState(td);
            var input = td.querySelector('.pm-value');
            var out = td.querySelector('.pm-result');
            var price = null;
            var note = '';

            input.disabled = s.mode === 'clear';
            input.placeholder = s.mode === 'percent' ? '%' : (s.mode === 'fixed' ? '$' : '');

            if (s.mode === 'clear') {
                input.value = '';
                if (td.dataset.column === 'public') {
                    if (cost !== '') {
                        price = selling(parseFloat(cost), parseFloat(td.dataset.default || '0'));
                        note = ' (default ' + td.dataset.default + '%)';
                    }
                } else {
                    note = 'pays public ' + td.dataset.publicPrice;
                }
            } else if (s.value !== '') {
                var v = parseFloat(s.value);
                price = s.mode === 'percent' ? (cost === '' ? null : selling(parseFloat(cost), v)) : v;
            }

            // Untouched cells show the price actually stored — what customers are charged
            // right now — rather than a recomputation of it.
            if (!isDirty(td) && td.dataset.origPrice !== '') {
                price = parseFloat(td.dataset.origPrice);
            }

            out.classList.toggle('pm-loss', price !== null && cost !== '' && price < parseFloat(cost));
            out.textContent = price === null ? note : '= ' + price.toFixed(2) + note;
            td.classList.toggle('pm-dirty', isDirty(td));
        }

        /**
         * Wire every price cell inside `form`. `onRefresh` (optional) runs after each
         * change, for page-specific counters. Returns { refresh, markSubmitting }.
         */
        window.pmGrid = function (form, onRefresh) {
            var save = form.querySelector('.pm-save');
            var dirtyCount = form.querySelector('.pm-dirty-count');

            function refresh() {
                var dirty = list(form, '.pm-cell').filter(isDirty);
                save.disabled = dirty.length === 0;
                dirtyCount.textContent = dirty.length ? dirty.length + ' unsaved change(s)' : 'No unsaved changes';
                if (onRefresh) onRefresh();
            }

            list(form, '.pm-cell').forEach(function (td) {
                td.querySelector('.pm-mode').addEventListener('change', function () { render(td); refresh(); });
                td.querySelector('.pm-value').addEventListener('input', function () { render(td); refresh(); });
                render(td);
            });

            form.addEventListener('submit', function (e) {
                var changes = list(form, '.pm-cell').filter(isDirty).map(function (td) {
                    var s = cellState(td);
                    return {
                        variation_id: parseInt(td.closest('tr').dataset.id, 10),
                        column: td.dataset.column,
                        mode: s.mode,
                        value: s.mode === 'clear' ? null : s.value
                    };
                }).filter(function (c) { return c.mode === 'clear' || c.value !== ''; });

                if (!changes.length) {
                    e.preventDefault();
                    return;
                }
                form.querySelector('.pm-changes').value = JSON.stringify(changes);
                save.dataset.submitting = '1';
            });

            window.addEventListener('beforeunload', function (e) {
                if (!save.disabled && !save.dataset.submitting) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            refresh();

            return {
                refresh: refresh,
                markSubmitting: function () { save.dataset.submitting = '1'; }
            };
        };
    })();
</script>
