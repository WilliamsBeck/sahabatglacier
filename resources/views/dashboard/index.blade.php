@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h4 class="page-title">Dashboard</h4>
        <p class="text-muted mb-0">
            Selamat datang, <strong>{{ auth()->user()->name }}</strong>
        </p>
    </div>
    <div class="text-end">
        <div id="liveClock" class="fw-semibold" style="font-size:1.35rem;letter-spacing:-.01em;font-variant-numeric:tabular-nums">--:--:--</div>
        <div id="liveDate" class="text-muted" style="font-size:.78rem"></div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════
     STAT CARDS
═══════════════════════════════════════════════════════ --}}
<div class="row g-3 mb-4">

    {{-- Nilai Stok (Saldo Harian) --}}
    <div class="col-6 col-xl">
        <div class="stat-card sg sg-teal">
            <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-info">
                <div class="stat-number" style="font-size:1.05rem">
                    Rp {{ number_format($stockValue, 0, ',', '.') }}
                </div>
                <div class="stat-label">Nilai Stok Saat Ini</div>
            </div>
        </div>
    </div>

    {{-- Toko Aktif --}}
    <div class="col-6 col-xl">
        <div class="stat-card sg sg-indigo">
            <div class="stat-icon"><i class="bi bi-shop"></i></div>
            <div class="stat-info">
                <div class="stat-number">{{ $totalActiveStores }}</div>
                <div class="stat-label">Toko Aktif</div>
            </div>
        </div>
    </div>

    {{-- Low Stock --}}
    <div class="col-6 col-xl">
        <div class="stat-card sg sg-amber">
            <div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="stat-info">
                <div class="stat-number">{{ $lowStocks->count() }}</div>
                <div class="stat-label">Stok Menipis (item)</div>
            </div>
        </div>
    </div>

    {{-- Total Waste --}}
    <div class="col-6 col-xl">
        <div class="stat-card sg sg-rose">
            <div class="stat-icon"><i class="bi bi-trash3"></i></div>
            <div class="stat-info">
                <div class="stat-number" style="font-size:1.05rem">
                    Rp {{ number_format($totalWaste, 0, ',', '.') }}
                </div>
                <div class="stat-label">Waste Bulan Ini</div>
            </div>
        </div>
    </div>

    {{-- Total Produksi --}}
    <div class="col-6 col-xl">
        <div class="stat-card sg sg-emerald">
            <div class="stat-icon"><i class="bi bi-gear"></i></div>
            <div class="stat-info">
                <div class="stat-number">{{ $totalProduksi }}</div>
                <div class="stat-label">Produksi Bulan Ini (batch)</div>
            </div>
        </div>
    </div>

</div>

{{-- ═══════════════════════════════════════════════════════
     TREN + AKTIVITAS
═══════════════════════════════════════════════════════ --}}
<div class="row g-3 mb-4">

    {{-- ── Pemakaian Bahan Baku (Pencatatan Harian) ──────────────────── --}}
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <div class="d-flex align-items-center mb-2">
                    <span class="fw-semibold">
                        <i class="bi bi-graph-up-arrow text-muted me-1"></i>
                        Pemakaian Bahan
                    </span>
                </div>
                <form method="GET" class="d-flex align-items-center gap-2" id="chartIngredientForm">
                    @if(request('store_id'))
                        <input type="hidden" name="store_id" value="{{ request('store_id') }}">
                    @endif
                    @php
                        $bulanID = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                        $currentYear = now()->year;
                    @endphp
                    <select name="chart_month" class="form-select form-select-sm" style="min-width:110px"
                            onchange="document.getElementById('chartIngredientForm').submit()">
                        @for($m = 1; $m <= 12; $m++)
                            <option value="{{ $m }}" {{ $chartMonth == $m ? 'selected' : '' }}>{{ $bulanID[$m] }}</option>
                        @endfor
                    </select>
                    <select name="chart_year" class="form-select form-select-sm" style="min-width:80px"
                            onchange="document.getElementById('chartIngredientForm').submit()">
                        @for($y = 2024; $y <= $currentYear; $y++)
                            <option value="{{ $y }}" {{ $chartYear == $y ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                    <select name="chart_ingredient" class="form-select form-select-sm flex-grow-1" style="min-width:160px"
                            onchange="document.getElementById('chartIngredientForm').submit()">
                        <option value="">Semua Bahan (nilai Rp)</option>
                        @foreach($chartIngredients as $ing)
                            <option value="{{ $ing->id }}" {{ (string)$chartSelectedId === (string)$ing->id ? 'selected' : '' }}>
                                {{ $ing->name }}
                            </option>
                        @endforeach
                    </select>
                </form>
            </div>
            <div class="card-body">
                <div class="small text-muted mb-2">
                    @if($chartIngredientName)
                        Kuantitas pemakaian <strong>{{ $chartIngredientName }}</strong> per hari (dalam pack)
                    @else
                        Total nilai pemakaian seluruh bahan per hari (Rp) — pilih bahan untuk lihat kuantitasnya
                    @endif
                </div>
                <div style="position:relative;height:240px">
                    <canvas id="usageChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Stok Menipis ───────────────────────────────────────────── --}}
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">
                    <i class="bi bi-exclamation-triangle text-danger me-1"></i>
                    Stok Menipis
                </span>
                <a href="{{ route('inventory.stocks.index') }}"
                   class="btn btn-sm btn-outline-danger">Lihat Semua</a>
            </div>
            <div class="card-body p-0" style="max-height:288px;overflow-y:auto">
                @if($lowStocks->isEmpty())
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-check-circle text-success fs-2 d-block mb-2"></i>
                        Semua stok aman ✓
                        <div class="small mt-1">Set par level di halaman Saldo Stok</div>
                    </div>
                @else
                    <table class="table table-index align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="col-name">Bahan</th>
                                <th>Sisa Stok</th>
                                <th>DOS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($lowStocks->take(8) as $stock)
                            @php
                                $isCrit   = $stock->dos_status === 'critical';
                                $dosColor = $isCrit ? 'danger' : 'warning';
                                $dosText  = $isCrit ? 'text-white' : 'text-dark';

                                // Pecah saldo ke Dus / Pack / unit dasar — konsisten dgn Saldo Stok
                                $pkg  = $stock->packaging ?? $stock->ingredient->packagings->first();
                                $unit = $stock->ingredient->unit_base;
                                $bal  = (float) ($stock->sealed_balance ?? $stock->stock_balance);
                                $ptb  = $pkg && $pkg->pack_to_base  > 0 ? (float) $pkg->pack_to_base : 0;
                                $ctb  = $pkg && $pkg->crate_to_pack > 0 ? $pkg->crate_to_pack * $ptb : 0;

                                $neg = $bal < 0; $rem = abs($bal);
                                $dus = $pack = 0;
                                if ($ctb > 0) { $dus  = (int) floor($rem / $ctb); $rem -= $dus  * $ctb; }
                                if ($ptb > 0) { $pack = (int) floor($rem / $ptb); $rem -= $pack * $ptb; }

                                $parts = [];
                                if ($dus)  $parts[] = $dus  . ' Dus';
                                if ($pack) $parts[] = $pack . ' Pack';
                                // Cukup Dus & Pack — sisa gram/pcs eceran diabaikan.
                                // Satuan dasar hanya dipakai untuk bahan tanpa kemasan (tak ada Dus/Pack).
                                if (empty($parts)) {
                                    $parts[] = number_format($rem, 0, ',', '.') . ' ' . $unit;
                                }
                            @endphp
                            <tr>
                                <td class="col-name">
                                    <div class="fw-semibold" style="font-size:.82rem">{{ $stock->ingredient->name }}</div>
                                    <div class="text-muted" style="font-size:.72rem">{{ $stock->store->name }}</div>
                                </td>
                                <td style="font-size:.8rem;white-space:nowrap">{{ $neg ? '-' : '' }}{{ implode(' ', $parts) }}</td>
                                <td>
                                    <span class="badge bg-{{ $dosColor }} {{ $dosText }}" style="font-size:.72rem;white-space:nowrap">
                                        {{ $isCrit ? '🔴' : '🟡' }} {{ $stock->dos_value }} hari
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

</div>

{{-- ═══════════════════════════════════════════════════════
     SECTION UTAMA
═══════════════════════════════════════════════════════ --}}
<div class="row g-3">

    {{-- ── Toko Belum Update Pencatatan Harian ─────────────────────── --}}
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">
                    <i class="bi bi-calendar-x text-danger me-1"></i>
                    Belum Update Harian
                </span>
                <span class="badge bg-danger rounded-pill">
                    {{ $storesNotUpdated->count() }} toko
                </span>
            </div>
            <div class="card-body p-0">
                @if($storesNotUpdated->isEmpty())
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-check-circle text-success fs-2 d-block mb-2"></i>
                    Semua toko sudah update s/d
                    {{ \Carbon\Carbon::parse($yesterday)->isoFormat('D MMM Y') }} ✓
                </div>
                @else
                <div class="px-3 py-2 border-bottom bg-danger-subtle">
                    <small class="text-danger fw-semibold">
                        <i class="bi bi-info-circle me-1"></i>
                        Belum ada catatan untuk
                        <strong>{{ \Carbon\Carbon::parse($yesterday)->isoFormat('D MMM Y') }}</strong>
                    </small>
                </div>
                @foreach($storesNotUpdated as $store)
                @php $lastDate = $lastUsageDates[$store->id] ?? null; @endphp
                <div class="d-flex align-items-center px-3 py-2 border-bottom gap-2">
                    <div class="flex-grow-1">
                        <div class="fw-semibold" style="font-size:.875rem">{{ $store->name }}</div>
                        <div class="text-muted" style="font-size:.75rem">
                            @if($lastDate)
                                Terakhir: {{ \Carbon\Carbon::parse($lastDate)->isoFormat('D MMM Y') }}
                                <span class="text-danger">
                                    ({{ \Carbon\Carbon::parse($lastDate)->diffForHumans() }})
                                </span>
                            @else
                                <span class="text-danger">Belum pernah input</span>
                            @endif
                        </div>
                    </div>
                    <a href="{{ route('inventory.daily-ledger.index', ['store_id' => $store->id]) }}"
                       class="btn btn-sm btn-outline-danger" style="font-size:.75rem">
                        Input
                    </a>
                </div>
                @endforeach
                @endif
            </div>
        </div>
    </div>

    {{-- ── Aktivitas Terbaru (real-time feed) ─────────────────────── --}}
    @php
        $modelLabels = [
            'Mutation' => 'Mutasi Stok', 'WasteLog' => 'Catatan Waste',
            'ProductionLog' => 'Produksi', 'Opname' => 'Stok Opname',
            'MonthlySale' => 'Penjualan Bulanan', 'MonthlyRevenue' => 'Omzet Bulanan',
            'Store' => 'Toko', 'Supplier' => 'Supplier', 'Ingredient' => 'Bahan Baku',
            'Menu' => 'Menu', 'Recipe' => 'Resep', 'User' => 'User',
            'IngredientCategory' => 'Kategori Bahan', 'MenuCategory' => 'Kategori Menu',
            'StoreStock' => 'Saldo Stok', 'HppMonthlyReport' => 'Laporan HPP',
            'DailyConfirmation' => 'Konfirmasi Harian',
        ];
        $mutTypes = [
            'purchase_zhisheng' => 'Pembelian Pusat', 'purchase_supplier' => 'Pembelian Supplier Lokal',
            'opening_stock' => 'Input Stok Awal', 'sale_internal' => 'Penjualan Internal',
            'sale_external' => 'Pembelian Eksternal',
        ];
        $verbs = [
            'created' => 'ditambahkan', 'updated' => 'diperbarui', 'deleted' => 'dihapus',
            'confirmed' => 'dikonfirmasi', 'approved' => 'disetujui', 'rejected' => 'ditolak',
        ];
    @endphp
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">
                    <span class="d-inline-block rounded-circle me-1" style="width:8px;height:8px;background:#16A34A;animation:pulse 1.6s infinite"></span>
                    Aktivitas Terbaru
                </span>
                <a href="{{ route('audit.index') }}" class="btn btn-sm btn-outline-secondary">Semua Log</a>
            </div>
            <div class="card-body p-0" style="max-height:340px;overflow-y:auto">
                @forelse($recentActivity as $log)
                    @php
                        $icon = match($log->action) {
                            'created'   => 'bi-plus-circle text-success',
                            'updated'   => 'bi-pencil text-primary',
                            'deleted'   => 'bi-trash text-danger',
                            'confirmed','approved' => 'bi-check-circle text-info',
                            'rejected'  => 'bi-x-circle text-warning',
                            default     => 'bi-dot text-secondary',
                        };
                        $vals  = $log->new_values ?: $log->old_values ?: [];
                        $title = $modelLabels[$log->model] ?? $log->model;
                        if ($log->model === 'Mutation' && !empty($vals['type'])) {
                            $title = $mutTypes[$vals['type']] ?? $title;
                        }
                        $ref  = $vals['reference_no'] ?? $vals['name'] ?? null;
                        $verb = $verbs[$log->action] ?? $log->action;
                    @endphp
                    <div class="d-flex align-items-start px-3 py-2 border-bottom gap-2">
                        <i class="bi {{ $icon }} mt-1"></i>
                        <div class="flex-grow-1">
                            <div style="font-size:.82rem;line-height:1.3">
                                <span class="fw-medium">{{ $title }}</span>
                                @if($ref)<span class="text-muted">· {{ $ref }}</span>@endif
                                <span class="text-muted">{{ $verb }}</span>
                            </div>
                            <div class="text-muted" style="font-size:.7rem">
                                {{ $log->user_name ?? '—' }} · {{ $log->created_at->diffForHumans() }}
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-clock-history fs-2 d-block mb-2"></i>
                        Belum ada aktivitas
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ── Top Waste Bulan Ini ──────────────────────────────────────── --}}
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">
                    <i class="bi bi-trash3 text-warning me-1"></i>
                    Top Waste Bulan Ini
                </span>
                <a href="{{ route('waste.logs.index') }}" class="btn btn-sm btn-outline-secondary">Detail</a>
            </div>
            <div class="card-body">
                @forelse($topWaste as $w)
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="fw-medium" style="font-size:.82rem">{{ $w->ingredient->name ?? '—' }}</span>
                        <span class="text-muted" style="font-size:.75rem">Rp {{ number_format($w->total_loss, 0, ',', '.') }}</span>
                    </div>
                    <div class="progress" style="height:6px;background:var(--surface-2)">
                        <div class="progress-bar" role="progressbar"
                             style="width:{{ $topWasteMax > 0 ? round(($w->total_loss / $topWasteMax) * 100) : 0 }}%;background:#DC2626"></div>
                    </div>
                </div>
                @empty
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-check-circle text-success fs-2 d-block mb-2"></i>
                    Tidak ada waste bulan ini ✓
                </div>
                @endforelse
            </div>
        </div>
    </div>

</div>
@endsection

@push('styles')
<style>
    @keyframes pulse {
        0%   { box-shadow: 0 0 0 0 rgba(22,163,74,.5); }
        70%  { box-shadow: 0 0 0 6px rgba(22,163,74,0); }
        100% { box-shadow: 0 0 0 0 rgba(22,163,74,0); }
    }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    // ── Live clock ───────────────────────────────────────────────
    (function () {
        var hari = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
        var bulan = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        function tick() {
            var n = new Date();
            var p = function (x) { return String(x).padStart(2, '0'); };
            var c = document.getElementById('liveClock');
            var d = document.getElementById('liveDate');
            if (c) c.textContent = p(n.getHours()) + ':' + p(n.getMinutes()) + ':' + p(n.getSeconds());
            if (d) d.textContent = hari[n.getDay()] + ', ' + n.getDate() + ' ' + bulan[n.getMonth()] + ' ' + n.getFullYear();
        }
        tick();
        setInterval(tick, 1000);
    })();

    // ── Grafik pemakaian bahan baku (pencatatan harian) ──────────
    (function () {
        var el = document.getElementById('usageChart');
        if (!el || typeof Chart === 'undefined') return;

        var mode = @json($chartMode);          // 'qty' | 'value'
        var unit = @json($chartUnit);          // unit base atau 'Rp'
        var name = @json($chartIngredientName);

        var rupiah = function (v) {
            if (v >= 1e9) return 'Rp ' + (v / 1e9).toFixed(1).replace('.', ',') + 'M';
            if (v >= 1e6) return 'Rp ' + (v / 1e6).toFixed(1).replace('.', ',') + 'jt';
            if (v >= 1e3) return 'Rp ' + (v / 1e3).toFixed(0) + 'rb';
            return 'Rp ' + Math.round(v).toLocaleString('id-ID');
        };
        var numFmt = function (v) {
            return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(v);
        };
        var fmt = function (v) { return mode === 'value' ? rupiah(v) : numFmt(v) + ' ' + unit; };

        // Mode per-bahan pakai satuan pack (bilangan bulat) → paksa tick sumbu-Y integer,
        // jangan tampilkan gridline desimal (0,2 / 0,4 dst). Step disesuaikan max ≤ ~6 tick.
        var dataArr = @json($chartData);
        var maxVal  = dataArr.length ? Math.max.apply(null, dataArr) : 0;
        var yTickOpts = { font: { size: 10 }, color: '#475569', callback: function (v) { return fmt(v); } };
        if (mode !== 'value') {
            yTickOpts.stepSize  = Math.max(1, Math.ceil(maxVal / 6));
            yTickOpts.precision = 0;
        }

        new Chart(el, {
            type: 'line',
            data: {
                labels: @json($chartLabels),
                datasets: [{
                    label: mode === 'value' ? 'Nilai Pemakaian' : ('Pemakaian ' + (name || '')),
                    data: @json($chartData),
                    borderColor: '#006275',
                    backgroundColor: 'rgba(0,98,117,.10)',
                    borderWidth: 2, tension: .35, fill: true,
                    pointRadius: 2, pointHoverRadius: 5,
                    pointBackgroundColor: '#006275'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (items) { return 'Tanggal ' + items[0].label; },
                            label: function (ctx) { return fmt(ctx.parsed.y); }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#475569' } },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#E2E8F0' },
                        ticks: yTickOpts
                    }
                }
            }
        });
    })();
</script>
@endpush
