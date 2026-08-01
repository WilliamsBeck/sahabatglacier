@extends('layouts.app')
@section('title', 'Ringkasan Bisnis')

@section('content')
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0">Ringkasan Bisnis</h4>
        <p class="text-muted mb-0 small">Omset · HPP · Waste · Produksi — semua toko dalam satu tampilan</p>
    </div>
</div>

{{-- FILTER --}}
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('reports.ringkasan') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Bulan</label>
                <select name="month" class="form-select form-select-sm">
                    @foreach(range(1,12) as $m)
                        <option value="{{ $m }}" {{ $month == $m ? 'selected' : '' }}>
                            {{ \Carbon\Carbon::create(null, $m)->isoFormat('MMMM') }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold small">Tahun</label>
                <select name="year" class="form-select form-select-sm">
                    @foreach(range(now()->year - 2, now()->year) as $y)
                        <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Periode</label>
                <select name="period_type" class="form-select form-select-sm">
                    <option value="end_month" {{ $periodType === 'end_month' ? 'selected' : '' }}>Akhir Bulan</option>
                    <option value="mid_month" {{ $periodType === 'mid_month' ? 'selected' : '' }}>Tengah Bulan</option>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2 justify-content-end align-items-end">
                <button type="submit" class="btn btn-primary btn-laporan">
                    <i class="bi bi-search me-1"></i>Tampilkan
                </button>
                <a href="{{ route('reports.ringkasan.export', request()->query()) }}"
                   class="btn btn-success btn-laporan">
                    <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                </a>
            </div>
        </form>
    </div>
</div>

@php
$hasAnyData  = $rows->where('has_data', true)->isNotEmpty();
$totalOmset  = $rows->whereNotNull('omset')->sum('omset');
$totalWaste  = $rows->sum('total_waste');
$totalProd   = $rows->sum('prod_cost');
$totalBatch  = $rows->sum('prod_batch');
$totalHppAktual = $rows->whereNotNull('hpp_aktual')->sum('hpp_aktual');
$totalHppIdeal = $rows->whereNotNull('hpp_ideal')->sum('hpp_ideal');
// % menyeluruh dihitung dari TOTAL (bukan rata-rata kasar) → lebih akurat.
$avgHppPct       = $totalOmset > 0 ? $totalHppIdeal  / $totalOmset * 100 : null;
$avgHppAktualPct = $totalOmset > 0 ? $totalHppAktual / $totalOmset * 100 : null;
$totalMargin     = $totalOmset > 0 ? (1 - $totalHppIdeal / $totalOmset) * 100 : null;
@endphp

{{-- STAT CARDS --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card border-success">
            <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-cash-stack fs-3"></i></div>
            <div class="stat-info">
                <div class="stat-number text-success" style="font-size:14px">Rp {{ number_format($totalOmset, 0, ',', '.') }}</div>
                <div class="stat-label">Total Omset (semua toko)</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card border-primary">
            <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-percent fs-3"></i></div>
            <div class="stat-info">
                <div class="stat-number text-primary">{{ $avgHppPct ? number_format($avgHppPct, 1, ',', '.') . '%' : '-' }}</div>
                <div class="stat-label">Rata-rata % HPP Ideal</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card border-warning">
            <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-percent fs-3"></i></div>
            <div class="stat-info">
                <div class="stat-number text-warning">{{ $avgHppAktualPct ? number_format($avgHppAktualPct, 1, ',', '.') . '%' : '-' }}</div>
                <div class="stat-label">Rata-rata % HPP Aktual</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card border-danger">
            <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-trash3 fs-3"></i></div>
            <div class="stat-info">
                <div class="stat-number text-danger" style="font-size:14px">Rp {{ number_format($totalWaste, 0, ',', '.') }}</div>
                <div class="stat-label">Total Kerugian Waste</div>
            </div>
        </div>
    </div>
</div>

{{-- TABEL RINGKASAN PER TOKO --}}
<div class="card mb-4">
    <div class="card-header fw-semibold">
        <i class="bi bi-table me-1"></i>
        Detail per Toko —
        {{ \Carbon\Carbon::create($year, $month)->isoFormat('MMMM Y') }}
        ({{ $periodType === 'mid_month' ? 'Tengah Bulan' : 'Akhir Bulan' }})
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-index table-balanced mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="col-name" style="width:13%">Toko</th>
                        <th class="text-end">Omset</th>
                        <th class="text-end">HPP Ideal</th>
                        <th class="text-end">% HPP Ideal</th>
                        <th class="text-end">HPP Aktual</th>
                        <th class="text-end">% HPP Aktual</th>
                        <th class="text-end">Waste</th>
                        <th class="text-end">Biaya Produksi</th>
                        <th class="text-end">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                    @php
                        $pctI = $row->pct_hpp_ideal;
                        $pctA = $row->pct_hpp_aktual;
                        $hppColor = fn($pct) => $pct === null ? 'secondary' : ($pct <= 30 ? 'success' : ($pct <= 35 ? 'warning' : 'danger'));
                    @endphp
                    <tr>
                        <td class="col-name fw-semibold">{{ $row->store->name }}</td>
                        <td class="text-end">
                            @if($row->omset)
                                <span class="fw-semibold">Rp {{ number_format($row->omset, 0, ',', '.') }}</span>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            {{ $row->hpp_ideal ? 'Rp ' . number_format($row->hpp_ideal, 0, ',', '.') : '—' }}
                        </td>
                        <td class="text-end">
                            @if($pctI)
                                <span class="fw-semibold">{{ number_format($pctI, 1, ',', '.') }}%</span>
                            @else <span class="text-muted">—</span> @endif
                        </td>
                        <td class="text-end">
                            @if($row->hpp_aktual !== null)
                                Rp {{ number_format($row->hpp_aktual, 0, ',', '.') }}
                            @else <span class="text-muted small">Belum ada opname</span> @endif
                        </td>
                        <td class="text-end">
                            @if($pctA)
                                @php $aktualColor = $pctI === null ? 'secondary' : ($pctA > $pctI ? 'danger' : 'success'); @endphp
                                <span class="badge bg-{{ $aktualColor }}">{{ number_format($pctA, 1, ',', '.') }}%</span>
                            @else <span class="text-muted">—</span> @endif
                        </td>
                        <td class="text-end {{ $row->total_waste > 0 ? 'text-danger' : 'text-muted' }}">
                            {{ $row->total_waste > 0 ? 'Rp ' . number_format($row->total_waste, 0, ',', '.') : '—' }}
                        </td>
                        <td class="text-end">
                            {{ $row->prod_cost > 0 ? 'Rp ' . number_format($row->prod_cost, 0, ',', '.') : '—' }}
                        </td>
                        <td class="text-end">
                            @if($row->margin_ideal !== null)
                                <span class="fw-semibold {{ $row->margin_ideal >= 60 ? 'text-success' : ($row->margin_ideal >= 50 ? 'text-warning' : 'text-danger') }}">
                                    {{ number_format($row->margin_ideal, 1, ',', '.') }}%
                                </span>
                            @else <span class="text-muted">—</span> @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                @if($hasAnyData)
                <tfoot class="table-secondary fw-semibold">
                    <tr>
                        <td class="col-name">TOTAL</td>
                        <td class="text-end">Rp {{ number_format($totalOmset, 0, ',', '.') }}</td>
                        <td class="text-end">Rp {{ number_format($totalHppIdeal, 0, ',', '.') }}</td>
                        <td class="text-end">{{ $avgHppPct !== null ? number_format($avgHppPct, 1, ',', '.') . '%' : '—' }}</td>
                        <td class="text-end">Rp {{ number_format($totalHppAktual, 0, ',', '.') }}</td>
                        <td class="text-end">
                            @if($avgHppAktualPct !== null)
                                @php $totAktualColor = $avgHppPct === null ? 'secondary' : ($avgHppAktualPct > $avgHppPct ? 'danger' : 'success'); @endphp
                                <span class="badge bg-{{ $totAktualColor }}">{{ number_format($avgHppAktualPct, 1, ',', '.') }}%</span>
                            @else — @endif
                        </td>
                        <td class="text-end text-danger">Rp {{ number_format($totalWaste, 0, ',', '.') }}</td>
                        <td class="text-end">Rp {{ number_format($totalProd, 0, ',', '.') }}</td>
                        <td class="text-end">{{ $totalMargin !== null ? number_format($totalMargin, 1, ',', '.') . '%' : '—' }}</td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
        @if(!$hasAnyData)
        <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
            Tidak ada data penjualan untuk periode ini
        </div>
        @endif
    </div>
</div>

{{-- TREND CHART --}}
<div class="card">
    <div class="card-header fw-semibold">
        <i class="bi bi-graph-up me-1"></i>Tren 6 Bulan Terakhir - Total Omset (semua toko)
    </div>
    <div class="card-body">
        <canvas id="trendChart" style="max-height:280px"></canvas>
    </div>
</div>

@endsection

@push('scripts')
<script src="{{ asset('js/chart.umd.min.js') }}"></script>
<script>
@php
$tLabels = $trendMonths->pluck('label')->values()->all();
$tOmset  = $trendMonths->pluck('omset')->values()->all();
@endphp
new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: @json($tLabels),
        datasets: [
            {
                label: 'Total Omset',
                data: @json($tOmset),
                backgroundColor: 'rgba(99,102,241,.7)',
                borderRadius: 6,
                yAxisID: 'y',
            },
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },   // hanya satu deret, legenda tidak perlu
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: v => 'Rp ' + new Intl.NumberFormat('id-ID').format(v)
                }
            }
        }
    }
});
</script>
@endpush
