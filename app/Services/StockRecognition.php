<?php
namespace App\Services;

use App\Models\Mutation;
use Illuminate\Support\Facades\DB;

/**
 * Aturan TANGGAL PENGAKUAN STOK — satu sumber untuk seluruh sistem.
 *
 * Barang MASUK diakui saat DITERIMA (delivery_date), barang KELUAR diakui saat
 * DIKIRIM (transaction_date). Dua tanggal yang berbeda inilah yang membuat
 * "barang dalam perjalanan" ada:
 *
 *      kirim 10 Sep ----------> tiba 5 Okt
 *      stok toko A turun       stok toko B naik
 *      (10 Sep)                (5 Okt)
 *              \______________/
 *               dalam perjalanan
 *
 * Sebelum ini, sisi KELUAR juga memakai delivery_date. Akibatnya stok toko
 * pengirim baru berkurang di bulan barang itu tiba — padahal barangnya sudah
 * tidak ada di gudang. Opname di toko pengirim jadi menampilkan stok hantu,
 * dan FIFO (yang memotong begitu mutasi dikonfirmasi, tanpa melihat tanggal)
 * bertentangan dengan angka di Saldo Stok / Opname / Pencatatan Harian.
 *
 * CATATAN: rumus lama dan baru menghasilkan angka IDENTIK bila tanggal kirim
 * sama dengan tanggal terima — jadi data yang sudah ada tidak bergeser.
 */
class StockRecognition
{
    /** Jenis mutasi yang menambah stok toko tujuan. */
    public const MASUK = ['purchase_zhisheng', 'purchase_supplier', 'sale_internal', 'sale_external'];

    /** Jenis mutasi yang mengurangi stok toko sumber. */
    public const KELUAR = ['sale_internal', 'sale_external_out'];

    /**
     * Ekspresi SQL tanggal pengakuan barang MASUK: tanggal terima.
     * COALESCE dipertahankan karena opening_stock boleh tanpa delivery_date.
     */
    public static function sqlMasuk(string $alias = 'mutations'): string
    {
        return "COALESCE({$alias}.delivery_date, {$alias}.transaction_date)";
    }

    /** Ekspresi SQL tanggal pengakuan barang KELUAR: tanggal kirim. */
    public static function sqlKeluar(string $alias = 'mutations'): string
    {
        return "{$alias}.transaction_date";
    }

    /**
     * Tanggal yang harus dicek terhadap kunci periode, PER TOKO.
     *
     * Karena pengakuannya beda per sisi, kuncinya juga harus dicek per sisi:
     * toko pengirim dicek di tanggal KIRIM, toko penerima di tanggal TERIMA.
     * Kalau dua-duanya dicek pakai tanggal terima saja, transfer yang dikirim
     * di bulan yang sudah dikunci opname tetap bisa dikonfirmasi — dan itu
     * merusak periode yang sudah dibekukan di toko pengirim.
     *
     * @return array<int,string>  [store_id => tanggal]
     */
    public static function tanggalKunci(Mutation $mutation): array
    {
        $terima = ($mutation->delivery_date ?? $mutation->transaction_date)->toDateString();
        $kirim  = $mutation->transaction_date->toDateString();
        $out    = [];

        if ($mutation->destination_store_id) {
            $out[(int) $mutation->destination_store_id] = $terima;
        }
        if ($mutation->source_store_id) {
            $sid  = (int) $mutation->source_store_id;
            $tgl  = in_array($mutation->type, self::KELUAR, true) ? $kirim : $terima;
            // Bila toko yang sama jadi pengirim & penerima, ambil tanggal TERAWAL
            // supaya pemeriksaan kuncinya yang paling ketat.
            $out[$sid] = isset($out[$sid]) ? min($out[$sid], $tgl) : $tgl;
        }
        return $out;
    }

    /**
     * Barang dalam perjalanan pada tanggal tertentu: sudah dikirim, belum diterima.
     * Dihitung, bukan disimpan — jadi tidak mungkin melenceng dari data mutasi.
     *
     * @param  int|null $storeId  batasi ke toko ini (sebagai pengirim ATAU penerima)
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function dalamPerjalanan(?int $storeId = null, ?string $asOf = null)
    {
        $asOf = $asOf ?: now()->toDateString();

        return Mutation::with([
                'items.ingredient:id,name,unit_base',
                'items.packaging:id,crate_to_pack,pack_to_base',
                'sourceStore:id,name',
                'destinationStore:id,name',
            ])
            ->where('status', 'confirmed')
            ->where('type', 'sale_internal')
            ->whereNotNull('delivery_date')
            ->where('transaction_date', '<=', $asOf)
            ->where('delivery_date', '>', $asOf)
            ->when($storeId, fn($q) => $q->where(fn($w) => $w
                ->where('source_store_id', $storeId)
                ->orWhere('destination_store_id', $storeId)))
            ->orderBy('delivery_date')->orderBy('id')
            ->get();
    }

    /**
     * Total base unit dalam perjalanan per (bahan × kemasan), dilihat dari sisi
     * toko PENERIMA — yaitu barang yang akan masuk tapi belum diakui.
     *
     * @return array<string,float>  kunci "bahan-kemasan"
     */
    public static function masukTertundaPerKemasan(int $storeId, ?string $asOf = null): array
    {
        $asOf = $asOf ?: now()->toDateString();

        $rows = DB::table('mutation_items as mi')
            ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
            ->where('m.status', 'confirmed')
            ->where('m.type', 'sale_internal')
            ->where('m.destination_store_id', $storeId)
            ->whereNotNull('m.delivery_date')
            ->where('m.transaction_date', '<=', $asOf)
            ->where('m.delivery_date', '>', $asOf)
            ->selectRaw('mi.ingredient_id, mi.packaging_id, SUM(mi.total_in_base) t')
            ->groupBy('mi.ingredient_id', 'mi.packaging_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->ingredient_id . '-' . ($r->packaging_id ?: 0)] = (float) $r->t;
        }
        return $out;
    }
}
