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

        $user    = auth()->user();
        $storeId = $request->route('store')
                ?? $request->input('store_id')
                ?? $request->input('destination_store_id')
                ?? $request->input('source_store_id');

        // Super admin & akun hanya-lihat bebas akses semua toko
        if ($user->isSuperAdmin() || $user->isViewer()) {
            return $next($request);
        }

        // Admin area hanya boleh akses toko yang di-assign
        if ($storeId && !$user->stores()->where('stores.id', $storeId)->exists()) {
            abort(403, 'Anda tidak memiliki akses ke toko ini.');
        }

        return $next($request);
    }
}
