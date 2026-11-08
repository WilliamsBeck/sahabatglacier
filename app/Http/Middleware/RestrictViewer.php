<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun Supervisor (hanya-lihat): boleh membuka halaman di toko yang di-assign, tapi
 * TIDAK boleh melakukan aksi tulis apa pun. Semua request non-GET (POST/PUT/PATCH/
 * DELETE) ditolak — kecuali logout, agar user tetap bisa keluar.
 *
 * Ini penjaga utamanya. Tombol tambah/edit/hapus/simpan yang disembunyikan di layout
 * hanya kenyamanan tampilan — kalaupun tombol lolos tampil, server tetap menolak.
 */
class RestrictViewer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user && $user->isSupervisor()
            && !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && !$request->routeIs('logout')
            && !$request->is('logout')
        ) {
            if ($request->expectsJson()) {
                abort(403, 'Akun Supervisor hanya bisa melihat data.');
            }
            return back()->with('error', 'Akun Supervisor hanya bisa melihat data — tidak dapat menambah, mengubah, atau menghapus.');
        }

        return $next($request);
    }
}
