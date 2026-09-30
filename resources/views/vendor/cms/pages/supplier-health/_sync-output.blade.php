{{-- Result of the last "Sync now" (SupplierHealthController::sync), shown once. --}}
@if (session('supplier_sync_output'))
    @php $sync = session('supplier_sync_output'); @endphp
    <div class="alert {{ $sync['ok'] ? 'alert-success' : 'alert-danger' }} mx-lg-5 mx-2">
        <strong>{{ ucfirst($sync['key']) }} sync {{ $sync['ok'] ? 'finished' : 'failed' }}.</strong>
        @if ($sync['text'] !== '')
            <pre class="mb-0 mt-2" style="white-space: pre-wrap; font-size: 12px;">{{ $sync['text'] }}</pre>
        @endif
    </div>
@endif
@if ($errors->has('sync'))
    <div class="alert alert-danger mx-lg-5 mx-2">{{ $errors->first('sync') }}</div>
@endif
