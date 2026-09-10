<?php
namespace App\Services;

use App\Models\{MutationItem, DailyUsage, IngredientPackaging, WasteLogItem, OpnameItem};

/**
 * Saldo stok teoretis per (bahan × kemasan) — dipakai bareng oleh:
 *   - Stok Opname  : kolom "Stok Sistem"
 *   - Saldo Stok   : saldo bertanda (boleh minus)
 *   - Pencatatan Harian : stok akhir bulan berjalan & STOK AWAL bulan berikutnya
 *
 * Sengaja SATU sumber rumus. Dulu Pencatatan Harian menghitung stok awal dengan
 * rumusnya sendiri (masuk − keluar − pemakaian) yang MELEWATKAN waste dan selisih
 * negatif opname, memakai satu kemasan saja per bahan, dan membuang saldo minus.
 * Akibatnya "stok akhir Agustus" tidak sama dengan "stok awal September" selama
 * opname belum di-approve.
 */
class StockBalanceService
{
    /** Kunci peta: bahan-kemasan (kemasan null → 0). */
    public static function key($ingredientId, $packagingId): string
    {
        return $ingredientId . '-' . ($packagingId ?: 0);
    }

    /**
     * @param  string $asOfDate  batas tanggal (inklusif)
     * @return array{0: array<string,float>, 1: array<string,float>} [diterima, terpakai]
     */
    public static function receivedDemandMaps(int $storeId, string $asOfDate): array
    {
        $K = fn($i, $p) => self::key($i, $p);
        $recv = []; $dem = [];

        // Masuk: semua mutasi confirmed yang tujuannya toko ini — diakui saat DITERIMA
        foreach (MutationItem::whereHas('mutation', fn($q) =>
                    $q->where('destination_store_id', $storeId)->where('status', 'confirmed')
                      ->whereRaw(StockRecognition::sqlMasuk() . ' <= ?', [$asOfDate])
                )
                ->selectRaw('ingredient_id, packaging_id, SUM(total_in_base) t')
                ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
            $recv[$K($r->ingredient_id, $r->packaging_id)] = (float) $r->t;
        }

        // Keluar: transfer internal & penjualan eksternal keluar — diakui saat DIKIRIM.
        // Barang yang sudah dikirim tapi belum tiba TIDAK lagi dihitung sebagai stok
        // toko pengirim (lihat StockRecognition).
        foreach (MutationItem::whereHas('mutation', fn($q) =>
                    $q->where('source_store_id', $storeId)->where('status', 'confirmed')
                      ->whereIn('type', StockRecognition::KELUAR)
                      ->whereRaw(StockRecognition::sqlKeluar() . ' <= ?', [$asOfDate])
                )
                ->selectRaw('ingredient_id, packaging_id, SUM(total_in_base) t')
                ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
            $k = $K($r->ingredient_id, $r->packaging_id); $dem[$k] = ($dem[$k] ?? 0) + (float) $r->t;
        }

        $pkgConvAll = IngredientPackaging::all()->keyBy('id');

        // Pemakaian harian — HANYA yang tanggalnya sudah dikonfirmasi
        foreach (DailyUsage::where('store_id', $storeId)->where('qty_pack', '>', 0)
                ->where('usage_date', '<=', $asOfDate)
                ->whereExists(fn($q) => $q->from('daily_confirmations')
                    ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                    ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date'))
                ->selectRaw('ingredient_id, packaging_id, SUM(qty_pack) p')
                ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
            $ptbU = ($r->packaging_id && $pkgConvAll->has($r->packaging_id))
                ? (float) $pkgConvAll[$r->packaging_id]->pack_to_base : 1;
            $k = $K($r->ingredient_id, $r->packaging_id); $dem[$k] = ($dem[$k] ?? 0) + (float) $r->p * $ptbU;
        }

        // Waste (bahan baku)
        foreach (WasteLogItem::query()
                ->join('waste_logs', 'waste_logs.id', '=', 'waste_log_items.waste_log_id')
                ->where('waste_logs.store_id', $storeId)->where('waste_log_items.source_type', 'raw')
                ->where('waste_logs.waste_date', '<=', $asOfDate)
                ->selectRaw('waste_log_items.ingredient_id ing, waste_log_items.packaging_id pkg,
                             SUM(waste_log_items.qty_crate) c, SUM(waste_log_items.qty_pack) p, SUM(waste_log_items.qty_base) b')
                ->groupBy('waste_log_items.ingredient_id', 'waste_log_items.packaging_id')->get() as $r) {
            $base = ($r->pkg && $pkgConvAll->has($r->pkg))
                ? ((float) $r->c * (float) $pkgConvAll[$r->pkg]->crate_to_pack * (float) $pkgConvAll[$r->pkg]->pack_to_base
                   + (float) $r->p * (float) $pkgConvAll[$r->pkg]->pack_to_base)
                : (float) $r->b;
            $k = $K($r->ing, $r->pkg); $dem[$k] = ($dem[$k] ?? 0) + $base;
        }

        // Opname approved: selisih NEGATIF ikut mengurangi stok
        foreach (OpnameItem::query()
                ->join('opnames', 'opnames.id', '=', 'opname_items.opname_id')
                ->where('opnames.store_id', $storeId)->where('opnames.status', 'approved')
                ->where('opnames.opname_date', '<=', $asOfDate)
                ->where('opname_items.variance', '<', 0)
                ->selectRaw('opname_items.ingredient_id ing, opname_items.packaging_id pkg, SUM(opname_items.variance) v')
                ->groupBy('opname_items.ingredient_id', 'opname_items.packaging_id')->get() as $r) {
            $k = $K($r->ing, $r->pkg); $dem[$k] = ($dem[$k] ?? 0) + abs((float) $r->v);
        }

        return [$recv, $dem];
    }

    /** Saldo bertanda (boleh minus) per (bahan × kemasan) sampai tanggal tertentu. */
    public static function saldoPerKemasan(int $storeId, string $asOfDate): array
    {
        [$recv, $dem] = self::receivedDemandMaps($storeId, $asOfDate);
        $saldo = [];
        foreach (array_unique(array_merge(array_keys($recv), array_keys($dem))) as $k) {
            $saldo[$k] = (float) ($recv[$k] ?? 0) - (float) ($dem[$k] ?? 0);
        }
        return $saldo;
    }
}
