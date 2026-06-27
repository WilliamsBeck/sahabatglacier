@extends('layouts.app')
@section('title', 'Menu')

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1 class="page-title">Daftar Menu</h1>
        <p class="page-subtitle">Manajemen master data menu dan varian resep</p>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        @unless(auth()->user()->role === 'admin_area')
        @include('master.partials.import-bundle-buttons', ['bundle' => 'menu-resep', 'label' => 'Menu & Resep', 'exportUrl' => route('master.export-bundle', 'menu-resep')])
        <a href="{{ route('master.menus.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i> Tambah Menu
        </a>
        @endunless
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control"
                       placeholder="Cari nama menu…" value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <select name="category_id" class="form-select">
                    <option value="">Semua Kategori</option>
                    @foreach($menuCategories as $cat)
                        <option value="{{ $cat->id }}" {{ request('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2 justify-content-end">
                <button type="submit" class="btn btn-primary">Cari</button>
                <a href="{{ route('master.menus.index') }}" class="btn btn-outline-secondary">Reset</a>
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
                    <th class="col-name">Nama Menu</th>
                    <th>Kategori</th>
                    <th>Versi Resep</th>
                    <th>Status</th>
                    <th width="80">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($menus as $menu)
                    <tr>
                        <td class="text-soft text-nowrap">{{ $menus->firstItem() + $loop->index }}</td>
                        <td class="col-name">
                            <span class="fw-medium">{{ $menu->name }}</span>
                        </td>
                        <td class="text-soft small">{{ $menu->menuCategory?->name ?? '—' }}</td>
                        <td>
                            @if($menu->recipe_versions_count > 0)
                                <span class="badge bg-primary">{{ $menu->recipe_versions_count }} versi</span>
                            @else
                                <span class="badge bg-secondary">Belum ada resep</span>
                            @endif
                        </td>
                        <td>
                            @if($menu->is_active)
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
                                <x-action-view :href="route('master.menus.show', $menu)" />
                                @unless(auth()->user()->role === 'admin_area')
                                <x-action-edit :href="route('master.menus.edit', $menu)" label="Edit & Resep" />
                                <x-action-delete :action="route('master.menus.destroy', $menu)"
                                                 confirm="Hapus menu ini beserta semua resepnya?" />
                                @endunless
                            </x-action-menu>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-5 text-soft text-center">
                            <i class="bi bi-cup-straw fs-3 d-block mb-2 opacity-25"></i>
                            Belum ada data menu.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($menus->hasPages())
        <div class="card-footer d-flex justify-content-between align-items-center">
            <div class="text-soft">
                Menampilkan {{ $menus->firstItem() }}–{{ $menus->lastItem() }} dari {{ $menus->total() }}
            </div>
            <div>{{ $menus->withQueryString()->links() }}</div>
        </div>
    @endif
</div>
@endsection
