@extends('layouts.app')
@section('title', 'Perbandingan HPP')
@section('content')
@include('sales._hpp_tabs', ['currentHppTab' => 'compare'])

@php
$monthNames = ['','Januari','Februari','Maret','April','Mei','Juni','Juli',
               'Agustus','September','Oktober','November','Desember'];
$rp  = fn($v) => 'Rp ' . number_format($v, 0, ',', '.');
$pct = fn($v) => $v !== null ? number_format($v, 1, ',', '.') . '%' : '—';

// Default chart variables (overridden below when compareData is available)
$chartLabels    = [];
$chartOmset     = [];
$chartHppIdeal  = [];
$chartHppAktual = [];
$chartPctIdeal  = [];
$chartPctAktual = [];
$chartSelisih   = [];
$hasAnyAktual   = false;
@endphp

<p class="text-muted small mb-3">
    Perbandingan Toko —
    {{ $monthNames[$month] }} {{ $year }} ·
    {{ $periodType === 'mid_month' ? 'Tengah Bulan (1–15)' : 'Akhir Bulan (1–31)' }}
</p>

{{-- ── Filter ──────────────────────────────────────────────────────────── --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('sales.hpp.compare') }}" id="compareForm">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Bulan</label>
                    <select name="month" class="form-select form-select-sm">
                        @foreach($monthNames as $i => $nm)
                            @if($i > 0)
                            <option value="{{ $i }}" {{ $month == $i ? 'selected' : '' }}>{{ $nm }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small fw-semibold mb-1">Tahun</label>
                    <input type="number" name="year" class="form-control form-control-sm"
                           value="{{ $year }}" min="2020">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Periode</label>
                    <select name="period_type" class="form-select form-select-sm">
                        <option value="end_month" {{ $periodType === 'end_month' ? 'selected' : '' }}>
                            Akhir Bulan (1–30/31)
                        </option>
                        <option value="mid_month" {{ $periodType === 'mid_month' ? 'selected' : '' }}>
                            Tengah Bulan (1–15)
                        </option>
                    </select>
                </div>
                <div class="col-md-5">
                    <x-multi-check-dropdown
                        name="store_ids[]"
                        label="Pilih Toko yang Dibandingkan"
                        placeholder="Pilih toko… (bisa lebih dari satu)"
                        :options="$stores->pluck('name', 'id')->all()"
                        :selected="$selectedIds" />
                </div>
                {{-- Pola tombol disamakan dgn tab HPP lain: paling kanan, rata bawah, ukuran normal --}}
                <div class="col-md-auto ms-md-auto d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-bar-chart-line me-1"></i>Bandingkan
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

@if(empty($selectedIds))
<div class="text-center py-5 text-muted">
    <i class="bi bi-bar-chart-line fs-1 d-block mb-2 opacity-25"></i>
    Pilih minimal 2 toko untuk membandingkan HPP.
</div>
@elseif(count($compareData) === 0)
<div class="alert alert-warning">Tidak ada data untuk filter yang dipilih.</div>
@else

@php
    // Siapkan data untuk chart
    $chartLabels    = collect($compareData)->map(fn($d) => $d->store->name)->toArray();
    $chartOmset     = collect($compareData)->map(fn($d) => $d->summary ? round($d->summary->omset) : 0)->toArray();
    $chartHppIdeal  = collect($compareData)->map(fn($d) => $d->summary ? round($d->summary->hpp_ideal) : 0)->toArray();
    $chartHppAktual = collect($compareData)->map(fn($d) => $d->summary?->hpp_aktual !== null ? round($d->summary->hpp_aktual) : null)->toArray();
    $chartPctIdeal  = collect($compareData)->map(fn($d) => $d->summary?->pct_hpp_ideal !== null ? round($d->summary->pct_hpp_ideal, 1) : null)->toArray();
    $chartPctAktual = collect($compareData)->map(fn($d) => $d->summary?->pct_hpp_aktual !== null ? round($d->summary->pct_hpp_aktual, 1) : null)->toArray();
    $chartSelisih   = collect($compareData)->map(fn($d) => $d->summary?->selisih_hpp !== null ? round($d->summary->selisih_hpp) : null)->toArray();
    $hasAnyAktual   = collect($chartHppAktual)->contains(fn($v) => $v !== null);
@endphp

{{-- ══════════════════════════════════════════════════════════════════════
     GRAFIK PERBANDINGAN (1 chart + dropdown)
══════════════════════════════════════════════════════════════════════ --}}
<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between gap-2 py-2">
        <span class="fw-semibold"><i class="bi bi-bar-chart me-1"></i>Grafik Perbandingan</span>
        <select id="chartMode" class="form-select form-select-sm" style="max-width:260px">
            <option value="hpp_ideal">HPP Ideal</option>
            <option value="hpp_aktual">HPP Aktual</option>
            <option value="selisih">Selisih HPP (Aktual − Ideal)</option>
            <option value="pct_ideal">% HPP Ideal</option>
            <option value="pct_aktual">% HPP Aktual</option>
            <option value="omset_vs_ideal">Omset vs HPP Ideal</option>
        </select>
    </div>
    <div class="card-body">
        <div id="chartSubtitle" class="text-muted small mb-2"></div>
        <canvas id="mainChart" style="max-height:320px"></canvas>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════
     TABEL PERBANDINGAN RINGKASAN
══════════════════════════════════════════════════════════════════════ --}}
<div class="card mb-3">
    <div class="card-header fw-semibold">
        <i class="bi bi-table me-1"></i>Ringkasan Perbandingan
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0 align-middle" style="font-size:.875rem">
                <thead class="table-dark">
                    <tr>
                        <th style="min-width:160px">Metrik</th>
                        @foreach($compareData as $d)
                        <th class="text-center" style="min-width:150px">
                            {{ $d->store->name }}
                        </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>

                    {{-- Omset --}}
                    <tr class="table-light">
                        <td class="fw-semibold text-muted small text-uppercase" style="letter-spacing:.04em">
                            Omset
                        </td>
                        @foreach($compareData as $d)
                        <td class="text-end fw-semibold">
                            @if($d->summary)
                                {{ $rp($d->summary->omset) }}
                            @else
                                <span class="text-muted small">Belum ada data</span>
                            @endif
                        </td>
                        @endforeach
                    </tr>

                    {{-- HPP Ideal --}}
                    <tr>
                        <td class="fw-semibold">HPP Ideal</td>
                        @foreach($compareData as $d)
                        <td class="text-end">
                            @if($d->summary)
                                {{ $rp($d->summary->hpp_ideal) }}
                            @else <span class="text-muted">—</span> @endif
                        </td>
                        @endforeach
                    </tr>

                    {{-- % HPP Ideal --}}
                    <tr>
                        <td class="fw-semibold">
                            % HPP Ideal
                            <i class="bi bi-info-circle text-muted ms-1" style="font-size:.75rem;cursor:help"
                               title="HPP Ideal ÷ Omset × 100%"></i>
                        </td>
                        @php
                            // cari toko dengan % HPP ideal terkecil (paling efisien)
                            $bestPctIdeal = collect($compareData)
                                ->filter(fn($d) => $d->summary?->pct_hpp_ideal !== null)
                                ->min(fn($d) => $d->summary->pct_hpp_ideal);
                        @endphp
                        @foreach($compareData as $d)
                        @php
                            $pctVal = $d->summary?->pct_hpp_ideal;
                            $isBest = $pctVal !== null && $pctVal == $bestPctIdeal && count(array_filter($compareData, fn($x) => $x->summary?->pct_hpp_ideal !== null)) > 1;
                        @endphp
                        <td class="text-end fw-bold">
                            @if($pctVal !== null)
                                <span class="{{ $pctVal <= 30 ? 'text-success' : ($pctVal <= 40 ? 'text-warning' : 'text-danger') }}">
                                    {{ $pct($pctVal) }}
                                </span>
                                @if($isBest)
                                    <span class="badge bg-success ms-1" style="font-size:.65rem">Terbaik</span>
                                @endif
                            @else <span class="text-muted">—</span> @endif
                        </td>
                        @endforeach
                    </tr>

                    {{-- Margin Ideal --}}
                    <tr>
                        <td class="fw-semibold">Margin Ideal</td>
                        @foreach($compareData as $d)
                        <td class="text-end">
                            @if($d->summary?->margin_ideal !== null)
                                <span class="{{ $d->summary->margin_ideal >= 60 ? 'text-success' : ($d->summary->margin_ideal >= 50 ? 'text-warning' : 'text-danger') }}">
                                    {{ $pct($d->summary->margin_ideal) }}
                                </span>
                            @else <span class="text-muted">—</span> @endif
                        </td>
                        @endforeach
                    </tr>

                    {{-- Divider: Aktual --}}
                    <tr class="table-light">
                        <td class="fw-semibold text-muted small text-uppercase" style="letter-spacing:.04em">
                            <i class="bi bi-clipboard-check me-1"></i>Berdasarkan Opname
                        </td>
                        @foreach($compareData as $d)
                        <td class="text-center">
                            @if($d->summary?->has_opname)
                                <span class="badge bg-success-subtle text-success border border-success" style="font-size:.72rem">
                                    <i class="bi bi-check-circle me-1"></i>Ada Opname
                                </span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary border" style="font-size:.72rem">
                                    Belum Ada
                                </span>
                            @endif
                        </td>
                        @endforeach
                    </tr>

                    {{-- HPP Aktual --}}
                    <tr>
                        <td class="fw-semibold">HPP Aktual</td>
                        @foreach($compareData as $d)
                        <td class="text-end">
                            @if($d->summary?->hpp_aktual !== null)
                                {{ $rp($d->summary->hpp_aktual) }}
                            @else <span class="text-muted small">—</span> @endif
                        </td>
                        @endforeach
                    </tr>

                    {{-- % HPP Aktual --}}
                    <tr>
                        <td class="fw-semibold">% HPP Aktual</td>
                        @php
                            $bestPctAktual = collect($compareData)
                                ->filter(fn($d) => $d->summary?->pct_hpp_aktual !== null)
                                ->min(fn($d) => $d->summary->pct_hpp_aktual);
                        @endphp
                        @foreach($compareData as $d)
                        @php
                            $pctA   = $d->summary?->pct_hpp_aktual;
                            $isBest = $pctA !== null && $pctA == $bestPctAktual && count(array_filter($compareData, fn($x) => $x->summary?->pct_hpp_aktual !== null)) > 1;
                        @endphp
                        <td class="text-end fw-bold">
                            @if($pctA !== null)
                                <span class="{{ $pctA <= 30 ? 'text-success' : ($pctA <= 40 ? 'text-warning' : 'text-danger') }}">
                                    {{ $pct($pctA) }}
                                </span>
                                @if($isBest)
                                    <span class="badge bg-success ms-1" style="font-size:.65rem">Terbaik</span>
                                @endif
                            @else <span class="text-muted">—</span> @endif
                        </td>
                        @endforeach
                    </tr>

                    {{-- Margin Aktual --}}
                    <tr>
                        <td class="fw-semibold">Margin Aktual</td>
                        @foreach($compareData as $d)
                        <td class="text-end">
                            @if($d->summary?->margin_aktual !== null)
                                <span class="{{ $d->summary->margin_aktual >= 60 ? 'text-success' : ($d->summary->margin_aktual >= 50 ? 'text-warning' : 'text-danger') }}">
                                    {{ $pct($d->summary->margin_aktual) }}
                                </span>
                            @else <span class="text-muted">—</span> @endif
                        </td>
                        @endforeach
                    </tr>

                    {{-- Selisih HPP --}}
                    <tr class="table-light">
                        <td class="fw-semibold">
                            Selisih HPP
                            <div class="text-muted fw-normal" style="font-size:.72rem">Aktual − Ideal</div>
                        </td>
                        @foreach($compareData as $d)
                        @php $sel = $d->summary?->selisih_hpp; @endphp
                        <td class="text-end fw-bold">
                            @if($sel !== null)
                                <span class="{{ $sel < 0 ? 'text-danger' : ($sel > 0 ? 'text-success' : 'text-muted') }}">
                                    {{ $sel >= 0 ? '+' : '' }}{{ $rp($sel) }}
                                </span>
                                <div style="font-size:.72rem" class="{{ $sel < 0 ? 'text-danger' : ($sel > 0 ? 'text-success' : 'text-muted') }}">
                                    {{ $sel < 0 ? 'Boros' : ($sel > 0 ? 'Efisien' : 'Sesuai') }}
                                </div>
                            @else <span class="text-muted">—</span> @endif
                        </td>
                        @endforeach
                    </tr>

                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════
     PERBANDINGAN PER MENU (accordion)
══════════════════════════════════════════════════════════════════════ --}}
@php
    // Kumpulkan semua menu unik dari semua toko yang dibandingkan
    $allMenus = collect($compareData)
        ->flatMap(fn($d) => $d->menuRows->map(fn($r) => $r->menu))
        ->unique('id')->sortBy('name')->values();
@endphp

@if($allMenus->isNotEmpty())
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">
            <i class="bi bi-cart3 me-1"></i>Perbandingan Per Menu
        </span>
        <button class="btn btn-sm btn-outline-secondary" type="button"
                data-bs-toggle="collapse" data-bs-target="#menuCompare">
            <i class="bi bi-chevron-down"></i>
        </button>
    </div>
    <div class="collapse show" id="menuCompare">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:.85rem">
                    <thead class="table-dark">
                        <tr>
                            <th style="min-width:160px">Menu</th>
                            @foreach($compareData as $d)
                            <th class="text-center" colspan="2">
                                {{ $d->store->name }}
                            </th>
                            @endforeach
                        </tr>
                        <tr class="table-secondary" style="font-size:.75rem">
                            <th></th>
                            @foreach($compareData as $d)
                            <th class="text-end">Qty Terjual</th>
                            <th class="text-end">HPP Ideal</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($allMenus as $menu)
                        <tr>
                            <td class="fw-semibold">{{ $menu->name }}</td>
                            @foreach($compareData as $d)
                            @php
                                $row = $d->menuRows->firstWhere('menu.id', $menu->id);
                            @endphp
                            @if($row)
                            <td class="text-end">{{ number_format($row->total_sold, 0, ',', '.') }}</td>
                            <td class="text-end">{{ $rp($row->hpp_ideal) }}</td>
                            @else
                            <td class="text-center text-muted small" colspan="2">—</td>
                            @endif
                            @endforeach
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td>TOTAL</td>
                            @foreach($compareData as $d)
                            <td class="text-end">{{ number_format($d->menuRows->sum('total_sold'), 0, ',', '.') }}</td>
                            <td class="text-end">{{ $rp($d->menuRows->sum('hpp_ideal')) }}</td>
                            @endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

@endif

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const LABELS     = @json($chartLabels);
const HPP_IDEAL  = @json($chartHppIdeal);
const HPP_AKTUAL = @json($chartHppAktual);
const PCT_IDEAL  = @json($chartPctIdeal);
const PCT_AKTUAL = @json($chartPctAktual);
const SELISIH    = @json($chartSelisih);
const OMSET      = @json($chartOmset);

const COLORS = ['#4f6ef7','#22c55e','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#f97316','#84cc16'];
const hex2a  = (hex, a) => hex + Math.round(a * 255).toString(16).padStart(2, '0');

const rpFmt  = v => v !== null ? 'Rp ' + new Intl.NumberFormat('id-ID').format(v) : '—';
const pctFmt = v => v !== null ? v.toFixed(1).replace('.', ',') + '%' : '—';

Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
Chart.defaults.font.size   = 12;

// ── Konfigurasi tiap mode ─────────────────────────────────────────────────
const MODES = {
    hpp_ideal: {
        subtitle: 'Total biaya bahan baku berdasarkan resep × qty terjual',
        build: () => ({
            datasets: [{
                label: 'HPP Ideal',
                data: HPP_IDEAL,
                backgroundColor: LABELS.map((_, i) => hex2a(COLORS[i % COLORS.length], .75)),
                borderColor:     LABELS.map((_, i) => COLORS[i % COLORS.length]),
                borderWidth: 2, borderRadius: 6,
            }],
            yFmt:   v => 'Rp ' + (v/1e6).toFixed(1).replace('.', ',') + 'jt',
            tipFmt: (label, v) => label + ': ' + rpFmt(v),
            legend: false,
        }),
    },
    hpp_aktual: {
        subtitle: 'HPP aktual dihitung dari: Stok Awal + Pembelian − Stok Akhir (opname)',
        build: () => ({
            datasets: [{
                label: 'HPP Aktual',
                data: HPP_AKTUAL,
                backgroundColor: LABELS.map((_, i) => hex2a(COLORS[i % COLORS.length], .75)),
                borderColor:     LABELS.map((_, i) => COLORS[i % COLORS.length]),
                borderWidth: 2, borderRadius: 6,
            }],
            yFmt:   v => 'Rp ' + (v/1e6).toFixed(1).replace('.', ',') + 'jt',
            tipFmt: (label, v) => v !== null ? label + ': ' + rpFmt(v) : label + ': Belum ada opname',
            legend: false,
        }),
    },
    selisih: {
        subtitle: 'Positif (+) = efisien (aktual < ideal) · Negatif (−) = boros (aktual > ideal)',
        build: () => ({
            datasets: [{
                label: 'Selisih HPP',
                data: SELISIH,
                backgroundColor: SELISIH.map(v => v === null ? '#e5e7eb' : v < 0 ? '#ef444499' : '#22c55e99'),
                borderColor:     SELISIH.map(v => v === null ? '#e5e7eb' : v < 0 ? '#ef4444'   : '#22c55e'),
                borderWidth: 2, borderRadius: 6,
            }],
            yFmt:   v => (v >= 0 ? '+' : '') + 'Rp ' + (v/1e6).toFixed(1).replace('.', ',') + 'jt',
            tipFmt: (label, v) => {
                if (v === null) return label + ': Belum ada opname';
                return label + ': ' + (v >= 0 ? '+' : '') + rpFmt(v) + (v < 0 ? ' (Boros)' : v > 0 ? ' (Efisien)' : ' (Sesuai)');
            },
            legend: false,
            zeroLine: true,
        }),
    },
    pct_ideal: {
        subtitle: '% HPP Ideal = HPP Ideal ÷ Omset × 100% — lebih rendah lebih baik',
        build: () => ({
            datasets: [{
                label: '% HPP Ideal',
                data: PCT_IDEAL,
                backgroundColor: LABELS.map((_, i) => hex2a(COLORS[i % COLORS.length], .75)),
                borderColor:     LABELS.map((_, i) => COLORS[i % COLORS.length]),
                borderWidth: 2, borderRadius: 6,
            }],
            yFmt:   v => v + '%',
            tipFmt: (label, v) => label + ': ' + pctFmt(v),
            legend: false,
            suggestedMax: 60,
        }),
    },
    pct_aktual: {
        subtitle: '% HPP Aktual = HPP Aktual ÷ Omset × 100% — lebih rendah lebih baik',
        build: () => ({
            datasets: [{
                label: '% HPP Aktual',
                data: PCT_AKTUAL,
                backgroundColor: LABELS.map((_, i) => hex2a(COLORS[i % COLORS.length], .75)),
                borderColor:     LABELS.map((_, i) => COLORS[i % COLORS.length]),
                borderWidth: 2, borderRadius: 6,
            }],
            yFmt:   v => v + '%',
            tipFmt: (label, v) => v !== null ? label + ': ' + pctFmt(v) : label + ': Belum ada opname',
            legend: false,
            suggestedMax: 60,
        }),
    },
    omset_vs_ideal: {
        subtitle: 'Perbandingan omset dengan HPP ideal per toko',
        build: () => ({
            datasets: [
                {
                    label: 'Omset',
                    data: OMSET,
                    backgroundColor: LABELS.map((_, i) => hex2a(COLORS[i % COLORS.length], .8)),
                    borderColor:     LABELS.map((_, i) => COLORS[i % COLORS.length]),
                    borderWidth: 2, borderRadius: 6,
                },
                {
                    label: 'HPP Ideal',
                    data: HPP_IDEAL,
                    backgroundColor: '#64748b44',
                    borderColor: '#64748b',
                    borderWidth: 2, borderRadius: 6,
                },
            ],
            yFmt:   v => 'Rp ' + (v/1e6).toFixed(1).replace('.', ',') + 'jt',
            tipFmt: (label, v) => label + ': ' + rpFmt(v),
            legend: true,
        }),
    },
};

// ── Inisialisasi chart ────────────────────────────────────────────────────
let chart = null;

function renderChart(mode) {
    const cfg   = MODES[mode];
    const built = cfg.build();

    document.getElementById('chartSubtitle').textContent = cfg.subtitle;

    if (chart) chart.destroy();

    chart = new Chart(document.getElementById('mainChart'), {
        type: 'bar',
        data: { labels: LABELS, datasets: built.datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: built.legend ?? false, position: 'top' },
                tooltip: {
                    callbacks: {
                        label: ctx => built.tipFmt(ctx.dataset.label, ctx.raw),
                    },
                },
            },
            scales: {
                y: {
                    ticks:       { callback: built.yFmt },
                    grid:        { color: ctx => (built.zeroLine && ctx.tick.value === 0) ? '#94a3b8' : '#f0f0f0' },
                    suggestedMin: built.suggestedMin ?? undefined,
                    suggestedMax: built.suggestedMax ?? undefined,
                },
                x: { grid: { display: false } },
            },
        },
    });
}

// init
renderChart('hpp_ideal');

document.getElementById('chartMode').addEventListener('change', function () {
    renderChart(this.value);
});
</script>
@endpush
@endsection
