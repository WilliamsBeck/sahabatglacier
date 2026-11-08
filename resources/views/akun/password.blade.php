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

                    <div class="mb-3">
                        <label class="form-label">Password Lama {{ $wajib ? '(password sementara)' : '' }} <span class="text-danger">*</span></label>
                        <input type="password" name="current_password" required autocomplete="current-password"
                               class="form-control @error('current_password') is-invalid @enderror">
                        @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password Baru <span class="text-danger">*</span></label>
                        <input type="password" name="password" required minlength="8" autocomplete="new-password"
                               placeholder="Minimal 8 karakter"
                               class="form-control @error('password') is-invalid @enderror">
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Ulangi Password Baru <span class="text-danger">*</span></label>
                        <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"
                               class="form-control">
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
