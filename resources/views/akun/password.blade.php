@extends('layouts.app')
@section('title', 'Ganti Password')

@section('content')
<div class="row">
    <div class="col-xl-5 col-lg-7">
        <div class="page-header">
            <h1 class="page-title">Ganti Password</h1>
            <p class="page-subtitle">{{ auth()->user()->name }} · {{ auth()->user()->username }}</p>
        </div>

        @if($wajib)
            <div class="alert alert-warning d-flex gap-2 align-items-start">
                <i class="bi bi-shield-lock-fill fs-5"></i>
                <div>
                    <strong>Anda masih memakai password sementara.</strong>
                    Ganti dengan password pilihan Anda sendiri sebelum melanjutkan.
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-body">
                {{-- data-boleh-tulis: tetap bisa dipakai Supervisor (mode hanya-lihat) --}}
                <form method="POST" action="{{ route('akun.password.update') }}" data-boleh-tulis>
                    @csrf @method('PUT')

                    {{-- Tombol mata (.lihat-pwd) menampilkan/menyembunyikan isian — sama seperti di halaman login --}}
                    <div class="mb-3">
                        <label class="form-label">Password Lama {{ $wajib ? '(password sementara)' : '' }} <span class="text-danger">*</span></label>
                        <div class="input-group has-validation">
                            <input type="password" name="current_password" required autocomplete="current-password"
                                   class="form-control @error('current_password') is-invalid @enderror">
                            <button type="button" class="btn btn-outline-secondary lihat-pwd" aria-label="Tampilkan password"><i class="bi bi-eye"></i></button>
                            @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password Baru <span class="text-danger">*</span></label>
                        <div class="input-group has-validation">
                            <input type="password" name="password" required minlength="8" autocomplete="new-password"
                                   placeholder="Minimal 8 karakter"
                                   class="form-control @error('password') is-invalid @enderror">
                            <button type="button" class="btn btn-outline-secondary lihat-pwd" aria-label="Tampilkan password"><i class="bi bi-eye"></i></button>
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Ulangi Password Baru <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"
                                   class="form-control">
                            <button type="button" class="btn btn-outline-secondary lihat-pwd" aria-label="Tampilkan password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-key-fill me-1"></i> Simpan Password Baru
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.lihat-pwd').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = btn.parentElement.querySelector('input');
        var tampil = input.type === 'password';
        input.type = tampil ? 'text' : 'password';
        btn.querySelector('i').className = tampil ? 'bi bi-eye-slash' : 'bi bi-eye';
        btn.setAttribute('aria-label', tampil ? 'Sembunyikan password' : 'Tampilkan password');
    });
});
</script>
@endpush
