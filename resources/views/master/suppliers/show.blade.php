@extends('layouts.app')
@section('title', 'Detail Supplier')

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1 class="page-title">{{ $supplier->name }}</h1>
        <p class="page-subtitle">Detail supplier (hanya lihat)</p>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        @if(auth()->user()->isSuperAdmin())
        <a href="{{ route('master.suppliers.edit', $supplier) }}" class="btn btn-primary">
            <i class="bi bi-pencil me-1"></i> Edit
        </a>
        @endif
        <a href="{{ route('master.suppliers.index') }}" class="btn btn-outline-secondary btn-sm btn-back">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

<div class="card" style="max-width:640px">
    <div class="card-body">
        <dl class="row mb-0 small">
            <dt class="col-4 text-muted">Nama</dt><dd class="col-8">{{ $supplier->name }}</dd>
            <dt class="col-4 text-muted">Tipe</dt><dd class="col-8">{{ $supplier->type_label ?? $supplier->type }}</dd>
            <dt class="col-4 text-muted">Kontak</dt><dd class="col-8">{{ $supplier->contact ?? '—' }}</dd>
            <dt class="col-4 text-muted">Alamat</dt><dd class="col-8">{{ $supplier->address ?? '—' }}</dd>
            <dt class="col-4 text-muted">Status</dt>
            <dd class="col-8">
                <span class="badge bg-{{ $supplier->is_active ? 'success' : 'secondary' }}">
                    {{ $supplier->is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
            </dd>
        </dl>
    </div>
</div>
@endsection
