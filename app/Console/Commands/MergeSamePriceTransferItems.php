<?php

namespace App\Console\Commands;

use App\Models\Mutation;
use App\Models\IngredientPackaging;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rapikan mutasi transfer internal LAMA: baris dengan (bahan × kemasan × harga)
 * yang SAMA digabung jadi satu baris. Aman untuk FIFO/HPP karena harganya identik.
 * Jalankan sekali: php artisan mutations:merge-same-price [--dry-run]
 */
class MergeSamePriceTransferItems extends Command
{
    protected $signature = 'mutations:merge-same-price {--dry-run : Tampilkan perubahan tanpa menyimpan}';
    protected $description = 'Gabungkan baris item transfer internal yang bahan+kemasan+harga-nya sama';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Konversi kemasan (ctb/ptb) untuk hitung ulang Dus/Pack.
        $conv = [];
        foreach (IngredientPackaging::get(['id', 'pack_to_base', 'crate_to_pack']) as $p) {
            $ptb = (float) $p->pack_to_base;
            $conv[$p->id] = ['ptb' => $ptb, 'ctb' => (float) $p->crate_to_pack * $ptb];
        }

        // Hanya transfer internal terkonfirmasi (satu-satunya tipe yang dipecah per-lapisan).
        $mutations = Mutation::with('items')
            ->where('type', 'sale_internal')->where('status', 'confirmed')
            ->get();

        $mutTouched = 0; $rowsRemoved = 0;

        foreach ($mutations as $mut) {
            // Kelompokkan item per bahan × kemasan × harga (dibulatkan per dus/pack).
            $groups = [];
            foreach ($mut->items as $it) {
                $pkgId = $it->packaging_id ?: 0;
                $c     = $conv[$it->packaging_id] ?? ['ptb' => 0, 'ctb' => 0];
                $pb    = (float) $it->price_per_base;
                $pKey  = $c['ctb'] > 0 ? round($pb * $c['ctb'])
                       : ($c['ptb'] > 0 ? round($pb * $c['ptb']) : round($pb, 6));
                $key   = $it->ingredient_id . '|' . $pkgId . '|' . $pKey;
                $groups[$key][] = $it;
            }

            $changedHere = false;
            foreach ($groups as $items) {
                if (count($items) < 2) continue; // tak ada yang perlu digabung

                $keep = $items[0];
                $c    = $conv[$keep->packaging_id] ?? ['ptb' => 0, 'ctb' => 0];
                $ctb  = $c['ctb']; $ptb = $c['ptb'];

                $sumBase   = 0.0; $sumRemain = 0.0; $wsum = 0.0;
                foreach ($items as $it) {
                    $sumBase   += (float) $it->total_in_base;
                    $sumRemain += (float) $it->remaining_qty;
                    $wsum      += (float) $it->total_in_base * (float) $it->price_per_base;
                }
                $price = $sumBase > 0 ? $wsum / $sumBase : (float) $keep->price_per_base;

                $dus = $ctb > 0 ? (int) floor($sumBase / $ctb) : 0;
                $pack = $ptb > 0 ? (int) floor(($sumBase - $dus * $ctb) / $ptb) : 0;

                $this->line(sprintf(
                    '  %s | %s: %d baris → 1 (%d Dus %d Pack @ Rp %s/dus)',
                    $mut->reference_no, optional($keep->ingredient)->name ?? ('bahan#' . $keep->ingredient_id),
                    count($items), $dus, $pack,
                    number_format($ctb > 0 ? round($price * $ctb) : round($price), 0, ',', '.')
                ));

                if (!$dry) {
                    $keep->update([
                        'qty_crate'     => $dus,
                        'qty_pack'      => $pack,
                        'qty_base'      => 0,
                        'total_in_base' => $sumBase,
                        'price_per_base' => $price,
                        'cost_subtotal' => $sumBase * $price,
                        'remaining_qty' => $sumRemain,
                    ]);
                    for ($i = 1; $i < count($items); $i++) {
                        $items[$i]->delete();
                        $rowsRemoved++;
                    }
                }
                $changedHere = true;
            }

            if ($changedHere) $mutTouched++;
        }

        if ($dry) {
            $this->info("[DRY-RUN] {$mutTouched} mutasi punya baris yang bisa digabung. Tidak ada yang disimpan.");
        } else {
            $this->info("Selesai: {$mutTouched} mutasi dirapikan, {$rowsRemoved} baris duplikat digabung.");
        }

        return self::SUCCESS;
    }
}
