{{--
    Restore is PUT and purge is DELETE, not POST: AdminMiddleware maps POST to the `add`
    permission, PUT to `edit` and DELETE to `delete`, so a POST would demand create
    rights to put a record back.
--}}
<form method="post" action="{{ url($prefix . '/deleted-records/' . $type . '/' . $id . '/restore') }}"
      class="d-inline-block"
      onsubmit="return confirm('Restore {{ $label }}?')">
    @csrf
    <input type="hidden" name="_method" value="PUT">
    <button class="mb-2 btn btn-sm btn-outline-primary">Restore</button>
</form>
<form method="post" action="{{ url($prefix . '/deleted-records/' . $type . '/' . $id) }}"
      class="d-inline-block"
      onsubmit="return confirm('Permanently delete {{ $label }}? This cannot be undone.')">
    @csrf
    <input type="hidden" name="_method" value="DELETE">
    <button class="mb-2 btn btn-sm btn-danger">Delete permanently</button>
</form>
