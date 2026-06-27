{{-- Tombol Export + Impor untuk bundle multi-sheet.
     Param: $bundle (slug), $label (teks tampil), $exportUrl (opsional). --}}
@php $modalId = 'importBundleModal_' . str_replace('-', '_', $bundle); @endphp

<div class="btn-group">
    <a href="{{ $exportUrl ?? route('master.export-bundle', $bundle) }}" class="btn btn-outline-secondary">
        <i class="bi bi-database-down me-1"></i> Export Data
    </a>
    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#{{ $modalId }}">
        <i class="bi bi-upload me-1"></i> Impor
    </button>
</div>

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('master.import-bundle.preview', $bundle) }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Impor {{ $label }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Upload file Excel hasil Export Data (berisi sheet Menu & Resep).</p>
                    <input type="file" name="file" class="form-control" accept=".xlsx,.xls" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Pratinjau & Impor</button>
                </div>
            </div>
        </form>
    </div>
</div>
