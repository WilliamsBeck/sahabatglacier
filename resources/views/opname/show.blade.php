@extends('layouts.app')
@section('title','Detail Opname')

@php
/**
 * Format selisih (dalam base unit) ke Dus + Pack.
 * Contoh: +2 Dus +3 Pack  |  -1 Pack  |  0
 */
function fmtVariance(float $var, ?int $ctrPack, ?int $packBase): string {
    if (!$ctrPack || !$packBase) {
        if (abs($var) < 0.01) return '0';
        return ($var >= 0 ? '+' : '') . number_format($var, 2, ',', '.');
    }
    if (abs($var) < 0.01) return '0';
    $sign = $var >= 0 ? '+' : '-';
    $abs  = abs($var);
    $ctn  = (int) floor($abs / ($ctrPack * $packBase));
    $rem  = $abs - ($ctn * $ctrPack * $packBase);
    $pk   = (int) floor($rem / $packBase);
    $parts = [];
    if ($ctn > 0) $parts[] = $sign . $ctn . ' Dus';
    if ($pk  > 0) $parts[] = $sign . $pk  . ' Pack';
    if (empty($parts)) $parts[] = $sign . '< 1 Pack';
    return implode(' ', $parts);
}
@endphp

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h4 class="page-title">Stok Opname — {{ $opname->store->name }}</h4>
        <p class="text-muted mb-0">
            {{ \Carbon\Carbon::parse($opname->opname_date)->format('d M Y') }} ·
            {{ $opname->period_type === 'mid_month' ? 'Periode 1–15' : 'Periode 1–30/31' }}
            {{ $opname->period_month }}/{{ $opname->period_year }}
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <a href="{{ route('opname.opnames.index') }}" class="btn btn-outline-secondary btn-sm btn-back">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
        <a href="{{ route('opname.opnames.export', $opname) }}" class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
        </a>
        @if($isLocked)
            <button class="btn btn-danger btn-sm" disabled title="Terkunci">
                <i class="bi bi-lock me-1"></i>Hapus (Terkunci)
            </button>
            @if($opname->status !== 'approved')
            <button class="btn btn-success btn-sm" disabled title="Terkunci">
                <i class="bi bi-lock me-1"></i>Approve (Terkunci)
            </button>
            @endif
        @else
            {{-- Tombol Hapus: tampil untuk semua status, Super Admin bisa hapus approved --}}
            @if($opname->status !== 'approved')
                <form method="POST" action="{{ route('opname.opnames.destroy', $opname) }}" class="d-inline"
                      data-confirm="Hapus opname ini?" data-confirm-type="error" data-confirm-danger="1" data-confirm-ok="Ya, hapus">
                    @csrf @method('DELETE')
                    <button class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Hapus</button>
                </form>
                <form method="POST" action="{{ route('opname.opnames.approve', $opname) }}" class="d-inline"
                      data-confirm="Approve opname? Selisih akan otomatis masuk ke ledger." data-confirm-type="info" data-confirm-ok="Ya, approve">
                    @csrf
                    <button class="btn btn-success btn-sm"><i class="bi bi-check-circle me-1"></i>Approve</button>
                </form>
            @else
                {{-- Approved: Admin Area & Super Admin bisa Batalkan Approve (untuk edit) atau Hapus.
                     Server menolak kalau sudah ada mutasi/pencatatan setelah tanggal opname. --}}
                @if(auth()->user()->isSuperAdmin() || auth()->user()->isAdminArea())
                <form method="POST" action="{{ route('opname.opnames.unapprove', $opname) }}" class="d-inline"
                      data-confirm="Batalkan approve opname ini? Opname kembali ke draft & efek stoknya dibalikkan agar bisa diedit. Hanya bisa jika belum ada mutasi setelahnya. Jangan lupa Approve lagi setelah selesai."
                      data-confirm-type="warning" data-confirm-ok="Ya, batalkan approve">
                    @csrf
                    <button class="btn btn-warning btn-sm"><i class="bi bi-pencil-square me-1"></i>Edit</button>
                </form>
                <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalHapusOpname">
                    <i class="bi bi-trash me-1"></i>Hapus
                </button>
                @endif
            @endif
        @endif
    </div>
</div>

{{-- ── Lock / Unlock Banner ─────────────────────────────────────────────── --}}
@if($isPastLock)
    @if($isLocked)
        <div class="alert alert-warning d-flex align-items-start gap-2 mt-3">
            <i class="bi bi-lock-fill fs-5 mt-1"></i>
            <div class="flex-fill">
                <strong>Data Terkunci</strong>
                <div class="small mt-1">Data bulan ini sudah melewati batas edit (H+7). Ajukan request unlock ke Super Admin.</div>
                @if($hasPending)
                    <span class="badge bg-info text-dark mt-2"><i class="bi bi-hourglass-split me-1"></i>Request unlock sedang menunggu persetujuan</span>
                @else
                    <button class="btn btn-sm btn-warning mt-2" data-bs-toggle="modal" data-bs-target="#modalUnlockOpname">
                        <i class="bi bi-unlock me-1"></i>Request Unlock
                    </button>
                @endif
            </div>
        </div>
    @elseif($hasUnlock && !auth()->user()->isSuperAdmin())
        <div class="alert alert-success d-flex align-items-center gap-2 mt-3">
            <i class="bi bi-unlock-fill fs-5"></i>
            <span><strong>Unlock Aktif</strong> — Data ini sudah dibuka oleh Super Admin dan dapat diedit.</span>
        </div>
    @elseif(auth()->user()->isSuperAdmin())
        <div class="alert alert-secondary d-flex align-items-center gap-2 mt-3 py-2">
            <i class="bi bi-shield-check fs-5"></i>
            <span class="small">Data bulan ini terkunci untuk user biasa. Anda login sebagai Super Admin — akses penuh.</span>
        </div>
    @endif
@endif

<div class="alert {{ $opname->status==='approved' ? 'alert-success' : 'alert-warning' }} d-flex align-items-center gap-2">
    <i class="bi bi-{{ $opname->status==='approved' ? 'check-circle' : 'clock' }}"></i>
    <span>
        Status: <strong>{{ $opname->status === 'approved' ? 'Disetujui' : 'Draft' }}</strong>
        @if($opname->status === 'draft') — Isi stok fisik lalu klik <strong>Setujui</strong> @endif
        @if($opname->status === 'approved') — Disetujui oleh {{ $opname->approvedBy?->name ?? '-' }} @endif
    </span>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {!! session('success') !!}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- FORM INPUT FISIK --}}
@if($opname->status !== 'approved')
<form method="POST" action="{{ route('opname.opnames.update', $opname) }}">
    @csrf @method('PUT')
@endif

<div class="card">
    <div class="card-header fw-semibold d-flex justify-content-between">
        <span><i class="bi bi-clipboard-check me-1"></i>Daftar Bahan</span>
        <span class="text-muted small fw-normal">{{ $opname->items->count() }} bahan</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            @php
                // Tandai bahan yang punya > 1 kemasan supaya bisa tampilkan sub-label
                $pkgCountByIng = $opname->items->groupBy('ingredient_id')->map->count();
                // Multi-batch tracking: grup per ingredient_id + packaging_id
                $batchTotalMap = $opname->items->groupBy(fn($i) => $i->ingredient_id.'_'.($i->packaging_id??'null'))->map->count();
                $batchCounter  = [];
            @endphp
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead>
                    <tr class="table-dark">
                        <th rowspan="2" class="align-middle" style="width:260px;max-width:260px">Bahan</th>
                        <th colspan="3" class="text-center border-start py-1" style="border-bottom:1px solid #4a6080">Stok Fisik</th>
                        <th colspan="2" class="text-center border-start py-1" style="border-bottom:1px solid #4a6080">Stok Sistem</th>
                        <th rowspan="2" class="text-end border-start align-middle" style="width:150px">Selisih<br><small class="fw-normal opacity-75">(Dus/Pack)</small></th>
                        <th rowspan="2" class="text-end border-start align-middle" style="width:140px">Harga<br><small class="fw-normal opacity-75">(/Dus)</small></th>
                        <th rowspan="2" class="text-end border-start align-middle" style="width:150px">Nilai Fisik<br><small class="fw-normal opacity-75">(Rp)</small></th>
                        @if($opname->opname_mode === 'stok_awal' && $opname->status !== 'approved')
                        <th rowspan="2" class="align-middle border-start" style="width:36px"></th>
                        @endif
                    </tr>
                    <tr class="table-dark" style="border-top:1px solid #4a6080">
                        <th class="text-center border-start fw-normal small py-1" style="width:75px">Dus</th>
                        <th class="text-center fw-normal small py-1" style="width:75px">Pack</th>
                        <th class="text-center fw-normal small py-1" style="width:80px">Pcs/Gr</th>
                        <th class="text-center border-start fw-normal small py-1" style="width:70px">Dus</th>
                        <th class="text-center fw-normal small py-1" style="width:70px">Pack</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($opname->items as $item)
                    @php
                        $pkg      = $item->packaging;
                        $ctrPack  = $pkg ? (int)$pkg->crate_to_pack : null;
                        $packBase = $pkg ? (int)$pkg->pack_to_base  : null;
                        $sysNeg   = $item->system_qty < 0;

                        // Batch tracking
                        $batchKey   = $item->ingredient_id . '_' . ($item->packaging_id ?? 'null');
                        $batchCounter[$batchKey] = ($batchCounter[$batchKey] ?? 0) + 1;
                        $batchNum   = $batchCounter[$batchKey];
                        $batchTotal = $batchTotalMap[$batchKey] ?? 1;
                        $isExtraBatch = $batchNum > 1;

                        // Label kemasan — "@ X pack" jika multi-kemasan + nama supplier jika LOKAL (bukan Zhisheng)
                        $isMultiPkg = ($pkgCountByIng[$item->ingredient_id] ?? 1) > 1;
                        $supLabel   = ($pkg && $pkg->supplier && $pkg->supplier->type !== 'zhisheng') ? $pkg->supplier->name : null;
                        $pkgLabel   = collect([($isMultiPkg && $ctrPack) ? '@ ' . $ctrPack . ' pack' : null, $supLabel])
                                        ->filter()->implode(' · ') ?: null;
                        if ($isExtraBatch) $pkgLabel = ($pkgLabel ? $pkgLabel . ' · ' : '') . 'Batch ' . $batchNum;

                        // Stok sistem dalam Dus + Pack
                        if ($ctrPack && $packBase) {
                            $crateBase  = $ctrPack * $packBase;
                            $sysDus     = (int) floor($item->system_qty / $crateBase);
                            $sysPackRem = $item->system_qty - $sysDus * $crateBase;
                            $sysPack    = (int) floor($sysPackRem / $packBase);
                        } else {
                            $sysDus = null; $sysPack = null;
                        }

                        // Selisih tampilan: hanya Dus + Pack (Pcs/Gr tidak dihitung)
                        if ($ctrPack && $packBase) {
                            $physNoPcs  = ($item->physical_crate ?? 0) * $ctrPack * $packBase
                                        + ($item->physical_pack  ?? 0) * $packBase;
                            $sysRounded = floor($item->system_qty / $packBase) * $packBase;
                            $displayVar = $physNoPcs - $sysRounded;
                        } else {
                            $displayVar = $item->variance;
                        }
                        $varText  = fmtVariance($displayVar, $ctrPack, $packBase);
                        $isZero   = abs($displayVar) < 0.01;
                        $varClass = $isZero ? 'text-muted' : ($displayVar < 0 ? 'text-danger' : 'text-success');

                        // Harga: stok_awal → price_per_base per item (batch individual)
                        //        bulanan   → harga efektif FIFO BERLAPIS (akurat) per item,
                        //                    fallback weighted bila tak ada batch.
                        // stok_awal: harga sudah diisi user (termasuk 0) → pakai apa adanya.
                        // NULL (belum diisi) → saran dari FIFO/harga terakhir.
                        $harga = ($opname->opname_mode === 'stok_awal' && $item->price_per_base !== null)
                            ? (float) $item->price_per_base
                            : (($fifoPrice[$item->id] ?? null) ?: ($priceMap[$item->ingredient_id] ?? 0));
                        $hargaDus   = ($ctrPack && $packBase) ? $harga * $ctrPack * $packBase : $harga;
                        // Nilai dihitung per komponen (Dus/Pack/Base) dari harga per-dus
                        // yang dibulatkan, agar konsisten & bebas galat floating-point.
                        $pdRound = ($ctrPack && $packBase) ? round($hargaDus) : 0;
                        if ($pdRound > 0) {
                            $pPack = $ctrPack > 0 ? $pdRound / $ctrPack : 0;
                            $pBase = ($ctrPack * $packBase) > 0 ? $pdRound / ($ctrPack * $packBase) : 0;
                            $nilaiFisik = round(
                                ($item->physical_crate ?? 0) * $pdRound
                                + ($item->physical_pack ?? 0) * $pPack
                                + ($item->physical_base ?? 0) * $pBase
                            );
                        } else {
                            $nilaiFisik = round($item->physical_qty * $harga);
                        }
                    @endphp
                    <tr
                        data-id="{{ $item->id }}"
                        data-system="{{ $item->system_qty }}"
                        data-crate="{{ $ctrPack ?? 0 }}"
                        data-pack="{{ $packBase ?? 1 }}"
                        data-ing="{{ $item->ingredient_id }}"
                        data-pkg="{{ $item->packaging_id }}"
                        @if($isExtraBatch) style="background:rgba(255,193,7,.07)" @endif
                    >
                        <td style="width:260px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <span class="fw-semibold">{{ $item->ingredient->name }}</span>
                            @if($pkgLabel)
                                <small class="text-muted ms-1">{{ $pkgLabel }}</small>
                            @endif
                        </td>

                        {{-- Input fisik --}}
                        @if($opname->status !== 'approved')
                            <td class="border-start">
                                <input type="number"
                                       name="items[{{ $item->id }}][physical_crate]"
                                       class="form-control form-control-sm opname-input"
                                       value="{{ old("items.{$item->id}.physical_crate", $item->physical_crate) }}"
                                       min="0" placeholder="" style="width:68px">
                            </td>
                            <td>
                                <input type="number"
                                       name="items[{{ $item->id }}][physical_pack]"
                                       class="form-control form-control-sm opname-input"
                                       value="{{ old("items.{$item->id}.physical_pack", $item->physical_pack) }}"
                                       min="0" placeholder="" style="width:68px">
                            </td>
                            <td>
                                <input type="number"
                                       name="items[{{ $item->id }}][physical_base]"
                                       class="form-control form-control-sm opname-input"
                                       value="{{ old("items.{$item->id}.physical_base", $item->physical_base !== null ? (int)$item->physical_base : '') }}"
                                       min="0" step="1" placeholder="" style="width:72px">
                            </td>
                        @else
                            <td class="text-center border-start small">{{ $item->physical_crate ?? '' }}{!! $item->physical_crate === null ? '<span class="text-muted opacity-50 small">-</span>' : '' !!}</td>
                            <td class="text-center small">{{ $item->physical_pack ?? '' }}{!! $item->physical_pack === null ? '<span class="text-muted opacity-50 small">-</span>' : '' !!}</td>
                            <td class="text-center small">{{ $item->physical_base !== null ? number_format($item->physical_base, 0, ',', '.') : '' }}{!! $item->physical_base === null ? '<span class="text-muted opacity-50 small">-</span>' : '' !!}</td>
                        @endif

                        {{-- Stok Sistem: kolom Dus --}}
                        <td class="text-center border-start {{ $sysNeg ? 'text-danger fw-bold' : '' }}">
                            @if($sysDus !== null)
                                {!! ($sysDus > 0 ? $sysDus : ($sysNeg ? $sysDus : '<span class="text-muted opacity-50 small">-</span>')) !!}
                            @else
                                {{ number_format($item->system_qty, 0, ',', '.') }}
                            @endif
                        </td>
                        {{-- Stok Sistem: kolom Pack --}}
                        <td class="text-center {{ $sysNeg ? 'text-danger fw-bold' : '' }}">
                            @if($sysDus !== null)
                                {!! ($sysPack > 0 ? $sysPack : '<span class="text-muted opacity-50 small">-</span>') !!}
                            @else
                                <span class="text-muted opacity-50 small">-</span>
                            @endif
                            @if($sysNeg)<small class="d-block text-danger">⚠</small>@endif
                        </td>

                        {{-- Selisih (Dus/Pack) --}}
                        <td class="text-end border-start fw-bold {{ $varClass }}" id="var-{{ $item->id }}">
                            @if($isZero)
                                <span class="text-muted opacity-50 small">-</span>
                            @else
                                {{ $varText }}
                            @endif
                        </td>

                        {{-- Harga & Nilai. stok_awal: harga selalu bisa diedit. bulanan: harga
                             HANYA bisa diisi kalau KOSONG (belum ada harga FIFO); kalau sudah ada
                             harga, tampil teks saja (koreksi harga dilakukan di transaksi pembelian). --}}
                        @php
                            $showPriceInput = $opname->status !== 'approved'
                                && ($opname->opname_mode === 'stok_awal' || $hargaDus <= 0);
                        @endphp
                        <td class="text-end border-start small text-muted">
                            @if($showPriceInput)
                                @php $hargaDusInput = $item->price_per_base !== null && $ctrPack && $packBase
                                    ? round($item->price_per_base * $ctrPack * $packBase)
                                    : ($hargaDus > 0 ? round($hargaDus) : ''); @endphp
                                <input type="number"
                                       name="items[{{ $item->id }}][price_per_dus]"
                                       class="form-control form-control-sm text-end price-input"
                                       value="{{ $hargaDusInput }}"
                                       min="0" step="1" placeholder="—"
                                       style="width:90px;margin-left:auto"
                                       data-crate="{{ $ctrPack ?? 0 }}"
                                       data-pack="{{ $packBase ?? 1 }}"
                                       data-item="{{ $item->id }}">
                            @elseif($hargaDus > 0)
                                {{ number_format($hargaDus, 0, ',', '.') }}
                            @else
                                <span class="text-muted opacity-50 small">-</span>
                            @endif
                        </td>
                        <td class="text-end border-start fw-semibold" id="nilai-{{ $item->id }}"
                            data-price="{{ $harga }}"{{-- data-price tetap per-base untuk kalkulasi JS --}}>
                            @if($harga > 0 && $nilaiFisik != 0)
                                {{ number_format($nilaiFisik, 0, ',', '.') }}
                            @else
                                <span class="text-muted opacity-50 small">-</span>
                            @endif
                        </td>
                        {{-- Kolom aksi: tombol + / × untuk stok_awal --}}
                        @if($opname->opname_mode === 'stok_awal' && $opname->status !== 'approved')
                        <td class="border-start text-center" style="width:36px;padding:2px 4px">
                            @if(!$isExtraBatch)
                                {{-- Tambah batch (AJAX, tanpa reload) --}}
                                <button type="button" class="btn btn-outline-warning btn-sm px-1 py-0 js-add-batch"
                                        data-ing="{{ $item->ingredient_id }}" data-pkg="{{ $item->packaging_id }}"
                                        title="Tambah batch harga berbeda"
                                        style="font-size:.75rem;line-height:1.4">+</button>
                            @else
                                {{-- Hapus batch (AJAX, tanpa reload) --}}
                                <button type="button" class="btn btn-outline-danger btn-sm px-1 py-0 js-del-batch"
                                        data-id="{{ $item->id }}"
                                        title="Hapus batch ini"
                                        style="font-size:.75rem;line-height:1.4">×</button>
                            @endif
                        </td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
                @php
                    // Akumulasi nilai MENTAH per baris, total dibulatkan sekali di akhir
                    // (round-of-sum) agar total akurat & sesuai penjumlahan sebenarnya.
                    $grandTotal = round($opname->items->sum(function($i) use ($priceMap, $fifoPrice, $opname) {
                        $h = ($opname->opname_mode === 'stok_awal' && $i->price_per_base > 0)
                            ? (float) $i->price_per_base
                            : (($fifoPrice[$i->id] ?? null) ?: ($priceMap[$i->ingredient_id] ?? 0));
                        $ctr = (float) ($i->packaging->crate_to_pack ?? 0);
                        $pkb = (float) ($i->packaging->pack_to_base ?? 0);
                        if ($ctr > 0 && $pkb > 0) {
                            $pd = round($h * $ctr * $pkb);
                            return ($i->physical_crate ?? 0) * $pd
                                + ($i->physical_pack ?? 0) * ($pd / $ctr)
                                + ($i->physical_base ?? 0) * ($pd / ($ctr * $pkb));
                        }
                        return $i->physical_qty * $h;
                    }));
                @endphp
                <tfoot>
                    <tr class="table-light fw-bold">
                        <td colspan="7" class="text-end border-top">TOTAL NILAI SO</td>
                        <td class="border-top"></td>
                        <td class="text-end border-top border-start fs-6" id="grand-total">
                            Rp {{ number_format($grandTotal, 0, ',', '.') }}
                        </td>
                        @if($opname->opname_mode === 'stok_awal' && $opname->status !== 'approved')
                        <td class="border-top"></td>
                        @endif
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @if($opname->status !== 'approved')
    <div class="card-footer text-end">
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-save me-1"></i>Simpan Stok Fisik
        </button>
    </div>
    @endif
</div>

@if($opname->status !== 'approved')</form>@endif

@if($opname->status === 'approved')
<div class="card mt-3 border-0 bg-light">
    <div class="card-body small text-muted">
        <i class="bi bi-info-circle me-1"></i>
        Opname ini sudah <strong>disetujui</strong>. Selisih telah otomatis diposting ke ledger sebagai penyesuaian stok opname.
        Data ini digunakan sebagai <strong>Stok Akhir (Closing Stock)</strong> dalam perhitungan HPP Aktual bulan
        {{ \Carbon\Carbon::create($opname->period_year, $opname->period_month, 1)->isoFormat('MMMM Y') }}.
    </div>
</div>
@endif

{{-- Modal Hapus Opname (hanya untuk approved, Super Admin) --}}
@if($opname->status === 'approved' && (auth()->user()->isSuperAdmin() || auth()->user()->isAdminArea()))
<div class="modal fade" id="modalHapusOpname" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Hapus Opname yang Sudah Disetujui</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Opname ini sudah <strong>disetujui</strong> dan telah mempengaruhi saldo stok. Menghapusnya akan:</p>
                <ul class="small mb-3">
                    <li>Membatalkan semua penyesuaian ledger dari opname ini</li>
                    <li>Me-recalculate ulang saldo FIFO semua bahan</li>
                    <li>Menghapus mutation bootstrap (jika ada) yang dibuat saat approve</li>
                </ul>
                <div class="alert alert-warning py-2 small mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Tindakan ini <strong>tidak dapat dibatalkan</strong>. Data pencatatan harian bulan berikutnya
                    mungkin terpengaruh.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                <form method="POST" action="{{ route('opname.opnames.destroy', $opname) }}" class="d-inline">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="bi bi-trash me-1"></i>Ya, Hapus Sekarang
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Modal Request Unlock Opname --}}
@if($isLocked && !$hasPending)
<div class="modal fade" id="modalUnlockOpname" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('inventory.unlock-requests.store') }}">
            @csrf
            <input type="hidden" name="resource_type"         value="opname">
            <input type="hidden" name="resource_id"           value="{{ $opname->id }}">
            <input type="hidden" name="store_id"              value="{{ $opname->store_id }}">
            <input type="hidden" name="resource_month"        value="{{ $lockMonth }}">
            <input type="hidden" name="resource_year"         value="{{ $lockYear }}">
            <input type="hidden" name="resource_period_type"  value="{{ $opname->period_type }}">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-unlock me-2"></i>Request Unlock Opname</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Opname <strong>{{ $opname->store->name }}</strong>
                        periode <strong>{{ $opname->period_month }}/{{ $opname->period_year }}</strong>
                        sudah terkunci. Jelaskan alasan perlunya perubahan.
                    </p>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Alasan <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control form-control-sm" rows="3"
                            placeholder="Contoh: Ada kesalahan input stok fisik bahan X, perlu dikoreksi sebelum HPP dihitung..." required maxlength="1000"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning btn-sm">
                        <i class="bi bi-send me-1"></i>Kirim Request
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
@endif

@endsection

@push('scripts')
<script>
/**
 * Format variance base → "±X Dus ±Y Pack"
 */
function fmtVar(varBase, crate, pack) {
    if (!crate || !pack) {
        if (Math.abs(varBase) < 0.01) return '0';
        return (varBase >= 0 ? '+' : '') + varBase.toFixed(2).replace('.', ',');
    }
    if (Math.abs(varBase) < 0.01) return '0';

    var sign  = varBase >= 0 ? '+' : '-';
    var abs   = Math.abs(varBase);
    var ctnN  = Math.floor(abs / (crate * pack));
    var rem   = abs - ctnN * crate * pack;
    var packN = Math.floor(rem / pack);

    var parts = [];
    if (ctnN  > 0) parts.push(sign + ctnN  + ' Dus');
    if (packN > 0) parts.push(sign + packN + ' Pack');
    if (parts.length === 0) parts.push(sign + '< 1 Pack');
    return parts.join(' ');
}

// Nilai per komponen (Dus/Pack/Base) dari harga per-dus dibulatkan — hindari
// galat floating-point dari physBase × harga_per_base (mis. ...271,4999 → ...271).
function nilaiRaw(crate, pack, c, p, b, priceBase) {
    if (crate > 0 && pack > 0 && priceBase > 0) {
        var pd = Math.round(priceBase * crate * pack);   // harga per dus (bulat)
        return (c * pd) + (p * (pd / crate)) + (b * (pd / (crate * pack)));
    }
    var physBase = crate > 0 ? (c * crate * pack) + (p * pack) + b : (p * pack) + b;
    return physBase * priceBase;
}
// Nilai per baris (dibulatkan) untuk kolom Nilai.
function nilaiPerKomponen(crate, pack, c, p, b, priceBase) {
    return Math.round(nilaiRaw(crate, pack, c, p, b, priceBase));
}

// Grand total dari semua baris
function recomputeGrandTotal() {
    var grandTotal = 0;
    document.querySelectorAll('tr[data-id]').forEach(function(r) {
        var nilaiEl = document.getElementById('nilai-' + r.dataset.id);
        if (!nilaiEl) return;
        var price = parseFloat(nilaiEl.dataset.price) || 0;
        var cr    = parseFloat(r.dataset.crate) || 0;
        var pk    = parseFloat(r.dataset.pack)  || 1;
        var c2    = parseFloat(r.querySelector('[name$="[physical_crate]"]')?.value) || 0;
        var p2    = parseFloat(r.querySelector('[name$="[physical_pack]"]')?.value)  || 0;
        var b2    = parseFloat(r.querySelector('[name$="[physical_base]"]')?.value)  || 0;
        grandTotal += nilaiRaw(cr, pk, c2, p2, b2, price);
    });
    var gtCell = document.getElementById('grand-total');
    if (gtCell) gtCell.textContent = 'Rp ' + Math.round(grandTotal).toLocaleString('id-ID');
}

// Saat user mengetik harga/dus, update data-price dan nilai fisik secara live
function priceInputHandler() {
    var row      = this.closest('tr');
    var id       = row.dataset.id;
    var crate    = parseFloat(row.dataset.crate) || 0;
    var pack     = parseFloat(row.dataset.pack)  || 1;
    var priceDus = parseFloat(this.value) || 0;
    var priceBase = (crate > 0 && pack > 0) ? priceDus / (crate * pack) : priceDus;

    var nilaiCell = document.getElementById('nilai-' + id);
    if (nilaiCell) {
        nilaiCell.dataset.price = priceBase;
        var c2 = parseFloat(row.querySelector('[name$="[physical_crate]"]')?.value) || 0;
        var p2 = parseFloat(row.querySelector('[name$="[physical_pack]"]')?.value)  || 0;
        var b2 = parseFloat(row.querySelector('[name$="[physical_base]"]')?.value)  || 0;
        var nilai = nilaiPerKomponen(crate, pack, c2, p2, b2, priceBase);
        nilaiCell.textContent = priceBase > 0 ? 'Rp ' + nilai.toLocaleString('id-ID') : '—';
    }
    recomputeGrandTotal();
}

function opnameInputHandler() {
    var row    = this.closest('tr');
    var id     = row.dataset.id;
    var crate  = parseFloat(row.dataset.crate) || 0;
    var pack   = parseFloat(row.dataset.pack)  || 1;
    var sysQty = parseFloat(row.dataset.system) || 0;

    var c = parseFloat(row.querySelector('[name$="[physical_crate]"]')?.value) || 0;
    var p = parseFloat(row.querySelector('[name$="[physical_pack]"]')?.value)  || 0;
    var b = parseFloat(row.querySelector('[name$="[physical_base]"]')?.value)  || 0;

    var physBase = crate > 0 ? (c * crate * pack) + (p * pack) + b : (p * pack) + b;

    var totalCell = document.getElementById('total-' + id);
    if (totalCell) {
        totalCell.textContent = crate > 0
            ? (physBase / (crate * pack)).toFixed(2).replace('.', ',')
            : physBase.toFixed(2).replace('.', ',');
    }

    // Selisih: hanya Dus + Pack (Pcs/Gr tidak dihitung)
    var physNoPcs   = crate > 0 ? (c * crate * pack) + (p * pack) : (p * pack);
    var sysRounded  = pack > 0 ? Math.floor(sysQty / pack) * pack : sysQty;
    var varBase     = Math.round((physNoPcs - sysRounded) * 10000) / 10000;
    var varText = fmtVar(varBase, crate, pack);
    var varCell = document.getElementById('var-' + id);
    if (varCell) {
        varCell.textContent = varText;
        varCell.className   = 'text-end border-start fw-bold ';
        varCell.className  += varText === '0' ? 'text-muted' : (varBase < 0 ? 'text-danger' : 'text-success');
    }

    var nilaiCell = document.getElementById('nilai-' + id);
    if (nilaiCell) {
        var price = parseFloat(nilaiCell.dataset.price) || 0;
        var nilai = nilaiPerKomponen(crate, pack, c, p, b, price);
        nilaiCell.textContent = price > 0 ? 'Rp ' + nilai.toLocaleString('id-ID') : '—';
    }
    recomputeGrandTotal();
}

// Event delegation → berlaku juga untuk baris batch yang disisipkan via AJAX.
var opnameTbody = document.querySelector('tr[data-id]') ? document.querySelector('tr[data-id]').closest('tbody') : null;
if (opnameTbody) {
    opnameTbody.addEventListener('input', function(e) {
        if (e.target.classList.contains('price-input')) priceInputHandler.call(e.target);
        else if (e.target.classList.contains('opname-input')) opnameInputHandler.call(e.target);
    });
    opnameTbody.addEventListener('click', function(e) {
        var add = e.target.closest('.js-add-batch');
        if (add) { addBatchAjax(add); return; }
        var del = e.target.closest('.js-del-batch');
        if (del) { removeBatchAjax(del); return; }
    });
}

// ── Tambah / hapus batch tanpa reload (AJAX) ──────────────────────────────
var ADD_BATCH_URL = @json(route('opname.opnames.add-batch', $opname));
var DEL_ITEM_URL  = @json(route('opname.opnames.items.destroy', [$opname, 'ITEMID']));
var OPNAME_CSRF   = @json(csrf_token());

function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
}

function buildBatchRow(d) {
    var tr = document.createElement('tr');
    tr.dataset.id = d.id;
    tr.dataset.system = 0;
    tr.dataset.crate = d.crate_to_pack || 0;
    tr.dataset.pack = d.pack_to_base || 1;
    tr.dataset.ing = d.ingredient_id;
    tr.dataset.pkg = d.packaging_id || '';
    tr.style.background = 'rgba(255,193,7,.07)';
    var n = 'items[' + d.id + ']';
    tr.innerHTML =
        '<td style="width:260px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
          + '<span class="fw-semibold">' + escapeHtml(d.ingredient) + '</span>'
          + '<small class="text-muted ms-1">' + escapeHtml(d.label) + '</small></td>'
        + '<td class="border-start"><input type="number" name="' + n + '[physical_crate]" class="form-control form-control-sm opname-input" min="0" style="width:68px"></td>'
        + '<td><input type="number" name="' + n + '[physical_pack]" class="form-control form-control-sm opname-input" min="0" style="width:68px"></td>'
        + '<td><input type="number" name="' + n + '[physical_base]" class="form-control form-control-sm opname-input" min="0" step="1" style="width:72px"></td>'
        + '<td class="text-center border-start"><span class="text-muted opacity-50 small">-</span></td>'
        + '<td class="text-center"><span class="text-muted opacity-50 small">-</span></td>'
        + '<td class="text-end border-start fw-bold text-muted" id="var-' + d.id + '"><span class="text-muted opacity-50 small">-</span></td>'
        + '<td class="text-end border-start small text-muted"><input type="number" name="' + n + '[price_per_dus]" class="form-control form-control-sm text-end price-input" min="0" step="1" placeholder="—" style="width:90px;margin-left:auto" data-crate="' + (d.crate_to_pack || 0) + '" data-pack="' + (d.pack_to_base || 1) + '" data-item="' + d.id + '"></td>'
        + '<td class="text-end border-start fw-semibold" id="nilai-' + d.id + '" data-price="0"><span class="text-muted opacity-50 small">-</span></td>'
        + '<td class="border-start text-center" style="width:36px;padding:2px 4px"><button type="button" class="btn btn-outline-danger btn-sm px-1 py-0 js-del-batch" data-id="' + d.id + '" title="Hapus batch ini" style="font-size:.75rem;line-height:1.4">×</button></td>';
    return tr;
}

function addBatchAjax(btn) {
    var ing = btn.dataset.ing, pkg = btn.dataset.pkg || '';
    btn.disabled = true;
    fetch(ADD_BATCH_URL, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': OPNAME_CSRF, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ingredient_id=' + encodeURIComponent(ing) + '&packaging_id=' + encodeURIComponent(pkg)
    }).then(function(res) {
        if (!res.ok) throw new Error('gagal');
        return res.json();
    }).then(function(d) {
        var tr = buildBatchRow(d);
        // Sisipkan setelah baris TERAKHIR dari grup (bahan + kemasan) yang sama.
        var last = btn.closest('tr');
        document.querySelectorAll('tr[data-id]').forEach(function(r) {
            if (r.dataset.ing === String(ing) && (r.dataset.pkg || '') === String(pkg)) last = r;
        });
        last.insertAdjacentElement('afterend', tr);
    }).catch(function() {
        alert('Gagal menambah batch. Coba lagi.');
    }).finally(function() { btn.disabled = false; });
}

function removeBatchAjax(btn) {
    if (!confirm('Hapus batch ini?')) return;
    var id = btn.dataset.id;
    btn.disabled = true;
    fetch(DEL_ITEM_URL.replace('ITEMID', id), {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': OPNAME_CSRF, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: '_method=DELETE'
    }).then(function(res) {
        if (!res.ok) throw new Error('gagal');
        var tr = btn.closest('tr');
        if (tr) tr.parentNode.removeChild(tr);
        recomputeGrandTotal();
    }).catch(function() {
        alert('Gagal menghapus batch.');
    }).finally(function() { btn.disabled = false; });
}
</script>
@endpush
