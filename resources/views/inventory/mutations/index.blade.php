@extends('layouts.app')
@section('title', 'Mutasi Stok')

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1 class="page-title">Mutasi Stok</h1>
        <p class="page-subtitle">Daftar semua transaksi masuk/keluar bahan baku</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('inventory.mutations.export', request()->query()) }}" class="btn btn-outline-success">
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
        </a>
        <a href="{{ route('inventory.mutations.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i> Tambah Mutasi
        </a>
    </div>
</div>

{{-- FILTER --}}
<div class="card mb-3">
    <div class="card-body py-2">
        {{-- Data toko untuk JS filter --}}
        <script>
        const allFilterStores  = @json($filterStores->map(fn($s)=>['id'=>$s->id,'name'=>$s->name])->values());
        const myFilterStores   = @json($stores->map(fn($s)=>['id'=>$s->id,'name'=>$s->name])->values());
        const purchaseTypes    = ['purchase_zhisheng','purchase_supplier'];
        </script>

        <form method="GET" class="row g-2 align-items-end flex-nowrap">
            <div class="col">
                <label class="form-label small fw-semibold mb-1">Tipe</label>
                <select id="filter-type" name="type" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="purchase_zhisheng"  {{ request('type') === 'purchase_zhisheng'  ? 'selected' : '' }}>Pembelian Pusat</option>
                    <option value="purchase_supplier"  {{ request('type') === 'purchase_supplier'  ? 'selected' : '' }}>Pembelian Supplier Lokal</option>
                    <option value="sale_internal"      {{ request('type') === 'sale_internal'      ? 'selected' : '' }}>Penjualan Internal</option>
                    <option value="sale_external"      {{ request('type') === 'sale_external'      ? 'selected' : '' }}>Pembelian Eksternal</option>
                    <option value="sale_external_out"  {{ request('type') === 'sale_external_out'  ? 'selected' : '' }}>Penjualan Eksternal</option>
                </select>
            </div>
            <div class="col">
                <label class="form-label small fw-semibold mb-1">Toko Pengirim</label>
                <select id="filter-source" name="source_store_id" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($filterStores as $store)
                        <option value="{{ $store->id }}" {{ request('source_store_id') == $store->id ? 'selected' : '' }}>{{ $store->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col">
                <label class="form-label small fw-semibold mb-1">Toko Penerima</label>
                <select id="filter-dest" name="dest_store_id" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($filterStores as $store)
                        <option value="{{ $store->id }}" {{ request('dest_store_id') == $store->id ? 'selected' : '' }}>{{ $store->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="draft"     {{ request('status') === 'draft'     ? 'selected' : '' }}>Draft</option>
                    <option value="confirmed" {{ request('status') === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                </select>
            </div>
            <div class="col">
                <label class="form-label small fw-semibold mb-1">Dari</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}">
            </div>
            <div class="col">
                <label class="form-label small fw-semibold mb-1">Sampai</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}">
            </div>
            <div class="col-auto d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary">Cari</button>
                <a href="{{ route('inventory.mutations.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-index table-balanced mb-0">
                <thead>
                    <tr>
                        <th class="col-name" style="width:14%">No SJ</th>
                        <th style="width:12%">Tipe</th>
                        <th class="col-name" style="width:16%">Pengirim</th>
                        <th class="col-name" style="width:16%">Penerima</th>
                        <th style="width:10%">Tgl Kirim</th>
                        <th style="width:10%">Tgl Terima</th>
                        <th style="width:6%">Item</th>
                        <th style="width:8%">Status</th>
                        <th style="width:8%">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($mutations as $mut)
                    @php
                        $typeConfig = [
                            'purchase_zhisheng' => ['Pembelian Pusat',          'bg-info text-dark'],
                            'purchase_supplier' => ['Pembelian Supplier Lokal', 'bg-info text-dark'],
                            'sale_internal'     => ['Penjualan Internal',       'bg-primary'],
                            'sale_external'     => ['Pembelian Eksternal',      'bg-primary'],
                            'sale_external_out' => ['Penjualan Eksternal',      'bg-warning text-dark'],
                            'opening_stock'     => ['Input Stok Awal',          'bg-secondary'],
                        ];
                        $tc = $typeConfig[$mut->type] ?? [$mut->type, 'bg-secondary'];

                        // Pengirim: pihak luar (Pembelian Eksternal) / supplier (pembelian) / toko asal
                        if ($mut->external_sender) {
                            $pengirim = e($mut->external_sender);
                        } elseif ($mut->isPurchase()) {
                            $pengirim = $mut->supplier->name ?? '<span class="text-muted">—</span>';
                        } else {
                            $pengirim = $mut->sourceStore->name ?? '<span class="text-muted">—</span>';
                        }

                        // Penerima: pihak luar (Penjualan Eksternal) / toko tujuan
                        $penerima = $mut->external_receiver
                            ? e($mut->external_receiver)
                            : ($mut->destinationStore->name ?? '<span class="text-muted">—</span>');
                    @endphp
                    <tr>
                        {{-- No SJ --}}
                        <td class="col-name">
                            @if($mut->invoice_no)
                                <span class="fw-semibold">{{ $mut->invoice_no }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        {{-- Tipe --}}
                        <td><span class="badge {{ $tc[1] }}" style="font-size:.72rem">{{ $tc[0] }}</span></td>
                        {{-- Pengirim --}}
                        <td class="col-name">{!! $pengirim !!}</td>
                        {{-- Penerima --}}
                        <td class="col-name">{!! $penerima !!}</td>
                        {{-- Tgl Transaksi / Tgl Stok (untuk opening_stock) --}}
                        <td class="text-nowrap">
                            {{ $mut->transaction_date ? $mut->transaction_date->format('d M Y') : '—' }}
                            @if($mut->type === 'opening_stock')
                                <div class="small text-muted" style="font-size:.65rem">Tgl Stok</div>
                            @endif
                        </td>
                        {{-- Tgl Terima (tidak relevan untuk opening_stock) --}}
                        <td class="text-nowrap">
                            @if($mut->type === 'opening_stock')
                                <span class="text-muted">—</span>
                            @elseif($mut->delivery_date)
                                <span class="text-success">{{ $mut->delivery_date->format('d M Y') }}</span>
                            @else
                                <span class="text-muted">Belum diterima</span>
                            @endif
                        </td>
                        {{-- Item count --}}
                        <td>
                            <span class="badge bg-secondary">{{ $mut->items_count }}</span>
                        </td>
                        {{-- Status --}}
                        <td>
                            @if($mut->status === 'draft')
                                @if(!$mut->delivery_date && $mut->type !== 'opening_stock')
                                    <span class="badge bg-info text-dark"><i class="bi bi-truck me-1"></i>Dalam Perjalanan</span>
                                @else
                                    <span class="badge bg-warning text-dark">Draft</span>
                                @endif
                            @elseif($mut->status === 'confirmed')
                                <span class="badge bg-success">Confirmed</span>
                            @else
                                <span class="badge bg-secondary">Cancelled</span>
                            @endif
                        </td>
                        {{-- Aksi --}}
                        <td>
                            <x-action-menu>
                                <x-action-view :href="route('inventory.mutations.show', $mut)" />
                                @if($mut->status === 'draft' && $mut->type !== 'opening_stock')
                                    <x-action-edit :href="route('inventory.mutations.edit', $mut)" />
                                @endif
                                <x-action-delete :action="route('inventory.mutations.destroy', $mut)"
                                                 confirm="Hapus mutasi {{ $mut->reference_no }} secara permanen?" />
                            </x-action-menu>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-arrow-left-right fs-1 d-block mb-2 opacity-25"></i>
                            Tidak ada data mutasi
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($mutations->hasPages())
    <div class="card-footer">{{ $mutations->withQueryString()->links() }}</div>
    @endif
</div>
@push('scripts')
<script>
(function() {
    const typeEl   = document.getElementById('filter-type');
    const sourceEl = document.getElementById('filter-source');
    const destEl   = document.getElementById('filter-dest');
    const currentDest = destEl.value;

    function rebuildOptions(selectEl, list, currentVal) {
        const prev = currentVal ?? selectEl.value;
        selectEl.innerHTML = '<option value="">Semua</option>';
        list.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.name;
            if (String(s.id) === String(prev)) opt.selected = true;
            selectEl.appendChild(opt);
        });
    }

    function applyRules() {
        const isPurchase = purchaseTypes.includes(typeEl.value);

        // Toko Pengirim: nonaktif saat pembelian pusat/supplier
        sourceEl.disabled = isPurchase;
        if (isPurchase) sourceEl.value = '';

        // Toko Penerima: hanya toko area sendiri saat pembelian pusat/supplier
        const list = isPurchase ? myFilterStores : allFilterStores;
        rebuildOptions(destEl, list, currentDest);
    }

    typeEl.addEventListener('change', applyRules);
    applyRules(); // jalankan saat load agar konsisten dengan request filter yg ada
})();
</script>
@endpush
@endsection
