@extends('layouts.app')
@section('title', 'Pencatatan Harian')

@section('content')
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h4 class="page-title">Pencatatan Harian</h4>
        <p class="text-muted small mb-0">Rekap pemakaian &amp; pembelian bahan per hari</p>
    </div>
    @if(request('store_id'))
    <div class="d-flex gap-2">
        @php
            $tplParams = ['store_id' => request('store_id'), 'month' => request('month', date('n')), 'year' => request('year', date('Y'))];
        @endphp
        <a href="{{ route('inventory.daily-ledger.export-template', $tplParams) }}"
           class="btn btn-outline-success">
            <i class="bi bi-file-earmark-excel me-1"></i>Download Template
        </a>
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalImportUsage">
            <i class="bi bi-upload me-1"></i>Import Excel
        </button>
    </div>
    @endif
</div>

{{-- ═══════════ MODAL IMPORT EXCEL ═══════════ --}}
@if(request('store_id'))
<div class="modal fade" id="modalImportUsage" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('inventory.daily-ledger.import-usage') }}" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="store_id" value="{{ request('store_id') }}">
            <input type="hidden" name="month"    value="{{ request('month', date('n')) }}">
            <input type="hidden" name="year"     value="{{ request('year', date('Y')) }}">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Import Pemakaian Harian</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small py-2">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Cara pakai:</strong>
                        <ol class="mb-0 mt-1 ps-3">
                            <li>Klik <strong>"Download Template"</strong> dulu untuk dapat file Excel</li>
                            <li>Buka file di Excel, isi qty pemakaian di kolom tanggal (1–31)</li>
                            <li>Simpan file, lalu upload di sini</li>
                            <li>Cell kosong = tidak ada pemakaian (data lama yang sudah ada akan dihapus)</li>
                        </ol>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Pilih File Excel <span class="text-danger">*</span></label>
                        <input type="file" name="file" class="form-control" accept=".xlsx,.xls" required>
                        <div class="form-text">Format: .xlsx atau .xls — pastikan struktur kolom sama dengan template.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload me-1"></i>Upload &amp; Import
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
@endif

{{-- FORM FILTER --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end" id="ledgerFilterForm">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Toko</label>
                <select name="store_id" class="form-select form-select-sm" required>
                    <option value="">— Pilih Toko —</option>
                    @foreach($stores as $s)
                        <option value="{{ $s->id }}" {{ request('store_id') == $s->id ? 'selected' : '' }}>
                            {{ $s->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Bulan</label>
                <select name="month" class="form-select form-select-sm">
                    @foreach(['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'] as $i => $bln)
                        <option value="{{ $i+1 }}" {{ request('month', date('n')) == $i+1 ? 'selected' : '' }}>{{ $bln }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Tahun</label>
                <select name="year" class="form-select form-select-sm">
                    @for($y = date('Y'); $y >= date('Y')-3; $y--)
                        <option value="{{ $y }}" {{ request('year', date('Y')) == $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
            </div>
            {{-- Ditampilkan lewat tombol (bukan auto-submit saat ganti filter) supaya
                 tidak memuat ulang halaman berat ini setiap kali dropdown disentuh. --}}
            {{-- Didorong ke paling kanan (ms-auto); tinggi disamakan dgn kotak isian --}}
            <div class="col-md-auto ms-md-auto">
                <button type="submit" class="btn btn-primary btn-sm px-4" style="height:36px">
                    <i class="bi bi-search me-1"></i>Tampilkan
                </button>
            </div>
        </form>
    </div>
</div>

@if($tableData === false)
    <div class="text-center py-5 text-muted">
        <i class="bi bi-calendar3 fs-1 d-block mb-2"></i>
        Pilih toko, bulan, dan tahun untuk menampilkan data.
    </div>

@elseif(count($tableData) === 0)
    <div class="alert alert-info">Tidak ada data untuk periode ini.</div>

@else
@php
    $monthNames = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

    // Helper: base → DUS (1 desimal jika ada sisa)
    $toDus = function($base, $packaging) {
        if ($base <= 0) return '';
        if (!$packaging || !$packaging->crate_to_pack || !$packaging->pack_to_base) return '';
        $ctb = $packaging->crate_to_pack * $packaging->pack_to_base;
        if ($ctb <= 0) return '';
        $v = $base / $ctb;
        // Tetap tampilkan nilai kecil (mis. waste 1 pack dari dus besar) — pakai 2 desimal.
        return $v >= 0.005 ? round($v, 2) : '';
    };

    // Helper: base → DUS+PACK array untuk stok awal/akhir
    $toDusPack = function($base, $packaging) {
        // Tangani negatif: hitung pada nilai mutlak lalu beri tanda minus.
        $neg = $base < 0;
        $b   = abs($base);
        if (!$packaging || !$packaging->crate_to_pack || !$packaging->pack_to_base) {
            $p = (int)round($b);
            return ['dus' => 0, 'pack' => $neg ? -$p : $p, 'base' => 0.0];
        }
        $ctb  = $packaging->crate_to_pack * $packaging->pack_to_base;
        if ($ctb <= 0) {
            $p = (int)round($b);
            return ['dus' => 0, 'pack' => $neg ? -$p : $p, 'base' => 0.0];
        }
        $dus  = (int)floor($b / $ctb);
        $pack = (int)floor(($b - $dus * $ctb) / $packaging->pack_to_base);
        $rem  = $b - $dus * $ctb - $pack * $packaging->pack_to_base;
        return ['dus' => $neg ? -$dus : $dus, 'pack' => $neg ? -$pack : $pack,
                'base' => $neg ? -$rem : $rem];
    };

    $sectionLabels = [
        'zhisheng' => 'PEMBELIAN ZHISHENG',
        'supplier' => 'PEMBELIAN SUPPLIER',
        'int_in'   => 'PEMBELIAN INTERNAL',
        'int_out'  => 'PENJUALAN INTERNAL',
        'waste'    => 'WASTE',
    ];

    $sectionH1 = [
        'zhisheng' => '#1a4a6b', 'supplier' => '#1a4a6b',
        'int_in'   => '#1a4a6b', 'int_out'  => '#7b2d3a',
        'waste'    => '#6d3a00',
    ];
    $sectionH2 = [
        'zhisheng' => '#2980b9', 'supplier' => '#2980b9',
        'int_in'   => '#2980b9', 'int_out'  => '#c0392b',
        'waste'    => '#c06a00',
    ];
    $sectionCell = [
        'zhisheng' => '#e8f4fd', 'supplier' => '#e8f4fd',
        'int_in'   => '#e8f4fd', 'int_out'  => '#fde8ea',
        'waste'    => '#fff3e0',
    ];

    $saveUrl = route('inventory.daily-ledger.save-usage');
    $csrf    = csrf_token();
@endphp

{{-- JUDUL --}}
<div class="mb-2 d-flex justify-content-between align-items-center">
    <div class="text-muted small">
        Toko: <strong>{{ $store->name }}</strong> &nbsp;|&nbsp;
        Periode: <strong>{{ $monthNames[$month] }} {{ $year }}</strong>
        @if($prevOpname)
            &nbsp;|&nbsp;
            <span class="badge bg-success">
                <i class="bi bi-clipboard-check me-1"></i>
                Stok awal dari SO {{ \Carbon\Carbon::create($prevOpname->period_year, $prevOpname->period_month, 1)->isoFormat('MMMM Y') }}
            </span>
        @else
            &nbsp;|&nbsp;
            <span class="badge bg-secondary">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Tidak ada opname akhir bulan lalu
            </span>
        @endif
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span class="text-muted small d-none d-md-inline" title="Klik-tahan lalu seret untuk memilih banyak sel, tekan Delete untuk mengosongkan. Esc untuk batal.">
            <i class="bi bi-grid-3x3-gap me-1"></i>Seret pilih blok · Delete = hapus
        </span>
        <span id="saveStatus" class="text-muted small"></span>
        <button id="btnToggleReorder" type="button" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-arrows-move me-1"></i> Atur Urutan
        </button>
        <button id="btnResetOrder" type="button" class="btn btn-outline-secondary btn-sm" title="Reset ke urutan default (kategori)">
            <i class="bi bi-arrow-counterclockwise"></i>
        </button>
    </div>
</div>

{{-- Banner lock / grace period / extension --}}
@if($approvedExtension)
<div class="alert alert-success py-2 small mb-2 d-flex align-items-center gap-2">
    <i class="bi bi-unlock-fill"></i>
    <span>
        Perpanjangan edit <strong>disetujui</strong> — data <strong>{{ $monthNames[$month] }} {{ $year }}</strong>
        dapat diedit hingga <strong>{{ $approvedExtension->new_lock_until->isoFormat('D MMMM Y') }}</strong>.
    </span>
</div>
@elseif($isLocked)
    @if($editRequest && $editRequest->isPending())
    <div class="alert alert-info py-2 small mb-2 d-flex align-items-center gap-2">
        <i class="bi bi-hourglass-split"></i>
        <span>
            Data <strong>{{ $monthNames[$month] }} {{ $year }}</strong> terkunci.
            Request perpanjangan <strong>sedang menunggu persetujuan</strong> Super Admin
            (+{{ $editRequest->extra_days }} hari diminta).
        </span>
    </div>
    @elseif($editRequest && $editRequest->isRejected())
    <div class="alert alert-danger py-2 small mb-2 d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-lock-fill me-1"></i>
            Data <strong>{{ $monthNames[$month] }} {{ $year }}</strong> terkunci.
            Request sebelumnya <strong>ditolak</strong>
            @if($editRequest->admin_notes) — "{{ $editRequest->admin_notes }}"@endif.
        </span>
        <button class="btn btn-outline-danger btn-sm ms-3 flex-shrink-0"
                data-bs-toggle="modal" data-bs-target="#modalRequestEdit">
            <i class="bi bi-send me-1"></i>Request Lagi
        </button>
    </div>
    @else
    <div class="alert alert-secondary py-2 small mb-2 d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-lock-fill me-1"></i>
            Data <strong>{{ $monthNames[$month] }} {{ $year }}</strong> sudah <strong>terkunci</strong>
            (batas edit {{ $lastEditDay->isoFormat('D MMMM Y') }}).
            Ada kebutuhan mendesak?
        </span>
        <button class="btn btn-outline-secondary btn-sm ms-3 flex-shrink-0"
                data-bs-toggle="modal" data-bs-target="#modalRequestEdit">
            <i class="bi bi-send me-1"></i>Request Perpanjangan
        </button>
    </div>
    @endif
@endif

{{-- Modal Request Perpanjangan Edit --}}
@if($isLocked && (!$editRequest || $editRequest->isRejected()))
<div class="modal fade" id="modalRequestEdit" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('inventory.daily-ledger.request-extension') }}">
            @csrf
            <input type="hidden" name="store_id" value="{{ $storeId }}">
            <input type="hidden" name="month"    value="{{ $month }}">
            <input type="hidden" name="year"     value="{{ $year }}">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-send me-1"></i>Request Perpanjangan Edit
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light py-2 small mb-3">
                        Data <strong>{{ $monthNames[$month] }} {{ $year }}</strong> — Toko <strong>{{ $store->name }}</strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">
                            Tambahan hari yang diminta <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="extra_days" class="form-control"
                                   min="1" max="30" placeholder="mis. 3" required>
                            <span class="input-group-text">hari</span>
                        </div>
                        <div class="form-text">Maksimal 30 hari tambahan.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">
                            Alasan / Keperluan <span class="text-danger">*</span>
                        </label>
                        <textarea name="reason" class="form-control form-control-sm" rows="3"
                            placeholder="Jelaskan kebutuhan edit data ini..." required maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-send me-1"></i>Kirim Request
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
@endif

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0 daily-ledger-table" data-store="{{ $storeId }}">
                <thead>
                    {{-- ROW 1: section group headers --}}
                    <tr class="text-center" style="font-size:0.68rem;font-weight:700">
                        <th rowspan="2" class="align-middle sticky-col" style="min-width:150px;background:#1a3a5c;color:#fff">NAMA BAHAN</th>
                        <th colspan="2" style="background:#1a6b3c;color:#fff">STOK AWAL</th>
                        <th colspan="{{ $daysInMonth + 1 }}" style="background:#c0392b;color:#fff">PEMAKAIAN HARIAN (PACK)</th>
                        <th colspan="2" style="background:#1a6b3c;color:#fff"
                            title="Dus dihitung dengan konversi standar (kemasan pertama bahan). Untuk rincian per kemasan fisik, lihat halaman Saldo Stok.">
                            STOK AKHIR <i class="bi bi-info-circle" style="font-size:.7rem;opacity:.7"></i>
                        </th>
                        @foreach($sectionLabels as $key => $label)
                            @if(count($activeDays[$key]) > 0)
                                <th colspan="{{ count($activeDays[$key]) }}" class="dl-sec-head"
                                    style="background:{{ $sectionH1[$key] }};color:#fff">
                                    {{ $label }} (DUS)
                                </th>
                            @endif
                        @endforeach
                    </tr>
                    {{-- ROW 2: sub-headers --}}
                    <tr class="text-center" style="font-size:0.63rem;font-weight:600">
                        <th style="background:#27ae60;color:#fff;width:46px">DUS</th>
                        <th style="background:#27ae60;color:#fff;width:46px">PACK</th>
                        @for($d = 1; $d <= $daysInMonth; $d++)
                        @php
                            $isConfirmed = isset($confirmedDates[$d]);
                            $dateStr     = sprintf('%04d-%02d-%02d', $year, $month, $d);
                        @endphp
                        <th class="{{ $isLocked ? '' : 'confirm-date-th' }}"
                            data-date="{{ $dateStr }}"
                            data-store="{{ $storeId }}"
                            data-confirmed="{{ $isConfirmed ? '1' : '0' }}"
                            style="background:{{ $isConfirmed ? '#1a7a3c' : '#e74c3c' }};color:#fff;min-width:30px;{{ $isLocked ? 'cursor:not-allowed;opacity:0.75' : 'cursor:pointer' }};user-select:none"
                            title="{{ $isLocked ? 'Data terkunci' : ($isConfirmed ? 'Sudah dikonfirmasi — klik untuk batalkan' : 'Klik untuk konfirmasi tgl '.$d) }}">
                            {{ $d }}
                            <div class="confirm-icon" style="font-size:0.55rem;line-height:1.2">{{ $isConfirmed ? '✓' : '·' }}</div>
                        </th>
                        @endfor
                        <th style="background:#922b21;color:#fff">TOT</th>
                        <th style="background:#27ae60;color:#fff;width:46px">DUS</th>
                        <th style="background:#27ae60;color:#fff;width:46px">PACK</th>
                        @foreach($sectionLabels as $key => $label)
                            @foreach($activeDays[$key] as $d)
                                <th class="dl-sec-col" style="background:{{ $sectionH2[$key] }};color:#fff">{{ $d }}</th>
                            @endforeach
                        @endforeach
                    </tr>
                </thead>
                {{-- Satu tbody berisi semua baris: tiap baris (bahan × kemasan) bisa
                     diatur urutannya sendiri, tidak lagi terikat per-bahan. --}}
                <tbody class="dl-body" style="font-size:0.7rem">
                    @foreach($tableRows as $trow)
                        @php
                            $ingId   = $trow['ing_id'];
                            $pkgId   = $trow['pkg_id'];
                            $ing     = $ingredients[$ingId];
                            $pkg     = $trow['packaging'];
                            $isFirst = $trow['is_first'];
                            $row     = $tableData[$ingId];
                            $ptb     = $pkg ? (float)$pkg->pack_to_base : 1;
                            $ctb     = $pkg ? (float)($pkg->crate_to_pack * $pkg->pack_to_base) : 0;
                            $multiPkg = $ing->packagings->count() > 1;
                            // Bahan yang dipasok >1 supplier: tampilkan nama supplier di SEMUA
                            // kemasannya (bukan hanya yang isinya kembar) supaya konsisten & jelas
                            // baris mana milik supplier mana.
                            $ambiguousPkg = $pkg && $multiPkg
                                && $ing->packagings->pluck('supplier_id')->unique()->count() > 1;

                            // Total pemakaian baris ini (pack)
                            $totalPack = collect($trow['days'])->sum('pemakaian');

                            // pkgKey: kunci packaging untuk lookup di tableData
                            $pkgKey = $pkgId ? (string)$pkgId : 'null';

                            // Stok Awal PER KEMASAN (tiap baris punya stok awalnya sendiri).
                            // Bila cocok dgn rincian opname (belum ada pergerakan), tampilkan
                            // apa adanya — gram/pcs longgar tidak dipromosikan jadi pack.
                            $opening = $toDusPack($trow['opening_base'], $pkg);
                            // totalInBase = net mutasi masuk-keluar bulan ini (semua pkg, hanya utk fallback closing bulan lalu)
                            $totalInBase = $isFirst
                                ? collect($row['days'])->sum(fn($d) =>
                                    array_sum($d['zhisheng']) + array_sum($d['supplier']) + array_sum($d['int_in'])
                                    - array_sum($d['int_out']))
                                : 0;

                            // ── Stok akhir per KEMASAN (boleh MINUS, pemakaian tdk pengaruhi kemasan lain) ──
                            $usageBase = $totalPack * $ptb;
                            $closingKey = $pkgId ?? 0;
                            if (isset($closingBreakdown[$ingId][$closingKey])) {
                                // Bulan berjalan atau sudah ada opname end_month: pakai snapshot
                                $closingBase = (float) $closingBreakdown[$ingId][$closingKey];
                                $availBase   = $closingBase + $usageBase; // utk recompute live di JS
                            } else {
                                // Bulan lain: alur bulanan per kemasan (opening hanya di baris pertama)
                                $inPkg = 0.0; $outPkg = 0.0; $wastePkg = 0.0;
                                foreach ($row['days'] as $dd) {
                                    $inPkg    += ($dd['zhisheng'][$pkgKey] ?? 0) + ($dd['supplier'][$pkgKey] ?? 0) + ($dd['int_in'][$pkgKey] ?? 0);
                                    $outPkg   += ($dd['int_out'][$pkgKey] ?? 0);
                                    $wastePkg += ($dd['waste'][$pkgKey] ?? 0);
                                }
                                $availBase   = (float)$trow['opening_base'] + $inPkg - $outPkg - $wastePkg;
                                $closingBase = $availBase - $usageBase;
                            }
                            $closing = $toDusPack($closingBase, $pkg);
                        @endphp

                        <tr data-avail="{{ $availBase }}"
                            data-ptb="{{ $ptb }}"
                            data-ctb="{{ $ctb }}"
                            data-ing="{{ $ingId }}"
                            data-pkg="{{ $pkgId ?? '' }}"
                            data-unit="{{ $ing->unit_base }}"
                            data-is-first="{{ $isFirst ? '1' : '0' }}">

                            {{-- Nama Bahan / Kemasan --}}
                            <td class="sticky-col" style="background:#fff;font-size:0.7rem">
                                {{-- Gagang drag di SETIAP baris: tiap kemasan bisa diatur sendiri --}}
                                <span class="drag-handle d-none me-1" style="cursor:grab;color:#999" title="Geser untuk atur urutan">
                                    <i class="bi bi-grip-vertical"></i>
                                </span>
                                <span class="fw-semibold">{{ $ing->name }}</span>
                                @if($pkg && $multiPkg && $pkg->crate_to_pack)
                                    <span class="text-muted" style="font-size:0.62rem">{{ '@'.$pkg->crate_to_pack }} pack</span>
                                @endif
                                @if($ambiguousPkg)
                                    <span class="badge align-middle ms-1"
                                          style="background:#e0f2fe;color:#0369a1;font-size:0.58rem;padding:2px 5px;font-weight:600"
                                          title="Supplier kemasan ini">
                                        <i class="bi bi-truck" style="font-size:0.55rem"></i>
                                        {{ $pkg->supplier->name ?? 'Tanpa supplier' }}
                                    </span>
                                @endif
                            </td>

                            {{-- Stok Awal per kemasan --}}
                            <td class="text-center" style="background:#eafaf1">{!! $opening['dus'] ?: '<span class="text-muted opacity-50 small">-</span>' !!}</td>
                            <td class="text-center" style="background:#eafaf1">{!! $opening['pack'] ?: '<span class="text-muted opacity-50 small">-</span>' !!}</td>

                            {{-- Pemakaian per hari — EDITABLE (lazy: input dibuat saat sel difokus) --}}
                            @for($d = 1; $d <= $daysInMonth; $d++)
                                @php $val = $trow['days'][$d]['pemakaian']; @endphp
                                <td class="td-usage-cell{{ $val > 0 ? ' has-val' : '' }}"
                                    tabindex="{{ $isLocked ? '-1' : '0' }}"
                                    data-date="{{ sprintf('%04d-%02d-%02d', $year, $month, $d) }}"
                                    data-val="{{ $val > 0 ? (int)$val : '' }}">{{ $val > 0 ? (int)$val : '' }}</td>
                            @endfor

                            {{-- Total pemakaian baris ini --}}
                            <td class="text-center fw-bold td-total" style="background:#fad7d7">
                                {{ $totalPack > 0 ? (int)$totalPack : '' }}
                            </td>

                            {{-- Stok Akhir: per kemasan bila stok awal per-kemasan (bulan berjalan
                                 atau ada opname bulan lalu); hanya baris pertama bila pakai carry-over. --}}
                            @if($isCurrentMonth || $prevOpname || $isFirst)
                                <td class="text-center td-closing-dus" style="background:#eafaf1">{!! $closing['dus'] ?: '<span class="text-muted opacity-50 small">-</span>' !!}</td>
                                <td class="text-center td-closing-pack" style="background:#eafaf1">{!! $closing['pack'] ?: '<span class="text-muted opacity-50 small">-</span>' !!}</td>
                            @else
                                <td style="background:#f8f9fa"></td>
                                <td style="background:#f8f9fa"></td>
                            @endif

                            {{-- Pembelian/Penjualan sparse — per baris packaging --}}
                            @foreach($sectionLabels as $key => $label)
                                @foreach($activeDays[$key] as $d)
                                    @php $v = $toDus($row['days'][$d][$key][$pkgKey] ?? 0, $pkg); @endphp
                                    <td class="text-center dl-sec-col" style="{{ $v ? 'background:'.$sectionCell[$key] : '' }}">{{ $v }}</td>
                                @endforeach
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Border separator antar ingredient group --}}
<style>
    .daily-ledger-table.reorder-mode tbody.dl-body > tr { cursor: grab; }
    .daily-ledger-table.reorder-mode tbody.dl-body > tr:hover { background: #fff8e1; }
    .daily-ledger-table.reorder-mode .drag-handle { display: inline-block !important; }
    .sortable-ghost { opacity: 0.4; }
</style>
@endif
@endsection

@push('styles')
<style>
.daily-ledger-table th, .daily-ledger-table td {
    padding: 2px 4px !important;
    white-space: nowrap;
    border-color: #ccc !important;
    vertical-align: middle;
}
.daily-ledger-table .sticky-col {
    position: sticky;
    left: 0;
    z-index: 2;
    border-right: 2px solid #666 !important;
}
.daily-ledger-table thead .sticky-col { z-index: 3; }
/* Kolom seksi (Pembelian/Penjualan/Waste): lebarnya dikunci seragam.
   Tanpa ini lebar kolom ditarik oleh judul seksi di atasnya — seksi berjudul
   panjang tapi berkolom sedikit (mis. "WASTE (DUS)" dgn 1 tanggal) jadi melebar,
   sementara seksi berkolom banyak jadi sempit. */
.daily-ledger-table .dl-sec-col {
    width: 44px;
    min-width: 44px;
    max-width: 44px;
    text-align: center;
}
/* Judul seksi TIDAK boleh mendorong lebar kolom di bawahnya.
   Aturan umum tabel ini memakai white-space:nowrap — itu memaksa judul panjang
   (mis. "PEMBELIAN ZHISHENG (DUS)") jadi satu baris, sehingga kolom di bawahnya
   ikut melebar. Di sini dibolehkan membungkus (wrap) supaya lebar kolom tetap 44px. */
.daily-ledger-table .dl-sec-head {
    white-space: normal !important;
    word-break: break-word;
    font-size: 0.58rem;
    line-height: 1.15;
    padding: 2px 3px !important;
}
/* Sel pemakaian (lazy): tampil sebagai teks, jadi input saat difokus */
.daily-ledger-table .td-usage-cell {
    text-align: center;
    cursor: text;
    padding: 2px 1px !important;
    min-width: 30px;
}
.daily-ledger-table .td-usage-cell.has-val { background: #fdecea; }
.daily-ledger-table .td-usage-cell:focus { outline: 2px solid #3498db; background: #ebf5fb; }
/* Seleksi blok ala Excel */
.daily-ledger-table .td-usage-cell.dl-selected { background: #cfe8ff !important; box-shadow: inset 0 0 0 1px #2980b9; }
body.dl-noselect, body.dl-noselect * { -webkit-user-select: none !important; user-select: none !important; }
.usage-input {
    width: 100%;
    border: none;
    background: transparent;
    text-align: center;
    font-size: 0.7rem;
    padding: 0;
    -moz-appearance: textfield;
}
.usage-input:focus { outline: none; }
/* Overstock alert */
.input-overstock {
    background: #f8d7da !important;
    outline: 2px solid #dc3545 !important;
}
.overstock-alert {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 4px;
    padding: 4px 8px;
    font-size: 0.72rem;
    color: #856404;
}
.confirm-date-th:hover { opacity: 0.85; }
@media print {
    .page-header, .card:first-of-type { display: none !important; }
    .table-responsive { overflow: visible !important; }
    .daily-ledger-table { font-size: 7pt !important; }
    .sticky-col { position: static !important; }
    .usage-input { border: none !important; }
}
</style>
@endpush

@push('scripts')
<script>
var saveUrl       = '{{ route("inventory.daily-ledger.save-usage") }}';
var bulkDeleteUrl = '{{ route("inventory.daily-ledger.bulk-delete-usage") }}';
var confirmUrl    = '{{ route("inventory.daily-ledger.confirm-date") }}';
var csrfToken  = '{{ csrf_token() }}';
var saveTimers = {};

// Transfer yang terdampak tapi TIDAK bisa disegarkan otomatis (periode terkunci).
// Ditampilkan lewat uiAlert — bukan toast singkat — supaya tidak terlewat. Dipakai
// bareng oleh simpan sel, hapus massal, dan toggle konfirmasi tanggal.
function laporTransferTerkunci(locked) {
    if (!locked || !locked.length || !window.uiAlert) return;
    window.uiAlert(
        'Harga transfer berikut TIDAK ikut disegarkan otomatis karena periodenya sudah terkunci:\n' +
        locked.join('\n') +
        '\n\nPeriksa manual bila perlu (Batalkan Konfirmasi → Konfirmasi ulang di halaman Mutasi).',
        { type: 'warning', title: 'Beberapa transfer perlu dicek manual' }
    );
}

// ── Lazy input: sel pemakaian tampil sebagai teks; <input> dibuat hanya saat difokus.
//    Ini memangkas ribuan input dari DOM → load jauh lebih cepat & scroll tidak nge-lag.
var ledgerTable = document.querySelector('.daily-ledger-table');
if (ledgerTable) {
    var ledgerStore = ledgerTable.dataset.store;

    function makeEditable(td) {
        if (td.querySelector('input')) return;
        var val   = td.dataset.val || '';
        var input = document.createElement('input');
        input.type         = 'text';
        input.className    = 'usage-input';
        input.value        = val;
        input.autocomplete = 'off';
        input.setAttribute('inputmode', 'numeric');
        td.textContent = '';
        td.appendChild(input);
        input.focus();
        input.select();
    }

    // FOCUS sel → jadikan input
    ledgerTable.addEventListener('focusin', function(e) {
        var el = e.target;
        if (el.classList.contains('usage-input')) return;
        var td = el.closest('.td-usage-cell');
        if (!td || td.getAttribute('tabindex') === '-1') return;
        makeEditable(td);
    });

    // INPUT: buang karakter non-angka, update stok akhir realtime
    ledgerTable.addEventListener('input', function(e) {
        var el = e.target;
        if (!el.classList.contains('usage-input')) return;
        var clean = el.value.replace(/[^0-9]/g, '');
        if (el.value !== clean) el.value = clean;
        updateRowSummary(el.closest('tr'));
    });

    // CHANGE (value berubah saat blur/enter): simpan ke server
    ledgerTable.addEventListener('change', function(e) {
        var el = e.target;
        if (!el.classList.contains('usage-input')) return;
        saveUsage(el);
    });

    // BLUR: kembalikan sel jadi teks biasa + recompute ringkasan baris
    ledgerTable.addEventListener('focusout', function(e) {
        var el = e.target;
        if (!el.classList.contains('usage-input')) return;
        var td = el.closest('.td-usage-cell');
        if (!td) return;
        var tr   = td.closest('tr');
        var v    = (el.value || '').replace(/[^0-9]/g, '');
        var show = (parseFloat(v) || 0) > 0 ? v : '';
        td.dataset.val = show;
        td.classList.toggle('has-val', show !== '');
        td.textContent = show;
        updateRowSummary(tr); // pastikan TOT & stok akhir selalu sinkron saat keluar sel
    });

    // NAVIGASI ala Excel: Enter/panah pindah sel (bukan mengubah angka).
    //   ↑/↓ & Enter → baris atas/bawah pada kolom tanggal yang sama
    //   ←/→        → tanggal sebelum/berikutnya pada baris yang sama
    //                (hanya pindah bila kursor sudah di ujung teks, biar tetap bisa edit)
    ledgerTable.addEventListener('keydown', function(e) {
        var el = e.target;
        if (!el.classList.contains('usage-input')) return;
        var key = e.key;
        if (key !== 'Enter' && key !== 'ArrowUp' && key !== 'ArrowDown'
            && key !== 'ArrowLeft' && key !== 'ArrowRight') return;

        var td = el.closest('.td-usage-cell');
        if (!td) return;

        // Kiri/kanan: biarkan kursor bergerak dulu selama belum di ujung teks
        var caret = (typeof el.selectionStart === 'number') ? el.selectionStart : 0;
        if (key === 'ArrowLeft'  && caret > 0) return;
        if (key === 'ArrowRight' && caret < (el.value || '').length) return;

        var list, idx, step;
        if (key === 'Enter' || key === 'ArrowDown' || key === 'ArrowUp') {
            list = Array.from(ledgerTable.querySelectorAll('.td-usage-cell[data-date="' + td.dataset.date + '"]'));
            step = (key === 'ArrowUp') ? -1 : 1;
        } else {
            list = Array.from(td.closest('tr').querySelectorAll('.td-usage-cell'));
            step = (key === 'ArrowLeft') ? -1 : 1;
        }
        idx = list.indexOf(td);

        // Cari sel berikutnya yang bisa diedit (lewati yang terkunci)
        var target = null;
        for (var i = idx + step; i >= 0 && i < list.length; i += step) {
            if (list[i].getAttribute('tabindex') !== '-1') { target = list[i]; break; }
        }

        e.preventDefault();                 // cegah scroll & perubahan nilai bawaan
        if (target) target.focus();         // blur sel kini → focusin sel tujuan → jadi input
    });

    // Simpan satu sel ke server. Dipakai edit tunggal (via saveUsage) & hapus massal.
    function postUsage(td, qtyPack) {
        var tr      = td.closest('tr');
        var date    = td.dataset.date;
        var ingId   = tr.dataset.ing;
        var pkg     = tr.dataset.pkg || null;
        var newVal  = qtyPack > 0 ? String(qtyPack) : '';
        var prevVal = td.dataset.val;
        var key     = ingId + date;

        td.dataset.val = newVal;
        td.classList.toggle('has-val', qtyPack > 0);

        var status = document.getElementById('saveStatus');
        clearTimeout(saveTimers[key]);
        status.textContent = 'Menyimpan...';

        saveTimers[key] = setTimeout(function() {
            fetch(saveUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    store_id:      ledgerStore,
                    ingredient_id: ingId,
                    packaging_id:  pkg,
                    date:          date,
                    qty_pack:      qtyPack
                })
            })
            .then(function(r) { return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
            .then(function(res) {
                if (!res.ok || (res.data && res.data.error)) {
                    status.textContent = '⚠ ' + ((res.data && res.data.error) || 'Gagal simpan');
                    td.dataset.val = prevVal;
                    td.classList.toggle('has-val', (parseFloat(prevVal) || 0) > 0);
                    var live = td.querySelector('input');
                    if (live) live.value = prevVal; else td.textContent = prevVal;
                    updateRowSummary(tr);
                    return;
                }
                var nFix = ((res.data && res.data.fixed) || []).length;
                status.textContent = nFix > 0
                    ? 'Tersimpan ✓ · ' + nFix + ' transfer disegarkan otomatis'
                    : 'Tersimpan ✓';
                setTimeout(function() { status.textContent = ''; }, nFix > 0 ? 4000 : 1500);
                laporTransferTerkunci(res.data && res.data.locked);
            })
            .catch(function() { status.textContent = '⚠ Gagal simpan'; });
        }, 500);
    }

    function saveUsage(input) {
        postUsage(input.closest('.td-usage-cell'), parseFloat(input.value) || 0);
    }

    // ── Seleksi blok ala Excel + hapus massal ─────────────────────────────
    // Seret (klik-tahan) untuk memilih blok sel, tekan Delete/Backspace untuk
    // mengosongkan semuanya sekaligus. Esc untuk batal seleksi.
    var selCells = new Set();
    var selAnchor = null, mouseDownCell = null, isDragging = false, rowCache = null;

    function usageRows() {
        if (!rowCache) rowCache = Array.from(ledgerTable.querySelectorAll('tr'))
            .filter(function(tr){ return tr.querySelector('.td-usage-cell'); });
        return rowCache;
    }
    function cellPos(td) {
        var tr = td.closest('tr');
        return { r: usageRows().indexOf(tr),
                 c: Array.from(tr.querySelectorAll('.td-usage-cell')).indexOf(td) };
    }
    function clearSelection() {
        selCells.forEach(function(td){ td.classList.remove('dl-selected'); });
        selCells.clear();
    }
    function selectRange(anchor, target) {
        clearSelection();
        var a = cellPos(anchor), b = cellPos(target);
        var r1 = Math.min(a.r, b.r), r2 = Math.max(a.r, b.r);
        var c1 = Math.min(a.c, b.c), c2 = Math.max(a.c, b.c);
        var rws = usageRows();
        for (var r = r1; r <= r2; r++) {
            var cells = Array.from(rws[r].querySelectorAll('.td-usage-cell'));
            for (var c = c1; c <= c2; c++) {
                var td = cells[c];
                if (td && td.getAttribute('tabindex') !== '-1') { td.classList.add('dl-selected'); selCells.add(td); }
            }
        }
    }

    ledgerTable.addEventListener('mousedown', function(e) {
        if (e.button !== 0) return;
        var td = e.target.closest('.td-usage-cell');
        if (!td || td.getAttribute('tabindex') === '-1') return;
        mouseDownCell = td;
        isDragging = false;
    });
    ledgerTable.addEventListener('mouseover', function(e) {
        if (!mouseDownCell) return;
        var td = e.target.closest('.td-usage-cell');
        if (!td || (!isDragging && td === mouseDownCell)) return;
        if (!isDragging) {
            isDragging = true;
            selAnchor  = mouseDownCell;
            document.body.classList.add('dl-noselect');
            var inp = mouseDownCell.querySelector('input');
            if (inp) inp.blur();                                  // batalkan mode edit sel awal
            var s = window.getSelection && window.getSelection(); if (s) s.removeAllRanges();
        }
        selectRange(selAnchor, td);
    });
    document.addEventListener('mouseup', function() {
        if (mouseDownCell && !isDragging) clearSelection();       // klik tunggal → edit biasa
        mouseDownCell = null; isDragging = false;
        document.body.classList.remove('dl-noselect');
    });

    document.addEventListener('keydown', function(e) {
        if (selCells.size === 0) return;
        if (e.key === 'Escape') { clearSelection(); return; }
        if (e.key !== 'Delete' && e.key !== 'Backspace') return;
        var ae = document.activeElement;
        if (ae && ae.classList && ae.classList.contains('usage-input')) return; // biarkan edit sel tunggal
        e.preventDefault();

        // Kosongkan tampilan segera (optimistis) + kumpulkan sel berisi yang perlu dihapus di server
        var payload = [], affectedRows = new Set();
        Array.from(selCells).forEach(function(td) {
            var tr  = td.closest('tr');
            var had = (parseFloat(td.dataset.val) || 0) > 0;
            td.textContent = ''; td.dataset.val = ''; td.classList.remove('has-val');
            affectedRows.add(tr);
            if (had) payload.push({ ingredient_id: tr.dataset.ing, packaging_id: tr.dataset.pkg || null, date: td.dataset.date });
        });
        affectedRows.forEach(function(tr){ updateRowSummary(tr); });
        if (payload.length === 0) return;

        // SATU request hapus-massal → 1 transaksi + recalc FIFO sekali per bahan (anti race)
        var status = document.getElementById('saveStatus');
        status.textContent = 'Menghapus...';
        fetch(bulkDeleteUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: JSON.stringify({ store_id: ledgerStore, cells: payload })
        })
        .then(function(r) { return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            if (!res.ok) { status.textContent = '⚠ Gagal hapus'; window.location.reload(); return; }
            if (res.data && res.data.errors && res.data.errors.length) {
                status.textContent = '⚠ Sebagian terkunci'; window.location.reload(); return; // resync
            }
            var nFix = ((res.data && res.data.fixed) || []).length;
            status.textContent = nFix > 0
                ? 'Terhapus ✓ · ' + nFix + ' transfer disegarkan otomatis'
                : 'Terhapus ✓';
            setTimeout(function() { status.textContent = ''; }, nFix > 0 ? 4000 : 1500);
            laporTransferTerkunci(res.data && res.data.locked);
        })
        .catch(function() { status.textContent = '⚠ Gagal hapus'; window.location.reload(); });
    });
}

// ── Update TOT + Stok Akhir ──────────────────────
// Jumlahkan dari data-val tiap sel (sel yang sedang diedit pakai nilai input live).
function updateRowSummary(tr) {
    if (!tr) return;
    var cells     = tr.querySelectorAll('.td-usage-cell');
    var totalPack = 0;
    cells.forEach(function(td) {
        var inp = td.querySelector('input');
        var v   = inp ? inp.value : td.dataset.val;
        totalPack += parseFloat(v) || 0;
    });

    // Update TOT
    var tdTot = tr.querySelector('.td-total');
    if (tdTot) tdTot.textContent = totalPack > 0 ? Math.round(totalPack) : '';

    // Update stok akhir (boleh negatif jika pemakaian > stok)
    var availBase   = parseFloat(tr.dataset.avail) || 0;
    var ptb         = parseFloat(tr.dataset.ptb)   || 1;
    var ctb         = parseFloat(tr.dataset.ctb)   || 0;
    var closingBase = availBase - (totalPack * ptb);
    var dp          = toDusPack(closingBase, ctb, ptb);

    var DASH   = '<span class="text-muted opacity-50 small">-</span>';
    var tdDus  = tr.querySelector('.td-closing-dus');
    var tdPack = tr.querySelector('.td-closing-pack');
    if (tdDus)  tdDus.innerHTML  = dp.dus  ? dp.dus  : DASH;
    if (tdPack) tdPack.innerHTML = dp.pack ? dp.pack : DASH;
}

function toDusPack(base, ctb, ptb) {
    // Tangani nilai negatif: hitung pada nilai mutlak lalu beri tanda minus.
    var neg = base < 0;
    var b   = Math.abs(base);
    if (!ctb || !ptb) {
        var p = Math.round(b);
        return { dus: 0, pack: neg ? -p : p, base: 0 };
    }
    var dus  = Math.floor(b / ctb);
    var pack = Math.floor((b - dus * ctb) / ptb);
    var rem  = b - dus * ctb - pack * ptb;
    return { dus: neg ? -dus : dus, pack: neg ? -pack : pack, base: neg ? -rem : rem };
}

document.querySelectorAll('.confirm-date-th').forEach(function(th) {
    th.addEventListener('click', function() {
        var el      = this;
        var date    = el.dataset.date;
        var storeId = el.dataset.store;

        fetch(confirmUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ store_id: storeId, date: date })
        })
        .then(function(r) {
            return r.json().then(function(data) {
                return { ok: r.ok, data: data };
            });
        })
        .then(function(res) {
            var st  = document.getElementById('saveStatus');
            var day = parseInt(date.split('-')[2]);

            if (!res.ok) {
                // Tampilkan pesan error dari server (mis. urutan belum benar)
                st.style.color  = '#dc3545';
                st.textContent  = '⚠ ' + (res.data.error || 'Tidak bisa dikonfirmasi');
                setTimeout(function() { st.textContent = ''; st.style.color = ''; }, 5000);
                return;
            }

            var confirmed = res.data.status === 'confirmed';
            el.dataset.confirmed = confirmed ? '1' : '0';
            el.style.background  = confirmed ? '#1a7a3c' : '#e74c3c';
            el.title = confirmed
                ? 'Sudah dikonfirmasi — klik untuk batalkan'
                : 'Klik untuk konfirmasi tgl ' + day;
            el.querySelector('.confirm-icon').textContent = confirmed ? '✓' : '·';

            var msg = confirmed ? 'Tgl ' + day + ' dikonfirmasi ✓' : 'Konfirmasi tgl ' + day + ' dibatalkan';
            var fixed = (res.data.fixed || []).length;
            if (fixed > 0) {
                msg += ' · ' + fixed + ' transfer disegarkan otomatis';
            }
            st.style.color  = confirmed ? '#198754' : '#6c757d';
            st.textContent  = msg;
            setTimeout(function() { st.textContent = ''; st.style.color = ''; }, fixed > 0 ? 4000 : 2000);

            laporTransferTerkunci(res.data.locked);
        })
        .catch(function() {
            document.getElementById('saveStatus').textContent = '⚠ Gagal terhubung ke server';
        });
    });
});
</script>

{{-- Reorder bahan baku (per user) — SortableJS di-load lazy hanya saat dipakai --}}
<script>
(function() {
    const table     = document.querySelector('.daily-ledger-table');
    const btnToggle = document.getElementById('btnToggleReorder');
    const btnReset  = document.getElementById('btnResetOrder');
    const status    = document.getElementById('saveStatus');
    if (!table || !btnToggle) return;

    const csrf       = '{{ csrf_token() }}';
    const saveUrl    = '{{ route("inventory.daily-ledger.save-order") }}';
    const resetUrl   = '{{ route("inventory.daily-ledger.reset-order") }}';
    let sortable     = null;
    let reorderMode  = false;
    let sortableLoaded = false;

    function loadSortable(cb) {
        if (typeof Sortable !== 'undefined') { cb(); return; }
        if (sortableLoaded) { var t = setInterval(function(){ if(typeof Sortable!=='undefined'){clearInterval(t);cb();} },50); return; }
        sortableLoaded = true;
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js';
        s.onload = cb;
        document.head.appendChild(s);
    }

    btnToggle.addEventListener('click', function() {
        reorderMode = !reorderMode;
        if (reorderMode) {
            loadSortable(function() {
                table.classList.add('reorder-mode');
                btnToggle.classList.remove('btn-outline-primary');
                btnToggle.classList.add('btn-primary');
                btnToggle.innerHTML = '<i class="bi bi-check-lg me-1"></i> Selesai';
                sortable = Sortable.create(table.querySelector('tbody.dl-body'), {
                    draggable: 'tr',
                    handle: '.drag-handle',
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                    onEnd: saveOrder,
                });
            });
        } else {
            table.classList.remove('reorder-mode');
            btnToggle.classList.remove('btn-primary');
            btnToggle.classList.add('btn-outline-primary');
            btnToggle.innerHTML = '<i class="bi bi-arrows-move me-1"></i> Atur Urutan';
            if (sortable) { sortable.destroy(); sortable = null; }
        }
    });

    function saveOrder() {
        // Urutan disimpan per BARIS (bahan × kemasan) dan melekat ke TOKO
        const rows = Array.from(table.querySelectorAll('tbody.dl-body > tr'))
                          .map(tr => ({
                              ingredient_id: parseInt(tr.dataset.ing, 10),
                              packaging_id:  parseInt(tr.dataset.pkg, 10) || 0,
                          }));
        status.textContent = 'Menyimpan urutan…';
        fetch(saveUrl, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'},
            body: JSON.stringify({ store_id: table.dataset.store, rows: rows }),
        })
        .then(r => r.json())
        .then(() => { status.textContent = '✓ Urutan tersimpan'; setTimeout(() => status.textContent = '', 1500); })
        .catch(() => { status.textContent = '⚠ Gagal simpan urutan'; });
    }

    btnReset.addEventListener('click', async function() {
        if (!(await uiConfirm('Reset urutan ke default (kategori → nama)?', { type: 'warning', confirmText: 'Ya, reset' }))) return;
        fetch(resetUrl, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'},
            body: JSON.stringify({ store_id: table.dataset.store }),
        })
        .then(r => r.json())
        .then(() => { location.reload(); })
        .catch(() => { status.textContent = '⚠ Gagal reset'; });
    });
})();
</script>
@endpush
