<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckStoreAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        // Hanya super admin yang bebas akses semua toko. Manager, admin area, dan
        // supervisor dibatasi ke toko yang di-assign.
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // Toko "milik" akun untuk transaksi ini:
        // - Transfer/penjualan keluar → toko PENGIRIM (source) yang harus milik akun;
        //   toko PENERIMA boleh toko mana pun.
        // - Pembelian/masuk → toko PENERIMA (destination).
        $storeId = $request->route('store')
                ?? $request->input('store_id')
                ?? $request->input('source_store_id')
                ?? $request->input('destination_store_id');

        // Selain super admin hanya boleh akses toko yang di-assign (aturan: User::bisaAksesToko).
        // {store} bisa berupa model (route model binding) atau angka.
        $storeId = $storeId instanceof \App\Models\Store ? $storeId->id : $storeId;
        if ($storeId && !$user->bisaAksesToko((int) $storeId)) {
            abort(403, 'Anda tidak memiliki akses ke toko ini.');
        }

        return $next($request);
    }
}
