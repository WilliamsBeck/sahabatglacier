@props([
    'name',                 // nama input, mis. "store_ids[]"
    'options' => [],        // [value => label]
    'selected' => [],       // nilai terpilih
    'label' => null,        // label field (opsional)
    'allLabel' => null,     // bila diisi → ada opsi "Semua" (kosong = semua)
    'placeholder' => 'Pilih…',
    'width' => '260px',
])
@php
    $uid      = 'mcd_' . \Illuminate\Support\Str::random(6);
    $selected = array_map('strval', (array) $selected);
    $labels   = collect($options)->filter(fn($l, $v) => in_array((string) $v, $selected, true))->values();
@endphp

<div {{ $attributes->merge(['class' => 'mcd-wrap']) }}>
    @if($label)
        <label class="form-label fw-semibold small">{{ $label }}</label>
    @endif
    <div class="dropdown" id="{{ $uid }}">
        <button class="btn btn-sm btn-outline-secondary w-100 d-flex justify-content-between align-items-center text-start"
                type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside">
            <span class="mcd-label text-truncate">
                @if(empty($selected))
                    {{ $allLabel ?? $placeholder }}
                @elseif($labels->count() === 1)
                    {{ $labels->first() }}
                @else
                    {{ $labels->count() }} dipilih
                @endif
            </span>
            <i class="bi bi-chevron-down ms-1 flex-shrink-0" style="font-size:.7rem"></i>
        </button>
        <div class="dropdown-menu p-2 shadow-sm"
             style="min-width:{{ $width }};max-height:320px;overflow-y:auto">
            @if($allLabel)
                <div class="form-check mb-1">
                    <input class="form-check-input mcd-all" type="checkbox" id="{{ $uid }}_all"
                           {{ empty($selected) ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="{{ $uid }}_all">{{ $allLabel }}</label>
                </div>
                <hr class="my-1">
            @endif
            @foreach($options as $val => $lbl)
                <div class="form-check">
                    <input class="form-check-input mcd-cb" type="checkbox" name="{{ $name }}"
                           value="{{ $val }}" id="{{ $uid }}_{{ $val }}"
                           {{ in_array((string) $val, $selected, true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="{{ $uid }}_{{ $val }}">{{ $lbl }}</label>
                </div>
            @endforeach
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
// Dropdown multi-checkbox: sinkronkan opsi "Semua" & label tombol.
document.addEventListener('change', function (e) {
    var wrap = e.target.closest('.dropdown');
    if (!wrap || !e.target.classList.contains('form-check-input')) return;

    var all = wrap.querySelector('.mcd-all');
    var cbs = Array.prototype.slice.call(wrap.querySelectorAll('.mcd-cb'));
    if (!cbs.length) return;

    if (all && e.target === all) {
        if (all.checked) cbs.forEach(function (c) { c.checked = false; });
    } else if (e.target.classList.contains('mcd-cb') && all) {
        all.checked = !cbs.some(function (c) { return c.checked; });
    }

    // Perbarui teks tombol
    var picked = cbs.filter(function (c) { return c.checked; });
    var lbl    = wrap.querySelector('.mcd-label');
    if (!lbl) return;
    if (!picked.length) {
        lbl.textContent = all ? (all.nextElementSibling.textContent.trim()) : 'Pilih…';
    } else if (picked.length === 1) {
        lbl.textContent = picked[0].nextElementSibling.textContent.trim();
    } else {
        lbl.textContent = picked.length + ' dipilih';
    }
});
</script>
@endpush
@endonce
