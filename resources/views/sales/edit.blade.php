@extends('layouts.app')
@section('title','Edit Penjualan')
@section('content')
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h4 class="page-title">Edit Penjualan</h4>
        <p class="text-muted mb-0 small">{{ $store->name }} &mdash;
            {{ $p['period_type'] === 'mid_month' ? '1–15' : '1–30/31' }}
            {{ $p['month'] }}/{{ $p['year'] }}
        </p>
    </div>
    <a href="{{ route('sales.period.show', $p) }}" class="btn btn-outline-secondary btn-sm btn-back">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

<form method="POST" action="{{ route('sales.period.update') }}">
    @csrf @method('PUT')
    <input type="hidden" name="store_id"    value="{{ $p['store_id'] }}">
    <input type="hidden" name="month"       value="{{ $p['month'] }}">
    <input type="hidden" name="year"        value="{{ $p['year'] }}">
    <input type="hidden" name="period_type" value="{{ $p['period_type'] }}">

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header fw-semibold">Info Periode</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Toko</label>
                        <input type="text" class="form-control" value="{{ $store->name }}" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Periode</label>
                        <input type="text" class="form-control"
                               value="{{ $p['period_type'] === 'mid_month' ? '1–15' : '1–30/31' }} {{ $p['month'] }}/{{ $p['year'] }}" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Omset Periode</label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            {{-- text + .num-fmt: tanpa panah spinner, ribuan otomatis bertitik.
                                 Nilai dibulatkan ke rupiah utuh — desimal .00 dari DB tidak perlu tampil. --}}
                            <input type="text" name="total_revenue" class="form-control text-end num-fmt"
                                   value="{{ old('total_revenue', $revenue ? (int) round($revenue->total_revenue) : '') }}"
                                   placeholder="0">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-header fw-semibold">Daftar Menu Terjual</div>
                <div class="card-body p-0">
                    @php
                        $existingMap = $sales->keyBy('menu_id');
                        $allMenus    = $menus; // sudah terurut kategori → input dari controller
                        $half        = (int) ceil($allMenus->count() / 2);
                    @endphp
                    <div class="row g-0">
                        @foreach([$allMenus->take($half), $allMenus->slice($half)] as $col => $chunk)
                        <div class="col-md-6 {{ $col === 0 ? 'border-end' : '' }}">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Menu</th>
                                        <th style="width:110px">Qty (pcs)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($chunk as $idx => $menu)
                                    @php $existing = $existingMap[$menu->id] ?? null; @endphp
                                    <tr>
                                        <td>
                                            <input type="hidden" name="items[{{ $idx }}][menu_id]" value="{{ $menu->id }}">
                                            <span class="{{ $existing ? 'fw-semibold' : 'text-muted' }}">{{ $menu->name }}</span>
                                        </td>
                                        <td>
                                            {{-- text + .num-fmt: panah spinner hilang, ribuan bertitik --}}
                                            <input type="text" name="items[{{ $idx }}][total_sold]"
                                                   class="form-control form-control-sm text-end num-fmt" placeholder="0"
                                                   value="{{ old("items.{$idx}.total_sold", $existing?->total_sold ?? 0) }}">
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-check-lg me-1"></i>Simpan Perubahan
                </button>
                <a href="{{ route('sales.period.show', $p) }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </div>
    </div>
</form>
@endsection
