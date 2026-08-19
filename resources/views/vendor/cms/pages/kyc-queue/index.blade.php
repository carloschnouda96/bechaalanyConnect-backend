@extends('cms::layouts/dashboard')

@php
    $prefix = config('hellotree.cms_route_prefix');
    $labels = \App\Models\User::VERIFICATION_STATUS_SLUGS;
    $badge = [
        \App\Models\User::VERIFICATION_PENDING => 'warning',
        \App\Models\User::VERIFICATION_APPROVED => 'success',
        \App\Models\User::VERIFICATION_REJECTED => 'danger',
        \App\Models\User::VERIFICATION_UNSUBMITTED => 'secondary',
    ];
@endphp

@section('breadcrumb')
    <ul class="breadcrumbs list-inline font-weight-bold text-uppercase m-0">
        <li>KYC Review</li>
    </ul>
@endsection

@section('dashboard-content')
    <div class="card mx-lg-5 mx-2 py-4 px-3">

        {{-- Status tabs, pending first. Previously there was no way to see who was
             waiting without scrolling the entire user list. --}}
        <div class="mb-3">
            @foreach ([
                (string) \App\Models\User::VERIFICATION_PENDING => 'Pending',
                (string) \App\Models\User::VERIFICATION_REJECTED => 'Rejected',
                (string) \App\Models\User::VERIFICATION_APPROVED => 'Approved',
                (string) \App\Models\User::VERIFICATION_UNSUBMITTED => 'Not submitted',
                'all' => 'All',
            ] as $value => $label)
                <a href="{{ url($prefix . '/kyc-queue') }}?status={{ $value }}"
                   class="btn btn-sm mr-1 {{ (string) $status === $value ? 'btn-primary' : 'btn-outline-secondary' }}">
                    {{ $label }}
                    @if ($value !== 'all')
                        <span class="badge badge-light ml-1">{{ $counts[(int) $value] ?? 0 }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        <form method="get" class="form-inline mb-3">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="text" name="q" value="{{ $q }}" class="form-control form-control-sm mr-2"
                   placeholder="Search name or email">
            <button class="btn btn-sm btn-secondary">Search</button>
            @if ($q)
                <a href="{{ url($prefix . '/kyc-queue') }}?status={{ $status }}" class="btn btn-sm btn-link">Clear</a>
            @endif
        </form>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Documents</th>
                        <th>Updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        @php
                            $uploaded = collect(['id_front_image', 'id_back_image', 'selfie_image'])
                                ->filter(fn ($c) => filled($user->{$c}))->count();
                        @endphp
                        <tr>
                            <td>{{ $user->username }}</td>
                            <td>{{ $user->email }}</td>
                            <td>{{ $user->phone_number }}</td>
                            <td>
                                <span class="badge badge-{{ $badge[$user->verification_statuses_id] ?? 'secondary' }}">
                                    {{ ucfirst($labels[$user->verification_statuses_id] ?? 'unknown') }}
                                </span>
                            </td>
                            <td>
                                <span class="{{ $uploaded === 3 ? 'text-success' : 'text-muted' }}">{{ $uploaded }}/3</span>
                            </td>
                            <td>{{ optional($user->updated_at)->format('Y-m-d H:i') }}</td>
                            <td class="text-right">
                                <a href="{{ url($prefix . '/kyc-queue/' . $user->id) }}" class="btn btn-sm btn-primary">
                                    Review
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                Nothing here. {{ (string) $status === (string) \App\Models\User::VERIFICATION_PENDING ? 'No verifications are waiting.' : '' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $users->links() }}
    </div>
@endsection
