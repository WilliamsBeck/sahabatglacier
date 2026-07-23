@extends('layouts.app')
@section('title', isset($menu) ? 'Edit Menu' : 'Tambah Menu')

@section('content')

    <div class="pb-2">
        <div class="row">
            <div class="col-12">

                <div class="page-header d-flex justify-content-between align-items-start">
                    <div>
                        <h1 class="page-title">{{ isset($menu) ? 'Edit Menu: ' . $menu->name : 'Tambah Menu' }}</h1>
                        <p class="page-subtitle">Kelola master data menu dan varian resepnya</p>
                    </div>
                    <a href="{{ route('master.menus.index') }}" class="btn btn-outline-secondary btn-back">
                        <i class="bi bi-arrow-left me-1"></i>Kembali
                    </a>
                </div>

                    {{-- =====================================================================
                    RESEP TERSIMPAN — di LUAR form utama agar tidak ada nested form
                    ===================================================================== --}}
                    @php
                        $bulanID = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'];
                        $formatID = function ($date) use ($bulanID) {
                            $c = \Carbon\Carbon::parse($date);
                            return $c->format('d') . ' ' . $bulanID[(int) $c->format('n')] . ' ' . $c->format('Y');
                        };
                    @endphp

                    @if(isset($menu) && isset($recipes) && $recipes->count())
                        <div class="card mb-4">
                            <div class="card-header bg-light d-flex align-items-center gap-2">
                                <div class="text-muted fs-5">
                                    <i class="bi bi-journal-check"></i>
                                </div>
                                <h4 class="fw-semibold mb-0">Daftar Versi Resep Tersimpan</h4>
                            </div>
                            <div class="card-body p-4 bg-light">
                                <div class="row g-3">
                                    @foreach($recipes as $groupId => $items)
                                        @php
                                            $first = $items->first();
                                            $date = $first->effective_from->toDateString();
                                            $vStoreIds = $items->pluck('store_id')->unique()->values();
                                            $isDefault = $vStoreIds->contains(null);
                                            $vStoreNames = $items->pluck('store.name')->filter()->unique()->values();
                                            $uniqueItems = $items->unique('ingredient_id')->values();
                                            $itemsData = $uniqueItems->map(fn($it) => [
                                                'ingredient_id' => $it->ingredient_id,
                                                'qty_usage' => $it->qty_usage,
                                                'unit' => $it->unit,
                                            ])->values();
                                            $storeIdsForJs = $isDefault ? [] : $vStoreIds->filter()->values()->all();
                                        @endphp
                                        <div class="col-md-6 col-lg-4">
                                            <div class="bg-white rounded-4 border p-3 h-100 shadow-sm d-flex flex-column">
                                                <div class="d-flex justify-content-between align-items-start mb-3">
                                                    <div class="d-flex flex-column gap-2">
                                                        <span class="badge"
                                                            style="background-color: #0f172a; color: #ffffff; width: fit-content;">
                                                            <i class="bi bi-calendar-event me-1"></i> {{ $formatID($date) }}
                                                        </span>
                                                        <div class="d-flex flex-wrap gap-1">
                                                            @if($isDefault)
                                                                <span class="badge"
                                                                    style="background-color: #dcfce7; color: #166534; font-size: 0.75rem; padding: 4px 8px;">Default
                                                                    (Semua Toko)</span>
                                                            @else
                                                                {{-- Ringkas: chip jumlah toko, klik untuk buka daftar lengkap --}}
                                                                @php $vsId = 'ver-stores-'.$loop->index; @endphp
                                                                <button type="button" class="badge border-0 btn-toggle-stores"
                                                                    data-target="{{ $vsId }}"
                                                                    title="{{ $vStoreNames->implode(', ') }}"
                                                                    style="background-color: #e0f2fe; color: #0284c7; font-size: 0.75rem; padding: 4px 8px; cursor: pointer;">
                                                                    <i class="bi bi-shop me-1"></i>{{ $vStoreNames->count() }} toko
                                                                    <i class="bi bi-chevron-down ms-1" style="font-size: 0.65rem;"></i>
                                                                </button>
                                                                <div id="{{ $vsId }}" class="d-none flex-wrap gap-1 mt-1 w-100">
                                                                    @foreach($vStoreNames as $sn)
                                                                        <span class="badge"
                                                                            style="background-color: #e0f2fe; color: #0284c7; font-size: 0.72rem; padding: 3px 7px;">
                                                                            <i class="bi bi-shop me-1"></i> {{ $sn }}
                                                                        </span>
                                                                    @endforeach
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                    @php
                                                        // Admin area hanya boleh kelola versi milik toko yg dia pegang
                                                        // (versi default / menyangkut toko lain = tampilan saja).
                                                        $canManageVer = auth()->user()->isSuperAdmin()
                                                            || (!$isDefault && $vStoreIds->filter()
                                                                    ->diff(auth()->user()->accessibleStoreIds())->isEmpty());
                                                    @endphp
                                                    @if($canManageVer)
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-sm btn-light border btn-edit-version"
                                                            style="border-radius: 8px; color: #475569;" data-date="{{ $date }}"
                                                            data-stores='@json($storeIdsForJs)' data-items='@json($itemsData)'>
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <form method="POST"
                                                            action="{{ route('master.menus.recipe-version.destroy', ['menu' => $menu->id, 'group' => $first->recipe_group_id ?? 'kosong']) }}"
                                                            data-confirm="Hapus versi resep {{ $formatID($date) }}?" data-confirm-type="error" data-confirm-danger="1" data-confirm-ok="Ya, hapus">
                                                            @csrf @method('DELETE')
                                                            <button type="submit" class="btn btn-sm btn-light border text-danger"
                                                                style="border-radius: 8px; background-color: #fff5f5; border-color: #fee2e2 !important;">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                    @endif
                                                </div>

                                                <div class="mt-auto">
                                                    <table class="table table-sm mb-0 align-middle">
                                                        <thead style="background-color: #f8fafc;">
                                                            <tr>
                                                                <th class="text-muted fw-semibold py-2 border-0 rounded-start"
                                                                    style="font-size: 0.8rem;">Bahan</th>
                                                                <th class="text-end text-muted fw-semibold py-2 border-0"
                                                                    style="font-size: 0.8rem;">Qty</th>
                                                                <th class="text-muted fw-semibold py-2 border-0 rounded-end"
                                                                    style="font-size: 0.8rem;">Satuan</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach($uniqueItems as $item)
                                                                <tr>
                                                                    <td class="fw-medium text-dark border-0 border-bottom"
                                                                        style="font-size: 0.85rem;">{{ $item->ingredient->name }}</td>
                                                                    <td class="text-end fw-bold text-dark border-0 border-bottom"
                                                                        style="font-size: 0.85rem;">
                                                                        {{ number_format($item->qty_usage, 0, ',', '.') }}
                                                                    </td>
                                                                    <td class="text-secondary border-0 border-bottom"
                                                                        style="font-size: 0.8rem;">{{ $item->unit }}</td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- =====================================================================
                    FORM UTAMA
                    ===================================================================== --}}
                    <form id="menuForm" method="POST"
                        action="{{ isset($menu) ? route('master.menus.update', $menu) : route('master.menus.store') }}">
                        @csrf @if(isset($menu)) @method('PUT') @endif

                        <div class="row g-4">
                            {{-- KIRI: Info Menu --}}
                            <div class="col-lg-4">
                                <div class="card">
                                    <div class="card-header d-flex align-items-center gap-2">
                                        <div class="text-muted fs-5">
                                            <i class="bi bi-info-circle-fill"></i>
                                        </div>
                                        <h4 class="fw-semibold mb-0">Info Menu Dasar</h4>
                                    </div>

                                    @php $isAdminArea = !auth()->user()->isSuperAdmin(); @endphp
                                    <div class="card-body">
                                        @if($isAdminArea)
                                            <div class="alert alert-info py-2 small mb-4">
                                                <i class="bi bi-info-circle me-1"></i>
                                                Info menu hanya bisa diubah Super Admin. Anda bisa menambah/mengubah
                                                <strong>versi resep untuk toko yang Anda pegang</strong> di panel kanan.
                                            </div>
                                        @endif
                                        <div class="mb-4">
                                            <label class="form-label">Nama Menu <span
                                                    class="text-danger">*</span></label>
                                            <input type="text" name="name" class="form-control"
                                                value="{{ old('name', $menu->name ?? '') }}"
                                                placeholder="Contoh: Brown Sugar Boba" required
                                                @if($isAdminArea) readonly style="background:#f8fafc" @endif>
                                        </div>

                                        <div class="mb-4">
                                            <label class="form-label">Kategori</label>
                                            <select name="category_id" class="form-select"
                                                @if($isAdminArea) style="pointer-events:none;background:#f8fafc" tabindex="-1" @endif>
                                                <option value="">— Pilih Kategori —</option>
                                                @foreach($menuCategories as $mc)
                                                    <option value="{{ $mc->id }}" {{ old('category_id', $menu->category_id ?? '') == $mc->id ? 'selected' : '' }}>
                                                        {{ $mc->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="form-text mt-2 fw-medium" style="font-size: 0.85rem;">
                                                <a href="{{ route('master.categories.index') }}#menu" target="_blank"
                                                    class="text-decoration-none text-primary">
                                                    <i class="bi bi-plus-circle me-1"></i>Kelola kategori menu
                                                </a>
                                            </div>
                                        </div>

                                        <div class="mb-4 pt-4 border-top">
                                            <div class="form-check form-switch d-flex align-items-center gap-3 ps-0">
                                                <input class="form-check-input m-0" type="checkbox" name="is_active"
                                                    value="1" id="actMenu" {{ old('is_active', $menu->is_active ?? true) ? 'checked' : '' }}
                                                    @if($isAdminArea) style="pointer-events:none" tabindex="-1" @endif>
                                                <label class="form-check-label form-label mb-0" for="actMenu"
                                                    style="cursor: pointer;">Set Menu Aktif</label>
                                            </div>
                                        </div>

                                        <div class="mt-auto pt-2">
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="bi bi-check-lg me-1"></i>{{ $isAdminArea ? 'Simpan Versi Resep' : 'Simpan Data Menu' }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- KANAN: Input Resep Baru --}}
                            <div class="col-lg-8">
                                <div class="card" id="recipeFormCard">
                                    <div class="card-header d-flex align-items-center gap-2">
                                        <div class="text-muted fs-5">
                                            <i class="bi bi-card-list"></i>
                                        </div>
                                        <h4 class="fw-semibold mb-0">
                                            {{ isset($menu) ? 'Tambah / Update Versi Resep' : 'Resep Menu' }}
                                        </h4>
                                    </div>

                                    <div class="card-body">
                                        <div class="row g-4 mb-4">
                                            <div class="col-md-5">
                                                <label class="form-label">Tanggal Berlaku <span
                                                        class="text-danger">*</span></label>
                                                <input type="date" name="effective_from" class="form-control"
                                                    value="{{ old('effective_from', now()->toDateString()) }}" required>
                                                <div class="form-text mt-2 text-muted"
                                                    style="font-size: 0.8rem; line-height: 1.5;">
                                                    <i class="bi bi-info-circle me-1"></i>Jika tanggal & toko sama dengan
                                                    versi lama, otomatis akan menimpa data lama.
                                                </div>
                                            </div>
                                            <div class="col-md-7">
                                                <label class="form-label">Berlaku Khusus Untuk Toko</label>
                                                <div class="dropdown w-100" id="storeDropdownWrap">
                                                    <button class="btn btn-outline-secondary w-100 d-flex justify-content-between align-items-center"
                                                            type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                                        <span id="storeDropdownLabel" class="text-truncate">Semua Toko (default)</span>
                                                        <i class="bi bi-chevron-down ms-2 flex-shrink-0"></i>
                                                    </button>
                                                    <div class="dropdown-menu w-100 p-2" style="max-height:280px;overflow-y:auto">
                                                        <input type="text" id="storeSearchInput" class="form-control form-control-sm mb-2"
                                                               placeholder="Cari toko…" autocomplete="off">
                                                        @foreach($stores ?? [] as $s)
                                                            <label class="dropdown-item d-flex align-items-center gap-2 store-option" style="cursor:pointer">
                                                                <input class="form-check-input store-checkbox m-0" type="checkbox"
                                                                       name="store_ids[]" value="{{ $s->id }}">
                                                                <span class="store-option-name">{{ $s->name }}</span>
                                                            </label>
                                                        @endforeach
                                                        <div id="storeNoResult" class="text-muted small px-2 py-1 d-none">Toko tidak ditemukan</div>
                                                    </div>
                                                </div>
                                                <div class="form-text mt-2">
                                                    @if($isAdminArea)
                                                        <span class="text-danger fw-semibold">Wajib pilih minimal 1 toko</span> —
                                                        resep default (semua toko) hanya bisa dibuat Super Admin.
                                                    @else
                                                        Kosongkan jika berlaku untuk <strong>semua toko</strong> (default).
                                                    @endif
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Header Tabel Resep --}}
                                        <div class="row g-2 mb-2 px-2 border-bottom pb-2 mt-4">
                                            <div class="col-5"><span class="form-label text-muted mb-0"
                                                    style="font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px;">Bahan
                                                    Baku</span></div>
                                            <div class="col-3"><span class="form-label text-muted mb-0"
                                                    style="font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px;">Qty
                                                    Digunakan</span></div>
                                            <div class="col-3"><span class="form-label text-muted mb-0"
                                                    style="font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px;">Satuan</span>
                                            </div>
                                            <div class="col-1"></div>
                                        </div>

                                        <div id="recipeRows" class="px-2">
                                            <div class="recipe-row row g-2 mb-3 align-items-center">
                                                <div class="col-5">
                                                    <select name="items[0][ingredient_id]"
                                                        class="form-select form-select-sm">
                                                        <option value="">— Pilih Bahan —</option>
                                                        @foreach($ingredients as $ing)
                                                            <option value="{{ $ing->id }}" data-unit="{{ $ing->unit_base }}">
                                                                {{ $ing->name }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="col-3">
                                                    <input type="number" name="items[0][qty_usage]"
                                                        class="form-control form-control-sm" step="1" min="1"
                                                        placeholder="cth: 50">
                                                </div>
                                                <div class="col-3">
                                                    <input type="text" name="items[0][unit]"
                                                        class="form-control form-control-sm bg-light text-center unit-display text-muted fw-bold border-0"
                                                        readonly placeholder="—">
                                                </div>
                                                <div class="col-1 d-flex justify-content-center"></div>
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                                            <button type="button" id="addRow"
                                                class="btn btn-outline-dark btn-sm rounded-pill px-4 py-2 fw-bold d-flex align-items-center gap-2"
                                                style="border-color: #cbd5e1;">
                                                <i class="bi bi-plus-lg"></i> Tambah Bahan
                                            </button>
                                            <div class="text-muted small fw-medium bg-light px-3 py-2 rounded-3 border">
                                                <i class="bi bi-lightbulb text-warning me-1"></i>Kosongkan bahan jika tidak
                                                ingin menyimpan resep
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>

            </div>
        </div>
    </div>

    <script>
        // ── Chip "N toko" pada kartu versi: klik untuk buka/tutup daftar toko ──
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-toggle-stores');
            if (!btn) return;
            const box = document.getElementById(btn.dataset.target);
            if (!box) return;
            const isOpen = !box.classList.contains('d-none');
            box.classList.toggle('d-none', isOpen);
            box.classList.toggle('d-flex', !isOpen);
            const chev = btn.querySelector('.bi-chevron-down, .bi-chevron-up');
            if (chev) chev.className = 'bi ms-1 ' + (isOpen ? 'bi-chevron-down' : 'bi-chevron-up');
        });

        // ── Dropdown checklist toko (skalabel utk banyak toko) ──────────────
        (function () {
            const wrap = document.getElementById('storeDropdownWrap');
            if (!wrap) return;
            const label = document.getElementById('storeDropdownLabel');
            const checks = wrap.querySelectorAll('.store-checkbox');

            function update() {
                const sel = [...checks].filter(c => c.checked);
                if (sel.length === 0) label.textContent = 'Semua Toko (default)';
                else if (sel.length <= 2)
                    label.textContent = sel.map(c => c.closest('.store-option').querySelector('.store-option-name').textContent.trim()).join(', ');
                else label.textContent = sel.length + ' toko dipilih';
            }
            checks.forEach(c => c.addEventListener('change', update));
            window.updateStoreDropdownLabel = update;

            const search = document.getElementById('storeSearchInput');
            if (search) {
                search.addEventListener('click', e => e.stopPropagation());
                search.addEventListener('input', function () {
                    const q = this.value.toLowerCase();
                    let any = false;
                    wrap.querySelectorAll('.store-option').forEach(function (opt) {
                        const match = opt.querySelector('.store-option-name').textContent.toLowerCase().includes(q);
                        opt.classList.toggle('d-none', !match);
                        if (match) any = true;
                    });
                    document.getElementById('storeNoResult').classList.toggle('d-none', any);
                });
            }
            update();
        })();

        let rowIdx = 1;
        const ingredientOptions = @json($ingredients->map(fn($i) => ['id' => $i->id, 'label' => $i->name, 'unit' => $i->unit_base]));

        // Map id → unit_base untuk lookup cepat
        const unitMap = {};
        ingredientOptions.forEach(i => { unitMap[i.id] = i.unit; });

        // Saat pilih bahan, unit otomatis sesuai unit_base bahan tersebut
        function bindIngredientChange(row) {
            const ingSelect = row.querySelector('select[name$="[ingredient_id]"]');
            const unitInput = row.querySelector('[name$="[unit]"]');
            ingSelect.addEventListener('change', function () {
                const unit = unitMap[this.value];
                if (unitInput) unitInput.value = unit || '';
            });
        }

        // Bind ke baris pertama yang sudah ada di HTML
        document.querySelectorAll('#recipeRows .recipe-row').forEach(bindIngredientChange);

        document.getElementById('addRow').addEventListener('click', function () {
            let opts = '<option value="">— Pilih Bahan —</option>';
            ingredientOptions.forEach(i => { opts += `<option value="${i.id}">${i.label}</option>`; });

            const tr = document.createElement('div');
            tr.className = 'recipe-row row g-2 mb-3 align-items-center';
            tr.innerHTML = `
                    <div class="col-5">
                        <select name="items[${rowIdx}][ingredient_id]" class="form-select form-select-sm">${opts}</select>
                    </div>
                    <div class="col-3">
                        <input type="number" name="items[${rowIdx}][qty_usage]" class="form-control form-control-sm" step="1" min="1" placeholder="cth: 50">
                    </div>
                    <div class="col-3">
                        <input type="text" name="items[${rowIdx}][unit]" class="form-control form-control-sm bg-light text-center unit-display text-muted fw-bold border-0" readonly placeholder="—">
                    </div>
                    <div class="col-1 d-flex justify-content-center">
                        <button type="button" class="btn btn-light border text-danger btn-sm remove-row" style="border-radius: 8px;"><i class="bi bi-x-lg"></i></button>
                    </div>
                `;
            tr.querySelector('.remove-row').addEventListener('click', () => tr.remove());
            bindIngredientChange(tr);
            document.getElementById('recipeRows').appendChild(tr);
            rowIdx++;
        });

        // ── Edit Versi Resep: pre-fill form bawah dengan data versi lama ───────────
        function buildRecipeRow(idx, data) {
            let opts = '<option value="">— Pilih Bahan —</option>';
            ingredientOptions.forEach(i => {
                const sel = (data && i.id == data.ingredient_id) ? 'selected' : '';
                opts += `<option value="${i.id}" ${sel}>${i.label}</option>`;
            });
            const qtyVal = data?.qty_usage ? (Number.isInteger(+data.qty_usage) ? +data.qty_usage : parseFloat(data.qty_usage)) : '';
            const tr = document.createElement('div');
            tr.className = 'recipe-row row g-2 mb-3 align-items-center';
            tr.innerHTML = `
                    <div class="col-5">
                        <select name="items[${idx}][ingredient_id]" class="form-select form-select-sm">${opts}</select>
                    </div>
                    <div class="col-3">
                        <input type="number" name="items[${idx}][qty_usage]" class="form-control form-control-sm" step="1" min="1" value="${qtyVal}" placeholder="cth: 50">
                    </div>
                    <div class="col-3">
                        <input type="text" name="items[${idx}][unit]" class="form-control form-control-sm bg-light text-center unit-display text-muted fw-bold border-0" readonly value="${data?.unit ?? ''}" placeholder="—">
                    </div>
                    <div class="col-1 d-flex justify-content-center">
                        ${idx > 0 ? '<button type="button" class="btn btn-light border text-danger btn-sm remove-row" style="border-radius: 8px;"><i class="bi bi-x-lg"></i></button>' : ''}
                    </div>
                `;
            const rb = tr.querySelector('.remove-row');
            if (rb) rb.addEventListener('click', () => tr.remove());
            bindIngredientChange(tr);
            return tr;
        }

        document.querySelectorAll('.btn-edit-version').forEach(btn => {
            btn.addEventListener('click', function () {
                const date = this.dataset.date;
                const storeIds = JSON.parse(this.dataset.stores || '[]');
                const items = JSON.parse(this.dataset.items || '[]');
                if (!items.length) return;

                // Set tanggal berlaku
                const dateInput = document.querySelector('input[name="effective_from"]');
                if (dateInput) dateInput.value = date;

                // Set ceklis toko
                document.querySelectorAll('.store-checkbox').forEach(cb => {
                    cb.checked = storeIds.map(String).includes(cb.value);
                });
                if (window.updateStoreDropdownLabel) window.updateStoreDropdownLabel();

                // Clear baris lama, isi ulang dari data
                const tbody = document.getElementById('recipeRows');
                tbody.innerHTML = '';
                rowIdx = 0;
                items.forEach(it => {
                    tbody.appendChild(buildRecipeRow(rowIdx, it));
                    rowIdx++;
                });

                // Scroll dan visual feedback
                const formCard = document.getElementById('recipeFormCard');
                formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                formCard.style.transition = 'box-shadow .4s ease, transform .4s ease';
                formCard.style.transform = 'translateY(-4px)';
                formCard.style.boxShadow = '0 0 0 4px rgba(37, 99, 235, 0.15), 0 20px 40px rgba(0,0,0,0.05)';
                setTimeout(() => {
                    formCard.style.transform = 'translateY(0)';
                    formCard.style.boxShadow = '0 15px 35px rgba(0,0,0,0.02), 0 5px 15px rgba(0,0,0,0.01)';
                }, 1500);
            });
        });
    </script>
@endsection