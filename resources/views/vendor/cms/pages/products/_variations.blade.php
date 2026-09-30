{{--
    The Variations card on the product edit page. Data: $editor from
    Cms\ProductEditorController::edit(). Price cells are the Price matrix's own
    (price-matrix/_cell + _assets) and save through the same VariationPricing service.

    Rendered after the product <form>, never inside it — HTML forms cannot nest.
--}}
@php
    $prefix = config('hellotree.cms_route_prefix');
    $fmt = fn ($n) => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
    $product = $editor['product'];
    $rows = $editor['rows'];
    $isSupplier = filled($product->external_source);
    $activeRows = $rows->filter(fn ($r) => $r['is_active'] && !$r['draft']);
    $newVariationUrl = url($prefix . '/products-variations/create') . '?product_id=' . $product->id . '&return_to=product';
@endphp

@include('cms::pages/price-matrix/_assets')

<div class="card mx-2 mx-sm-5 py-4 px-4 mb-4" id="variations">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-2">
        <p class="font-weight-bold text-uppercase mb-0">Variations &amp; prices</p>
        <div>
            @unless ($isSupplier)
                <a href="{{ $newVariationUrl }}" class="btn btn-sm btn-secondary">Add with description / image</a>
            @endunless
        </div>
    </div>
    <p class="text-muted">
        Each variation is one amount this product is sold in (e.g. "100 UC"). <strong>Public</strong> is what
        guests and regular accounts pay; each user-type column overrides it for that type only
        (<strong>—</strong> = pays the public price). Choose <strong>$</strong> for a fixed price or
        <strong>%</strong> for a profit on cost. Public <strong>—</strong> uses the default {{ $fmt($editor['default_profit']) }}% from Fixed Settings.
    </p>

    @unless ($editor['for_sale'])
        <div class="alert alert-warning">
            <strong>Not for sale.</strong>
            @if ($rows->isEmpty())
                This product has no variations yet, so it is hidden from every storefront list. Add at least one below.
            @elseif ($activeRows->isEmpty())
                None of its variations is active, so it is hidden from every storefront list.
            @elseif (!$product->is_active)
                <strong>Is Active</strong> is off above, so it is hidden from the storefront.
            @else
                The supplier currently has none of its variations in stock, so it is hidden until they return.
            @endif
        </div>
    @endunless

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0 pl-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (session('price_matrix_skipped'))
        <div class="alert alert-warning">
            <strong>Skipped:</strong>
            <ul class="mb-0 pl-3">
                @foreach (session('price_matrix_skipped') as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($rows->isNotEmpty())
        <form method="post" action="{{ url($prefix . '/products/' . $product->id . '/prices') }}" id="pe-prices-form">
            @csrf
            <input type="hidden" name="_method" value="PUT">
            <input type="hidden" name="changes" class="pm-changes">

            <div class="table-responsive">
                <table class="table table-sm pm-table mb-2">
                    <thead>
                        <tr>
                            <th>Variation</th>
                            <th class="text-right">Cost</th>
                            <th>Public</th>
                            @foreach ($editor['user_types'] as $type)
                                <th>{{ $type['title'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="pm-row" data-id="{{ $row['id'] }}" data-cost="{{ $row['cost'] ?? '' }}">
                                <td class="pm-name">
                                    <a href="{{ url($prefix . '/products-variations/' . $row['id'] . '/edit') }}?return_to=product">{{ $row['name'] }}</a>
                                    @if ($row['draft'])
                                        <span class="badge badge-secondary">draft</span>
                                    @elseif (!$row['is_active'])
                                        <span class="badge badge-secondary">inactive</span>
                                    @endif
                                    @if ($row['supplier_status'] && $row['supplier_status'] !== 'available')
                                        <span class="badge badge-warning">{{ str_replace('_', ' ', $row['supplier_status']) }}</span>
                                    @endif
                                </td>
                                <td class="text-right">{{ $row['cost'] === null ? '—' : $fmt($row['cost']) }}</td>
                                @foreach ($row['cells'] as $column => $cell)
                                    @include('cms::pages/price-matrix/_cell', ['cells' => $row['cells'], 'cost' => $row['cost']])
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <button type="submit" class="btn btn-primary btn-sm pm-save" disabled>Save prices</button>
            <span class="text-muted ml-2 pm-dirty-count">No unsaved changes</span>
        </form>
    @endif

    @unless ($isSupplier)
        <hr>
        <p class="font-weight-bold mb-2">Quick add a variation</p>
        <form method="post" action="{{ url($prefix . '/products/' . $product->id . '/variations') }}" class="form-row align-items-end">
            @csrf
            @foreach ($editor['locales'] as $i => $locale)
                <div class="form-group col-md-2">
                    <label class="font-weight-bold">Name ({{ strtoupper($locale) }}){!! $i === 0 ? ' <span class="text-danger">*</span>' : '' !!}</label>
                    <input type="text" name="name[{{ $locale }}]" class="form-control" maxlength="191"
                           value="{{ old('name.' . $locale) }}" {{ $i === 0 ? 'required' : '' }}
                           dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}"
                           placeholder="{{ $i === 0 ? 'e.g. 100 UC' : 'optional — copies ' . strtoupper($editor['locales'][0]) }}">
                </div>
            @endforeach
            <div class="form-group col-md-2">
                <label class="font-weight-bold">Cost $ <span class="text-danger">*</span></label>
                <input type="number" step="any" min="0" name="cost_price" class="form-control" value="{{ old('cost_price') }}" required>
            </div>
            <div class="form-group col-md-2">
                <label class="font-weight-bold">Price as</label>
                <select name="pricing_mode" class="form-control regular-select">
                    <option value="fixed" {{ old('pricing_mode') !== 'percent' ? 'selected' : '' }}>Fixed price $</option>
                    <option value="percent" {{ old('pricing_mode') === 'percent' ? 'selected' : '' }}>Profit % on cost</option>
                </select>
            </div>
            <div class="form-group col-md-2">
                <label class="font-weight-bold">Value</label>
                <input type="number" step="0.0001" name="pricing_value" class="form-control" value="{{ old('pricing_value') }}" required>
            </div>
            <div class="form-group col-md-2">
                <button type="submit" class="btn btn-primary btn-sm">Add variation</button>
            </div>
        </form>
        <small class="text-muted">Adds an active variation. Open it afterwards to add a description or image.</small>
    @endunless
</div>

@if ($rows->isNotEmpty())
    <script>
        window.pmGrid(document.getElementById('pe-prices-form'));
    </script>
@endif
