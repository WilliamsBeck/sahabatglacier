@extends('layouts.app')
@section('title', 'Saldo Stok')
@section('content')

<div class="page-header d-flex justify-content-between align-items-center">
    <h4 class="page-title mb-0">Saldo Stok Real-Time</h4>
    <span class="text-muted small">
        <i class="bi bi-info-circle me-1" data-bs-toggle="tooltip"
           title="Stok dihitung per-kemasan fisik. Total dus mungkin berbeda dengan Pencatatan Harian yang pakai konversi standar."></i>
        <i class="bi bi-clock me-1"></i>Rata² pakai <strong>{{ $dosWindowDays }} hr terakhir</strong>
    </span>
</div>

{{-- Konfigurasi order banner (compact) --}}
@php
// Model standar: reorder point = lead time + safety stock; zona kuning +1 siklus order
$critAt = $leadTimeDays ? ($leadTimeDays + (int)($safetyStockDays ?? 0)) : null;
$warnAt = $critAt !== null ? ($critAt + (int)($orderCycleDays ?? 0)) : null;
@endphp
@if($leadTimeDays)
<div class="alert alert-info d-flex align-items-center gap-2 py-1 px-3 mb-2 small">
    <i class="bi bi-bullseye"></i>
    <span>
        <strong>Lead:</strong> <span id="cfgLeadTime">{{ $leadTimeDays }}</span> hr ·
        <strong>Safety:</strong> <span id="cfgSafety">{{ $safetyStockDays ?? 0 }}</span> hr ·
        <strong>Order:</strong> <span id="cfgOrderCycle">{{ $orderCycleDays ?? '?' }}</span> hr ·
        <strong>Window:</strong> <span id="cfgDosWindow">{{ $dosWindowDays }}</span> hr
        <span class="text-muted ms-2">🔴 &lt;<span id="cfgCrit">{{ $critAt }}</span>hr pesan · 🟡 &lt;<span id="cfgWarn">{{ $warnAt }}</span>hr ancang²</span>
    </span>
    <button type="button" class="btn btn-sm btn-outline-primary ms-auto py-0" data-bs-toggle="modal" data-bs-target="#modalStoreConfig">
        <i class="bi bi-pencil-square"></i> Ubah
    </button>
</div>
@else
<div class="alert alert-warning d-flex align-items-center gap-2 py-1 px-3 mb-2 small">
    <i class="bi bi-exclamation-triangle"></i>
    <span><strong>Konfigurasi order belum diset</strong> — sistem tidak bisa menentukan reorder.</span>
    <button type="button" class="btn btn-sm btn-warning ms-auto py-0" data-bs-toggle="modal" data-bs-target="#modalStoreConfig">
        <i class="bi bi-gear"></i> Set Konfigurasi
    </button>
</div>
@endif

{{-- ═══════════ MODAL KONFIGURASI ORDER ═══════════ --}}
<div class="modal fade" id="modalStoreConfig" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-gear me-2"></i>Konfigurasi Order Toko</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formStoreConfig">
                @csrf
                <input type="hidden" name="store_id" value="{{ $selectedId }}">
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        Konfigurasi ini menentukan threshold DOS dan window perhitungan rata-rata pemakaian untuk toko <strong>{{ $stores->firstWhere('id', $selectedId)?->name ?? '-' }}</strong>.
                    </p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Lead Time (hari) <span class="text-danger">*</span></label>
                        <input type="number" name="lead_time_days" class="form-control" min="1" max="30" required
                               value="{{ $leadTimeDays ?? 7 }}">
                        <div class="form-text">Berapa hari dari Anda kirim PO sampai barang tiba.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Safety Stock (hari) <span class="text-danger">*</span></label>
                        <input type="number" name="safety_stock_days" class="form-control" min="0" max="60" required
                               value="{{ $safetyStockDays ?? 0 }}">
                        <div class="form-text">Cadangan di atas lead time (jaga-jaga pemakaian naik / kiriman telat). Titik pesan = Lead Time + Safety. Patokan umum: 30–50% lead time.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Siklus Order (hari) <span class="text-danger">*</span></label>
                        <input type="number" name="order_cycle_days" class="form-control" min="1" max="90" required
                               value="{{ $orderCycleDays ?? 14 }}">
                        <div class="form-text">Jarak antar order (misal: 30 = order tiap sebulan sekali). Juga jadi zona kuning "ancang-ancang".</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Window DOS (hari) <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3">
                            @foreach([7, 14, 30] as $opt)
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="dos_window_days" id="dosw{{ $opt }}"
                                       value="{{ $opt }}" {{ $dosWindowDays == $opt ? 'checked' : '' }}>
                                <label class="form-check-label" for="dosw{{ $opt }}">{{ $opt }} hari</label>
                            </div>
                            @endforeach
                        </div>
                        <div class="form-text">Berapa hari ke belakang dipakai untuk rata² pemakaian harian.</div>
                    </div>

                    <div class="alert alert-info small py-2 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Threshold DOS yang akan berlaku:</strong>
                        <div class="mt-1">
                            🔴 Pesan sekarang: DOS &lt; <span id="prevCrit">{{ $critAt ?? 7 }}</span> hari (Lead+Safety) ·
                            🟡 Ancang-ancang: DOS &lt; <span id="prevWarn">{{ $warnAt ?? 10 }}</span> hari (+Siklus)
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Filter + Legend (compact 1 baris) --}}
<div class="card mb-2"><div class="card-body py-2 d-flex align-items-center gap-3 flex-wrap">
    <form method="GET" class="d-flex align-items-center gap-2 mb-0" style="min-width:240px">
        <select name="store_id" class="form-select form-select-sm" onchange="this.form.submit()">
            @foreach($stores as $s)
            <option value="{{ $s->id }}" {{ $selectedId == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
            @endforeach
        </select>
    </form>
    <div class="d-flex gap-1 align-items-center small">
        <span class="badge bg-danger">🔴 Kritis</span>
        <span class="badge bg-warning text-dark">🟡 Hampir habis</span>
        <span class="badge bg-success">🟢 Aman</span>
        <span class="text-muted ms-2">— belum ada catatan</span>
    </div>
    <button class="btn btn-link btn-sm text-muted ms-auto p-0" type="button" data-bs-toggle="collapse" data-bs-target="#helpBox" style="font-size:.8rem">
        <i class="bi bi-info-circle"></i> Penjelasan kolom
    </button>
</div></div>

{{-- Help box (collapsed by default) --}}
<div class="collapse mb-2" id="helpBox">
    <div class="card border-0 bg-light"><div class="card-body py-2 small text-muted">
        <strong>DOS</strong>: Stok ÷ rata² pemakaian harian — perkiraan habis dalam berapa hari ·
        <strong>Min Stok</strong>: jumlah pack minimum sebelum kiriman berikutnya ·
        <strong>Data pemakaian</strong>: dari Pencatatan Harian (min. 7 hari untuk akurat).
    </div></div>
</div>

@if($grouped->isEmpty())
<div class="card"><div class="card-body text-center py-4 text-muted">
    <i class="bi bi-clipboard-data fs-1 d-block mb-2 opacity-25"></i>
    Belum ada data stok untuk toko ini.
</div></div>
@else
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 stock-table align-middle">
                <thead class="table-dark">
                    <tr>
                        <th style="width:22%">Bahan</th>
                        <th class="text-end" style="width:10%">Dus</th>
                        <th class="text-end" style="width:10%">Pack</th>
                        <th class="text-end" style="width:15%">Harga/Dus</th>
                        <th class="text-end" style="width:15%">Subtotal</th>
                        <th class="text-center" style="width:14%;padding-left:calc(4.5rem + 20px)">DOS</th>
                        <th class="text-center" style="width:14%">Min Stok</th>
                    </tr>
                </thead>
                <tbody>
                @php $grandTotal = 0; $colspanTotal = 7; @endphp
                @foreach($grouped as $catKey => $rows)
                @php
                    $label      = $categoryLabels[$catKey] ?? ucfirst($catKey);
                    $catTotal   = $rows->sum('subtotal');
                    $grandTotal += $catTotal;
                    $critCount  = $rows->where('dosStatus','critical')->count();
                    $warnCount  = $rows->where('dosStatus','warning')->count();
                    $rowsByIng  = $rows->groupBy(fn($r) => $r->ingredient->id);
                @endphp

                {{-- Category divider --}}
                <tr class="category-header">
                    <td colspan="{{ $colspanTotal }}">
                        <span class="fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.5px">{{ $label }}</span>
                        <span class="text-muted ms-1" style="font-size:.7rem">· {{ $rowsByIng->count() }} bahan</span>
                        @if($critCount) <span class="badge bg-danger ms-1" style="font-size:.6rem">{{ $critCount }} kritis</span> @endif
                        @if($warnCount) <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">{{ $warnCount }} hampir</span> @endif
                    </td>
                </tr>

                @foreach($rowsByIng as $ingId => $ingRows)
                    @php
                        // Cari kemasan utama (yang punya stok terbanyak, atau pertama kalau semua kosong)
                        $primaryRow   = $ingRows->sortByDesc('balance')->first();
                        $isPcs        = $primaryRow->ingredient->unit_base === 'pcs';

                        // TOTAL agregat lintas kemasan (jumlah fisik per kemasan)
                        $totalDus      = $ingRows->sum('dus');
                        $totalPack     = $ingRows->sum('pack');
                        $totalBaseRem  = $ingRows->sum('baseRem');
                        $totalSubtotal = $ingRows->sum('subtotal');
                        $totalBalance  = $ingRows->sum('balance');

                        // Stok dalam perjalanan (on-order) — untuk badge 🚚
                        $totalInTransit = $ingRows->sum('inTransitBase');
                        $itDus  = $ingRows->sum('inTransitDus');
                        $itPack = $ingRows->sum('inTransitPack');
                        $itParts = [];
                        if ($itDus)  $itParts[] = $itDus.' Dus';
                        if ($itPack) $itParts[] = $itPack.' Pack';
                        $itLabel = $itParts ? implode(' ', $itParts) : 'ada';

                        // Harga rata-rata (weighted by balance)
                        $avgPriceBase = $totalBalance > 0
                            ? $ingRows->sum(fn($r) => $r->balance * $r->avgPrice) / $totalBalance
                            : 0;
                        $avgPerDus  = $primaryRow->crateToBase > 0 ? $avgPriceBase * $primaryRow->crateToBase : 0;
                        $avgPerPack = $primaryRow->ptb > 0 ? $avgPriceBase * $primaryRow->ptb : 0;

                        $hasMultiPkg = $ingRows->count() > 1;
                        $trClass     = match($primaryRow->dosStatus) {
                            'critical' => 'table-danger',
                            'warning'  => 'table-warning',
                            default    => ($totalBalance <= 0 ? 'stock-empty' : ''),
                        };
                    @endphp

                    {{-- ═══ BARIS UTAMA per bahan (TOTAL) ═══ --}}
                    <tr class="{{ $trClass }} ing-main-row" data-ing="{{ $ingId }}">

                        {{-- Bahan --}}
                        <td class="fw-semibold align-middle">
                            @if($primaryRow->packaging && $ingRows->count() == 1)
                                <span class="toggle-pkg" data-target="ing-{{ $ingId }}" style="cursor:pointer">{{ $primaryRow->ingredient->name }}</span>
                            @elseif($primaryRow->packaging && $ingRows->count() > 1)
                                {{ $primaryRow->ingredient->name }}
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none toggle-pkg ms-1 align-baseline"
                                        data-target="ing-{{ $ingId }}" style="font-size:.7rem">
                                    <i class="bi bi-caret-right-fill text-secondary me-1" style="font-size:.65rem"></i>
                                    <span class="text-muted">{{ $ingRows->count() }} kemasan</span>
                                </button>
                            @else
                                {{ $primaryRow->ingredient->name }}
                                <span class="text-warning ms-2" style="font-size:.65rem"><i class="bi bi-exclamation-triangle me-1"></i>Kemasan belum diset</span>
                            @endif
                        </td>

                        {{-- Total Dus --}}
                        <td class="text-end">
                            @if($totalDus > 0)
                                <span class="fw-semibold stock-qty">{{ $totalDus }}</span>
                            @else
                                <span class="text-muted opacity-50 small">-</span>
                            @endif
                        </td>

                        {{-- Total Pack --}}
                        <td class="text-end">
                            @if($totalPack > 0)
                                <span class="fw-semibold stock-qty">{{ $totalPack }}</span>
                            @else
                                <span class="text-muted opacity-50 small">-</span>
                            @endif
                        </td>

                        {{-- Harga/Dus (avg) --}}
                        <td class="text-end">
                            @if($avgPerDus > 0)
                                <span>Rp {{ number_format($avgPerDus, 0, ',', '.') }}</span>
                            @else <span class="text-muted">–</span> @endif
                        </td>

                        {{-- Total Subtotal --}}
                        <td class="text-end fw-semibold">
                            @if($totalSubtotal > 0)
                                Rp {{ number_format($totalSubtotal, 0, ',', '.') }}
                            @else <span class="text-muted">–</span> @endif
                        </td>

                        {{-- DOS --}}
                        <td class="text-center" style="padding-left:calc(4.5rem + 20px)">
                            @if($totalDus <= 0 && $totalPack <= 0)
                                <span class="badge bg-dark" style="font-size:.6rem">Habis</span>
                            @elseif($primaryRow->dosValue !== null)
                                @php
                                    $badge = match($primaryRow->dosStatus) {
                                        'critical' => 'bg-danger',
                                        'warning'  => 'bg-warning text-dark',
                                        default    => 'bg-success',
                                    };
                                @endphp
                                <span class="badge {{ $badge }}" style="font-size:.62rem">{{ $primaryRow->dosValue }} hari</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                            @if($primaryRow->avgDailyPack !== null)
                                <div class="text-muted" style="font-size:.6rem">{{ number_format($primaryRow->avgDailyPack, 1, ',', '.') }} Pack/hari</div>
                            @endif
                            @if($totalInTransit > 0)
                                <div class="mt-1"><span class="badge bg-info text-dark" style="font-size:.56rem"
                                    title="Stok dalam perjalanan (mutasi masuk belum dikonfirmasi). Status reorder sudah memperhitungkan ini.">🚚 {{ $itLabel }} di jalan</span></div>
                            @endif
                        </td>

                        {{-- Min Stok --}}
                        <td class="text-center">
                            @if($primaryRow->parLevelPack !== null)
                                @php
                                    $minClass = match($primaryRow->dosStatus) {
                                        'critical' => 'text-danger fw-bold',
                                        'warning'  => 'text-warning fw-semibold',
                                        default    => 'text-success',
                                    };
                                @endphp
                                <span class="small {{ $minClass }}">{{ number_format(ceil($primaryRow->parLevelPack), 0, ',', '.') }} Pack</span>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                    </tr>

                    {{-- ═══ BARIS DETAIL per kemasan (hidden by default) ═══ --}}
                    @if($primaryRow->packaging)
                        @foreach($ingRows as $row)
                        <tr class="pkg-detail-row pkg-detail-ing-{{ $ingId }} d-none">
                            <td class="ps-4">
                                @if($row->packaging)
                                    <div style="font-size:.75rem">
                                        <strong>{{ $row->packaging_name }}</strong>
                                    </div>
                                    <div class="text-muted" style="font-size:.62rem;line-height:1.3">
                                        {{ $row->crate_to_pack }} Pack × {{ rtrim(rtrim(number_format($row->ptb, 2, '.', ''), '0'), '.') }} {{ $row->ingredient->unit_base }}
                                        @if($row->pkg_supplier) · {{ $row->pkg_supplier }} @endif
                                    </div>
                                @endif
                            </td>
                            <td class="text-end">
                                @if($row->dus > 0)
                                    <span>{{ $row->dus }}</span>
                                @else
                                    <span class="text-muted opacity-50 small">-</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if($row->pack > 0)
                                    <span>{{ $row->pack }}</span>
                                @else
                                    <span class="text-muted opacity-50 small">-</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if($row->pricePerDus > 0)
                                    <span>Rp {{ number_format($row->pricePerDus, 0, ',', '.') }}</span>
                                    @if($row->hasMultiPrices)
                                        @php
                                            $tipLines = $row->priceLayers->map(function($b) use ($row) {
                                                $parts = [];
                                                if ($b->dus)  $parts[] = $b->dus.' Dus';
                                                if ($b->pack) $parts[] = $b->pack.' Pack';
                                                return implode(' ', $parts ?: ['< 1 Pack']).' @ Rp '.number_format($b->price_per_crate ?: $b->price_per_pack, 0, ',', '.').'/'.($b->price_per_crate ? 'Dus' : 'Pack');
                                            })->implode("\n");
                                        @endphp
                                        <div class="text-muted" style="font-size:.6rem;cursor:help"
                                             data-bs-toggle="tooltip" data-bs-placement="left"
                                             title="{{ $tipLines }}">
                                            <i class="bi bi-info-circle"></i> Ø {{ $row->priceLayers->count() }} batch
                                        </div>
                                    @endif
                                @else <span class="text-muted">–</span> @endif
                            </td>
                            <td class="text-end">
                                @if($row->subtotal > 0)
                                    Rp {{ number_format($row->subtotal, 0, ',', '.') }}
                                @else <span class="text-muted">–</span> @endif
                            </td>
                            <td class="text-center">
                                @if($row->dus <= 0 && $row->pack <= 0)
                                    <span class="badge bg-dark" style="font-size:.55rem">Habis</span>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                            <td></td>
                        </tr>
                        @endforeach
                    @endif
                @endforeach

                {{-- Category subtotal --}}
                @if($catTotal > 0)
                <tr class="subtotal-row small fw-semibold">
                    <td colspan="4" class="text-end text-muted">Total {{ $label }}</td>
                    <td class="text-end">Rp {{ number_format($catTotal, 0, ',', '.') }}</td>
                    <td colspan="2"></td>
                </tr>
                @endif
                @endforeach
                </tbody>
                @if($grandTotal > 0)
                <tfoot>
                    <tr class="table-dark fw-bold">
                        <td colspan="4" class="text-end">TOTAL KESELURUHAN</td>
                        <td class="text-end">Rp {{ number_format($grandTotal, 0, ',', '.') }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
@endif

@push('scripts')
<script>
// ── Toggle detail per kemasan ──────────────────────────────
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.toggle-pkg');
    if (!btn) return;
    const target = btn.getAttribute('data-target');
    const rows   = document.querySelectorAll('.pkg-detail-' + target);
    if (!rows.length) return;
    const icon     = btn.querySelector('i');
    const expanded = !rows[0].classList.contains('d-none');
    rows.forEach(r => r.classList.toggle('d-none'));
    if (icon) {
        icon.classList.toggle('bi-caret-right-fill', expanded);
        icon.classList.toggle('bi-caret-down-fill', !expanded);
    }
});

// Aktifkan Bootstrap tooltips untuk batch info
document.addEventListener('DOMContentLoaded', function() {
    if (window.bootstrap?.Tooltip) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            new bootstrap.Tooltip(el);
        });
    }
});

(function() {
    const form = document.getElementById('formStoreConfig');
    if (!form) return;

    // Live preview threshold — model standar: crit = lead+safety, warn = crit+siklus
    function calcWarn() {
        const lead   = parseInt(form.querySelector('[name=lead_time_days]').value) || 0;
        const safety = parseInt(form.querySelector('[name=safety_stock_days]').value) || 0;
        const cycle  = parseInt(form.querySelector('[name=order_cycle_days]').value) || 0;
        const crit = lead + safety;
        const warn = crit + cycle;
        const cEl = document.getElementById('prevCrit');
        const wEl = document.getElementById('prevWarn');
        if (cEl) cEl.textContent = crit;
        if (wEl) wEl.textContent = warn;
    }
    ['lead_time_days','safety_stock_days','order_cycle_days'].forEach(n =>
        form.querySelector('[name='+n+']')?.addEventListener('input', calcWarn));

    // Submit via AJAX
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(form);
        const data = Object.fromEntries(fd.entries());

        fetch('{{ route("inventory.stocks.set-store-par") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
            },
            body: JSON.stringify(data),
        })
        .then(r => r.ok ? r.json() : r.json().then(e => Promise.reject(e)))
        .then(res => {
            // Reload supaya angka di tabel ikut update (threshold DOS berubah)
            window.location.reload();
        })
        .catch(err => {
            alert('Gagal simpan: ' + (err?.message || JSON.stringify(err)));
        });
    });
})();
</script>
@endpush

@push('styles')
<style>
.stock-table { font-size: .82rem; table-layout: fixed; }
.stock-table th { white-space: nowrap; font-size: .72rem; padding: .4rem .5rem; }
.stock-table td { padding: .35rem .5rem; overflow: hidden; text-overflow: ellipsis; }
/* app.css punya `.table-sm tbody td { font-size: var(--fs-sm) }` yang menyetel ukuran
   LANGSUNG di td, jadi mengalahkan pewarisan dari .stock-table. Selector ini dibuat
   lebih spesifik supaya menang — naik 1 tingkat skala: --fs-sm (12px) → --fs-base (13px).
   Nama bahan & angka Dus/Pack sama-sama ikut aturan ini, jadi ukurannya tetap seragam. */
.stock-table.table-sm tbody td { font-size: var(--fs-base); }
/* Angka Dus/Pack = data utama → dibuat lebih menonjol dari teks sel lain.
   Ukuran dipasang di span-nya sendiri, jadi menang atas warisan dari td. */
.stock-table .stock-qty { font-size: var(--fs-md); line-height: 1.2; }

/* ── MOBILE ───────────────────────────────────────────────────────────────
   Masalah: .stock-table pakai table-layout:fixed + lebar kolom PERSEN, jadi
   tabel MENGKERUT mengikuti layar dan tidak pernah meluber → .table-responsive
   tak pernah scroll. Di layar 375px kolom Bahan cuma ~82px (nama terlipat) dan
   kolom DOS ~52px padahal padding-left-nya 92px (isi terdorong keluar/terpotong).
   Solusi: beri min-width supaya tabel benar-benar bisa di-scroll horizontal
   dengan proporsi kolom yang wajar, dan netralkan padding DOS khusus di HP.
   Semua kolom tetap utuh → baris kategori & subtotal (pakai colspan) aman. */
@media (max-width: 768px) {
    .stock-table { min-width: 760px; }
    .stock-table thead th:nth-child(6),
    .stock-table td:nth-child(6) { padding-left: .5rem !important; }
    /* beri isyarat bahwa tabel bisa digeser */
    .table-responsive { -webkit-overflow-scrolling: touch; }
}
.stock-table .category-header td {
    background: #f3f4f6; padding: .25rem .6rem;
    border-top: 1px solid #dee2e6; border-bottom: 1px solid #dee2e6;
}
.stock-table .subtotal-row td {
    background: #fafbfc; padding: .25rem .5rem;
    border-bottom: 1px solid #dee2e6;
}
.stock-table tbody tr:not(.category-header):not(.subtotal-row):hover {
    background: #f8fafc;
}
.stock-table tr.ing-main-row > td {
    border-bottom: 1px solid #e2e8f0;
}
.stock-table tr.pkg-detail-row {
    background: #f8fafc;
}
.stock-table tr.pkg-detail-row > td {
    border-top: 1px dashed #cbd5e1;
    font-size: .78rem;
    color: #475569;
}
.stock-table .toggle-pkg {
    border: none;
    background: transparent;
    padding: 0;
    line-height: 1;
}
.stock-table .toggle-pkg:hover i {
    color: #0d6efd !important;
}
.stock-table .text-muted-cell {
    background: rgba(0,0,0,.015);
}
.stock-table .stock-empty {
    color: #94a3b8;
}
.tooltip-inner {
    white-space: pre-line;
    text-align: left;
    max-width: 320px;
}
</style>
@endpush

@endsection
