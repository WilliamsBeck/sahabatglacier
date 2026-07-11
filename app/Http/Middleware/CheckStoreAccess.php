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

        // Super admin & akun hanya-lihat bebas akses semua toko
        if ($user->isSuperAdmin() || $user->isViewer()) {
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

        // Admin area hanya boleh akses toko yang di-assign
        if ($storeId && !$user->stores()->where('stores.id', $storeId)->exists()) {
            abort(403, 'Anda tidak memiliki akses ke toko ini.');
        }

        return $next($request);
    }
}
