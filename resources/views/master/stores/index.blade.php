@extends('layouts.app')
@section('title', 'Toko')

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1 class="page-title">Manajemen Toko</h1>
        <p class="page-subtitle">Total {{ $stores->total() }} toko terdaftar dalam sistem</p>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        @include('master.partials.import-buttons', ['entity' => 'stores', 'label' => 'Toko'])
        <a href="{{ route('master.stores.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i> Tambah Toko
        </a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control"
                       placeholder="Cari nama atau kode toko…" value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <select name="area" class="form-select">
                    <option value="">Semua Area</option>
                    @foreach($areas as $area)
                        <option value="{{ $area }}" {{ request('area') == $area ? 'selected' : '' }}>{{ $area }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">Semua Status</option>
                    <option value="1" {{ request('status') === '1' ? 'selected' : '' }}>Aktif</option>
                    <option value="0" {{ request('status') === '0' ? 'selected' : '' }}>Nonaktif</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2 justify-content-end">
                <button type="submit" class="btn btn-primary">Cari</button>
                <a href="{{ route('master.stores.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-index table-balanced mb-0">
            <thead>
                <tr>
                    <th width="48" style="white-space:nowrap">#</th>
                    <th class="col-name">Toko</th>
                    <th>Kode</th>
                    <th>Area</th>
                    <th>Status</th>
                    <th width="80">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($stores as $s)
                    <tr>
                        <td class="text-soft" style="white-space:nowrap">{{ $stores->firstItem() + $loop->index }}</td>
                        <td class="col-name">
                            <span class="fw-medium">{{ $s->name }}</span>
                        </td>
                        <td><code>{{ $s->store_code }}</code></td>
                        <td><span class="text-soft">{{ $s->area }}</span></td>
                        <td>
                            @if($s->is_active)
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
                                <x-action-view :href="route('master.stores.show', $s)" />
                                <x-action-edit :href="route('master.stores.edit', $s)" />
                                <x-action-delete :action="route('master.stores.destroy', $s)"
                                                 confirm="Hapus toko {{ $s->name }}?" />
                            </x-action-menu>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-5 text-soft text-center">
                            <i class="bi bi-shop fs-3 d-block mb-2 opacity-25"></i>
                            Belum ada data toko.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($stores->hasPages())
        <div class="card-footer d-flex justify-content-between align-items-center">
            <div class="text-soft">
                Menampilkan {{ $stores->firstItem() }}–{{ $stores->lastItem() }} dari {{ $stores->total() }}
            </div>
            <div>{{ $stores->withQueryString()->links() }}</div>
        </div>
    @endif
</div>
@endsection
