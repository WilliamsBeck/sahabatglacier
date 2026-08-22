<?php
namespace App\Services;

use App\Models\Mutation;
use App\Models\StockLedger;
use App\Models\StoreStock;
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
                        // Re-sync FIFO toko PENERIMA — sama seperti pada pembelian.
                        // Batch baru ini masuk dengan remaining_qty penuh, sementara toko
                        // penerima mungkin sudah punya pemakaian/waste/opname yang perlu
                        // diterapkan ulang. Tanpa ini, stok penerima bisa kelebihan
                        // (mis. saat mutasi dikonfirmasi ulang setelah dibatalkan).
                        FifoService::recalculate($mutation->destination_store_id, $ingredientId);
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

            $pkg = $pkgId ? \App\Models\IngredientPackaging::find($pkgId) : null;
            $ptb = $pkg ? (float) $pkg->pack_to_base : 0;
            $ctb = $pkg ? (float) $pkg->crate_to_pack * $ptb : 0;

            // Gabungkan lapisan FIFO yang HARGANYA SAMA (dibulatkan per dus/pack) supaya
            // batch berbeda tapi harga identik tidak terpecah jadi banyak baris sia-sia.
            $groups = [];
            foreach ($layers as $L) {
                $pb  = (float) $L['price_per_base'];
                $key = $ctb > 0 ? (string) round($pb * $ctb)
                     : ($ptb > 0 ? (string) round($pb * $ptb) : number_format($pb, 6, '.', ''));
                if (!isset($groups[$key])) $groups[$key] = ['base' => 0.0, 'wsum' => 0.0];
                $groups[$key]['base'] += (float) $L['base'];
                $groups[$key]['wsum'] += (float) $L['base'] * $pb;
            }
            $layers = array_values(array_map(fn($g) => [
                'base'           => $g['base'],
                'price_per_base' => $g['base'] > 0 ? $g['wsum'] / $g['base'] : 0.0,
            ], $groups));

            // 1 harga: tidak perlu dipecah, tapi PASTIKAN harga = harga FIFO sumber
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

    // ─────────────────────────────────────────────────────────────────────
    // FIFO backdate auto-fix — dipakai bareng oleh 2 pemicu:
    //  1) MutationController: pembelian/sale_external yang tgl terimanya
    //     lebih AWAL dari transfer yang sudah confirmed (batch baru "menyelip").
    //  2) DailyLedgerController: pencatatan harian yang dikonfirmasi BELAKANGAN,
    //     padahal ada transfer confirmed di tanggal setelahnya (transfer itu
    //     kepakai batch yang seharusnya sudah habis dipakai harian duluan).
    // Kedua arah sama-sama berujung pada transfer confirmed yang harganya jadi
    // stale karena urutan FIFO berubah SETELAH transfer itu dikonfirmasi — jadi
    // logikanya sengaja disatukan di sini supaya tidak mencar & tidak bias
    // (satu arah dapat auto-fix, arah lain tidak).
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Transfer/penjualan KELUAR (sale_internal / sale_external_out) yang sudah
     * confirmed dari toko $storeId, tanggalnya SETELAH $afterDate, untuk salah
     * satu pasangan (ingredient_id, packaging_id) di $pairs. Ini daftar kandidat
     * transfer yang mungkin kepakai batch keliru karena batch/pemakaian di
     * $afterDate baru "masuk" ke FIFO belakangan.
     *
     * @param iterable<array{0:int,1:?int}> $pairs pasangan [ingredient_id, packaging_id]
     * @return array<int,array{id:int,reference_no:string,date:string}>
     */
    public static function confirmedTransfersAfter(int $storeId, iterable $pairs, string $afterDate): array
    {
        $pairs = collect($pairs)->filter(fn($p) => !empty($p[0]))->values();
        if ($pairs->isEmpty()) return [];

        return Mutation::where('source_store_id', $storeId)
            ->where('status', 'confirmed')
            ->whereIn('type', ['sale_internal', 'sale_external_out'])
            ->whereHas('items', function ($q) use ($pairs) {
                $q->where(function ($qq) use ($pairs) {
                    foreach ($pairs as [$ingId, $pkgId]) {
                        $qq->orWhere(function ($q3) use ($ingId, $pkgId) {
                            $q3->where('ingredient_id', $ingId);
                            $pkgId === null ? $q3->whereNull('packaging_id') : $q3->where('packaging_id', $pkgId);
                        });
                    }
                });
            })
            ->whereRaw('COALESCE(delivery_date, transaction_date) > ?', [$afterDate])
            ->orderByRaw('COALESCE(delivery_date, transaction_date)')
            ->get(['id', 'reference_no', 'delivery_date', 'transaction_date'])
            ->map(fn($m) => [
                'id'           => $m->id,
                'reference_no' => $m->reference_no,
                'date'         => ($m->delivery_date ?? $m->transaction_date)->toDateString(),
            ])->all();
    }

    /**
     * Kebalikan dari confirmedTransfersAfter: dipakai saat mutasi ini MAU
     * dibatalkan konfirmasinya (Batalkan Konfirmasi). Membatalkan sebuah batch
     * (pembelian/sale_external) menghilangkannya dari FIFO, dan membatalkan
     * sebuah transfer mengembalikan qty-nya ke toko sumber + menghapus batch
     * yang tadi dibuatnya di toko tujuan — DUA-DUANYA bisa menggeser urutan FIFO
     * utk transfer LAIN yang confirmed di tanggal setelahnya, persis seperti saat
     * mutasi ini dikonfirmasi (lihat pendingConfirmedTransfersAfter di
     * MutationController). Dicek di KEDUA toko yang terlibat (sumber & tujuan),
     * bukan cuma satu arah, supaya tidak bias sebelah.
     *
     * @return array<int,array{id:int,reference_no:string,date:string}>
     */
    public static function pendingConfirmedTransfersAfterUnconfirm(Mutation $mutation): array
    {
        $mutation->loadMissing('items');
        $storeIds = array_filter([$mutation->source_store_id, $mutation->destination_store_id]);
        if (empty($storeIds)) return [];

        $pairs = $mutation->items->map(fn($i) => [$i->ingredient_id, $i->packaging_id])->unique()->values();
        if ($pairs->isEmpty()) return [];

        $upTo = ($mutation->delivery_date ?? $mutation->transaction_date)->toDateString();

        $all = [];
        foreach ($storeIds as $sid) {
            foreach (self::confirmedTransfersAfter((int) $sid, $pairs, $upTo) as $t) {
                if ($t['id'] === $mutation->id) continue; // jangan termasuk dirinya sendiri
                $all[$t['id']] = $t; // dedupe — bisa muncul dari toko sumber & tujuan sekaligus
            }
        }
        return array_values($all);
    }

    /**
     * Tanggal pencatatan harian (di toko sumber, bahan-bahan mutasi ini) yang
     * BELUM dikonfirmasi tapi tanggalnya <= tanggal mutasi ini — dipakai untuk
     * peringatan lunak saat konfirmasi transfer, dan sebagai "gerbang" sebelum
     * auto-fix (lihat applyBackdateAutoFix).
     */
    public static function pendingUsageDatesBefore(Mutation $mutation): array
    {
        if (!$mutation->deductsFromSource() || !$mutation->source_store_id) return [];

        $upTo   = ($mutation->delivery_date ?? $mutation->transaction_date)->toDateString();
        $ingIds = $mutation->items->pluck('ingredient_id')->unique()->all();
        if (empty($ingIds)) return [];

        return \App\Models\DailyUsage::where('store_id', $mutation->source_store_id)
            ->whereIn('ingredient_id', $ingIds)
            ->where('qty_pack', '>', 0)
            ->where('usage_date', '<=', $upTo)
            ->whereNotExists(fn($q) => $q->from('daily_confirmations')
                ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date'))
            ->distinct()->orderBy('usage_date')
            ->pluck('usage_date')
            ->map(fn($d) => $d instanceof \Carbon\Carbon ? $d->toDateString() : (string) $d)
            ->all();
    }

    /**
     * Cek kunci periode (bulan / opname / snapshot HPP) untuk mutasi ini — dipakai
     * bareng oleh unconfirm() (aksi manual) dan autoFixTransferPrice() (auto-fix).
     * null = tidak terkunci, boleh dibatalkan konfirmasinya.
     */
    public static function unconfirmLockReason(Mutation $mutation): ?string
    {
        $txDate = $mutation->transaction_date;
        if (MonthLockService::isLocked('mutation', $mutation->id, $txDate->month, $txDate->year)) {
            return MonthLockService::lockMessage($txDate->month, $txDate->year);
        }
        $lockDate = ($mutation->delivery_date ?? $mutation->transaction_date)->toDateString();
        $lc = \Carbon\Carbon::parse($lockDate);
        foreach (array_filter([$mutation->destination_store_id, $mutation->source_store_id]) as $sid) {
            if (\App\Models\Opname::isDateLocked((int) $sid, $lockDate)) {
                return \App\Models\Opname::lockMessageFor((int) $sid);
            }
            if (\App\Models\HppSnapshot::isDateLocked((int) $sid, $lockDate)) {
                return \App\Models\HppSnapshot::lockMessageFor((int) $sid, $lc->month, $lc->year);
            }
        }
        return null;
    }

    /**
     * Balikkan efek stok mutasi ini & kembalikan ke draft — inti dari "Batalkan
     * Konfirmasi", diekstrak supaya bisa dipanggil ulang oleh auto-fix tanpa lewat
     * HTTP. TIDAK mengecek kunci — panggil unconfirmLockReason() dulu di pemanggil.
     */
    public static function doUnconfirm(Mutation $mutation): void
    {
        DB::transaction(function () use ($mutation) {
            $mutation->loadMissing('items');

            // Pasangan (toko × bahan) yang perlu dihitung ulang — dua arah, sama spt destroy()
            $pairs = [];
            foreach ($mutation->items as $item) {
                if ($mutation->source_store_id && in_array($mutation->type, ['sale_internal', 'sale_external_out'])) {
                    $pairs[$mutation->source_store_id . '-' . $item->ingredient_id] =
                        ['store_id' => $mutation->source_store_id, 'ingredient_id' => $item->ingredient_id];
                }
                if ($mutation->destination_store_id) {
                    $pairs[$mutation->destination_store_id . '-' . $item->ingredient_id] =
                        ['store_id' => $mutation->destination_store_id, 'ingredient_id' => $item->ingredient_id];
                }
            }

            // Hapus jejak ledger mutasi ini, lalu susun ulang balance_after-nya
            $ledgerPairs = StockLedger::where('reference_type', 'Mutation')
                ->where('reference_id', $mutation->id)
                ->get(['store_id', 'ingredient_id'])
                ->unique(fn($e) => $e->store_id . '-' . $e->ingredient_id)
                ->values();

            StockLedger::where('reference_type', 'Mutation')
                ->where('reference_id', $mutation->id)
                ->delete();

            foreach ($ledgerPairs as $pair) {
                $balance = 0;
                foreach (StockLedger::where('store_id', $pair->store_id)
                            ->where('ingredient_id', $pair->ingredient_id)
                            ->orderBy('movement_date')->orderBy('id')->get() as $entry) {
                    $balance += $entry->qty_change;
                    $entry->update(['balance_after' => $balance]);
                }
                StoreStock::updateOrCreate(
                    ['store_id' => $pair->store_id, 'ingredient_id' => $pair->ingredient_id],
                    ['stock_balance' => $balance]
                );
            }

            // Kembali ke draft. remaining_qty dikembalikan penuh supaya saat dikonfirmasi
            // ulang batch-nya mulai bersih (tidak membawa sisa deduksi lama).
            $mutation->items()->update(['remaining_qty' => DB::raw('total_in_base')]);
            $mutation->update(['status' => 'draft', 'confirmed_by' => null]);

            // Setelah status jadi draft, item mutasi ini tidak lagi dihitung sebagai batch
            // maupun deduksi → recalculate merapikan sisa batch toko terdampak.
            foreach ($pairs as $p) {
                FifoService::recalculate($p['store_id'], $p['ingredient_id']);
            }
        });
    }

    /**
     * Auto-fix harga transfer yang jadi stale akibat pembelian/pencatatan harian
     * backdated. Sama persis dengan Batalkan Konfirmasi lalu Konfirmasi lagi
     * secara manual — HANYA jalan kalau periode transfer ini TIDAK terkunci
     * (bulan / opname / HPP), sama seperti kalau user melakukannya sendiri.
     * true = berhasil diperbaiki, false = dilewati krn terkunci.
     */
    public static function autoFixTransferPrice(Mutation $transfer): bool
    {
        if ($transfer->status !== 'confirmed' || $transfer->type === 'opening_stock') return false;
        if (self::unconfirmLockReason($transfer) !== null) return false;

        self::doUnconfirm($transfer);
        self::confirm($transfer->fresh());
        return true;
    }

    /**
     * Jalankan auto-fix ke daftar transfer terdampak (dari confirmedTransfersAfter
     * / pendingConfirmedTransfersAfterUnconfirm), dipanggil SETELAH pemicunya
     * sendiri (konfirmasi ATAU batalkan-konfirmasi pembelian/pencatatan harian/
     * waste) selesai. Satu fungsi dipakai oleh SEMUA jalur pemicu supaya
     * perilakunya tidak bisa mencar/bias antar tempat.
     *
     * $checkUsageGate MURNI OPTIMASI, BUKAN soal benar/salah. Perbaikannya sendiri
     * (autoFixTransferPrice) selalu menghitung ulang dari keadaan FIFO SAAT INI —
     * dan FIFO cuma memasukkan pemakaian harian yang sudah dikonfirmasi. Jadi harga
     * hasil hitung ulang selalu konsisten dengan yang sistem percayai sekarang;
     * tidak pernah "setengah jadi". Karena itu defaultnya false (selalu perbaiki).
     *
     * true dipakai HANYA di DailyLedgerController::confirmDate cabang konfirmasi:
     * di sana user menekan tanggal 1,2,3,...,30 satu per satu, dan tanpa gerbang ini
     * transfer yang sama akan dibongkar-pasang berkali-kali (30x transaksi DB) untuk
     * hasil akhir yang sama. Gerbangnya menunda sampai tanggal terakhir yang tersisa
     * selesai dikonfirmasi, lalu memperbaiki sekali saja.
     *
     * JANGAN pakai true di jalur "batalkan konfirmasi tanggal": tanggal yang baru
     * dibatalkan itu sendiri langsung terhitung "belum dikonfirmasi", sehingga
     * gerbangnya tidak akan pernah lolos dan auto-fix tidak pernah jalan.
     *
     * @return array{fixed: string[], locked: string[]} reference_no yang berhasil
     *   diperbaiki, dan reference_no+tanggal yang dilewati karena terkunci.
     */
    public static function applyBackdateAutoFix(array $affected, bool $checkUsageGate = false): array
    {
        $fixed = []; $locked = [];
        foreach ($affected as $a) {
            $transfer = Mutation::find($a['id']);
            if (!$transfer) continue;
            $transfer->loadMissing('items');

            if ($checkUsageGate && !empty(self::pendingUsageDatesBefore($transfer))) continue;

            if (self::autoFixTransferPrice($transfer)) {
                $fixed[] = $a['reference_no'];
            } else {
                $locked[] = $a['reference_no'] . ' (' . \Carbon\Carbon::parse($a['date'])->isoFormat('D MMM Y') . ')';
            }
        }
        return ['fixed' => $fixed, 'locked' => $locked];
    }

    /**
     * Tempel ringkasan auto-fix ke pesan sukses (redirect biasa, bukan JSON) &
     * flash daftar yang terkunci — dipakai bareng oleh SEMUA controller yang
     * memicu applyBackdateAutoFix lewat redirect (Mutasi, Waste). Ditangkap oleh
     * banner global di layouts/app.blade.php lewat session('backdate_locked').
     */
    public static function withBackdateFixMessage($redirect, string $baseMsg, array $result)
    {
        $msg = $baseMsg;
        if ($result['fixed']) {
            $msg .= ' Harga ' . count($result['fixed']) . ' transfer ikut disegarkan otomatis ('
                  . implode(', ', $result['fixed']) . ').';
        }
        $redirect = $redirect->with('success', $msg);
        if ($result['locked']) {
            $redirect = $redirect->with('backdate_locked', $result['locked']);
        }
        return $redirect;
    }
}
