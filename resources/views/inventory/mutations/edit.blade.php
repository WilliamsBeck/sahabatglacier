@extends('layouts.app')
@section('title', 'Edit Draft Mutasi')

@push('styles')
<style>
    #editTable { table-layout: fixed; }
    #editTable input[type=number]::-webkit-inner-spin-button,
    #editTable input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    #editTable input[type=number] { -moz-appearance: textfield; }
</style>
@endpush

@section('content')
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h4 class="page-title">Edit Draft Mutasi</h4>
        <span class="font-monospace text-muted small">{{ $mutation->reference_no }}</span>
    </div>
    <a href="{{ route('inventory.mutations.show', $mutation) }}" class="btn btn-outline-secondary btn-sm btn-back">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

<form method="POST" action="{{ route('inventory.mutations.update', $mutation) }}" id="editForm">
    @csrf @method('PUT')

    {{-- ═══════════ HEADER ═══════════ --}}
    <div class="card mb-3">
        <div class="card-header fw-semibold">Informasi Mutasi</div>
        <div class="card-body">
            <div class="row g-3">
                {{-- Tipe (read-only) --}}
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Tipe Mutasi</label>
                    <input type="text" class="form-control bg-light" value="{{ $mutation->type_label }}" readonly>
                </div>

                {{-- Toko Tujuan (read-only) --}}
                @if($mutation->destination_store_id)
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Toko Tujuan</label>
                    <input type="text" class="form-control bg-light" value="{{ $mutation->destinationStore->name ?? '-' }}" readonly>
                </div>
                @endif

                {{-- Toko Asal / Supplier (read-only) --}}
                @if($mutation->source_store_id)
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Toko Asal</label>
                    <input type="text" class="form-control bg-light" value="{{ $mutation->sourceStore->name ?? '-' }}" readonly>
                </div>
                @endif
                @if($mutation->supplier_id)
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Supplier</label>
                    <input type="text" class="form-control bg-light" value="{{ $mutation->supplier->name ?? '-' }}" readonly>
                </div>
                @endif
                @if($mutation->external_sender)
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Pengirim</label>
                    <input type="text" name="external_sender" class="form-control" value="{{ old('external_sender', $mutation->external_sender) }}">
                </div>
                @endif
                @if($mutation->type === 'sale_external_out')
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Penjualan ke</label>
                    <input type="text" name="external_receiver" class="form-control" value="{{ old('external_receiver', $mutation->external_receiver) }}">
                </div>
                @endif

                {{-- No. SJ --}}
                <div class="col-md-3">
                    <label class="form-label fw-semibold">No. SJ / Invoice</label>
                    <input type="text" name="invoice_no" class="form-control"
                           value="{{ old('invoice_no', $mutation->invoice_no) }}" placeholder="Opsional">
                </div>

                {{-- Tanggal Pengiriman --}}
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        {{ $mutation->type === 'opening_stock' ? 'Tanggal Stok' : 'Tanggal Pengiriman' }}
                        <span class="text-danger">*</span>
                    </label>
                    <input type="date" name="transaction_date" id="inputTxDate" class="form-control @error('transaction_date') is-invalid @enderror"
                           value="{{ old('transaction_date', $mutation->transaction_date->format('Y-m-d')) }}" required>
                    <div class="invalid-feedback" id="errTxDate">@error('transaction_date'){{ $message }}@enderror</div>
                </div>

                {{-- Tanggal Penerimaan --}}
                @if($mutation->type !== 'opening_stock')
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        Tanggal Penerimaan
                        <span class="text-danger">*</span>
                        <span class="text-muted fw-normal small">(wajib saat konfirmasi)</span>
                    </label>
                    <input type="date" name="delivery_date" id="inputDelivery" class="form-control @error('delivery_date') is-invalid @enderror"
                           value="{{ old('delivery_date', $mutation->delivery_date?->format('Y-m-d')) }}">
                    <div class="invalid-feedback" id="errDelivery">@error('delivery_date'){{ $message }}@enderror</div>
                    @if(!$mutation->delivery_date)
                    <div class="form-text text-warning"><i class="bi bi-clock me-1"></i>Belum diisi — barang belum diterima.</div>
                    @endif
                </div>
                @endif

                {{-- Catatan --}}
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Catatan</label>
                    <input type="text" name="notes" class="form-control"
                           value="{{ old('notes', $mutation->notes) }}" placeholder="Opsional">
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════ DAFTAR BAHAN ═══════════ --}}
    <div class="card mb-3">
        <div class="card-header fw-semibold">Daftar Bahan ({{ $mutation->items->count() }} item)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="editTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:28%">Bahan</th>
                            <th style="width:18%">Kemasan</th>
                            <th style="width:8%">Dus</th>
                            <th style="width:8%">Pack</th>
                            <th style="width:8%">{{ 'Pcs/Gr' }}</th>
                            <th style="width:20%">Harga / Dus</th>
                            <th style="width:10%" class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($mutation->items as $idx => $item)
                        @php
                            $pkg        = $item->packaging;
                            $ctb        = $pkg ? ($pkg->crate_to_pack * $pkg->pack_to_base) : 0;   // base per dus
                            $ptb        = $pkg ? (float)$pkg->pack_to_base : 0;                     // base per pack
                            // Form menampilkan harga BRUTO (katalog); netto dihitung ulang server
                            // dari diskon invoice saat disimpan.
                            $grossBase  = (float) ($item->gross_price_per_base ?? $item->price_per_base);
                            // Harga/dus: pakai angka asli yang pernah diketik bila ada.
                            // Kalau dihitung ulang dari harga per satuan dasar, hasilnya
                            // bisa meleset 1 rupiah (730.000 -> 729.999) sehingga edit
                            // terlihat "kembali ke angka semula".
                            $priceDus   = $item->price_per_crate !== null
                                ? (int) round($item->price_per_crate)
                                : ($ctb > 0 ? round($grossBase * $ctb) : 0);
                            $subtotal   = $ctb > 0
                                ? round(($item->total_in_base / $ctb) * $priceDus)
                                : round($item->total_in_base * $grossBase);
                        @endphp
                        <tr class="edit-row"
                            id="erow-{{ $idx }}"
                            data-idx="{{ $idx }}"
                            data-ctb="{{ $ctb }}"
                            data-ptb="{{ $ptb }}"
                            data-unit="{{ $item->ingredient->unit_base }}">

                            {{-- Hidden inputs --}}
                            <input type="hidden" name="items[{{ $idx }}][item_id]"        value="{{ $item->id }}">
                            <input type="hidden" name="items[{{ $idx }}][ingredient_id]"  value="{{ $item->ingredient_id }}">
                            <input type="hidden" name="items[{{ $idx }}][packaging_id]"   value="{{ $item->packaging_id }}">
                            <input type="hidden" name="items[{{ $idx }}][price_per_base]" class="price-per-base-hidden"
                                   value="{{ $grossBase }}">
                            {{-- Harga/dus dikirim apa adanya — inilah sumber kebenaran tampilan --}}
                            <input type="hidden" name="items[{{ $idx }}][price_per_crate]" class="price-per-crate-hidden"
                                   value="{{ $ctb > 0 ? $priceDus : '' }}">

                            <td class="fw-semibold">{{ $item->ingredient->name }}</td>
                            <td class="text-muted small">
                                @if($pkg)
                                    {{ $pkg->packaging_name }}
                                    <div class="text-muted" style="font-size:.75rem">
                                        1 Dus = {{ $pkg->crate_to_pack }} Pack × {{ $pkg->pack_to_base }} {{ $item->ingredient->unit_base }}
                                    </div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>

                            <td>
                                @if($ctb > 0)
                                <input type="number" name="items[{{ $idx }}][qty_crate]"
                                       class="form-control form-control-sm qty-input"
                                       value="{{ old('items.'.$idx.'.qty_crate', $item->qty_crate) }}"
                                       min="0" placeholder="0"
                                       oninput="recalcRow({{ $idx }})">
                                @else
                                <span class="text-muted">—</span>
                                <input type="hidden" name="items[{{ $idx }}][qty_crate]" value="0">
                                @endif
                            </td>

                            <td>
                                @if($ptb > 0)
                                <input type="number" name="items[{{ $idx }}][qty_pack]"
                                       class="form-control form-control-sm qty-input"
                                       value="{{ old('items.'.$idx.'.qty_pack', $item->qty_pack) }}"
                                       min="0" placeholder="0"
                                       oninput="recalcRow({{ $idx }})">
                                @else
                                <span class="text-muted">—</span>
                                <input type="hidden" name="items[{{ $idx }}][qty_pack]" value="0">
                                @endif
                            </td>

                            <td>
                                <input type="number" name="items[{{ $idx }}][qty_base]"
                                       class="form-control form-control-sm qty-input"
                                       value="{{ old('items.'.$idx.'.qty_base', $item->qty_base) }}"
                                       step="0.01" min="0" placeholder="0"
                                       oninput="recalcRow({{ $idx }})">
                            </td>

                            <td>
                                @if($ctb > 0)
                                {{-- Show price per dus; hidden price_per_base auto-calculated --}}
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">Rp</span>
                                    <input type="number" class="form-control form-control-sm price-dus-input"
                                           value="{{ $priceDus }}"
                                           min="0" step="1" placeholder="0"
                                           oninput="onPriceDusChange({{ $idx }})">
                                </div>
                                <div class="form-text text-muted" style="font-size:.72rem">per dus</div>
                                @else
                                {{-- No packaging: show price per base unit directly --}}
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">Rp</span>
                                    <input type="number" class="form-control form-control-sm price-dus-input"
                                           value="{{ round($grossBase) }}"
                                           min="0" step="0.01" placeholder="0"
                                           oninput="onPriceDusChange({{ $idx }})">
                                </div>
                                <div class="form-text text-muted" style="font-size:.72rem">per {{ $item->ingredient->unit_base }}</div>
                                @endif
                            </td>

                            <td class="text-end fw-semibold td-subtotal" id="sub-{{ $idx }}">
                                Rp {{ number_format($subtotal, 0, ',', '.') }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="6" class="text-end fw-bold">Grand Total</td>
                            <td class="text-end fw-bold text-success" id="grandTotal">
                                Rp {{ number_format($mutation->items->sum(fn($i) => $i->total_in_base * (float)($i->gross_price_per_base ?? $i->price_per_base)), 0, ',', '.') }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    @if(in_array($mutation->type, ['purchase_zhisheng', 'purchase_supplier']))
    {{-- ═══════════ DISKON INVOICE ═══════════ --}}
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row align-items-center g-2">
                <div class="col-md-5">
                    <label class="form-label fw-semibold mb-0">Diskon Total Invoice (Rp)</label>
                    <div class="form-text mt-0">Diskon akumulasi satu nota — otomatis dialokasikan proporsional; harga stok tercatat netto.</div>
                </div>
                <div class="col-md-4">
                    <input type="text" id="discountInput" class="form-control text-end" inputmode="numeric" placeholder="0"
                           value="{{ (float) old('discount_amount', $mutation->discount_amount) > 0 ? number_format((float) old('discount_amount', $mutation->discount_amount), 0, ',', '.') : '' }}">
                    <input type="hidden" name="discount_amount" id="discountHidden"
                           value="{{ old('discount_amount', (float) $mutation->discount_amount) }}">
                </div>
            </div>
            @error('discount_amount')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>
    </div>
    <script>
        document.addEventListener('input', function(e) {
            if (e.target.id !== 'discountInput') return;
            var raw = e.target.value.replace(/[^0-9]/g, '');
            e.target.value = raw ? Number(raw).toLocaleString('id-ID') : '';
            document.getElementById('discountHidden').value = raw || 0;
        });
    </script>
    @endif

    {{-- ═══════════ ACTION BUTTONS ═══════════ --}}
    <div class="d-flex gap-2 flex-wrap">
        <button type="submit" name="action" value="save_draft" class="btn btn-primary px-4">
            <i class="bi bi-save me-1"></i> Simpan Draft
        </button>
        <button type="submit" name="action" value="confirm" class="btn btn-success px-4"
                data-confirm="Konfirmasi mutasi ini? Stok akan langsung diupdate dan tidak bisa diubah lagi." data-confirm-type="info" data-confirm-ok="Ya, konfirmasi">
            <i class="bi bi-check-circle me-1"></i> Konfirmasi & Update Stok
        </button>
        <a href="{{ route('inventory.mutations.show', $mutation) }}" class="btn btn-outline-secondary px-4">
            Batal
        </a>
    </div>
</form>
@endsection

@push('scripts')
<script>
// ── Recalc subtotal for one row ─────────────────────────────────────────────
function recalcRow(idx) {
    var row  = document.getElementById('erow-' + idx);
    if (!row) return;
    var ctb  = parseFloat(row.dataset.ctb) || 0;
    var ptb  = parseFloat(row.dataset.ptb) || 0;
    var qtyC = parseFloat(row.querySelector('input[name$="[qty_crate]"]')?.value) || 0;
    var qtyP = parseFloat(row.querySelector('input[name$="[qty_pack]"]')?.value)  || 0;
    var qtyB = parseFloat(row.querySelector('input[name$="[qty_base]"]')?.value)  || 0;

    var totalBase = (qtyC * ctb) + (qtyP * ptb) + qtyB;
    var priceBase = parseFloat(row.querySelector('.price-per-base-hidden')?.value) || 0;
    var subtotal  = Math.round(totalBase * priceBase);

    var tdSub = document.getElementById('sub-' + idx);
    if (tdSub) tdSub.textContent = 'Rp ' + subtotal.toLocaleString('id-ID');

    updateGrandTotal();
}

// ── Called when price-per-dus input changes ─────────────────────────────────
function onPriceDusChange(idx) {
    var row    = document.getElementById('erow-' + idx);
    if (!row) return;
    var ctb    = parseFloat(row.dataset.ctb) || 0;
    var priceDus = parseFloat(row.querySelector('.price-dus-input')?.value) || 0;
    var priceBase = ctb > 0 ? priceDus / ctb : priceDus;  // if no packaging, treat as price_per_base

    var hidden = row.querySelector('.price-per-base-hidden');
    if (hidden) hidden.value = priceBase.toFixed(8);

    // Kirim juga harga/dus persis seperti yang diketik (tanpa konversi)
    var hiddenCrate = row.querySelector('.price-per-crate-hidden');
    if (hiddenCrate) hiddenCrate.value = ctb > 0 ? priceDus : '';

    recalcRow(idx);
}

// ── Grand total ──────────────────────────────────────────────────────────────
function updateGrandTotal() {
    var total = 0;
    document.querySelectorAll('.td-subtotal').forEach(function(td) {
        var txt = td.textContent.replace(/[^\d]/g, '');
        total += parseInt(txt) || 0;
    });
    var el = document.getElementById('grandTotal');
    if (el) el.textContent = 'Rp ' + total.toLocaleString('id-ID');
}

// ── Date validation ──────────────────────────────────────────────────────────
function validateDates() {
    var txEl  = document.getElementById('inputTxDate');
    var delEl = document.getElementById('inputDelivery');
    if (!txEl || !delEl) return true;

    txEl.classList.remove('is-invalid');
    delEl.classList.remove('is-invalid');
    document.getElementById('errTxDate').textContent  = '';
    document.getElementById('errDelivery').textContent = '';

    if (txEl.value && delEl.value && delEl.value < txEl.value) {
        delEl.classList.add('is-invalid');
        document.getElementById('errDelivery').textContent =
            'Tanggal penerimaan tidak boleh lebih awal dari tanggal pengiriman.';
        return false;
    }
    return true;
}

var txEl  = document.getElementById('inputTxDate');
var delEl = document.getElementById('inputDelivery');
if (txEl)  txEl.addEventListener('change',  validateDates);
if (delEl) delEl.addEventListener('change', validateDates);

// ── Submit handler ───────────────────────────────────────────────────────────
document.getElementById('editForm').addEventListener('submit', function(e) {
    if (!validateDates()) {
        e.preventDefault();
        e.stopImmediatePropagation(); // prevent confirm() on the "confirm" button from re-firing
        return false;
    }
});
</script>
@endpush
