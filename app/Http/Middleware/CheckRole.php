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
     * Route::middleware(['role:super_admin,manager,admin_area'])
     *
     * Setiap route menyebut role yang boleh secara eksplisit. Dulu akun viewer lolos
     * semua cek di sini; supervisor tidak — dia hanya masuk ke route yang menyebutnya.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        if (!in_array(auth()->user()->role, $roles, true)) {
            abort(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        return $next($request);
    }
}
