{{--
    One price cell of the grid: Public or a user-type column.

    Expects $column ('public' or a user type id), $cell (VariationPricing::publicCell /
    tierCell), $cells (the row's cells — the public price is quoted by tier cells),
    $cost (float|null) and $fmt. Behaviour lives in _assets.blade.php (pmGrid).

    Cell selects carry `regular-select` so main.js does not turn every one of them into
    a full-width select2 widget (hundreds per page).
--}}
<td class="pm-cell" data-column="{{ $column }}"
    data-orig-mode="{{ $cell['mode'] }}"
    data-orig-value="{{ $fmt($cell['value']) }}"
    data-orig-price="{{ $fmt($cell['price']) }}"
    data-default="{{ $column === 'public' ? $fmt($cell['default']) : '' }}"
    data-public-price="{{ $fmt($cells['public']['price']) }}">
    <div class="d-flex">
        <select class="form-control form-control-sm pm-mode regular-select mr-1" aria-label="Pricing mode">
            <option value="clear" {{ $cell['mode'] === 'clear' || ($column === 'public' && ($cell['inherits_default'] ?? false)) ? 'selected' : '' }}>—</option>
            <option value="fixed" {{ $cell['mode'] === 'fixed' ? 'selected' : '' }}>$</option>
            <option value="percent" {{ $cell['mode'] === 'percent' && !($cell['inherits_default'] ?? false) ? 'selected' : '' }} {{ $cost === null ? 'disabled' : '' }}>%</option>
        </select>
        <input type="number" step="0.0001" class="form-control form-control-sm pm-value" value="{{ $fmt($cell['value']) }}" aria-label="Price value">
    </div>
    <div class="pm-result text-muted"></div>
</td>
