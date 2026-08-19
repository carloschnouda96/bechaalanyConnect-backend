{{--
    Operations dashboard — overrides the vendor's blank home page.

    The stock page renders only config('hellotree.home_title'), which is set to the
    empty string, so an admin signing in saw nothing and had to open every page in
    turn to find out whether anything needed doing.

    Data comes from App\Services\Cms\DashboardMetrics (each group cached ~60s and
    individually fault-tolerant). Called statically here, matching the existing
    pattern in pages/orders/index.blade.php, so no route override is needed — the
    published view path alone takes precedence over the package's.
--}}
@extends('cms::layouts/dashboard')

@php
    $prefix = config('hellotree.cms_route_prefix');
    $metrics = app(\App\Services\Cms\DashboardMetrics::class)->all();
    $queues = $metrics['queues'] ?? [];
    $revenue = $metrics['revenue'] ?? [];
    $health = $metrics['health'] ?? [];
    $money = fn ($v) => '$' . number_format((float) $v, 2);
@endphp

@section('dashboard-content')

    <div class="mx-2 mx-lg-5">

        {{-- Work waiting on a human. Each tile links straight into its queue. --}}
        <h5 class="font-weight-bold mt-4 mb-3">Needs attention</h5>
        <div class="row">
            @php
                $waiting = [
                    ['label' => 'Pending orders',  'value' => $queues['pending_orders'] ?? 0, 'url' => $prefix . '/orders',           'icon' => 'fa-shopping-cart'],
                    ['label' => 'Pending top-ups', 'value' => $queues['pending_topups'] ?? 0, 'url' => $prefix . '/credits-transfer', 'icon' => 'fa-credit-card'],
                    ['label' => 'Pending KYC',     'value' => $queues['pending_kyc'] ?? 0,    'url' => $prefix . '/kyc-queue',        'icon' => 'fa-id-card'],
                ];
            @endphp

            @foreach ($waiting as $tile)
                <div class="col-12 col-md-4 mb-3">
                    <a href="{{ url($tile['url']) }}" class="text-decoration-none">
                        <div class="card h-100 py-4 px-3 text-center">
                            <i class="fa {{ $tile['icon'] }} fa-2x mb-2 {{ $tile['value'] > 0 ? 'text-warning' : 'text-muted' }}"></i>
                            <div style="font-size:2rem; font-weight:700; line-height:1;"
                                 class="{{ $tile['value'] > 0 ? 'text-warning' : 'text-muted' }}">
                                {{ number_format($tile['value']) }}
                            </div>
                            <div class="text-muted mt-1">{{ $tile['label'] }}</div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>

        {{-- Revenue and profit. Only trustworthy since orders.total_price became
             DECIMAL: as LONGTEXT, MySQL silently coerced bad rows to 0 inside SUM(). --}}
        <h5 class="font-weight-bold mt-4 mb-3">Sales</h5>
        <div class="row">
            @foreach (['today' => 'Today', 'month' => 'This month', 'all_time' => 'All time'] as $key => $label)
                @php $r = $revenue[$key] ?? ['orders' => 0, 'revenue' => 0, 'profit' => 0]; @endphp
                <div class="col-12 col-md-4 mb-3">
                    <div class="card h-100 py-4 px-3">
                        <div class="text-muted text-uppercase" style="font-size:.75rem; letter-spacing:.05em;">{{ $label }}</div>
                        <div style="font-size:1.75rem; font-weight:700; line-height:1.2;">{{ $money($r['revenue']) }}</div>
                        <div class="text-success" style="font-weight:600;">{{ $money($r['profit']) }} profit</div>
                        <div class="text-muted mt-1">{{ number_format($r['orders']) }} approved order(s)</div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Conditions that are silently wrong. Every one of these was previously
             invisible until a customer complained. --}}
        <h5 class="font-weight-bold mt-4 mb-3">System health</h5>
        <div class="row">
            @php
                $alerts = [
                    [
                        'label' => 'Balances the ledger cannot explain',
                        'value' => $health['ledger_drift'] ?? 0,
                        'hint'  => 'Credits moved without recording themselves. Run: php artisan credits:reconcile',
                    ],
                    [
                        'label' => 'Approved but never sent to supplier',
                        'value' => $health['unplaced_supplier_orders'] ?? 0,
                        'hint'  => 'The customer paid and the supplier never received the order. Retry from Supplier health.',
                    ],
                    [
                        'label' => 'Supplier reported failure',
                        'value' => $health['failed_supplier_orders'] ?? 0,
                        'hint'  => 'These are refunded automatically; shown so a pattern is visible.',
                    ],
                    [
                        'label' => 'Balances near the column ceiling',
                        'value' => $health['balances_near_ceiling'] ?? 0,
                        'hint'  => 'Approaching the limit of the money column.',
                    ],
                ];
            @endphp

            @foreach ($alerts as $alert)
                <div class="col-12 col-md-3 mb-3">
                    <div class="card h-100 py-3 px-3 {{ $alert['value'] > 0 ? 'border-danger' : '' }}" title="{{ $alert['hint'] }}">
                        <div style="font-size:1.5rem; font-weight:700;"
                             class="{{ $alert['value'] > 0 ? 'text-danger' : 'text-muted' }}">
                            {{ number_format($alert['value']) }}
                        </div>
                        <div class="text-muted" style="font-size:.85rem;">{{ $alert['label'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="row">
            <div class="col-12 col-md-6 mb-4">
                <div class="card h-100 py-3 px-3">
                    <div class="text-muted text-uppercase mb-2" style="font-size:.75rem; letter-spacing:.05em;">Suppliers enabled</div>
                    @if (!empty($health['suppliers']))
                        @foreach ($health['suppliers'] as $supplier)
                            <span class="badge badge-secondary mr-1">{{ $supplier }}</span>
                        @endforeach
                        <a href="{{ url($prefix . '/supplier-health') }}" class="d-block mt-3">Supplier health &amp; balances &rarr;</a>
                    @else
                        <span class="text-muted">None configured.</span>
                    @endif
                </div>
            </div>

            <div class="col-12 col-md-6 mb-4">
                <div class="card h-100 py-3 px-3">
                    <div class="text-muted text-uppercase mb-2" style="font-size:.75rem; letter-spacing:.05em;">Queue</div>
                    @if (is_null($health['queued_jobs'] ?? null))
                        <span class="text-muted">
                            Running inline (QUEUE_CONNECTION=sync) — supplier calls block the approve request.
                        </span>
                    @else
                        <div style="font-size:1.5rem; font-weight:700;">{{ number_format($health['queued_jobs']) }}</div>
                        <div class="text-muted" style="font-size:.85rem;">job(s) waiting to be processed</div>
                    @endif
                </div>
            </div>
        </div>

    </div>

@endsection
