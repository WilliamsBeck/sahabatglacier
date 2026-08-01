@extends('layouts.app')
@section('title','Detail Penjualan')
@section('content')
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h4 class="page-title">Detail Penjualan</h4>
        <p class="text-muted mb-0 small">{{ $store->name }} &mdash;
            {{ $p['period_type'] === 'mid_month' ? '1–15' : '1–30/31' }}
            {{ $p['month'] }}/{{ $p['year'] }}
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('sales.monthly.index', ['month' => $p['month'], 'year' => $p['year']]) }}"
           class="btn btn-outline-secondary btn-sm btn-back"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
        <a href="{{ route('sales.period.edit', $p) }}" class="btn btn-warning btn-sm">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold">Ringkasan</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted">Toko</td><td class="fw-semibold">{{ $store->name }}</td></tr>
                    <tr><td class="text-muted">Periode</td>
                        <td><span class="badge bg-light text-dark border">
                            {{ $p['period_type'] === 'mid_month' ? '1–15' : '1–30/31' }}
                            {{ $p['month'] }}/{{ $p['year'] }}
                        </span></td>
                    </tr>
                    <tr><td class="text-muted">Total Menu</td><td>{{ $sales->count() }} menu</td></tr>
                    <tr><td class="text-muted">Total Terjual</td>
                        <td class="fw-bold">{{ number_format($sales->filter(fn($x) => $x->menu?->count_in_total ?? true)->sum('total_sold'), 0, ',', '.') }} pcs</td>
                    </tr>
                    @if($revenue && $revenue->total_revenue > 0)
                    <tr><td class="text-muted">Omset</td>
                        <td class="fw-bold text-primary">Rp {{ number_format($revenue->total_revenue, 0, ',', '.') }}</td>
                    </tr>
                    @endif
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold">Daftar Menu Terjual</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Menu</th>
                            <th class="text-end">Qty Terjual</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $grouped = $sales->sortBy([
                                fn($a, $b) => ($a->menu->menuCategory->sort_order ?? 9999) <=> ($b->menu->menuCategory->sort_order ?? 9999),
                                fn($a, $b) => ($a->menu->name ?? '') <=> ($b->menu->name ?? ''),
                            ])->groupBy(fn($s) => $s->menu->menuCategory->name ?? 'Tanpa Kategori');
                        @endphp
                        @foreach($grouped as $catName => $items)
                        <tr class="table-secondary">
                            <td colspan="2" class="fw-bold small text-uppercase">{{ $catName }}</td>
                        </tr>
                        @foreach($items as $sale)
                        <tr>
                            <td class="fw-semibold">{{ $sale->menu->name }}</td>
                            <td class="text-end fw-bold">{{ number_format($sale->total_sold, 0, ',', '.') }} pcs</td>
                        </tr>
                        @endforeach
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td>Total</td>
                            <td class="text-end">{{ number_format($sales->filter(fn($x) => $x->menu?->count_in_total ?? true)->sum('total_sold'), 0, ',', '.') }} pcs</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
