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
     *
     * Transfer internal yang tanggal terimanya masih KOSONG berarti barang masih
     * di jalan — belum diterima siapa pun. Untuk kasus itu ekspresi ini bernilai
     * NULL, sehingga perbandingan tanggal apa pun (<=, BETWEEN, <) otomatis TIDAK
     * cocok dan barangnya tidak terhitung sebagai stok toko tujuan. Begitu tanggal
     * terimanya diisi lewat aksi "Terima Barang", barangnya langsung muncul.
     *
     * COALESCE tetap dipakai untuk jenis lain karena opening_stock memang tidak
     * punya delivery_date, dan pembelian selalu wajib mengisinya.
     */
    public static function sqlMasuk(string $alias = 'mutations'): string
    {
        return "CASE WHEN {$alias}.type = 'sale_internal' AND {$alias}.delivery_date IS NULL"
             . " THEN NULL ELSE COALESCE({$alias}.delivery_date, {$alias}.transaction_date) END";
    }

    /**
     * Tanggal pengakuan barang masuk menurut KEPEMILIKAN (bukan keberadaan fisik).
     *
     * Untuk transfer internal, kepemilikan pindah ke toko tujuan saat barang
     * DIKIRIM — sejak saat itu barang tersebut sudah "milik" toko tujuan walau
     * fisiknya masih di jalan. Dipakai HANYA untuk valuasi persediaan & HPP.
     *
     * Sengaja dipisah dari sqlMasuk() yang berbasis fisik: Saldo Stok, FIFO,
     * Stok Sistem, dan Pencatatan Harian tetap hanya mengakui barang yang sudah
     * benar-benar ada di gudang.
     */
    public static function sqlMasukKepemilikan(string $alias = 'mutations'): string
    {
        return "CASE WHEN {$alias}.type = 'sale_internal' THEN {$alias}.transaction_date"
             . " ELSE COALESCE({$alias}.delivery_date, {$alias}.transaction_date) END";
    }

    /**
     * Barang milik toko tujuan yang masih di perjalanan pada tanggal tertentu,
     * per BAHAN: qty base + nilai rupiah.
     *
     * Dipakai untuk menambah SO Awal/SO Akhir dalam perhitungan HPP. Ini pasangan
     * wajib dari sqlMasukKepemilikan(): kalau barangnya diakui masuk saat dikirim
     * tapi TIDAK ikut menambah stok akhir, konsumsi HPP toko tujuan akan melonjak
     * sebesar barang yang belum datang.
     *
     * @return array<int,array{base: float, nilai: float}>
     */
    public static function transitTujuanPerBahan(int $storeId, string $asOf): array
    {
        $rows = DB::table('mutation_items as mi')
            ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
            ->where('m.status', 'confirmed')
            ->where('m.type', 'sale_internal')
            ->where('m.destination_store_id', $storeId)
            ->where('m.transaction_date', '<=', $asOf)
            ->where(fn($q) => $q->whereNull('m.delivery_date')->orWhere('m.delivery_date', '>', $asOf))
            ->selectRaw('mi.ingredient_id,
                         SUM(mi.total_in_base) base,
                         SUM(mi.total_in_base * mi.price_per_base) nilai')
            ->groupBy('mi.ingredient_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->ingredient_id] = ['base' => (float) $r->base, 'nilai' => (float) $r->nilai];
        }
        return $out;
    }

    /**
     * Barang dalam perjalanan milik toko tujuan per (bahan × kemasan) — untuk
     * kolom "DALAM PERJALANAN" di Stok Opname. Hanya tampilan; angka Stok Sistem
     * tetap fisik supaya Selisih opname tidak memunculkan kekurangan palsu.
     *
     * @return array<string,float>  kunci "bahan-kemasan"
     */
    public static function transitTujuanPerKemasan(int $storeId, ?string $asOf = null): array
    {
        return self::masukTertundaPerKemasan($storeId, $asOf);
    }

    /**
     * Barang dalam perjalanan per (bahan × kemasan) LENGKAP dengan nilai rupiahnya.
     * Nilainya memakai harga di baris transfer itu sendiri — harga yang sama yang
     * dipakai HPP lewat transitTujuanPerBahan(), jadi angka di Opname dan di Analisa
     * HPP tidak akan saling bertentangan.
     *
     * @return array<string,array{base: float, nilai: float}>
     */
    public static function transitTujuanRinci(int $storeId, ?string $asOf = null): array
    {
        $asOf = $asOf ?: now()->toDateString();

        $rows = DB::table('mutation_items as mi')
            ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
            ->where('m.status', 'confirmed')
            ->where('m.type', 'sale_internal')
            ->where('m.destination_store_id', $storeId)
            ->where('m.transaction_date', '<=', $asOf)
            ->where(fn($q) => $q->whereNull('m.delivery_date')->orWhere('m.delivery_date', '>', $asOf))
            ->selectRaw('mi.ingredient_id, mi.packaging_id,
                         SUM(mi.total_in_base) base,
                         SUM(mi.total_in_base * mi.price_per_base) nilai')
            ->groupBy('mi.ingredient_id', 'mi.packaging_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->ingredient_id . '-' . ($r->packaging_id ?: 0)] = [
                'base'  => (float) $r->base,
                'nilai' => (float) $r->nilai,
            ];
        }
        return $out;
    }

    /** Total nilai rupiah barang dalam perjalanan milik toko ini. */
    public static function nilaiTransit(int $storeId, ?string $asOf = null): float
    {
        $t = 0.0;
        foreach (self::transitTujuanRinci($storeId, $asOf) as $v) $t += $v['nilai'];
        return $t;
    }

    /**
     * Predikat SQL "barang ini sudah benar-benar diterima toko tujuan".
     * Dipakai di tempat yang TIDAK membandingkan tanggal — mis. kumpulan batch FIFO
     * milik toko tujuan, yang tidak boleh memuat barang yang masih di perjalanan.
     */
    public static function sqlSudahDiterima(string $alias = 'mutations'): string
    {
        return "({$alias}.type <> 'sale_internal' OR {$alias}.delivery_date IS NOT NULL)";
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

        // Kalau tanggal terima masih kosong (barang di jalan), belum ada apa pun yang
        // masuk ke toko tujuan — jadi tidak ada periode tujuan yang perlu dicek.
        // Pemeriksaannya dilakukan nanti saat aksi "Terima Barang".
        $belumDiterima = $mutation->type === 'sale_internal' && !$mutation->delivery_date;
        if ($mutation->destination_store_id && !$belumDiterima) {
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
            ->where('transaction_date', '<=', $asOf)
            // Tanggal terima KOSONG = masih di jalan, belum tahu kapan tiba.
            ->where(fn($q) => $q->whereNull('delivery_date')->orWhere('delivery_date', '>', $asOf))
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
            ->where('m.transaction_date', '<=', $asOf)
            ->where(fn($q) => $q->whereNull('m.delivery_date')->orWhere('m.delivery_date', '>', $asOf))
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
