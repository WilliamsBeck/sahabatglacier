@extends('layouts.app')
@section('title', 'Detail Menu')

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1 class="page-title">{{ $menu->name }}</h1>
        <p class="page-subtitle">Detail menu (hanya lihat)</p>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        {{-- Admin area boleh masuk form untuk kelola versi resep tokonya --}}
        <a href="{{ route('master.menus.edit', $menu) }}" class="btn btn-primary">
            <i class="bi bi-pencil me-1"></i> Edit &amp; Resep
        </a>
        <a href="{{ route('master.menus.index') }}" class="btn btn-outline-secondary btn-sm btn-back">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

<div class="card mb-3" style="max-width:640px">
    <div class="card-body">
        <dl class="row mb-0 small">
            <dt class="col-4 text-muted">Nama</dt><dd class="col-8">{{ $menu->name }}</dd>
            <dt class="col-4 text-muted">Kategori</dt><dd class="col-8">{{ $menu->menuCategory?->name ?? '—' }}</dd>
            <dt class="col-4 text-muted">Status</dt>
            <dd class="col-8">
                <span class="badge bg-{{ $menu->is_active ? 'success' : 'secondary' }}">
                    {{ $menu->is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
            </dd>
        </dl>
    </div>
</div>

@foreach($recipes as $groupId => $versi)
    <div class="card mb-3">
        <div class="card-header fw-semibold d-flex justify-content-between">
            <span><i class="bi bi-card-list me-1"></i> Versi Resep</span>
            <span class="text-muted small">Berlaku sejak {{ \Carbon\Carbon::parse($versi->effective_from)->isoFormat('D MMM Y') }}</span>
        </div>
        <div class="card-body py-2 px-3 border-bottom small text-muted">
            Toko: <span class="fw-medium text-body">{{ $versi->stores->implode(', ') }}</span>
            @if($versi->stores->count() > 1)
                <span class="text-muted">({{ $versi->stores->count() }} toko, resep sama)</span>
            @endif
        </div>

        @if($versi->seragam)
            {{-- Resep tiap toko identik: cukup ditampilkan sekali --}}
            <div class="table-responsive">
                <table class="table table-index mb-0 align-middle">
                    <thead><tr><th>Bahan</th><th class="text-end">Qty</th><th>Satuan</th></tr></thead>
                    <tbody>
                        @foreach($versi->items as $r)
                        <tr>
                            <td>{{ $r->ingredient?->name ?? '-' }}</td>
                            <td class="text-end">{{ number_format($r->qty_usage, 0, ',', '.') }}</td>
                            <td class="text-muted small">{{ $r->unit }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            {{-- Jarang terjadi: satu versi tapi isi per toko ternyata beda.
                 Ditampilkan terpisah supaya perbedaannya kelihatan, bukan disamarkan. --}}
            @foreach($versi->perStore as $rows)
                <div class="px-3 pt-2 small fw-semibold">
                    {{ $rows->first()->store?->name ?? 'Semua toko (default)' }}
                </div>
                <div class="table-responsive">
                    <table class="table table-index mb-0 align-middle">
                        <thead><tr><th>Bahan</th><th class="text-end">Qty</th><th>Satuan</th></tr></thead>
                        <tbody>
                            @foreach($rows as $r)
                            <tr>
                                <td>{{ $r->ingredient?->name ?? '-' }}</td>
                                <td class="text-end">{{ number_format($r->qty_usage, 0, ',', '.') }}</td>
                                <td class="text-muted small">{{ $r->unit }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        @endif
    </div>
@endforeach

@if($recipes->isEmpty())
    <div class="alert alert-warning"><i class="bi bi-info-circle me-1"></i> Menu ini belum punya resep.</div>
@endif
@endsection
