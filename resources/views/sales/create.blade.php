@extends('layouts.app')
@section('title','Input Penjualan Bulanan')
@section('content')

<div class="page-header d-flex justify-content-between align-items-center">
    <h4 class="page-title">Input Penjualan Bulanan</h4>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-success" id="btnDownloadTemplate" onclick="downloadTemplate()">
            <i class="bi bi-file-earmark-excel me-1"></i>Download Template
        </button>
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalImportSales">
            <i class="bi bi-upload me-1"></i>Import Excel
        </button>
        <a href="{{ route('sales.monthly.index') }}" class="btn btn-outline-secondary btn-sm btn-back">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

<form method="POST" action="{{ route('sales.monthly.store') }}">
    @csrf

    @if(session('error'))
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-lock-fill fs-5 mt-1"></i>
            <div class="flex-fill">
                <strong>Data Terkunci</strong>
                <div class="small mt-1">{{ session('error') }}</div>
            </div>
        </div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- Periode + Omset --}}
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-shop me-1"></i>Informasi Periode</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Toko <span class="text-danger">*</span></label>
                    <select name="store_id" class="form-select" required>
                        <option value="">— Pilih Toko —</option>
                        @foreach($stores as $s)
                            <option value="{{ $s->id }}" {{ old('store_id')==$s->id ? 'selected':'' }}>
                                {{ $s->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Bulan <span class="text-danger">*</span></label>
                    <select name="month" class="form-select" required>
                        @foreach(['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'] as $i => $nm)
                            <option value="{{ $i+1 }}" {{ old('month', date('n'))==$i+1 ? 'selected':'' }}>{{ $nm }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Tahun <span class="text-danger">*</span></label>
                    <input type="number" name="year" class="form-control"
                           value="{{ old('year', date('Y')) }}" min="2020" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Periode <span class="text-danger">*</span></label>
                    <select name="period_type" class="form-select" required>
                        <option value="end_month" {{ old('period_type','end_month')==='end_month'?'selected':'' }}>
                            Akhir Bulan (Tgl 1–30/31)
                        </option>
                        <option value="mid_month" {{ old('period_type')==='mid_month'?'selected':'' }}>
                            Tengah Bulan (Tgl 1–15)
                        </option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Total Omset Periode Ini <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="text" name="total_revenue" id="total_revenue"
                               class="form-control text-end num-fmt"
                               value="{{ old('total_revenue', '') }}"
                               placeholder="0" required>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Menu per qty --}}
    <div class="card">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ol me-1"></i>Qty Terjual per Menu</span>
            <span class="text-muted small fw-normal">Kosongkan baris yang tidak terjual</span>
        </div>
        <div class="card-body p-0">
            @php $half = (int) ceil($menus->count() / 2); @endphp
            <div class="row g-0">
                @foreach([$menus->take($half), $menus->slice($half)] as $col => $chunk)
                <div class="col-md-6 {{ $col === 0 ? 'border-end' : '' }}">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Menu</th>
                                <th class="text-center border-start" style="width:130px">Qty (pcs)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($chunk as $i => $menu)
                            @php
                                // Diatur lewat Master Data → Menu ("Hitung ke Total Menu Terjual").
                                // Qty tetap bisa diisi & disimpan, hanya tidak ikut dijumlah.
                                $skipTotal = !($menu->count_in_total ?? true);
                            @endphp
                            <tr>
                                <td class="fw-semibold">
                                    <input type="hidden" name="items[{{ $i }}][menu_id]" value="{{ $menu->id }}">
                                    {{ $menu->name }}
                                </td>
                                <td class="border-start">
                                    <input type="text"
                                           name="items[{{ $i }}][total_sold]"
                                           class="form-control form-control-sm text-center num-fmt"
                                           value="{{ old("items.{$i}.total_sold", '') }}"
                                           @if($skipTotal) data-skip-total="1" @endif
                                           placeholder="">
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endforeach
            </div>
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top bg-light fw-bold">
                <span>TOTAL TERJUAL</span>
                <span id="total-qty">—</span>
            </div>
        </div>
        <div class="card-footer text-end">
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-save me-1"></i>Simpan
            </button>
        </div>
    </div>
</form>

{{-- Modal Import --}}
<div class="modal fade" id="modalImportSales" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form method="POST" action="{{ route('sales.monthly.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="bi bi-upload me-2"></i>Import Penjualan Menu</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @if($errors->any())
                        <div class="alert alert-danger py-2 small">
                            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                        </div>
                    @endif
                    <p class="text-muted small mb-2">Upload file template yang sudah diisi.</p>
                    <input type="file" name="file" class="form-control form-control-sm" accept=".xlsx,.xls" required>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-upload me-1"></i>Import
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
function downloadTemplate() {
    var storeId    = document.querySelector('select[name="store_id"]').value;
    var month      = document.querySelector('select[name="month"]').value;
    var year       = document.querySelector('input[name="year"]').value;
    var periodType = document.querySelector('select[name="period_type"]').value;

    if (!storeId) { alert('Pilih toko terlebih dahulu.'); return; }
    if (!month)   { alert('Pilih bulan terlebih dahulu.'); return; }
    if (!year)    { alert('Isi tahun terlebih dahulu.'); return; }

    var url = '{{ route('sales.monthly.template') }}'
        + '?store_id=' + encodeURIComponent(storeId)
        + '&month='    + encodeURIComponent(month)
        + '&year='     + encodeURIComponent(year)
        + '&period_type=' + encodeURIComponent(periodType);

    window.location.href = url;
}

@if($errors->has('file'))
var modalImportSales = new bootstrap.Modal(document.getElementById('modalImportSales'));
modalImportSales.show();
@endif

function updateTotals() {
    var total = 0;
    document.querySelectorAll('input[name$="[total_sold]"]').forEach(function(el) {
        if (el.dataset.skipTotal) return;   // add on & packaging cup tidak dihitung
        total += parseInt(el.value) || 0;
    });
    document.getElementById('total-qty').textContent = total > 0 ? total.toLocaleString('id-ID') : '—';
}

document.querySelectorAll('input[name$="[total_sold]"]').forEach(function(el) {
    el.addEventListener('input', updateTotals);
});
</script>
@endpush
