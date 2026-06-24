@extends('layouts.app')
@section('title', 'Bahan Baku')

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1 class="page-title">Bahan Baku &amp; Setengah Jadi</h1>
        <p class="page-subtitle">Manajemen master data bahan</p>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        <a href="{{ route('master.import-bundle.template', 'bahan') }}" class="btn btn-outline-success">
            <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Template
        </a>
        <a href="{{ route('master.export-bundle', 'bahan') }}" class="btn btn-outline-secondary">
            <i class="bi bi-database-down me-1"></i> Export Data
        </a>
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importBundleModal">
            <i class="bi bi-upload me-1"></i> Impor
        </button>
        <a href="{{ route('master.ingredients.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i> Tambah Bahan
        </a>
        <div class="modal fade" id="importBundleModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('master.import-bundle.preview', 'bahan') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Impor Bahan, Kemasan & Komposisi</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small mb-2">
                                Satu file berisi 3 sheet: <b>Bahan</b>, <b>Kemasan</b>, <b>Komposisi</b>. Unduh template
                                dulu, isi, lalu unggah di sini. Bahan diproses lebih dulu sehingga Kemasan & Komposisi
                                bisa mengacu bahan baru di file yang sama.
                            </p>
                            <input type="file" name="file" class="form-control" accept=".xlsx,.xls" required>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i> Pratinjau</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control"
                       placeholder="Cari nama bahan baku…" value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <select name="type" class="form-select">
                    <option value="">Semua Tipe</option>
                    <option value="raw" {{ request('type') === 'raw' ? 'selected' : '' }}>Raw</option>
                    <option value="semi_finished" {{ request('type') === 'semi_finished' ? 'selected' : '' }}>Semi</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="category" class="form-select">
                    <option value="">Semua Kategori</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->name }}" {{ request('category') === $cat->name ? 'selected' : '' }}>
                            {{ $cat->label ?? ucfirst($cat->name) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2 justify-content-end">
                <button type="submit" class="btn btn-primary">Cari</button>
                <a href="{{ route('master.ingredients.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-index table-balanced mb-0">
            <thead>
                <tr>
                    <th width="56" class="text-nowrap">#</th>
                    <th class="col-name">Nama Bahan</th>
                    <th>Tipe</th>
                    <th>Satuan</th>
                    <th>Status</th>
                    <th width="80">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($ingredients as $ing)
                    <tr>
                        <td class="text-soft text-nowrap">{{ $ingredients->firstItem() + $loop->index }}</td>
                        <td class="col-name">
                            <span class="fw-medium">{{ $ing->name }}</span>
                        </td>
                        <td>
                            @if($ing->type === 'raw')
                                <span class="badge bg-primary">Raw</span>
                            @else
                                <span class="badge bg-info">Semi</span>
                            @endif
                        </td>
                        <td><span class="text-soft">{{ $ing->unit_base }}</span></td>
                        <td>
                            @if($ing->is_active)
                                <span class="badge bg-success">
                                    <span class="d-inline-block rounded-circle me-1" style="width:5px;height:5px;background:currentColor"></span>
                                    Aktif
                                </span>
                            @else
                                <span class="badge bg-secondary">Nonaktif</span>
                            @endif
                        </td>
                        <td>
                            <x-action-menu>
                                <x-action-view :href="route('master.ingredients.show', $ing)" />
                                <x-action-edit :href="route('master.ingredients.edit', $ing)" />
                                <x-action-delete :action="route('master.ingredients.destroy', $ing)"
                                                 confirm="Hapus bahan baku ini?" />
                            </x-action-menu>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-5 text-soft text-center">
                            <i class="bi bi-box2 fs-3 d-block mb-2 opacity-25"></i>
                            Belum ada data bahan.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($ingredients->hasPages())
        <div class="card-footer d-flex justify-content-between align-items-center">
            <div class="text-soft">
                Menampilkan {{ $ingredients->firstItem() }}–{{ $ingredients->lastItem() }} dari {{ $ingredients->total() }}
            </div>
            <div>{{ $ingredients->withQueryString()->links() }}</div>
        </div>
    @endif
</div>
@endsection
