<?php

namespace App\Console\Commands;

use App\Models\{Mutation, MutationItem, Store, Ingredient, Opname, HppSnapshot};
use App\Services\FifoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AUDIT (hanya membaca) — mendeteksi transfer/penjualan yang HARGANYA tidak sesuai
 * alokasi FIFO yang benar.
 *
 * Penyebab umum: mutasi dikonfirmasi SEBELUM pencatatan harian tanggal sebelumnya
 * dikonfirmasi. Kuantitas nanti terkoreksi sendiri oleh recalculate(), tapi harga
 * yang terlanjur terkunci di mutasi TIDAK ikut dihitung ulang.
 *
 * Command ini menjalankan simulasi memakai mesin FIFO asli di dalam transaksi yang
 * SELALU di-rollback — jadi tidak ada satu pun data yang berubah.
 */
class AuditFifoTransferPrice extends Command
{
    protected $signature = 'mutations:audit-fifo-price
                            {--store= : Batasi ke satu toko (id)}
                            {--min=1000 : Abaikan selisih nilai di bawah ini (Rp)}';

    protected $description = 'Periksa transfer/penjualan yang harganya menyimpang dari alokasi FIFO (read-only)';

    public function handle(): int
    {
        $storeFilter = $this->option('store');
        $minDiff     = (float) $this->option('min');

        // Pasangan (toko × bahan) yang punya transfer/penjualan keluar terkonfirmasi
        $pairs = MutationItem::query()
            ->join('mutations', 'mutations.id', '=', 'mutation_items.mutation_id')
            ->where('mutations.status', 'confirmed')
            ->whereIn('mutations.type', ['sale_internal', 'sale_external_out'])
            ->whereNotNull('mutations.source_store_id')
            ->when($storeFilter, fn($q) => $q->where('mutations.source_store_id', $storeFilter))
            ->distinct()
            ->get(['mutations.source_store_id as store_id', 'mutation_items.ingredient_id']);

        if ($pairs->isEmpty()) {
            $this->info('Tidak ada transfer/penjualan terkonfirmasi untuk diperiksa.');
            return self::SUCCESS;
        }

        $this->line('Memindai ' . $pairs->count() . ' kombinasi (toko × bahan)…');

        $stores  = Store::pluck('name', 'id');
        $ings    = Ingredient::pluck('name', 'id');
        $muts    = Mutation::whereIn('type', ['sale_internal', 'sale_external_out'])
                    ->where('status', 'confirmed')
                    ->get(['id', 'reference_no', 'transaction_date', 'delivery_date', 'source_store_id'])
                    ->keyBy('id');

        $findings = [];

        // Simulasi di dalam transaksi → SELALU rollback, tidak ada data yang berubah.
        DB::beginTransaction();
        try {
            foreach ($pairs as $pair) {
                $sid = (int) $pair->store_id;
                $iid = (int) $pair->ingredient_id;

                FifoService::recalculate($sid, $iid, function ($item, $layers) use (
                    &$findings, $sid, $iid, $muts, $stores, $ings
                ) {
                    if (empty($layers)) return;

                    $base = array_sum(array_column($layers, 'base'));
                    if ($base <= 0) return;

                    // Harga FIFO yang SEHARUSNYA (rata-rata tertimbang lapisan yang dipakai)
                    $seharusnya = array_sum(array_map(
                        fn($l) => $l['base'] * $l['price_per_base'], $layers
                    )) / $base;

                    $tercatat = (float) $item->price_per_base;
                    $selisih  = ($seharusnya - $tercatat) * (float) $item->total_in_base;

                    if (abs($seharusnya - $tercatat) < 0.0001) return;

                    $mut = $muts[$item->mutation_id] ?? null;
                    $findings[] = [
                        'ref'        => $mut->reference_no ?? ('mut#' . $item->mutation_id),
                        'tanggal'    => optional($mut->delivery_date ?? $mut->transaction_date)->toDateString() ?? '-',
                        'store_id'   => $sid,
                        'toko'       => $stores[$sid] ?? ('toko#' . $sid),
                        'bahan'      => $ings[$iid] ?? ('bahan#' . $iid),
                        'tercatat'   => $tercatat,
                        'seharusnya' => $seharusnya,
                        'selisih'    => $selisih,
                    ];
                });
            }
        } finally {
            DB::rollBack();   // WAJIB: simulasi tidak boleh menyentuh data
        }

        // Saring selisih yang tidak material
        $findings = array_values(array_filter($findings, fn($f) => abs($f['selisih']) >= $minDiff));

        if (empty($findings)) {
            $this->info('✓ Semua transfer/penjualan harganya sudah sesuai alokasi FIFO.');
            return self::SUCCESS;
        }

        usort($findings, fn($a, $b) => abs($b['selisih']) <=> abs($a['selisih']));

        $this->newLine();
        $this->warn(count($findings) . ' transfer/penjualan harganya menyimpang dari alokasi FIFO:');
        $this->newLine();

        $rows = [];
        $totalSelisih = 0.0;
        foreach ($findings as $f) {
            // Periode terkunci opname / snapshot HPP? → sebaiknya JANGAN dikoreksi surut
            $c      = \Carbon\Carbon::parse($f['tanggal']);
            $locked = Opname::isDateLocked($f['store_id'], $f['tanggal'])
                   || HppSnapshot::isDateLocked($f['store_id'], $f['tanggal']);

            $rows[] = [
                $f['ref'],
                $c->isoFormat('D MMM Y'),
                \Illuminate\Support\Str::limit($f['toko'], 14, ''),
                \Illuminate\Support\Str::limit($f['bahan'], 20, ''),
                number_format($f['tercatat'], 2, ',', '.'),
                number_format($f['seharusnya'], 2, ',', '.'),
                ($f['selisih'] >= 0 ? '+' : '−') . 'Rp ' . number_format(abs($f['selisih']), 0, ',', '.'),
                $locked ? '🔒 terkunci' : 'bisa diperbaiki',
            ];
            $totalSelisih += $f['selisih'];
        }

        $this->table(
            ['REF', 'TANGGAL', 'TOKO', 'BAHAN', 'HARGA/BASE TERCATAT', 'SEHARUSNYA', 'SELISIH NILAI', 'STATUS'],
            $rows
        );

        $this->newLine();
        $this->line('Total selisih nilai: ' . ($totalSelisih >= 0 ? '+' : '−')
            . 'Rp ' . number_format(abs($totalSelisih), 0, ',', '.'));
        $this->newLine();
        $this->line('Keterangan:');
        $this->line('  • "bisa diperbaiki" → hapus mutasi tsb lalu input ulang (pastikan pencatatan');
        $this->line('    harian sebelum tanggalnya sudah dikonfirmasi lebih dulu).');
        $this->line('  • "🔒 terkunci" → periode sudah kena opname approved / snapshot HPP.');
        $this->line('    Sebaiknya DIBIARKAN — selisih diserap lewat opname periode berjalan.');
        $this->newLine();
        $this->info('Tidak ada data yang diubah oleh command ini (read-only).');

        return self::SUCCESS;
    }
}
