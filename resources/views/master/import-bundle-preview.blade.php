@extends('layouts.app')
@section('title', 'Pratinjau Impor - ' . $cfg['label'])

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1 class="page-title">Pratinjau Impor — {{ $cfg['label'] }}</h1>
        <p class="page-subtitle">Periksa tiap sheet di bawah sebelum disimpan.</p>
    </div>
    <a href="{{ route($cfg['route_index']) }}" class="btn btn-outline-secondary btn-sm btn-back">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($result['has_error'])
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Masih ada baris bermasalah. Perbaiki file lalu unggah ulang — impor hanya bisa dilanjutkan
        jika semua sheet bersih.
    </div>
@endif

@foreach($result['members'] as $slug => $m)
    @php $s = $m['parsed']['summary']; $headers = array_map(fn($c) => $c['header'], $m['cfg']['columns']); @endphp
    <div class="card mb-3">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table me-1"></i> Sheet "{{ $m['sheet'] }}" — {{ $m['cfg']['label'] }}</span>
            <span class="small">
                <span class="badge bg-success-subtle text-success">{{ $s['new'] }} baru</span>
                <span class="badge bg-primary-subtle text-primary">{{ $s['update'] }} update</span>
                @if($s['error'] > 0)<span class="badge bg-danger-subtle text-danger">{{ $s['error'] }} error</span>@endif
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-index mb-0 align-middle">
                <thead>
                    <tr>
                        <th width="60">Baris</th><th width="90">Status</th>
                        @foreach($headers as $h)<th>{{ $h }}</th>@endforeach
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($m['parsed']['rows'] as $row)
                    <tr class="{{ $row['status'] === 'error' ? 'table-danger' : '' }}">
                        <td class="text-muted">{{ $row['row_num'] }}</td>
                        <td>
                            @if($row['status'] === 'new')<span class="badge bg-success-subtle text-success">Baru</span>
                            @elseif($row['status'] === 'update')<span class="badge bg-primary-subtle text-primary">Update</span>
                            @else<span class="badge bg-danger-subtle text-danger">Error</span>@endif
                        </td>
                        @foreach($headers as $h)<td class="small">{{ $row['display'][$h] ?? '' }}</td>@endforeach
                        <td class="small text-danger">{{ implode(' | ', $row['errors']) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="{{ count($headers) + 3 }}" class="text-center text-muted py-3">Sheet kosong.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endforeach

<div class="d-flex justify-content-end gap-2 mt-3">
    <a href="{{ route($cfg['route_index']) }}" class="btn btn-outline-secondary">Batal</a>
    <form method="POST" action="{{ route('master.import-bundle.commit', $bundle) }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <button type="submit" class="btn btn-primary" {{ ($result['has_error'] || $result['total_save'] === 0) ? 'disabled' : '' }}>
            <i class="bi bi-check-lg me-1"></i> Konfirmasi & Simpan ({{ $result['total_save'] }})
        </button>
    </form>
</div>
@endsection
