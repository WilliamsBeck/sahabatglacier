@extends('layouts.app')
@section('title', 'Input Mutasi')

@push('styles')
<style>
    /* Sembunyikan placeholder "—" saat dropdown kemasan / input harga aktif */
    .item-row td .wrap-packaging:not(.d-none) ~ .no-packaging-label { display: none; }
    .item-row td .wrap-price-crate:not(.d-none) ~ .no-price-label,
    .item-row td .wrap-price-direct:not(.d-none) ~ .no-price-label { display: none; }
    .item-row td { vertical-align: top; }

    /* Hilangkan panah/spinner di input number */
    #bahanTable input[type=number]::-webkit-inner-spin-button,
    #bahanTable input[type=number]::-webkit-outer-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    #bahanTable input[type=number] { -moz-appearance: textfield; }

    /* Fix lebar tabel — kolom tidak shifting saat content berubah */
    #bahanTable { table-layout: fixed; }
</style>
@endpush

@section('content')
<div class="page-header d-flex justify-content-between align-items-center">
    <h4 class="page-title">Input Mutasi Baru</h4>
    <a href="{{ route('inventory.mutations.index') }}" class="btn btn-outline-secondary btn-sm btn-back">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

<form method="POST" action="{{ route('inventory.mutations.store') }}" id="mutasiForm">
    @csrf

    {{-- ═══════════ INFORMASI MUTASI (ATAS, FULL WIDTH) ═══════════ --}}
    <div class="card mb-3">
        <div class="card-header fw-semibold">Informasi Mutasi</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tipe Mutasi <span class="text-danger">*</span></label>
                    <select name="type" id="typeSelect" class="form-select" required onchange="handleTypeChange()">
                        <option value="">— Pilih Tipe —</option>
                        <optgroup label="Supplier">
                            <option value="purchase_zhisheng" {{ old('type') === 'purchase_zhisheng' ? 'selected' : '' }}>Pembelian Pusat</option>
                            <option value="purchase_supplier" {{ old('type') === 'purchase_supplier' ? 'selected' : '' }}>Pembelian Supplier Lokal</option>
                        </optgroup>
                        <optgroup label="Internal">
                            <option value="sale_internal"  {{ old('type') === 'sale_internal'  ? 'selected' : '' }}>Penjualan Internal</option>
                        </optgroup>
                        <optgroup label="Eksternal">
                            <option value="sale_external"     {{ old('type') === 'sale_external'     ? 'selected' : '' }}>Pembelian Eksternal</option>
                            <option value="sale_external_out" {{ old('type') === 'sale_external_out' ? 'selected' : '' }}>Penjualan Eksternal</option>
                        </optgroup>
                    </select>
                </div>

                <div class="col-md-3 d-none" id="wrapSource">
                    <label class="form-label fw-semibold" id="labelSource">Toko Pengirim</label>
                    {{-- Pengirim = HANYA toko yang dipegang akun (barang keluar dari toko sendiri) --}}
                    <select name="source_store_id" id="sourceStoreSelect" class="form-select" onchange="onSourceStoreChange()">
                        <option value="">— Pilih Toko —</option>
                        @foreach($stores as $store)
                            <option value="{{ $store->id }}" {{ old('source_store_id') == $store->id ? 'selected' : '' }}>
                                {{ $store->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3" id="wrapDest">
                    <label class="form-label fw-semibold" id="labelDest">Toko Penerima <span class="text-danger">*</span></label>
                    <select name="destination_store_id" id="destStoreSelect" class="form-select" required onchange="syncStoreDropdowns()">
                        <option value="">— Pilih Toko —</option>
                        @foreach($stores as $store)
                            <option value="{{ $store->id }}"
                                {{ old('destination_store_id', count($stores) === 1 ? $store->id : '') == $store->id ? 'selected' : '' }}>
                                {{ $store->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3 d-none" id="wrapSupplier">
                    <label class="form-label fw-semibold" id="labelSupplier">Supplier <span class="text-danger">*</span></label>
                    <select name="supplier_id" id="supplierSelect" class="form-select" onchange="onSupplierChange()">
                        <option value="">— Pilih Supplier —</option>
                        @foreach($suppliers as $sup)
                            <option value="{{ $sup->id }}">{{ $sup->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3 d-none" id="wrapExtSender">
                    <label class="form-label fw-semibold">Pengirim <span class="text-danger">*</span></label>
                    <input type="text" name="external_sender" id="inputExtSender" class="form-control"
                           placeholder="Nama pengirim / toko luar" value="{{ old('external_sender') }}">
                </div>

                <div class="col-md-3 d-none" id="wrapExtReceiver">
                    <label class="form-label fw-semibold">Penjualan ke <span class="text-danger">*</span></label>
                    <input type="text" name="external_receiver" id="inputExtReceiver" class="form-control"
                           placeholder="Nama penerima / pembeli luar" value="{{ old('external_receiver') }}">
                </div>

                <div class="col-md-3" id="wrapInvoice">
                    <label class="form-label fw-semibold">No. SJ</label>
                    <input type="text" name="invoice_no" class="form-control" placeholder="Nomor Surat Jalan (opsional)" value="{{ old('invoice_no') }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold" id="labelTxDate">Tanggal Pengiriman <span class="text-danger">*</span></label>
                    <input type="date" name="transaction_date" id="inputTxDate" class="form-control" required value="{{ old('transaction_date') }}">
                    <div class="invalid-feedback" id="errTxDate"></div>
                </div>

                <div class="col-md-3" id="wrapDelivery">
                    <label class="form-label fw-semibold" id="labelDelivery">Tanggal Penerimaan</label>
                    <input type="date" name="delivery_date" id="inputDelivery" class="form-control" value="{{ old('delivery_date') }}">
                    <div class="invalid-feedback" id="errDelivery"></div>
                    <div class="form-text text-muted" id="hintDelivery">Kosongkan jika barang belum diterima — simpan sebagai <em>draft</em> dulu.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Catatan</label>
                    <input type="text" name="notes" class="form-control" placeholder="Opsional" value="{{ old('notes') }}">
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════ DAFTAR BAHAN (BAWAH, TABEL) ═══════════ --}}
    <div class="card">
        <div class="card-header fw-semibold">Daftar Bahan</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="bahanTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:28%">Bahan <span class="text-danger">*</span></th>
                            <th style="width:18%">Kemasan</th>
                            <th style="width:8%">Dus</th>
                            <th style="width:8%">Pack</th>
                            <th style="width:8%">Pcs/Gr</th>
                            <th style="width:25%"><span class="label-price-header">Harga / Dus</span></th>
                            <th style="width:5%"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        @php $oldItems = old('items', []); @endphp
                        @if(count($oldItems) > 0)
                            @foreach($oldItems as $i => $oldItem)
                                <tr class="item-row" id="row-{{ $i }}">
                                    @include('inventory.mutations._item_row', ['idx' => $i, 'ingredients' => $ingredients, 'oldItem' => $oldItem])
                                </tr>
                            @endforeach
                        @else
                            <tr class="item-row" id="row-0">
                                @include('inventory.mutations._item_row', ['idx' => 0, 'ingredients' => $ingredients])
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer border-top-0 pt-0 pb-3 px-3 d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-sm btn-outline-success" onclick="addRow()">
                <i class="bi bi-plus-circle me-1"></i> Tambah Bahan
            </button>
            <button type="button" id="btnLoadZhisheng" class="btn btn-sm btn-outline-primary d-none" onclick="loadZhishengItems()">
                <i class="bi bi-lightning-charge me-1"></i> Muat Semua Bahan Zhisheng
            </button>
        </div>
    </div>

    {{-- ═════ Ringkasan Total ═════ --}}
    <div class="card mt-3 border-success">
        <div class="card-body py-3">
            <div id="totalsContainer">
                <div class="text-muted small text-center py-2">Isi data item dulu untuk lihat subtotal.</div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-3">
        <button type="submit" id="btnSubmit" class="btn btn-success px-4">
            <i class="bi bi-save me-1"></i> Simpan sebagai Draft
        </button>
        <a href="{{ route('inventory.mutations.index') }}" class="btn btn-outline-secondary px-4">Batal</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
var rowCount = {{ max(1, count(old('items', [1]))) }};

// Data lengkap ingredient + packagings (termasuk supplier_id untuk filtering)
var ingredientData = @json($ingredientJs);

// ── Helper: bangun <optgroup> berdasarkan kategori ──────────────
var _catOrder  = @json(\App\Models\IngredientCategory::orderedNames());
var _catLabels = @json(array_merge(\App\Models\IngredientCategory::labelsMap(), ['lainnya'=>'Lainnya','semi_finished'=>'Setengah Jadi']));

function buildIngOptgroups(filtered) {
    var html = '<option value="">— Pilih Bahan —</option>';
    // Raw per kategori
    _catOrder.forEach(function(cat) {
        var items = filtered.filter(function(i) { return i.type === 'raw' && i.category === cat; });
        if (!items.length) return;
        html += '<optgroup label="' + _catLabels[cat] + '">';
        items.forEach(function(i) { html += '<option value="' + i.id + '" data-unit="' + i.unit + '">' + i.name + '</option>'; });
        html += '</optgroup>';
    });
    // Raw tanpa kategori
    var nocat = filtered.filter(function(i) { return i.type === 'raw' && !i.category; });
    if (nocat.length) {
        html += '<optgroup label="Lainnya">';
        nocat.forEach(function(i) { html += '<option value="' + i.id + '" data-unit="' + i.unit + '">' + i.name + '</option>'; });
        html += '</optgroup>';
    }
    // Semi finished
    var semi = filtered.filter(function(i) { return i.type === 'semi_finished'; });
    if (semi.length) {
        html += '<optgroup label="Setengah Jadi">';
        semi.forEach(function(i) { html += '<option value="' + i.id + '" data-unit="' + i.unit + '">' + i.name + '</option>'; });
        html += '</optgroup>';
    }
    return html;
}

var zhishengSupplierId = {{ $zhishengId ?? 'null' }};
var suppliersData   = @json($suppliersJs);  // [{id, name, type}]
var STORES_MINE     = @json($storesMineJs);  // toko yg dipegang akun (pengirim)
var STORES_ALL      = @json($storesAllJs);   // semua toko (penerima transfer internal)

// Isi ulang dropdown Toko Penerima sesuai tipe:
// - Penjualan Internal → SEMUA toko (kirim ke toko mana pun)
// - Pembelian/eksternal masuk → hanya toko akun (barang masuk ke toko sendiri)
function updateDestStoreOptions() {
    var sel = document.getElementById('destStoreSelect');
    if (!sel) return;
    var type = document.getElementById('typeSelect').value;
    var list = (type === 'sale_internal') ? STORES_ALL : STORES_MINE;
    var prev = sel.value;
    var html = '<option value="">— Pilih Toko —</option>';
    list.forEach(function (s) { html += '<option value="' + s.id + '">' + s.name + '</option>'; });
    sel.innerHTML = html;
    // pertahankan pilihan sebelumnya bila masih ada; kalau hanya 1 toko, auto-pilih
    if (prev && list.some(function (s) { return String(s.id) === String(prev); })) sel.value = prev;
    else if (list.length === 1) sel.value = list[0].id;
}
var packagingCache  = {};
var stockPriceCache = {};
var storeStockCache = {};

// Tipe yang MEMOTONG stok toko sumber (barang keluar) & memakai harga modal dari
// toko pengirim: transfer internal (sale_internal) & penjualan eksternal (sale_external_out).
function isSourceSale(t){ return t === 'sale_internal' || t === 'sale_external_out'; }

// Rebuild isi dropdown supplier sesuai tipe mutasi
function rebuildSupplierSelect(mutationType) {
    var select = document.getElementById('supplierSelect');
    if (!select) return;
    var prev = select.value;

    // Tentukan filter: zhisheng → type='zhisheng', supplier lokal → type='local_supplier'
    var allowedType = mutationType === 'purchase_zhisheng' ? 'zhisheng' : 'local_supplier';
    var filtered    = suppliersData.filter(function(s) { return s.type === allowedType; });

    select.innerHTML = '<option value="">— Pilih Supplier —</option>';
    filtered.forEach(function(s) {
        var opt = document.createElement('option');
        opt.value       = s.id;
        opt.textContent = s.name;
        if (s.id == prev) opt.selected = true;
        select.appendChild(opt);
    });

    // Kalau hanya 1 supplier, auto-select
    if (filtered.length === 1) {
        select.value = filtered[0].id;
        onSupplierChange();
    }
}

function handleTypeChange() {
    var type       = document.getElementById('typeSelect').value;
    var isSale     = type.startsWith('sale');       // pembelian dari toko
    var isPurchase = type.startsWith('purchase') || type === 'opening_stock';
    var isOpening  = type === 'opening_stock';

    // Toko Pengirim tampil untuk transfer internal & penjualan eksternal (keduanya keluar dari toko sumber)
    var showSource = isSourceSale(type);

    document.getElementById('wrapSource').classList.toggle('d-none', !showSource);
    // Penjualan Eksternal = barang KELUAR ke pihak luar → tidak ada toko penerima.
    var noDest  = (type === 'sale_external_out');
    var destSel = document.getElementById('destStoreSelect');
    document.getElementById('wrapDest').classList.toggle('d-none', noDest);
    destSel.required = !noDest;
    if (noDest) destSel.value = '';

    // Label dinamis
    var labelDest   = document.getElementById('labelDest');
    var labelSource = document.getElementById('labelSource');
    var labelTxDate = document.getElementById('labelTxDate');
    if (isSale) {
        labelSource.textContent = 'Toko Pengirim';
        labelDest.textContent   = 'Toko Penerima';
    } else if (isOpening) {
        labelDest.textContent   = 'Toko';
    } else {
        labelDest.textContent   = 'Toko Penerima';
    }

    // Label tanggal pengiriman: stok awal → "Tanggal Stok"
    if (labelTxDate) {
        labelTxDate.innerHTML = (isOpening ? 'Tanggal Stok' : 'Tanggal Pengiriman') + ' <span class="text-danger">*</span>';
    }

    // Tanggal Penerimaan: tidak pernah wajib saat create (barang bisa masih di perjalanan)
    // Field disembunyikan untuk opening_stock karena tidak relevan

    var showSupplier = (type === 'purchase_zhisheng' || type === 'purchase_supplier');
    document.getElementById('wrapSupplier').classList.toggle('d-none', !showSupplier);
    var labelSupplier = document.getElementById('labelSupplier');
    if (labelSupplier) {
        labelSupplier.innerHTML = (type === 'purchase_zhisheng' ? 'Supplier Pusat' : 'Supplier Lokal') + ' <span class="text-danger">*</span>';
    }
    if (showSupplier) {
        rebuildSupplierSelect(type);
    }
    // Field "Pengirim" hanya untuk Pembelian Eksternal (barang masuk, diketik manual)
    document.getElementById('wrapExtSender').classList.toggle('d-none', type !== 'sale_external');
    // Field "Penjualan ke" hanya untuk Penjualan Eksternal (barang keluar)
    document.getElementById('wrapExtReceiver').classList.toggle('d-none', type !== 'sale_external_out');

    document.getElementById('wrapInvoice').classList.toggle('d-none', isOpening);
    document.getElementById('wrapDelivery').classList.toggle('d-none', isOpening);

    // Harga Jual per item HANYA untuk Penjualan Eksternal
    document.querySelectorAll('.sale-price').forEach(function(el) {
        el.classList.toggle('d-none', type !== 'sale_external_out');
    });

    // Rebuild daftar bahan sesuai tipe
    rebuildAllIngredientSelects();

    // Tampilkan / sembunyikan info harga stok toko pengirim (transfer internal & penjualan eksternal)
    if (isSourceSale(type)) {
        refreshAllStockPriceInfo();
    } else {
        document.querySelectorAll('.stock-price-info').forEach(function(el) {
            el.classList.add('d-none');
        });
    }

    // Kunci/buka dropdown bahan: tak bisa pilih bahan sebelum tipe dipilih
    toggleIngredientLock();
    toggleZhishengButton();
    updateDestStoreOptions();

    // Tombol selalu sama — semua langsung confirmed
}

// Kunci semua dropdown Bahan selama Tipe Mutasi belum dipilih
function toggleIngredientLock() {
    var hasType = !!document.getElementById('typeSelect').value;
    document.querySelectorAll('select[name^="items"][name$="[ingredient_id]"]').forEach(function(sel) {
        sel.disabled = !hasType;
        if (!hasType) sel.value = '';
        sel.title = hasType ? '' : 'Pilih Tipe Mutasi terlebih dahulu';
    });
}

// Dipanggil saat supplier berubah
function onSupplierChange() {
    var type = document.getElementById('typeSelect').value;
    if (type === 'purchase_supplier' || type === 'purchase_zhisheng') {
        rebuildAllIngredientSelects();
    }
    toggleZhishengButton();
}

// Sinkronisasi: disable opsi yang sama di dropdown lain
function syncStoreDropdowns() {
    var srcVal  = document.getElementById('sourceStoreSelect').value;
    var dstVal  = document.getElementById('destStoreSelect').value;

    document.querySelectorAll('#sourceStoreSelect option').forEach(function(opt) {
        opt.disabled = (opt.value !== '' && opt.value === dstVal);
    });
    document.querySelectorAll('#destStoreSelect option').forEach(function(opt) {
        opt.disabled = (opt.value !== '' && opt.value === srcVal);
    });
}

// Dipanggil saat toko pengirim berubah
function onSourceStoreChange() {
    var type    = document.getElementById('typeSelect').value;
    var storeId = document.getElementById('sourceStoreSelect').value;
    stockPriceCache = {};

    if (!storeId) {
        rebuildAllIngredientSelects();
        return;
    }

    syncStoreDropdowns();

    // Fetch stock summary dulu, lalu rebuild ingredient options
    fetchStoreStock(storeId, function() {
        rebuildAllIngredientSelects();
        if (isSourceSale(type)) refreshAllStockPriceInfo();
    });
}

// ── Fetch store stock summary ──────────────────────────────
function fetchStoreStock(storeId, callback) {
    if (storeStockCache[storeId] !== undefined) { callback(); return; }
    storeStockCache[storeId] = null; // mark loading
    fetch('{{ url("api-internal/store") }}/' + storeId + '/stock-summary')
        .then(r => r.json())
        .then(function(data) {
            storeStockCache[storeId] = data;
            callback();
        });
}

// ── Ingredient filtering ────────────────────────────────────
function getFilteredIngredients() {
    var type       = document.getElementById('typeSelect').value;
    var suppSelect = document.getElementById('supplierSelect');
    var suppId     = suppSelect ? suppSelect.value : null;
    var storeId    = document.getElementById('sourceStoreSelect')
                        ? document.getElementById('sourceStoreSelect').value : null;

    // Pembelian Pusat atau Supplier Lokal: filter by supplier yang dipilih
    if ((type === 'purchase_zhisheng' || type === 'purchase_supplier') && suppId) {
        return ingredientData.filter(function(i) {
            return i.packagings.some(function(p) { return p.supplier_id == suppId; });
        });
    }
    if (isSourceSale(type) && storeId) {
        var stock = storeStockCache[storeId];
        if (stock) {
            return ingredientData.filter(function(i) {
                return stock[i.id] && stock[i.id].qty > 0;
            });
        }
    }
    return ingredientData;
}

function getFilteredPackagings(ingId) {
    var type    = document.getElementById('typeSelect').value;
    var ing     = ingredientData.find(function(i) { return i.id == ingId; });
    if (!ing) return [];

    // Pembelian Pusat atau Supplier Lokal: filter kemasan by supplier yang dipilih
    var suppSelect = document.getElementById('supplierSelect');
    if ((type === 'purchase_zhisheng' || type === 'purchase_supplier') && suppSelect && suppSelect.value) {
        return ing.packagings.filter(function(p) { return p.supplier_id == suppSelect.value; });
    }
    if (isSourceSale(type)) {
        var storeId = document.getElementById('sourceStoreSelect').value;
        var stock   = storeId ? storeStockCache[storeId] : null;
        if (stock && stock[ingId]) {
            var availPacks = stock[ingId].packagings.map(Number);
            if (availPacks.length > 0) {
                return ing.packagings.filter(function(p) { return availPacks.includes(p.id); });
            }
        }
    }
    return ing.packagings;
}

function rebuildAllIngredientSelects() {
    document.querySelectorAll('.item-row').forEach(function(row) {
        rebuildIngredientSelect(row.id.replace('row-',''));
    });
}

function rebuildIngredientSelect(idx) {
    var select = document.querySelector('#row-' + idx + ' select[name$="[ingredient_id]"]');
    if (!select) return;
    var prev = select.value;

    var filtered = getFilteredIngredients();
    select.innerHTML = buildIngOptgroups(filtered);

    if (prev && filtered.find(function(i) { return i.id == prev; })) {
        select.value = prev;
    } else if (prev) {
        // Bahan yang dipilih tidak lagi tersedia — reset row
        loadPackagings('', idx);
        var infoBox = document.querySelector('#row-' + idx + ' .stock-price-info');
        if (infoBox) infoBox.classList.add('d-none');
    }
}

// Tampilkan sisa stok toko pengirim dalam format Dus / Pack / Sisa
function showAvailableQty(idx, ingId) {
    var type    = document.getElementById('typeSelect').value;
    var storeId = document.getElementById('sourceStoreSelect') ? document.getElementById('sourceStoreSelect').value : null;
    var qtyInfo = document.querySelector('#row-' + idx + ' .qty-available-info');
    if (!qtyInfo) return;

    if (!isSourceSale(type) || !storeId || !ingId) {
        qtyInfo.classList.add('d-none'); return;
    }
    var stock = storeStockCache[storeId];
    if (!stock || !stock[ingId]) { qtyInfo.classList.add('d-none'); return; }

    var row  = document.querySelector('#row-' + idx);
    var ctb  = parseFloat(row ? row.dataset.crateToBase : 0) || 0;
    var ptb  = parseFloat(row ? row.dataset.packToBase   : 0) || 0;

    // Ambil packaging_id yang dipilih (kalau ada)
    var pkgSelect = row ? row.querySelector('.packaging-select') : null;
    var pkgId     = pkgSelect ? pkgSelect.value : '';

    var ing  = ingredientData.find(function(i) { return i.id == ingId; });
    var unit = ing ? ing.unit : 'sat';
    var packagings = ing && ing.packagings ? ing.packagings : [];

    // Format helper: base qty + packaging → "X Dus + Y Pack" (tanpa pcs/gr)
    function formatBreakdown(qty, pkgCtb, pkgPtb) {
        if (pkgCtb <= 0) return parseFloat(qty.toFixed(2)).toLocaleString('id-ID') + ' ' + unit;
        var dus  = Math.floor(qty / pkgCtb);
        var rem  = qty - dus * pkgCtb;
        var pack = pkgPtb > 0 ? Math.floor(rem / pkgPtb) : 0;
        var pa = [];
        if (dus  > 0) pa.push('<strong>' + dus  + '</strong> Dus');
        if (pack > 0) pa.push('<strong>' + pack + '</strong> Pack');
        if (pa.length === 0) pa.push('<strong>0</strong>');
        return pa.join(' + ');
    }

    var html = '';
    if (pkgId && stock[ingId].per_packaging && stock[ingId].per_packaging[pkgId] !== undefined) {
        // Packaging sudah dipilih → tampil sisa kemasan ini saja
        var qtyPkg = parseFloat(stock[ingId].per_packaging[pkgId]) || 0;
        html = '<i class="bi bi-info-circle me-1"></i>Stok kemasan ini: ' + formatBreakdown(qtyPkg, ctb, ptb);
    } else {
        // Belum pilih packaging → tampil breakdown SEMUA kemasan yang ada stok
        var perPkg = stock[ingId].per_packaging || {};
        var lines = [];
        packagings.forEach(function(p) {
            var qtyP = parseFloat(perPkg[p.id] || 0);
            if (qtyP <= 0) return;
            var pCtb = (p.crate_to_pack || 0) * (p.pack_to_base || 0);
            var pPtb = parseFloat(p.pack_to_base || 0);
            lines.push('<div class="small mt-1"><span class="text-muted">' + p.packaging_name + ':</span> ' + formatBreakdown(qtyP, pCtb, pPtb) + '</div>');
        });
        // Sisa packaging NULL (data lama tanpa packaging_id)
        var qtyNull = parseFloat(perPkg[0] || 0);
        if (qtyNull > 0) {
            lines.push('<div class="small mt-1"><span class="text-muted">(tanpa kemasan):</span> <strong>' + qtyNull.toLocaleString('id-ID') + '</strong> ' + unit + '</div>');
        }
        if (lines.length === 0) {
            html = '<i class="bi bi-info-circle me-1"></i>Tidak ada stok di toko sumber.';
        } else {
            html = '<i class="bi bi-info-circle me-1"></i><strong>Stok tersedia per kemasan:</strong>' + lines.join('');
        }
    }

    qtyInfo.innerHTML = html;
    qtyInfo.classList.remove('d-none');
    qtyInfo.className = qtyInfo.className.replace(/alert-\w+/g, 'alert-secondary');
}

// Cek real-time apakah qty input melebihi stok toko pengirim
function checkRowStock(idx) {
    var type    = document.getElementById('typeSelect').value;
    var storeId = document.getElementById('sourceStoreSelect')?.value;
    // Recalc harga FIFO setiap qty berubah
    calcFifoPriceForQty(idx);
    if (!isSourceSale(type) || !storeId) return;

    var row     = document.querySelector('#row-' + idx);
    var infoBox = row?.querySelector('.qty-available-info');
    if (!row || !infoBox || infoBox.classList.contains('d-none')) return;

    var stock = storeStockCache[storeId];
    var ingId = row.querySelector('select[name$="[ingredient_id]"]')?.value;
    if (!stock || !ingId) return;

    // Stok tersedia PER KEMASAN yang dipilih (bukan total bahan)
    var pkgId  = row.querySelector('select[name$="[packaging_id]"]')?.value;
    var perPkg = (stock[ingId] || {}).per_packaging || {};
    var available = (pkgId && perPkg[pkgId] !== undefined)
        ? (parseFloat(perPkg[pkgId]) || 0)
        : (parseFloat((stock[ingId] || {}).qty) || 0);
    var ctb = parseFloat(row.dataset.crateToBase || 0);
    var ptb = parseFloat(row.dataset.packToBase  || 0);
    // Barang keluar hanya boleh pack utuh → stok tersedia di-floor ke pack (samakan dgn server)
    if (ptb > 0) available = Math.floor(available / ptb) * ptb;

    var qtyC = parseFloat(row.querySelector('input[name$="[qty_crate]"]')?.value) || 0;
    var qtyP = parseFloat(row.querySelector('input[name$="[qty_pack]"]')?.value)  || 0;
    var qtyB = parseFloat(row.querySelector('input[name$="[qty_base]"]')?.value)  || 0;
    var requested = ctb > 0 ? (qtyC * ctb + qtyP * (ptb || 1) + qtyB) : qtyB;

    // Reset warna input
    row.querySelectorAll('.qty-input').forEach(function(el) {
        el.classList.remove('is-invalid');
    });

    if (requested > available + 0.001) {
        // Merah — melebihi stok
        infoBox.className = infoBox.className.replace(/alert-\w+/g, 'alert-danger');
        var ing  = ingredientData.find(function(i) { return i.id == ingId; });
        var unit = ing ? ing.unit : 'sat';

        var avDus  = ctb > 0 ? Math.floor(available / ctb) : 0;
        var avRem  = ctb > 0 ? available - avDus * ctb : available;
        var avPack = ptb > 0 ? Math.floor(avRem / ptb) : 0;

        var avText = ctb > 0
            ? (avDus > 0 ? avDus + ' Dus ' : '') + (avPack > 0 ? avPack + ' Pack' : '') || '0'
            : available.toLocaleString('id-ID') + ' ' + unit;

        var reqDus  = ctb > 0 ? Math.floor(requested / ctb) : 0;
        var reqRem  = ctb > 0 ? requested - reqDus * ctb : requested;
        var reqPack = ptb > 0 ? Math.floor(reqRem / ptb) : 0;
        var reqText = ctb > 0
            ? (reqDus > 0 ? reqDus + ' Dus ' : '') + (reqPack > 0 ? reqPack + ' Pack' : '') || '< 1'
            : requested.toLocaleString('id-ID') + ' ' + unit;

        infoBox.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>'
            + '<strong>Stok tidak cukup!</strong> Tersedia: ' + avText.trim()
            + ' — Diminta: <strong>' + reqText.trim() + '</strong>';
        row.querySelectorAll('.qty-input').forEach(function(el) {
            if (parseFloat(el.value) > 0) el.classList.add('is-invalid');
        });
    } else {
        // Hijau/normal — aman
        infoBox.className = infoBox.className.replace(/alert-\w+/g, 'alert-secondary');
        showAvailableQty(idx, ingId);
    }
}

// Dipanggil saat ingredient dipilih di row tertentu
function onIngredientChange(ingId, idx) {
    loadPackagings(ingId, idx);
    var type = document.getElementById('typeSelect').value;
    if (isSourceSale(type)) { // transfer internal saja yg ambil harga dari stok sumber
        fetchStockPrice(ingId, idx);
    } else {
        var info = document.querySelector('#row-' + idx + ' .stock-price-info');
        if (info) info.classList.add('d-none');
    }
    showAvailableQty(idx, ingId);

    // Update label "Satuan" → "gram" / "pcs" sesuai unit bahan
    var labelBase = document.querySelector('#row-' + idx + ' .label-qty-base');
    var ing = ingredientData.find(function(i) { return i.id == ingId; });
    if (labelBase) {
        labelBase.textContent = ing ? ing.unit : '';
    }
    // Simpan unit di row supaya bisa dipakai onPriceCrateChange
    var rowEl = document.getElementById('row-' + idx);
    if (rowEl) rowEl.dataset.ingUnit = ing ? ing.unit : '';
}

// Re-fetch harga stok untuk semua row yang punya ingredient terpilih
function refreshAllStockPriceInfo() {
    document.querySelectorAll('.item-row').forEach(function(row) {
        var idx = row.id.replace('row-', '');
        var ingSelect = row.querySelector('select[class*="form-select"]');
        // Cari ingredient select (yang punya name items[n][ingredient_id])
        var ingInput = row.querySelector('select[name$="[ingredient_id]"]');
        if (ingInput && ingInput.value) {
            fetchStockPrice(ingInput.value, idx);
        }
    });
}

// Ambil harga stok dari toko pengirim untuk ingredient di row idx
function fetchStockPrice(ingId, idx) {
    var infoBox = document.querySelector('#row-' + idx + ' .stock-price-info');
    if (!infoBox) return;

    var storeId = document.getElementById('sourceStoreSelect').value;
    if (!ingId || !storeId) {
        infoBox.classList.add('d-none');
        return;
    }

    // Ambil packaging_id yang dipilih supaya batch yang muncul HANYA dari kemasan itu
    var row    = document.querySelector('#row-' + idx);
    var pkgSel = row ? row.querySelector('.packaging-select') : null;
    var pkgId  = pkgSel ? pkgSel.value : '';

    var cacheKey = ingId + '_' + storeId + '_' + (pkgId || 'all');

    var doFill = function(data) {
        if (!data.batches || data.batches.length === 0) {
            infoBox.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i><strong>Tidak ada stok</strong> bahan ini di toko pengirim.';
            infoBox.className = 'col-12 stock-price-info alert alert-warning py-1 px-2 mb-0 small';
            infoBox.classList.remove('d-none');
            return;
        }

        var fmt = function(n) { return Number(n).toLocaleString('id-ID'); };

        // Hitung total sisa stok lintas batch (Dus + Pack)
        var totalBase = data.batches.reduce(function(s, b) { return s + b.remaining_qty; }, 0);
        var ctb = data.crate_to_base || 0;
        var totalDus  = ctb > 0 ? Math.floor(totalBase / ctb) : 0;
        var totalPack = ctb > 0 ? Math.floor((totalBase - totalDus * ctb) / (ctb / (data.batches[0].crate_to_pack || 1))) : 0;
        var stockLabel = (totalDus > 0 ? totalDus + ' Dus ' : '') + (totalPack > 0 ? totalPack + ' Pack' : '') || '< 1 Pack';

        // Simpan batch FIFO di row untuk kalkulasi harga dinamis
        var row = document.querySelector('#row-' + idx);
        if (row) row._fifoBatches = data.batches;

        // Hitung harga FIFO berdasarkan qty yang sudah diinput (jika ada)
        // Kalau qty belum diisi, pakai harga batch pertama sebagai default
        calcFifoPriceForQty(idx);

        infoBox.innerHTML = '<i class="bi bi-box-seam me-1"></i>'
            + 'Sisa stok: <strong>' + stockLabel + '</strong>';
        infoBox.className = 'col-12 stock-price-info alert alert-info py-1 px-2 mb-0 small';
        infoBox.classList.remove('d-none');
    };

    if (stockPriceCache[cacheKey]) {
        doFill(stockPriceCache[cacheKey]);
        return;
    }

    infoBox.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Memuat harga stok...</span>';
    infoBox.className = 'col-12 stock-price-info alert alert-secondary py-1 px-2 mb-0 small';
    infoBox.classList.remove('d-none');

    fetch('{{ url("api-internal/ingredient") }}/' + ingId + '/stock-price?store_id=' + storeId + (pkgId ? '&packaging_id=' + pkgId : ''))
        .then(r => r.json())
        .then(function(data) {
            stockPriceCache[cacheKey] = data;
            doFill(data);
        })
        .catch(function() {
            infoBox.innerHTML = '<span class="text-danger">Gagal memuat data stok.</span>';
            infoBox.classList.remove('d-none');
        });
}

// Hitung harga FIFO berdasarkan qty yang diinput — konsumsi batch dari tertua ke terbaru
function calcFifoPriceForQty(idx) {
    var type = document.getElementById('typeSelect').value;
    if (!isSourceSale(type)) return; // harga FIFO utk transfer internal & penjualan eksternal

    var row = document.querySelector('#row-' + idx);
    if (!row || !row._fifoBatches || row._fifoBatches.length === 0) return;

    var ctb = parseFloat(row.dataset.crateToBase || 0);
    var ptb = parseFloat(row.dataset.packToBase  || 0);
    if (ctb <= 0) return;

    var qtyC = parseFloat(row.querySelector('input[name$="[qty_crate]"]')?.value) || 0;
    var qtyP = parseFloat(row.querySelector('input[name$="[qty_pack]"]')?.value)  || 0;
    var qtyB = parseFloat(row.querySelector('input[name$="[qty_base]"]')?.value)  || 0;
    var requestedBase = qtyC * ctb + qtyP * (ptb > 0 ? ptb : 1) + qtyB;

    var batches = row._fifoBatches;

    // Jika qty belum diisi, pakai harga batch FIFO pertama sebagai default
    if (requestedBase <= 0) {
        onBatchSelect(idx, batches[0].price_per_crate || 0);
        return;
    }

    // Konsumsi PACK UTUH: ambil dari batch tertua dulu; sisa gram (< 1 pack) tiap
    // batch DILEWATI — konsisten dengan FifoService::deductWholePacks di server.
    var ptbLocal    = ptb > 0 ? ptb : 1;
    var packsNeeded = Math.round(requestedBase / ptbLocal);
    var totalCost   = 0;
    var packsTaken  = 0;
    for (var i = 0; i < batches.length; i++) {
        if (packsNeeded <= 0) break;
        var wholePacks = Math.floor(batches[i].remaining_qty / ptbLocal);
        if (wholePacks <= 0) continue;        // batch cuma sisa gram → lewati
        var take = Math.min(wholePacks, packsNeeded);
        totalCost  += take * ptbLocal * batches[i].price_per_base;
        packsNeeded -= take;
        packsTaken  += take;
    }
    // Kalau pack utuh kurang, sisa pakai harga batch terakhir (estimasi)
    if (packsNeeded > 0 && batches.length > 0) {
        totalCost  += packsNeeded * ptbLocal * batches[batches.length - 1].price_per_base;
        packsTaken += packsNeeded;
    }

    var consumedBase      = packsTaken * ptbLocal;
    var blendedPriceBase  = consumedBase > 0 ? totalCost / consumedBase : 0;
    var blendedPriceCrate = Math.round(blendedPriceBase * ctb);
    onBatchSelect(idx, blendedPriceCrate);
}

// Dipanggil saat user memilih batch harga
function onBatchSelect(idx, priceCrate) {
    var priceCrateInput = document.querySelector('#row-' + idx + ' .price-crate-input');
    if (priceCrateInput) {
        // Format pakai titik per 3 digit
        priceCrateInput.value = (window.NumberFmt ? window.NumberFmt.format(priceCrate) : priceCrate);
        onPriceCrateChange(idx);
    }
}

// Load packagings untuk ingredient tertentu (filtered by type/supplier/store)
function loadPackagings(ingId, idx) {
    var packSelect = document.querySelector('#row-' + idx + ' .packaging-select');
    var wrapPack   = document.querySelector('#row-' + idx + ' .wrap-packaging');

    packSelect.innerHTML = '<option value="">— Pilih Kemasan —</option>';
    resetPriceRow(idx);

    if (!ingId) { wrapPack.classList.add('d-none'); return; }

    // Gunakan data client-side yang sudah difilter
    var filtered = getFilteredPackagings(ingId);
    if (filtered.length > 0 || ingredientData.find(function(i) { return i.id == ingId; })) {
        fillPackagings(packSelect, wrapPack, filtered, idx, ingId);
        return;
    }

    // Fallback AJAX jika ingredient tidak ada di client-side data
    if (packagingCache[ingId]) {
        fillPackagings(packSelect, wrapPack, packagingCache[ingId], idx, ingId);
        return;
    }
    fetch('{{ url("api-internal/ingredient") }}/' + ingId + '/packagings')
        .then(r => r.json())
        .then(function(data) {
            packagingCache[ingId] = data;
            fillPackagings(packSelect, wrapPack, data, idx, ingId);
        });
}

function fillPackagings(select, wrapper, packagings, idx, ingId) {
    if (!packagings.length) {
        wrapper.classList.add('d-none');
        showDirectPrice(idx);
        return;
    }
    var ing  = ingredientData.find(function(i) { return i.id == ingId; });
    var unit = ing ? ing.unit : 'sat';

    packagings.forEach(function(p) {
        var ctp     = Math.round(p.crate_to_pack);
        var ptb     = Math.round(p.pack_to_base);
        var perBase = ctp * ptb;
        var opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = '@' + ctp + ' pack';
        opt.dataset.crateToBase = perBase;
        opt.dataset.packToBase  = ptb;
        select.appendChild(opt);
    });
    wrapper.classList.remove('d-none');

    // Coba restore old packaging dari data server (saat ada validasi error)
    var row = document.getElementById('row-' + idx);
    var oldPkgSpan = row ? row.querySelector('.old-packaging-id') : null;
    var oldPkgId   = oldPkgSpan ? oldPkgSpan.textContent.trim() : '';
    if (oldPkgId) {
        select.value = oldPkgId;
        oldPkgSpan.textContent = ''; // clear agar tidak restore dua kali
    } else if (packagings.length === 1) {
        // Auto-select satu-satunya kemasan
        select.value = packagings[0].id;
    }

    // Trigger packaging change untuk set crateToBase + tampilkan harga
    if (select.value) onPackagingChange(idx);

    // Restore harga setelah packaging terset (crateToBase sudah ada)
    if (oldPkgId) {
        var oldPriceSpan = row ? row.querySelector('.old-price-per-base') : null;
        var oldPriceBase = oldPriceSpan ? parseFloat(oldPriceSpan.textContent.trim()) : 0;
        if (oldPriceBase > 0) {
            var ctb = parseFloat(row.dataset.crateToBase || 0);
            var priceInput = row.querySelector('.price-crate-input');
            if (priceInput && ctb > 0) {
                priceInput.value = NumberFmt.format(Math.round(oldPriceBase * ctb));
                onPriceCrateChange(idx);
            }
            if (oldPriceSpan) oldPriceSpan.textContent = '';
        }
    }
}

function onPackagingChange(idx) {
    var select  = document.querySelector('#row-' + idx + ' .packaging-select');
    var opt     = select.options[select.selectedIndex];
    var crateToBase = parseFloat(opt.dataset.crateToBase || 0);
    var packToBase  = parseFloat(opt.dataset.packToBase  || 0);

    resetPriceRow(idx);

    if (!select.value) return;

    // Tampilkan input harga per Dus
    var wrapPrice   = document.querySelector('#row-' + idx + ' .wrap-price-crate');
    wrapPrice.classList.remove('d-none');

    // Simpan konversi di row untuk kalkulasi
    document.querySelector('#row-' + idx).dataset.crateToBase = crateToBase;
    document.querySelector('#row-' + idx).dataset.packToBase  = packToBase;

    var ingInput = document.querySelector('#row-' + idx + ' select[name$="[ingredient_id]"]');
    var type     = document.getElementById('typeSelect').value;

    // Pembelian (pusat/supplier/EKSTERNAL) → harga/dus BISA diketik manual.
    // Transfer internal → readonly (otomatis dari harga stok sumber).
    var priceCrate = document.querySelector('#row-' + idx + ' .price-crate-input');
    if (priceCrate) {
        var editable = ['purchase_zhisheng','purchase_supplier','sale_external'].includes(type);
        priceCrate.readOnly = !editable;
        priceCrate.classList.toggle('bg-light', !editable);
    }
    // Fetch batch price hanya untuk transfer internal (mengurangi stok toko sumber).
    // Pembelian eksternal = stok MASUK, harga diketik manual → jangan fetch/timpa.
    if (isSourceSale(type) && ingInput && ingInput.value) {
        fetchStockPrice(ingInput.value, idx);
    }
    // Tipe Pembelian → auto-fill Harga/Dus dari pembelian terakhir GLOBAL (semua toko, per kemasan).
    // Hanya kalau field harga masih kosong/0 → tidak menimpa input manual.
    if (['purchase_zhisheng','purchase_supplier'].includes(type) && ingInput && ingInput.value) {
        var priceInput = document.querySelector('#row-' + idx + ' .price-crate-input');
        if (priceInput && (!priceInput.value || NumberFmt.parse(priceInput.value) === 0)) {
            fetch('{{ url("api-internal/ingredient") }}/' + ingInput.value
                + '/last-price?type=' + encodeURIComponent(type)
                + '&packaging_id=' + encodeURIComponent(select.value))
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d.price_per_dus > 0 && priceInput && (!priceInput.value || NumberFmt.parse(priceInput.value) === 0)) {
                        priceInput.value = NumberFmt.format(d.price_per_dus);
                        onPriceCrateChange(idx);
                    }
                }).catch(function(){});
        }
    }
    if (ingInput) showAvailableQty(idx, ingInput.value);
}

function onPriceCrateChange(idx) {
    var priceCrate  = NumberFmt.parse(document.querySelector('#row-' + idx + ' .price-crate-input').value);
    var crateToBase = parseFloat(document.querySelector('#row-' + idx).dataset.crateToBase || 1);
    var priceBase   = crateToBase > 0 ? priceCrate / crateToBase : 0;

    // Set hidden price_per_base (presisi tinggi supaya tidak ada precision loss)
    document.querySelector('#row-' + idx + ' .price-per-base-hidden').value = priceBase.toFixed(8);

    // Sembunyikan info konversi per-gram (tidak relevan untuk user)
    var info = document.querySelector('#row-' + idx + ' .price-info');
    if (info) info.classList.add('d-none');

    recalcTotals();
}

// ════════════════════════════════════════════════════════════════════
// HITUNG SUBTOTAL & GRAND TOTAL — dipanggil setiap qty/price berubah
// ════════════════════════════════════════════════════════════════════
function recalcTotals() {
    var container = document.getElementById('totalsContainer');
    if (!container) return;

    var rows = document.querySelectorAll('.item-row');
    var fmt  = function(n) { return Number(Math.round(n)).toLocaleString('id-ID'); };

    var lines = [];
    var grand = 0;

    rows.forEach(function(row) {
        var ingSel = row.querySelector('select[name$="[ingredient_id]"]');
        if (!ingSel || !ingSel.value) return;

        var ing       = ingredientData.find(function(i){ return i.id == ingSel.value; });
        var ingName   = ing ? ing.name : '?';
        var pkgSel    = row.querySelector('.packaging-select');
        var pkgLabel  = pkgSel && pkgSel.value
            ? (pkgSel.options[pkgSel.selectedIndex]?.textContent.split(' (')[0] || '')
            : '';

        var ctb       = parseFloat(row.dataset.crateToBase || 0) || 0;
        var ptb       = parseFloat(row.dataset.packToBase  || 0) || 0;
        var qtyC      = parseFloat(row.querySelector('input[name$="[qty_crate]"]')?.value) || 0;
        var qtyP      = parseFloat(row.querySelector('input[name$="[qty_pack]"]')?.value)  || 0;
        var qtyB      = parseFloat(row.querySelector('input[name$="[qty_base]"]')?.value)  || 0;
        var totalBase = (qtyC * ctb) + (qtyP * ptb) + qtyB;

        var priceBase = parseFloat(row.querySelector('.price-per-base-hidden')?.value) || 0;
        var subtotal  = totalBase * priceBase;

        if (subtotal <= 0) return;
        grand += subtotal;

        // Format qty
        var qtyParts = [];
        if (qtyC > 0) qtyParts.push(qtyC + ' Dus');
        if (qtyP > 0) qtyParts.push(qtyP + ' Pack');
        if (qtyB > 0) qtyParts.push(qtyB + ' ' + (ing ? ing.unit : 'sat'));
        var qtyStr = qtyParts.join(' + ');

        lines.push(
            '<div class="d-flex justify-content-between border-bottom py-1 small">'
            + '<div><strong>' + ingName + '</strong>'
            + (pkgLabel ? ' <span class="text-muted">· ' + pkgLabel + '</span>' : '')
            + ' <span class="text-muted ms-1">(' + qtyStr + ')</span></div>'
            + '<div class="fw-semibold">Rp ' + fmt(subtotal) + '</div>'
            + '</div>'
        );
    });

    if (lines.length === 0) {
        container.innerHTML = '<div class="text-muted small text-center py-2">Isi data item dulu untuk lihat subtotal.</div>';
        return;
    }

    container.innerHTML =
        '<div class="fw-semibold mb-2"><i class="bi bi-receipt me-1"></i>Ringkasan Total</div>'
        + lines.join('')
        + '<div class="d-flex justify-content-between mt-2 pt-2 border-top">'
        + '<div class="fw-bold fs-6">GRAND TOTAL</div>'
        + '<div class="fw-bold fs-5 text-success">Rp ' + fmt(grand) + '</div>'
        + '</div>';
}

// Auto-recalc setiap kali qty input berubah (delegasi event)
document.addEventListener('input', function(e) {
    if (e.target.matches('.price-crate-input')) {
        var row = e.target.closest('[id^="row-"]');
        if (row) onPriceCrateChange(row.id.replace('row-', '')); // update hidden price_per_base + recalc
        return;
    }
    if (e.target.matches('.qty-input, .price-direct-input')) {
        recalcTotals();
    }
});
document.addEventListener('change', function(e) {
    if (e.target.matches('select[name$="[ingredient_id]"], .packaging-select')) {
        recalcTotals();
    }
});

function showDirectPrice(idx) {
    // Tidak ada packaging → input harga per satuan langsung
    var wrapPrice = document.querySelector('#row-' + idx + ' .wrap-price-direct');
    wrapPrice.classList.remove('d-none');
}

function resetPriceRow(idx) {
    document.querySelector('#row-' + idx + ' .wrap-price-crate').classList.add('d-none');
    document.querySelector('#row-' + idx + ' .wrap-price-direct').classList.add('d-none');
    var info = document.querySelector('#row-' + idx + ' .price-info');
    if (info) info.classList.add('d-none');
    document.querySelector('#row-' + idx + ' .price-per-base-hidden').value = '';
}

function addRow() {
    var container = document.getElementById('itemsContainer');
    var idx = rowCount++;

    var filtered   = getFilteredIngredients();
    var ingOptions = buildIngOptgroups(filtered);

    var html = buildRowHTML(idx, ingOptions);
    var tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.id = 'row-' + idx;
    tr.innerHTML = html;
    container.appendChild(tr);

    updateRemoveButtons();
    toggleIngredientLock();
}

// Tampilkan tombol "Muat Semua Bahan Zhisheng" hanya untuk Pembelian Pusat + supplier terpilih.
function toggleZhishengButton() {
    var btn = document.getElementById('btnLoadZhisheng');
    if (!btn) return;
    var type   = document.getElementById('typeSelect').value;
    var suppId = document.getElementById('supplierSelect') ? document.getElementById('supplierSelect').value : '';
    btn.classList.toggle('d-none', !(type === 'purchase_zhisheng' && suppId));
}

// Muat semua bahan baku Zhisheng jadi baris (bahan+kemasan terisi, harga auto dari
// pembelian terakhir & bisa diedit). Qty dikosongkan — user isi yang dibeli saja.
function loadZhishengItems() {
    var type   = document.getElementById('typeSelect').value;
    var suppId = document.getElementById('supplierSelect') ? document.getElementById('supplierSelect').value : '';
    if (type !== 'purchase_zhisheng' || !suppId) return;

    // Kumpulkan pasangan (bahan baku × kemasan Zhisheng)
    var pairs = [];
    ingredientData.forEach(function (i) {
        if (i.type === 'semi_finished') return;   // hanya bahan baku
        i.packagings.forEach(function (p) {
            if (String(p.supplier_id) === String(suppId)) pairs.push({ ing: i.id, pkg: p.id });
        });
    });
    if (!pairs.length) { alert('Tidak ada bahan baku dengan kemasan dari supplier Pusat ini.'); return; }

    if (document.querySelectorAll('.item-row').length > 0 &&
        !confirm('Muat ' + pairs.length + ' bahan Zhisheng? Semua baris yang ada sekarang akan diganti.')) return;

    document.querySelectorAll('.item-row').forEach(function (r) { r.remove(); });

    pairs.forEach(function (pair) {
        addRow();
        var idx = rowCount - 1;
        var sel = document.querySelector('#row-' + idx + ' select[name$="[ingredient_id]"]');
        if (!sel) return;
        sel.value = pair.ing;
        onIngredientChange(pair.ing, idx);   // load kemasan (sinkron utk data client-side)

        var pkgSel = document.querySelector('#row-' + idx + ' .packaging-select');
        if (pkgSel && String(pkgSel.value) !== String(pair.pkg)) {
            pkgSel.value = pair.pkg;
            if (String(pkgSel.value) === String(pair.pkg)) onPackagingChange(idx);  // auto-isi harga
        }
    });
    updateRemoveButtons();
}

function buildRowHTML(idx, ingOptions) {
    return `
    <td>
        <select name="items[${idx}][ingredient_id]" class="form-select form-select-sm" required onchange="onIngredientChange(this.value, ${idx})">
            ${ingOptions}
        </select>
        <div class="qty-available-info d-none small text-muted mt-1"></div>
        <div class="stock-price-info d-none small text-info mt-1"></div>
    </td>
    <td>
        <div class="wrap-packaging d-none">
            <select name="items[${idx}][packaging_id]" class="form-select form-select-sm packaging-select" onchange="onPackagingChange(${idx})">
                <option value="">— Pilih Kemasan —</option>
            </select>
        </div>
        <span class="text-muted small no-packaging-label">—</span>
    </td>
    <td>
        <input type="number" name="items[${idx}][qty_crate]" class="form-control form-control-sm qty-input" min="0" placeholder="0" oninput="checkRowStock(${idx})">
    </td>
    <td>
        <input type="number" name="items[${idx}][qty_pack]" class="form-control form-control-sm qty-input" min="0" placeholder="0" oninput="checkRowStock(${idx})">
    </td>
    <td>
        <input type="number" name="items[${idx}][qty_base]" class="form-control form-control-sm qty-input" step="0.01" min="0" placeholder="0" oninput="checkRowStock(${idx})">
        <span class="d-none label-qty-base"></span>
    </td>
    <td>
        <div class="wrap-price-crate d-none">
            <div class="input-group input-group-sm">
                <span class="input-group-text">Rp</span>
                <input type="text" class="form-control form-control-sm price-crate-input num-fmt bg-light" placeholder="0" readonly>
            </div>
            <div class="form-text price-info d-none text-primary"></div>
        </div>
        <div class="wrap-price-direct d-none">
            <div class="input-group input-group-sm">
                <span class="input-group-text">Rp</span>
                <input type="number" class="form-control form-control-sm" step="0.01" min="0" placeholder="0"
                       oninput="document.querySelector('#row-${idx} .price-per-base-hidden').value = this.value">
            </div>
        </div>
        <div class="sale-price d-none mt-1">
            <div class="input-group input-group-sm">
                <span class="input-group-text">Jual</span>
                <span class="input-group-text">Rp</span>
                <input type="number" name="items[${idx}][selling_price_per_base]" class="form-control form-control-sm" step="0.01" min="0" placeholder="0">
            </div>
        </div>
        <span class="text-muted small no-price-label">—</span>
        <input type="hidden" name="items[${idx}][price_per_base]" class="price-per-base-hidden" value="0">
    </td>
    <td class="text-center">
        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" onclick="removeRow(${idx})" style="display:none" title="Hapus">
            <i class="bi bi-x-lg"></i>
        </button>
    </td>`;
}

function removeRow(idx) {
    document.getElementById('row-' + idx).remove();
    updateRemoveButtons();
}

// ── Validasi tanggal: delivery_date tidak boleh lebih muda dari transaction_date ──
function validateDateOrder() {
    var txInput  = document.getElementById('inputTxDate');
    var delInput = document.getElementById('inputDelivery');
    if (!txInput || !delInput) return true;

    var txVal  = txInput.value;
    var delVal = delInput.value;

    // Reset error
    txInput.classList.remove('is-invalid');
    delInput.classList.remove('is-invalid');
    document.getElementById('errTxDate').textContent   = '';
    document.getElementById('errDelivery').textContent = '';

    if (txVal && delVal && delVal < txVal) {
        delInput.classList.add('is-invalid');
        document.getElementById('errDelivery').textContent =
            'Tanggal penerimaan tidak boleh lebih awal dari tanggal pengiriman.';
        return false;
    }
    return true;
}

document.getElementById('inputTxDate').addEventListener('change', validateDateOrder);
document.getElementById('inputDelivery').addEventListener('change', validateDateOrder);

// Validasi qty saat submit
document.getElementById('mutasiForm').addEventListener('submit', function(e) {
    if (!validateDateOrder()) { e.preventDefault(); return; }
    var type    = document.getElementById('typeSelect').value;
    var storeId = document.getElementById('sourceStoreSelect') ? document.getElementById('sourceStoreSelect').value : null;
    if (!isSourceSale(type) || !storeId) return;
    var stock = storeStockCache[storeId];
    if (!stock) return;

    var errors = [];
    document.querySelectorAll('.item-row').forEach(function(row) {
        var idx    = row.id.replace('row-','');
        var ingSel = row.querySelector('select[name$="[ingredient_id]"]');
        if (!ingSel || !ingSel.value) return;

        var packSel = row.querySelector('.packaging-select');
        var ctb     = parseFloat(row.dataset.crateToBase || 0);
        var qtyC    = parseFloat(row.querySelector('input[name$="[qty_crate]"]').value) || 0;
        var qtyP    = parseFloat(row.querySelector('input[name$="[qty_pack]"]').value)  || 0;
        var qtyB    = parseFloat(row.querySelector('input[name$="[qty_base]"]').value)  || 0;
        var packSel2 = row.querySelector('.packaging-select');
        var ptb     = parseFloat(packSel2 ? (packSel2.options[packSel2.selectedIndex] || {}).dataset?.packToBase || 0 : 0);
        var reqQty  = ctb > 0 ? (qtyC * ctb + qtyP * (ptb || 1) + qtyB) : qtyB;

        var available = (stock[ingSel.value] || {}).qty || 0;
        if (reqQty > available + 0.001) {
            var ingName = ingSel.options[ingSel.selectedIndex].textContent;
            errors.push(ingName + ': diminta ' + reqQty.toLocaleString('id-ID') + ' sat, tersedia ' + available.toLocaleString('id-ID') + ' sat');
        }
    });

    if (errors.length) {
        e.preventDefault();
        alert('Stok tidak mencukupi:\n' + errors.join('\n'));
    }
});

function updateRemoveButtons() {
    var rows = document.querySelectorAll('.item-row');
    rows.forEach(function(row) {
        var btn = row.querySelector('.btn-remove-row');
        if (btn) btn.style.display = rows.length > 1 ? 'inline-block' : 'none';
    });
}

// Kondisi awal
document.addEventListener('DOMContentLoaded', function() {
    syncStoreDropdowns();

    // Jika ada old type (setelah validasi error), restore state form
    var typeSelect = document.getElementById('typeSelect');
    if (typeSelect && typeSelect.value) {
        handleTypeChange();
        // Restore packaging & price untuk setiap row yang sudah punya ingredient
        document.querySelectorAll('.item-row').forEach(function(row) {
            var idx    = row.id.replace('row-', '');
            var ingSel = row.querySelector('select[name$="[ingredient_id]"]');
            if (ingSel && ingSel.value) {
                onIngredientChange(ingSel.value, idx);
            }
        });
        updateRemoveButtons();
        recalcTotals();
    }
    toggleIngredientLock();
});
</script>
@endpush
