<?php
namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\{StoreStock, Store, Ingredient, IngredientCategory, MutationItem, DailyUsage, Opname, OpnameItem};
use Illuminate\Http\Request;

class StockController extends Controller
{
    const DOS_WINDOW_DAYS = 30;

    public function index(Request $request)
    {
        $storeIds    = auth()->user()->accessibleStoreIds();
        $stores      = auth()->user()->accessibleStores();
        $selectedId  = $request->store_id ?? ($storeIds[0] ?? null);

        // Urutan & label kategori dari DB
        $categoryOrder  = IngredientCategory::orderedNames();
        $categoryLabels = IngredientCategory::labelsMap();
        $categoryLabels['lainnya']       = 'Lainnya';
        $categoryLabels['semi_finished'] = 'Semi Finished';

        // ── Load ingredients aktif dengan packaging ────────────────────────────
        $ingredients = Ingredient::with([
                'packagings' => fn($q) => $q->where('is_active', true)->orderBy('id'),
            ])
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        // ── Konfigurasi order toko ────────────────────────────────────────────
        $selectedStore  = Store::find($selectedId);
        $leadTimeDays   = $selectedStore?->leadTimeDays();   // reorder point
        $orderCycleDays = $selectedStore?->orderCycleDays(); // siklus order
        $dosWindowDays  = $selectedStore?->dosWindowDays() ?? 30; // window hitung rata-rata
        $parLevelDays   = $leadTimeDays; // backward compat alias

        // ── Load saldo untuk toko terpilih ────────────────────────────────────
        $storeStockMap = StoreStock::where('store_id', $selectedId)
            ->get(['ingredient_id', 'stock_balance', 'min_stock_base'])
            ->keyBy('ingredient_id');

        // ── Load semua batch aktif (remaining_qty > 0) ─────────────────────────
        $batchesMap = MutationItem::whereHas('mutation', fn($q) =>
                $q->where('destination_store_id', $selectedId)
                  ->where('status', 'confirmed')
                  ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'opening_stock', 'sale_internal', 'sale_external'])
            )
            ->where('remaining_qty', '>', 0)
            ->orderBy('id')
            ->get(['id', 'ingredient_id', 'packaging_id', 'price_per_base', 'remaining_qty'])
            ->groupBy('ingredient_id');

        // ── Eceran (pcs/gr) dari opname approved TERAKHIR — untuk DIABAIKAN di Saldo Stok ──
        // Saldo Stok hanya memantau Dus+Pack UTUH; eceran (pack terbuka) tidak dihitung.
        // INI DISPLAY-ONLY: saldo FIFO & HPP TIDAK diubah (HPP tetap hitung eceran). Mengubah
        // FIFO akan mencemari system_qty/variance opname berikutnya — jadi cukup dikurangi di
        // tampilan saja. key = "ingredient_id-packaging_id".
        $lastOpnameId = Opname::where('store_id', $selectedId)->where('status', 'approved')
            ->orderByDesc('opname_date')->orderByDesc('id')->value('id');
        $looseMap = $lastOpnameId
            ? OpnameItem::where('opname_id', $lastOpnameId)
                ->whereNotNull('packaging_id')->where('physical_base', '>', 0)
                ->get(['ingredient_id', 'packaging_id', 'physical_base'])
                ->mapWithKeys(fn($i) => [$i->ingredient_id . '-' . $i->packaging_id => (float) $i->physical_base])
                ->all()
            : [];

        // ── Days of Supply: anchor di tanggal terakhir dikonfirmasi ─────────
        // Window N hari MUNDUR dari last_confirmed_date, bukan dari hari ini.
        // Supaya pembagi (window size) cover periode yang DATANYA SUDAH VALID
        // — tidak ditarik turun oleh hari yang belum dikonfirmasi.
        $lastConfirmed = \App\Models\DailyConfirmation::where('store_id', $selectedId)
            ->orderBy('confirmation_date', 'desc')
            ->first()?->confirmation_date;

        $usageSums = collect();
        if ($lastConfirmed) {
            $dosTo   = $lastConfirmed->toDateString();
            $dosFrom = $lastConfirmed->copy()->subDays($dosWindowDays - 1)->toDateString();

            $usageSums = DailyUsage::where('store_id', $selectedId)
                ->whereBetween('usage_date', [$dosFrom, $dosTo])
                ->where('qty_pack', '>', 0)
                ->whereExists(fn($q) => $q
                    ->from('daily_confirmations')
                    ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                    ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date')
                )
                ->groupBy('ingredient_id')
                ->selectRaw('ingredient_id, SUM(qty_pack) as total_pack, COUNT(DISTINCT usage_date) as active_days')
                ->get()
                ->keyBy('ingredient_id');
        }

        // ── Bangun data: 1 BARIS per (ingredient × packaging) ─────────────────
        // Filosofi: SEMUA packaging master dari setiap bahan tampil sebagai baris
        // sendiri, terlepas ada stok atau tidak. Kemasan yang kosong tetap muncul
        // supaya user tahu kemasan apa saja yang tersedia.
        // ── Saldo bertanda per (ingredient × packaging): received − demand ─────
        // Dipakai HANYA untuk MENAMPILKAN saldo MINUS bila sebuah kemasan dipakai
        // melebihi stoknya (pemotongan tidak meluber ke kemasan lain). Saldo positif
        // tetap memakai FIFO remaining (akurat, termasuk opname). demand = pemakaian
        // terkonfirmasi + waste + penjualan keluar, semuanya PER kemasan.
        $pkgConv = []; // pkgId => ['ptb' => ..., 'ctb' => ...]
        foreach ($ingredients as $ingX) {
            foreach ($ingX->packagings as $p) {
                $pkgConv[$p->id] = ['ptb' => (float)$p->pack_to_base,
                                    'ctb' => (float)$p->crate_to_pack * (float)$p->pack_to_base];
            }
        }
        $kkey = fn($i, $p) => $i . '-' . ($p ?: 0);

        $receivedMap = [];
        foreach (MutationItem::whereHas('mutation', fn($q) =>
                    $q->where('destination_store_id', $selectedId)->where('status', 'confirmed'))
                ->selectRaw('ingredient_id, packaging_id, SUM(total_in_base) as t')
                ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
            $receivedMap[$kkey($r->ingredient_id, $r->packaging_id)] = (float) $r->t;
        }

        $demandMap = [];
        // penjualan keluar
        foreach (MutationItem::whereHas('mutation', fn($q) =>
                    $q->where('source_store_id', $selectedId)->where('status', 'confirmed')
                      ->whereIn('type', ['sale_internal', 'sale_external_out']))
                ->selectRaw('ingredient_id, packaging_id, SUM(total_in_base) as t')
                ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
            $k = $kkey($r->ingredient_id, $r->packaging_id);
            $demandMap[$k] = ($demandMap[$k] ?? 0) + (float) $r->t;
        }
        // pemakaian harian terkonfirmasi (qty_pack × ptb)
        foreach (DailyUsage::where('store_id', $selectedId)->where('qty_pack', '>', 0)
                ->whereExists(fn($q) => $q->from('daily_confirmations')
                    ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                    ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date'))
                ->selectRaw('ingredient_id, packaging_id, SUM(qty_pack) as p')
                ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
            $ptbU = ($r->packaging_id && isset($pkgConv[$r->packaging_id])) ? $pkgConv[$r->packaging_id]['ptb'] : 1;
            $k = $kkey($r->ingredient_id, $r->packaging_id);
            $demandMap[$k] = ($demandMap[$k] ?? 0) + (float) $r->p * $ptbU;
        }
        // opname: variance NEGATIF (kekurangan fisik) per kemasan = ikut mengurangi stok
        foreach (\App\Models\OpnameItem::query()
                ->join('opnames', 'opnames.id', '=', 'opname_items.opname_id')
                ->where('opnames.store_id', $selectedId)->where('opnames.status', 'approved')
                ->where('opname_items.variance', '<', 0)
                ->selectRaw('opname_items.ingredient_id as ing, opname_items.packaging_id as pkg, SUM(opname_items.variance) as v')
                ->groupBy('opname_items.ingredient_id', 'opname_items.packaging_id')->get() as $r) {
            $k = $kkey($r->ing, $r->pkg);
            $demandMap[$k] = ($demandMap[$k] ?? 0) + abs((float) $r->v);
        }
        // waste (raw, porsi Dus+Pack)
        foreach (\App\Models\WasteLogItem::query()
                ->join('waste_logs', 'waste_logs.id', '=', 'waste_log_items.waste_log_id')
                ->where('waste_logs.store_id', $selectedId)
                ->where('waste_log_items.source_type', 'raw')
                ->selectRaw('waste_log_items.ingredient_id as ing, waste_log_items.packaging_id as pkg, SUM(waste_log_items.qty_crate) as c, SUM(waste_log_items.qty_pack) as p, SUM(waste_log_items.qty_base) as b')
                ->groupBy('waste_log_items.ingredient_id', 'waste_log_items.packaging_id')->get() as $r) {
            if ($r->pkg && isset($pkgConv[$r->pkg])) {
                $base = (float) $r->c * $pkgConv[$r->pkg]['ctb'] + (float) $r->p * $pkgConv[$r->pkg]['ptb'];
            } else {
                $base = (float) $r->b;
            }
            $k = $kkey($r->ing, $r->pkg);
            $demandMap[$k] = ($demandMap[$k] ?? 0) + $base;
        }

        $rows = collect();

        $buildRow = function ($ing, $pkg, $pkgBatches, $usageRow, $parLevelDays, $leadTimeDays, $orderCycleDays, $dosWindowDays) use ($receivedMap, $demandMap, $looseMap) {
            $ptb         = $pkg && $pkg->pack_to_base > 0 ? (float)$pkg->pack_to_base : 0;
            $crateToBase = $pkg ? $pkg->crate_to_pack * $ptb : 0;

            // SALDO STOK = pantau Dus+Pack UTUH. Eceran (pcs/gr) dari opname TERAKHIR DIABAIKAN
            // — dikurangi dari batch TERTUA dulu (FIFO; pkgBatches sudah urut id), dari qty &
            // harga. FIFO & HPP TIDAK diubah (display-only); HPP tetap menghitung eceran.
            $k     = $ing->id . '-' . ($pkg?->id ?: 0);
            $loose = $ptb > 0 ? (float) ($looseMap[$k] ?? 0) : 0.0;
            $rem   = $loose;
            $sealedBatches = collect();
            foreach ($pkgBatches as $b) {
                $bq     = (float) $b->remaining_qty;
                $cut    = min($bq, $rem);            // buang porsi eceran dari batch ini
                $rem   -= $cut;
                $sealed = $bq - $cut;
                if ($sealed > 0) {
                    $sealedBatches->push((object) [
                        'remaining_qty'  => $sealed,
                        'price_per_base' => (float) $b->price_per_base,
                    ]);
                }
            }
            $wholeBase   = (float) $sealedBatches->sum('remaining_qty');
            $pkgAvgPrice = $wholeBase > 0
                ? $sealedBatches->sum(fn($b) => $b->remaining_qty * $b->price_per_base) / $wholeBase
                : 0;

            // Saldo bertanda: kalau dipakai melebihi stok → MINUS; selain itu pack utuh (segel).
            $signed    = ($receivedMap[$k] ?? 0) - ($demandMap[$k] ?? 0);
            $pkgBalance = $signed < -0.001 ? $signed : $wholeBase;

            // Pecah ke Dus/Pack — tangani negatif (hitung pada nilai mutlak, beri tanda)
            $neg = $pkgBalance < 0; $absBal = abs($pkgBalance);
            $dus = $pack = 0; $baseRem = $absBal;
            if ($crateToBase > 0) {
                $dus     = (int) floor($absBal / $crateToBase);
                $baseRem = $absBal - ($dus * $crateToBase);
            }
            if ($ptb > 0) {
                $pack    = (int) floor($baseRem / $ptb);
                $baseRem = $baseRem - ($pack * $ptb);
            }
            if ($neg) { $dus = -$dus; $pack = -$pack; $baseRem = -$baseRem; }

            // Price layers (untuk tooltip & nilai). Dikelompokkan per HARGA/DUS BULAT
            // (round price_per_base × ctb) — bukan price_per_base mentah 8-desimal —
            // supaya batch ber-harga-sama menyatu & tidak ada selisih desimal gajelas.
            $priceLayers = $sealedBatches
                ->groupBy(fn($b) => $crateToBase > 0
                    ? (int) round((float) $b->price_per_base * $crateToBase)
                    : (int) round((float) $b->price_per_base * ($ptb ?: 1)))
                ->map(function ($grp) use ($crateToBase, $ptb) {
                    $totalQty   = $grp->sum('remaining_qty');
                    $priceBase  = (float) $grp->first()->price_per_base;
                    // HARGA/DUS & /PACK BULAT = sumber kebenaran tampilan & nilai
                    $priceCrate = $crateToBase > 0 ? (int) round($priceBase * $crateToBase) : 0;
                    $pricePack  = $ptb > 0 ? (int) round($priceBase * $ptb) : 0;
                    $dusB = $packB = 0; $baseB = $totalQty;
                    if ($crateToBase > 0) {
                        $dusB  = (int) floor($totalQty / $crateToBase);
                        $baseB = $totalQty - ($dusB * $crateToBase);
                    }
                    if ($ptb > 0) {
                        $packB = (int) floor($baseB / $ptb);
                        $baseB = $baseB - ($packB * $ptb);
                    }
                    return (object) [
                        'remaining_qty'   => $totalQty,
                        'dus'             => $dusB,
                        'pack'            => $packB,
                        'base'            => round($baseB, 2),
                        'price_per_base'  => $priceBase,
                        'price_per_pack'  => $pricePack,
                        'price_per_crate' => $priceCrate,
                    ];
                })
                // Hanya batch yang punya Dus/Pack utuh — sisa < 1 pack tidak ditampilkan.
                ->filter(fn($b) => $b->dus != 0 || $b->pack != 0)
                ->values();

            // Nilai Rp = (dus + pack) × harga rata-rata per-base, dibulatkan sekali.
            // (Konsisten dgn valuasi inventaris standar qty × harga rata-rata.)
            $dusPackBase = (abs($dus) * $crateToBase) + (abs($pack) * $ptb);
            $pkgSubtotal = ($neg ? -1 : 1) * $dusPackBase * $pkgAvgPrice;

            $activeDays   = $usageRow?->active_days ?? 0;
            // Rata² pemakaian = total pack ÷ WINDOW SIZE (hari kalender),
            // bukan hari aktif. Supaya konsisten dgn lead_time & order_cycle
            // yang dihitung dalam hari kalender.
            $avgDailyPack = ($usageRow && $usageRow->total_pack > 0)
                ? $usageRow->total_pack / $dosWindowDays
                : null;
            $avgDailyBase = ($avgDailyPack !== null && $ptb > 0) ? $avgDailyPack * $ptb : null;
            $dosValue     = ($avgDailyBase && $avgDailyBase > 0.001) ? $pkgBalance / $avgDailyBase : null;
            $parLevelPack = ($avgDailyPack !== null && $parLevelDays) ? $avgDailyPack * $parLevelDays : null;
            $dosStatus    = (new StoreStock())->dosStatus($dosValue, $leadTimeDays, $orderCycleDays);

            return (object) [
                'ingredient'      => $ing,
                'packaging'       => $pkg,
                'packaging_id'    => $pkg?->id,
                'packaging_name'  => $pkg?->packaging_name ?? '(Belum diset)',
                'pkg_supplier'    => $pkg?->supplier?->name,
                'crate_to_pack'   => $pkg?->crate_to_pack,
                'ptb'             => $ptb,
                'crateToBase'     => $crateToBase,
                'balance'         => $pkgBalance,
                'dus'             => $dus,
                'pack'            => $pack,
                'baseRem'         => round($baseRem, 2),
                'subtotal'        => round($pkgSubtotal, 0),
                'avgPrice'        => $pkgAvgPrice,
                // round() supaya angka kembali persis ke input user setelah konversi base ↔ dus.
                'pricePerPack'    => $ptb > 0 ? (int) round($pkgAvgPrice * $ptb) : 0,
                'pricePerDus'     => $crateToBase > 0 ? (int) round($pkgAvgPrice * $crateToBase) : 0,
                'priceLayers'     => $priceLayers,
                'hasMultiPrices'  => $priceLayers->count() > 1,
                'avgDailyPack'    => $avgDailyPack,
                'activeDays'      => $activeDays,
                'dosValue'        => $dosValue !== null ? round($dosValue, 1) : null,
                'parLevelPack'    => $parLevelPack,
                'dosStatus'       => $dosStatus,
            ];
        };

        foreach ($ingredients as $ing) {
            $batches    = $batchesMap[$ing->id] ?? collect();
            $packagings = $ing->packagings; // sudah difilter is_active=true di query ingredients
            $usageRow   = $usageSums[$ing->id] ?? null;

            if ($packagings->isEmpty()) {
                // Bahan tanpa kemasan master aktif → 1 row dengan packaging null
                $rows->push($buildRow($ing, null, $batches, $usageRow, $parLevelDays, $leadTimeDays, $orderCycleDays, $dosWindowDays));
                continue;
            }

            $defaultPkgId = $packagings->first()->id;

            // Iterate SEMUA packaging AKTIF — termasuk yang stok 0
            // (kontrol tampil/sembunyi sekarang via toggle is_active di master kemasan)
            foreach ($packagings as $pkg) {
                $pkgBatches = $batches->filter(function ($b) use ($pkg, $defaultPkgId) {
                    if ($b->packaging_id) return $b->packaging_id == $pkg->id;
                    return $pkg->id == $defaultPkgId; // NULL fallback ke default
                });

                $rows->push($buildRow($ing, $pkg, $pkgBatches, $usageRow, $parLevelDays, $leadTimeDays, $orderCycleDays, $dosWindowDays));
            }
        }

        // Group by category
        $grouped = collect();
        foreach ($categoryOrder as $cat) {
            $items = $rows->filter(fn($r) => $r->ingredient->type === 'raw' && $r->ingredient->category === $cat);
            if ($items->isNotEmpty()) $grouped[$cat] = $items->values();
        }
        $nocat = $rows->filter(fn($r) => $r->ingredient->type === 'raw' && !$r->ingredient->category);
        if ($nocat->isNotEmpty()) $grouped['lainnya'] = $nocat->values();

        return view('inventory.stocks.index', compact(
            'grouped', 'categoryLabels', 'stores', 'selectedId',
            'selectedStore', 'parLevelDays', 'leadTimeDays', 'orderCycleDays', 'dosWindowDays'
        ));
    }

    // ── Set konfigurasi order toko ────────────────────────────────────────────
    public function setStorePar(Request $request)
    {
        $request->validate([
            'store_id'        => 'required|exists:stores,id',
            'lead_time_days'  => 'required|integer|min:1|max:30',
            'order_cycle_days'=> 'required|integer|min:1|max:90',
            'dos_window_days' => 'required|integer|in:7,14,30',
        ]);

        abort_unless(in_array($request->store_id, auth()->user()->accessibleStoreIds()), 403);

        Store::where('id', $request->store_id)->update([
            'lead_time_days'   => $request->lead_time_days,
            'order_cycle_days' => $request->order_cycle_days,
            'dos_window_days'  => $request->dos_window_days,
        ]);

        return response()->json([
            'ok'               => true,
            'lead_time_days'   => $request->lead_time_days,
            'order_cycle_days' => $request->order_cycle_days,
            'dos_window_days'  => $request->dos_window_days,
        ]);
    }

    // ── Legacy: set min stok manual ───────────────────────────────────────────
    public function setMin(Request $request)
    {
        $request->validate([
            'store_id'       => 'required|exists:stores,id',
            'ingredient_id'  => 'required|exists:ingredients,id',
            'min_stock_base' => 'required|numeric|min:0',
        ]);
        StoreStock::updateOrCreate(
            ['store_id' => $request->store_id, 'ingredient_id' => $request->ingredient_id],
            ['min_stock_base' => $request->min_stock_base]
        );
        return back()->with('success', 'Batas minimum stok disimpan.');
    }
}
