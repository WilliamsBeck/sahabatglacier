@extends('layouts.app')
@section('title', 'Trend HPP')

@section('content')
@include('sales._hpp_tabs', ['currentHppTab' => 'tren'])
<div class="container-fluid py-2">
  <p class="text-muted small mb-3">Tren — pergerakan % HPP ideal & aktual per bulan.</p>

  {{-- Filter Card --}}
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
      <form method="GET" action="{{ route('sales.hpp.trend') }}" class="row g-3 align-items-end">

        {{-- Pemilih toko: dropdown checkbox (ringkas, tidak menumpuk) --}}
        <div class="col-12 col-md-3">
          <x-multi-check-dropdown
              name="store_ids[]"
              label="Toko"
              placeholder="Pilih toko…"
              :options="$stores->pluck('name', 'id')->all()"
              :selected="$selectedIds" />
        </div>

        {{-- End period --}}
        <div class="col-6 col-md-2">
          <label class="form-label fw-semibold small">Bulan Akhir</label>
          <select name="month" class="form-select form-select-sm">
            @foreach(range(1,12) as $m)
              <option value="{{ $m }}" {{ $m == $endMonth ? 'selected' : '' }}>
                {{ \Carbon\Carbon::create(null,$m)->translatedFormat('F') }}
              </option>
            @endforeach
          </select>
        </div>
        <div class="col-6 col-md-1">
          <label class="form-label fw-semibold small">Tahun</label>
          <select name="year" class="form-select form-select-sm">
            @foreach(range(now()->year - 2, now()->year + 1) as $y)
              <option value="{{ $y }}" {{ $y == $endYear ? 'selected' : '' }}>{{ $y }}</option>
            @endforeach
          </select>
        </div>

        {{-- Periods & type --}}
        <div class="col-6 col-md-2">
          <label class="form-label fw-semibold small">Jumlah Periode</label>
          <select name="periods" class="form-select form-select-sm">
            @foreach([3,6,9,12] as $n)
              <option value="{{ $n }}" {{ $n == $periods ? 'selected' : '' }}>{{ $n }} bulan</option>
            @endforeach
          </select>
        </div>
        <div class="col-6 col-md-1">
          <label class="form-label fw-semibold small">Periode</label>
          <select name="period_type" class="form-select form-select-sm">
            <option value="end_month"  {{ $periodType=='end_month'  ? 'selected':'' }}>Full</option>
            <option value="mid_month"  {{ $periodType=='mid_month'  ? 'selected':'' }}>Mid</option>
          </select>
        </div>

        <div class="col-12 col-md-1">
          <button type="submit" class="btn btn-primary btn-sm w-100">Tampilkan</button>
        </div>
      </form>
    </div>
  </div>

  @if(count($trendData) > 0)
  {{-- Chart Card --}}
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
      <span class="fw-semibold">Grafik Trend</span>
      <select id="chartModeSelect" class="form-select form-select-sm" style="width:220px">
        <option value="pct_ideal">% HPP Ideal</option>
        <option value="pct_aktual">% HPP Aktual</option>
        <option value="hpp_ideal">HPP Ideal (Rp)</option>
        <option value="hpp_aktual">HPP Aktual (Rp)</option>
        <option value="margin_ideal">Margin Ideal (%)</option>
        <option value="omset">Omset (Rp)</option>
      </select>
    </div>
    <div class="card-body">
      <canvas id="trendChart" height="80"></canvas>
    </div>
  </div>

  {{-- Table --}}
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-0 py-3">
      <span class="fw-semibold">Tabel Detail per Toko</span>
    </div>
    <div class="card-body p-0">
      @foreach($trendData as $td)
      <div class="px-4 py-3 border-bottom">
        <h6 class="fw-semibold mb-3">{{ $td->store->name }}</h6>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle small mb-0">
            <thead class="table-light">
              <tr>
                <th>Periode</th>
                <th class="text-end">Omset</th>
                <th class="text-end">HPP Ideal</th>
                <th class="text-end">% HPP Ideal</th>
                <th class="text-end">HPP Aktual</th>
                <th class="text-end">% HPP Aktual</th>
                <th class="text-end">Margin Ideal</th>
              </tr>
            </thead>
            <tbody>
              @foreach($td->points as $pt)
              <tr>
                <td>{{ $pt->label }}</td>
                <td class="text-end">{{ $pt->omset !== null ? 'Rp '.number_format($pt->omset,0,',','.') : '-' }}</td>
                <td class="text-end">{{ $pt->hpp_ideal !== null ? 'Rp '.number_format($pt->hpp_ideal,0,',','.') : '-' }}</td>
                <td class="text-end">
                  @if($pt->pct_hpp_ideal !== null)
                    <span class="badge {{ $pt->pct_hpp_ideal > 35 ? 'bg-danger-subtle text-danger' : ($pt->pct_hpp_ideal > 28 ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success') }}">
                      {{ number_format($pt->pct_hpp_ideal, 1, ',', '.') }}%
                    </span>
                  @else
                    <span class="text-muted">-</span>
                  @endif
                </td>
                <td class="text-end">{{ $pt->hpp_aktual !== null ? 'Rp '.number_format($pt->hpp_aktual,0,',','.') : '-' }}</td>
                <td class="text-end">
                  @if($pt->pct_hpp_aktual !== null)
                    <span class="badge {{ $pt->pct_hpp_aktual > 35 ? 'bg-danger-subtle text-danger' : ($pt->pct_hpp_aktual > 28 ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success') }}">
                      {{ number_format($pt->pct_hpp_aktual, 1, ',', '.') }}%
                    </span>
                  @else
                    <span class="text-muted">-</span>
                  @endif
                </td>
                <td class="text-end">{{ $pt->margin_ideal !== null ? number_format($pt->margin_ideal, 1, ',', '.').'%' : '-' }}</td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
      @endforeach
    </div>
  </div>
  @else
    <div class="alert alert-info">Pilih minimal satu toko dan klik Tampilkan.</div>
  @endif
</div>

@php
$jsDatasets = collect($trendData)->map(function ($td) {
    return [
        'store'  => $td->store->name,
        'points' => collect($td->points)->map(function ($pt) {
            return [
                'pct_ideal'    => $pt->pct_hpp_ideal,
                'pct_aktual'   => $pt->pct_hpp_aktual,
                'hpp_ideal'    => $pt->hpp_ideal,
                'hpp_aktual'   => $pt->hpp_aktual,
                'margin_ideal' => $pt->margin_ideal,
                'omset'        => $pt->omset,
            ];
        })->values()->all(),
    ];
})->values()->all();
@endphp

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const LABELS   = @json($labels);
const DATASETS = @json($jsDatasets);

const PALETTE = ['#3b82f6','#f59e0b','#10b981','#ef4444','#8b5cf6','#06b6d4','#f97316'];

let chart = null;

function buildDatasets(mode) {
    return DATASETS.map((td, i) => ({
        label: td.store,
        data: td.points.map(p => p[mode] !== null ? parseFloat(p[mode].toFixed(2)) : null),
        borderColor: PALETTE[i % PALETTE.length],
        backgroundColor: PALETTE[i % PALETTE.length] + '22',
        tension: 0.35,
        fill: false,
        spanGaps: false,
        pointRadius: 5,
        pointHoverRadius: 7,
    }));
}

function isPercent(mode) {
    return ['pct_ideal','pct_aktual','margin_ideal'].includes(mode);
}

function renderChart(mode) {
    if (chart) chart.destroy();
    const ctx = document.getElementById('trendChart').getContext('2d');
    chart = new Chart(ctx, {
        type: 'line',
        data: { labels: LABELS, datasets: buildDatasets(mode) },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: {
                    ticks: {
                        callback: (v) => isPercent(mode)
                            ? v.toFixed(1).replace('.', ',') + '%'
                            : 'Rp ' + v.toLocaleString('id-ID'),
                    }
                }
            },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const val = ctx.parsed.y;
                            if (val === null) return ctx.dataset.label + ': -';
                            return ctx.dataset.label + ': ' + (
                                isPercent(mode)
                                    ? val.toFixed(1).replace('.', ',') + '%'
                                    : 'Rp ' + val.toLocaleString('id-ID')
                            );
                        }
                    }
                }
            }
        }
    });
}

document.getElementById('chartModeSelect').addEventListener('change', function () {
    renderChart(this.value);
});

renderChart('pct_ideal');
</script>
@endpush
@endsection
