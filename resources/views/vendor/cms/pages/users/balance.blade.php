@extends('cms::layouts/dashboard')

@php
    $prefix = config('hellotree.cms_route_prefix');
@endphp

@section('breadcrumb')
    <ul class="breadcrumbs list-inline font-weight-bold text-uppercase m-0">
        <li><a href="{{ url($prefix . '/users') }}">Users</a></li>
        <li>{{ $user->username }}</li>
        <li>Balance</li>
    </ul>
@endsection

@section('dashboard-content')
    <div class="card mx-lg-5 mx-2 py-4 px-4 mb-4">
        <div class="text-muted">Current balance</div>
        <div style="font-size:1.5rem; font-weight:700;">{{ number_format((float) $user->credits_balance, 2) }}</div>

        <form method="post" action="{{ url($prefix . '/users/' . $user->id . '/balance') }}" class="mt-4">
            @csrf
            <input type="hidden" name="_method" value="PUT">
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="form-group">
                <label for="amount">Amount</label>
                <input id="amount" name="amount" class="form-control" required inputmode="decimal" value="{{ old('amount') }}" placeholder="10.00 to add, -10.00 to remove">
                @error('amount')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <label for="reason">Reason</label>
                <textarea id="reason" name="reason" class="form-control" required maxlength="500" rows="2">{{ old('reason') }}</textarea>
                @error('reason')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>

            <button type="submit" class="btn btn-primary" onclick="return confirm('Change this customer\'s balance?')">Apply adjustment</button>
        </form>
    </div>

    <div class="card mx-lg-5 mx-2 py-4 px-4">
        <h6 class="font-weight-bold mb-3">Recent balance changes</h6>
        @if ($entries->isEmpty())
            <div class="text-muted">No balance changes yet.</div>
        @else
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr><th>Date</th><th>Change</th><th>Balance after</th><th>Type</th><th>By</th><th>Reason</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td>{{ $entry->created_at }}</td>
                                <td>{{ number_format((float) $entry->amount, 2) }}</td>
                                <td>{{ number_format((float) $entry->balance_after, 2) }}</td>
                                <td>{{ str_replace('_', ' ', $entry->reason) }}</td>
                                <td>{{ $entry->actor_type }}{{ $entry->actor_id ? ' #' . $entry->actor_id : '' }}</td>
                                <td>{{ $entry->meta['reason'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
