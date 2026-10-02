<?php

namespace App\Console\Commands;

use App\Models\{Ingredient, Store};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Forensik HANYA-BACA: garis waktu semua kejadian yang memengaruhi stok satu bahan
 * di satu toko — supaya ketahuan kejadian MANA yang membuat FIFO bergeser.
 *
 * FIFO bergeser = ada kejadian yang mengubah stok (pemakaian, konfirmasi tanggal,
 * transfer, waste, opname) tapi TIDAK diikuti penulisan ulang remaining_qty.
 * Penulisan remaining_qty tercatat di audit_logs (MutationItem), jadi kejadian yang
 * terjadi SETELAH penulisan terakhir adalah tersangka utama.
 */
class JejakStok extends Command
{
    protected $signature   = 'stok:jejak {toko : nama/ID toko} {bahan : nama/ID bahan} {--hari=14 : rentang hari ke belakang}';
    protected $description = 'Garis waktu kejadian stok satu bahan di satu toko (forensik FIFO bergeser)';

    public function handle(): int
    {
        $store = is_numeric($this->argument('toko')) ? Store::find($this->argument('toko'))
               : Store::where('name', 'like', '%' . $this->argument('toko') . '%')->first();
        $ing   = is_numeric($this->argument('bahan')) ? Ingredient::find($this->argument('bahan'))
               : Ingredient::where('name', 'like', '%' . $this->argument('bahan') . '%')->first();
        if (!$store || !$ing) { $this->error('Toko atau bahan tidak ditemukan.'); return self::FAILURE; }

        $sejak = now()->subDays((int) $this->option('hari'))->startOfDay();
        $this->info("Toko: {$store->name} (#{$store->id}) | Bahan: {$ing->name} (#{$ing->id}) | sejak {$sejak->toDateString()}");

        $ev = [];

        // 1) Penulisan remaining_qty (audit MutationItem) — batch milik toko ini
        $itemIds = DB::table('mutation_items as mi')->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
            ->where('mi.ingredient_id', $ing->id)
            ->where(fn($q) => $q->where('m.destination_store_id', $store->id)->orWhere('m.source_store_id', $store->id))
            ->pluck('mi.id');
        foreach (DB::table('audit_logs')->where('model', 'MutationItem')->whereIn('model_id', $itemIds)
                    ->where('created_at', '>=', $sejak)->orderBy('id')->get() as $a) {
            $old = json_decode($a->old_values, true) ?: []; $new = json_decode($a->new_values, true) ?: [];
            if (!array_key_exists('remaining_qty', $new)) continue;
            $ev[] = [$a->created_at, 'FIFO', "item #{$a->model_id} remaining " . ($old['remaining_qty'] ?? '?') . ' → ' . $new['remaining_qty'],
                     $a->user_name ?: 'sistem'];
        }

        // 2) Pemakaian harian (dibuat / diubah)
        $users = DB::table('users')->pluck('name', 'id');
        foreach (DB::table('daily_usages')->where('store_id', $store->id)->where('ingredient_id', $ing->id)
                    ->where('updated_at', '>=', $sejak)->get() as $u) {
            $konf = DB::table('daily_confirmations')->where('store_id', $store->id)
                ->whereDate('confirmation_date', $u->usage_date)->value('created_at');
            $baru = $u->created_at === $u->updated_at;
            $ket  = "tgl " . substr($u->usage_date, 0, 10) . " kms #{$u->packaging_id} = {$u->qty_pack} pack ("
                  . ($baru ? 'diketik' : 'diubah') . ')'
                  . ($konf ? ' | tgl itu dikonfirmasi ' . $konf : ' | tgl itu BELUM dikonfirmasi');
            $ev[] = [$u->updated_at, 'PAKAI', $ket, $users[$u->created_by] ?? '-'];
        }

        // 3) Konfirmasi tanggal yang memuat pemakaian bahan ini
        $tglPakai = DB::table('daily_usages')->where('store_id', $store->id)->where('ingredient_id', $ing->id)
            ->where('qty_pack', '>', 0)->pluck('usage_date')->map(fn($d) => substr($d, 0, 10))->unique()->all();
        foreach (DB::table('daily_confirmations')->where('store_id', $store->id)
                    ->where('created_at', '>=', $sejak)->get() as $c) {
            if (!in_array(substr($c->confirmation_date, 0, 10), $tglPakai, true)) continue;
            $ev[] = [$c->created_at, 'KONFIRM', 'tanggal ' . substr($c->confirmation_date, 0, 10) . ' dikonfirmasi',
                     $users[$c->confirmed_by] ?? '-'];
        }

        // 4) Mutasi yang memuat bahan ini (dibuat / dikonfirmasi / diubah)
        foreach (DB::table('mutations as m')->join('mutation_items as mi', 'mi.mutation_id', '=', 'm.id')
                    ->where('mi.ingredient_id', $ing->id)
                    ->where(fn($q) => $q->where('m.destination_store_id', $store->id)->orWhere('m.source_store_id', $store->id))
                    ->where('m.updated_at', '>=', $sejak)
                    ->select('m.id', 'm.reference_no', 'm.type', 'm.status', 'm.updated_at', 'm.source_store_id',
                             'm.transaction_date', 'm.delivery_date', 'm.confirmed_by')
                    ->distinct()->get() as $m) {
            $arah = $m->source_store_id == $store->id ? 'KELUAR' : 'MASUK';
            $ev[] = [$m->updated_at, 'MUTASI', "{$m->reference_no} {$m->type} {$arah} status={$m->status} kirim="
                     . substr($m->transaction_date, 0, 10) . ' terima=' . ($m->delivery_date ? substr($m->delivery_date, 0, 10) : '-'),
                     $users[$m->confirmed_by] ?? '-'];
        }

        // 5) Waste
        foreach (DB::table('waste_logs as w')->join('waste_log_items as wi', 'wi.waste_log_id', '=', 'w.id')
                    ->where('w.store_id', $store->id)->where('wi.ingredient_id', $ing->id)
                    ->where('w.updated_at', '>=', $sejak)
                    ->select('w.id', 'w.waste_date', 'w.updated_at', 'w.recorded_by')->distinct()->get() as $w) {
            $ev[] = [$w->updated_at, 'WASTE', "waste #{$w->id} tgl " . substr($w->waste_date, 0, 10), $users[$w->recorded_by] ?? '-'];
        }

        // 6) Opname (status berubah)
        foreach (DB::table('audit_logs')->where('model', 'Opname')->where('created_at', '>=', $sejak)
                    ->whereIn('model_id', DB::table('opnames')->where('store_id', $store->id)->pluck('id'))
                    ->orderBy('id')->get() as $a) {
            $new = json_decode($a->new_values, true) ?: [];
            if (!isset($new['status'])) continue;
            $ev[] = [$a->created_at, 'OPNAME', "opname #{$a->model_id} status → {$new['status']}", $a->user_name ?: '-'];
        }

        usort($ev, fn($a, $b) => strcmp((string) $a[0], (string) $b[0]));
        if (!$ev) { $this->line('Tidak ada kejadian dalam rentang ini.'); return self::SUCCESS; }

        // Satu hitung ulang FIFO menulis puluhan baris audit. Baris FIFO yang berurutan
        // diringkas jadi SATU baris (jumlah perubahan + rentang waktu) supaya kejadian
        // lain tidak tenggelam.
        $ringkas = [];
        foreach ($ev as $e) {
            $akhir = end($ringkas);
            if ($e[1] === 'FIFO' && $akhir && $akhir[1] === 'FIFO') {
                $k = array_key_last($ringkas);
                $ringkas[$k][0] = $e[0];                         // waktu = akhir rentang
                $ringkas[$k][5]++;
                $ringkas[$k][2] = "FIFO ditulis ulang ({$ringkas[$k][5]} perubahan, mulai {$ringkas[$k][6]})";
                continue;
            }
            $ringkas[] = $e[1] === 'FIFO'
                ? [$e[0], 'FIFO', 'FIFO ditulis ulang (1 perubahan)', $e[3], null, 1, substr((string) $e[0], 11)]
                : $e;
        }
        $ev = array_map(fn($e) => array_slice($e, 0, 4), $ringkas);
        if (count($ev) > 60) {
            $this->line('(' . (count($ev) - 60) . ' kejadian lebih lama tidak ditampilkan — kecilkan --hari bila perlu)');
            $ev = array_slice($ev, -60);
        }

        // Tandai kejadian yang terjadi SETELAH penulisan FIFO terakhir = tersangka
        $terakhirFifo = collect($ev)->where(1, 'FIFO')->max(0);
        $baris = array_map(function ($e) use ($terakhirFifo) {
            $curiga = $terakhirFifo && $e[1] !== 'FIFO' && (string) $e[0] > (string) $terakhirFifo;
            return [$e[0], $e[1], mb_substr($e[2], 0, 110), $e[3], $curiga ? '◀ SETELAH FIFO TERAKHIR' : ''];
        }, $ev);

        $this->table(['Waktu', 'Jenis', 'Keterangan', 'Oleh', ''], $baris);
        $this->line('Penulisan FIFO terakhir: ' . ($terakhirFifo ?: '(tidak ada dalam rentang)'));
        $this->line('Baris bertanda ◀ = kejadian yang belum diikuti hitung ulang FIFO → tersangka pergeseran.');
        return self::SUCCESS;
    }
}
