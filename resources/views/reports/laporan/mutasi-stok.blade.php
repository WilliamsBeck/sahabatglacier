@extends('layouts.app')
@section('title', 'Laporan Mutasi Stok')

@section('content')
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0">Laporan Mutasi Stok</h4>
        <p class="text-muted mb-0 small">Rekap pembelian & penerimaan stok (PI, Zhisheng, Supplier)</p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <a href="{{ route('reports.laporan.mutasi-stok.export', request()->query()) }}" class="btn btn-outline-success">
            <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
        </a>
        <a href="{{ route('reports.laporan.index') }}" class="btn btn-outline-secondary btn-sm btn-back">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

{{-- FILTER --}}
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('reports.laporan.mutasi-stok') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Toko</label>
                <select name="store_id" class="form-select form-select-sm">
                    @foreach($stores as $s)
                        <option value="{{ $s->id }}" {{ $storeId == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold small">Dari Tanggal</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="{{ $dateFrom }}">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold small">Sampai Tanggal</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="{{ $dateTo }}">
            </div>
            @php
                $tipeOptions = [
                    'internal_in'         => 'Internal Masuk',
                    'internal_out'        => 'Internal Keluar',
                    'eksternal'           => 'Pembelian Eksternal',
                    'penjualan_eksternal' => 'Penjualan Eksternal',
                    'zhisheng'            => 'Zhisheng',
                    'supplier'            => 'Supplier',
                ];
                $tipeSel = (array) $tipe;   // [] = semua
            @endphp
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Tipe Mutasi</label>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary w-100 d-flex justify-content-between align-items-center"
                            type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                        <span id="tipeLabel">
                            @if(empty($tipeSel))
                                Semua
                            @elseif(count($tipeSel) === 1)
                                {{ $tipeOptions[$tipeSel[0]] ?? $tipeSel[0] }}
                            @else
                                {{ count($tipeSel) }} tipe dipilih
                            @endif
                        </span>
                        <i class="bi bi-chevron-down ms-1" style="font-size:.7rem"></i>
                    </button>
                    <div class="dropdown-menu p-2 shadow-sm" style="min-width:230px">
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" id="tipeAll"
                                   {{ empty($tipeSel) ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="tipeAll">Semua</label>
                        </div>
                        <hr class="my-1">
                        @foreach($tipeOptions as $val => $lbl)
                            <div class="form-check">
                                <input class="form-check-input tipe-cb" type="checkbox" name="tipe[]"
                                       value="{{ $val }}" id="tipe-{{ $val }}"
                                       {{ in_array($val, $tipeSel, true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="tipe-{{ $val }}">{{ $lbl }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="col-md-3 d-flex gap-2 justify-content-end">
                <button type="submit" class="btn btn-primary btn-laporan">
                    <i class="bi bi-search me-1"></i>Tampilkan
                </button>
            </div>
        </form>
    </div>
</div>

@if($storeId)

@php
$tabs = [
    'semua'        => ['label' => 'Semua'],
    'internal_in'  => ['label' => 'Internal Masuk'],
    'internal_out' => ['label' => 'Internal Keluar'],
    'eksternal'    => ['label' => 'Eksternal'],
    'penjualan_eksternal' => ['label' => 'Penjualan Eksternal'],
    'zhisheng'     => ['label' => 'Zhisheng'],
    'supplier'     => ['label' => 'Supplier'],
];
@endphp

{{-- SUMMARY CARDS --}}
@php
$totalItems = $rows->flatMap->items->count();
$byType     = $rows->groupBy('type')->map->count();
@endphp
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card border-primary">
            <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-receipt fs-3"></i></div>
            <div class="stat-info">
                <div class="stat-number text-primary">{{ $rows->count() }}</div>
                <div class="stat-label">Total Transaksi</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card border-info">
            <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-box-seam fs-3"></i></div>
            <div class="stat-info">
                <div class="stat-number text-info">{{ $totalItems }}</div>
                <div class="stat-label">Total Item</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card border-success">
            <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-cash-stack fs-3"></i></div>
            <div class="stat-info">
                <div class="stat-number text-success" style="font-size:14px">Rp {{ number_format($grandTotal, 0, ',', '.') }}</div>
                <div class="stat-label">Total Nilai Mutasi</div>
            </div>
        </div>
    </div>
</div>

{{-- MAIN TABLE --}}
<div class="card">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-table me-1"></i>
            Detail Mutasi —
            {{ \Carbon\Carbon::parse($dateFrom)->isoFormat('D MMM Y') }} s/d {{ \Carbon\Carbon::parse($dateTo)->isoFormat('D MMM Y') }}
        </span>
        @if(!empty($tipeSel))
        <span>
            @foreach($tipeSel as $t)
                <span class="badge bg-primary">{{ $tipeOptions[$t] ?? $t }}</span>
            @endforeach
        </span>
        @endif
    </div>
    <div class="card-body p-0">
        @if($rows->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                Tidak ada data mutasi untuk periode dan filter ini
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-index mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width:8%">Tanggal</th>
                        <th style="width:15%">Tipe</th>
                        <th style="width:15%">No Ref</th>
                        <th style="width:10%">No Invoice</th>
                        <th style="width:15%">Supplier / Sumber</th>
                        <th style="width:15%">Toko Tujuan</th>
                        <th class="text-start" style="width:12%">Nilai</th>
                        <th class="text-center" style="width:6%">Item</th>
                        <th class="text-center" style="width:4%">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $mutation)
                    @php
                        $val = $mutation->items->sum('cost_subtotal');
                        $typeColors = [
                            'purchase_zhisheng' => 'warning',
                            'purchase_supplier' => 'success',
                            'sale_internal'     => 'primary',
                            'sale_external'     => 'info',
                            'opening_stock'     => 'secondary',
                        ];
                        $tc = $typeColors[$mutation->type] ?? 'secondary';
                    @endphp
                    <tr>
                        <td class="text-muted small text-nowrap">
                            {{ \Carbon\Carbon::parse($mutation->delivery_date ?? $mutation->transaction_date)->isoFormat('D MMM Y') }}
                        </td>
                        <td>
                            <span class="badge bg-{{ $tc }}-subtle text-{{ $tc }} border border-{{ $tc }}-subtle">
                                {{ $mutation->type_label ?? str_replace('_', ' ', $mutation->type) }}
                            </span>
                        </td>
                        <td class="col-name small">{{ $mutation->reference_no ?? '-' }}</td>
                        <td class="col-name small">{{ $mutation->invoice_no ?? '-' }}</td>
                        <td class="col-name small">{{ $mutation->supplier?->name ?? $mutation->sourceStore?->name ?? '-' }}</td>
                        <td class="col-name small">{{ $mutation->destinationStore?->name ?? '-' }}</td>
                        <td class="text-start fw-semibold">Rp {{ number_format($val, 0, ',', '.') }}</td>
                        <td class="text-center">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $mutation->items->count() }} item</span>
                        </td>
                        <td class="text-center">
                            @if($mutation->items->isNotEmpty())
                            <button class="btn btn-outline-secondary btn-sm py-0 px-2"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#mut-{{ $mutation->id }}">
                                <i class="bi bi-chevron-down" style="font-size:.7rem"></i>
                            </button>
                            @endif
                        </td>
                    </tr>
                    @if($mutation->items->isNotEmpty())
                    <tr class="collapse" id="mut-{{ $mutation->id }}">
                        <td colspan="9" class="p-0">
                            <div class="bg-light px-4 py-2">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr class="text-muted small">
                                            <th>Bahan</th>
                                            <th class="text-end">Qty (Dus / Pack / Pcs/Gr)</th>
                                            <th class="text-end">Harga/Dus</th>
                                            <th class="text-end">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($mutation->items as $item)
                                        @php
                                            $pkg  = $item->packaging;
                                            $ctb  = $pkg ? (float)$pkg->crate_to_pack * (float)$pkg->pack_to_base : 0;
                                            $unit = $item->ingredient?->unit_base ?? '';
                                            $parts = [];
                                            if ((int)$item->qty_crate)  $parts[] = (int)$item->qty_crate.' Dus';
                                            if ((int)$item->qty_pack)   $parts[] = (int)$item->qty_pack.' Pack';
                                            if ((float)$item->qty_base)  $parts[] = rtrim(rtrim(number_format($item->qty_base, 2, ',', '.'), '0'), ',').' '.$unit;
                                            $qtyStr   = $parts ? implode(' · ', $parts) : '—';
                                            $hargaDus = $ctb > 0 ? round($item->price_per_base * $ctb) : $item->price_per_base;
                                        @endphp
                                        <tr class="small">
                                            <td>{{ $item->ingredient?->name ?? '-' }}
                                                <span class="text-muted">({{ $unit }})</span>
                                            </td>
                                            <td class="text-end">{{ $qtyStr }}</td>
                                            <td class="text-end">Rp {{ number_format($hargaDus, 0, ',', '.') }}</td>
                                            <td class="text-end fw-semibold">Rp {{ number_format($item->cost_subtotal, 0, ',', '.') }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
                <tfoot class="table-primary fw-semibold">
                    <tr>
                        <td colspan="6">TOTAL ({{ $rows->count() }} transaksi)</td>
                        <td class="text-start">Rp {{ number_format($grandTotal, 0, ',', '.') }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @endif
    </div>
</div>

@else
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i> Pilih toko terlebih dahulu untuk melihat laporan.
</div>
@endif

@push('scripts')
<script>
(function () {
    var all = document.getElementById('tipeAll');
    var cbs = Array.prototype.slice.call(document.querySelectorAll('.tipe-cb'));
    if (!all || !cbs.length) return;

    // "Semua" dicentang → kosongkan pilihan spesifik (server: [] = semua)
    all.addEventListener('change', function () {
        if (all.checked) cbs.forEach(function (c) { c.checked = false; });
    });
    // Memilih tipe tertentu → "Semua" otomatis lepas.
    // Kalau tidak ada satupun yang dipilih, kembali ke "Semua".
    cbs.forEach(function (c) {
        c.addEventListener('change', function () {
            var any = cbs.some(function (x) { return x.checked; });
            all.checked = !any;
        });
    });
})();
</script>
@endpush
@endsection
