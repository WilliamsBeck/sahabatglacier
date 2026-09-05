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
                            <th style="width:28%">Bahan <span class="text-danger">*</span></th>
                            <th style="width:18%">Kemasan</th>
                            <th style="width:8%">Dus</th>
                            <th style="width:8%">Pack</th>
                            <th style="width:8%">Pcs/Gr</th>
                            <th style="width:25%">Harga / Dus</th>
                            <th style="width:5%"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
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
                            <input type="hidden" name="items[{{ $idx }}][price_per_base]" class="price-per-base-hidden"
                                   value="{{ $grossBase }}">
                            {{-- Harga/dus dikirim apa adanya — inilah sumber kebenaran tampilan --}}
                            <input type="hidden" name="items[{{ $idx }}][price_per_crate]" class="price-per-crate-hidden"
                                   value="{{ $ctb > 0 ? $priceDus : '' }}">

                            {{-- Bahan & kemasan bisa diganti, sama seperti form Buat Mutasi --}}
                            <td>
                                <select name="items[{{ $idx }}][ingredient_id]"
                                        class="form-select form-select-sm"
                                        onchange="onBahanChange({{ $idx }})">
                                    <option value="">— Pilih Bahan —</option>
                                    @foreach($ingredientJs as $b)
                                        <option value="{{ $b['id'] }}" @selected($b['id'] == $item->ingredient_id)>{{ $b['name'] }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                {{-- Isinya dibangun JS saat halaman dimuat memakai aturan
                                     yang SAMA dgn baris baru, supaya tidak ada dua versi logika --}}
                                <select name="items[{{ $idx }}][packaging_id]"
                                        class="form-select form-select-sm sel-kemasan"
                                        data-terpilih="{{ $item->packaging_id }}"
                                        onchange="onKemasanChange({{ $idx }})">
                                </select>
                            </td>

                            {{-- Selalu dirender (tidak lagi bergantung kemasan awal), karena
                                 kemasannya kini bisa diganti — sama seperti form Buat Mutasi --}}
                            <td>
                                <input type="number" name="items[{{ $idx }}][qty_crate]"
                                       class="form-control form-control-sm qty-input"
                                       value="{{ old('items.'.$idx.'.qty_crate', $item->qty_crate) }}"
                                       min="0" placeholder="0"
                                       oninput="recalcRow({{ $idx }})">
                            </td>

                            <td>
                                <input type="number" name="items[{{ $idx }}][qty_pack]"
                                       class="form-control form-control-sm qty-input"
                                       value="{{ old('items.'.$idx.'.qty_pack', $item->qty_pack) }}"
                                       min="0" placeholder="0"
                                       oninput="recalcRow({{ $idx }})">
                            </td>

                            <td>
                                <input type="number" name="items[{{ $idx }}][qty_base]"
                                       class="form-control form-control-sm qty-input"
                                       value="{{ old('items.'.$idx.'.qty_base', $item->qty_base) }}"
                                       step="0.01" min="0" placeholder="0"
                                       oninput="recalcRow({{ $idx }})">
                            </td>

                            <td>
                                {{-- Sama seperti form Buat: input teks ber-format ribuan (num-fmt),
                                     bukan input angka mentah, dan tanpa label satuan di bawahnya --}}
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">Rp</span>
                                    <input type="text" class="form-control form-control-sm price-dus-input num-fmt"
                                           value="{{ number_format($ctb > 0 ? $priceDus : round($grossBase), 0, ',', '.') }}"
                                           placeholder="0"
                                           oninput="onPriceDusChange({{ $idx }})">
                                </div>
                            </td>

                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-danger" title="Hapus baris"
                                        onclick="hapusBaris({{ $idx }})"><i class="bi bi-x-lg"></i></button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="7" class="py-2">
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="tambahBaris()">
                                    <i class="bi bi-plus-circle me-1"></i> Tambah Bahan
                                </button>
                                <span class="text-muted small ms-2">Bahan baru ikut tersimpan saat Simpan Draft / Konfirmasi.</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- ═════ Ringkasan Total ═════ (bentuk & isi disamakan dgn form Buat Mutasi) --}}
    <div class="card mt-3 border-success">
        <div class="card-body py-3">
            <div id="totalsContainer">
                <div class="text-muted small text-center py-2">Isi data item dulu untuk lihat subtotal.</div>
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
            if (typeof recalcTotals === 'function') recalcTotals();   // ringkasan ikut
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
// ══════════════════════════════════════════════════════════════════════════
// TAMBAH BAHAN saat edit draft
// Baris baru dikirim TANPA item_id; server membuatkan record-nya (lihat
// MutationController::update). Baris yang ditambah lalu dibiarkan kosong
// diabaikan server, jadi user tidak wajib menghapusnya.
// ══════════════════════════════════════════════════════════════════════════
var dataBahan   = @json($ingredientJs ?? []);
var supplierMut = @json($mutation->supplier_id);
var tipeMut     = @json($mutation->type);
var idxBaru     = {{ $mutation->items->count() }};

// Kemasan yang boleh dipakai: untuk pembelian dari supplier tertentu, hanya
// kemasan milik supplier itu — konsisten dengan form Buat Mutasi.
function kemasanTersedia(bahan) {
    var perluFilter = ['purchase_zhisheng', 'purchase_supplier'].indexOf(tipeMut) > -1 && supplierMut;
    if (!perluFilter) return bahan.packagings;
    var cocok = bahan.packagings.filter(function (p) {
        return String(p.supplier_id) === String(supplierMut);
    });
    return cocok.length ? cocok : bahan.packagings;
}

function tambahBaris() {
    var idx = idxBaru++;
    var opsi = dataBahan.map(function (b) {
        return '<option value="' + b.id + '">' + b.name + '</option>';
    }).join('');

    var tr = document.createElement('tr');
    tr.className = 'edit-row baris-baru';
    tr.id = 'erow-' + idx;
    tr.dataset.idx = idx;
    tr.dataset.ctb = 0;
    tr.dataset.ptb = 0;
    tr.innerHTML =
      '<td>'
      + '<select name="items[' + idx + '][ingredient_id]" class="form-select form-select-sm"'
      + ' onchange="onBahanChange(' + idx + ')">'
      + '<option value="">— Pilih Bahan —</option>' + opsi + '</select>'
      + '</td>'
      + '<td>'
      + '<select name="items[' + idx + '][packaging_id]" class="form-select form-select-sm sel-kemasan"'
      + ' onchange="onKemasanChange(' + idx + ')"><option value="">— Kemasan —</option></select>'
      + '</td>'
      + '<td><input type="number" name="items[' + idx + '][qty_crate]" class="form-control form-control-sm qty-input"'
      + ' min="0" placeholder="0" oninput="recalcRow(' + idx + ')"></td>'
      + '<td><input type="number" name="items[' + idx + '][qty_pack]" class="form-control form-control-sm qty-input"'
      + ' min="0" placeholder="0" oninput="recalcRow(' + idx + ')"></td>'
      + '<td><input type="number" name="items[' + idx + '][qty_base]" class="form-control form-control-sm qty-input"'
      + ' step="0.01" min="0" placeholder="0" oninput="recalcRow(' + idx + ')"></td>'
      + '<td>'
      + '<div class="input-group input-group-sm"><span class="input-group-text">Rp</span>'
      + '<input type="text" class="form-control form-control-sm price-dus-input num-fmt" placeholder="0"'
      + ' oninput="onPriceDusChange(' + idx + ')"></div>'
      + '<input type="hidden" name="items[' + idx + '][price_per_base]" class="price-per-base-hidden" value="0">'
      + '<input type="hidden" name="items[' + idx + '][price_per_crate]" class="price-per-crate-hidden" value="">'
      + '</td>'
      + '<td class="text-end">'
      + '<button type="button" class="btn btn-sm btn-outline-danger" title="Hapus baris"'
      + ' onclick="hapusBaris(' + idx + ')"><i class="bi bi-x-lg"></i></button>'
      + '</td>';

    document.getElementById('itemsBody').appendChild(tr);
}

// Isi dropdown kemasan + set konversi dus/pack pada baris.
// TIDAK menyentuh harga — dipakai juga saat halaman dimuat untuk baris lama,
// di mana harga yang sudah tersimpan tidak boleh dihitung ulang (pembulatan
// bolak-balik bisa menggeser angka walau user tidak mengubah apa pun).
function isiDropdownKemasan(idx, terpilih) {
    var row = document.getElementById('erow-' + idx);
    if (!row) return;
    var id  = row.querySelector('select[name$="[ingredient_id]"]').value;
    var sel = row.querySelector('.sel-kemasan');
    sel.innerHTML = '<option value="">— Kemasan —</option>';
    row.dataset.ctb = 0; row.dataset.ptb = 0;

    var bahan = dataBahan.filter(function (b) { return String(b.id) === String(id); })[0];
    if (!bahan) return;

    var list = kemasanTersedia(bahan);
    list.forEach(function (p) {
        var ctp = Math.round(parseFloat(p.crate_to_pack) || 0);
        var ptb = Math.round(parseFloat(p.pack_to_base) || 0);
        var o = document.createElement('option');
        o.value = p.id;
        o.textContent = '@' + ctp + ' pack';   // label ringkas, sama dgn form Buat
        o.dataset.ctb = ctp * ptb;
        o.dataset.ptb = parseFloat(p.pack_to_base) || 0;
        sel.appendChild(o);
    });

    var adaTerpilih = terpilih && list.some(function (p) { return String(p.id) === String(terpilih); });
    if (adaTerpilih)          sel.value = terpilih;
    else if (list.length === 1) sel.value = list[0].id;

    terapkanKemasan(idx);
}

// Ambil ctb/ptb dari kemasan yang sedang dipilih (tanpa menyentuh harga)
function terapkanKemasan(idx) {
    var row = document.getElementById('erow-' + idx);
    if (!row) return;
    var sel = row.querySelector('.sel-kemasan');
    var opt = sel.options[sel.selectedIndex];
    row.dataset.ctb = (opt && opt.dataset.ctb) ? opt.dataset.ctb : 0;
    row.dataset.ptb = (opt && opt.dataset.ptb) ? opt.dataset.ptb : 0;
}

function onBahanChange(idx) {
    isiDropdownKemasan(idx, null);
    onPriceDusChange(idx);   // bahan berganti -> harga dihitung ulang
}

function onKemasanChange(idx) {
    terapkanKemasan(idx);
    onPriceDusChange(idx);   // isi dus berubah -> harga/satuan ikut berubah
}

// Bangun dropdown kemasan semua baris lama saat halaman dimuat, memakai aturan
// yang SAMA dengan baris baru (tidak ada dua versi logika server vs klien).
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#itemsBody tr.edit-row').forEach(function (row) {
        var sel = row.querySelector('.sel-kemasan');
        if (!sel) return;
        isiDropdownKemasan(row.dataset.idx, sel.dataset.terpilih || null);
    });
    recalcTotals();
});

function hapusBaris(idx) {
    var row = document.getElementById('erow-' + idx);
    if (row) row.remove();
    recalcTotals();
}

// Subtotal per baris kini tampil di panel Ringkasan Total (sama seperti form Buat),
// bukan lagi kolom di dalam tabel — jadi cukup hitung ulang panelnya.
function recalcRow(idx) { recalcTotals(); }

// ── Called when price-per-dus input changes ─────────────────────────────────
function onPriceDusChange(idx) {
    var row    = document.getElementById('erow-' + idx);
    if (!row) return;
    var ctb = parseFloat(row.dataset.ctb) || 0;
    // Nilainya kini berformat ribuan ("1.016.000"), jadi WAJIB lewat NumberFmt.parse.
    // parseFloat("1.016.000") akan terbaca 1.016 — harganya jadi kacau total.
    var el = row.querySelector('.price-dus-input');
    var priceDus = el ? (window.NumberFmt ? NumberFmt.parse(el.value) : parseFloat(el.value) || 0) : 0;
    var priceBase = ctb > 0 ? priceDus / ctb : priceDus;  // tanpa kemasan: dianggap harga per satuan dasar

    var hidden = row.querySelector('.price-per-base-hidden');
    if (hidden) hidden.value = priceBase.toFixed(8);

    // Kirim juga harga/dus persis seperti yang diketik (tanpa konversi)
    var hiddenCrate = row.querySelector('.price-per-crate-hidden');
    if (hiddenCrate) hiddenCrate.value = ctb > 0 ? priceDus : '';

    recalcRow(idx);
}

// ══════════════════════════════════════════════════════════════════════════
// RINGKASAN TOTAL — bentuk & perilakunya disamakan dengan form Buat Mutasi
// (lihat recalcTotals di create.blade.php). Bedanya hanya nama atribut baris:
// di sini .edit-row + data-ctb/data-ptb, di sana .item-row + data-crate-to-base.
// ══════════════════════════════════════════════════════════════════════════
function recalcTotals() {
    var container = document.getElementById('totalsContainer');
    if (!container) return;

    var fmt   = function (n) { return Number(Math.round(n)).toLocaleString('id-ID'); };
    var lines = [];
    var grand = 0;

    document.querySelectorAll('#itemsBody tr.edit-row').forEach(function (row) {
        var ingSel = row.querySelector('select[name$="[ingredient_id]"]');
        if (!ingSel || !ingSel.value) return;

        var bahan   = dataBahan.filter(function (b) { return String(b.id) === String(ingSel.value); })[0];
        var nama    = bahan ? bahan.name : (ingSel.options[ingSel.selectedIndex] || {}).text || '?';
        var pkgSel  = row.querySelector('.sel-kemasan');
        var pkgNama = (pkgSel && pkgSel.value && pkgSel.options[pkgSel.selectedIndex])
            ? pkgSel.options[pkgSel.selectedIndex].textContent : '';

        var ctb  = parseFloat(row.dataset.ctb) || 0;
        var ptb  = parseFloat(row.dataset.ptb) || 0;
        var qtyC = parseFloat(row.querySelector('input[name$="[qty_crate]"]')?.value) || 0;
        var qtyP = parseFloat(row.querySelector('input[name$="[qty_pack]"]')?.value)  || 0;
        var qtyB = parseFloat(row.querySelector('input[name$="[qty_base]"]')?.value)  || 0;
        var totalBase = (qtyC * ctb) + (qtyP * ptb) + qtyB;

        var priceBase = parseFloat(row.querySelector('.price-per-base-hidden')?.value) || 0;
        var subtotal  = totalBase * priceBase;
        if (subtotal <= 0) return;
        grand += subtotal;

        var bagian = [];
        if (qtyC > 0) bagian.push(qtyC + ' Dus');
        if (qtyP > 0) bagian.push(qtyP + ' Pack');
        if (qtyB > 0) bagian.push(qtyB + ' ' + (bahan ? bahan.unit : 'sat'));

        lines.push(
            '<tr>'
          + '<td class="fw-semibold">' + nama + '</td>'
          + '<td class="text-muted small">' + (pkgNama || '—') + '</td>'
          + '<td class="text-end text-muted small text-nowrap">' + (bagian.join(' + ') || '—') + '</td>'
          + '<td class="text-end fw-semibold text-nowrap">Rp ' + fmt(subtotal) + '</td>'
          + '</tr>'
        );
    });

    if (lines.length === 0) {
        container.innerHTML = '<div class="text-muted small text-center py-2">Isi data item dulu untuk lihat subtotal.</div>';
        return;
    }

    var discEl   = document.getElementById('discountHidden');
    var discount = discEl ? (parseFloat(discEl.value) || 0) : 0;
    var footRows;
    if (discount > 0) {
        footRows =
            '<tr class="border-top"><td colspan="3" class="text-end text-muted">Subtotal (bruto)</td>'
          +   '<td class="text-end text-nowrap">Rp ' + fmt(grand) + '</td></tr>'
          + '<tr><td colspan="3" class="text-end text-muted">Diskon invoice</td>'
          +   '<td class="text-end text-danger text-nowrap">− Rp ' + fmt(discount) + '</td></tr>'
          + '<tr><td colspan="3" class="fw-bold fs-6">TOTAL BAYAR</td>'
          +   '<td class="text-end fw-bold fs-5 text-success text-nowrap">Rp ' + fmt(Math.max(0, grand - discount)) + '</td></tr>';
    } else {
        footRows =
            '<tr class="border-top"><td colspan="3" class="fw-bold fs-6">GRAND TOTAL</td>'
          + '<td class="text-end fw-bold fs-5 text-success text-nowrap">Rp ' + fmt(grand) + '</td></tr>';
    }

    container.innerHTML =
        '<div class="fw-semibold mb-2"><i class="bi bi-receipt me-1"></i>Ringkasan Total</div>'
      + '<div class="table-responsive">'
      + '<table class="table table-sm align-middle mb-0">'
      + '<thead><tr class="text-muted small">'
      +   '<th>Bahan</th><th>Kemasan</th>'
      +   '<th class="text-end">Qty</th><th class="text-end">Subtotal</th>'
      + '</tr></thead>'
      + '<tbody>' + lines.join('') + '</tbody>'
      + '<tfoot>' + footRows + '</tfoot>'
      + '</table></div>';
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
// Baris yang bahannya sudah dipilih WAJIB punya kemasan. Tanpa kemasan, isi dus
// tidak diketahui sehingga angka di kolom "Harga / Dus" akan tersimpan sebagai
// harga per gram/pcs — nilainya jadi jauh meleset tanpa ada tanda apa pun.
function validasiKemasan() {
    var bermasalah = [];
    document.querySelectorAll('#itemsBody tr.edit-row').forEach(function (row) {
        var selB = row.querySelector('select[name$="[ingredient_id]"]');
        var selK = row.querySelector('.sel-kemasan');
        if (!selB || !selK || !selB.value) return;          // baris kosong: diabaikan server
        if (selK.value) { selK.classList.remove('is-invalid'); return; }
        if (selK.options.length <= 1) return;               // memang tidak punya kemasan
        selK.classList.add('is-invalid');
        bermasalah.push(selB.options[selB.selectedIndex].text);
    });
    if (bermasalah.length && window.uiAlert) {
        uiAlert('Kemasan belum dipilih untuk: ' + bermasalah.join(', ') + '.\n\n'
              + 'Tanpa kemasan, sistem tidak tahu isi 1 dus sehingga harga yang diketik '
              + 'akan dihitung per satuan terkecil.',
              { type: 'warning', title: 'Kemasan belum dipilih' });
    }
    return bermasalah.length === 0;
}

document.getElementById('editForm').addEventListener('submit', function(e) {
    if (!validateDates() || !validasiKemasan()) {
        e.preventDefault();
        e.stopImmediatePropagation(); // prevent confirm() on the "confirm" button from re-firing
        return false;
    }
});
</script>
@endpush
