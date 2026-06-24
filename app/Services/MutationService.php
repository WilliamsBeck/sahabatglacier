<?php
namespace App\Services;

use App\Models\Mutation;
use Illuminate\Support\Facades\DB;
use App\Services\FifoService;

class MutationService
{
    /**
     * Konfirmasi mutasi: ubah status dan catat ke stock_ledger.
     */
    public static function confirm(Mutation $mutation): void
    {
        DB::transaction(function () use ($mutation) {
            $mutation->update([
                'status'       => 'confirmed',
                'confirmed_by' => auth()->id(),
            ]);

            foreach ($mutation->items as $item) {
                // Stok bergerak per tgl terima (delivery_date); fallback ke tgl kirim jika belum diisi
                $date         = ($mutation->delivery_date ?? $mutation->transaction_date)->format('Y-m-d');
                $ingredientId = $item->ingredient_id;
                $qty          = (float) $item->total_in_base;

                if ($mutation->isPurchase()) {
                    // Stok masuk ke toko tujuan
                    if (!$mutation->destination_store_id) continue;
                    $movementType = $mutation->type === 'opening_stock' ? 'opening_stock' : 'purchase_in';
                    StockLedgerService::record(
                        $mutation->destination_store_id, $ingredientId,
                        $date, $movementType, +$qty,
                        'Mutation', $mutation->id,
                        "Ref: {$mutation->reference_no}"
                    );
                    // Re-sync FIFO: deduction yang terjadi saat batch ini masih draft
                    // (mis. sale_internal dikonfirmasi sebelum pembelian ini) tidak sempat
                    // ter-apply karena getFifoItems hanya melihat confirmed batches.
                    // Recalculate memastikan semua deduction ter-apply ulang secara berurutan.
                    FifoService::recalculate($mutation->destination_store_id, $ingredientId);

                } elseif ($mutation->isSale()) {
                    // Barang KELUAR dari toko sumber: transfer internal (sale_internal)
                    // & penjualan eksternal (sale_external_out). sale_external (masuk)
                    // tidak punya source_store_id → tidak memotong sumber.
                    if ($mutation->deductsFromSource() && $mutation->source_store_id) {
                        StockLedgerService::record(
                            $mutation->source_store_id, $ingredientId,
                            $date, 'sale_deduction', -$qty,
                            'Mutation', $mutation->id,
                            "Ref: {$mutation->reference_no}"
                        );
                        FifoService::deductWholePacks($mutation->source_store_id, $ingredientId, $qty, $item->packaging_id ?: null);
                    }
                    // Tambahkan stok ke toko penerima jika ada (sale_internal & sale_external masuk).
                    // sale_external_out tidak punya penerima → tidak menambah stok ke mana pun.
                    if ($mutation->destination_store_id) {
                        StockLedgerService::record(
                            $mutation->destination_store_id, $ingredientId,
                            $date, 'purchase_in', +$qty,
                            'Mutation', $mutation->id,
                            "Ref: {$mutation->reference_no}"
                        );
                    }
                }
            }
        });
    }

    /**
     * Batalkan mutasi (hanya bisa dari status draft).
     */
    public static function cancel(Mutation $mutation): void
    {
        abort_if($mutation->status === 'confirmed', 422, 'Mutasi yang sudah dikonfirmasi tidak bisa dibatalkan.');
        $mutation->update(['status' => 'cancelled']);
    }
}
