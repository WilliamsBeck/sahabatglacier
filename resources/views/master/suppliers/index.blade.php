@extends('layouts.app')
@section('title', 'Supplier')

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1 class="page-title">Manajemen Supplier</h1>
        <p class="page-subtitle">Database rantai pasok logistik resmi</p>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        {{-- Supplier berlaku untuk semua toko → ubah hanya Super Admin --}}
        @if(auth()->user()->isSuperAdmin())
        @include('master.partials.import-buttons', ['entity' => 'suppliers', 'label' => 'Supplier'])
        <a href="{{ route('master.suppliers.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i> Tambah Supplier
        </a>
        @endif
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control"
                       placeholder="Cari nama supplier…" value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <select name="type" class="form-select">
                    <option value="">Semua Tipe</option>
                    <option value="zhisheng" {{ request('type') === 'zhisheng' ? 'selected' : '' }}>Pusat</option>
                    <option value="local_supplier" {{ request('type') === 'local_supplier' ? 'selected' : '' }}>Supplier Lokal</option>
                    <option value="other" {{ request('type') === 'other' ? 'selected' : '' }}>Lainnya</option>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2 justify-content-end">
                <button type="submit" class="btn btn-primary">Cari</button>
                <a href="{{ route('master.suppliers.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-index table-balanced mb-0">
            <thead>
                <tr>
                    <th width="48">#</th>
                    <th class="col-name">Supplier</th>
                    <th>Tipe</th>
                    <th>Kontak</th>
                    <th>Status</th>
                    <th width="80">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($suppliers as $sup)
                    <tr>
                        <td class="text-soft">{{ $suppliers->firstItem() + $loop->index }}</td>
                        <td class="col-name">
                            <span class="fw-medium">{{ $sup->name }}</span>
                        </td>
                        <td>
                            @if($sup->type === 'zhisheng')
                                <span class="badge bg-primary">{{ $sup->type_label }}</span>
                            @elseif($sup->type === 'local_supplier')
                                <span class="badge bg-success">{{ $sup->type_label }}</span>
                            @else
                                <span class="badge bg-secondary">{{ $sup->type_label }}</span>
                            @endif
                        </td>
                        <td><span class="text-soft">{{ $sup->contact ?? '—' }}</span></td>
                        <td>
                            @if($sup->is_active)
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
                                <x-action-view :href="route('master.suppliers.show', $sup)" />
                                @if(auth()->user()->isSuperAdmin())
                                <x-action-edit :href="route('master.suppliers.edit', $sup)" />
                                <x-action-delete :action="route('master.suppliers.destroy', $sup)"
                                                 confirm="Hapus supplier ini?" />
                                @endif
                            </x-action-menu>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-5 text-soft text-center">
                            <i class="bi bi-folder2-open fs-3 d-block mb-2 opacity-25"></i>
                            Belum ada data supplier.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($suppliers->hasPages())
        <div class="card-footer d-flex justify-content-between align-items-center">
            <div class="text-soft">
                Menampilkan {{ $suppliers->firstItem() }}–{{ $suppliers->lastItem() }} dari {{ $suppliers->total() }}
            </div>
            <div>{{ $suppliers->withQueryString()->links() }}</div>
        </div>
    @endif
</div>
@endsection
