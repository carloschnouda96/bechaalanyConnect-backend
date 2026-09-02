@extends('cms::layouts/dashboard')

@php
    $prefix = config('hellotree.cms_route_prefix');
    $money = fn ($range) => $range === null ? '—' : ($range[0] == $range[1]
        ? number_format($range[0], 2)
        : number_format($range[0], 2) . ' – ' . number_format($range[1], 2));
@endphp

@section('breadcrumb')
    <ul class="breadcrumbs list-inline font-weight-bold text-uppercase m-0">
        <li>Bulk pricing</li>
    </ul>
@endsection

@section('dashboard-content')
    <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
        <form method="get" class="form-row align-items-end">
            <div class="form-group col-md-3">
                <label class="font-weight-bold">Source</label>
                <select name="source" class="form-control">
                    <option value="">All</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source }}" {{ ($filters['source'] ?? '') === $source ? 'selected' : '' }}>
                            {{ $source }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group col-md-3">
                <label class="font-weight-bold">Subcategory</label>
                <select name="subcategory_id" class="form-control">
                    <option value="">All</option>
                    @foreach ($subcategories as $subcategory)
                        <option value="{{ $subcategory->id }}" {{ (string) ($filters['subcategory_id'] ?? '') === (string) $subcategory->id ? 'selected' : '' }}>
                            {{ $subcategory->slug }}
                        </option>
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
            <div class="form-group col-md-2">
                <label class="font-weight-bold">Search</label>
                <input type="search" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="name or slug">
            </div>
            <div class="form-group col-md-2">
                <label class="font-weight-bold">Preview profit %</label>
                <input type="number" step="0.01" name="preview_profit" class="form-control"
                       value="{{ $filters['preview_profit'] ?? '' }}" placeholder="e.g. 20">
            </div>
            <div class="form-group col-12">
                <button type="submit" class="btn btn-secondary btn-sm">Apply filters</button>
                <a href="{{ url($prefix . '/catalog-pricing') }}" class="btn btn-link btn-sm">Reset</a>
            </div>
        </form>

        <p class="text-muted mb-0">
            Default profit when a product has none of its own: <strong>{{ number_format($default_profit, 2) }}%</strong>
            (Fixed Settings). Enter a <strong>preview profit %</strong> above to see the prices it would produce
            before you apply anything.
        </p>
    </div>

    <form method="post" action="{{ url($prefix . '/catalog-pricing') }}" id="pricing-form">
        @csrf
        <input type="hidden" name="_method" value="PUT">
        <input type="hidden" name="ids" id="pricing-ids">

        <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
            <div class="form-row align-items-end">
                <div class="form-group col-md-4">
                    <label class="font-weight-bold">Action</label>
                    <select name="action" class="form-control" required>
                        <option value="set_profit">Set profit % (reprice from cost)</option>
                        <option value="adjust_price">Adjust current prices by % (manual products only)</option>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label class="font-weight-bold">Value %</label>
                    <input type="number" step="0.01" name="value" class="form-control" required>
                </div>
                <div class="form-group col-md-5">
                    <button type="submit" class="btn btn-primary btn-sm">Apply to selected</button>
                    <span class="text-muted ml-2" id="pricing-count">0 selected</span>
                </div>
            </div>
            <p class="text-muted mb-0">
                <strong>Set profit %</strong> writes the markup and recomputes selling price from cost — the same
                formula the supplier syncs use. <strong>Adjust prices by %</strong> multiplies the current price and
                is refused for supplier products, whose price is derived and would be overwritten at the next sync.
            </p>
        </div>

        <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="pricing-all"></th>
                            <th>Product</th>
                            <th>Source</th>
                            <th class="text-right">Variations</th>
                            <th class="text-right">Profit %</th>
                            <th class="text-right">Cost</th>
                            <th class="text-right">Price</th>
                            @if (($filters['preview_profit'] ?? null) !== null)
                                <th class="text-right">Price at {{ $filters['preview_profit'] }}%</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td><input type="checkbox" class="pricing-check" value="{{ $row['id'] }}"></td>
                                <td>
                                    <a href="{{ url($prefix . '/products/' . $row['id'] . '/edit') }}">{{ $row['name'] }}</a>
                                    @unless ($row['is_active'])
                                        <span class="badge badge-secondary">inactive</span>
                                    @endunless
                                    <div class="text-muted"><small>{{ $row['slug'] }}</small></div>
                                </td>
                                <td>
                                    @if ($row['source'])
                                        <span class="badge badge-info">{{ $row['source'] }}</span>
                                    @else
                                        <span class="text-muted">manual</span>
                                    @endif
                                </td>
                                <td class="text-right">{{ $row['variations'] }}</td>
                                <td class="text-right">
                                    @if ($row['profit'] === null)
                                        <span class="text-muted" title="Inherited from Fixed Settings">{{ number_format($row['effective'], 2) }}*</span>
                                    @else
                                        {{ number_format((float) $row['profit'], 2) }}
                                    @endif
                                </td>
                                <td class="text-right">{{ $money($row['cost_range']) }}</td>
                                <td class="text-right">{{ $money($row['price_range']) }}</td>
                                @if (($filters['preview_profit'] ?? null) !== null)
                                    <td class="text-right text-primary">{{ $money($row['projected_range']) }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-muted">No products match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $products->links() }}
        </div>
    </form>

    <script>
        (function () {
            var form = document.getElementById('pricing-form');
            var all = document.getElementById('pricing-all');
            var count = document.getElementById('pricing-count');

            function checks() {
                return Array.prototype.slice.call(document.querySelectorAll('.pricing-check'));
            }

            function selected() {
                return checks().filter(function (c) { return c.checked; });
            }

            function refresh() {
                count.textContent = selected().length + ' selected';
            }

            all.addEventListener('change', function () {
                checks().forEach(function (c) { c.checked = all.checked; });
                refresh();
            });

            checks().forEach(function (c) { c.addEventListener('change', refresh); });

            form.addEventListener('submit', function (e) {
                var ids = selected().map(function (c) { return c.value; });

                if (!ids.length) {
                    e.preventDefault();
                    alert('Select at least one product.');
                    return;
                }

                if (!confirm('Apply this pricing change to ' + ids.length + ' product(s)?')) {
                    e.preventDefault();
                    return;
                }

                document.getElementById('pricing-ids').value = ids.join(',');
            });

            refresh();
        })();
    </script>
@endsection
