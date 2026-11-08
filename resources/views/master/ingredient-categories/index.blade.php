@extends('layouts.app')
@section('title', 'Kategori Bahan Baku')
{{-- Kategori berlaku untuk semua toko: selain Super Admin hanya bisa melihat --}}
@section('data_global', '1')
@section('content')
<div class="page-header d-flex justify-content-between align-items-center">
    <h4 class="page-title">Kategori Bahan Baku</h4>
    <div class="d-flex gap-2 flex-wrap">
        @include('master.partials.import-buttons', ['entity' => 'ingredient-categories', 'label' => 'Kategori'])
        <a href="{{ route('master.ingredients.index') }}" class="btn btn-outline-secondary btn-sm btn-back">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="row g-3">
    {{-- Daftar kategori --}}
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>Daftar Kategori</span>
                <span class="text-muted small fw-normal"><i class="bi bi-grip-vertical me-1"></i>Drag untuk ubah urutan</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:36px"></th>
                            <th>Nama Kategori</th>
                            <th style="width:120px" class="text-center">Digunakan</th>
                            <th style="width:80px"></th>
                        </tr>
                    </thead>
                    <tbody id="catList">
                        @foreach($categories as $cat)
                        <tr data-id="{{ $cat->id }}" class="cat-row">
                            <td class="text-center text-muted drag-handle" style="cursor:grab">
                                <i class="bi bi-grip-vertical"></i>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('master.ingredient-categories.update', $cat) }}"
                                      class="d-flex gap-2 align-items-center">
                                    @csrf @method('PUT')
                                    <input type="text" name="label" value="{{ $cat->label }}"
                                           class="form-control form-control-sm" style="max-width:200px">
                                    <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-2">
                                        <i class="bi bi-check2"></i>
                                    </button>
                                </form>
                            </td>
                            <td class="text-center">
                                @php $count = \App\Models\Ingredient::where('category', $cat->name)->count(); @endphp
                                <span class="badge {{ $count > 0 ? 'bg-primary' : 'bg-light text-muted border' }}">
                                    {{ $count }} bahan
                                </span>
                            </td>
                            <td class="text-center">
                                @if($count === 0)
                                <form method="POST" action="{{ route('master.ingredient-categories.destroy', $cat) }}"
                                      data-confirm="Hapus kategori {{ $cat->label }}?" data-confirm-type="error" data-confirm-danger="1" data-confirm-ok="Ya, hapus">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                @else
                                <span class="text-muted small">—</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Form tambah --}}
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold">Tambah Kategori Baru</div>
            <div class="card-body">
                <form method="POST" action="{{ route('master.ingredient-categories.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Kategori <span class="text-danger">*</span></label>
                        <input type="text" name="label" class="form-control"
                               placeholder="cth: Sauce" value="{{ old('label') }}" autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-plus-circle me-1"></i> Tambah
                    </button>
                </form>
            </div>
        </div>
        <div class="card mt-3 border-0 bg-light">
            <div class="card-body small text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Kategori yang ditambahkan langsung tersedia di form <strong>Edit Bahan</strong>.
                Urutan di sini menentukan urutan tampilan di <strong>Saldo Stok</strong> dan <strong>Pencatatan Harian</strong>.<br><br>
                Kategori hanya bisa dihapus jika tidak ada bahan yang menggunakannya.
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>
<script>
Sortable.create(document.getElementById('catList'), {
    handle: '.drag-handle',
    animation: 150,
    onEnd: function() {
        var order = Array.from(document.querySelectorAll('#catList .cat-row'))
            .map(function(r) { return r.dataset.id; });
        fetch('{{ route("master.ingredient-categories.reorder") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ order: order })
        });
    }
});
</script>
@endpush
