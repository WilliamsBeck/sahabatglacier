@extends('layouts.app')
@section('title', isset($user) ? 'Edit User' : 'Tambah User')

@section('content')

<div class="pb-2">
    <div class="row">
        <div class="col-xl-9 col-lg-11">

                <div class="page-header d-flex justify-content-between align-items-start">
                    <div>
                        <h1 class="page-title">{{ isset($user) ? 'Edit User' : 'Tambah User' }}</h1>
                        <p class="page-subtitle">{{ isset($user) ? 'Perbarui data dan hak akses pengguna' : 'Buat akun pengguna sistem baru' }}</p>
                    </div>
                    <a href="{{ route('master.users.index') }}" class="btn btn-outline-secondary btn-back">
                        <i class="bi bi-arrow-left me-1"></i>Kembali
                    </a>
                </div>

                <div class="d-flex flex-column gap-4">
                    
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-3">
                                @if(isset($user))
                                    <div class="d-flex align-items-center justify-content-center rounded bg-dark text-white fw-bold flex-shrink-0" style="width:46px;height:46px;font-size:1.2rem">
                                        {{ strtoupper(substr($user->name, 0, 1)) }}
                                    </div>
                                @else
                                    <div class="d-flex align-items-center justify-content-center rounded bg-primary bg-opacity-10 text-primary flex-shrink-0" style="width:46px;height:46px">
                                        <i class="bi bi-person-plus-fill fs-4"></i>
                                    </div>
                                @endif
                                <div>
                                    <h4 class="fw-semibold mb-0">
                                        {{ isset($user) ? 'Perbarui Data User' : 'Buat User Sistem Baru' }}
                                    </h4>
                                    @if(isset($user))
                                        <div class="text-muted small mt-1">ID User: {{ $user->username }}</div>
                                    @else
                                        <div class="text-muted small mt-1">Lengkapi data kredensial login di bawah ini</div>
                                    @endif
                                </div>
                            </div>
                            <span class="badge {{ isset($user) ? 'bg-primary' : 'bg-success' }} rounded-pill px-3 py-2 fw-semibold" style="font-size: 0.75rem;">
                                {{ isset($user) ? 'Mode Edit' : 'User Baru' }}
                            </span>
                        </div>
                        
                        <div class="card-body">
                            <form method="POST" action="{{ isset($user) ? route('master.users.update', $user) : route('master.users.store') }}">
                                @csrf @if(isset($user)) @method('PUT') @endif
                                
                                <div class="row g-4">
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                        <input type="text" name="name" class="form-control" value="{{ old('name', $user->name ?? '') }}" placeholder="cth: John Doe" required>
                                    </div>
                                    
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Username (Login ID) <span class="text-danger">*</span></label>
                                        <input type="text" name="username" class="form-control" value="{{ old('username', $user->username ?? '') }}" placeholder="cth: johndoe99" required>
                                    </div>
                                    
                                    <div class="col-12 mb-2">
                                        <label class="form-label">Alamat Email <span class="text-danger">*</span></label>
                                        <input type="email" name="email" class="form-control" value="{{ old('email', $user->email ?? '') }}" placeholder="johndoe@email.com" required>
                                    </div>
                                    
                                    <div class="col-12 mb-2">
                                        <label class="form-label">Role Akses Sistem <span class="text-danger">*</span></label>
                                        <select name="role" class="form-select" required>
                                            <option value="">— Pilih Level Akses —</option>
                                            <option value="super_admin" {{ old('role', $user->role ?? '') === 'super_admin' ? 'selected' : '' }}>Super Admin (Akses Penuh Seluruh Toko)</option>
                                            <option value="admin_area" {{ old('role', $user->role ?? '') === 'admin_area' ? 'selected' : '' }}>Admin Area (Terbatas pada Toko yang Ditentukan)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">
                                            Password 
                                            @if(isset($user)) 
                                                <span class="text-muted fw-normal" style="font-size: 0.78rem;">(Biarkan kosong jika tidak ingin mengubah)</span> 
                                            @else 
                                                <span class="text-danger">*</span> 
                                            @endif
                                        </label>
                                        <input type="password" name="password" class="form-control" placeholder="Minimal 8 karakter" {{ isset($user) ? '' : 'required' }} minlength="8">
                                    </div>
                                    
                                    <div class="col-md-6 mb-4">
                                        <label class="form-label">Ulangi Password Baru</label>
                                        <input type="password" name="password_confirmation" class="form-control" placeholder="Konfirmasi password" minlength="8">
                                    </div>
                                    
                                    <div class="col-12 pt-3 border-top">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="bi bi-save2-fill me-2"></i>{{ isset($user) ? 'Simpan Perubahan Data' : 'Daftarkan User Baru Sekarang' }}
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    @if(isset($user) && $user->role === 'admin_area')
                        <div class="card mb-5">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="d-flex align-items-center justify-content-center rounded bg-success bg-opacity-10 text-success flex-shrink-0" style="width:46px;height:46px">
                                        <i class="bi bi-shop-window fs-4"></i>
                                    </div>
                                    <div>
                                        <h4 class="fw-semibold mb-0">Otorisasi Akses Toko</h4>
                                        <div class="text-muted small mt-1">Tentukan toko mana saja yang dapat dikelola oleh user ini</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card-body">
                                <div class="alert alert-info mb-4 d-flex gap-3 align-items-start">
                                    <i class="bi bi-patch-check-fill fs-4 mt-1"></i>
                                    <div>
                                        <div class="fw-bold mb-1">Status: Admin Area</div>
                                        User dengan role Admin Area **wajib** diberikan akses ke minimal satu toko agar dapat bekerja. Pilih toko dari daftar di bawah untuk menambah akses.
                                    </div>
                                </div>

                                @php $availableStores = $stores->filter(fn($s) => !in_array($s->id, $assignedStores)); @endphp
                                @if($availableStores->isNotEmpty())
                                <form method="POST" action="{{ route('master.users.assign-store', $user) }}" class="mb-5 p-4 border rounded-4 bg-light bg-opacity-50">
                                    @csrf
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label fw-semibold mb-0">Pilih Toko untuk Ditambahkan</label>
                                        <a href="#" class="small" onclick="toggleAllStores(this);return false;">Pilih Semua</a>
                                    </div>
                                    <div class="border rounded bg-white mb-3" id="storeChecklistBox">
                                        @foreach($availableStores as $s)
                                        <div class="form-check px-3 py-2 border-bottom">
                                            <input class="form-check-input store-cb" type="checkbox" name="store_ids[]" value="{{ $s->id }}" id="store_{{ $s->id }}">
                                            <label class="form-check-label w-100" for="store_{{ $s->id }}">
                                                <span class="fw-semibold">{{ $s->name }}</span>
                                                <span class="text-muted small ms-2">({{ $s->store_code }}) — {{ $s->area }}</span>
                                            </label>
                                        </div>
                                        @endforeach
                                    </div>
                                    <button type="submit" class="btn btn-primary fw-bold px-4" style="border-radius: 14px;">
                                        <i class="bi bi-plus-lg me-1"></i>Tambah yang Dipilih
                                    </button>
                                    <script>
                                    function toggleAllStores(el) {
                                        const cbs = document.querySelectorAll('.store-cb');
                                        const allChecked = [...cbs].every(c => c.checked);
                                        cbs.forEach(c => c.checked = !allChecked);
                                        el.textContent = allChecked ? 'Pilih Semua' : 'Batal Semua';
                                    }
                                    </script>
                                </form>
                                @endif
                                
                                <div class="mt-2">
                                    <h6 class="fw-bold text-dark mb-3 px-1" style="font-size: 1rem;">Daftar Toko Terotorisasi ({{ $user->stores->count() }})</h6>
                                    
                                    <div class="border rounded p-2" style="max-height:350px;overflow-y:auto">
                                        @forelse($user->stores as $s)
                                            <div class="d-flex align-items-center p-3 border rounded mb-2">
                                                <div class="bg-light text-secondary rounded-circle d-flex align-items-center justify-content-center me-3 flex-shrink-0" style="width: 44px; height: 44px; border: 1px solid #e2e8f0;">
                                                    <i class="bi bi-shop fs-5"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="fw-bold text-dark" style="font-size: 0.98rem;">{{ $s->name }}</div>
                                                    <div class="d-flex align-items-center gap-2 mt-1">
                                                        <span class="badge bg-light text-secondary border px-2 py-1" style="font-size: 0.7rem; border-radius: 6px;">Kode: {{ $s->store_code }}</span>
                                                        <span class="text-muted small"><i class="bi bi-geo-alt-fill me-1"></i>{{ $s->area }}</span>
                                                    </div>
                                                </div>
                                                <form method="POST" action="{{ route('master.users.revoke-store', [$user, $s]) }}" class="d-inline m-0 ms-3" data-confirm="Apakah Anda yakin ingin mencabut otorisasi akses toko {{ $s->name }} dari user ini?" data-confirm-type="warning" data-confirm-ok="Ya, cabut">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="btn btn-outline-danger btn-sm p-3" title="Cabut Otorisasi">
                                                        <i class="bi bi-x-lg fw-bold"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        @empty
                                            <div class="text-center py-5 d-flex flex-column align-items-center justify-content-center h-100 text-muted">
                                                <i class="bi bi-building-exclamation fs-1 mb-3 opacity-50"></i>
                                                <span class="fw-medium small">Belum ada otorisasi toko untuk user ini. <br> User tidak akan bisa login sebelum toko di-assign.</span>
                                            </div>
                                        @endforelse
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection