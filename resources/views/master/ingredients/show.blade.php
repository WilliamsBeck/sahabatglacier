@extends('layouts.app')
@section('title', 'Detail Bahan')

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1 class="page-title">{{ $ingredient->name }}</h1>
        <p class="page-subtitle">Detail bahan (hanya lihat)</p>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        @unless(auth()->user()->role === 'admin_area')
        <a href="{{ route('master.ingredients.edit', $ingredient) }}" class="btn btn-primary">
            <i class="bi bi-pencil me-1"></i> Edit
        </a>
        @endunless
        <a href="{{ route('master.ingredients.index') }}" class="btn btn-outline-secondary btn-sm btn-back">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i> Info Dasar</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Nama</dt><dd class="col-7">{{ $ingredient->name }}</dd>
                    <dt class="col-5 text-muted">Tipe</dt>
                    <dd class="col-7">
                        <span class="badge bg-{{ $ingredient->type === 'raw' ? 'primary' : 'info' }}">
                            {{ $ingredient->type === 'raw' ? 'Raw' : 'Semi' }}
                        </span>
                    </dd>
                    <dt class="col-5 text-muted">Kategori</dt><dd class="col-7">{{ $ingredient->category ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Satuan Dasar</dt><dd class="col-7">{{ $ingredient->unit_base }}</dd>
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7">
                        <span class="badge bg-{{ $ingredient->is_active ? 'success' : 'secondary' }}">
                            {{ $ingredient->is_active ? 'Aktif' : 'Nonaktif' }}
                        </span>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        @if($ingredient->type === 'raw')
            <div class="card">
                <div class="card-header fw-semibold"><i class="bi bi-box-seam me-1"></i> Kemasan ({{ $ingredient->packagings->count() }})</div>
                <div class="table-responsive">
                    <table class="table table-index mb-0 align-middle">
                        <thead><tr>
                            <th>Nama Kemasan</th><th>Supplier</th>
                            <th class="text-end">Pack/Dus</th><th class="text-end">Isi/Pack</th>
                            <th class="text-end">Total/Dus</th><th>Status</th>
                        </tr></thead>
                        <tbody>
                            @forelse($ingredient->packagings as $p)
                            <tr>
                                <td>{{ $p->packaging_name }}</td>
                                <td class="small">{{ $p->supplier?->name ?? '—' }}</td>
                                <td class="text-end">{{ number_format($p->crate_to_pack, 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($p->pack_to_base, 0, ',', '.') }}</td>
                                <td class="text-end fw-semibold">{{ number_format($p->crate_to_pack * $p->pack_to_base, 0, ',', '.') }} {{ $ingredient->unit_base }}</td>
                                <td><span class="badge bg-{{ $p->is_active ? 'success' : 'secondary' }}-subtle text-{{ $p->is_active ? 'success' : 'secondary' }}">{{ $p->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">Belum ada kemasan.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <div class="card">
                <div class="card-header fw-semibold"><i class="bi bi-layers me-1"></i> Komposisi Bahan Baku ({{ $ingredient->compositions->count() }})</div>
                <div class="table-responsive">
                    <table class="table table-index mb-0 align-middle">
                        <thead><tr>
                            <th>Bahan Baku</th>
                            <th class="text-end">Qty per 1 {{ $ingredient->unit_base }}</th>
                        </tr></thead>
                        <tbody>
                            @forelse($ingredient->compositions as $c)
                            <tr>
                                <td>{{ $c->child?->name ?? '—' }} <span class="text-muted small">({{ $c->child?->unit_base }})</span></td>
                                <td class="text-end">{{ rtrim(rtrim(number_format($c->qty_needed, 6, ',', '.'), '0'), ',') }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="2" class="text-center text-muted py-4">Belum ada komposisi.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
