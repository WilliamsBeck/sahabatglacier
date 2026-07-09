<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Models\MutationItem;
use App\Models\OpnameItem;
use App\Models\Store;
use Illuminate\Console\Command;

/**
 * Diagnostik: bandingkan stok fisik opname approved TERAKHIR vs sisa FIFO
 * (yang dipakai Saldo Stok), per (bahan × kemasan). Untuk menelusuri kenapa
 * Saldo Stok tidak cocok dengan Stok Opname. READ-ONLY (tidak mengubah apa pun).
 */
class AuditOpnameStock extends Command
{
    protected $signature = 'opname:audit-stock
                            {--store= : Nama/ID toko (wajib)}
                            {--ingredient= : Filter nama bahan (opsional)}';

    protected $description = 'Bandingkan stok fisik opname vs sisa FIFO (Saldo Stok) per bahan-kemasan.';

    public function handle(): int
    {
        $storeOpt = $this->option('store');
        $store = is_numeric($storeOpt)
            ? Store::find((int) $storeOpt)
            : Store::where('name', 'like', '%' . $storeOpt . '%')->first();

        if (!$store) {
            $this->error('Toko tidak ditemukan. Pakai --store=Nama atau --store=ID');
            return self::FAILURE;
        }
        $this->info("Toko: {$store->name} (id={$store->id})");

        $ingQuery = Ingredient::query();
        if ($this->option('ingredient')) {
            $ingQuery->where('name', 'like', '%' . $this->option('ingredient') . '%');
        }
        $ingredients = $ingQuery->with(['packagings' => fn($q) => $q->where('is_active', true)->orderBy('id')])->get();

        foreach ($ingredients as $ing) {
            $lines = [];
            foreach ($ing->packagings as $pkg) {
                $ctp = (float) $pkg->crate_to_pack;
                $ptb = (float) $pkg->pack_to_base;

                // Opname approved terakhir (semua batch) untuk ing+pkg ini
                $opItems = OpnameItem::where('ingredient_id', $ing->id)
                    ->where('packaging_id', $pkg->id)
                    ->whereHas('opname', fn($q) => $q->where('store_id', $store->id)->where('status', 'approved'))
                    ->whereIn('opname_id', function ($q) use ($store) {
                        $q->select('id')->from('opnames')
                          ->where('store_id', $store->id)->where('status', 'approved')
                          ->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('id')->limit(1);
                    })
                    ->get();

                if ($opItems->isEmpty()) continue;

                $opDus  = $opItems->sum('physical_crate');
                $opPack = $opItems->sum('physical_pack');
                $opBase = $opItems->sum('physical_base');
                $opWholeBase = $opDus * $ctp * $ptb + $opPack * $ptb;   // dus+pack (tanpa eceran)
                $opWholePacks = $ptb > 0 ? $opWholeBase / $ptb : 0;

                // Sisa FIFO sekarang
                $fifo = MutationItem::where('ingredient_id', $ing->id)
                    ->where('packaging_id', $pkg->id)
                    ->where('remaining_qty', '>', 0)
                    ->whereHas('mutation', fn($q) => $q->where('destination_store_id', $store->id)->where('status', 'confirmed'))
                    ->sum('remaining_qty');
                $fifoPacks = $ptb > 0 ? $fifo / $ptb : 0;

                // Saldo Stok = (FIFO - eceran opname) dibulatkan ke pack utuh
                $saldoBase  = max(0, $fifo - $opBase);
                $saldoPacks = $ptb > 0 ? floor($saldoBase / $ptb) : 0;

                $flag = (round($opWholePacks) != $saldoPacks) ? '  <<< TIDAK COCOK' : '';
                $lines[] = sprintf(
                    "   pkg[%s ctp=%s ptb=%s] opname: %sdus %spack %sbase (=%.0f pack utuh) | FIFO=%.0f (%.1f pack) | eceran=%.0f | Saldo=%s pack%s",
                    $pkg->packaging_name, $ctp, $ptb, $opDus, $opPack, $opBase,
                    $opWholePacks, $fifo, $fifoPacks, $opBase, $saldoPacks, $flag
                );
            }
            if ($lines) {
                $this->line($ing->name);
                foreach ($lines as $l) $this->line($l);
            }
        }

        return self::SUCCESS;
    }
}
