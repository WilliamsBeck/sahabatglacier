@extends('layouts.app')
@section('title', 'Detail Mutasi')

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h4 class="page-title">Detail Mutasi</h4>
        <span class="font-monospace text-muted">{{ $mutation->reference_no }}</span>
    </div>
    <a href="{{ route('inventory.mutations.index') }}" class="btn btn-outline-secondary btn-sm btn-back">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

{{-- ── Peringatan urutan FIFO (lunak — boleh dilanjutkan) ──────────────────── --}}
@if(session('fifo_warning'))
    @php $fw = session('fifo_warning'); @endphp
    <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill fs-5 mt-1"></i>
        <div class="flex-fill">
            <strong>Ada pencatatan harian yang belum dikonfirmasi</strong>
            <div class="small mt-1">
                <strong>{{ $fw['store'] }}</strong> punya pemakaian yang belum dikonfirmasi di tanggal:
                <strong>{{ collect($fw['dates'])->map(fn($d) => \Carbon\Carbon::parse($d)->isoFormat('D MMM'))->implode(', ') }}</strong>
                — semuanya sebelum/pada tanggal mutasi ini
                ({{ \Carbon\Carbon::parse($fw['txDate'])->isoFormat('D MMM Y') }}).
            </div>
            <div class="small mt-2">
                Kalau dilanjutkan, mutasi ini bisa mengambil batch yang keliru dan
                <strong>harganya</strong> mungkin tidak sesuai FIFO. Kuantitas tetap terkoreksi
                otomatis saat tanggal-tanggal itu dikonfirmasi, tapi harga tidak ikut dihitung ulang.
            </div>
            <div class="small mt-2 text-muted">
                Disarankan: konfirmasi tanggal-tanggal itu dulu di Pencatatan Harian, baru konfirmasi mutasi ini.
            </div>
            <div class="d-flex gap-2 mt-3 flex-wrap">
                <a href="{{ route('inventory.daily-ledger.index', [
                        'store_id' => $mutation->source_store_id,
                        'month'    => \Carbon\Carbon::parse($fw['txDate'])->month,
                        'year'     => \Carbon\Carbon::parse($fw['txDate'])->year,
                   ]) }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-calendar-check me-1"></i>Konfirmasi tanggalnya dulu
                </a>
                <form method="POST" action="{{ route('inventory.mutations.confirm', $mutation) }}"
                      data-confirm="Lanjutkan konfirmasi meski ada pencatatan harian yang belum dikonfirmasi?"
                      data-confirm-type="warning" data-confirm-ok="Ya, lanjutkan">
                    @csrf
                    <input type="hidden" name="force_fifo" value="1">
                    <button class="btn btn-sm btn-outline-secondary">Lanjutkan saja</button>
                </form>
            </div>
        </div>
    </div>
@endif

{{-- ── Lock / Unlock Banner ─────────────────────────────────────────────── --}}
@if($isPastLock)
    @if($isLocked)
        <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
            <i class="bi bi-lock-fill fs-5 mt-1"></i>
            <div class="flex-fill">
                <strong>Data Terkunci</strong>
                <div class="small mt-1">
                    Data bulan ini sudah melewati batas edit (H+7). Ajukan request unlock ke Super Admin agar bisa diedit kembali.
                </div>
                @if($hasPending)
                    <div class="mt-2">
                        <span class="badge bg-info text-dark"><i class="bi bi-hourglass-split me-1"></i>Request unlock sedang menunggu persetujuan Super Admin</span>
                    </div>
                @else
                    <button class="btn btn-sm btn-warning mt-2" data-bs-toggle="modal" data-bs-target="#modalUnlockRequest">
                        <i class="bi bi-unlock me-1"></i>Request Unlock
                    </button>
                @endif
            </div>
        </div>
    @elseif($hasUnlock && !auth()->user()->isSuperAdmin())
        <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-unlock-fill fs-5"></i>
            <span><strong>Unlock Aktif</strong> — Data ini sudah dibuka oleh Super Admin dan dapat diedit.</span>
        </div>
    @elseif(auth()->user()->isSuperAdmin())
        <div class="alert alert-secondary d-flex align-items-center gap-2 mb-3 py-2">
            <i class="bi bi-shield-check fs-5"></i>
            <span class="small">Data bulan ini terkunci untuk user biasa. Anda login sebagai Super Admin — akses penuh tetap diberikan.</span>
        </div>
    @endif
@endif

<div class="row g-3">
    {{-- INFO --}}
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold">Informasi Mutasi</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" width="130">Referensi</td>
                        <td class="font-monospace fw-bold">{{ $mutation->reference_no }}</td></tr>
                    <tr><td class="text-muted">Tipe</td>
                        <td><span class="badge bg-primary">{{ $mutation->type_label }}</span></td></tr>
                    <tr><td class="text-muted">Status</td>
                        <td>
                            @if($mutation->status === 'draft')
                                @if(!$mutation->delivery_date && $mutation->type !== 'opening_stock')
                                    <span class="badge bg-info text-dark">
                                        <i class="bi bi-truck me-1"></i>Dalam Perjalanan
                                    </span>
                                @else
                                    <span class="badge bg-warning text-dark">Draft</span>
                                @endif
                            @elseif($mutation->status === 'confirmed')
                                <span class="badge bg-success">Confirmed</span>
                            @else
                                <span class="badge bg-secondary">Cancelled</span>
                            @endif
                        </td></tr>
                    <tr><td class="text-muted">Toko Tujuan</td>
                        <td>{{ $mutation->destinationStore->name ?? '-' }}</td></tr>
                    <tr><td class="text-muted">Toko Asal</td>
                        <td>{{ $mutation->sourceStore->name ?? '-' }}</td></tr>
                    <tr><td class="text-muted">Supplier</td>
                        <td>{{ $mutation->supplier->name ?? '-' }}</td></tr>
                    @if($mutation->external_sender)
                    <tr><td class="text-muted">Pengirim</td>
                        <td>{{ $mutation->external_sender }}</td></tr>
                    @endif
                    @if($mutation->external_receiver)
                    <tr><td class="text-muted">Penjualan ke</td>
                        <td>{{ $mutation->external_receiver }}</td></tr>
                    @endif
                    <tr><td class="text-muted">No. SJ</td>
                        <td>{{ $mutation->invoice_no ?? '-' }}</td></tr>
                    @if((float) $mutation->discount_amount > 0)
                    <tr><td class="text-muted">Diskon Invoice</td>
                        <td class="text-danger fw-semibold">− Rp {{ number_format($mutation->discount_amount, 0, ',', '.') }}</td></tr>
                    @endif
                    <tr><td class="text-muted">Tgl Pengiriman</td>
                        <td>{{ \Carbon\Carbon::parse($mutation->transaction_date)->format('d M Y') }}</td></tr>
                    <tr><td class="text-muted">Tgl Penerimaan</td>
                        <td>{{ $mutation->delivery_date ? \Carbon\Carbon::parse($mutation->delivery_date)->format('d M Y') : '-' }}</td></tr>
                    <tr><td class="text-muted">Dibuat oleh</td>
                        <td>{{ $mutation->createdBy->name }}</td></tr>
                    @if($mutation->confirmedBy)
                    <tr><td class="text-muted">Dikonfirmasi</td>
                        <td>{{ $mutation->confirmedBy->name }}</td></tr>
                    @endif
                    @if($mutation->notes)
                    <tr><td class="text-muted">Catatan</td>
                        <td>{{ $mutation->notes }}</td></tr>
                    @endif
                </table>
            </div>

            <div class="card-footer d-flex gap-2 flex-wrap">
                {{-- Edit & Konfirmasi: hanya untuk draft --}}
                @if($mutation->status === 'draft')
                    <a href="{{ route('inventory.mutations.edit', $mutation) }}" class="btn btn-primary btn-sm">
                        <i class="bi bi-pencil me-1"></i> Edit Draft
                    </a>
                    <form method="POST" action="{{ route('inventory.mutations.confirm', $mutation) }}"
                          data-confirm="Konfirmasi mutasi ini? Stok akan langsung diupdate dan tidak bisa diubah lagi." data-confirm-type="info" data-confirm-ok="Ya, konfirmasi">
                        @csrf
                        <button class="btn btn-success btn-sm">
                            <i class="bi bi-check-circle me-1"></i> Konfirmasi
                        </button>
                    </form>
                    <form method="POST" action="{{ route('inventory.mutations.cancel', $mutation) }}"
                          data-confirm="Batalkan mutasi ini?" data-confirm-type="warning" data-confirm-ok="Ya, batalkan">
                        @csrf
                        <button class="btn btn-warning btn-sm text-dark">
                            <i class="bi bi-x-circle me-1"></i> Batalkan
                        </button>
                    </form>
                @endif

                {{-- Batalkan Konfirmasi: mutasi confirmed kembali jadi draft supaya bisa diperbaiki --}}
                @if($mutation->status === 'confirmed' && $mutation->type !== 'opening_stock' && !$isLocked)
                    <form method="POST" action="{{ route('inventory.mutations.unconfirm', $mutation) }}"
                          data-confirm="Batalkan konfirmasi mutasi ini? Stok akan dikembalikan seperti sebelum dikonfirmasi, dan mutasi jadi draft agar bisa diedit."
                          data-confirm-type="warning" data-confirm-ok="Ya, batalkan konfirmasi">
                        @csrf
                        <button class="btn btn-outline-warning btn-sm">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Batalkan Konfirmasi & Edit
                        </button>
                    </form>
                @endif

                {{-- Hapus: untuk confirmed/cancelled atau draft yang belum dikunci --}}
                @if($isLocked)
                    <button class="btn btn-danger btn-sm" disabled title="Data terkunci">
                        <i class="bi bi-lock me-1"></i> Hapus (Terkunci)
                    </button>
                @elseif($mutation->status !== 'draft')
                    <form method="POST" action="{{ route('inventory.mutations.destroy', $mutation) }}"
                          data-confirm="Hapus mutasi ini secara permanen? Stok akan dikoreksi otomatis." data-confirm-type="error" data-confirm-danger="1" data-confirm-ok="Ya, hapus">
                        @csrf @method('DELETE')
                        <button class="btn btn-danger btn-sm">
                            <i class="bi bi-trash me-1"></i> Hapus
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    {{-- ITEMS --}}
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold">
                Daftar Bahan ({{ $mutation->items->count() }} item)
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Bahan</th>
                                <th class="text-center" style="width:80px">Dus</th>
                                <th class="text-center" style="width:80px">Pack</th>
                                <th class="text-center" style="width:80px">Pcs/Gr</th>
                                <th class="text-end">Harga/Dus</th>
                                @if($mutation->type === 'sale_external_out')
                                <th class="text-end">Harga Jual/Base</th>
                                @endif
                                <th class="text-end">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $grandTotal = 0; @endphp
                            @foreach($mutation->items as $item)
                            @php
                                $grandTotal += $item->cost_subtotal;
                                $pkg = $item->packaging ?? $item->ingredient->packagings->first();
                                $dusSize = $pkg ? (float)$pkg->crate_to_pack * (float)$pkg->pack_to_base : 0;
                                // Angka asli yang diketik user bila ada; data lama jatuh balik
                                // ke perhitungan dari harga per satuan dasar (bisa meleset 1 rupiah).
                                $hargaDus = $item->price_per_crate !== null
                                    ? (int) round($item->price_per_crate)
                                    : ($dusSize > 0 ? (int) round($item->price_per_base * $dusSize) : null);
                            @endphp
                            <tr>
                                <td class="fw-semibold">{{ $item->ingredient->name }}</td>
                                <td class="text-center">{{ $item->qty_crate ?: '-' }}</td>
                                <td class="text-center">{{ $item->qty_pack ?: '-' }}</td>
                                <td class="text-center">{{ (float) $item->qty_base ? number_format($item->qty_base, 0, ',', '.') : '-' }}</td>
                                <td class="text-end">{{ $hargaDus ? 'Rp '.number_format($hargaDus, 0, ',', '.') : '-' }}</td>
                                @if($mutation->type === 'sale_external_out')
                                <td class="text-end">{{ ($item->selling_price_per_base ?? 0) > 0 ? 'Rp '.number_format($item->selling_price_per_base, 0, ',', '.') : '-' }}</td>
                                @endif
                                <td class="text-end fw-semibold">Rp {{ number_format($item->cost_subtotal, 0, ',', '.') }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            @php $colspanFoot = $mutation->type === 'sale_external_out' ? 6 : 5; @endphp
                            @if((float) $mutation->discount_amount > 0)
                                @php $brutoTotal = $mutation->items->sum(fn($i) => $i->total_in_base * (float)($i->gross_price_per_base ?? $i->price_per_base)); @endphp
                                <tr class="small">
                                    <td colspan="{{ $colspanFoot }}" class="text-end text-muted">Subtotal (bruto)</td>
                                    <td class="text-end text-muted">Rp {{ number_format($brutoTotal, 0, ',', '.') }}</td>
                                </tr>
                                <tr class="small">
                                    <td colspan="{{ $colspanFoot }}" class="text-end text-muted">Diskon invoice</td>
                                    <td class="text-end text-danger">− Rp {{ number_format($mutation->discount_amount, 0, ',', '.') }}</td>
                                </tr>
                            @endif
                            <tr class="table-light fw-bold">
                                <td colspan="{{ $colspanFoot }}" class="text-end">Total Nilai{{ (float) $mutation->discount_amount > 0 ? ' (netto)' : '' }}</td>
                                <td class="text-end text-primary">Rp {{ number_format($grandTotal, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Modal Request Unlock --}}
@if($isLocked && !$hasPending)
<div class="modal fade" id="modalUnlockRequest" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('inventory.unlock-requests.store') }}">
            @csrf
            <input type="hidden" name="resource_type" value="mutation">
            <input type="hidden" name="resource_id"   value="{{ $mutation->id }}">
            <input type="hidden" name="store_id"      value="{{ $mutation->destination_store_id ?? $mutation->source_store_id }}">
            <input type="hidden" name="resource_month" value="{{ $txMonth }}">
            <input type="hidden" name="resource_year"  value="{{ $txYear }}">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-unlock me-2"></i>Request Unlock Mutasi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Mutasi <strong>{{ $mutation->reference_no }}</strong> dari bulan yang sudah terkunci.
                        Jelaskan alasan mengapa data ini perlu dibuka kembali.
                    </p>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Alasan <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control form-control-sm" rows="3"
                            placeholder="Contoh: Ada salah input qty pada item X, perlu dikoreksi sebelum laporan HPP dikirim..." required maxlength="1000"></textarea>
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
