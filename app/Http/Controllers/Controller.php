<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Tolak (403) bila user tidak boleh mengakses toko pemilik data ini.
     * Dipanggil di awal aksi yang membuka data lewat nomor ID (show/edit/update/
     * destroy/approve/…), karena CheckStoreAccess hanya melihat toko di URL/form —
     * bukan toko pemilik data yang sedang dibuka. Aturannya: User::bisaAksesToko().
     */
    protected function wajibAksesToko(?int $storeId): void
    {
        abort_unless(auth()->user()->bisaAksesToko($storeId), 403, 'Anda tidak memiliki akses ke toko ini.');
    }

    /** Sama seperti wajibAksesToko, tapi cukup SALAH SATU toko (mis. pengirim atau penerima transfer). */
    protected function wajibAksesSalahSatuToko(array $storeIds): void
    {
        $user = auth()->user();
        abort_unless(collect($storeIds)->contains(fn ($id) => $user->bisaAksesToko((int) $id)),
            403, 'Anda tidak memiliki akses ke toko ini.');
    }
}
