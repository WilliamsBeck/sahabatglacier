@extends('layouts.app')
@section('title','Import Penjualan Menu')
@section('content')

<div class="page-header d-flex justify-content-between align-items-center">
    <h4 class="page-title">Import Penjualan Menu</h4>
    <a href="{{ route('sales.monthly.index') }}" class="btn btn-outline-secondary btn-sm btn-back">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show">
        <strong><i class="bi bi-exclamation-triangle me-1"></i>Gagal import:</strong>
        <ul class="mb-0 mt-1">
            @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@isset($preview)
{{-- ── PREVIEW SECTION ── --}}
<div class="alert alert-info d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-eye fs-5"></i>
    <div>Cek data berikut sebelum disimpan. Klik <strong>Konfirmasi & Simpan</strong> jika sudah benar.</div>
</div>

<form method="POST" action="{{ route('sales.monthly.import.commit') }}" style="max-width:680px">
    @csrf
    <input type="hidden" name="store_id"    value="{{ $preview['store_id'] }}">
    <input type="hidden" name="month"       value="{{ $preview['month'] }}">
    <input type="hidden" name="year"        value="{{ $preview['year'] }}">
    <input type="hidden" name="period_type" value="{{ $preview['period_type'] }}">

    <div class="card mb-3">
        <div class="card-body pb-2">
            <div class="row g-2 small">
                <div class="col-6">
                    <span class="text-muted">Toko</span><br>
                    <strong>{{ $preview['store_name'] }}</strong>
                </div>
                <div class="col-6">
                    <span class="text-muted">Periode</span><br>
                    <strong>{{ $preview['month_name'] }} {{ $preview['year'] }}</strong>
                    <span class="badge bg-secondary ms-1">
                        {{ $preview['period_type'] === 'mid_month' ? 'Tengah Bulan' : 'Akhir Bulan' }}
                    </span>
                </div>
                {{-- Omset bisa dikoreksi sebelum disimpan --}}
                @include('sales._omset_fields', [
                    'colClass' => 'col-md-4 mt-2',
                    'gross'    => $preview['revenue'] > 0 ? $preview['revenue'] : null,
                    'tiktok'   => ($preview['tiktok_diff'] ?? 0) != 0 ? $preview['tiktok_diff'] : null,
                ])
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span class="fw-semibold small">Daftar Menu ({{ count($preview['items']) }} item)</span>
            <span class="small text-muted">Qty boleh dikoreksi sebelum disimpan</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Kategori</th>
                        <th>Menu</th>
                        <th class="text-end" style="width:120px">Qty Terjual</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($preview['items'] as $i => $item)
                    @php $ikutHitung = $item['count_in_total'] ?? true; @endphp
                    <tr>
                        <td class="text-muted small">{{ $item['category'] }}</td>
                        <td>
                            {{ $item['menu_name'] }}
                            @unless($ikutHitung)
                                {{-- Penanda halus: ikon kecil + tooltip, bukan badge --}}
                                <i class="bi bi-slash-circle ms-1" style="font-size:.72rem;color:#cbd5e1"
                                   title="Tidak dihitung ke total menu terjual"></i>
                            @endunless
                        </td>
                        <td class="text-end">
                            <input type="hidden" name="items[{{ $i }}][menu_id]" value="{{ $item['menu_id'] }}">
                            <input type="text"
                                   name="items[{{ $i }}][total_sold]"
                                   class="form-control form-control-sm text-end qty-import"
                                   value="{{ number_format($item['total_sold'], 0, ',', '.') }}"
                                   inputmode="numeric"
                                   @if(!$ikutHitung) data-skip-total="1" @endif>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="3" class="text-center text-muted py-3">Tidak ada data menu dengan qty > 0</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="table-light fw-bold">
                        <td colspan="2" class="text-end">TOTAL MENU TERJUAL</td>
                        <td class="text-end" id="importTotal">—</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="d-flex gap-2">
        <a href="{{ route('sales.monthly.import.form') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Upload Ulang
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle me-1"></i>Konfirmasi & Simpan
        </button>
    </div>
</form>

@push('scripts')
<script>
(function () {
    // Format ribuan pakai TITIK (format Indonesia)
    var fmt   = function (n) { return n ? Number(n).toLocaleString('id-ID') : ''; };
    var angka = function (el) { return parseInt((el.value || '').replace(/[^0-9]/g, ''), 10) || 0; };

    function hitungTotal() {
        var total = 0;
        document.querySelectorAll('.qty-import').forEach(function (el) {
            if (el.dataset.skipTotal) return;   // add on & packaging cup tidak dihitung
            total += angka(el);
        });
        document.getElementById('importTotal').textContent = total > 0 ? fmt(total) : '—';
    }

    // Rapikan tampilan angka sambil diketik (nilai dikirim tetap angka murni saat submit)
    document.addEventListener('input', function (e) {
        if (e.target.classList.contains('qty-import')) {
            e.target.value = fmt(angka(e.target));
            hitungTotal();
        }
    });

    // Bersihkan titik sebelum dikirim supaya server menerima angka murni
    document.querySelector('form[action*="import"]').addEventListener('submit', function () {
        document.querySelectorAll('.qty-import').forEach(function (el) { el.value = angka(el); });
    });

    hitungTotal();
})();
</script>
@endpush

@else
{{-- ── UPLOAD FORM ── --}}
<div class="card" style="max-width:520px">
    <div class="card-body">
        <p class="text-muted small mb-3">
            Upload file template yang sudah diisi. Template bisa didownload dari halaman
            <a href="{{ route('sales.monthly.create') }}">Input Penjualan</a>.
        </p>
        <form method="POST" action="{{ route('sales.monthly.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="mb-3">
                <label class="form-label fw-semibold">File Excel (.xlsx)</label>
                <input type="file" name="file" class="form-control" accept=".xlsx,.xls" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-eye me-1"></i>Preview Data
            </button>
        </form>
    </div>
</div>
@endisset

@endsection
