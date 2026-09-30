{{--
    Product create/edit — the vendor form (cms::pages/cms-page/form), reordered so it
    reads top to bottom the way a product is set up: name and description first, then
    where it is sold, with the auto-generated slug last. On edit, the Variations card
    (_variations.blade.php) follows the form; its data comes from
    Cms\ProductEditorController::edit().

    Every registered field is still rendered: the vendor update() writes null for any
    non-checkbox field missing from the request.
--}}
@extends('cms::layouts/dashboard')

@php
    $prefix = config('hellotree.cms_route_prefix');

    // Order of the non-translated fields. Anything not listed keeps its schema order
    // after these; the slug always goes last.
    $preferred = ['subcategory_id', 'product_type_id', 'image', 'is_active', 'related_products'];
    $byName = collect($page_fields)->keyBy('name');
    $ordered = collect($preferred)->filter(fn ($name) => $byName->has($name))->map(fn ($name) => $byName[$name])
        ->merge(collect($page_fields)->reject(fn ($f) => in_array($f['name'], $preferred, true) || $f['name'] === 'slug'))
        ->merge($byName->has('slug') ? [$byName['slug']] : [])
        ->values();

    $supplierSource = isset($row) ? $row['external_source'] : null;
@endphp

@section('breadcrumb')
    <ul class="breadcrumbs list-inline font-weight-bold text-uppercase m-0">
        <li><a href="{{ url($prefix . '/' . $page['route']) }}">{{ $page['display_name_plural'] }}</a></li>
        @if (isset($row))
            <li>{{ $row['id'] }}</li>
            <li>Edit</li>
        @else
            <li>Create</li>
        @endif
    </ul>
@endsection

@section('dashboard-content')
    <style>
        .pe-section-title { font-size: .8rem; letter-spacing: .04em; }
    </style>

    <form method="post" enctype="multipart/form-data" action="{{ isset($row) ? url($prefix . '/' . $page['route'] . '/' . $row['id'] . ($appends_to_query ?? '')) : url($prefix . '/' . $page['route']) }}" ajax>

        <div class="card p-4 mx-2 mx-sm-5 mb-4">
            <p class="font-weight-bold text-uppercase mb-3">{{ isset($row) ? 'Edit ' . $page['display_name'] . ' #' . $row['id'] : 'Add ' . $page['display_name'] }}</p>

            @if (isset($row))
                @method('put')
            @endif

            {{-- No error list here: this form saves over ajax and shows its validation
                 errors in the CMS toast. Redirect-based errors come from the Variations
                 card and are listed there. --}}

            @if ($supplierSource)
                <div class="alert alert-info">
                    Imported from <strong>{{ $supplierSource }}</strong>
                    @if ($row['supplier_status'])
                        &middot; supplier status <strong>{{ str_replace('_', ' ', $row['supplier_status']) }}</strong>
                    @endif
                    . Its variations and costs come from the supplier sync; prices follow cost &times; profit %.
                    Name, description, category, image and <strong>Is Active</strong> are yours to edit.
                </div>
            @elseif (!isset($row))
                <p class="text-muted">Fill in the product, then save — you will land on its page to add the amounts it is sold in (its variations) and their prices.</p>
            @endif

            <input type="hidden" name="draft_cms_field" id="draftField" value="0">

            @if (count($page_translatable_fields))
                <p class="pe-section-title font-weight-bold text-uppercase text-muted mb-2">Name &amp; description</p>
                @foreach (\Hellotreedigital\Cms\Models\Language::get() as $language)
                    <div class="form-group">
                        <label>{{ $language->title }}</label>
                        <div class="pl-3">
                            @foreach ($page_translatable_fields as $field)
                                @include('cms::pages/cms-page/form-fields', ['locale' => $language->slug])
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif

            <p class="pe-section-title font-weight-bold text-uppercase text-muted mb-2 mt-2">Where and how it is sold</p>
            @foreach ($ordered as $field)
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
                <button type="submit" class="btn btn-sm btn-primary submit-draft-button">{{ isset($row) ? 'Save product' : 'Save and add variations' }}</button>
                @if ($page['single_record'] == 0)
                    <button type="submit" class="btn btn-secondary save-as-draft-button">Save As Draft</button>
                @endif
            </div>
        </div>

    </form>

    @if (isset($row, $editor))
        @include('cms::pages/products/_variations')
    @endif
@endsection
