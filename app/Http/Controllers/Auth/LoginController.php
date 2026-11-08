<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    /** Batas percobaan login gagal per IP sebelum dijeda. */
    private const MAKS_GAGAL   = 5;
    private const JEDA_DETIK   = 60;

    public function showForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            // Kolom 'username' menerima username ATAU email (nama kolom dipertahankan
            // supaya isian browser yang tersimpan tetap terisi).
            'username' => ['required', 'string'],
            'password' => ['required'],
        ]);

        // Jeda setelah terlalu banyak gagal dari IP yang sama — menahan tebak-tebakan
        // password. Dihitung per IP (bukan per akun) supaya penyerang tidak bisa sekadar
        // berganti username, dan satu akun tidak bisa dikunci orang lain dari luar.
        $kunci = 'login|' . $request->ip();
        if (RateLimiter::tooManyAttempts($kunci, self::MAKS_GAGAL)) {
            $detik = RateLimiter::availableIn($kunci);
            return back()->withErrors([
                'username' => "Terlalu banyak percobaan login yang gagal. Silakan coba lagi dalam {$detik} detik.",
            ])->onlyInput('username');
        }

        // Username Glacier hanya huruf/angka/garis (alpha_dash), jadi isian berisi '@'
        // pasti email — tidak ada yang ambigu.
        $kolom = str_contains($request->username, '@') ? 'email' : 'username';

        if (Auth::attempt([$kolom => $request->username, 'password' => $request->password], $request->boolean('remember'))) {
            RateLimiter::clear($kunci);
            $request->session()->regenerate();
            // Penanda sekali-pakai untuk popup sambutan setelah login
            $request->session()->flash('just_logged_in', true);

            // Password sementara (akun baru / di-reset) wajib diganti dulu. Halaman yang
            // tadi dibuka tetap tersimpan sebagai tujuan — dibuka setelah password diganti.
            if (Auth::user()->wajib_ganti_password) {
                return redirect()->route('akun.password.edit');
            }
            // Kembali ke halaman yang tadi dibuka sebelum diminta login.
            return redirect()->intended(route('dashboard'));
        }

        RateLimiter::hit($kunci, self::JEDA_DETIK);

        // Sengaja tidak menyebut mana yang salah (username/email atau password), supaya
        // tidak membocorkan akun mana yang terdaftar.
        return back()->withErrors([
            'username' => 'Username/email atau password salah.',
        ])->onlyInput('username');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
