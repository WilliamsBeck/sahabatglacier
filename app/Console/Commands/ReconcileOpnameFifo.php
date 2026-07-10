<?php

namespace App\Console\Commands;

use App\Models\Mutation;
use App\Models\MutationItem;
use App\Models\Opname;
use App\Models\Store;
use App\Services\FifoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rekonsiliasi FIFO terhadap stok fisik opname yang sudah approved.
 *
 * Memperbaiki data lama dari bug: approve opname (mode bulanan) dulu menghitung
 * delta FIFO hanya dari Dus+Pack, sehingga surplus fisik berupa eceran (pcs/gr)
 * tidak masuk FIFO → Saldo Stok kurang. Command ini menambahkan kekurangan itu.
 *
 * IDEMPOTEN: delta = physical_qty − FIFO sekarang. Kalau sudah benar → 0, dilewati.
 * Hanya menyentuh opname end_month approved TERBARU per toko (yang menentukan saldo).
 */
class ReconcileOpnameFifo extends Command
{
    protected $signature = 'opname:reconcile-fifo
                            {--store= : Nama/ID toko (kosong = semua toko)}
                            {--month= : Bulan periode (kosong = opname end_month approved terbaru per toko)}
                            {--year= : Tahun periode}
                            {--undo : Hapus SEMUA batch rekonsiliasi yang pernah dibuat lalu recalculate}
                            {--dry-run : Hanya menampilkan apa yang akan diubah, tanpa menyimpan}';

    protected $description = 'Rekonsiliasi FIFO ke stok fisik opname approved (perbaiki saldo stok yang kurang akibat eceran).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($this->option('undo')) {
            return $this->undoAll($dry);
        }

        $stores = $this->resolveStores();
        if ($stores->isEmpty()) {
            $this->error('Toko tidak ditemukan.');
            return self::FAILURE;
        }

        $totalFixed = 0;

        foreach ($stores as $store) {
            $opname = $this->resolveOpname($store->id);
            if (!$opname) {
                $this->line("• {$store->name}: tidak ada opname end_month approved — dilewati.");
                continue;
            }

            $this->info("• {$store->name} — opname #{$opname->id} ({$opname->period_month}/{$opname->period_year})");
            $fixed = $this->reconcile($opname, $dry);
            $totalFixed += $fixed;

            if ($fixed === 0) {
                $this->line('  (sudah sinkron, tidak ada perubahan)');
            }
        }

        $verb = $dry ? 'akan diperbaiki' : 'diperbaiki';
        $this->newLine();
        $this->info("Selesai. {$totalFixed} baris {$verb}.");

        return self::SUCCESS;
    }

    /** Hapus semua batch rekonsiliasi (notes 'Reconcile FIFO opname%') & recalculate. */
    private function undoAll(bool $dry): int
    {
        $muts = Mutation::where('type', 'opening_stock')
            ->where('notes', 'like', 'Reconcile FIFO opname%')
            ->with('items:id,mutation_id,ingredient_id')
            ->get();

        if ($muts->isEmpty()) {
            $this->info('Tidak ada batch rekonsiliasi untuk dihapus.');
            return self::SUCCESS;
        }

        // Kumpulkan (store, ingredient) terdampak untuk recalculate.
        $affected = [];
        foreach ($muts as $m) {
            foreach ($m->items as $it) {
                $affected[$m->destination_store_id . '-' . $it->ingredient_id] =
                    [$m->destination_store_id, $it->ingredient_id];
            }
        }

        // Batch opname (opening_stock auto-generated) yang menyimpan eceran di FIFO
        // (qty_base > 0) dari kode lama — eceran dibuang agar FIFO = pack utuh saja.
        $eceranBatches = \App\Models\MutationItem::whereHas('mutation', fn ($q) => $q
                ->where('type', 'opening_stock')->where('notes', 'like', 'Auto-generated dari Opname%'))
            ->where('qty_base', '>', 0)
            ->with('mutation:id,destination_store_id')
            ->get();

        foreach ($eceranBatches as $b) {
            $affected[$b->mutation->destination_store_id . '-' . $b->ingredient_id] =
                [$b->mutation->destination_store_id, $b->ingredient_id];
        }

        $this->info(($dry ? '[dry-run] ' : '') . 'Hapus ' . $muts->count()
            . ' mutasi rekonsiliasi + buang eceran dari ' . $eceranBatches->count()
            . ' batch opname. ' . count($affected) . ' (toko×bahan) di-recalculate.');

        if ($dry) return self::SUCCESS;

        DB::transaction(function () use ($muts, $eceranBatches, $affected) {
            foreach ($muts as $m) { $m->items()->delete(); $m->delete(); }

            // Kurangi eceran dari batch opname: total_in_base & remaining turun sebesar qty_base.
            foreach ($eceranBatches as $b) {
                $ecer = (float) $b->qty_base;
                $b->total_in_base = max(0, (float) $b->total_in_base - $ecer);
                $b->remaining_qty = max(0, (float) $b->remaining_qty - $ecer);
                $b->qty_base = 0;
                $b->save();
            }

            foreach ($affected as [$sid, $ingId]) {
                FifoService::recalculate((int) $sid, (int) $ingId);
            }
        });

        $this->info('Selesai. Batch rekonsiliasi dihapus, eceran dibuang dari FIFO & recalculate.');
        return self::SUCCESS;
    }

    private function resolveStores()
    {
        $store = $this->option('store');
        if ($store === null || $store === '') {
            return Store::orderBy('id')->get();
        }
        if (is_numeric($store)) {
            return Store::where('id', (int) $store)->get();
        }
        return Store::where('name', 'like', '%' . $store . '%')->get();
    }

    private function resolveOpname(int $storeId): ?Opname
    {
        $q = Opname::where('store_id', $storeId)
            ->where('status', 'approved');

        if ($this->option('month') && $this->option('year')) {
            $q->where('period_month', (int) $this->option('month'))
              ->where('period_year', (int) $this->option('year'));
        }

        return $q->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('id')->first();
    }

    private function reconcile(Opname $opname, bool $dry): int
    {
        $fixed = 0;

        $apply = function () use ($opname, &$fixed) {
            $opening = null;

            // Kelompokkan per (bahan × kemasan) — bahan multi-batch punya >1 item,
            // stoknya dijumlahkan dulu agar delta dihitung sekali (tidak dobel).
            $groups = $opname->items->groupBy(fn ($i) => $i->ingredient_id . '-' . ($i->packaging_id ?: 0));

            foreach ($groups as $group) {
                $first = $group->first();
                $ingId = $first->ingredient_id;
                $pkgId = $first->packaging_id;
                $target = (float) $group->sum('physical_qty');   // fisik TOTAL semua batch
                if ($target <= 0) continue;

                $curr = MutationItem::whereHas('mutation', fn ($q) => $q
                        ->where('destination_store_id', $opname->store_id)
                        ->where('status', 'confirmed'))
                    ->where('ingredient_id', $ingId)
                    ->when($pkgId,
                        fn ($q) => $q->where('packaging_id', $pkgId),
                        fn ($q) => $q->whereNull('packaging_id'))
                    ->sum('remaining_qty');

                $delta = round($target - (float) $curr, 4);
                if ($delta <= 0) continue;

                $name = $first->ingredient->name ?? ('#' . $ingId);
                $this->line("  + {$name} (delta {$delta})");
                $fixed++;

                $opening = $opening ?: Mutation::create([
                    'type'                 => 'opening_stock',
                    'destination_store_id' => $opname->store_id,
                    'transaction_date'     => $opname->opname_date,
                    'delivery_date'        => $opname->opname_date,
                    'status'               => 'confirmed',
                    'notes'                => 'Reconcile FIFO opname #' . $opname->id,
                    'created_by'           => $opname->performed_by ?? $opname->approved_by ?? 1,
                    'confirmed_by'         => $opname->approved_by ?? 1,
                ]);

                MutationItem::create([
                    'mutation_id'            => $opening->id,
                    'ingredient_id'          => $ingId,
                    'packaging_id'           => $pkgId,
                    'qty_crate'              => 0,
                    'qty_pack'               => 0,
                    'qty_base'               => 0,
                    'total_in_base'          => $delta,
                    'remaining_qty'          => $delta,
                    'price_per_base'         => (float) $first->price_per_base,
                    'selling_price_per_base' => 0,
                    'cost_subtotal'          => $delta * (float) $first->price_per_base,
                ]);
            }

            foreach ($opname->items->pluck('ingredient_id')->unique() as $iid) {
                FifoService::recalculate($opname->store_id, (int) $iid);
            }
        };

        if ($dry) {
            // Hitung tanpa menyimpan: jalankan dalam transaksi lalu rollback.
            DB::beginTransaction();
            try {
                $apply();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($apply);
        }

        return $fixed;
    }
}
