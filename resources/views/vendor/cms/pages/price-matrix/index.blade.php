@extends('cms::layouts/dashboard')

@php
    $prefix = config('hellotree.cms_route_prefix');
    $fmt = fn ($n) => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
@endphp

@section('breadcrumb')
    <ul class="breadcrumbs list-inline font-weight-bold text-uppercase m-0">
        <li>Prices</li>
    </ul>
@endsection

@section('dashboard-content')
    @include('cms::pages/price-matrix/_assets')

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
        <input type="hidden" name="changes" class="pm-changes">

        <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
            <div class="mb-3">
                <button type="submit" class="btn btn-primary btn-sm pm-save" disabled>Save changes</button>
                <span class="text-muted ml-2 pm-dirty-count">No unsaved changes</span>
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
                                    @include('cms::pages/price-matrix/_cell', ['cells' => $row['cells'], 'cost' => $row['cost']])
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

            function list(selector) {
                return Array.prototype.slice.call(document.querySelectorAll(selector));
            }

            function countSelected() {
                count.textContent = list('.pm-check').filter(function (c) { return c.checked; }).length + ' selected';
            }

            var grid = window.pmGrid(document.getElementById('pm-form'), countSelected);

            all.addEventListener('change', function () {
                list('.pm-check, .pm-product-check').forEach(function (c) { c.checked = all.checked; });
                countSelected();
            });

            // A product row selects all of its variations — what Bulk pricing used to do.
            list('.pm-product-check').forEach(function (p) {
                p.addEventListener('change', function () {
                    list('.pm-row[data-product="' + p.dataset.product + '"] .pm-check').forEach(function (c) { c.checked = p.checked; });
                    countSelected();
                });
            });
            list('.pm-check').forEach(function (c) { c.addEventListener('change', countSelected); });

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
                grid.markSubmitting();
            });
        })();
    </script>
@endsection
