@extends('layouts.app')
@section('title', 'Kelola Kategori')
{{-- Kategori berlaku untuk semua toko: selain Super Admin hanya bisa melihat --}}
@section('data_global', '1')

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1 class="page-title">Kelola Kategori</h1>
        <p class="page-subtitle">Atur kategori untuk bahan baku dan menu</p>
    </div>
</div>

{{-- TABS --}}
<ul class="nav nav-tabs mb-3" id="catTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" id="tab-bahan" data-bs-toggle="tab" data-bs-target="#pane-bahan" type="button">
            <i class="bi bi-box-seam me-1"></i> Kategori Bahan Baku
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" id="tab-menu" data-bs-toggle="tab" data-bs-target="#pane-menu" type="button">
            <i class="bi bi-cup-straw me-1"></i> Kategori Menu
        </button>
    </li>
</ul>

<div class="tab-content" id="catTabContent">

    {{-- ===================== TAB BAHAN BAKU ===================== --}}
    <div class="tab-pane fade show active" id="pane-bahan" role="tabpanel">
        <div class="mb-2 d-flex justify-content-end gap-2">
            @include('master.partials.import-buttons', ['entity' => 'ingredient-categories', 'label' => 'Kategori Bahan'])
        </div>
        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-list-ul me-1"></i> Daftar Kategori Bahan Baku</span>
                        <span class="badge bg-secondary"><i class="bi bi-grip-vertical me-1"></i> Drag untuk urutkan</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th style="width:36px"></th>
                                    <th>Nama Kategori</th>
                                    <th style="width:120px" class="text-center">Digunakan</th>
                                    <th style="width:60px"></th>
                                </tr>
                            </thead>
                            <tbody id="ingCatList">
                                @foreach($ingredientCategories as $cat)
                                    @php $count = \App\Models\Ingredient::where('category', $cat->name)->count(); @endphp
                                    <tr data-id="{{ $cat->id }}" class="ing-cat-row">
                                        <td class="text-center drag-handle" style="cursor:grab">
                                            <i class="bi bi-grip-vertical text-fade"></i>
                                        </td>
                                        <td>
                                            <form method="POST" action="{{ route('master.ingredient-categories.update', $cat) }}"
                                                  class="d-flex gap-2 align-items-center">
                                                @csrf @method('PUT')
                                                <input type="text" name="label" value="{{ $cat->label }}"
                                                       class="form-control form-control-sm" style="max-width:240px">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Simpan">
                                                    <i class="bi bi-check2"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge {{ $count > 0 ? 'bg-primary' : 'bg-secondary' }}">{{ $count }} bahan</span>
                                        </td>
                                        <td class="text-center">
                                            @if($count === 0)
                                                <form method="POST" action="{{ route('master.ingredient-categories.destroy', $cat) }}"
                                                      data-confirm="Hapus kategori {{ $cat->label }}?" data-confirm-type="error" data-confirm-danger="1" data-confirm-ok="Ya, hapus">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            @else
                                                <span class="text-fade" title="Sedang digunakan">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                @if($ingredientCategories->isEmpty())
                                    <tr><td colspan="4" class="text-center text-soft py-4">Belum ada kategori bahan baku</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-plus-circle me-1"></i> Tambah Baru</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('master.ingredient-categories.store') }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label">Nama Kategori Bahan <span class="text-danger">*</span></label>
                                <input type="text" name="label" class="form-control"
                                       placeholder="cth: Sauce, Powder, Syrup" value="{{ old('label') }}" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Tambah Kategori</button>
                        </form>
                    </div>
                </div>
                <div class="alert alert-info">
                    <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i> Informasi</div>
                    <p class="mb-1">Kategori tersedia di form <strong>Edit Bahan Baku</strong>.</p>
                    <p class="mb-1">Urutan tabel menentukan tampilan di <strong>Saldo Stok</strong> &amp; <strong>Pencatatan Harian</strong>.</p>
                    <p class="mb-0">Hanya bisa dihapus jika tidak ada bahan yang memakainya.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== TAB MENU ===================== --}}
    <div class="tab-pane fade" id="pane-menu" role="tabpanel">
        <div class="mb-2 d-flex justify-content-end gap-2">
            @include('master.partials.import-buttons', ['entity' => 'menu-categories', 'label' => 'Kategori Menu'])
        </div>
        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-list-ul me-1"></i> Daftar Kategori Menu</span>
                        <span class="badge bg-secondary"><i class="bi bi-grip-vertical me-1"></i> Drag untuk urutkan</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th style="width:36px"></th>
                                    <th>Nama Kategori</th>
                                    <th style="width:120px" class="text-center">Digunakan</th>
                                    <th style="width:60px"></th>
                                </tr>
                            </thead>
                            <tbody id="menuCatList">
                                @foreach($menuCategories as $cat)
                                    @php $count = \App\Models\Menu::where('category_id', $cat->id)->count(); @endphp
                                    <tr data-id="{{ $cat->id }}" class="menu-cat-row">
                                        <td class="text-center drag-handle" style="cursor:grab">
                                            <i class="bi bi-grip-vertical text-fade"></i>
                                        </td>
                                        <td>
                                            <form method="POST" action="{{ route('master.menu-categories.update', $cat) }}"
                                                  class="d-flex gap-2 align-items-center">
                                                @csrf @method('PUT')
                                                <input type="text" name="name" value="{{ $cat->name }}"
                                                       class="form-control form-control-sm" style="max-width:240px">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Simpan">
                                                    <i class="bi bi-check2"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge {{ $count > 0 ? 'bg-primary' : 'bg-secondary' }}">{{ $count }} menu</span>
                                        </td>
                                        <td class="text-center">
                                            @if($count === 0)
                                                <form method="POST" action="{{ route('master.menu-categories.destroy', $cat) }}"
                                                      data-confirm="Hapus kategori {{ $cat->name }}?" data-confirm-type="error" data-confirm-danger="1" data-confirm-ok="Ya, hapus">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            @else
                                                <span class="text-fade" title="Sedang digunakan">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                @if($menuCategories->isEmpty())
                                    <tr><td colspan="4" class="text-center text-soft py-4">Belum ada kategori menu</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-plus-circle me-1"></i> Tambah Baru</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('master.menu-categories.store') }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label">Nama Kategori Menu <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control"
                                       placeholder="cth: Ice Cream, Milk Tea" value="{{ old('name') }}" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Tambah Kategori</button>
                        </form>
                    </div>
                </div>
                <div class="alert alert-info">
                    <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i> Informasi</div>
                    <p class="mb-1">Kategori tersedia di form <strong>Tambah/Edit Menu</strong>.</p>
                    <p class="mb-0">Hanya bisa dihapus jika tidak ada menu yang memakainya.</p>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>
<script>
    (function () {
        var hash = window.location.hash;
        if (hash === '#menu') {
            var tab = document.getElementById('tab-menu');
            if (tab) bootstrap.Tab.getOrCreateInstance(tab).show();
        }
    })();

    Sortable.create(document.getElementById('ingCatList'), {
        handle: '.drag-handle', animation: 150,
        onEnd: function () {
            var order = Array.from(document.querySelectorAll('#ingCatList .ing-cat-row')).map(r => r.dataset.id);
            fetch('{{ route("master.ingredient-categories.reorder") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ order: order })
            });
        }
    });

    Sortable.create(document.getElementById('menuCatList'), {
        handle: '.drag-handle', animation: 150,
        onEnd: function () {
            var order = Array.from(document.querySelectorAll('#menuCatList .menu-cat-row')).map(r => r.dataset.id);
            fetch('{{ route("master.menu-categories.reorder") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ order: order })
            });
        }
    });
</script>
@endpush
