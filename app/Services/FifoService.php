<?php
namespace App\Services;

use App\Models\{DailyConfirmation, DailyUsage, IngredientPackaging, MutationItem, StockLedger, StoreStock};
use Illuminate\Support\Facades\DB;

class FifoService
{
    /**
     * Hitung total biaya berdasarkan FIFO untuk qty tertentu.
     * Dipakai untuk menghitung harga saat produksi dan waste.
     */
    public static function getCost(int $storeId, int $ingredientId, float $qty, ?int $packagingId = null): float
    {
        $items = self::getFifoItems($storeId, $ingredientId, $packagingId);

        // Fallback: packaging filter tidak match → pakai semua batch (sama seperti deduct())
        if ($items->isEmpty() && $packagingId !== null) {
            $items = self::getFifoItems($storeId, $ingredientId, null);
        }

        $totalCost      = 0;
        $remainingNeeded = $qty;

        foreach ($items as $item) {
            if ($remainingNeeded <= 0) break;
            $take       = min((float)$item->remaining_qty, $remainingNeeded);
            $totalCost += $take * $item->price_per_base;
            $remainingNeeded -= $take;
        }

        return $totalCost;
    }

    /**
     * Hitung rata-rata harga per base dari sisa stok FIFO.
     */
    public static function getAvgPrice(int $storeId, int $ingredientId): float
    {
        $items     = self::getFifoItems($storeId, $ingredientId);
        $totalQty  = $items->sum('remaining_qty');
        $totalCost = $items->sum(fn($i) => $i->remaining_qty * $i->price_per_base);

        return $totalQty > 0 ? $totalCost / $totalQty : 0;
    }

    /**
     * Kurangi remaining_qty dari item FIFO secara berurutan (terlama dulu).
     * Pemotongan TERIKAT pada kemasan yang diminta: jika kemasan itu kehabisan
     * stok, sisa pemotongan TIDAK boleh meluber ke kemasan lain (stok kemasan
     * itu dianggap minus). Ini mencegah pemakaian kemasan A mengurangi kemasan B.
     */
    public static function deduct(int $storeId, int $ingredientId, float $qty, ?int $packagingId = null): void
    {
        $items = self::getFifoItems($storeId, $ingredientId, $packagingId);

        foreach ($items as $item) {
            if ($qty <= 0) break;
            $deduct = min((float)$item->remaining_qty, $qty);
            $item->decrement('remaining_qty', $deduct);
            $qty -= $deduct;
        }
    }

    /**
     * pack_to_base untuk sebuah kemasan. 0 = bahan tanpa kemasan (tak ada konsep "pack").
     */
    private static function packToBaseFor(?int $packagingId): float
    {
        if ($packagingId === null) return 0.0;
        return (float) (IngredientPackaging::where('id', $packagingId)->value('pack_to_base') ?? 0);
    }

    /**
     * Eceran (pcs/gr = pack terbuka) dari opname approved TERAKHIR untuk (toko, bahan, kemasan).
     * Dipakai untuk MENGABAIKAN eceran di stok yang ditampilkan/ditransfer (pack segel saja).
     * Catatan: HANYA untuk display/validasi barang keluar — saldo FIFO TIDAK diubah.
     */
    public static function opnameLoose(int $storeId, int $ingredientId, ?int $packagingId): float
    {
        if ($packagingId === null) return 0.0;
        $lastOpnameId = \App\Models\Opname::where('store_id', $storeId)->where('status', 'approved')
            ->orderByDesc('opname_date')->orderByDesc('id')->value('id');
        if (!$lastOpnameId) return 0.0;
        return (float) (\App\Models\OpnameItem::where('opname_id', $lastOpnameId)
            ->where('ingredient_id', $ingredientId)->where('packaging_id', $packagingId)
            ->where('physical_base', '>', 0)->value('physical_base') ?? 0);
    }

    /**
     * Potong stok dalam PACK UTUH saja (barang keluar: transfer & penjualan eksternal).
     * Sisa gram (< 1 pack = "pack terbuka") di sebuah batch DILEWATI — tetap tinggal di
     * toko untuk dipakai produksi harian. Bahan tanpa kemasan → jatuh ke mode per-gram.
     */
    public static function deductWholePacks(int $storeId, int $ingredientId, float $qty, ?int $packagingId = null): void
    {
        $ptb = self::packToBaseFor($packagingId);
        if ($ptb <= 0) { self::deduct($storeId, $ingredientId, $qty, $packagingId); return; }

        $packsNeeded = (int) round($qty / $ptb);
        foreach (self::getFifoItems($storeId, $ingredientId, $packagingId) as $item) {
            if ($packsNeeded <= 0) break;
            $wholePacks = (int) floor((float) $item->remaining_qty / $ptb); // pack UTUH di batch ini
            if ($wholePacks <= 0) continue;                                  // cuma sisa gram → lewati
            $take = min($wholePacks, $packsNeeded);
            $item->decrement('remaining_qty', $take * $ptb);
            $packsNeeded -= $take;
        }
    }

    /**
     * Biaya FIFO untuk qty tertentu, dihitung PACK UTUH per batch (sejajar deductWholePacks).
     * Dipakai untuk menilai harga modal transfer/penjualan eksternal.
     */
    public static function getCostWholePacks(int $storeId, int $ingredientId, float $qty, ?int $packagingId = null): float
    {
        $ptb = self::packToBaseFor($packagingId);
        if ($ptb <= 0) return self::getCost($storeId, $ingredientId, $qty, $packagingId);

        $items = self::getFifoItems($storeId, $ingredientId, $packagingId);
        if ($items->isEmpty()) return self::getCost($storeId, $ingredientId, $qty, $packagingId);

        $packsNeeded = (int) round($qty / $ptb);
        $totalCost   = 0.0;
        foreach ($items as $item) {
            if ($packsNeeded <= 0) break;
            $wholePacks = (int) floor((float) $item->remaining_qty / $ptb);
            if ($wholePacks <= 0) continue;
            $take = min($wholePacks, $packsNeeded);
            $totalCost += $take * $ptb * (float) $item->price_per_base;
            $packsNeeded -= $take;
        }
        return $totalCost;
    }

    /**
     * Rincian LAPISAN FIFO (pack utuh) yang akan terpakai untuk qty tertentu — urut FIFO
     * (terlama dulu), sejajar dengan deductWholePacks. Tiap lapisan: ['base', 'price_per_base'].
     * Dipakai untuk mempertahankan harga per-batch saat transfer antar toko.
     */
    public static function getWholePackLayers(int $storeId, int $ingredientId, float $qty, ?int $packagingId = null): array
    {
        $ptb = self::packToBaseFor($packagingId);
        if ($ptb <= 0) {
            // Tanpa kemasan: 1 lapisan dengan harga rata-rata FIFO.
            $cost = self::getCost($storeId, $ingredientId, $qty, $packagingId);
            return [['base' => $qty, 'price_per_base' => $qty > 0 ? $cost / $qty : 0.0]];
        }

        $packsNeeded = (int) round($qty / $ptb);
        $layers = [];
        foreach (self::getFifoItems($storeId, $ingredientId, $packagingId) as $item) {
            if ($packsNeeded <= 0) break;
            $wholePacks = (int) floor((float) $item->remaining_qty / $ptb);
            if ($wholePacks <= 0) continue;
            $take = min($wholePacks, $packsNeeded);
            $layers[] = ['base' => $take * $ptb, 'price_per_base' => (float) $item->price_per_base];
            $packsNeeded -= $take;
        }
        return $layers;
    }

    /**
     * Stok TERSEDIA untuk barang keluar = PACK UTUH dari FIFO, dibulatkan ke bawah.
     * Eceran (pcs/gr) tidak masuk FIFO, jadi sisa pecahan pack otomatis terbuang oleh
     * floor — konsisten dengan tampilan Saldo Stok. Dipakai untuk validasi transfer/jual.
     */
    public static function availableWholePacksBase(int $storeId, int $ingredientId, ?int $packagingId = null): float
    {
        $items = self::getFifoItems($storeId, $ingredientId, $packagingId);
        $total = (float) $items->sum('remaining_qty');
        $ptb   = self::packToBaseFor($packagingId);
        if ($ptb <= 0) return $total;

        return floor($total / $ptb) * $ptb;
    }

    /**
     * Hitung ulang remaining_qty semua batch di toko ini dari nol.
     * Dipanggil setelah menghapus mutation yang sudah confirmed,
     * supaya remaining_qty tidak under-count akibat deduction yang sudah dihapus.
     */
    /**
     * @param callable|null $onTransfer Kait OPSIONAL untuk audit: dipanggil tepat sebelum tiap
     *        transfer/penjualan dipotong, dengan ($item, $layers) — $layers = lapisan FIFO yang
     *        AKAN dipakai. Dipakai command audit untuk membandingkan harga tercatat vs seharusnya
     *        tanpa menduplikasi logika urutan potong. Default null = perilaku normal.
     */
    public static function recalculate(int $storeId, int $ingredientId, ?callable $onTransfer = null): void
    {
        // 1. Reset semua incoming batch ke remaining_qty = total_in_base
        MutationItem::whereHas('mutation', fn($q) =>
            $q->where('destination_store_id', $storeId)
              ->where('status', 'confirmed')
        )
        ->where('ingredient_id', $ingredientId)
        ->update(['remaining_qty' => DB::raw('total_in_base')]);

        // 2. Ambil semua outgoing dari toko ini (barang keluar), urut dari terlama:
        //    transfer internal (sale_internal) & penjualan eksternal (sale_external_out).
        //    (sale_external = barang MASUK, tidak memotong sumber.)
        $deductions = MutationItem::whereHas('mutation', fn($q) =>
            $q->where('source_store_id', $storeId)
              ->where('status', 'confirmed')
              ->whereIn('type', ['sale_internal', 'sale_external_out'])
        )
        ->where('ingredient_id', $ingredientId)
        ->orderBy('id')
        ->get(['id', 'mutation_id', 'total_in_base', 'packaging_id', 'price_per_base']);

        // 3. Deduksi transfer/penjualan (PACK UTUH) DITERAPKAN PALING AKHIR (lihat step 7b).
        //    Alasannya: transfer mengambil pack utuh dari stok pasca-pemakaian-harian.
        //    Kalau diterapkan sebelum pemakaian harian, pembagian batch jadi berbeda dengan
        //    confirm real-time (mis. sisa gram batch lama keliru ikut terpakai dulu).

        // 4. Re-apply deductions dari daily usage (pencatatan harian)
        //    HANYA tanggal yang sudah DIKONFIRMASI yang dideduksi dari FIFO.
        //    Pemakaian yang masih "draft" tidak mempengaruhi saldo stok.
        // Load SEMUA packaging (termasuk non-aktif) untuk lookup konversi per packaging_id.
        // Termasuk crate_to_pack — dipakai juga oleh step 5 (waste) untuk hitung Dus+Pack.
        $packagingMap = IngredientPackaging::where('ingredient_id', $ingredientId)
            ->get(['id', 'crate_to_pack', 'pack_to_base', 'is_active'])
            ->keyBy('id');
        $defaultPtb = $packagingMap->firstWhere('is_active', true)?->pack_to_base
            ?? $packagingMap->first()?->pack_to_base ?? 1;

        $usages = DailyUsage::where('store_id', $storeId)
            ->where('ingredient_id', $ingredientId)
            ->where('qty_pack', '>', 0)
            ->whereExists(fn($q) => $q
                ->from('daily_confirmations')
                ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date')
            )
            ->orderBy('usage_date')->orderBy('id')
            ->get(['qty_pack', 'packaging_id']);

        foreach ($usages as $usage) {
            $ptb     = $usage->packaging_id && isset($packagingMap[$usage->packaging_id])
                       ? (float)$packagingMap[$usage->packaging_id]->pack_to_base
                       : (float)$defaultPtb;
            $baseQty = (float)$usage->qty_pack * $ptb;
            if ($baseQty > 0.001) self::deduct($storeId, $ingredientId, $baseQty, $usage->packaging_id ?: null);
        }

        // 5. Re-apply deductions dari WASTE — PER KEMASAN.
        //    Dibaca dari waste_log_items (punya packaging_id), BUKAN stock_ledger
        //    (yang tidak menyimpan kemasan), supaya deduksi tepat di batch kemasannya
        //    dan tidak menggeser stok antar-kemasan saat recalculate dipanggil ulang.
        //    Hanya source_type='raw' yang memotong stok (semi_finished tidak distok).
        //    Porsi yang memotong stok = Dus+Pack saja (pcs/gr tidak), sama seperti saat input.
        $wasteItems = \App\Models\WasteLogItem::query()
            ->join('waste_logs', 'waste_logs.id', '=', 'waste_log_items.waste_log_id')
            ->where('waste_logs.store_id', $storeId)
            ->where('waste_log_items.ingredient_id', $ingredientId)
            ->where('waste_log_items.source_type', 'raw')
            ->orderBy('waste_logs.waste_date')
            ->orderBy('waste_log_items.id')
            ->get(['waste_log_items.packaging_id', 'waste_log_items.qty_crate',
                   'waste_log_items.qty_pack', 'waste_log_items.qty_base']);

        foreach ($wasteItems as $wi) {
            $pid = $wi->packaging_id ?: null;
            if ($pid && isset($packagingMap[$pid])) {
                $ptbW      = (float) $packagingMap[$pid]->pack_to_base;
                $ctpW      = (float) ($packagingMap[$pid]->crate_to_pack ?? 0);
                $stockBase = ((int)$wi->qty_crate) * $ctpW * $ptbW + ((int)$wi->qty_pack) * $ptbW;
            } else {
                // Tanpa kemasan → seluruh qty_base memotong stok
                $stockBase = (float) $wi->qty_base;
            }
            if ($stockBase > 0.001) self::deduct($storeId, $ingredientId, $stockBase, $pid);
        }

        // 6. Re-apply deductions dari PRODUCTION (bahan baku dikonsumsi → production_out)
        $prodDeducts = StockLedger::where('store_id', $storeId)
            ->where('ingredient_id', $ingredientId)
            ->where('movement_type', 'production_out')
            ->orderBy('movement_date')->orderBy('id')
            ->get(['qty_change']);

        foreach ($prodDeducts as $p) {
            $qty = abs((float)$p->qty_change);
            if ($qty > 0.001) self::deduct($storeId, $ingredientId, $qty);
        }

        // 7. Opname adjustments — PER KEMASAN, HANYA selisih NEGATIF (kekurangan fisik).
        //    Selisih POSITIF sudah berupa batch opening_stock (di-preserve di step 1),
        //    jadi tidak boleh ditambah lagi di sini (kalau ditambah → dobel).
        //    Dibaca dari opname_items (punya packaging_id), bukan stock_ledger global.
        $opnameNeg = \App\Models\OpnameItem::query()
            ->join('opnames', 'opnames.id', '=', 'opname_items.opname_id')
            ->where('opnames.store_id', $storeId)
            ->where('opnames.status', 'approved')
            ->where('opname_items.ingredient_id', $ingredientId)
            ->where('opname_items.variance', '<', 0)
            ->orderBy('opnames.opname_date')->orderBy('opname_items.id')
            ->get(['opname_items.packaging_id', 'opname_items.variance']);

        foreach ($opnameNeg as $adj) {
            $qty = abs((float) $adj->variance);
            if ($qty > 0.001) self::deduct($storeId, $ingredientId, $qty, $adj->packaging_id ?: null);
        }

        // 7b. Deduksi transfer/penjualan eksternal — PACK UTUH, diterapkan PALING AKHIR
        //     (setelah semua konsumsi) supaya hasilnya sama dengan confirm real-time:
        //     transfer mengambil pack utuh dari stok yang tersisa pasca-pemakaian.
        foreach ($deductions as $ded) {
            if ($onTransfer) {
                // Lapisan yang AKAN dipakai — direkam sebelum dipotong (untuk audit)
                $onTransfer($ded, self::getWholePackLayers(
                    $storeId, $ingredientId, (float) $ded->total_in_base, $ded->packaging_id ?: null
                ));
            }
            self::deductWholePacks($storeId, $ingredientId, (float) $ded->total_in_base, $ded->packaging_id ?: null);
        }

        // 8. Sync store_stocks.stock_balance
        $balance = MutationItem::whereHas('mutation', fn($q) =>
            $q->where('destination_store_id', $storeId)->where('status', 'confirmed')
        )->where('ingredient_id', $ingredientId)->sum('remaining_qty');

        StoreStock::updateOrCreate(
            ['store_id' => $storeId, 'ingredient_id' => $ingredientId],
            ['stock_balance' => $balance]
        );
    }

    /**
     * Ambil mutation_items yang masih punya sisa, urut dari terlama.
     * Jika packagingId diberikan, filter hanya batch kemasan tersebut.
     */
    private static function getFifoItems(int $storeId, int $ingredientId, ?int $packagingId = null)
    {
        // FIFO diurutkan berdasarkan tanggal pengakuan stok (delivery_date, fallback
        // transaction_date) — BUKAN created_at — supaya entri yang diinput mundur
        // tanggal tetap dikonsumsi sesuai urutan barang masuk yang sebenarnya.
        $query = MutationItem::query()
            ->join('mutations', 'mutations.id', '=', 'mutation_items.mutation_id')
            ->where('mutations.destination_store_id', $storeId)
            ->where('mutations.status', 'confirmed')
            ->where('mutation_items.ingredient_id', $ingredientId)
            ->where('mutation_items.remaining_qty', '>', 0);

        if ($packagingId !== null) {
            $query->where('mutation_items.packaging_id', $packagingId);
        }

        return $query
            ->orderByRaw('COALESCE(mutations.delivery_date, mutations.transaction_date) ASC')
            ->orderBy('mutations.id', 'asc')
            ->orderBy('mutation_items.id', 'asc')
            ->select('mutation_items.*')
            ->get();
    }
}
