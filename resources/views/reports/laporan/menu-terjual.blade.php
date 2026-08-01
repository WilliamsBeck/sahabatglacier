@extends('layouts.app')
@section('title', 'Laporan Menu Terjual')

@section('content')
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0">Laporan Menu Terjual</h4>
        <p class="text-muted mb-0 small">Rekap penjualan menu per periode</p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <a href="{{ route('reports.laporan.menu-terjual.export', request()->query()) }}" class="btn btn-outline-success">
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
        <form method="GET" action="{{ route('reports.laporan.menu-terjual') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Toko</label>
                <select name="store_id" class="form-select form-select-sm">
                    @foreach($stores as $s)
                        <option value="{{ $s->id }}" {{ $storeId == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
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
            <div class="col-md-2">
                <label class="form-label fw-semibold small">Periode</label>
                <select name="period_type" class="form-select form-select-sm">
                    <option value="end_month" {{ $periodType === 'end_month' ? 'selected' : '' }}>Akhir Bulan</option>
                    <option value="mid_month" {{ $periodType === 'mid_month' ? 'selected' : '' }}>Tengah Bulan</option>
                </select>
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

{{-- SUMMARY CARDS --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card border-primary">
            <div class="stat-icon bg-primary-subtle text-primary">
                <i class="bi bi-cup-straw fs-3"></i>
            </div>
            <div class="stat-info">
                <div class="stat-number text-primary">{{ number_format($totalSold, 0, ',', '.') }}</div>
                <div class="stat-label">Total Menu Terjual</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card border-success">
            <div class="stat-icon bg-success-subtle text-success">
                <i class="bi bi-cash-stack fs-3"></i>
            </div>
            <div class="stat-info">
                <div class="stat-number text-success" style="font-size:15px">
                    Rp {{ number_format($totalRevenue, 0, ',', '.') }}
                </div>
                <div class="stat-label">Total Pendapatan</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card border-info">
            <div class="stat-icon bg-info-subtle text-info">
                <i class="bi bi-tags fs-3"></i>
            </div>
            <div class="stat-info">
                <div class="stat-number text-info">{{ $rows->count() }}</div>
                <div class="stat-label">Varian Menu</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    {{-- TABLE --}}
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-table me-1"></i>
                Detail Menu Terjual —
                {{ \Carbon\Carbon::create($year, $month)->isoFormat('MMMM Y') }}
                ({{ $periodType === 'mid_month' ? 'Tengah Bulan' : 'Akhir Bulan' }})
            </div>
            <div class="card-body p-0">
                @if($rows->isEmpty())
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                        Tidak ada data penjualan untuk periode ini
                    </div>
                @else
                <div class="table-responsive">
                    <table class="table table-index table-balanced mb-0 align-middle">
                        <thead>
                            <tr>
                                <th style="width:6%">No</th>
                                <th class="col-name" style="width:40%">Nama Menu</th>
                                <th class="text-end" style="width:18%">Qty Terjual</th>
                                <th class="text-end" style="width:18%">% Share</th>
                                <th class="text-end" style="width:18%">Pendapatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $no = 0; @endphp
                            @foreach($rows->groupBy(fn($r) => $r->menu?->menuCategory?->name ?? 'Lainnya') as $catName => $catRows)
                                @php
                                    // Qty kategori ditampilkan APA ADANYA supaya bisa dianalisa.
                                    // Yang dikecualikan hanya angka TOTAL keseluruhan di bawah.
                                    $catQty = $catRows->sum('total_sold');
                                    $catRev = $catRows->sum('total_revenue');
                                    $catDihitung = $catRows->contains(fn($r) => $r->menu?->count_in_total ?? true);
                                @endphp
                                <tr class="table-light">
                                    <td colspan="2" class="fw-semibold text-uppercase small" style="letter-spacing:.03em">
                                        <i class="bi bi-tag-fill me-1 text-secondary"></i>{{ $catName }}
                                    </td>
                                    <td class="text-end fw-semibold">{{ number_format($catQty, 0, ',', '.') }}</td>
                                    <td class="text-end fw-semibold text-muted small">
                                        @if($catDihitung && $totalSold > 0)
                                            {{ number_format($catQty / $totalSold * 100, 1, ',', '.') }}%
                                        @else
                                            <span title="Kategori ini tidak dijumlah ke total menu terjual">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end fw-semibold">Rp {{ number_format($catRev, 0, ',', '.') }}</td>
                                </tr>
                                @foreach($catRows as $row)
                                    @php $no++; @endphp
                                    @php $ikutHitung = $row->menu?->count_in_total ?? true; @endphp
                                    <tr>
                                        <td class="text-muted small">{{ $no }}</td>
                                        <td class="col-name fw-semibold ps-4">
                                            {{ $row->menu?->name ?? '-' }}
                                            @unless($ikutHitung)
                                                <span class="badge bg-light text-secondary border ms-1"
                                                      style="font-size:.6rem;font-weight:500"
                                                      title="Tidak dihitung ke total menu terjual (atur di Master Data → Menu)">tidak dihitung</span>
                                            @endunless
                                        </td>
                                        <td class="text-end fw-semibold {{ $ikutHitung ? '' : 'text-muted' }}">{{ number_format($row->total_sold, 0, ',', '.') }}</td>
                                        <td class="text-end text-muted small">
                                            {{ $ikutHitung && $totalSold > 0 ? number_format($row->total_sold / $totalSold * 100, 1, ',', '.') . '%' : '—' }}
                                        </td>
                                        <td class="text-end">Rp {{ number_format($row->total_revenue, 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                        <tfoot class="table-primary fw-semibold">
                            <tr>
                                <td colspan="2">TOTAL</td>
                                <td class="text-end">{{ number_format($totalSold, 0, ',', '.') }}</td>
                                <td class="text-end">100%</td>
                                <td class="text-end">Rp {{ number_format($totalRevenue, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- PIE CHART BY CATEGORY --}}
    <div class="col-lg-4 d-flex flex-column">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-pie-chart me-1"></i>Komposisi per Kategori
            </div>
            <div class="card-body">
                @if($byCategory->isEmpty())
                    <div class="text-center text-muted py-5">Tidak ada data</div>
                @else
                    <div style="position:relative;height:340px">
                        <canvas id="catChart"></canvas>
                    </div>
                @endif
            </div>
        </div>

        {{-- RECAP: MENU TERLARIS --}}
        @if($rows->isNotEmpty())
        <div class="card mt-4 flex-grow-1" style="min-height:240px">
            <div class="card-header fw-semibold">
                <i class="bi bi-trophy me-1"></i>Menu Terlaris
            </div>
            <div class="card-body p-0" style="position:relative">
              <div style="position:absolute; inset:0; overflow-y:auto" class="p-3">
                @foreach($rows->sortByDesc('total_sold')->values() as $i => $row)
                    @php
                        $share = $totalSold > 0 ? $row->total_sold / $totalSold * 100 : 0;
                        $rank  = $loop->iteration;
                    @endphp
                    <div class="d-flex align-items-center gap-3 {{ !$loop->last ? 'mb-3' : '' }}">
                        <span class="badge {{ $rank <= 3 ? 'bg-primary' : 'bg-secondary-subtle text-secondary' }}"
                              style="width:24px">{{ $rank }}</span>
                        <div class="flex-fill" style="min-width:0">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold text-truncate me-3">{{ $row->menu?->name ?? '-' }}</span>
                                <span class="small text-muted text-nowrap">{{ number_format($row->total_sold, 0, ',', '.') }} pcs<span class="ms-4">{{ number_format($share, 1, ',', '.') }}%</span></span>
                            </div>
                            <div class="progress mt-1" style="height:5px">
                                <div class="progress-bar" style="width:{{ $share }}%"></div>
                            </div>
                        </div>
                    </div>
                @endforeach
              </div>
            </div>
        </div>
        @endif
    </div>
</div>

@else
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i> Pilih toko terlebih dahulu untuk melihat laporan.
</div>
@endif
@endsection

@push('scripts')
@if($storeId && $byCategory->isNotEmpty())
<script src="{{ asset('js/chart.umd.min.js') }}"></script>
<script>
@php
$catLabels = $byCategory->keys()->values()->all();
$catData   = $byCategory->values()->all();
$palette   = ['#6366f1','#f59e0b','#10b981','#ef4444','#3b82f6','#8b5cf6','#ec4899','#14b8a6','#f97316','#84cc16'];
@endphp
const catData = @json($catData);
const catTotal = catData.reduce((a, b) => a + b, 0);
const fmt = n => n.toLocaleString('id-ID');
const pct = v => catTotal > 0 ? (v / catTotal * 100).toFixed(1).replace('.', ',') : '0';

new Chart(document.getElementById('catChart'), {
    type: 'doughnut',
    data: {
        labels: @json($catLabels),
        datasets: [{ data: catData, backgroundColor: @json($palette), borderWidth: 2 }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '55%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    font: { size: 11 },
                    boxWidth: 12,
                    padding: 10,
                    generateLabels(chart) {
                        const ds = chart.data.datasets[0];
                        return chart.data.labels.map((label, i) => ({
                            text: `${label} — ${fmt(ds.data[i])} (${pct(ds.data[i])}%)`,
                            fillStyle: ds.backgroundColor[i],
                            strokeStyle: ds.backgroundColor[i],
                            index: i
                        }));
                    }
                }
            },
            tooltip: {
                callbacks: {
                    label: ctx => ` ${fmt(ctx.parsed)} terjual (${pct(ctx.parsed)}%)`
                }
            }
        }
    }
});
</script>
@endif
@endpush
