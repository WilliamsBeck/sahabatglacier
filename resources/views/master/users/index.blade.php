@extends('layouts.app')
@section('title', 'User Management')

@section('content')
<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1 class="page-title">Manajemen User</h1>
        <p class="page-subtitle">Kelola hak akses dan data pengguna sistem</p>
    </div>
    <a href="{{ route('master.users.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Tambah User
    </a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-6">
                <input type="text" name="search" class="form-control"
                       placeholder="Cari nama atau email…" value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <select name="role" class="form-select">
                    <option value="">Semua Jenis Akun</option>
                    @foreach($roleBoleh as $r)
                        <option value="{{ $r }}" {{ request('role') === $r ? 'selected' : '' }}>{{ \App\Models\User::ROLES[$r] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2 justify-content-end">
                <button type="submit" class="btn btn-primary">Cari</button>
                <a href="{{ route('master.users.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-index table-balanced mb-0">
            <thead>
                <tr>
                    <th width="48">#</th>
                    <th class="col-name">User</th>
                    <th>Email</th>
                    <th>Jenis Akun</th>
                    <th>Toko Akses</th>
                    <th width="80">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td class="text-soft">{{ $users->firstItem() + $loop->index }}</td>
                        <td class="col-name">
                            <span class="fw-medium">{{ $user->name }}</span>
                        </td>
                        <td><span class="text-soft">{{ $user->email }}</span></td>
                        <td>
                            @php $warna = ['super_admin' => 'bg-danger', 'manager' => 'bg-warning text-dark', 'admin_area' => 'bg-primary', 'supervisor' => 'bg-secondary'][$user->role] ?? 'bg-light text-dark'; @endphp
                            <span class="badge {{ $warna }}">{{ $user->roleLabel() }}</span>
                        </td>
                        <td>
                            @if($user->isSuperAdmin())
                                <span class="text-soft"><i class="bi bi-infinity me-1"></i> Semua Toko</span>
                            @else
                                <span class="badge bg-success">{{ $user->stores->count() }} Toko</span>
                            @endif
                        </td>
                        <td>
                            <x-action-menu>
                                <x-action-edit :href="route('master.users.edit', $user)" />
                                @if($user->id !== auth()->id())
                                    <x-action-delete :action="route('master.users.destroy', $user)"
                                                     confirm="Hapus user ini secara permanen?" />
                                @endif
                            </x-action-menu>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-5 text-soft text-center">
                            <i class="bi bi-people fs-3 d-block mb-2 opacity-25"></i>
                            Belum ada data user.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($users->hasPages())
        <div class="card-footer d-flex justify-content-between align-items-center">
            <div class="text-soft">
                Menampilkan {{ $users->firstItem() }}–{{ $users->lastItem() }} dari {{ $users->total() }}
            </div>
            <div>{{ $users->withQueryString()->links() }}</div>
        </div>
    @endif
</div>
@endsection
