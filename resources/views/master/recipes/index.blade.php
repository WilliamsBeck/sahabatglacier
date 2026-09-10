@extends('layouts.app')
@section('title','Resep')
@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1 class="page-title">Resep Menu</h1>
        <p class="page-subtitle">Versi resep aktif menentukan HPP per periode</p>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end align-items-start">
        @unless(auth()->user()->role === 'admin_area')
        @include('master.partials.import-buttons', ['entity' => 'recipes', 'label' => 'Resep Menu'])
        <a href="{{ route('master.recipes.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Tambah Resep</a>
        @endunless
    </div>
</div>
<div class="card mb-3"><div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-md-3"><select name="menu_id" class="form-select"><option value="">Semua Menu</option>@foreach($menus as $m)<option value="{{ $m->id }}" {{ request('menu_id')==$m->id?'selected':'' }}>{{ $m->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><select name="store_id" class="form-select">
            <option value="">Semua Toko</option>
            <option value="default" {{ request('store_id')==='default' ? 'selected' : '' }}>— Default (semua toko) —</option>
            @foreach($stores as $s)<option value="{{ $s->id }}" {{ request('store_id')==$s->id?'selected':'' }}>{{ $s->name }}</option>@endforeach
        </select></div>
        <div class="col-md-auto ms-auto d-flex gap-2"><button type="submit" class="btn btn-primary">Cari</button><a href="{{ route('master.recipes.index') }}" class="btn btn-outline-secondary">Reset</a></div>
    </form>
</div></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-index mb-0">
        <thead>
            <tr>
                <th class="col-name">Menu</th>
                <th>Berlaku Untuk</th>
                <th>Berlaku Sejak</th>
                <th class="col-name">Komposisi Bahan</th>
                <th>Dibuat Oleh</th>
                <th style="width:70px">Aksi</th>
            </tr>
        </thead>
        <tbody>
            {{-- $recipes = daftar VERSI resep; tiap $group = kumpulan baris bahan
                 dari satu resep yang sama (bisa gabungan beberapa toko). --}}
            @forelse($recipes as $group)
            @php
                $first     = $group->first();
                $jmlVersi  = $group->pluck('recipe_group_id')->unique()->count();
            @endphp
            <tr>
                <td class="col-name fw-semibold align-top">{{ $first->menu->name }}</td>
                <td class="align-top">
                    @php
                        $isDefault   = $group->pluck('store_id')->contains(null);
                        $storeNames  = $group->pluck('store.name')->filter()->unique()->values();
                    @endphp
                    @if($isDefault)
                        <span class="badge bg-success-subtle text-success-emphasis">Default (semua toko)</span>
                    @else
                        <div class="d-flex flex-wrap gap-1">
                            @foreach($storeNames as $sn)
                                <span class="badge bg-info-subtle text-info-emphasis"><i class="bi bi-shop me-1"></i>{{ $sn }}</span>
                            @endforeach
                        </div>
                    @endif
                    @if($jmlVersi > 1)
                        {{-- Resep sama persis tapi disimpan terpisah per toko. Ditampilkan
                             jadi satu baris; penanda ini supaya tetap jelas kalau di
                             database datanya masih {{ $jmlVersi }} versi terpisah. --}}
                        <div class="text-soft mt-1" style="font-size:.7rem">
                            <i class="bi bi-info-circle me-1"></i>{{ $jmlVersi }} versi tersimpan, isinya sama
                        </div>
                    @endif
                </td>
                <td class="align-top">
                    <span class="badge bg-secondary">{{ $first->effective_from->format('d') . ' ' . ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'][(int)$first->effective_from->format('n')] . ' ' . $first->effective_from->format('Y') }}</span>
                </td>
                <td class="col-name">
                    @foreach($group->unique('ingredient_id') as $r)
                    <div class="small">
                        {{ $r->ingredient->name }}
                        <span class="text-soft">— {{ number_format($r->qty_usage, 0, ',', '.') }} {{ $r->unit }}</span>
                    </div>
                    @endforeach
                </td>
                <td class="align-top small text-soft">
                    {{ $group->pluck('createdBy.name')->filter()->unique()->implode(', ') ?: '-' }}
                </td>
                @unless(auth()->user()->role === 'admin_area')
                <td class="align-top">
                    <x-action-menu>
                        <li>
                            <a class="dropdown-item" href="{{ route('master.recipes.duplicate', $first->id) }}">
                                <i class="bi bi-copy"></i> Duplikat Versi
                            </a>
                        </li>
                    </x-action-menu>
                </td>
                @endunless
            </tr>
            @empty
            <tr><td colspan="6" class="py-4 text-soft text-center">Belum ada resep</td></tr>
            @endforelse
        </tbody>
    </table>
</div></div>
@if($recipes->hasPages())<div class="card-footer">{{ $recipes->withQueryString()->links() }}</div>@endif
</div>
@endsection
