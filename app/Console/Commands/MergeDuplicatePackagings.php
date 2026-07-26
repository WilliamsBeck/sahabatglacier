<?php

namespace App\Console\Commands;

use App\Models\IngredientPackaging;
use App\Services\FifoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gabungkan KEMASAN GANDA: kemasan dengan bahan + spesifikasi (crate_to_pack &
 * pack_to_base) yang SAMA, tapi terdaftar terpisah karena beda supplier.
 *
 * Barang yang fisiknya identik harus jadi SATU kemasan supaya stok tidak terpecah
 * jadi beberapa kolam FIFO. Riwayat supplier tetap utuh karena tersimpan di tiap
 * mutasi (mutations.supplier_id), bukan di kemasan.
 *
 * Jalankan: php artisan packagings:merge-duplicates [--dry-run]
 */
class MergeDuplicatePackagings extends Command
{
    protected $signature = 'packagings:merge-duplicates {--dry-run : Tampilkan rencana tanpa menyimpan}';
    protected $description = 'Gabungkan kemasan ganda (bahan & spesifikasi sama, beda supplier) jadi satu';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Kelompokkan per (bahan × crate_to_pack × pack_to_base)
        $groups = IngredientPackaging::orderBy('id')->get()
            ->groupBy(fn($p) => $p->ingredient_id . '|' . (float) $p->crate_to_pack . '|' . (float) $p->pack_to_base)
            ->filter(fn($g) => $g->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('Tidak ada kemasan ganda. Semua sudah bersih.');
            return self::SUCCESS;
        }

        $this->line('Ditemukan ' . $groups->count() . ' kelompok kemasan ganda:');
        $affected = [];   // [ "storeId-ingId" => true ] untuk recalculate FIFO
        $totalDel = 0;

        foreach ($groups as $group) {
            $keep = $group->first();                 // id terkecil = kemasan utama
            $dups = $group->slice(1);
            $ing  = $keep->ingredient;

            $this->newLine();
            $this->line("  <fg=cyan>{$ing->name}</> — 1 Dus = {$keep->crate_to_pack} pack × {$keep->pack_to_base} {$ing->unit_base}");
            $this->line("    DIPERTAHANKAN: pkg#{$keep->id} \"{$keep->packaging_name}\" (" . ($keep->supplier->name ?? 'tanpa supplier') . ')');

            foreach ($dups as $dup) {
                $counts = [
                    'mutation_items' => DB::table('mutation_items')->where('packaging_id', $dup->id)->count(),
                    'daily_usages'   => DB::table('daily_usages')->where('packaging_id', $dup->id)->count(),
                    'opname_items'   => DB::table('opname_items')->where('packaging_id', $dup->id)->count(),
                    'waste_items'    => DB::table('waste_log_items')->where('packaging_id', $dup->id)->count(),
                    'urutan_baris'   => DB::table('store_ingredient_orders')->where('packaging_id', $dup->id)->count(),
                ];
                $ringkas = collect($counts)->filter()->map(fn($v, $k) => "$k:$v")->implode(', ');
                $this->line("    DIGABUNG  : pkg#{$dup->id} \"{$dup->packaging_name}\" ("
                    . ($dup->supplier->name ?? 'tanpa supplier') . ') → '
                    . ($ringkas ?: 'tanpa transaksi'));

                if ($dry) continue;

                DB::transaction(function () use ($dup, $keep, &$affected) {
                    // ── daily_usages: unique(store,ing,pkg,date) → JUMLAHKAN qty bila bentrok
                    foreach (DB::table('daily_usages')->where('packaging_id', $dup->id)->get() as $u) {
                        $existing = DB::table('daily_usages')
                            ->where('store_id', $u->store_id)
                            ->where('ingredient_id', $u->ingredient_id)
                            ->where('packaging_id', $keep->id)
                            ->where('usage_date', $u->usage_date)
                            ->first();
                        if ($existing) {
                            DB::table('daily_usages')->where('id', $existing->id)
                                ->update(['qty_pack' => (float) $existing->qty_pack + (float) $u->qty_pack]);
                            DB::table('daily_usages')->where('id', $u->id)->delete();
                        } else {
                            DB::table('daily_usages')->where('id', $u->id)->update(['packaging_id' => $keep->id]);
                        }
                        $affected[$u->store_id . '-' . $u->ingredient_id] = true;
                    }

                    // ── opname_items: gabungkan bila 1 opname punya 2 baris utk kemasan sama
                    foreach (DB::table('opname_items')->where('packaging_id', $dup->id)->get() as $oi) {
                        $existing = DB::table('opname_items')
                            ->where('opname_id', $oi->opname_id)
                            ->where('ingredient_id', $oi->ingredient_id)
                            ->where('packaging_id', $keep->id)
                            ->first();
                        if ($existing) {
                            $sysQty  = (float) $existing->system_qty   + (float) $oi->system_qty;
                            $phyQty  = (float) $existing->physical_qty + (float) $oi->physical_qty;
                            // harga = rata-rata tertimbang berdasarkan qty fisik
                            $pA = (float) ($existing->price_per_base ?? 0); $qA = (float) $existing->physical_qty;
                            $pB = (float) ($oi->price_per_base ?? 0);       $qB = (float) $oi->physical_qty;
                            $price = ($qA + $qB) > 0 ? (($pA * $qA) + ($pB * $qB)) / ($qA + $qB) : ($pA ?: $pB);
                            DB::table('opname_items')->where('id', $existing->id)->update([
                                'system_qty'     => $sysQty,
                                'physical_qty'   => $phyQty,
                                'variance'       => $phyQty - $sysQty,
                                'price_per_base' => $price,
                            ]);
                            DB::table('opname_items')->where('id', $oi->id)->delete();
                        } else {
                            DB::table('opname_items')->where('id', $oi->id)->update(['packaging_id' => $keep->id]);
                        }
                    }

                    // ── store_ingredient_orders: PK(store,ing,pkg) → pertahankan urutan terkecil
                    foreach (DB::table('store_ingredient_orders')->where('packaging_id', $dup->id)->get() as $so) {
                        $existing = DB::table('store_ingredient_orders')
                            ->where('store_id', $so->store_id)
                            ->where('ingredient_id', $so->ingredient_id)
                            ->where('packaging_id', $keep->id)
                            ->first();
                        if ($existing) {
                            DB::table('store_ingredient_orders')
                                ->where('store_id', $so->store_id)
                                ->where('ingredient_id', $so->ingredient_id)
                                ->where('packaging_id', $keep->id)
                                ->update(['sort_order' => min((int) $existing->sort_order, (int) $so->sort_order)]);
                            DB::table('store_ingredient_orders')
                                ->where('store_id', $so->store_id)
                                ->where('ingredient_id', $so->ingredient_id)
                                ->where('packaging_id', $dup->id)->delete();
                        } else {
                            DB::table('store_ingredient_orders')
                                ->where('store_id', $so->store_id)
                                ->where('ingredient_id', $so->ingredient_id)
                                ->where('packaging_id', $dup->id)
                                ->update(['packaging_id' => $keep->id]);
                        }
                    }

                    // ── mutation_items & waste_log_items: tanpa unique → langsung dialihkan.
                    //    Catat toko yang terdampak supaya FIFO-nya dihitung ulang.
                    foreach (DB::table('mutation_items')
                            ->join('mutations', 'mutations.id', '=', 'mutation_items.mutation_id')
                            ->where('mutation_items.packaging_id', $dup->id)
                            ->get(['mutations.destination_store_id as d', 'mutations.source_store_id as s',
                                   'mutation_items.ingredient_id as ing']) as $r) {
                        if ($r->d) $affected[$r->d . '-' . $r->ing] = true;
                        if ($r->s) $affected[$r->s . '-' . $r->ing] = true;
                    }
                    DB::table('mutation_items')->where('packaging_id', $dup->id)->update(['packaging_id' => $keep->id]);
                    DB::table('waste_log_items')->where('packaging_id', $dup->id)->update(['packaging_id' => $keep->id]);

                    // Kemasan ganda dinonaktifkan & dihapus (sudah tidak direferensikan)
                    IngredientPackaging::where('id', $dup->id)->delete();
                });

                $totalDel++;
            }
        }

        if ($dry) {
            $this->newLine();
            $this->info('[DRY-RUN] Tidak ada perubahan disimpan. Jalankan tanpa --dry-run untuk menerapkan.');
            return self::SUCCESS;
        }

        // Hitung ulang FIFO supaya kolam stok yang tergabung konsisten
        $this->newLine();
        $this->line('Menghitung ulang FIFO untuk ' . count($affected) . ' kombinasi toko × bahan...');
        foreach (array_keys($affected) as $key) {
            [$storeId, $ingId] = explode('-', $key);
            FifoService::recalculate((int) $storeId, (int) $ingId);
        }

        $this->info("Selesai: {$totalDel} kemasan ganda digabungkan.");
        return self::SUCCESS;
    }
}
