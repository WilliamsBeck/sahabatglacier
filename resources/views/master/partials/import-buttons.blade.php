{{-- Tombol Download Template + Impor untuk satu entitas master.
     Param: $entity (slug registry), $label (teks tampil). --}}
@php $modalId = 'importModal_' . str_replace('-', '_', $entity); @endphp

<div class="btn-group">
    <a href="{{ route('master.import.template', $entity) }}" class="btn btn-outline-success">
        <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Template
    </a>
    <a href="{{ route('master.export', $entity) }}" class="btn btn-outline-secondary">
        <i class="bi bi-database-down me-1"></i> Export Data
    </a>
    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#{{ $modalId }}">
        <i class="bi bi-upload me-1"></i> Impor
    </button>
</div>

@include('master.partials.import-modal', ['entity' => $entity, 'label' => $label ?? ''])
