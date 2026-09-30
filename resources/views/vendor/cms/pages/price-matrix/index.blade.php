@extends('cms::layouts/dashboard')

@php
    $prefix = config('hellotree.cms_route_prefix');
    $fmt = fn ($n) => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
@endphp

@section('breadcrumb')
    <ul class="breadcrumbs list-inline font-weight-bold text-uppercase m-0">
        <li>Price matrix</li>
    </ul>
@endsection

@section('dashboard-content')
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
    {{-- Cell selects carry `regular-select` so main.js does not turn every one of
         them into a full-width select2 widget (hundreds per page). --}}

    <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
        <form method="get" class="form-row align-items-end">
            <div class="form-group col-md-3">
                <label class="font-weight-bold">Source</label>
                <select name="source" class="form-control">
                    <option value="">All</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source }}" {{ ($filters['source'] ?? '') === $source ? 'selected' : '' }}>{{ $source }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group col-md-3">
                <label class="font-weight-bold">Subcategory</label>
                <select name="subcategory_id" class="form-control">
                    <option value="">All</option>
                    @foreach ($subcategories as $subcategory)
                        <option value="{{ $subcategory->id }}" {{ (string) ($filters['subcategory_id'] ?? '') === (string) $subcategory->id ? 'selected' : '' }}>{{ $subcategory->slug }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group col-md-2">
                <label class="font-weight-bold">Status</label>
                <select name="active" class="form-control">
                    <option value="">Any</option>
                    <option value="1" {{ ($filters['active'] ?? '') === '1' ? 'selected' : '' }}>Active</option>
                    <option value="0" {{ ($filters['active'] ?? '') === '0' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
            <div class="form-group col-md-4">
                <label class="font-weight-bold">Search</label>
                <input type="search" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="product name or slug">
            </div>
            <div class="form-group col-12">
                <button type="submit" class="btn btn-secondary btn-sm">Apply filters</button>
                <a href="{{ url($prefix . '/price-matrix') }}" class="btn btn-link btn-sm">Reset</a>
            </div>
        </form>

        <p class="text-muted mb-0">
            <strong>Public</strong> is what guests and accounts without a user type pay. Each user-type column
            overrides it for that type only; an empty cell (<strong>—</strong>) means that type pays the public price.
            In every cell choose <strong>$</strong> for a fixed price or <strong>%</strong> for a profit % on the
            variation's cost (cost &times; (1 + %)). A % price follows the supplier cost automatically on every sync; a
            fixed price never moves on its own. Public <strong>—</strong> means “use the default
            {{ $fmt($default_profit) }}%” from Fixed Settings.
            Prices <span class="pm-loss">in red</span> are below cost.
        </p>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger mx-lg-5 mx-2">
            <ul class="mb-0 pl-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (session('price_matrix_skipped'))
        <div class="alert alert-warning mx-lg-5 mx-2">
            <strong>Skipped:</strong>
            <ul class="mb-0 pl-3">
                @foreach (session('price_matrix_skipped') as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ url($prefix . '/price-matrix') }}" id="pm-bulk-form">
        @csrf
        <input type="hidden" name="_method" value="PUT">
        <input type="hidden" name="bulk_ids" id="pm-bulk-ids">

        <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
            <div class="form-row align-items-end">
                <div class="form-group col-md-3">
                    <label class="font-weight-bold">Column</label>
                    <select name="bulk_column" class="form-control" required>
                        <option value="public">Public</option>
                        @foreach ($user_types as $type)
                            <option value="{{ $type['id'] }}">{{ $type['title'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label class="font-weight-bold">Action</label>
                    <select name="bulk_mode" class="form-control" id="pm-bulk-mode" required>
                        <option value="percent">Set profit % on cost</option>
                        <option value="fixed">Set fixed price $</option>
                        <option value="clear">Clear (use public / default)</option>
                        <option value="adjust">Adjust current price by % (Public, manual products only)</option>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <label class="font-weight-bold">Value</label>
                    <input type="number" step="0.0001" name="bulk_value" id="pm-bulk-value" class="form-control">
                </div>
                <div class="form-group col-md-4">
                    <button type="submit" class="btn btn-primary btn-sm">Apply to selected</button>
                    <span class="text-muted ml-2" id="pm-count">0 selected</span>
                </div>
            </div>
            <p class="text-muted mb-0">
                Tick a product's row to select all of its variations. <strong>Adjust current price by %</strong>
                multiplies today's public price (10 = +10%, -5 = -5%) and locks it as a fixed price; it is refused for
                supplier products, whose price follows cost &times; profit % — set a profit % on those instead.
            </p>
        </div>
    </form>

    <form method="post" action="{{ url($prefix . '/price-matrix') }}" id="pm-form">
        @csrf
        <input type="hidden" name="_method" value="PUT">
        <input type="hidden" name="changes" id="pm-changes">

        <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
            <div class="mb-3">
                <button type="submit" class="btn btn-primary btn-sm" id="pm-save" disabled>Save changes</button>
                <span class="text-muted ml-2" id="pm-dirty-count">No unsaved changes</span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm pm-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="pm-all"></th>
                            <th>Variation</th>
                            <th class="text-right">Cost</th>
                            <th>Public</th>
                            @foreach ($user_types as $type)
                                <th>{{ $type['title'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $lastProduct = null;
                        @endphp
                        @forelse ($rows as $row)
                            @if ($lastProduct !== $row['product_id'])
                                @php
                                    $lastProduct = $row['product_id'];
                                @endphp
                                <tr class="pm-group">
                                    <td><input type="checkbox" class="pm-product-check" data-product="{{ $row['product_id'] }}" title="Select every variation of this product"></td>
                                    <td colspan="{{ 3 + count($user_types) }}">
                                        <a href="{{ url($prefix . '/products/' . $row['product_id'] . '/edit') }}">{{ $row['product'] }}</a>
                                        @if ($row['source'])
                                            <span class="badge badge-info">{{ $row['source'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                            <tr class="pm-row" data-id="{{ $row['id'] }}" data-product="{{ $row['product_id'] }}" data-cost="{{ $row['cost'] ?? '' }}">
                                <td><input type="checkbox" class="pm-check" value="{{ $row['id'] }}"></td>
                                <td class="pm-name">
                                    <a href="{{ url($prefix . '/products-variations/' . $row['id'] . '/edit') }}">{{ $row['name'] }}</a>
                                    @unless ($row['is_active'])
                                        <span class="badge badge-secondary">inactive</span>
                                    @endunless
                                </td>
                                <td class="text-right">{{ $row['cost'] === null ? '—' : $fmt($row['cost']) }}</td>
                                @foreach ($row['cells'] as $column => $cell)
                                    <td class="pm-cell" data-column="{{ $column }}"
                                        data-orig-mode="{{ $cell['mode'] }}"
                                        data-orig-value="{{ $fmt($cell['value']) }}"
                                        data-orig-price="{{ $fmt($cell['price']) }}"
                                        data-default="{{ $column === 'public' ? $fmt($cell['default']) : '' }}"
                                        data-public-price="{{ $fmt($row['cells']['public']['price']) }}">
                                        <div class="d-flex">
                                            <select class="form-control form-control-sm pm-mode regular-select mr-1">
                                                <option value="clear" {{ $cell['mode'] === 'clear' || ($column === 'public' && ($cell['inherits_default'] ?? false)) ? 'selected' : '' }}>—</option>
                                                <option value="fixed" {{ $cell['mode'] === 'fixed' ? 'selected' : '' }}>$</option>
                                                <option value="percent" {{ $cell['mode'] === 'percent' && !($cell['inherits_default'] ?? false) ? 'selected' : '' }} {{ $row['cost'] === null ? 'disabled' : '' }}>%</option>
                                            </select>
                                            <input type="number" step="0.0001" class="form-control form-control-sm pm-value" value="{{ $fmt($cell['value']) }}">
                                        </div>
                                        <div class="pm-result text-muted"></div>
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ 4 + count($user_types) }}" class="text-muted">No products match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $products->links() }}
        </div>
    </form>

    <script>
        (function () {
            var all = document.getElementById('pm-all');
            var count = document.getElementById('pm-count');
            var save = document.getElementById('pm-save');
            var dirtyCount = document.getElementById('pm-dirty-count');

            function list(selector) {
                return Array.prototype.slice.call(document.querySelectorAll(selector));
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

            function refresh() {
                var dirty = list('.pm-cell').filter(isDirty);
                save.disabled = dirty.length === 0;
                dirtyCount.textContent = dirty.length ? dirty.length + ' unsaved change(s)' : 'No unsaved changes';
                count.textContent = list('.pm-check').filter(function (c) { return c.checked; }).length + ' selected';
            }

            list('.pm-cell').forEach(function (td) {
                td.querySelector('.pm-mode').addEventListener('change', function () { render(td); refresh(); });
                td.querySelector('.pm-value').addEventListener('input', function () { render(td); refresh(); });
                render(td);
            });

            all.addEventListener('change', function () {
                list('.pm-check, .pm-product-check').forEach(function (c) { c.checked = all.checked; });
                refresh();
            });

            // A product row selects all of its variations — what Bulk pricing used to do.
            list('.pm-product-check').forEach(function (p) {
                p.addEventListener('change', function () {
                    list('.pm-row[data-product="' + p.dataset.product + '"] .pm-check').forEach(function (c) { c.checked = p.checked; });
                    refresh();
                });
            });
            list('.pm-check').forEach(function (c) { c.addEventListener('change', refresh); });

            document.getElementById('pm-form').addEventListener('submit', function (e) {
                var changes = list('.pm-cell').filter(isDirty).map(function (td) {
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
                document.getElementById('pm-changes').value = JSON.stringify(changes);
            });

            window.addEventListener('beforeunload', function (e) {
                if (!save.disabled && !save.dataset.submitting) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
            document.getElementById('pm-form').addEventListener('submit', function () { save.dataset.submitting = '1'; });

            document.getElementById('pm-bulk-form').addEventListener('submit', function (e) {
                var ids = list('.pm-check').filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
                var mode = document.getElementById('pm-bulk-mode').value;

                if (!ids.length) {
                    e.preventDefault();
                    alert('Select at least one variation.');
                    return;
                }
                if (mode !== 'clear' && document.getElementById('pm-bulk-value').value === '') {
                    e.preventDefault();
                    alert('Enter a value.');
                    return;
                }
                if (!confirm('Apply this to ' + ids.length + ' variation(s)?')) {
                    e.preventDefault();
                    return;
                }
                document.getElementById('pm-bulk-ids').value = ids.join(',');
                save.dataset.submitting = '1';
            });

            refresh();
        })();
    </script>
@endsection
