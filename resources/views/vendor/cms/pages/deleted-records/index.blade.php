@extends('cms::layouts/dashboard')

@php $prefix = config('hellotree.cms_route_prefix'); @endphp

@section('breadcrumb')
    <ul class="breadcrumbs list-inline font-weight-bold text-uppercase m-0">
        <li>Deleted records</li>
    </ul>
@endsection

@section('dashboard-content')
    <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
        <h5 class="font-weight-bold mb-1">What this page is</h5>
        <p class="text-muted mb-0" style="font-size:.9rem;">
            Deleting an order, a credit transfer or a user does not remove the row — the money that
            moved through it has to stay on record. The row is only marked deleted and hidden from
            every other page, which is why a deleted order can still stop you from deleting the
            product variation it was for. Everything hidden that way is listed here.
            <strong>Restore</strong> puts a record back exactly as it was, without moving any
            credits. <strong>Delete permanently</strong> destroys it for good.
            Only the {{ $limit }} most recently deleted of each kind are shown.
        </p>
    </div>

    <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
        <h5 class="font-weight-bold mb-3">Deleted orders</h5>

        @if ($orders->isEmpty())
            <p class="text-muted mb-0">No deleted orders.</p>
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Product variation</th>
                            <th>Status</th>
                            <th class="text-right">Total</th>
                            <th>Deleted</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td>#{{ $order->id }}</td>
                                <td>{{ optional($order->users)->username ?? '—' }}</td>
                                <td>
                                    {{ optional($order->product_variation)->name ?? '—' }}
                                    <br><small class="text-muted">#{{ $order->product_variation_id }}</small>
                                </td>
                                <td>{{ optional($order->statuses)->title ?? '—' }}</td>
                                <td class="text-right">{{ number_format((float) $order->total_price, 2) }}</td>
                                <td>{{ optional($order->deleted_at)->format('Y-m-d H:i') }}</td>
                                <td class="text-right text-nowrap">
                                    @include('cms::pages/deleted-records/_actions', [
                                        'prefix' => $prefix,
                                        'type' => 'orders',
                                        'id' => $order->id,
                                        'label' => 'order #' . $order->id,
                                    ])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
        <h5 class="font-weight-bold mb-3">Deleted credit transfers</h5>

        @if ($transfers->isEmpty())
            <p class="text-muted mb-0">No deleted credit transfers.</p>
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Transfer</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th class="text-right">Amount</th>
                            <th>Deleted</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transfers as $transfer)
                            <tr>
                                <td>#{{ $transfer->id }}</td>
                                <td>{{ optional($transfer->users)->username ?? '—' }}</td>
                                <td>{{ optional($transfer->statuses)->title ?? '—' }}</td>
                                <td class="text-right">{{ number_format((float) $transfer->amount, 2) }}</td>
                                <td>{{ optional($transfer->deleted_at)->format('Y-m-d H:i') }}</td>
                                <td class="text-right text-nowrap">
                                    @include('cms::pages/deleted-records/_actions', [
                                        'prefix' => $prefix,
                                        'type' => 'credits-transfer',
                                        'id' => $transfer->id,
                                        'label' => 'credit transfer #' . $transfer->id,
                                    ])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card mx-lg-5 mx-2 py-4 px-3">
        <h5 class="font-weight-bold mb-1">Deleted users</h5>
        <p class="text-muted" style="font-size:.9rem;">
            A deleted account is already locked out — it cannot sign in or spend its balance. An
            account that ever moved credits cannot be destroyed permanently, because its ledger
            entries are an immutable record.
        </p>

        @if ($users->isEmpty())
            <p class="text-muted mb-0">No deleted users.</p>
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th class="text-right">Credits balance</th>
                            <th>Deleted</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr>
                                <td>#{{ $user->id }}</td>
                                <td>{{ $user->username }}</td>
                                <td>{{ $user->email }}</td>
                                <td class="text-right">{{ number_format((float) $user->credits_balance, 2) }}</td>
                                <td>{{ optional($user->deleted_at)->format('Y-m-d H:i') }}</td>
                                <td class="text-right text-nowrap">
                                    @include('cms::pages/deleted-records/_actions', [
                                        'prefix' => $prefix,
                                        'type' => 'users',
                                        'id' => $user->id,
                                        'label' => 'user #' . $user->id,
                                    ])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
