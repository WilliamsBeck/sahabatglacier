<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun hanya-lihat (role 'viewer'): boleh membuka semua halaman, tapi TIDAK boleh
 * melakukan aksi tulis apa pun. Semua request non-GET (POST/PUT/PATCH/DELETE)
 * ditolak — kecuali logout, agar user tetap bisa keluar.
 */
class RestrictViewer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user && $user->isViewer()
            && !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && !$request->routeIs('logout')
            && !$request->is('logout')
        ) {
            if ($request->expectsJson()) {
                abort(403, 'Akun hanya-lihat: tidak dapat mengubah data.');
            }
            return back()->with('error', 'Akun hanya-lihat (demo) — tidak dapat menambah, mengubah, atau menghapus data.');
        }

        return $next($request);
    }
}
