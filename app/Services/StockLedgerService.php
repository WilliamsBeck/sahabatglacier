<?php
namespace App\Services;

use App\Models\StockLedger;
use App\Models\StoreStock;

class StockLedgerService
{
    /**
     * Catat pergerakan stok ke ledger dan update saldo di store_stocks.
     */
    public static function record(
        int    $storeId,
        int    $ingredientId,
        string $movementDate,
        string $movementType,
        float  $qtyChange,       // positif = masuk, negatif = keluar
        string $referenceType,   // nama model: 'Mutation', 'ProductionLog', dll
        int    $referenceId,
        string $notes = ''
    ): StockLedger {
        // Ambil saldo terakhir. Tiebreaker by id: beberapa entri sering dibuat dalam
        // loop yang sama (created_at identik sampai detik) — tanpa urutan id, saldo
        // terakhir bisa terambil dari entri yang salah → balance_after melenceng.
        $lastBalance = StockLedger::where('store_id', $storeId)
            ->where('ingredient_id', $ingredientId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('balance_after') ?? 0;

        $newBalance = $lastBalance + $qtyChange;

        // Simpan ke ledger
        $ledger = StockLedger::create([
            'store_id'       => $storeId,
            'ingredient_id'  => $ingredientId,
            'movement_date'  => $movementDate,
            'movement_type'  => $movementType,
            'qty_change'     => $qtyChange,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'balance_after'  => $newBalance,
            'notes'          => $notes,
            'created_by'     => auth()->id(),
        ]);

        // Update saldo real-time di store_stocks
        StoreStock::updateOrCreate(
            ['store_id' => $storeId, 'ingredient_id' => $ingredientId],
            ['stock_balance' => $newBalance, 'updated_at' => now()]
        );

        return $ledger;
    }

    /**
     * Ambil saldo stok di tanggal tertentu (dari ledger historis).
     */
    public static function getBalanceAt(int $storeId, int $ingredientId, string $date): float
    {
        return StockLedger::where('store_id', $storeId)
            ->where('ingredient_id', $ingredientId)
            ->where('movement_date', '<=', $date)
            ->latest('created_at')
            ->value('balance_after') ?? 0;
    }
}
