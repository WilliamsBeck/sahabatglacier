<?php

namespace App\Http\Controllers\Akun;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Ganti password akun sendiri — untuk semua jenis akun (PBI-105). Juga tujuan wajib
 * bagi akun yang masih memakai password sementara (lihat WajibGantiPassword).
 */
class PasswordController extends Controller
{
    public function edit(Request $request)
    {
        return view('akun.password', ['wajib' => (bool) $request->user()->wajib_ganti_password]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ], [
            'current_password.required'         => 'Password lama wajib diisi.',
            'current_password.current_password' => 'Password lama salah.',
            'password.required'                 => 'Password baru wajib diisi.',
            'password.min'                      => 'Password baru minimal 8 karakter.',
            'password.confirmed'                => 'Konfirmasi password baru tidak sama.',
            'password.different'                => 'Password baru harus berbeda dari password lama.',
        ]);

        $user = $request->user();
        // saveQuietly: perubahan password dicatat sendiri di bawah TANPA nilainya, bukan
        // lewat AuditObserver. (Kolom password di-hash otomatis oleh cast 'hashed'.)
        $user->forceFill(['password' => $request->password, 'wajib_ganti_password' => false])->saveQuietly();

        AuditLog::record('updated', 'User', $user->id,
            "Password diganti oleh pemilik akun: {$user->name} ({$user->username})");

        // Kembali ke halaman yang tadi dituju sebelum diminta ganti password.
        return redirect()->intended(route('dashboard'))->with('success', 'Password berhasil diganti.');
    }
}
