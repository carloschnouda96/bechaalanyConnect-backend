@extends('cms::layouts/dashboard')

@php $prefix = config('hellotree.cms_route_prefix'); @endphp

@section('breadcrumb')
    <ul class="breadcrumbs list-inline font-weight-bold text-uppercase m-0">
        <li>Supplier health</li>
    </ul>
@endsection

@section('dashboard-content')
    <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
        <h5 class="font-weight-bold mb-3">Suppliers</h5>

        @if (empty($suppliers))
            <p class="text-muted mb-0">
                No suppliers are enabled. Each one is switched on with its own
                <code>*_SYNC_ENABLED</code> environment variable plus credentials.
            </p>
        @else
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Credentials</th>
                            <th class="text-right">Wallet balance</th>
                            <th class="text-right">Awaiting supplier</th>
                            <th class="text-right">Never sent</th>
                            <th class="text-right">Failed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($suppliers as $s)
                            <tr>
                                <td class="font-weight-bold">{{ $s['key'] }}</td>
                                <td>
                                    @if ($s['configured'])
                                        <span class="badge badge-success">configured</span>
                                    @else
                                        <span class="badge badge-warning">missing credentials</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if (is_null($s['balance']))
                                        {{-- Never render 0.00 for an unreachable API: that reads as a
                                             real answer and would send someone topping up an account
                                             that is actually fine. --}}
                                        <span class="text-muted" title="The supplier API did not answer. See storage/logs/laravel.log">unavailable</span>
                                    @else
                                        <span class="{{ $s['balance'] <= 0 ? 'text-danger font-weight-bold' : '' }}">
                                            {{ number_format($s['balance'], 2) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="text-right">{{ number_format($s['orders_pending']) }}</td>
                                <td class="text-right {{ $s['orders_unplaced'] > 0 ? 'text-danger font-weight-bold' : 'text-muted' }}">
                                    {{ number_format($s['orders_unplaced']) }}
                                </td>
                                <td class="text-right {{ $s['orders_failed'] > 0 ? 'text-warning' : 'text-muted' }}">
                                    {{ number_format($s['orders_failed']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <small class="text-muted">Balances are cached for 5 minutes.</small>
        @endif
    </div>

    <div class="card mx-lg-5 mx-2 py-4 px-3">
        <h5 class="font-weight-bold mb-1">Approved but never sent to the supplier</h5>
        <p class="text-muted" style="font-size:.9rem;">
            The customer has been charged and the order was approved, but it never reached the
            supplier — most often because the supplier rate-limited us and the job ran out of
            retries. Retrying is safe: fulfillment is idempotent on the order's internal uuid,
            so an order that did get through will not be placed twice.
        </p>

        @if ($unplaced->isEmpty())
            <p class="text-success mb-0">Nothing outstanding — every approved supplier order has been placed.</p>
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Supplier</th>
                            <th class="text-right">Total</th>
                            <th>Placed</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($unplaced as $order)
                            <tr>
                                <td>#{{ $order->id }}</td>
                                <td>{{ optional($order->users)->username ?? '—' }}</td>
                                <td>{{ $order->external_source }}</td>
                                <td class="text-right">{{ number_format((float) $order->total_price, 2) }}</td>
                                <td>{{ optional($order->created_at)->format('Y-m-d H:i') }}</td>
                                <td class="text-right">
                                    <form method="post" action="{{ url($prefix . '/supplier-health/retry/' . $order->id) }}"
                                          onsubmit="return confirm('Send order #{{ $order->id }} to {{ $order->external_source }} now?')">
                                        @csrf
                                        <input type="hidden" name="_method" value="PUT">
                                        <button class="btn btn-sm btn-primary">Retry</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card mx-lg-5 mx-2 py-4 px-3">
        <h5 class="font-weight-bold mb-1">Bycel purchases awaiting a decision</h5>
        <p class="text-muted" style="font-size:.9rem;">
            Bycel's <code>buy_voucher</code> returns no order id, so a purchase is matched to its
            PIN by narrowing down the purchase report. When more than one card could plausibly be
            the customer's — usually because someone also bought in the Bycel app at that moment —
            it refuses to guess rather than risk handing over the wrong code. Check the Bycel app,
            then assign the right row or abandon the purchase to refund the customer.
        </p>

        @if ($bycelReview->isEmpty())
            <p class="text-success mb-0">Nothing waiting — every Bycel purchase resolved on its own.</p>
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Product</th>
                            <th>Bought</th>
                            <th>Candidate rows</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bycelReview as $purchase)
                            @php $candidates = data_get($purchase->report_row, 'candidates', []); @endphp
                            <tr>
                                <td>#{{ $purchase->order_id }}</td>
                                <td>
                                    {{ data_get($purchase->snapshot, 'ProductName', $purchase->product_id) }}
                                    <br><small class="text-muted">{{ $purchase->family }}</small>
                                </td>
                                <td>{{ optional($purchase->created_at)->format('Y-m-d H:i') }}</td>
                                <td>
                                    @forelse ($candidates as $candidate)
                                        <div class="d-flex align-items-center mb-1">
                                            <code class="mr-2">#{{ data_get($candidate, 'MerchantPurchaseId') }}</code>
                                            <small class="text-muted mr-2">
                                                {{ data_get($candidate, 'ProductName') }} ·
                                                PIN ····{{ data_get($candidate, 'PinLast4', '????') }} ·
                                                serial {{ data_get($candidate, 'Serial', '—') }}
                                            </small>
                                            <form method="post"
                                                  action="{{ url($prefix . '/supplier-health/bycel/' . $purchase->id . '/claim/' . data_get($candidate, 'MerchantPurchaseId')) }}"
                                                  onsubmit="return confirm('Give row #{{ data_get($candidate, 'MerchantPurchaseId') }} to order #{{ $purchase->order_id }}? The customer will see this code immediately.')">
                                                @csrf
                                                <input type="hidden" name="_method" value="PUT">
                                                <button class="btn btn-sm btn-outline-primary">Assign</button>
                                            </form>
                                        </div>
                                    @empty
                                        <small class="text-muted">{{ $purchase->resolver_reason ?: 'no candidates recorded' }}</small>
                                    @endforelse
                                </td>
                                <td class="text-right">
                                    <form method="post" action="{{ url($prefix . '/supplier-health/bycel/' . $purchase->id . '/abandon') }}"
                                          onsubmit="return confirm('Confirm nothing was bought for order #{{ $purchase->order_id }}? This refunds the customer.')">
                                        @csrf
                                        <input type="hidden" name="_method" value="PUT">
                                        <button class="btn btn-sm btn-outline-danger">Abandon &amp; refund</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
