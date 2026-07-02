<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Contoh penggunaan di route:
     * Route::middleware(['role:super_admin'])
     * Route::middleware(['role:super_admin,admin_area'])
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        // Akun hanya-lihat boleh membuka semua halaman (aksi tulis diblok terpisah).
        if (auth()->user()->isViewer()) {
            return $next($request);
        }

        if (!in_array(auth()->user()->role, $roles)) {
            abort(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        return $next($request);
    }
}
