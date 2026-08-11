@extends('layouts.app')
@section('title','Buat Opname')
@section('content')

<div class="page-header d-flex justify-content-between align-items-center">
    <h4 class="page-title">Buat Stok Opname Baru</h4>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-success" onclick="downloadOpnameTemplate()">
            <i class="bi bi-file-earmark-excel me-1"></i>Download Template
        </button>
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalImportOpname">
            <i class="bi bi-upload me-1"></i>Import Excel
        </button>
        <a href="{{ route('opname.opnames.index') }}" class="btn btn-outline-secondary btn-sm btn-back">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

<form method="POST" action="{{ route('opname.opnames.store') }}" id="opname-form"
      onsubmit="document.querySelectorAll('.price-input').forEach(function(el){ el.value = el.value.replace(/\./g,''); })">
    @csrf

    {{-- Card: Informasi Opname --}}
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Informasi Opname</div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Toko <span class="text-danger">*</span></label>
                    <select name="store_id" id="store_id" class="form-select" required>
                        <option value="">— Pilih Toko —</option>
                        @foreach($stores as $s)
                            <option value="{{ $s->id }}" {{ old('store_id')==$s->id ? 'selected' : '' }}>
                                {{ $s->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Tanggal Opname <span class="text-danger">*</span></label>
                    <input type="date" name="opname_date" id="opname_date"
                           class="form-control" value="{{ old('opname_date', date('Y-m-d')) }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tipe Periode <span class="text-danger">*</span></label>
                    <select name="period_type" id="period_type" class="form-select" required>
                        <option value="mid_month" {{ old('period_type')=='mid_month' ? 'selected' : '' }}>
                            Periode 1–15
                        </option>
                        <option value="end_month" {{ old('period_type','end_month')=='end_month' ? 'selected' : '' }}>
                            Periode 1–30/31 (Akhir Bulan)
                        </option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Jenis Opname</label>
                    <select name="opname_mode" id="opname_mode" class="form-select" onchange="applyOpnameMode()">
                        <option value="bulanan" selected>Opname Bulanan (harga otomatis)</option>
                        <option value="stok_awal">Input Stok Awal (isi harga)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Catatan</label>
                    <input type="text" name="notes" class="form-control"
                           placeholder="Opsional" value="{{ old('notes') }}">
                </div>
                <div class="col-md-2">
                    <button type="button" id="btn-load" class="btn btn-secondary w-100" onclick="loadIngredients()">
                        <i class="bi bi-search me-1"></i>Muat Daftar Bahan
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Loading spinner --}}
    <div id="loading-section" class="d-none text-center py-5">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="text-muted mt-2 mb-0">Memuat data stok…</p>
    </div>

    {{-- Card: Tabel Bahan (muncul setelah AJAX) --}}
    <div id="ingredient-section" class="d-none">
        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-clipboard-check me-1"></i>Daftar Bahan</span>
                <span class="text-muted small fw-normal" id="count-label"></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2" style="min-width:180px">Nama Bahan</th>
                                <th colspan="3" class="text-center border-start">STOK FISIK</th>
                                <th rowspan="2" class="text-end border-start" style="min-width:80px">Total Dus</th>
                                <th colspan="2" class="text-center border-start">STOK SISTEM</th>
                                <th rowspan="2" class="text-end border-start" style="min-width:90px">Selisih</th>
                                <th rowspan="2" class="text-end border-start" style="width:140px">Harga / Dus</th>
                                <th rowspan="2" class="text-end border-start" style="width:140px">Subtotal</th>
                                <th rowspan="2" class="border-start batch-col" style="width:36px;display:none"></th>
                            </tr>
                            <tr>
                                <th class="text-center border-start small fw-normal py-1" style="width:70px">Dus</th>
                                <th class="text-center small fw-normal py-1" style="width:70px">Pack</th>
                                <th class="text-center small fw-normal py-1" style="width:75px">Pcs/Gr</th>
                                <th class="text-end border-start small fw-normal py-1" style="width:60px">Dus</th>
                                <th class="text-end small fw-normal py-1" style="width:60px">Pack</th>
                            </tr>
                        </thead>
                        <tbody id="ingredient-tbody"></tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="9" class="text-end border-top">TOTAL NILAI SO</td>
                                <td class="text-end border-top border-start fs-6" id="grand-total">Rp 0</td>
                                <td class="border-top batch-col" style="display:none"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Kolom yang dikosongkan dihitung sebagai 0. Selisih dihitung otomatis saat Anda mengetik.
                </small>
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-save me-1"></i>Simpan Opname
                </button>
            </div>
        </div>
    </div>

</form>

{{-- Modal Import --}}
<div class="modal fade" id="modalImportOpname" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form method="POST" action="{{ route('opname.opnames.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="bi bi-upload me-2"></i>Import Stok Opname</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @if($errors->any())
                        <div class="alert alert-danger py-2 small">
                            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                        </div>
                    @endif
                    <p class="text-muted small mb-2">Upload file template yang sudah diisi.</p>
                    <input type="file" name="file" class="form-control form-control-sm" accept=".xlsx,.xls" required>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-upload me-1"></i>Import
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
@if($errors->any())
var modalImport = new bootstrap.Modal(document.getElementById('modalImportOpname'));
modalImport.show();
@endif

function downloadOpnameTemplate() {
    var storeId    = document.getElementById('store_id').value;
    var date       = document.getElementById('opname_date').value;
    var periodType = document.getElementById('period_type').value;

    if (!storeId)    { alert('Pilih toko terlebih dahulu.'); return; }
    if (!date)       { alert('Isi tanggal opname terlebih dahulu.'); return; }
    if (!periodType) { alert('Pilih tipe periode terlebih dahulu.'); return; }

    var mode = document.getElementById('opname_mode') ? document.getElementById('opname_mode').value : 'bulanan';
    var url = '{{ route('opname.opnames.template') }}'
        + '?store_id='    + encodeURIComponent(storeId)
        + '&date='        + encodeURIComponent(date)
        + '&period_type=' + encodeURIComponent(periodType)
        + '&opname_mode=' + encodeURIComponent(mode);

    window.location.href = url;
}
</script>
<style>
/* Hilangkan panah spinner pada input number */
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
input[type=number] { -moz-appearance: textfield; }
</style>
<script>
function formatPriceInput(el) {
    var raw = el.value.replace(/\./g, '').replace(/[^0-9]/g, '');
    var num = parseInt(raw, 10);
    el.value = isNaN(num) ? '' : num.toLocaleString('id-ID');
}
function parsePriceInput(el) {
    return parseFloat(el.value.replace(/\./g, '')) || 0;
}
function splitDusPack(baseQty, crate, pack) {
    if (!crate || !pack) return { dus: 0, pack: 0 };
    // Tangani NEGATIF (saldo sistem boleh minus): pecah dari nilai mutlak dulu,
    // baru pasang tandanya ke KEDUA komponen. Math.floor() langsung pada angka
    // negatif membulat ke arah -tak hingga, jadi -5 base bisa muncul sbg
    // "-1 Dus + 195 Pack" (matematis benar tapi tidak terbaca) alih-alih "-5 Pack".
    var neg = baseQty < 0;
    var abs = Math.abs(baseQty);
    var dus = Math.floor(abs / (crate * pack));
    var rem = abs - dus * crate * pack;
    var pck = Math.floor(rem / pack);
    return { dus: neg ? -dus : dus, pack: neg ? -pck : pck };
}

/**
 * Format selisih (base units) → "±X Dus ±Y Pack"
 * Sub-pack (< 1 pack) diabaikan — selisih hanya dihitung per Dus & Pack bulat.
 */
function fmtVariance(varBase, crate, pack) {
    var crateToBase = crate > 0 ? crate * pack : 0;
    if (!crateToBase && !pack) {
        // Tidak ada kemasan — tampilkan sebagai angka mentah
        if (Math.abs(varBase) < 0.01) return '0';
        return (varBase > 0 ? '+' : '') + Math.round(varBase).toLocaleString('id-ID');
    }
    if (Math.abs(varBase) < 0.001) return '0';

    var sign   = varBase >= 0 ? 1 : -1;
    var prefix = sign > 0 ? '+' : '−';
    var abs    = Math.abs(varBase);
    var dusN   = crateToBase > 0 ? Math.floor(abs / crateToBase) : 0;
    var remPck = abs - dusN * crateToBase;
    var packN  = pack > 0 ? Math.floor(remPck / pack) : 0;

    var parts = [];
    if (dusN  > 0) parts.push(prefix + dusN  + ' Dus');
    if (packN > 0) parts.push(prefix + packN + ' Pack');
    return parts.length > 0 ? parts.join(' ') : '0';
}

// rowKey = 'pkg_X' atau 'ing_X'
function updateRow(rowKey) {
    var row = document.querySelector('tr[data-rowkey="' + rowKey + '"]');
    if (!row) return;
    var crate  = parseFloat(row.dataset.crate)  || 0;
    var pack   = parseFloat(row.dataset.pack)   || 1;
    var sysQty = parseFloat(row.dataset.system) || 0;
    var price  = parseFloat(row.dataset.price)  || 0;

    // Harga manual (kalau belum ada harga dari data) → per dus dikonversi ke per base
    var priceInput = row.querySelector('[name$="[price_per_dus]"]');
    if (priceInput) {
        var ctbP = crate > 0 ? crate * pack : 0;
        var pd   = parsePriceInput(priceInput);
        price = ctbP > 0 ? pd / ctbP : pd;
        row.dataset.price = price; // supaya grand total ikut pakai harga manual
    }

    var c = parseFloat(row.querySelector('[name$="[physical_crate]"]')?.value) || 0;
    var p = parseFloat(row.querySelector('[name$="[physical_pack]"]')?.value)  || 0;
    var b = parseFloat(row.querySelector('[name$="[physical_base]"]')?.value)  || 0;

    // physBase menyertakan Pcs/Gr → dipakai untuk Total Dus & Nilai
    var physBase    = crate > 0 ? (c * crate * pack) + (p * pack) + b : (p * pack) + b;
    // physBaseForVar TIDAK menyertakan Pcs/Gr → hanya Dus & Pack bulat untuk Selisih
    var physBaseVar = crate > 0 ? (c * crate * pack) + (p * pack) : (p * pack);
    var crateToBase = crate > 0 ? crate * pack : 0;

    var safeKey = rowKey.replace(/_/g, '-');

    var DASH = '<span class="text-muted opacity-50 small">-</span>';

    // Total Dus (fisik termasuk Pcs/Gr, desimal)
    var totalDusCell = document.getElementById('totaldus-' + safeKey);
    if (totalDusCell) {
        var totalDusVal = crateToBase > 0 ? physBase / crateToBase : physBase;
        totalDusCell.innerHTML = totalDusVal === 0 ? DASH
            : totalDusVal.toLocaleString('id-ID', { maximumFractionDigits: 2 });
    }

    // Selisih: hanya Dus & Pack bulat (abaikan Pcs/Gr)
    // Stok Sistem juga ditruncate ke pack bulat agar simetris
    var sysPackBase = pack > 0 ? Math.floor(sysQty / pack) * pack : sysQty; // truncate ke pack bulat
    var varBase = Math.round((physBaseVar - sysPackBase) * 10000) / 10000;
    var varCell = document.getElementById('var-' + safeKey);
    if (varCell) {
        var txt = fmtVariance(varBase, crate, pack);
        if (txt === '0') {
            varCell.innerHTML = DASH;
            varCell.className = 'text-end border-start fw-bold text-muted';
        } else {
            varCell.textContent = txt;
            varCell.className = 'text-end border-start fw-bold ' + (varBase < 0 ? 'text-danger' : 'text-success');
        }
    }

    // Nilai: hitung per komponen (Dus/Pack/Base) dari harga per-dus agar presisi
    // tidak hilang. Mengalikan physBase × price_per_base menimbulkan galat
    // floating-point (mis. 10.353.271,4999… → keliru jadi ...271).
    var nilaiCell = document.getElementById('nilai-' + safeKey);
    if (nilaiCell) {
        var nilaiVal;
        if (priceInput && crate > 0) {
            var pdNow = parsePriceInput(priceInput);   // harga per dus
            var pPack = crate > 0 ? pdNow / crate : 0;  // crate = pack per dus
            var pBase = crateToBase > 0 ? pdNow / crateToBase : 0;
            nilaiVal = Math.round((c * pdNow) + (p * pPack) + (b * pBase));
        } else {
            nilaiVal = price > 0 ? Math.round(physBase * price) : 0;
        }
        nilaiCell.innerHTML = nilaiVal !== 0
            ? 'Rp ' + nilaiVal.toLocaleString('id-ID')
            : DASH;
    }

    updateGrandTotal();
}

function updateGrandTotal() {
    var total = 0;
    document.querySelectorAll('tr[data-rowkey]').forEach(function (row) {
        var crate = parseFloat(row.dataset.crate) || 0;
        var pack  = parseFloat(row.dataset.pack)  || 1;
        var price = parseFloat(row.dataset.price) || 0;
        var c = parseFloat(row.querySelector('[name$="[physical_crate]"]')?.value) || 0;
        var p = parseFloat(row.querySelector('[name$="[physical_pack]"]')?.value)  || 0;
        var b = parseFloat(row.querySelector('[name$="[physical_base]"]')?.value)  || 0;
        var crateToBase = crate > 0 ? crate * pack : 0;
        // Akumulasi nilai MENTAH (belum dibulatkan), total dibulatkan sekali di akhir
        // → lebih akurat daripada menjumlahkan hasil bulat per baris.
        var priceInput = row.querySelector('[name$="[price_per_dus]"]');
        if (priceInput && crate > 0) {
            var pd    = parsePriceInput(priceInput);
            var pPack = crate > 0 ? pd / crate : 0;
            var pBase = crateToBase > 0 ? pd / crateToBase : 0;
            total += (c * pd) + (p * pPack) + (b * pBase);
        } else {
            var physBase = crate > 0 ? (c * crate * pack) + (p * pack) + b : (p * pack) + b;
            total += physBase * price;
        }
    });
    var gt = document.getElementById('grand-total');
    if (gt) gt.textContent = 'Rp ' + Math.round(total).toLocaleString('id-ID');
}

// Mode "Stok Awal" → harga bisa diisi + kolom batch tampil; "Bulanan" → harga readonly
function applyOpnameMode() {
    var stokAwal = document.getElementById('opname_mode').value === 'stok_awal';
    document.querySelectorAll('.price-input').forEach(function (i) {
        i.readOnly = !stokAwal;
        i.classList.toggle('bg-light', !stokAwal);
    });
    document.querySelectorAll('.batch-col').forEach(function (el) {
        el.style.display = stokAwal ? '' : 'none';
    });
}

var batchCounters = {}; // rowKey → jumlah batch yang sudah ditambah

function addBatchRow(parentRowKey, ingredientId, packagingId) {
    batchCounters[parentRowKey] = (batchCounters[parentRowKey] || 1) + 1;
    var batchNum = batchCounters[parentRowKey];
    var batchKey = parentRowKey + '_b' + batchNum;
    var safeKey  = batchKey.replace(/_/g, '-');

    var parentRow = document.querySelector('tr[data-rowkey="' + parentRowKey + '"]');
    var crate = parseFloat(parentRow.dataset.crate) || 0;
    var pack  = parseFloat(parentRow.dataset.pack)  || 1;

    var tr = document.createElement('tr');
    tr.dataset.rowkey = batchKey;
    tr.dataset.system = 0;
    tr.dataset.crate  = crate;
    tr.dataset.pack   = pack;
    tr.dataset.price  = 0;
    tr.style.background = 'rgba(255,193,7,.07)';

    tr.innerHTML =
        '<td>' +
            '<input type="hidden" name="items[' + batchKey + '][ingredient_id]" value="' + ingredientId + '">' +
            '<input type="hidden" name="items[' + batchKey + '][packaging_id]"  value="' + (packagingId || '') + '">' +
            '<span class="fw-semibold" style="opacity:.5;font-size:.85em">└ Batch ' + batchNum + '</span>' +
        '</td>' +
        '<td class="border-start">' +
            '<input type="number" name="items[' + batchKey + '][physical_crate]"' +
                   ' class="form-control form-control-sm text-center no-spin" min="0" placeholder="0"' +
                   ' oninput="updateRow(\'' + batchKey + '\')">' +
        '</td>' +
        '<td>' +
            '<input type="number" name="items[' + batchKey + '][physical_pack]"' +
                   ' class="form-control form-control-sm text-center no-spin" min="0" placeholder="0"' +
                   ' oninput="updateRow(\'' + batchKey + '\')">' +
        '</td>' +
        '<td>' +
            '<input type="number" name="items[' + batchKey + '][physical_base]"' +
                   ' class="form-control form-control-sm text-center no-spin" min="0" step="0.01" placeholder="0"' +
                   ' oninput="updateRow(\'' + batchKey + '\')">' +
        '</td>' +
        '<td class="text-end border-start text-muted" id="totaldus-' + safeKey + '">0</td>' +
        '<td class="text-end border-start text-muted">—</td>' +
        '<td class="text-end text-muted">—</td>' +
        '<td class="text-end border-start fw-bold text-muted" id="var-' + safeKey + '">—</td>' +
        '<td class="text-end border-start">' +
            '<input type="text" inputmode="numeric" name="items[' + batchKey + '][price_per_dus]"' +
            ' class="form-control form-control-sm text-end price-input" placeholder="Harga/Dus"' +
            ' oninput="formatPriceInput(this);updateRow(\'' + batchKey + '\')">' +
        '</td>' +
        '<td class="text-end border-start fw-semibold" id="nilai-' + safeKey + '"><span class="text-muted opacity-50 small">-</span></td>' +
        '<td class="border-start text-center batch-col" style="width:36px;padding:2px 4px">' +
            '<button type="button" class="btn btn-outline-danger btn-sm px-1 py-0"' +
                ' title="Hapus batch ini" style="font-size:.75rem;line-height:1.4"' +
                ' onclick="removeBatchRow(\'' + batchKey + '\')">×</button>' +
        '</td>';

    // Sisipkan setelah baris terakhir grup ini
    var allRows = Array.from(document.querySelectorAll('tr[data-rowkey]'));
    var lastInGroup = null;
    allRows.forEach(function(r) {
        if (r.dataset.rowkey === parentRowKey || r.dataset.rowkey.startsWith(parentRowKey + '_b')) {
            lastInGroup = r;
        }
    });
    if (lastInGroup && lastInGroup.nextSibling) {
        lastInGroup.parentNode.insertBefore(tr, lastInGroup.nextSibling);
    } else if (lastInGroup) {
        lastInGroup.parentNode.appendChild(tr);
    }
}

function removeBatchRow(batchKey) {
    var tr = document.querySelector('tr[data-rowkey="' + batchKey + '"]');
    if (tr) { tr.remove(); updateGrandTotal(); }
}

function loadIngredients() {
    var storeId = document.getElementById('store_id').value;
    var date    = document.getElementById('opname_date').value;

    if (!storeId || !date) {
        alert('Pilih toko dan tanggal opname terlebih dahulu.');
        return;
    }

    document.getElementById('ingredient-section').classList.add('d-none');
    document.getElementById('loading-section').classList.remove('d-none');
    document.getElementById('btn-load').disabled = true;

    fetch('{{ route("api.opname.system-qty") }}?store_id=' + storeId + '&date=' + date)
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            document.getElementById('loading-section').classList.add('d-none');
            document.getElementById('btn-load').disabled = false;

            if (!data.length) {
                alert('Tidak ada bahan yang terdaftar untuk toko ini.');
                return;
            }

            document.getElementById('count-label').textContent = data.length + ' baris';

            var tbody = document.getElementById('ingredient-tbody');
            tbody.innerHTML = '';

            data.forEach(function (ing) {
                var rowKey   = ing.row_key;            // 'pkg_X' atau 'ing_X'
                var safeKey  = rowKey.replace(/_/g, '-'); // untuk id HTML (pkg-X / ing-X)
                var crate    = ing.crate_to_pack || 0;
                var pack     = ing.pack_to_base  || 1;
                var sysQty   = ing.system_qty    || 0;
                var priceDus = ing.price_per_dus || 0;

                var sysSplit = splitDusPack(sysQty, crate, pack);

                // Sub-label: hanya tampilkan "@X pack" bila multi-kemasan
                var subLabel = ing.pkg_label
                    ? '<div class="text-muted" style="font-size:.75rem">' + ing.pkg_label + '</div>'
                    : '';

                var tr = document.createElement('tr');
                tr.dataset.rowkey = rowKey;
                tr.dataset.system = sysQty;
                tr.dataset.crate  = crate;
                tr.dataset.pack   = pack;
                tr.dataset.price  = ing.price_per_base;

                tr.innerHTML =
                    '<td>' +
                        '<input type="hidden" name="items[' + rowKey + '][ingredient_id]" value="' + ing.ingredient_id + '">' +
                        '<input type="hidden" name="items[' + rowKey + '][packaging_id]"  value="' + (ing.packaging_id || '') + '">' +
                        '<span class="fw-semibold">' + ing.name + '</span>' +
                        subLabel +
                    '</td>' +
                    // Stok Fisik: Dus | Pack | Pcs/Gr
                    '<td class="border-start">' +
                        '<input type="number" name="items[' + rowKey + '][physical_crate]"' +
                               ' class="form-control form-control-sm text-center no-spin" min="0" placeholder="0"' +
                               ' oninput="updateRow(\'' + rowKey + '\')">' +
                    '</td>' +
                    '<td>' +
                        '<input type="number" name="items[' + rowKey + '][physical_pack]"' +
                               ' class="form-control form-control-sm text-center no-spin" min="0" placeholder="0"' +
                               ' oninput="updateRow(\'' + rowKey + '\')">' +
                    '</td>' +
                    '<td>' +
                        '<input type="number" name="items[' + rowKey + '][physical_base]"' +
                               ' class="form-control form-control-sm text-center no-spin" min="0" step="0.01" placeholder="0"' +
                               ' oninput="updateRow(\'' + rowKey + '\')">' +
                    '</td>' +
                    // Total Dus
                    '<td class="text-end border-start text-muted" id="totaldus-' + safeKey + '"><span class="text-muted opacity-50 small">-</span></td>' +
                    // Stok Sistem: Dus | Pack
                    '<td class="text-end border-start">' + (sysSplit.dus  ? sysSplit.dus  : '<span class="text-muted opacity-50 small">-</span>') + '</td>' +
                    '<td class="text-end">'               + (sysSplit.pack ? sysSplit.pack : '<span class="text-muted opacity-50 small">-</span>') + '</td>' +
                    // Selisih
                    '<td class="text-end border-start fw-bold text-muted" id="var-' + safeKey + '">0</td>' +
                    // Harga/Dus — readonly saat "Bulanan" (tampil otomatis), bisa diisi saat "Stok Awal"
                    '<td class="text-end border-start">' +
                        '<input type="text" inputmode="numeric" name="items[' + rowKey + '][price_per_dus]"' +
                        ' class="form-control form-control-sm text-end price-input" placeholder="Harga/Dus"' +
                        ' value="' + (priceDus > 0 ? priceDus.toLocaleString('id-ID') : '') + '"' +
                        ' oninput="formatPriceInput(this);updateRow(\'' + rowKey + '\')">' +
                    '</td>' +
                    // Subtotal
                    '<td class="text-end border-start fw-semibold" id="nilai-' + safeKey + '"><span class="text-muted opacity-50 small">-</span></td>' +
                    // Kolom aksi batch (show/hide via applyOpnameMode)
                    '<td class="border-start text-center batch-col" style="display:none;width:36px;padding:2px 4px">' +
                        '<button type="button" class="btn btn-outline-warning btn-sm px-1 py-0"' +
                            ' title="Tambah batch harga berbeda" style="font-size:.75rem;line-height:1.4"' +
                            ' onclick="addBatchRow(\'' + rowKey + '\',' + ing.ingredient_id + ',' + (ing.packaging_id || 0) + ')">+</button>' +
                    '</td>';

                tbody.appendChild(tr);
            });

            document.getElementById('ingredient-section').classList.remove('d-none');
            applyOpnameMode();
        })
        .catch(function (err) {
            document.getElementById('loading-section').classList.add('d-none');
            document.getElementById('btn-load').disabled = false;
            alert('Gagal memuat data bahan: ' + err.message);
        });
}
</script>
@endpush
