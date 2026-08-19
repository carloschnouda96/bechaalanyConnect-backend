@extends('cms::layouts/dashboard')

@php
    $prefix = config('hellotree.cms_route_prefix');
    $labels = \App\Models\User::VERIFICATION_STATUS_SLUGS;
@endphp

@section('breadcrumb')
    <ul class="breadcrumbs list-inline font-weight-bold text-uppercase m-0">
        <li><a href="{{ url($prefix . '/kyc-queue') }}">KYC Review</a></li>
        <li>{{ $user->username }}</li>
    </ul>
@endsection

@section('dashboard-content')
    <div class="card mx-lg-5 mx-2 py-4 px-4">

        <div class="row mb-4">
            <div class="col-md-6">
                <h5 class="font-weight-bold">{{ $user->username }}</h5>
                <div class="text-muted">{{ $user->email }}</div>
                <div class="text-muted">{{ $user->phone_number }} &middot; {{ $user->country }}</div>
            </div>
            <div class="col-md-6 text-md-right">
                <div class="text-muted">Current status</div>
                <div style="font-size:1.25rem; font-weight:700;">
                    {{ ucfirst($labels[$user->verification_statuses_id] ?? 'unknown') }}
                </div>
            </div>
        </div>

        {{-- Side by side, so the selfie can be compared against the ID without
             navigating between records.

             Each image is streamed by KycController::document from the PRIVATE disk
             through this admin-guarded route. They are deliberately NOT public URLs:
             these are government ID photos, and they used to be served to anyone who
             had the link. --}}
        <div class="row">
            @foreach ($slots as $slot => $column)
                <div class="col-md-4 mb-4">
                    <div class="text-muted text-uppercase mb-2" style="font-size:.75rem; letter-spacing:.05em;">
                        {{ str_replace('-', ' ', $slot) }}
                    </div>

                    @if (filled($user->{$column}))
                        <a href="{{ url($prefix . '/kyc-queue/' . $user->id . '/document/' . $slot) }}" target="_blank" rel="noopener">
                            <img src="{{ url($prefix . '/kyc-queue/' . $user->id . '/document/' . $slot) }}"
                                 alt="{{ str_replace('-', ' ', $slot) }} document for {{ $user->username }}"
                                 class="img-fluid border rounded" style="max-height:320px;">
                        </a>
                        <div class="mt-1"><small class="text-muted">Click to open full size</small></div>
                    @else
                        <div class="border rounded d-flex align-items-center justify-content-center text-muted"
                             style="height:220px;">
                            Not uploaded
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <hr>

        <form method="post" action="{{ url($prefix . '/kyc-queue/' . $user->id) }}">
            @csrf
            <input type="hidden" name="_method" value="PUT">

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="form-group">
                <label for="verification_statuses_id" class="font-weight-bold">Decision</label>
                <select name="verification_statuses_id" id="verification_statuses_id" class="form-control" required>
                    <option value="{{ \App\Models\User::VERIFICATION_APPROVED }}">Approve</option>
                    <option value="{{ \App\Models\User::VERIFICATION_REJECTED }}">Reject</option>
                    <option value="{{ \App\Models\User::VERIFICATION_PENDING }}">Leave pending</option>
                </select>
            </div>

            <div class="form-group">
                <label for="rejection_reason" class="font-weight-bold">Reason (required when rejecting)</label>
                <textarea name="rejection_reason" id="rejection_reason" rows="3" class="form-control"
                          placeholder="e.g. The back of the ID is cut off — please re-take the photo showing all four corners.">{{ old('rejection_reason', $user->rejection_reason) }}</textarea>
                <small class="text-muted">
                    Sent to the applicant in the rejection email. Without it they are only told
                    &ldquo;rejected&rdquo; and usually resubmit the same documents.
                </small>
            </div>

            <button type="submit" class="btn btn-primary">Save decision</button>
            <a href="{{ url($prefix . '/kyc-queue') }}" class="btn btn-link">Back to queue</a>
        </form>
    </div>
@endsection
