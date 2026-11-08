@extends('layouts.app')
@section('title', 'Detail Toko')

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1 class="page-title">{{ $store->name }}</h1>
        <p class="page-subtitle">Detail toko (hanya lihat)</p>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        @if(auth()->user()->isSuperAdmin())
        <a href="{{ route('master.stores.edit', $store) }}" class="btn btn-primary">
            <i class="bi bi-pencil me-1"></i> Edit
        </a>
        @endif
        <a href="{{ route('master.stores.index') }}" class="btn btn-outline-secondary btn-sm btn-back">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

<div class="card" style="max-width:640px">
    <div class="card-body">
        <dl class="row mb-0 small">
            <dt class="col-5 text-muted">Kode Toko</dt><dd class="col-7">{{ $store->store_code }}</dd>
            <dt class="col-5 text-muted">Nama</dt><dd class="col-7">{{ $store->name }}</dd>
            <dt class="col-5 text-muted">Area</dt><dd class="col-7">{{ $store->area ?? '—' }}</dd>
            <dt class="col-5 text-muted">Status</dt>
            <dd class="col-7">
                <span class="badge bg-{{ $store->is_active ? 'success' : 'secondary' }}">
                    {{ $store->is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
            </dd>
            <dt class="col-5 text-muted">Lead Time (hari)</dt><dd class="col-7">{{ $store->lead_time_days ?? '—' }}</dd>
            <dt class="col-5 text-muted">Order Cycle (hari)</dt><dd class="col-7">{{ $store->order_cycle_days ?? '—' }}</dd>
            <dt class="col-5 text-muted">DOS Window (hari)</dt><dd class="col-7">{{ $store->dos_window_days ?? '—' }}</dd>
            <dt class="col-5 text-muted">Par (hari)</dt><dd class="col-7">{{ $store->par_days ?? '—' }}</dd>
        </dl>
    </div>
</div>
@endsection
