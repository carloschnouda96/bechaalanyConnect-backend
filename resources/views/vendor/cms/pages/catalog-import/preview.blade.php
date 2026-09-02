@extends('cms::layouts/dashboard')

@php
    $prefix = config('hellotree.cms_route_prefix');
    $badge = [
        'create' => 'badge-success',
        'update' => 'badge-primary',
        'unchanged' => 'badge-secondary',
        'skipped' => 'badge-warning',
        'error' => 'badge-danger',
    ];
    $willWrite = $summary['create'] + $summary['update'];
@endphp

@section('breadcrumb')
    <ul class="breadcrumbs list-inline font-weight-bold text-uppercase m-0">
        <li><a href="{{ url($prefix . '/catalog-import') }}">Catalog import</a></li>
        <li>Preview</li>
    </ul>
@endsection

@section('dashboard-content')
    <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
        <h5 class="font-weight-bold mb-3">Nothing has been saved yet</h5>

        @if (count($errors_found))
            <div class="alert alert-warning">
                <ul class="mb-0 pl-3">
                    @foreach ($errors_found as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="mb-3">
            <span class="badge badge-success">{{ $summary['create'] }} to create</span>
            <span class="badge badge-primary">{{ $summary['update'] }} to update</span>
            <span class="badge badge-secondary">{{ $summary['unchanged'] }} unchanged</span>
            <span class="badge badge-warning">{{ $summary['skipped'] }} skipped</span>
            <span class="badge badge-danger">{{ $summary['error'] }} with errors</span>
        </p>

        <form method="post" action="{{ url($prefix . '/catalog-import') }}" class="d-inline-block"
              onsubmit="return confirm('Apply {{ $willWrite }} change(s) to the catalog?')">
            @csrf
            <input type="hidden" name="path" value="{{ $path }}">
            <input type="hidden" name="hash" value="{{ $hash }}">
            <button type="submit" class="btn btn-primary btn-sm" {{ $willWrite ? '' : 'disabled' }}>
                Apply {{ $willWrite }} change(s)
            </button>
        </form>
        <a href="{{ url($prefix . '/catalog-import') }}" class="btn btn-secondary btn-sm">Cancel</a>

        @if ($summary['error'])
            <p class="text-danger mt-3 mb-0">
                Rows with errors are skipped. Fix them in the file and upload it again if they matter.
            </p>
        @endif
    </div>

    <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
        <h5 class="font-weight-bold mb-3">Row by row</h5>

        @if (empty($plan))
            <p class="text-muted mb-0">The file contained no data rows.</p>
        @else
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Line</th>
                            <th>Product</th>
                            <th>Variation</th>
                            <th>Action</th>
                            <th>What changes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($plan as $entry)
                            <tr>
                                <td>{{ $entry['line'] }}</td>
                                <td>{{ $entry['product_slug'] }}</td>
                                <td>{{ $entry['variation_slug'] }}</td>
                                <td>
                                    <span class="badge {{ $badge[$entry['action']] ?? 'badge-secondary' }}">
                                        {{ $entry['action'] }}
                                    </span>
                                </td>
                                <td>
                                    @if ($entry['reason'])
                                        <span class="text-muted">{{ $entry['reason'] }}</span>
                                    @elseif (empty($entry['changes']))
                                        <span class="text-muted">&mdash;</span>
                                    @else
                                        @foreach ($entry['changes'] as $side => $fields)
                                            @foreach ($fields as $field => $pair)
                                                <div>
                                                    <strong>{{ $field }}</strong>
                                                    <span class="text-muted">{{ $pair[0] === null || $pair[0] === '' ? '(empty)' : $pair[0] }}</span>
                                                    &rarr;
                                                    {{ $pair[1] === null || $pair[1] === '' ? '(empty)' : $pair[1] }}
                                                </div>
                                            @endforeach
                                        @endforeach
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
