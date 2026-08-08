@extends('layouts.app')
@section('title','Cone & Cup')

@push('styles')
<style>
    /* Sembunyikan panah naik/turun pada input angka — angkanya diketik langsung */
    .cone-cup-table input[type=number]::-webkit-inner-spin-button,
    .cone-cup-table input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    .cone-cup-table input[type=number] { -moz-appearance: textfield; appearance: textfield; }
</style>
@endpush

@section('content')
@include('sales._hpp_tabs', ['currentHppTab' => 'cone-cup'])

@php
    $monthNames = ['','Januari','Februari','Maret','April','Mei','Juni','Juli',
                   'Agustus','September','Oktober','November','Desember'];
    $n = fn($v) => number_format($v, 0, ',', '.');
@endphp

<p class="text-muted mb-3 small">Cone &amp; Cup - Rekonsiliasi selisih pemakaian (Tidak ada penjelasan = sisa Selisih setelah dijelaskan Rusak &amp; Overfill)</p>

{{-- Filter --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Toko</label>
                <select name="store_id" class="form-select" required>
                    <option value="">— Pilih Toko —</option>
                    @foreach($stores as $s)
                        <option value="{{ $s->id }}" {{ $storeId==$s->id ? 'selected':'' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Bulan</label>
                <select name="month" class="form-select">
                    @foreach($monthNames as $i => $nm)
                        @if($i>0)<option value="{{ $i }}" {{ $month==$i ? 'selected':'' }}>{{ $nm }}</option>@endif
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Tahun</label>
                <input type="number" name="year" class="form-control" value="{{ $year }}" min="2020">
            </div>
            <div class="col-md-auto ms-auto d-flex align-items-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Tampilkan</button>
            </div>
        </form>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($rows->isEmpty())
    <div class="alert alert-secondary">Pilih toko untuk melihat rekonsiliasi cone &amp; cup.</div>
@else
<form method="POST" action="{{ route('sales.hpp.cone-cup.save') }}">
    @csrf
    <input type="hidden" name="store_id" value="{{ $storeId }}">
    <input type="hidden" name="month" value="{{ $month }}">
    <input type="hidden" name="year" value="{{ $year }}">

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 cone-cup-table" style="table-layout:fixed;width:100%">
                    {{-- table-layout:fixed butuh lebar eksplisit tiap kolom (kolom tanpa
                         lebar akan kolaps ke 0). Total sengaja > 100% — tabel dibungkus
                         .table-responsive jadi kelebihannya digulir horizontal, bukan
                         memepetkan kolom Bahan sampai tak terbaca. --}}
                    <colgroup>
                        <col style="width:14%"><col style="width:13%"><col style="width:13%">
                        <col style="width:13%"><col style="width:13%"><col style="width:13%">
                        <col style="width:13%"><col style="width:22%">
                    </colgroup>
                    <thead class="table-light">
                        <tr>
                            <th>Bahan</th>
                            <th class="text-end">Terjual</th>
                            <th class="text-end">Terpakai</th>
                            <th class="text-end">Selisih</th>
                            <th class="text-end">Rusak <small class="text-muted">(waste)</small></th>
                            <th class="text-end">Overfill <small class="text-muted">(manual)</small></th>
                            <th class="text-end">Tidak ada penjelasan</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $r)
                        <tr>
                            <td class="fw-semibold">{{ $r->ingredient->name }}</td>
                            <td class="text-end">{{ $n($r->terjual) }}</td>
                            <td class="text-end">{{ $n($r->terpakai) }}</td>
                            {{-- + hijau = hemat (terpakai < terjual), - merah = boros --}}
                            <td class="text-end fw-semibold {{ $r->selisih > 0 ? 'text-success' : ($r->selisih < 0 ? 'text-danger' : '') }}">
                                {{ $r->selisih > 0 ? '+' : '' }}{{ $n($r->selisih) }}
                            </td>
                            <td>
                                {{-- Rusak bisa dikoreksi manual di sini: catatan waste ikut
                                     terkunci begitu opname di-approve, padahal selisih cup/cone
                                     sering baru ketahuan setelahnya. Stok TIDAK tersentuh. --}}
                                <input type="number" min="0" step="1"
                                       name="rusak[{{ $r->ingredient->id }}]"
                                       value="{{ $r->rusak ? (int)$r->rusak : '' }}"
                                       class="form-control form-control-sm text-end {{ $r->is_override ? 'border-warning' : '' }}"
                                       placeholder="0"
                                       title="{{ $r->is_override
                                            ? 'Dikoreksi manual. Angka dari catatan waste: '.$n($r->rusak_waste)
                                            : 'Otomatis dari catatan waste. Boleh diketik ulang bila perlu koreksi.' }}">
                                <input type="hidden" name="rusak_base[{{ $r->ingredient->id }}]" value="{{ $r->rusak_waste }}">
                                @if($r->is_override)
                                    <div class="text-warning" style="font-size:.62rem;line-height:1.2">
                                        <i class="bi bi-pencil-fill"></i> koreksi (waste: {{ $n($r->rusak_waste) }})
                                    </div>
                                @endif
                            </td>
                            <td>
                                <input type="number" min="0" step="1"
                                       name="overfill[{{ $r->ingredient->id }}]"
                                       value="{{ $r->overfill ? (int)$r->overfill : '' }}"
                                       class="form-control form-control-sm text-end" placeholder="0">
                            </td>
                            <td class="text-end fw-semibold {{ $r->unexplained > 0 ? 'text-success' : ($r->unexplained < 0 ? 'text-danger' : '') }}">
                                {{ $r->unexplained > 0 ? '+' : '' }}{{ $n($r->unexplained) }}
                            </td>
                            <td>
                                {{-- Isian bebas — mis. alasan selisih, kejadian di toko, dsb. Tidak ikut dihitung. --}}
                                <input type="text" maxlength="255"
                                       name="catatan[{{ $r->ingredient->id }}]"
                                       value="{{ $r->catatan }}"
                                       class="form-control form-control-sm"
                                       placeholder="Opsional">
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Tidak ada penjelasan</strong> = Selisih yang belum tertutup Rusak &amp; Overfill. Rusak dan Overfill selalu mengecilkan selisih menuju nol; nol berarti sudah terjelaskan sepenuhnya.
                Isi Rusak / Overfill lalu klik Simpan untuk menghitung ulang.
                <br>
                Kolom <strong>Rusak</strong> otomatis dari catatan waste — boleh diketik ulang untuk koreksi
                (hanya mengubah laporan ini, tidak mengubah stok). Kosongkan agar kembali mengikuti catatan waste.
            </small>
            <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan</button>
        </div>
    </div>
</form>
@endif
@endsection
