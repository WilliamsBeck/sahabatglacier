<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PBI-105: akun dengan password sementara (akun baru / password di-reset) wajib
 * mengganti password dulu. Sebelum itu semua halaman lain diarahkan ke halaman
 * Ganti Password — kecuali halaman itu sendiri dan logout.
 */
class WajibGantiPassword
{
    private const BOLEH = ['akun.password.edit', 'akun.password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user && $user->wajib_ganti_password && !$request->routeIs(...self::BOLEH)) {
            $pesan = 'Anda masih memakai password sementara. Silakan ganti password dulu.';
            if ($request->expectsJson()) {
                abort(403, $pesan);
            }
            return redirect()->route('akun.password.edit')->with('warning', $pesan);
        }

        return $next($request);
    }
}
