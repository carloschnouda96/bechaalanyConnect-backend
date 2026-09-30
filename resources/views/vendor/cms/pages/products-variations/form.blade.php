{{--
    Variation create/edit — the vendor form, regrouped into Product / Name / Price /
    Details, with one pricing question ("profit % on cost" or "fixed price") instead of
    three free-standing Price / Cost / Profit inputs. Posted to
    Cms\VariationFormController, which lets the vendor save and then applies the chosen
    mode through VariationPricing — the Price matrix's write path.

    Every registered field is still rendered (Coin fields only hidden with CSS): the
    vendor update() writes null for any non-checkbox field missing from the request.
--}}
@extends('cms::layouts/dashboard')

@php
    $prefix = config('hellotree.cms_route_prefix');
    $fmt = fn ($n) => $n === null || $n === '' ? '' : rtrim(rtrim(number_format((float) $n, 8, '.', ''), '0'), '.');
    $pricing = app(\App\Services\Cms\VariationPricing::class);
    $defaultProfit = $pricing->defaultProfit();
    $coinTypeId = 4; // product_type "coin-recharge" — see Coin Recharge products

    $byName = collect($page_fields)->keyBy('name');
    $special = ['product_id', 'price', 'cost_price', 'profit_percentage', 'unit_amount', 'slug'];
    $details = collect($page_fields)->reject(fn ($f) => in_array($f['name'], $special, true))
        ->merge($byName->has('slug') ? [$byName['slug']] : [])
        ->values();

    $products = $extra_variables['products'] ?? collect();
    $lockedProductId = isset($row) ? null : request('product_id');
    $productId = isset($row) ? $row['product_id'] : ($lockedProductId ?: old('product_id'));
    $parent = $productId ? $products->firstWhere('id', (int) $productId) : null;
    $isSupplier = $parent && filled($parent->external_source);
    $returnTo = request('return_to') === 'product' ? 'product' : null;

    // Which pricing mode the row is in right now — the same reading the Price matrix uses.
    if (isset($row)) {
        $cell = $pricing->publicCell($row, \App\Services\Cms\VariationPricing::costOf($row), $defaultProfit);
        $mode = $cell['mode'];
    } else {
        $mode = 'fixed';
    }
@endphp

@section('breadcrumb')
    <ul class="breadcrumbs list-inline font-weight-bold text-uppercase m-0">
        @if ($parent)
            <li><a href="{{ url($prefix . '/products/' . $parent->id . '/edit') }}#variations">{{ $parent->name ?: $parent->slug }}</a></li>
        @else
            <li><a href="{{ url($prefix . '/' . $page['route']) }}">{{ $page['display_name_plural'] }}</a></li>
        @endif
        @if (isset($row))
            <li>{{ $row['id'] }}</li>
            <li>Edit</li>
        @else
            <li>Add variation</li>
        @endif
    </ul>
@endsection

@section('dashboard-content')
    <style>
        .pe-section-title { font-size: .8rem; letter-spacing: .04em; }
        .pe-coin.pe-hidden, .pe-mode-block.pe-hidden { display: none; }
        .pe-price-preview { font-size: 1.1rem; }
    </style>

    <form method="post" enctype="multipart/form-data" action="{{ isset($row) ? url($prefix . '/' . $page['route'] . '/' . $row['id'] . ($appends_to_query ?? '')) : url($prefix . '/' . $page['route']) }}" ajax>

        <div class="card p-4 mx-2 mx-sm-5">
            <p class="font-weight-bold text-uppercase mb-3">{{ isset($row) ? 'Edit ' . $page['display_name'] . ' #' . $row['id'] : 'Add ' . $page['display_name'] }}</p>

            @if (isset($row))
                @method('put')
            @endif
            @if ($returnTo)
                <input type="hidden" name="return_to" value="product">
            @endif
            <input type="hidden" name="draft_cms_field" id="draftField" value="0">

            @if ($isSupplier)
                <div class="alert alert-info">
                    Imported from <strong>{{ $parent->external_source }}</strong>
                    @if (isset($row) && $row['supplier_status'])
                        &middot; supplier status <strong>{{ str_replace('_', ' ', $row['supplier_status']) }}</strong>
                    @endif
                    . The cost is kept current by the supplier sync; a profit % keeps the price following it.
                </div>
            @endif

            {{-- 1 · Product --}}
            <p class="pe-section-title font-weight-bold text-uppercase text-muted mb-2">Product</p>
            <div class="form-group">
                @if ($lockedProductId && $parent)
                    <input type="hidden" name="product_id" value="{{ $parent->id }}" id="pe-product" data-type="{{ $parent->product_type_id }}">
                    <p class="form-control-plaintext font-weight-bold mb-0">{{ $parent->name ?: $parent->slug }}</p>
                @else
                    @include('cms::components/form-fields/label', ['label' => 'Product', 'required' => true, 'description' => ''])
                    <select class="form-control" name="product_id" id="pe-product">
                        <option></option>
                        @foreach ($products as $option)
                            <option value="{{ $option->id }}" data-type="{{ $option->product_type_id }}" {{ (string) $productId === (string) $option->id ? 'selected' : '' }}>
                                {{ strip_tags($option->name ?: $option->slug) }}{{ $option->external_source ? ' (' . $option->external_source . ')' : '' }}
                            </option>
                        @endforeach
                    </select>
                @endif
            </div>

            {{-- 2 · Name / description --}}
            @if (count($page_translatable_fields))
                <p class="pe-section-title font-weight-bold text-uppercase text-muted mb-2 mt-2">Name &amp; description</p>
                @foreach (\Hellotreedigital\Cms\Models\Language::get() as $language)
                    <div class="form-group">
                        <label>{{ $language->title }}</label>
                        <div class="pl-3">
                            @foreach ($page_translatable_fields as $field)
                                @if ($field['name'] === 'unit_label')
                                    <div class="pe-coin">
                                        @include('cms::pages/cms-page/form-fields', ['locale' => $language->slug])
                                    </div>
                                @else
                                    @include('cms::pages/cms-page/form-fields', ['locale' => $language->slug])
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif

            {{-- 3 · Price --}}
            <p class="pe-section-title font-weight-bold text-uppercase text-muted mb-2 mt-2">Price</p>
            <div class="form-row">
                <div class="form-group col-md-4">
                    @include('cms::components/form-fields/label', ['label' => 'Cost price $', 'required' => true, 'description' => 'What one unit costs you — used for profit reports and for a profit % price. Never shown to customers.'])
                    <input type="number" step="any" min="0" name="cost_price" id="pe-cost" class="form-control"
                           value="{{ $fmt(isset($row) ? $row['cost_price'] : old('cost_price')) }}"
                           {{ $isSupplier ? 'readonly' : '' }}>
                    @if ($isSupplier)
                        <small class="text-muted">Set by the supplier sync.</small>
                    @endif
                </div>
            </div>

            <div class="form-group">
                <label class="d-block font-weight-bold mb-1">How is it priced?</label>
                <div class="custom-control custom-radio custom-control-inline">
                    <input type="radio" id="pe-mode-percent" name="pricing_mode" value="percent" class="custom-control-input" {{ $mode === 'percent' ? 'checked' : '' }}>
                    <label class="custom-control-label" for="pe-mode-percent">Profit % on cost</label>
                </div>
                <div class="custom-control custom-radio custom-control-inline">
                    <input type="radio" id="pe-mode-fixed" name="pricing_mode" value="fixed" class="custom-control-input" {{ $mode === 'fixed' ? 'checked' : '' }}>
                    <label class="custom-control-label" for="pe-mode-fixed">Fixed price</label>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4 pe-mode-block" data-mode="percent">
                    @include('cms::components/form-fields/label', ['label' => 'Profit %', 'description' => 'Leave empty to use the default ' . $fmt($defaultProfit) . '% from Fixed Settings. The price follows the cost automatically.'])
                    <input type="number" step="0.01" name="profit_percentage" id="pe-profit" class="form-control"
                           value="{{ $fmt(isset($row) ? $row['profit_percentage'] : old('profit_percentage')) }}"
                           placeholder="default {{ $fmt($defaultProfit) }}">
                </div>
                <div class="form-group col-md-4 pe-mode-block" data-mode="fixed">
                    @include('cms::components/form-fields/label', ['label' => 'Price $', 'required' => true, 'description' => 'What customers pay. A fixed price never moves on its own, even when the cost changes.'])
                    <input type="number" step="0.01" min="0" name="price" id="pe-price" class="form-control"
                           value="{{ $fmt(isset($row) ? $row['price'] : old('price')) }}">
                </div>
                <div class="form-group col-md-4 d-flex align-items-end">
                    <p class="pe-price-preview mb-2">Customers pay <strong id="pe-preview">—</strong> <span id="pe-loss" class="text-danger" hidden>(below cost)</span></p>
                </div>
            </div>
            <p class="text-muted small">Prices for specific user types (VIP, resellers…) are set on the product page or on Prices.</p>

            {{-- 4 · Details --}}
            <p class="pe-section-title font-weight-bold text-uppercase text-muted mb-2 mt-2">Details</p>
            @if ($byName->has('unit_amount'))
                <div class="pe-coin">
                    @php $field = $byName['unit_amount']; @endphp
                    @include('cms::pages/cms-page/form-fields', ['locale' => null])
                </div>
            @endif
            @foreach ($details as $field)
                @if ($field['name'] == 'slug' && (isset($row) && (isset($row['cms_draft_flag']) && $row['cms_draft_flag'] == 1)))
                    @php
                        $field['form_field_additionals_2'] = 1;
                        $field['hide_edit'] = 0;
                    @endphp
                @endif
                @if ($field['form_field'] && ((!isset($row) && (!isset($field['hide_create']) || !$field['hide_create'])) || (isset($row) && (!isset($field['hide_edit']) || !$field['hide_edit']))))
                    @include('cms::pages/cms-page/form-fields', ['locale' => null])
                @endif
            @endforeach

            @csrf

            <div class="form-buttons-wrapper text-right">
                <input type="hidden" name="ht_preview_mode" value="0">
                @if ($page['preview_path'])
                    <button type="button" class="btn btn-sm btn-secondary ht-preview-mode">Preview</button>
                @endif
                <button type="submit" class="btn btn-sm btn-primary submit-draft-button">Save variation</button>
                @if ($page['single_record'] == 0)
                    <button type="submit" class="btn btn-secondary save-as-draft-button">Save As Draft</button>
                @endif
            </div>
        </div>

    </form>

    <script>
        (function () {
            var coinTypeId = '{{ $coinTypeId }}';
            var defaultProfit = parseFloat('{{ $fmt($defaultProfit) ?: 0 }}');
            var product = document.getElementById('pe-product');
            var cost = document.getElementById('pe-cost');
            var profit = document.getElementById('pe-profit');
            var price = document.getElementById('pe-price');
            var preview = document.getElementById('pe-preview');
            var loss = document.getElementById('pe-loss');

            function each(selector, fn) {
                Array.prototype.forEach.call(document.querySelectorAll(selector), fn);
            }

            function mode() {
                var checked = document.querySelector('input[name="pricing_mode"]:checked');
                return checked ? checked.value : 'fixed';
            }

            function productType() {
                if (!product) return '';
                if (product.tagName === 'SELECT') {
                    var opt = product.options[product.selectedIndex];
                    return opt ? (opt.dataset.type || '') : '';
                }
                return product.dataset.type || '';
            }

            // Same arithmetic as ProductsVariation::computeSellingPrice(); display only.
            function selling(c, pct) {
                return Math.round(c * (1 + pct / 100) * 100) / 100;
            }

            function render() {
                var m = mode();
                each('.pe-mode-block', function (el) { el.classList.toggle('pe-hidden', el.dataset.mode !== m); });
                // Coin fields stay in the form (the vendor save nulls anything missing) —
                // they are only hidden for other product types.
                each('.pe-coin', function (el) { el.classList.toggle('pe-hidden', productType() !== coinTypeId); });

                var c = cost.value === '' ? null : parseFloat(cost.value);
                var p = null;
                if (m === 'percent') {
                    p = c === null ? null : selling(c, profit.value === '' ? defaultProfit : parseFloat(profit.value));
                } else if (price.value !== '') {
                    p = parseFloat(price.value);
                }
                preview.textContent = p === null ? (m === 'percent' ? 'needs a cost price' : '—') : '$' + p.toFixed(2);
                loss.hidden = !(p !== null && c !== null && p < c);
            }

            each('input[name="pricing_mode"]', function (el) { el.addEventListener('change', render); });
            [cost, profit, price].forEach(function (el) { el.addEventListener('input', render); });
            if (product && product.tagName === 'SELECT') {
                product.addEventListener('change', render);
                if (window.jQuery) window.jQuery(product).on('change', render); // select2 fires jQuery events
            }
            render();
        })();
    </script>
@endsection
