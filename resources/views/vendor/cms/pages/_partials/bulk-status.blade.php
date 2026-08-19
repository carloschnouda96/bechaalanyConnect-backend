{{--
    Bulk approve / reject.

    The vendor CMS ships exactly one bulk action — Bulk Delete — so approving a day's
    orders meant opening each record, changing one dropdown, saving, and going back.
    For a top-up business that is the single biggest time cost in the admin.

    Mechanics deliberately mirror the vendor's bulk-delete (main.js:296-322): the same
    `.delete-checkbox input` checkboxes select rows, and the ids are submitted as one
    comma-separated field, which the controller explodes. Ids are read straight from
    the DOM at submit time rather than from main.js's private `idsToDelete` array,
    which is not reachable from here.

    METHOD IS PUT, NOT POST, on purpose: AdminMiddleware (:146-165) maps POST to the
    `add` permission and PUT to `edit`, so a POST here would wrongly demand create
    rights to change a status.

    Expects: $bulkStatusUrl, $bulkStatuses (id => label), and $page.
--}}
@if (($page['edit'] ?? true) || !request()->get('admin')['admin_role_id'])
    @if (request()->get('admin')['cms_pages'][$page['route']]['permissions']['edit'] ?? true)
        <form method="post" action="{{ $bulkStatusUrl }}"
              class="d-block d-md-inline-block bulk-status"
              onsubmit="return window.__bulkStatusSubmit(this)">
            @csrf
            <input type="hidden" name="_method" value="PUT">
            <input type="hidden" name="ids" value="">

            <div class="d-inline-flex align-items-center">
                <select name="statuses_id" class="form-control form-control-sm mr-2" style="width:auto;" required>
                    <option value="">Set selected to…</option>
                    @foreach ($bulkStatuses as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-success btn-sm">Apply</button>
            </div>
        </form>

        {{-- Inlined rather than @push'd: the vendor layout exposes @yield('scripts'),
             not a @stack, and a @section here would collide with the page's own.
             The script only defines a function on window, and the form reads it at
             submit time, so parse order is irrelevant. --}}
        @once
                <script>
                    // Collects the checked rows and blocks submission when nothing is
                    // selected — without this the form would post an empty id list and
                    // silently do nothing, which reads as "the button is broken".
                    window.__bulkStatusSubmit = function (form) {
                        var ids = [];
                        document.querySelectorAll('.delete-checkbox input:checked').forEach(function (input) {
                            if (input.value) ids.push(input.value);
                        });

                        if (!ids.length) {
                            alert('Select at least one row first.');
                            return false;
                        }

                        var status = form.querySelector('[name="statuses_id"]');
                        if (!status.value) {
                            alert('Choose a status to apply.');
                            return false;
                        }

                        form.querySelector('[name="ids"]').value = ids.join(',');

                        return confirm(
                            'Apply "' + status.options[status.selectedIndex].text +
                            '" to ' + ids.length + ' record(s)?\n\n' +
                            'Credits will be refunded or re-charged where the status change requires it.'
                        );
                    };
                </script>
        @endonce
    @endif
@endif
