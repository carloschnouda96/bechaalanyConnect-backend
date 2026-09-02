{{--
    The receipt, streamed off the private disk.

    The vendor's show-fields image component renders Storage::url($image) against the
    DEFAULT disk (public). Receipts are written to the private disk, so that URL points
    at public/storage/receipts/ — which does not exist — and the reviewer sees a broken
    image on the one screen where a top-up is approved. Same fix as the KYC queue's
    document route.
--}}
@php
    $receiptUrl = url(config('hellotree.cms_route_prefix') . '/credits-transfer/' . $row['id'] . '/receipt');
@endphp
<div class="mb-4">
    <label class="font-weight-bold mb-3">Receipt Image</label>
    <div class="pl-3">
        @if ($row['receipt_image'])
            <a href="{{ $receiptUrl }}" target="_blank" rel="noopener">
                <img class="img-thumbnail" src="{{ $receiptUrl }}" style="max-height:520px">
            </a>
            <p class="mt-2 mb-0">
                <a href="{{ $receiptUrl }}" target="_blank" rel="noopener">Open full size</a>
            </p>
        @else
            <p class="m-0">No receipt uploaded</p>
        @endif
    </div>
    <hr>
</div>
