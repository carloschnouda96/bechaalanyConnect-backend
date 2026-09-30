{{-- "Sync now" for one supplier. Expects $key. PUT: AdminMiddleware maps it to `edit`. --}}
<form method="post" action="{{ url(config('hellotree.cms_route_prefix') . '/supplier-health/sync/' . $key) }}" class="d-inline"
      onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Syncing…';">
    @csrf
    @method('PUT')
    <button class="btn btn-sm btn-outline-primary">Sync {{ $key }} now</button>
</form>
