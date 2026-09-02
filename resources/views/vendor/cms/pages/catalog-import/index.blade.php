@extends('cms::layouts/dashboard')

@php $prefix = config('hellotree.cms_route_prefix'); @endphp

@section('breadcrumb')
    <ul class="breadcrumbs list-inline font-weight-bold text-uppercase m-0">
        <li>Catalog import</li>
    </ul>
@endsection

@section('dashboard-content')
    <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
        <h5 class="font-weight-bold mb-3">1 &middot; Download the current catalog</h5>
        <p class="text-muted">
            One row per variation, with the product's columns repeated. Edit it in Excel or
            Google Sheets and upload it back — the columns are identical in both directions.
        </p>
        <div>
            <a href="{{ url($prefix . '/catalog-import/export') }}" class="btn btn-secondary btn-sm">
                <i class="fa fa-download" aria-hidden="true"></i> Download catalog CSV
            </a>
        </div>
    </div>

    <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
        <h5 class="font-weight-bold mb-3">2 &middot; Upload your edited file</h5>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0 pl-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="text-muted">
            Nothing is saved yet. You will see exactly what the file would change, row by
            row, and can then apply it or walk away.
        </p>

        <form method="post" action="{{ url($prefix . '/catalog-import/preview') }}" enctype="multipart/form-data">
            @csrf
            <div class="form-group">
                <input type="file" name="file" accept=".csv,text/csv" required>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Preview changes</button>
        </form>
    </div>

    <div class="card mx-lg-5 mx-2 py-4 px-3 mb-4">
        <h5 class="font-weight-bold mb-3">What the import will and will not touch</h5>
        <ul class="text-muted mb-3">
            <li>
                <strong>Supplier products are never modified.</strong> Anything with an
                <code>external_source</code> is owned by its supplier sync: its name and cost come
                from the supplier's feed and its selling price is recomputed from cost &times;
                profit&nbsp;%. Those rows are reported as skipped. To change their price, edit the
                product's <strong>profit&nbsp;%</strong> instead.
            </li>
            <li>Rows are matched on <code>product_slug</code> and <code>variation_slug</code>. An unknown slug creates a new record.</li>
            <li>A blank cell means "leave this as it is", not "clear it".</li>
            <li>Images are not part of the CSV — upload those on the Products page as usual.</li>
        </ul>

        <h6 class="font-weight-bold">Columns</h6>
        <p class="text-muted mb-0"><code>{{ implode(', ', $header) }}</code></p>
    </div>
@endsection
