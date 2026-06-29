@extends('layouts.app')
@section('title','Analisa HPP')

{{-- Aksi di header (sejajar judul): Export Excel + Kunci/Buka Kunci HPP --}}
@section('hpp_actions')
    @if($storeId)
    <a href="{{ route('sales.hpp.export', request()->query()) }}" class="btn btn-outline-success">
        <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
    </a>
    @endif
    @if(($summary ?? null) && ($summary->omset > 0 || $summary->hpp_ideal > 0 || ($summary->hpp_aktual ?? 0) > 0))
        @if($locked ?? false)
            <span class="badge bg-dark align-self-center"><i class="bi bi-lock-fill me-1"></i>Terkunci</span>
            @if(auth()->user()->isSuperAdmin())
            <form method="POST" action="{{ route('sales.hpp.unlock') }}" class="m-0"
                  data-confirm="Buka kunci HPP periode ini? Angka akan kembali dihitung live." data-confirm-type="warning" data-confirm-ok="Ya, buka kunci">
                @csrf
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <input type="hidden" name="month" value="{{ $month }}">
                <input type="hidden" name="year" value="{{ $year }}">
                <input type="hidden" name="period_type" value="{{ $periodType }}">
                <button class="btn btn-outline-secondary"><i class="bi bi-unlock me-1"></i>Buka Kunci HPP</button>
            </form>
            @endif
        @else
            <form method="POST" action="{{ route('sales.hpp.lock') }}" class="m-0"
                  data-confirm="Kunci HPP periode ini? Angka dibekukan sebagai snapshot agar tidak berubah." data-confirm-type="info" data-confirm-ok="Ya, kunci">
                @csrf
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <input type="hidden" name="month" value="{{ $month }}">
                <input type="hidden" name="year" value="{{ $year }}">
                <input type="hidden" name="period_type" value="{{ $periodType }}">
                <button class="btn btn-success"><i class="bi bi-lock me-1"></i>Kunci HPP</button>
            </form>
        @endif
    @endif
@endsection

@section('content')
@include('sales._hpp_tabs', ['currentHppTab' => 'periode'])

@php
    $monthNames = ['','Januari','Februari','Maret','April','Mei','Juni','Juli',
                   'Agustus','September','Oktober','November','Desember'];
    $rp = fn($v) => 'Rp ' . number_format($v, 0, ',', '.');
    $pct = fn($v) => $v !== null ? number_format($v, 2, ',', '.') . '%' : '—';
@endphp

<p class="text-muted mb-3 small">Per Periode — HPP Ideal vs Aktual</p>

{{-- Filter --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Toko</label>
                <select name="store_id" class="form-select" required>
                    <option value="">— Pilih Toko —</option>
                    @foreach($stores as $s)
                        <option value="{{ $s->id }}" {{ $storeId==$s->id ? 'selected':'' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Bulan</label>
                <select name="month" class="form-select">
                    @foreach($monthNames as $i => $nm)
                        @if($i>0)<option value="{{ $i }}" {{ $month==$i ? 'selected':'' }}>{{ $nm }}</option>@endif
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Tahun</label>
                <input type="number" name="year" class="form-control" value="{{ $year }}" min="2020">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Periode</label>
                <select name="period_type" class="form-select">
                    <option value="end_month" {{ ($periodType??'end_month')==='end_month'?'selected':'' }}>Akhir Bulan (1–30/31)</option>
                    <option value="mid_month" {{ ($periodType??'')==='mid_month'?'selected':'' }}>Tengah Bulan (1–15)</option>
                </select>
            </div>
            <div class="col-md-auto ms-auto d-flex align-items-end">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i>Tampilkan
                </button>
            </div>
        </form>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(!$storeId)
    <div class="text-center py-5 text-muted">
        <i class="bi bi-bar-chart fs-1 d-block mb-2 opacity-25"></i>
        Pilih toko untuk menampilkan laporan HPP.
    </div>
@else

{{-- Status kunci (info ringkas) --}}
@if(($locked ?? false) && $summary)
<div class="alert alert-dark py-1 px-2 small mb-3">
    <i class="bi bi-lock-fill me-1"></i>HPP periode ini <strong>terkunci</strong>
    {{ ($lockedAt ?? null) ? '· dikunci '.optional($lockedAt)->isoFormat('D MMM Y, HH:mm') : '' }}{{ ($lockedBy ?? null) ? ' oleh '.$lockedBy : '' }} — angka beku, opname & mutasi periode ini tidak bisa diubah.
</div>
@endif

{{-- Summary Cards --}}
@if($summary && ($summary->omset > 0 || $summary->hpp_ideal > 0 || $summary->hpp_aktual > 0))
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card border-0 h-100" style="background:#2563eb;">
            <div class="card-body">
                <div class="mb-1" style="color:rgba(255,255,255,.7);font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Total Omset</div>
                <div class="fs-5 fw-bold" style="color:#fff;">{{ $rp($summary->omset) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 h-100" style="background:#0891b2;">
            <div class="card-body">
                <div class="mb-1" style="color:rgba(255,255,255,.7);font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">HPP Ideal</div>
                <div class="fs-5 fw-bold" style="color:#fff;">{{ $rp($summary->hpp_ideal) }}</div>
                <div class="mt-1" style="color:rgba(255,255,255,.85);font-size:.8rem;">
                    {{ $pct($summary->margin_ideal) }} margin
                    @if($summary->margin_ideal !== null) · HPP {{ $pct(100 - $summary->margin_ideal) }}@endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        @if($summary->hpp_aktual !== null)
        <div class="card border-0 h-100" style="background:#d97706;">
            <div class="card-body">
                <div class="mb-1" style="color:rgba(255,255,255,.7);font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">HPP Aktual</div>
                <div class="fs-5 fw-bold" style="color:#fff;">{{ $rp($summary->hpp_aktual) }}</div>
                <div class="mt-1" style="color:rgba(255,255,255,.85);font-size:.8rem;">{{ $pct($summary->margin_aktual) }} margin · HPP {{ $pct(100 - $summary->margin_aktual) }}</div>
            </div>
        </div>
        @else
        <div class="card border-0 h-100" style="background:#d97706;">
            <div class="card-body">
                <div class="mb-1" style="color:rgba(255,255,255,.7);font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">HPP Aktual</div>
                <div style="color:#fff;font-weight:600;">Belum ada SO Akhir</div>
                <div style="color:rgba(255,255,255,.8);font-size:.8rem;">Generate setelah approve opname</div>
            </div>
        </div>
        @endif
    </div>
    <div class="col-md-3">
        @if($summary->selisih_hpp !== null)
            @php $bg = $summary->selisih_hpp < 0 ? '#dc2626' : '#16a34a'; @endphp
            <div class="card border-0 h-100" style="background:{{ $bg }};">
                <div class="card-body">
                    <div class="mb-1" style="color:rgba(255,255,255,.7);font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Selisih HPP (Ideal − Aktual)</div>
                    <div class="fs-5 fw-bold" style="color:#fff;">{{ $summary->selisih_hpp >= 0 ? '+' : '' }}{{ $rp($summary->selisih_hpp) }}</div>
                </div>
            </div>
        @else
        <div class="card border-0 h-100" style="background:#6b7280;">
            <div class="card-body">
                <div class="mb-1" style="color:rgba(255,255,255,.7);font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Selisih HPP (Aktual − Ideal)</div>
                <div style="color:#fff;font-weight:600;">—</div>
                <div style="color:rgba(255,255,255,.8);font-size:.8rem;">Tersedia setelah HPP Aktual ada</div>
            </div>
        </div>
        @endif
    </div>
</div>
@endif

{{-- Info jika belum ada opname akhir bulan --}}
@if($summary && !$summary->has_opname)
<div class="alert alert-info py-2 small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    <strong>HPP Aktual belum tersedia</strong> — kolom Aktual & Selisih akan muncul otomatis setelah
    <strong>Opname {{ ($periodType??'end_month')==='mid_month' ? 'Tengah Bulan (Tgl 1–15)' : 'Akhir Bulan' }}
    {{ $monthNames[$month] }} {{ $year }}</strong> di-approve.
</div>
@endif

{{-- Tabs --}}
<ul class="nav nav-tabs mb-0" id="hppTab">
    <li class="nav-item">
        <button class="nav-link {{ $menuRows->isEmpty() ? '' : 'active' }}" data-bs-toggle="tab" data-bs-target="#tab-menu">
            <i class="bi bi-cart3 me-1"></i>Per Menu
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link {{ $menuRows->isEmpty() ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-ingredient">
            <i class="bi bi-boxes me-1"></i>Per Bahan
        </button>
    </li>
</ul>

<div class="tab-content">
    {{-- Tab: Per Menu --}}
    <div class="tab-pane fade {{ $menuRows->isEmpty() ? '' : 'show active' }}" id="tab-menu">
        <div class="card rounded-top-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-index table-balanced mb-0 align-middle">
                        <thead>
                            <tr>
                                <th style="width:4%"></th>
                                <th class="col-name" style="width:28%">Menu</th>
                                <th class="text-end" style="width:17%">Qty Terjual</th>
                                <th class="text-end" style="width:17%">HPP Ideal/pcs</th>
                                <th class="text-end" style="width:17%">Total HPP Ideal</th>
                                <th class="text-end" style="width:17%">% dari Total HPP</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $totalHppAll = $menuRows->sum('hpp_ideal'); @endphp
                            @forelse($menuRows as $rIdx => $row)
                            @php
                                $pctOfTotal = $totalHppAll > 0 ? ($row->hpp_ideal / $totalHppAll * 100) : null;
                                $detailId   = 'detail-menu-' . $rIdx;
                            @endphp
                            <tr>
                                <td style="width:32px">
                                    @if(count($row->ingredients) > 0)
                                    <button class="btn btn-sm btn-link p-0 text-muted toggle-detail"
                                            data-bs-toggle="collapse" data-bs-target="#{{ $detailId }}"
                                            title="Lihat breakdown bahan">
                                        <i class="bi bi-chevron-right" style="font-size:.75rem"></i>
                                    </button>
                                    @endif
                                </td>
                                <td class="col-name fw-semibold">{{ $row->menu->name }}</td>
                                <td class="text-end">{{ number_format($row->total_sold, 0, ',', '.') }}</td>
                                <td class="text-end small text-muted">
                                    {{ $row->hpp_per_pcs > 0 ? $rp($row->hpp_per_pcs) : '—' }}
                                </td>
                                <td class="text-end fw-semibold">
                                    {{ $row->hpp_ideal > 0 ? $rp($row->hpp_ideal) : '—' }}
                                </td>
                                <td class="text-end small text-muted">
                                    {{ $pctOfTotal !== null ? number_format($pctOfTotal, 1, ',', '.') . '%' : '—' }}
                                </td>
                            </tr>
                            {{-- Baris detail breakdown bahan --}}
                            @if(count($row->ingredients) > 0)
                            <tr class="collapse" id="{{ $detailId }}">
                                <td colspan="6" class="p-0">
                                    <div class="px-4 py-2" style="background:#f8f9fa;border-bottom:1px solid #dee2e6">
                                        <div class="small text-muted fw-semibold mb-1">
                                            Breakdown HPP: <span class="text-dark">{{ $row->menu->name }}</span>
                                            ({{ number_format($row->total_sold, 0, ',', '.') }} pcs × {{ $rp($row->hpp_per_pcs) }}/pcs)
                                        </div>
                                        <table class="table table-sm mb-0" style="font-size:.78rem">
                                            <thead style="background:#e9ecef">
                                                <tr>
                                                    <th>Bahan Baku</th>
                                                    <th class="text-muted fst-italic">via</th>
                                                    <th class="text-end">Qty/pcs</th>
                                                    <th class="text-end">Total Qty</th>
                                                    <th class="text-end">Harga Beli Terakhir</th>
                                                    <th class="text-end">Subtotal HPP</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($row->ingredients as $ing)
                                                <tr>
                                                    <td class="fw-semibold">{{ $ing->ingredient->name }}</td>
                                                    <td class="text-muted fst-italic">{{ $ing->via_composed ?? '—' }}</td>
                                                    <td class="text-end">
                                                        {{ number_format($ing->qty_per_pcs, strtolower($ing->ingredient->unit_base ?? '') === 'gram' ? 0 : 3, ',', '.') }}
                                                        <span class="text-muted">{{ $ing->ingredient->unit_base }}</span>
                                                    </td>
                                                    <td class="text-end">
                                                        {{ number_format($ing->total_usage, strtolower($ing->ingredient->unit_base ?? '') === 'gram' ? 0 : 2, ',', '.') }}
                                                        <span class="text-muted">{{ $ing->ingredient->unit_base }}</span>
                                                    </td>
                                                    <td class="text-end">{{ $rp($ing->price) }}</td>
                                                    <td class="text-end fw-semibold">{{ $rp($ing->hpp) }}</td>
                                                </tr>
                                                @endforeach
                                            </tbody>
                                            <tfoot>
                                                <tr style="background:#e9ecef;font-weight:600">
                                                    <td colspan="5" class="text-end">Total HPP Ideal</td>
                                                    <td class="text-end text-primary">{{ $rp($row->hpp_ideal) }}</td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                            @endif
                            @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-cart-x fs-2 d-block mb-2 opacity-25"></i>
                                    Belum ada data penjualan.
                                    <a href="{{ route('sales.monthly.create') }}" class="d-block mt-1">Input Penjualan</a>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                        @if($menuRows->isNotEmpty())
                        <tfoot>
                            <tr class="table-secondary fw-bold">
                                <td colspan="2" class="col-name">TOTAL</td>
                                <td class="text-end">{{ number_format($menuRows->sum('total_sold'), 0, ',', '.') }}</td>
                                <td></td>
                                <td class="text-end">{{ $rp($menuRows->sum('hpp_ideal')) }}</td>
                                <td class="text-end">100%</td>
                            </tr>
                        </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Tab: Per Bahan — 1 tabel: bahan raw, ideal & aktual dalam Dus + Rp --}}
    <div class="tab-pane fade {{ $menuRows->isEmpty() ? 'show active' : '' }}" id="tab-ingredient">
        <div class="card rounded-top-0">
            @if($ingredientRows->isEmpty())
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-cart-x fs-2 d-block mb-2 opacity-25"></i>
                    Belum ada data penjualan bulan ini.
                </div>
            @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-index table-balanced mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="col-name" style="width:22%">Bahan Baku</th>
                                <th class="text-end" style="width:11.5%">Ideal<br><small class="fw-normal opacity-75">Dus</small></th>
                                <th class="text-end" style="width:11.5%">Aktual<br><small class="fw-normal opacity-75">Dus</small></th>
                                <th class="text-end" style="width:11.5%">Selisih<br><small class="fw-normal opacity-75">Dus</small></th>
                                <th class="text-end" style="width:11.5%">HPP Ideal</th>
                                <th class="text-end" style="width:11.5%">HPP Aktual</th>
                                <th class="text-end" style="width:11.5%">Selisih HPP</th>
                                <th class="text-end" style="width:9%">% Selisih</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ingredientRows as $r)
                            @php
                                $selClass = $r->selisih_hpp === null ? ''
                                    : ($r->selisih_hpp < 0 ? 'text-danger' : ($r->selisih_hpp > 0 ? 'text-success' : 'text-muted'));
                                $fmtDus = fn($v) => $v !== null ? number_format($v, 2, ',', '.') : '—';
                            @endphp
                            <tr>
                                <td class="col-name fw-semibold">{{ $r->ingredient->name }}</td>

                                {{-- Ideal Dus --}}
                                <td class="text-end">
                                    @if($r->ideal_dus !== null)
                                        {{ number_format($r->ideal_dus, 2, ',', '.') }}
                                    @else
                                        <span class="text-muted small" title="{{ number_format($r->ideal_base, 2, ',', '.') }} base">
                                            {{ number_format($r->ideal_base, 2, ',', '.') }}*
                                        </span>
                                    @endif
                                </td>

                                {{-- Aktual Dus --}}
                                <td class="text-end">
                                    @if($r->has_actual && $r->actual_dus !== null)
                                        {{ number_format($r->actual_dus, 2, ',', '.') }}
                                    @elseif($r->has_actual)
                                        <span class="text-muted small" title="{{ number_format($r->actual_base, 2, ',', '.') }} base">
                                            {{ number_format($r->actual_base, 2, ',', '.') }}*
                                        </span>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>

                                {{-- Selisih Dus --}}
                                <td class="text-end fw-semibold {{ $selClass }}">
                                    @if($r->selisih_dus !== null)
                                        {{ $r->selisih_dus >= 0 ? '+' : '' }}{{ number_format($r->selisih_dus, 2, ',', '.') }}
                                    @elseif($r->selisih_base !== null)
                                        {{ $r->selisih_base >= 0 ? '+' : '' }}{{ number_format($r->selisih_base, 2, ',', '.') }}*
                                    @else
                                        —
                                    @endif
                                </td>

                                {{-- HPP Ideal --}}
                                <td class="text-end">
                                    @if($r->hpp_ideal > 0)
                                        {{ $rp($r->hpp_ideal) }}
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>

                                {{-- HPP Aktual --}}
                                <td class="text-end">
                                    @if($r->has_actual)
                                        {{ $rp($r->hpp_aktual) }}
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>

                                {{-- Selisih HPP --}}
                                <td class="text-end fw-semibold {{ $selClass }}">
                                    @if($r->selisih_hpp !== null)
                                        {{ $r->selisih_hpp >= 0 ? '+' : '' }}{{ $rp($r->selisih_hpp) }}
                                    @else
                                        —
                                    @endif
                                </td>

                                {{-- % Selisih (terhadap HPP Ideal) --}}
                                <td class="text-end fw-semibold {{ $selClass }}">
                                    @if($r->selisih_pct !== null)
                                        {{ $r->selisih_pct >= 0 ? '+' : '' }}{{ number_format($r->selisih_pct, 1, ',', '.') }}%
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="table-secondary fw-bold">
                                <td class="col-name">TOTAL</td>
                                <td></td><td></td><td></td>
                                <td class="text-end">{{ $rp($ingredientRows->sum('hpp_ideal')) }}</td>
                                <td class="text-end">
                                    @if($ingredientRows->where('has_actual', true)->isNotEmpty())
                                        {{ $rp($ingredientRows->where('has_actual', true)->sum('hpp_aktual')) }}
                                    @else —
                                    @endif
                                </td>
                                <td class="text-end">
                                    @php $totSelisih = $ingredientRows->whereNotNull('selisih_hpp')->sum('selisih_hpp'); @endphp
                                    @if($ingredientRows->whereNotNull('selisih_hpp')->isNotEmpty())
                                        <span class="{{ $totSelisih < 0 ? 'text-danger' : ($totSelisih > 0 ? 'text-success' : '') }}">
                                            {{ $totSelisih >= 0 ? '+' : '' }}{{ $rp($totSelisih) }}
                                        </span>
                                    @else —
                                    @endif
                                </td>
                                <td class="text-end">
                                    @php $totAktual = $ingredientRows->where('has_actual', true)->sum('hpp_aktual'); @endphp
                                    @if(abs($totAktual) > 0.0001)
                                        @php $totPct = $totSelisih / abs($totAktual) * 100; @endphp
                                        <span class="{{ $totPct < 0 ? 'text-danger' : ($totPct > 0 ? 'text-success' : '') }}">
                                            {{ $totPct >= 0 ? '+' : '' }}{{ number_format($totPct, 1, ',', '.') }}%
                                        </span>
                                    @else —
                                    @endif
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @if($ingredientRows->contains(fn($r) => $r->ideal_dus === null))
                <div class="card-footer text-muted small py-1">
                    * Tidak ada data packaging Dus — ditampilkan dalam satuan base unit.
                </div>
                @endif
            </div>
            @endif
        </div>
    </div>
</div>

@endif
@endsection

@push('scripts')
<script>
// Rotate chevron icon saat expand/collapse
document.addEventListener('show.bs.collapse', function(e) {
    var btn = document.querySelector('[data-bs-target="#' + e.target.id + '"]');
    if (btn) btn.querySelector('i')?.classList.replace('bi-chevron-right', 'bi-chevron-down');
});
document.addEventListener('hide.bs.collapse', function(e) {
    var btn = document.querySelector('[data-bs-target="#' + e.target.id + '"]');
    if (btn) btn.querySelector('i')?.classList.replace('bi-chevron-down', 'bi-chevron-right');
});
</script>
@endpush
