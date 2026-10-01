<?php

namespace App\Console\Commands;

use App\Models\{DailyConfirmation, DailyUsage, Ingredient, Opname, Store, User};
use App\Services\{StockBalanceService, StockRecognition};
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnosa HANYA-BACA: kenapa "Stok Sistem" di Stok Opname berbeda dengan
 * "Stok Akhir" di Pencatatan Harian untuk satu bahan di satu toko.
 *
 * Mengambil angka langsung dari kedua halaman (bukan menghitung ulang sendiri),
 * lalu membedah komponen stoknya per kemasan supaya kelihatan sumber selisihnya.
 */
class DiagnosaStok extends Command
{
    protected $signature   = 'stok:diagnosa {toko : nama/ID toko} {bahan : nama/ID bahan, atau "semua" untuk memeriksa semua bahan} {tanggal : tanggal opname, mis. 2026-09-30}';
    protected $description = 'Bandingkan Stok Sistem opname vs Stok Akhir pencatatan harian (satu bahan beserta rinciannya, atau semua bahan)';

    public function handle(): int
    {
        $store = is_numeric($this->argument('toko')) ? Store::find($this->argument('toko'))
               : Store::where('name', 'like', '%' . $this->argument('toko') . '%')->first();
        if (!$store) { $this->error('Toko tidak ditemukan.'); return self::FAILURE; }
        $tgl   = Carbon::parse($this->argument('tanggal'));

        if (strtolower($this->argument('bahan')) === 'semua') {
            return $this->semuaBahan($store, $tgl);
        }

        $ing   = is_numeric($this->argument('bahan')) ? Ingredient::find($this->argument('bahan'))
               : Ingredient::where('name', 'like', '%' . $this->argument('bahan') . '%')->first();
        if (!$ing) { $this->error('Bahan tidak ditemukan.'); return self::FAILURE; }

        $awal  = $tgl->copy()->startOfMonth()->toDateString();
        $D     = $tgl->toDateString();
        $u     = User::where('role', 'super_admin')->first();
        auth()->login($u);
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);

        $this->info("Toko: {$store->name} (#{$store->id}) | Bahan: {$ing->name} (#{$ing->id}) | Tanggal: {$D}");
        $this->line('Hari ini: ' . now()->toDateString() . ' | bulan ' . $tgl->isoFormat('MMMM Y') . ' = '
            . ($tgl->isSameMonth(now()) ? 'BULAN BERJALAN' : 'bulan lampau'));
        $this->newLine();

        $pkgs = $ing->packagings()->orderBy('id')->get();
        $dus  = function ($base, $p) {
            $ctb = (float) $p->crate_to_pack * (float) $p->pack_to_base;
            $ptb = (float) $p->pack_to_base;
            if ($ctb <= 0 || $ptb <= 0) return number_format($base, 2, ',', '.') . ' base';
            $neg = $base < 0; $b = abs($base);
            $d = floor($b / $ctb); $pk = floor(($b - $d * $ctb) / $ptb); $sisa = $b - $d * $ctb - $pk * $ptb;
            return ($neg ? '-' : '') . "{$d} dus {$pk} pack" . ($sisa > 0.001 ? ' +' . round($sisa, 2) : '')
                 . '  (' . number_format($base, 2, ',', '.') . ' base)';
        };

        // ── 1) Angka dari HALAMAN Stok Opname (input baru per tanggal) ─────────
        $r = \Illuminate\Http\Request::create('/x', 'GET', ['store_id' => $store->id, 'date' => $D]);
        $r->setUserResolver(fn() => $u); app()->instance('request', $r);
        $opRows = collect(json_decode(app(\App\Http\Controllers\Opname\OpnameController::class)->systemQty($r)->getContent(), true))
            ->where('ingredient_id', $ing->id)->keyBy('packaging_id');

        // ── 2) Angka dari HALAMAN Pencatatan Harian (stok akhir bulan itu) ────
        $r2 = \Illuminate\Http\Request::create('/x', 'GET', ['store_id' => $store->id, 'month' => $tgl->month, 'year' => $tgl->year]);
        $r2->setUserResolver(fn() => $u); app()->instance('request', $r2);
        $html = app(\App\Http\Controllers\Inventory\DailyLedgerController::class)->index($r2)->render();
        $ledger = [];
        preg_match_all('/<tr\s+data-opening="([-\d.]+)"\s+data-avail="([-\d.]+)"\s+data-ptb="([\d.]+)"\s+data-ctb="[\d.]+"\s+data-ing="'
            . $ing->id . '"\s+data-pkg="(\d*)"(.*?)<\/tr>/su', $html, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $tot = 0.0;
            if (preg_match_all('/class="[^"]*td-usage-cell[^"]*"[^>]*data-val="([\d.]*)"/', $x[5], $c))
                foreach ($c[1] as $v) $tot += (float) ($v ?: 0);
            $ledger[(int) $x[4]] = ['awal' => (float) $x[1], 'akhir' => (float) $x[2] - $tot * (float) $x[3],
                                    'tot_pack' => $tot];
        }

        $rows = [];
        foreach ($pkgs as $p) {
            $s = $opRows[$p->id]['system_qty'] ?? null;
            $a = $ledger[$p->id]['akhir'] ?? null;
            $rows[] = ["#{$p->id} @" . (int) $p->crate_to_pack . ' pack' . ($p->is_active ? '' : ' (nonaktif)'),
                $s === null ? '(tidak tampil)' : $dus((float) $s, $p),
                $a === null ? '(tidak tampil)' : $dus($a, $p),
                ($s !== null && $a !== null) ? (abs($s - $a) < 0.01 ? 'SAMA' : 'BEDA ' . number_format($s - $a, 2, ',', '.')) : '-'];
        }
        $this->line("PERBANDINGAN (Opname tgl {$D} vs Stok Akhir Pencatatan Harian " . $tgl->isoFormat('MMMM') . '):');
        $this->table(['Kemasan', 'Stok Sistem Opname', 'Stok Akhir Pencatatan', 'Selisih (base)'], $rows);

        // ── 3) Rincian komponen ────────────────────────────────────────────────
        $sumBy = fn($q) => $q->selectRaw('mi.packaging_id p, SUM(mi.total_in_base) t')->groupBy('mi.packaging_id')->pluck('t', 'p');
        $base  = fn() => DB::table('mutation_items as mi')->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
                         ->where('m.status', 'confirmed')->where('mi.ingredient_id', $ing->id);

        $fifo  = DB::table('mutation_items as mi')->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
            ->where('m.status', 'confirmed')->where('m.destination_store_id', $store->id)->where('mi.ingredient_id', $ing->id)
            ->selectRaw('mi.packaging_id p, SUM(mi.remaining_qty) t')->groupBy('mi.packaging_id')->pluck('t', 'p');
        $saldo = StockBalanceService::saldoPerKemasan($store->id, $D);
        $transit = StockRecognition::transitTujuanPerKemasan($store->id, $D);

        $usage = fn($sampai, $konfirmasi) => DailyUsage::where('store_id', $store->id)->where('ingredient_id', $ing->id)
            ->whereBetween('usage_date', [$awal, $sampai])
            ->{$konfirmasi ? 'whereExists' : 'whereNotExists'}(fn($q) => $q->from('daily_confirmations')
                ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date'))
            ->selectRaw('packaging_id p, SUM(qty_pack) t, GROUP_CONCAT(DISTINCT DAY(usage_date) ORDER BY usage_date) tgl')
            ->groupBy('packaging_id')->get()->keyBy('p');
        $akhirBulan = $tgl->copy()->endOfMonth()->toDateString();
        $uKonf  = $usage($akhirBulan, true);
        $uDraft = $usage($akhirBulan, false);
        $uSetelah = DailyUsage::where('store_id', $store->id)->where('ingredient_id', $ing->id)
            ->where('usage_date', '>', $D)->where('qty_pack', '>', 0)
            ->whereExists(fn($q) => $q->from('daily_confirmations')
                ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date'))
            ->selectRaw('packaging_id p, SUM(qty_pack) t')->groupBy('packaging_id')->pluck('t', 'p');

        $det = [];
        foreach ($pkgs as $p) {
            $k = $ing->id . '-' . $p->id; $ptb = (float) $p->pack_to_base;
            $det[] = ["#{$p->id}",
                number_format((float) ($fifo[$p->id] ?? 0), 0, ',', '.'),
                number_format((float) ($saldo[$k] ?? 0), 0, ',', '.'),
                number_format((float) ($uKonf[$p->id]->t ?? 0) * $ptb, 0, ',', '.') . ' (' . (float) ($uKonf[$p->id]->t ?? 0) . ' pk)',
                ($uDraft[$p->id]->t ?? 0) ? number_format((float) $uDraft[$p->id]->t * $ptb, 0, ',', '.') . ' (' . (float) $uDraft[$p->id]->t
                    . ' pk, tgl ' . $uDraft[$p->id]->tgl . ')' : '0',
                number_format((float) ($uSetelah[$p->id] ?? 0) * $ptb, 0, ',', '.'),
                number_format((float) ($transit[$k] ?? 0), 0, ',', '.'),
            ];
        }
        $this->newLine();
        $this->line('RINCIAN (dalam base):');
        $this->table(['Kemasan', 'Sisa FIFO skrg', "Saldo s/d {$D}", 'Pemakaian dikonfirmasi (bln ini)',
                      'Pemakaian BELUM dikonfirmasi', "Pemakaian dikonfirmasi SETELAH {$D}", 'Dlm perjalanan'], $det);

        $op = Opname::where('store_id', $store->id)->whereDate('opname_date', $D)->orderByDesc('id')->first();
        $this->line('Opname tersimpan di tanggal itu: ' . ($op ? "#{$op->id} status {$op->status} ({$op->opname_mode})" : 'tidak ada'));
        return self::SUCCESS;
    }

    /**
     * Mode SEMUA BAHAN: tampilkan setiap (bahan × kemasan) yang angkanya tidak cocok
     * di antara tiga sumber:
     *   1. Stok Sistem opname DIHITUNG ULANG sekarang (= yang tampil bila halaman dibuka ulang)
     *   2. Stok Akhir Pencatatan Harian bulan itu
     *   3. Stok Sistem yang TERSIMPAN di opname pada tanggal itu (kalau ada)
     */
    private function semuaBahan(Store $store, Carbon $tgl): int
    {
        $D = $tgl->toDateString();
        $u = User::where('role', 'super_admin')->first();
        auth()->login($u);
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);

        $this->info("Toko: {$store->name} (#{$store->id}) | Tanggal opname: {$D} | SEMUA BAHAN");
        $this->line('Hari ini: ' . now()->toDateString() . ' | bulan ' . $tgl->isoFormat('MMMM Y') . ' = '
            . ($tgl->isSameMonth(now()) ? 'BULAN BERJALAN' : 'bulan lampau'));

        // 1) Stok Sistem opname dihitung ulang
        $r = \Illuminate\Http\Request::create('/x', 'GET', ['store_id' => $store->id, 'date' => $D]);
        $r->setUserResolver(fn() => $u); app()->instance('request', $r);
        $hitung = collect(json_decode(app(\App\Http\Controllers\Opname\OpnameController::class)->systemQty($r)->getContent(), true))
            ->mapWithKeys(fn($x) => [$x['ingredient_id'] . '-' . ($x['packaging_id'] ?: 0) => (float) $x['system_qty']]);

        // 2) Stok Akhir Pencatatan Harian
        $r2 = \Illuminate\Http\Request::create('/x', 'GET', ['store_id' => $store->id, 'month' => $tgl->month, 'year' => $tgl->year]);
        $r2->setUserResolver(fn() => $u); app()->instance('request', $r2);
        $html = app(\App\Http\Controllers\Inventory\DailyLedgerController::class)->index($r2)->render();
        $ledger = [];
        preg_match_all('/<tr\s+data-opening="([-\d.]+)"\s+data-avail="([-\d.]+)"\s+data-ptb="([\d.]+)"\s+data-ctb="[\d.]+"\s+data-ing="(\d+)"\s+data-pkg="(\d*)"(.*?)<\/tr>/su',
            $html, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $tot = 0.0;
            if (preg_match_all('/class="[^"]*td-usage-cell[^"]*"[^>]*data-val="([\d.]*)"/', $x[6], $c))
                foreach ($c[1] as $v) $tot += (float) ($v ?: 0);
            $ledger[$x[4] . '-' . ($x[5] ?: 0)] = (float) $x[2] - $tot * (float) $x[3];
        }

        // 3) Tersimpan di opname pada tanggal itu
        $op = Opname::where('store_id', $store->id)->whereDate('opname_date', $D)->orderByDesc('id')->first();
        $simpan = $op ? DB::table('opname_items')->where('opname_id', $op->id)
            ->get(['ingredient_id', 'packaging_id', 'system_qty'])
            ->mapWithKeys(fn($x) => [$x->ingredient_id . '-' . ($x->packaging_id ?: 0) => (float) $x->system_qty]) : collect();

        $nama = Ingredient::pluck('name', 'id');
        $pkgs = \App\Models\IngredientPackaging::get()->keyBy('id');
        $fmt  = function ($base, $pid) use ($pkgs) {
            if ($base === null) return '-';
            $p   = $pkgs[$pid] ?? null;
            $ctb = $p ? (float) $p->crate_to_pack * (float) $p->pack_to_base : 0;
            $ptb = $p ? (float) $p->pack_to_base : 0;
            if ($ctb <= 0 || $ptb <= 0) return number_format($base, 0, ',', '.');
            $neg = $base < 0; $b = abs($base); $d = floor($b / $ctb); $pk = floor(($b - $d * $ctb) / $ptb);
            return ($neg ? '-' : '') . "{$d} dus {$pk} pack";
        };

        $kunci = $hitung->keys()->merge(array_keys($ledger))->merge($simpan->keys())->unique();
        $baris = []; $bedaLedger = 0; $basi = 0;
        foreach ($kunci as $k) {
            [$iid, $pid] = array_map('intval', explode('-', $k));
            $h = $hitung[$k] ?? null; $l = $ledger[$k] ?? null; $s = $simpan[$k] ?? null;
            $x1 = $h !== null && $l !== null && abs($h - $l) > 0.01;   // opname vs pencatatan
            $x2 = $h !== null && $s !== null && abs($h - $s) > 0.01;   // angka tersimpan sudah basi
            if (!$x1 && !$x2) continue;
            $bedaLedger += $x1 ? 1 : 0; $basi += $x2 ? 1 : 0;
            $baris[] = [mb_substr($nama[$iid] ?? "#$iid", 0, 28), $pid ? "#$pid" : '-',
                        $fmt($h, $pid), $fmt($l, $pid), $op ? $fmt($s, $pid) : '-',
                        trim(($x1 ? 'OPNAME≠PENCATATAN ' : '') . ($x2 ? 'TERSIMPAN BASI' : ''))];
        }

        $this->newLine();
        $this->line('Dibandingkan: ' . $kunci->count() . ' baris bahan × kemasan | Opname tersimpan: '
            . ($op ? "#{$op->id} ({$op->status})" : 'tidak ada'));
        if (!$baris) {
            $this->info('SEMUA COCOK — Stok Sistem opname, Stok Akhir Pencatatan Harian, dan angka tersimpan sama untuk semua bahan.');
            return self::SUCCESS;
        }

        // ── Simulasi: apakah selisih OPNAME≠PENCATATAN hilang bila FIFO dihitung ulang?
        // Dijalankan di dalam transaksi lalu DIBATALKAN — tidak ada yang berubah.
        //   hilang  → FIFO tersimpan bergeser (data), beres dengan stok:hitung-ulang-fifo
        //   tetap   → rumus kedua halaman memang berbeda (kode), harus diperbaiki
        $ingBeda  = [];
        foreach ($kunci as $k) {
            $h = $hitung[$k] ?? null; $l = $ledger[$k] ?? null;
            if ($h !== null && $l !== null && abs($h - $l) > 0.01) $ingBeda[(int) explode('-', $k)[0]] = true;
        }
        $setelah = collect();
        if ($ingBeda) {
            DB::beginTransaction();
            try {
                foreach (array_keys($ingBeda) as $iid) \App\Services\FifoService::recalculate($store->id, $iid);
                $r3 = \Illuminate\Http\Request::create('/x', 'GET', ['store_id' => $store->id, 'date' => $D]);
                $r3->setUserResolver(fn() => $u); app()->instance('request', $r3);
                $setelah = collect(json_decode(app(\App\Http\Controllers\Opname\OpnameController::class)->systemQty($r3)->getContent(), true))
                    ->mapWithKeys(fn($x) => [$x['ingredient_id'] . '-' . ($x['packaging_id'] ?: 0) => (float) $x['system_qty']]);
            } finally {
                DB::rollBack();
            }
        }
        $i = 0;
        foreach ($kunci as $k) {
            [$iid, $pid] = array_map('intval', explode('-', $k));
            $h = $hitung[$k] ?? null; $l = $ledger[$k] ?? null; $s = $simpan[$k] ?? null;
            $x1 = $h !== null && $l !== null && abs($h - $l) > 0.01;
            $x2 = $h !== null && $s !== null && abs($h - $s) > 0.01;
            if (!$x1 && !$x2) continue;
            if ($x1) {
                $sa = $setelah[$k] ?? null;
                $baris[$i][] = $fmt($sa, $pid);
                $baris[$i][] = ($sa !== null && abs($sa - $l) <= 0.01) ? 'FIFO BERGESER' : 'RUMUS BEDA';
            } else {
                $baris[$i][] = '-'; $baris[$i][] = '';
            }
            $i++;
        }

        $this->table(['Bahan', 'Kms', 'Opname (hitung ulang)', 'Pencatatan Harian', 'Opname (tersimpan)', 'Masalah',
                      'Opname stlh FIFO dihitung ulang', 'Jenis'], $baris);
        $nGeser = collect($baris)->where(7, 'FIFO BERGESER')->count();
        $nRumus = collect($baris)->where(7, 'RUMUS BEDA')->count();
        $this->line("FIFO BERGESER : {$nGeser} baris  ← data, beres dgn: php artisan stok:hitung-ulang-fifo --store={$store->id} --apply");
        $this->line("RUMUS BEDA    : {$nRumus} baris  ← kode, perlu diperbaiki — kirim hasil ini");
        $this->line("OPNAME≠PENCATATAN : {$bedaLedger} baris  ← perhitungan berbeda, perlu ditelusuri");
        $this->line("TERSIMPAN BASI    : {$basi} baris  ← draft: beres sendiri saat halaman opname dibuka ulang");
        $this->line('Rincian satu bahan: php artisan stok:diagnosa "' . $store->name . '" "NAMA BAHAN" ' . $D);
        return self::SUCCESS;
    }
}
