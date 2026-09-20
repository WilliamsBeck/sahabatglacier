<?php

namespace App\Console\Commands;

use App\Models\{Ingredient, Store};
use App\Services\FifoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hitung ulang sisa batch FIFO untuk semua (toko × bahan) dari nol.
 *
 * Dipakai untuk membersihkan pergeseran yang ditinggalkan bug race condition:
 * dua recalculate() yang berjalan bersamaan untuk bahan yang sama saling
 * menimpa sehingga stok akhir di Pencatatan Harian / Saldo Stok / Stok Sistem
 * Opname tidak lagi sesuai matematika inputnya. Bug-nya sudah ditutup dengan
 * kunci per bahan (FifoService::withLock); command ini merapikan sisa lamanya.
 *
 * Default hanya PRATINJAU: menampilkan mana yang akan berubah. --apply untuk menulis.
 */
class HitungUlangFifo extends Command
{
    protected $signature   = 'stok:hitung-ulang-fifo
                              {--apply : Tulis perubahan (default hanya pratinjau)}
                              {--store= : Batasi ke satu toko (id)}';
    protected $description = 'Hitung ulang sisa batch FIFO semua toko×bahan; tampilkan/perbaiki yang bergeser';

    public function handle(): int
    {
        $apply   = (bool) $this->option('apply');
        $storeId = $this->option('store') ? (int) $this->option('store') : null;

        $this->info($apply ? 'MODE TULIS — FIFO akan dihitung ulang & disimpan.'
                           : 'MODE PRATINJAU — tidak ada yang diubah. Tambahkan --apply untuk menulis.');
        $this->newLine();

        $tersimpan = fn($sid, $iid) => (float) DB::table('mutation_items as mi')
            ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
            ->where('m.destination_store_id', $sid)->where('m.status', 'confirmed')
            ->where('mi.ingredient_id', $iid)->sum('mi.remaining_qty');

        $ingNama = Ingredient::pluck('name', 'id');
        $stores  = Store::when($storeId, fn($q) => $q->where('id', $storeId))->orderBy('id')->get();

        $total = 0; $geser = [];
        foreach ($stores as $s) {
            $iids = DB::table('mutation_items as mi')
                ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
                ->where('m.destination_store_id', $s->id)->where('m.status', 'confirmed')
                ->distinct()->pluck('mi.ingredient_id');

            foreach ($iids as $iid) {
                $total++;
                $sebelum = $tersimpan($s->id, $iid);

                if ($apply) {
                    FifoService::recalculate($s->id, $iid);
                    $sesudah = $tersimpan($s->id, $iid);
                } else {
                    // Pratinjau: hitung di dalam transaksi lalu batalkan.
                    DB::beginTransaction();
                    try {
                        FifoService::recalculate($s->id, $iid);
                        $sesudah = $tersimpan($s->id, $iid);
                    } finally {
                        DB::rollBack();
                    }
                }

                if (abs($sebelum - $sesudah) > 0.01) {
                    $geser[] = [$s->name, mb_substr($ingNama[$iid] ?? "#$iid", 0, 30),
                                number_format($sebelum, 2, ',', '.'), number_format($sesudah, 2, ',', '.'),
                                ($sesudah - $sebelum > 0 ? '+' : '') . number_format($sesudah - $sebelum, 2, ',', '.')];
                }
            }
        }

        $this->line("Diperiksa: {$total} kombinasi toko × bahan | Bergeser: " . count($geser));
        if ($geser) {
            $this->newLine();
            $this->table(['Toko', 'Bahan', 'Tersimpan (base)', 'Seharusnya (base)', 'Selisih'], $geser);
        }

        $this->newLine();
        if (!$geser) {
            $this->info('Semua FIFO sudah sesuai. Tidak ada yang perlu diperbaiki.');
        } elseif ($apply) {
            $this->info(count($geser) . ' kombinasi sudah dihitung ulang & disimpan.');
        } else {
            $this->warn('Belum ada yang diubah. Jalankan lagi dengan --apply untuk memperbaiki.');
        }
        return self::SUCCESS;
    }
}
