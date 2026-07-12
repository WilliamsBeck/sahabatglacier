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

            // Transfer internal: pecah tiap baris menjadi beberapa baris sesuai LAPISAN
            // harga FIFO sumber, supaya toko tujuan menerima batch per-harga (bukan rata-rata).
            self::splitSaleItemsByFifoLayers($mutation);

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
     * Pecah baris transfer internal menjadi beberapa baris per LAPISAN harga FIFO sumber.
     * Baris @247rb + @310rb tidak digabung rata-rata, tapi jadi 2 batch terpisah di tujuan —
     * supaya transfer/pemakaian berikutnya mengambil harga FIFO yang benar. Hanya dipecah
     * bila sumber punya >1 harga untuk qty yang dikirim.
     */
    private static function splitSaleItemsByFifoLayers(Mutation $mutation): void
    {
        if (!$mutation->isSale() || !$mutation->destination_store_id
            || !$mutation->source_store_id || !$mutation->deductsFromSource()) {
            return;
        }

        $changed = false;
        foreach ($mutation->items()->get() as $item) {
            $pkgId  = $item->packaging_id ?: null;
            $layers = FifoService::getWholePackLayers(
                (int) $mutation->source_store_id, (int) $item->ingredient_id,
                (float) $item->total_in_base, $pkgId
            );
            if (empty($layers)) continue;

            // 1 lapisan: tidak perlu dipecah, tapi PASTIKAN harga = harga FIFO sumber
            // (harga input transfer internal bisa meleset/stale — sumber yang otoritatif).
            if (count($layers) === 1) {
                $price = (float) $layers[0]['price_per_base'];
                if (abs($price - (float) $item->price_per_base) > 0.0001) {
                    $item->update([
                        'price_per_base' => $price,
                        'cost_subtotal'  => (float) $item->total_in_base * $price,
                    ]);
                    $item->refresh();
                }
                continue;
            }

            $pkg = $pkgId ? \App\Models\IngredientPackaging::find($pkgId) : null;
            $ptb = $pkg ? (float) $pkg->pack_to_base : 0;
            $ctb = $pkg ? (float) $pkg->crate_to_pack * $ptb : 0;

            foreach ($layers as $L) {
                $base  = (float) $L['base'];
                $price = (float) $L['price_per_base'];
                $dus   = $ctb > 0 ? (int) floor($base / $ctb) : 0;
                $pack  = $ptb > 0 ? (int) floor(($base - $dus * $ctb) / $ptb) : 0;

                $mutation->items()->create([
                    'ingredient_id'          => $item->ingredient_id,
                    'packaging_id'           => $pkgId,
                    'qty_crate'              => $dus,
                    'qty_pack'               => $pack,
                    'qty_base'               => 0,
                    'total_in_base'          => $base,
                    'price_per_base'         => $price,
                    'selling_price_per_base' => $item->selling_price_per_base,
                    'cost_subtotal'          => $base * $price,
                    'remaining_qty'          => $base,
                ]);
            }
            $item->delete();
            $changed = true;
        }

        if ($changed) $mutation->load('items');
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
