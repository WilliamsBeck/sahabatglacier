@extends('layouts.app')
@section('title', isset($ingredient) ? 'Edit Bahan' : 'Tambah Bahan')

@section('content')

    <div class="pb-2">

        <div class="page-header d-flex justify-content-between align-items-start">
            <div>
                <h1 class="page-title">{{ isset($ingredient) ? 'Edit Bahan: ' . $ingredient->name : 'Tambah Bahan' }}</h1>
                <p class="page-subtitle">Lengkapi data dasar, kemasan, atau komposisi bahan</p>
            </div>
            <a href="{{ route('master.ingredients.index') }}" class="btn btn-outline-secondary btn-back">
                <i class="bi bi-arrow-left me-1"></i>Kembali
            </a>
        </div>

        <form id="ingredientForm" method="POST"
            action="{{ isset($ingredient) ? route('master.ingredients.update', $ingredient) : route('master.ingredients.store') }}">
            @csrf @if(isset($ingredient)) @method('PUT') @endif

            <div class="row g-4">
                {{-- KOLOM KIRI: Info Dasar --}}
                <div class="col-lg-4">
                    <div class="card p-4">
                        <div class="fw-semibold border-bottom pb-2 mb-3">
                            <i class="bi bi-info-circle-fill me-2 text-primary"></i> Info Dasar
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Nama Bahan <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control"
                                value="{{ old('name', $ingredient->name ?? '') }}" placeholder="Contoh: Susu Full Cream"
                                required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Tipe Bahan <span class="text-danger">*</span></label>
                            <select name="type" id="typeSelect" class="form-select" required
                                onchange="onTypeChange()">
                                <option value="">— Pilih Tipe —</option>
                                <option value="raw" {{ old('type', $ingredient->type ?? '') === 'raw' ? 'selected' : '' }}>Raw</option>
                                <option value="semi_finished" {{ old('type', $ingredient->type ?? '') === 'semi_finished' ? 'selected' : '' }}>Semi</option>
                            </select>
                        </div>

                        <div class="mb-4" id="wrapCategory"
                            style="{{ old('type', $ingredient->type ?? '') === 'semi_finished' ? 'display:none' : '' }}">
                            <label class="form-label">Kategori</label>
                            <select name="category" class="form-select">
                                <option value="">— Pilih Kategori —</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->name }}" {{ old('category', $ingredient->category ?? '') === $cat->name ? 'selected' : '' }}>
                                        {{ $cat->label }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text mt-2 fw-medium text-primary" style="font-size: 0.8rem;">
                                <a href="{{ route('master.categories.index') }}" target="_blank"
                                    class="text-decoration-none">
                                    <i class="bi bi-plus-circle me-1"></i>Kelola master kategori
                                </a>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Satuan Dasar <span class="text-danger">*</span></label>
                            <select name="unit_base" id="unitBase" class="form-select" required>
                                <option value="">— Pilih Satuan —</option>
                                <option value="gram" {{ old('unit_base', $ingredient->unit_base ?? '') === 'gram' ? 'selected' : '' }}>Gram</option>
                                <option value="pcs" {{ old('unit_base', $ingredient->unit_base ?? '') === 'pcs' ? 'selected' : '' }}>Pcs</option>
                            </select>
                        </div>

                        <div class="mb-2 pt-2 border-top">
                            <div class="form-check form-switch d-flex align-items-center gap-3 ps-0 mt-3">
                                <input class="form-check-input m-0" type="checkbox" name="is_active" value="1" id="actIng"
                                    {{ old('is_active', $ingredient->is_active ?? true) ? 'checked' : '' }}>
                                <label class="form-check-label form-label mb-0" for="actIng" style="cursor: pointer;">Set
                                    Bahan Aktif</label>
                            </div>

                            {{-- Bahan di luar resep (mis. Single/Double/Big Bag) selalu punya
                                 HPP Ideal 0, jadi selisihnya semu. Saklar ini menyamakan
                                 Ideal dengan Aktual supaya selisihnya nol. --}}
                            <div class="form-check form-switch d-flex align-items-center gap-3 ps-0 mt-3">
                                <input class="form-check-input m-0" type="checkbox" name="ideal_follows_actual" value="1" id="idealIkutAktual"
                                    {{ old('ideal_follows_actual', $ingredient->ideal_follows_actual ?? false) ? 'checked' : '' }}>
                                <label class="form-check-label form-label mb-0" for="idealIkutAktual" style="cursor: pointer;">
                                    HPP Ideal ikut HPP Aktual
                                </label>
                            </div>
                            <div class="form-text ms-0" style="margin-left:2.6rem">
                                Untuk bahan yang tidak ada di resep menu - selisih HPP dibuat nol.
                            </div>
                        </div>
                    </div>
                </div>

                {{-- KOLOM KANAN: Kemasan / Komposisi --}}
                <div class="col-lg-8">

                    {{-- BAGIAN KEMASAN (HANYA RAW) --}}
                    <div id="sectionPackaging"
                        style="{{ (old('type', $ingredient->type ?? '') === 'semi_finished') ? 'display:none' : '' }}">
                        <div class="card p-4">
                            <div class="fw-semibold border-bottom pb-2 mb-3 d-flex justify-content-between align-items-center">
                                <div><i class="bi bi-box-seam-fill me-2 text-primary"></i> Data Kemasan</div>
                                @php
                                    $currentUnit = old('unit_base', $ingredient->unit_base ?? '');
                                    $unitLabel = $currentUnit === 'gram' ? 'Gram' : ($currentUnit === 'pcs' ? 'Pcs' : 'Satuan');
                                @endphp
                                <span class="badge bg-light text-dark border px-3 py-2 fw-semibold"
                                    style="font-size: 0.85rem;">Format: Dus / Pack / <span
                                        id="headerUnitLabel">{{ $unitLabel }}</span></span>
                            </div>

                            {{-- Kemasan yg sudah ada — EDITABLE --}}
                            @if(isset($ingredient) && $ingredient->packagings->count())
                                <div class="mb-4">
                                    <div class="text-secondary small fw-bold mb-3 text-uppercase"
                                        style="letter-spacing: 0.5px;">Kemasan Tersimpan:</div>
                                    @foreach($ingredient->packagings as $pack)
                                        <div class="border rounded p-3 mb-3 {{ !$pack->is_active ? 'opacity-75' : '' }}"
                                            id="pkgRow-{{ $pack->id }}"
                                            style="background:{{ $pack->is_active ? '#ffffff' : '#f8fafc' }}">
                                            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge bg-dark text-white rounded-pill px-2 py-1"
                                                        style="font-size:0.7rem">#{{ $loop->iteration }}</span>
                                                    <span
                                                        class="badge pkg-status-badge {{ $pack->is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}"
                                                        id="pkgStatusBadge-{{ $pack->id }}" style="font-size:0.7rem">
                                                        {{ $pack->is_active ? 'Aktif' : 'Nonaktif' }}
                                                    </span>
                                                </div>
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="form-check form-switch m-0 d-flex align-items-center gap-2 ps-0">
                                                        <input class="form-check-input pkg-toggle-active m-0" type="checkbox"
                                                            id="pkgToggle-{{ $pack->id }}" data-packaging-id="{{ $pack->id }}" {{ $pack->is_active ? 'checked' : '' }}>
                                                        <label class="form-check-label small fw-semibold"
                                                            for="pkgToggle-{{ $pack->id }}" style="cursor:pointer">Tampil di
                                                            Stok</label>
                                                    </div>
                                                    <button type="button" class="btn btn-sm btn-light text-danger border fw-medium"
                                                        onclick="deletePackaging({{ $pack->id }}, this)">
                                                        <i class="bi bi-trash"></i> Hapus
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label" style="font-size: 0.8rem;">Nama Kemasan</label>
                                                    <input type="text" name="existing_packagings[{{ $pack->id }}][packaging_name]"
                                                        class="form-control form-control-sm packaging-name-input"
                                                        value="{{ $pack->packaging_name }}" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label" style="font-size: 0.8rem;">Supplier</label>
                                                    <select name="existing_packagings[{{ $pack->id }}][supplier_id]"
                                                        class="form-select form-select-sm packaging-supplier-input">
                                                        <option value="">— Tidak Ada —</option>
                                                        @foreach($suppliers as $s)
                                                            <option value="{{ $s->id }}" {{ $pack->supplier_id == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label" style="font-size: 0.8rem;">Jml Pack per Dus</label>
                                                    <input type="number" name="existing_packagings[{{ $pack->id }}][crate_to_pack]"
                                                        class="form-control form-control-sm crate-input"
                                                        value="{{ $pack->crate_to_pack }}" min="1" required>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label" style="font-size: 0.8rem;">Isi per Pack (<span
                                                            class="unit-label">{{ ucfirst($ingredient->unit_base) }}</span>)</label>
                                                    <input type="text" name="existing_packagings[{{ $pack->id }}][pack_to_base]"
                                                        class="form-control form-control-sm pack-input"
                                                        value="{{ $pack->pack_to_base + 0 }}" required>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label" style="font-size: 0.8rem;">Total Isi per
                                                        Dus</label>
                                                    <input type="text"
                                                        class="form-control form-control-sm bg-light text-muted fw-bold total-display"
                                                        value="{{ number_format($pack->crate_to_pack * $pack->pack_to_base, 2, ',', '.') }} {{ ucfirst($ingredient->unit_base) }}"
                                                        readonly>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @php $hasExistingPack = isset($ingredient) && $ingredient->packagings->count(); @endphp

                            {{-- Form tambah kemasan baru --}}
                            <div class="text-secondary small fw-bold mb-2 text-uppercase" style="letter-spacing: 0.5px;">
                                {{ $hasExistingPack ? 'Tambah Kemasan Baru:' : 'Data Kemasan:' }}
                            </div>
                            <div id="packagingRows">
                                @unless($hasExistingPack)
                                    <div class="border rounded p-3 mb-3 packaging-row">
                                        <button type="button" class="btn-close position-absolute top-0 end-0 m-3 remove-row"
                                            style="display:none"></button>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label" style="font-size: 0.8rem;">Nama Kemasan</label>
                                                <input type="text" name="packagings[0][packaging_name]"
                                                    class="form-control form-control-sm packaging-name-input"
                                                    placeholder="Contoh: Dus Zhisheng">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" style="font-size: 0.8rem;">Supplier</label>
                                                <select name="packagings[0][supplier_id]"
                                                    class="form-select form-select-sm packaging-supplier-input">
                                                    <option value="">— Tidak Ada —</option>
                                                    @foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}
                                                    </option>@endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" style="font-size: 0.8rem;">Jml Pack per Dus</label>
                                                <input type="number" name="packagings[0][crate_to_pack]"
                                                    class="form-control form-control-sm crate-input" min="1"
                                                    placeholder="Contoh: 12">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" style="font-size: 0.8rem;">Isi per Pack (<span
                                                        class="unit-label">satuan</span>)</label>
                                                <input type="text" name="packagings[0][pack_to_base]"
                                                    class="form-control form-control-sm pack-input"
                                                    placeholder="Contoh: 500">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" style="font-size: 0.8rem;">Total Isi per
                                                    Dus</label>
                                                <input type="text"
                                                    class="form-control form-control-sm bg-light text-muted fw-bold total-display"
                                                    readonly placeholder="Dihitung otomatis">
                                            </div>
                                        </div>
                                    </div>
                                @endunless
                            </div>

                            <button type="button" id="addPackaging"
                                class="btn btn-outline-primary btn-sm mt-2">
                                <i class="bi bi-plus-lg me-1"></i> Tambah Baris Kemasan
                            </button>
                        </div>
                    </div>{{-- end sectionPackaging --}}

                    {{-- BAGIAN KOMPOSISI (HANYA SEMI FINISHED) --}}
                    <div id="sectionComposition"
                        style="{{ (old('type', $ingredient->type ?? '') !== 'semi_finished') ? 'display:none' : '' }}">
                        <div class="card p-4">
                            <div class="fw-semibold border-bottom pb-2 mb-3"><i class="bi bi-layers-fill me-2 text-primary"></i> Komposisi Bahan
                                Baku</div>

                            {{-- Komposisi tersimpan (hanya mode edit) --}}
                            @if(isset($ingredient) && $ingredient->compositions->count())
                                <div class="mb-4">
                                    <div class="text-secondary small fw-bold mb-3 text-uppercase"
                                        style="letter-spacing: 0.5px;">Komposisi Tersimpan:</div>
                                    @foreach($ingredient->compositions as $comp)
                                        <div class="d-flex justify-content-between align-items-center mb-2 p-3 border rounded"
                                            style="background-color: #f8fafc; border-color: #e2e8f0;">
                                            <div class="flex-grow-1">
                                                <div class="fw-bold text-dark" style="font-size: 0.95rem;">{{ $comp->child->name }}
                                                </div>
                                                <div class="text-muted small fw-medium mt-1">
                                                    <i class="bi bi-arrow-return-right me-1"></i>
                                                    {{ number_format($comp->qty_needed, 4, ',', '.') }}
                                                    {{ $comp->child->unit_base }} per 1 {{ $ingredient->unit_base }}
                                                </div>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-light text-danger border fw-medium px-3"
                                                onclick="uiConfirm('Hapus komposisi ini?', {type:'error', danger:true, confirmText:'Ya, hapus'}).then(ok => { if(ok) document.getElementById('delComp{{ $comp->id }}').click() })">
                                                <i class="bi bi-trash"></i> Hapus
                                            </button>
                                            <input type="checkbox" name="delete_compositions[]" value="{{ $comp->id }}"
                                                id="delComp{{ $comp->id }}" style="display:none" form="ingredientForm">
                                        </div>
                                    @endforeach
                                    <hr class="my-4" style="border-color: #e2e8f0;">
                                </div>
                            @endif

                            {{-- Form tambah komposisi baru --}}
                            <div class="mb-4 bg-light p-3 rounded border" style="border-color: #e2e8f0 !important;">
                                <label class="form-label d-flex align-items-center mb-0 gap-2">
                                    Jika membuat
                                    <input type="text" name="total_output" id="totalOutput"
                                        class="form-control form-control-sm text-center num-dec" style="width:110px"
                                        placeholder="cth: 11.000">
                                    <span class="badge bg-white text-dark border px-2 py-1"><span
                                            id="unitLabelComp">{{ $ingredient->unit_base ?? 'gram' }}</span>
                                        {{ $ingredient->name ?? 'ini' }}</span>, butuh bahan:
                                </label>
                            </div>

                            <div class="row g-2 mb-2 px-1">
                                <div class="col-5"><span class="form-label text-muted" style="font-size: 0.8rem;">Bahan
                                        Baku Master</span></div>
                                <div class="col-3"><span class="form-label text-muted" style="font-size: 0.8rem;">Qty
                                        digunakan</span></div>
                                <div class="col-3"><span class="form-label text-muted" style="font-size: 0.8rem;">Per 1
                                        <span id="perUnitHeaderLabel">{{ $ingredient->unit_base ?? 'gram' }}</span></span>
                                </div>
                                <div class="col-1"></div>
                            </div>

                            <div id="compositionRows">
                                <div class="composition-row row g-2 mb-3 align-items-center">
                                    <div class="col-5">
                                        <select name="compositions[0][child_id]"
                                            class="form-select form-select-sm child-select">
                                            <option value="">— Pilih Bahan —</option>
                                            @foreach($rawIngredients as $raw)
                                                <option value="{{ $raw->id }}" data-unit="{{ $raw->unit_base }}">
                                                    {{ $raw->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-3">
                                        <div class="input-group input-group-sm">
                                            <input type="text" name="compositions[0][qty_used]"
                                                class="form-control form-control-sm qty-used-input num-dec"
                                                placeholder="cth: 0,8"
                                                style="border-right: 0; border-radius: 8px 0 0 8px !important;">
                                            <span
                                                class="input-group-text bg-white small unit-label-comp text-muted fw-semibold"
                                                style="border: 1px solid #cbd5e1; border-left: 0; border-radius: 0 8px 8px 0;">satuan</span>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <input type="text"
                                            class="form-control form-control-sm bg-light per-unit-display text-muted fw-bold"
                                            readonly placeholder="—">
                                    </div>
                                    <div class="col-1 d-flex justify-content-center">
                                        <button type="button"
                                            class="btn btn-light text-danger border btn-sm remove-comp-row"
                                            style="display:none; border-radius: 8px;">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <button type="button" id="addComposition"
                                class="btn btn-outline-primary btn-sm mt-2">
                                <i class="bi bi-plus-lg me-1"></i> Tambah Bahan Lagi
                            </button>
                        </div>
                    </div>{{-- end sectionComposition --}}

                </div>
            </div>

            <div class="d-flex gap-2 mt-4 pt-4 border-top">
                <button type="submit" name="action" value="save" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> Simpan Data Bahan
                </button>
                @unless(isset($ingredient))
                    <button type="submit" name="action" value="save_and_new" class="btn btn-outline-secondary">
                        <i class="bi bi-plus-circle me-1"></i> Simpan &amp; Tambah Baru
                    </button>
                @endunless
                <a href="{{ route('master.ingredients.index') }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>

    <template id="packagingTemplate">
        <div class="border rounded p-3 mb-3 packaging-row">
            <button type="button" class="btn-close position-absolute top-0 end-0 m-3 remove-row"></button>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" style="font-size: 0.8rem;">Nama Kemasan</label>
                    <input type="text" name="" class="form-control form-control-sm packaging-name-input"
                        placeholder="Contoh: Dus Zhisheng">
                </div>
                <div class="col-md-6">
                    <label class="form-label" style="font-size: 0.8rem;">Supplier</label>
                    <select name="" class="form-select form-select-sm packaging-supplier-input">
                        <option value="">— Tidak Ada —</option>
                        @foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" style="font-size: 0.8rem;">Jml Pack per Dus</label>
                    <input type="number" name="" class="form-control form-control-sm crate-input" min="1"
                        placeholder="Contoh: 12">
                </div>
                <div class="col-md-4">
                    <label class="form-label" style="font-size: 0.8rem;">Isi per Pack (<span
                            class="unit-label">satuan</span>)</label>
                    <input type="text" name="" class="form-control form-control-sm pack-input"
                        placeholder="Contoh: 500">
                </div>
                <div class="col-md-4">
                    <label class="form-label" style="font-size: 0.8rem;">Total Isi per Dus</label>
                    <input type="text" class="form-control form-control-sm bg-light text-muted fw-bold total-display"
                        readonly placeholder="Dihitung otomatis">
                </div>
            </div>
        </div>
    </template>

    <script>
        // ── Hapus kemasan via AJAX (hindari nested form) ─────────── 
        async function deletePackaging(packId, btn) {
            if (!(await uiConfirm('Hapus kemasan ini?', { type: 'error', danger: true, confirmText: 'Ya, hapus' }))) return;
            const row = document.getElementById('pkgRow-' + packId);
            row.style.opacity = '0.5';
            btn.disabled = true;
            fetch('{{ url("master/packagings") }}/' + packId, {
                
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}', 
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: '_method=DELETE'
            })
                .then(function (r) {
                    if (r.ok || r.redirected) {
                        
                        row.remove();
                    } else {
                        row.style.opacity = '';
                        btn.disabled = false;
                        alert('Gagal menghapus kemasan.');
                    }
                })
                .catch(function () {
                    row.style.opacity = '';
                    btn.disabled = false;
                    alert('Gagal menghapus kemasan.');
                });
        }

        // ── Toggle aktif/nonaktif kemasan via AJAX ─────────────────── 
        document.addEventListener('change', function (e) {
            const toggle = e.target.closest('.pkg-toggle-active');
            if (!toggle) return;

            const packId = toggle.dataset.packagingId;
            const row = document.getElementById('pkgRow-' + packId);
            const badge = document.getElementById('pkgStatusBadge-' + packId);
            toggle.disabled = true;

            fetch('{{ url("master/packagings") }}/' + packId + '/toggle-active', {
                method: 'POST', 
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}', 
                    'Accept': 'application/json',
                },
            })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function (res) {
                    toggle.disabled = false;
                    toggle.checked = res.is_active;
                    if (badge) {
                        badge.textContent = res.is_active ? 'Aktif' : 'Nonaktif';
                        badge.className = 'badge pkg-status-badge ' +
                            (res.is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary');
                        badge.style.fontSize = '0.7rem';
                    }
                    if (row) {
                        row.style.background = res.is_active ? '#ffffff' : '#f8fafc';
                        row.classList.toggle('opacity-75', !res.is_active);
                    }
                })
                .catch(function () {
                    toggle.disabled = false;
                    toggle.checked = !toggle.checked; // revert
                    alert('Gagal mengubah status kemasan.');
                });
        });

        // ── Komposisi ────────────────────────────────────────────── 
        let compIdx = 1;

        function updateCompUnitLabel(row) {
            const sel = row.querySelector('.child-select');
            const lbl = row.querySelector('.unit-label-comp');
            if (!sel || !lbl) return;
            const opt = sel.options[sel.selectedIndex];
            const unit = opt?.dataset?.unit || 'satuan';
            lbl.textContent = unit;
            recalcPerUnit(row);
        }

        function recalcPerUnit(row) {
            const disp = row.querySelector('.per-unit-display');
            if (!disp) return;
            const totalOutput = NumberFmt.parse(document.getElementById('totalOutput')?.value || '0');
            const qtyUsed = NumberFmt.parse(row.querySelector('.qty-used-input')?.value || '0');
            const sel = row.querySelector('.child-select');
            const opt = sel?.options[sel.selectedIndex];
            const unit = opt?.dataset?.unit || '';
            if (totalOutput > 0 && qtyUsed > 0) {
                
                const perUnit = qtyUsed / totalOutput;
                disp.value = parseFloat(perUnit.toFixed(6)) + (unit ? ' ' + unit : '');
            } else {
                
                disp.value = '';
            } 
        }

        function recalcAllComps() {
            document.querySelectorAll('.composition-row').forEach(row => {
                updateCompUnitLabel(row);
                recalcPerUnit(row);
            });
        } 

        function updateCompRemoveButtons() {
            const rows = document.querySelectorAll('#compositionRows .composition-row');
            rows.forEach(function (r) {
                
                const btn = r.querySelector('.remove-comp-row');
                if (btn) btn.style.display = rows.length > 1 ? '' : 'none';
            });
        } 

        function attachCompRowEvents(row, idx) {
            const sel = row.querySelector('.child-select');
            const qty = row.querySelector('.qty-used-input');
            if (sel) {
                
                sel.name = `compositions[${idx}][child_id]`;
                sel.addEventListener('change', () => updateCompUnitLabel(row));
            } 
            if (qty) {
                
                qty.name = `compositions[${idx}][qty_used]`;
                qty.addEventListener('input', () => recalcPerUnit(row));
            }
            const removeBtn = row.querySelector('.remove-comp-row');
            if (removeBtn) {
                
                removeBtn.addEventListener('click', () => {
                    
                    row.remove();
                    updateCompRemoveButtons();
                });
            } 
        }

        const rawIngredients = @json($rawIngredients);

        function buildCompRowHTML(idx) {
            let opts = '<option value="">— Pilih Bahan —</option>';
            rawIngredients.forEach(r => { opts += `<option value="${r.id}" data-unit="${r.unit_base}">${r.name}</option>`; });
            return `<div class="composition-row row g-2 mb-3 align-items-center">
                <div class="col-5">
                    <select name="compositions[${idx}][child_id]" class="form-select form-select-sm child-select">${opts}</select>
                </div>
                <div class="col-3">
                    <div class="input-group input-group-sm">
                        <input type="text" name="compositions[${idx}][qty_used]" class="form-control form-control-sm qty-used-input num-dec" placeholder="cth: 0,8" style="border-right: 0; border-radius: 8px 0 0 8px !important;">
                        <span class="input-group-text bg-white small unit-label-comp text-muted fw-semibold" style="border: 1px solid #cbd5e1; border-left: 0; border-radius: 0 8px 8px 0;">satuan</span>
                    </div>
                </div>
                <div class="col-3">
                    <input type="text" class="form-control form-control-sm bg-light per-unit-display text-muted fw-bold" readonly placeholder="—">
                </div>
                <div class="col-1 d-flex justify-content-center">
                    <button type="button" class="btn btn-light text-danger border btn-sm remove-comp-row" style="border-radius: 8px;"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>`;
        }

        // ── Packaging ────────────────────────────────────────────── 
        let packIdx = 1;
        function getUnit() {
            
            return document.getElementById('unitBase').value || 'satuan';
        }

        function getUnitLabel() {
            const v = document.getElementById('unitBase').value;
            if (v === 'gram') return 'Gram';
            if (v === 'pcs') return 'Pcs';
            return 'Satuan';
        } 

        function updateUnitLabels() {
            const unit = getUnit();
            const label = getUnitLabel();
            document.querySelectorAll('.unit-label').forEach(el => el.textContent = label);
            const header = document.getElementById('headerUnitLabel');
            if (header) header.textContent = label;
            recalcAll();
        } 

        function recalcRow(row) {
            const crate = parseFloat(row.querySelector('.crate-input').value) || 0;
            const pack = parseFloat((row.querySelector('.pack-input').value || '').replace(',', '.')) || 0;
            const total = crate * pack;
            const label = getUnitLabel();
            const disp = row.querySelector('.total-display');
            if (total > 0) {
                disp.value = total.toLocaleString('id-ID', { maximumFractionDigits: 4 }) + ' ' + label;
            } else {
                
                disp.value = '';
            } 
        }

        function recalcAll() {
            document.querySelectorAll('.packaging-row').forEach(recalcRow);
        }

        function attachRowEvents(row, idx) {
            row.querySelector('.packaging-name-input').name = `packagings[${idx}][packaging_name]`;
            row.querySelector('.packaging-supplier-input').name = `packagings[${idx}][supplier_id]`;
            row.querySelector('.crate-input').name = `packagings[${idx}][crate_to_pack]`;
            row.querySelector('.pack-input').name = `packagings[${idx}][pack_to_base]`;
            row.querySelector('.crate-input').addEventListener('input', () => recalcRow(row));
            row.querySelector('.pack-input').addEventListener('input', () => recalcRow(row));

            const removeBtn = row.querySelector('.remove-row');
            if (removeBtn) {
                
                removeBtn.style.display = '';
                removeBtn.addEventListener('click', function () {
                    
                    row.remove();
                    updateRemoveButtons();
                });
            } 
        }

        function updateRemoveButtons() {
            const rows = document.querySelectorAll('#packagingRows .packaging-row');
            rows.forEach(function (r, i) {
                
                const btn = r.querySelector('.remove-row');
                if (btn) btn.style.display = rows.length > 1 ? '' : 'none';
            });
        } 

        document.addEventListener('DOMContentLoaded', function () {
            // Existing packagings: attach recalc events
            document.querySelectorAll('.packaging-row').forEach(function (row) {
                row.querySelector('.crate-input')?.addEventListener('input', () => recalcRow(row));
                row.querySelector('.pack-input')?.addEventListener('input', () => recalcRow(row));
            });

            // Packaging baru: attach events ke row pertama
            const firstPackRow = document.querySelector('#packagingRows .packaging-row');
            if (firstPackRow) attachRowEvents(firstPackRow, 0);
            updateRemoveButtons();

            // Toggle kemasan vs komposisi vs kategori berdasarkan tipe
            function toggleSections() {
                const tipe = document.querySelector('[name="type"]').value;
                const isSemi = tipe === 'semi_finished';
                document.getElementById('sectionPackaging').style.display = isSemi ? 'none' : '';
                document.getElementById('sectionComposition').style.display = isSemi ? '' : 'none';
                document.getElementById('wrapCategory').style.display = isSemi ? 'none' : '';
            } 
            function onTypeChange() { toggleSections(); } 
            document.querySelector('[name="type"]').addEventListener('change', toggleSections);
            toggleSections();
            document.getElementById('unitBase').addEventListener('change', function () {
                
                updateUnitLabels();
                const v = this.value;
                const lbl = v === 'gram' ? 'gram' : (v === 'pcs' ? 'pcs' : 'satuan');
                const hdr = document.getElementById('perUnitHeaderLabel');
                if (hdr) hdr.textContent = lbl;
            });

            // total_output berubah → recalc semua per-unit
            document.getElementById('totalOutput')?.addEventListener('input', () => {
                
                document.querySelectorAll('#compositionRows .composition-row').forEach(recalcPerUnit);
            });

            document.getElementById('addPackaging').addEventListener('click', function () {
                
                const tmpl = document.getElementById('packagingTemplate');
                const container = document.getElementById('packagingRows');
                container.appendChild(tmpl.content.cloneNode(true));
                const allRows = container.querySelectorAll('.packaging-row');
                const row = allRows[allRows.length - 1];
                attachRowEvents(row, packIdx++);
                updateUnitLabels();
                updateRemoveButtons();
            });
            updateUnitLabels();

            // Komposisi: attach events ke row pertama
            const firstCompRow = document.querySelector('.composition-row');
            if (firstCompRow) {
                
                attachCompRowEvents(firstCompRow, 0);
                updateCompUnitLabel(firstCompRow);
                updateCompRemoveButtons();
            } 

            const addCompBtn = document.getElementById('addComposition');
            if (addCompBtn) {
                
                addCompBtn.addEventListener('click', function () {
                    
                    const container = document.getElementById('compositionRows');
                    const div = document.createElement('div');
                    div.innerHTML = buildCompRowHTML(compIdx);
                    const row = div.firstElementChild;
                    container.appendChild(row);
                    attachCompRowEvents(row, compIdx++);
                    updateCompRemoveButtons();
                });
            } 
        });
    </script>

    {{-- Blok terpisah & mandiri (tahan walau script di atas error karena urutan
         pemuatan NumberFmt): toggle panel + hitung "Per 1 …" komposisi. --}}
    <script>
        (function () {
            // ── Toggle Kemasan (raw) vs Komposisi (semi) ──────────────────────
            function togglePanels() {
                var t = document.querySelector('select[name="type"]');
                if (!t) return;
                var isSemi = t.value === 'semi_finished';
                var sp = document.getElementById('sectionPackaging');
                var sc = document.getElementById('sectionComposition');
                var wc = document.getElementById('wrapCategory');
                if (sp) sp.style.display = isSemi ? 'none' : '';
                if (sc) sc.style.display = isSemi ? '' : 'none';
                if (wc) wc.style.display = isSemi ? 'none' : '';
            }
            window.onTypeChange = togglePanels; // dipakai atribut onchange
            var sel = document.querySelector('select[name="type"]');
            if (sel) sel.addEventListener('change', togglePanels);
            togglePanels();

            // ── Kolom komposisi boleh DESIMAL (mis. 0,8 pcs) ──────────────────
            // Formatter ribuan global (.num-fmt) membulatkan angka, sehingga koma
            // terhapus saat diketik. Kolom ini memakai formatter sendiri: titik
            // untuk ribuan, koma untuk desimal (maks 4 angka di belakang koma).
            function fmtDesimal(str) {
                var s = String(str == null ? '' : str).replace(/[^0-9,]/g, '');
                var bagian = s.split(',');
                var utuh   = bagian.shift().replace(/^0+(?=\d)/, '');
                var pecah  = bagian.length ? bagian.join('').slice(0, 4) : null;
                var hasil  = utuh === '' ? (pecah !== null ? '0' : '')
                                         : Number(utuh).toLocaleString('id-ID');
                return pecah !== null ? hasil + ',' + pecah : hasil;
            }
            document.addEventListener('input', function (e) {
                if (!e.target.classList || !e.target.classList.contains('num-dec')) return;
                var caret = e.target.selectionStart, panjangLama = e.target.value.length;
                e.target.value = fmtDesimal(e.target.value);
                var geser = e.target.value.length - panjangLama;
                try { e.target.setSelectionRange(caret + geser, caret + geser); } catch (err) {}
            });
            document.querySelectorAll('.num-dec').forEach(function (el) {
                el.setAttribute('inputmode', 'decimal');
                if (el.value) el.value = fmtDesimal(el.value);
            });

            // ── Hitung "Per 1 <satuan>" tiap baris komposisi ──────────────────
            function num(v) {
                if (window.NumberFmt && NumberFmt.parse) return NumberFmt.parse(v || '0');
                return parseFloat(String(v || '').replace(/\./g, '').replace(',', '.')) || 0;
            }
            function recalcPer(row) {
                if (!row) return;
                var disp = row.querySelector('.per-unit-display');
                if (!disp) return;
                var toEl = document.getElementById('totalOutput');
                var qEl  = row.querySelector('.qty-used-input');
                var total = num(toEl ? toEl.value : 0);
                var qty   = num(qEl ? qEl.value : 0);
                var s = row.querySelector('.child-select');
                var unit = (s && s.options[s.selectedIndex] && s.options[s.selectedIndex].dataset.unit) || '';
                disp.value = (total > 0 && qty > 0)
                    ? parseFloat((qty / total).toFixed(6)) + (unit ? ' ' + unit : '')
                    : '';
            }
            function recalcAllComp() {
                document.querySelectorAll('.composition-row').forEach(recalcPer);
            }
            // Delegasi event → berlaku untuk baris awal & baris yang ditambah dinamis
            document.addEventListener('input', function (e) {
                if (e.target.classList.contains('qty-used-input'))
                    recalcPer(e.target.closest('.composition-row'));
                if (e.target.id === 'totalOutput') recalcAllComp();
            });
            document.addEventListener('change', function (e) {
                if (e.target.classList.contains('child-select'))
                    recalcPer(e.target.closest('.composition-row'));
            });
            recalcAllComp();

            // ── Tambah / hapus baris komposisi (handler mandiri) ──────────────
            function updateCompRemoveBtns() {
                var rows = document.querySelectorAll('#compositionRows .composition-row');
                rows.forEach(function (r) {
                    var b = r.querySelector('.remove-comp-row');
                    if (b) b.style.display = rows.length > 1 ? '' : 'none';
                });
            }
            // Builder baris komposisi mandiri (tidak bergantung script utama)
            var rawIngs = @json($rawIngredients);
            function buildCompOptions() {
                var o = '<option value="">— Pilih Bahan —</option>';
                rawIngs.forEach(function (r) {
                    o += '<option value="' + r.id + '" data-unit="' + (r.unit_base || '') + '">' + r.name + '</option>';
                });
                return o;
            }
            function buildCompRow(idx) {
                var div = document.createElement('div');
                div.className = 'composition-row row g-2 mb-3 align-items-center';
                div.innerHTML =
                    '<div class="col-5"><select name="compositions[' + idx + '][child_id]" class="form-select form-select-sm child-select">' + buildCompOptions() + '</select></div>' +
                    '<div class="col-3"><div class="input-group input-group-sm">' +
                        '<input type="text" name="compositions[' + idx + '][qty_used]" class="form-control form-control-sm qty-used-input num-dec" placeholder="cth: 0,8" style="border-right:0;border-radius:8px 0 0 8px !important;">' +
                        '<span class="input-group-text bg-white small unit-label-comp text-muted fw-semibold" style="border:1px solid #cbd5e1;border-left:0;border-radius:0 8px 8px 0;">satuan</span>' +
                    '</div></div>' +
                    '<div class="col-3"><input type="text" class="form-control form-control-sm bg-light per-unit-display text-muted fw-bold" readonly placeholder="—"></div>' +
                    '<div class="col-1 d-flex justify-content-center"><button type="button" class="btn btn-light text-danger border btn-sm remove-comp-row" style="border-radius:8px;"><i class="bi bi-x-lg"></i></button></div>';
                return div;
            }
            var addComp = document.getElementById('addComposition');
            if (addComp) {
                var compIdx2 = document.querySelectorAll('#compositionRows .composition-row').length;
                addComp.addEventListener('click', function () {
                    var container = document.getElementById('compositionRows');
                    if (!container) return;
                    container.appendChild(buildCompRow(compIdx2++));
                    updateCompRemoveBtns();
                });
            }
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.remove-comp-row');
                if (!btn) return;
                var row = btn.closest('.composition-row');
                if (row) { row.remove(); updateCompRemoveBtns(); }
            });
            updateCompRemoveBtns();
        })();
    </script>
@endsection