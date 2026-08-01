<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Mengingat toko yang dipilih di pemilih toko (topbar) ke dalam session.
 *
 * Pemilih toko me-reload halaman dengan ?store_id=..., jadi nilainya cukup
 * dibaca dari query. Halaman yang wajib memilih satu toko (mis. Analisa HPP)
 * memakai nilai ini sebagai pilihan awal, supaya tidak perlu dipilih dua kali.
 */
class RememberActiveStore
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->filled('store_id') && auth()->check()) {
            $storeId = (int) $request->input('store_id');
            if (in_array($storeId, auth()->user()->accessibleStoreIds(), true)) {
                session(['active_store_id' => $storeId]);
            }
        }

        return $next($request);
    }
}
