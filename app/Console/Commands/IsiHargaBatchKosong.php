<?php

namespace App\Console\Commands;

use App\Models\{Opname, OpnameItem, MutationItem, Store};
use App\Services\FifoService;
use Illuminate\Console\Command;

/**
 * Perbaikan data: batch FIFO yang harganya 0/NULL diisi dari harga opname
 * APPROVED terbaru yang memuat bahan+kemasan itu di toko tersebut.
 *
 * Latar: harga yang diketik di opname baru dialirkan ke batch mulai versi ini
 * (saat approve). Opname yang sudah approved sebelumnya tidak sempat mengalirkannya,
 * dan sering tidak bisa dibatalkan lagi karena sudah ada mutasi setelahnya.
 * Command ini mengejar yang tertinggal itu.
 *
 * Default HANYA MENAMPILKAN apa yang akan diubah. Tambahkan --apply untuk menulis.
 */
class IsiHargaBatchKosong extends Command
{
    protected $signature   = 'stok:isi-harga-batch-kosong {--apply : Tulis perubahan (default hanya pratinjau)}';
    protected $description = 'Isi harga batch FIFO yang kosong (0/NULL) dari harga opname approved terbaru';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'MODE TULIS — perubahan akan disimpan.' : 'MODE PRATINJAU — tidak ada yang diubah. Tambahkan --apply untuk menulis.');
        $this->newLine();

        // Semua batch sisa yang harganya kosong, per (toko × bahan × kemasan)
        $kosong = MutationItem::query()
            ->join('mutations as m', 'm.id', '=', 'mutation_items.mutation_id')
            ->join('ingredients as i', 'i.id', '=', 'mutation_items.ingredient_id')
            ->join('stores as s', 's.id', '=', 'm.destination_store_id')
            ->where('m.status', 'confirmed')
            ->where('mutation_items.remaining_qty', '>', 0)
            ->where(fn($q) => $q->whereNull('mutation_items.price_per_base')
                                ->orWhere('mutation_items.price_per_base', '<=', 0))
            ->selectRaw('m.destination_store_id store_id, s.name toko, mutation_items.ingredient_id, i.name bahan,
                         mutation_items.packaging_id, COUNT(*) n, SUM(mutation_items.remaining_qty) sisa')
            ->groupBy('m.destination_store_id', 's.name', 'mutation_items.ingredient_id', 'i.name', 'mutation_items.packaging_id')
            ->orderBy('s.name')->orderBy('i.name')
            ->get();

        if ($kosong->isEmpty()) {
            $this->info('Tidak ada batch berharga 0/NULL. Tidak ada yang perlu diperbaiki.');
            return self::SUCCESS;
        }

        $this->line("Ditemukan {$kosong->count()} kombinasi toko × bahan × kemasan dengan batch tanpa harga.");
        $this->newLine();

        $rows = []; $bisa = 0; $tidak = 0; $totalBatch = 0;
        foreach ($kosong as $k) {
            // Harga dari opname APPROVED TERBARU yang memuat bahan+kemasan ini di toko ini
            $sumber = OpnameItem::query()
                ->join('opnames', 'opnames.id', '=', 'opname_items.opname_id')
                ->where('opnames.store_id', $k->store_id)
                ->where('opnames.status', 'approved')
                ->where('opname_items.ingredient_id', $k->ingredient_id)
                ->when($k->packaging_id,
                    fn($q) => $q->where('opname_items.packaging_id', $k->packaging_id),
                    fn($q) => $q->whereNull('opname_items.packaging_id'))
                ->where('opname_items.price_per_base', '>', 0)
                ->orderByDesc('opnames.opname_date')->orderByDesc('opnames.id')
                ->first(['opname_items.price_per_base', 'opnames.opname_date', 'opnames.id as opname_id']);

            $pkgLabel = $k->packaging_id ? '#' . $k->packaging_id : '-';
            if (!$sumber) {
                $tidak++;
                $rows[] = [$k->toko, $k->bahan, $pkgLabel, $k->n, '-', 'TIDAK ADA opname berharga → dilewati'];
                continue;
            }

            $bisa++; $totalBatch += $k->n;
            $ket = "dari opname " . substr($sumber->opname_date, 0, 10);
            if ($apply) {
                $n = FifoService::isiHargaBatchKosong(
                    (int) $k->store_id, (int) $k->ingredient_id,
                    $k->packaging_id ? (int) $k->packaging_id : null,
                    (float) $sumber->price_per_base
                );
                $ket .= " → {$n} batch DIISI";
            }
            $rows[] = [$k->toko, $k->bahan, $pkgLabel, $k->n,
                       number_format((float) $sumber->price_per_base, 4, ',', '.') . ' /base', $ket];
        }

        $this->table(['Toko', 'Bahan', 'Kemasan', 'Batch', 'Harga sumber', 'Keterangan'], $rows);
        $this->newLine();
        $this->line("Bisa diperbaiki : {$bisa} kombinasi ({$totalBatch} batch)");
        $this->line("Tidak ada sumber: {$tidak} kombinasi — isi harganya lewat opname dulu, lalu jalankan lagi");

        if (!$apply && $bisa > 0) {
            $this->newLine();
            $this->warn('Belum ada yang diubah. Jalankan lagi dengan --apply untuk menulis.');
        }
        return self::SUCCESS;
    }
}
