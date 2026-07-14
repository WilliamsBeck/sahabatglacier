<?php
namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\{DailyUsage, DailyConfirmation, Ingredient, IngredientCategory, MutationItem, Opname, Store, WasteLogItem};
use App\Services\FifoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class DailyLedgerController extends Controller
{
    public function index(Request $request)
    {
        $stores   = auth()->user()->accessibleStores();
        $storeIds = auth()->user()->accessibleStoreIds();

        if (!$request->filled('store_id')) {
            return view('inventory.daily-ledger.index', ['stores' => $stores, 'tableData' => false]);
        }

        $storeId = (int)$request->store_id;
        abort_unless(in_array($storeId, $storeIds), 403);

        $month       = $request->filled('month') ? (int)$request->month : (int)now()->month;
        $year        = $request->filled('year')  ? (int)$request->year  : (int)now()->year;
        $daysInMonth = Carbon::create($year, $month)->daysInMonth;
        $startDate   = Carbon::create($year, $month, 1)->toDateString();
        $endDate     = Carbon::create($year, $month, $daysInMonth)->toDateString();
        $store       = Store::find($storeId);

        // ── Stok Awal: prioritas opname end_month bulan sebelumnya ──
        $prevMonth      = Carbon::create($year, $month, 1)->subMonth();
        $prevOpname     = Opname::with('items.packaging')
            ->where('store_id', $storeId)
            ->where('period_month', $prevMonth->month)
            ->where('period_year',  $prevMonth->year)
            ->where('period_type',  'end_month')
            ->where('status',       'approved')
            ->first();

        // Stok awal HANYA hitung dus+pack (abaikan sisa loose pcs/gram) — konsisten dgn
        // opening_stock mutation & halaman Saldo Stok. Fallback ke physical_qty bila
        // item tidak punya breakdown crate/pack (mis. bahan tanpa kemasan).
        $opnamePackedBase = function ($item) {
            $pkg         = $item->packaging;
            $crateToBase = $pkg ? (float)$pkg->crate_to_pack * (float)$pkg->pack_to_base : 0;
            $ptb         = $pkg ? (float)$pkg->pack_to_base : 0;
            $physCrate   = (int)($item->physical_crate ?? 0);
            $physPack    = (int)($item->physical_pack  ?? 0);
            $qty = ($crateToBase > 0 ? $physCrate * $crateToBase : 0)
                 + ($ptb > 0        ? $physPack  * $ptb         : 0);
            // Fallback ke physical_qty HANYA untuk bahan tanpa kemasan. Bila ada
            // kemasan tapi dus+pack = 0 (hanya pcs/gr longgar) → 0 (loose diabaikan).
            if ($qty <= 0 && !$pkg) $qty = round((float)$item->physical_qty, 4);
            return (float)$qty;
        };

        // base packed per ingredient dari opname bulan lalu (keyed by ingredient_id).
        // SUM per ingredient_id agar bahan dengan >1 kemasan (2 baris opname) tetap benar.
        $opnameOpeningMap = $prevOpname
            ? $prevOpname->items
                ->groupBy('ingredient_id')
                ->map(fn($grp) => $grp->sum(fn($i) => $opnamePackedBase($i)))
                ->all()
            : [];

        // ── Stok Awal fallback: opening_stock mutations dalam bulan ini ──
        $openingItems = MutationItem::with('mutation:id,transaction_date,type')
            ->whereHas('mutation', fn($q) => $q
                ->where('destination_store_id', $storeId)
                ->where('status', 'confirmed')
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->where('type', 'opening_stock')
            )->get();

        // ── Pembelian: masuk ke toko ini ───────────────────────────
        // Gunakan COALESCE(delivery_date, transaction_date) sebagai tanggal pengakuan stok.
        // Untuk pembelian (zhisheng/supplier), stok diakui saat diterima (delivery_date).
        // Untuk transfer/sale masuk, sama — pakai delivery_date jika ada.
        $purchaseItems = MutationItem::with('mutation:id,transaction_date,delivery_date,type')
            ->whereHas('mutation', fn($q) => $q
                ->where('destination_store_id', $storeId)
                ->where('status', 'confirmed')
                ->whereBetween(\DB::raw('COALESCE(delivery_date, transaction_date)'), [$startDate, $endDate])
                ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'sale_internal', 'sale_external'])
            )->get();

        // ── Waste: bahan yang dibuang dari toko ini ────────────────
        $wasteItems = WasteLogItem::with('wasteLog:id,waste_date,store_id')
            ->whereHas('wasteLog', fn($q) => $q
                ->where('store_id', $storeId)
                ->whereBetween('waste_date', [$startDate, $endDate])
            )->get();

        // ── Penjualan/transfer: keluar dari toko ini ───────────────
        $saleItems = MutationItem::with('mutation:id,transaction_date,delivery_date,type')
            ->whereHas('mutation', fn($q) => $q
                ->where('source_store_id', $storeId)
                ->where('status', 'confirmed')
                ->whereBetween(\DB::raw('COALESCE(delivery_date, transaction_date)'), [$startDate, $endDate])
                ->whereIn('type', ['sale_internal', 'sale_external_out'])
            )->get();

        // ── Daily usages (manual input) ────────────────────────────
        $usageRows = DailyUsage::where('store_id', $storeId)
            ->whereBetween('usage_date', [$startDate, $endDate])
            ->get();

        // usageMap[ingId][pkgId|'null'][day] = qty_pack
        $usageMap = [];
        foreach ($usageRows as $u) {
            $pkgKey = $u->packaging_id ?? 'null';
            $usageMap[$u->ingredient_id][$pkgKey][(int)$u->usage_date->format('j')] = (float)$u->qty_pack;
        }

        // ── Carry-over: hitung saldo akhir bulan lalu jika tidak ada opname ──
        // Untuk setiap bahan dengan history apapun sebelum bulan ini, hitung:
        //   stok_in (semua mutasi masuk) - stok_out (semua mutasi keluar) - usage_base
        // Hasilnya = stok awal bulan ini secara teoritis.
        $carryOverMap = [];
        if (empty($opnameOpeningMap)) {
            // Mutations masuk sebelum bulan ini
            // Pakai COALESCE(delivery_date, transaction_date) sebagai tanggal pengakuan stok
            $prevIn = \DB::table('mutation_items as mi')
                ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
                ->where('m.destination_store_id', $storeId)
                ->where('m.status', 'confirmed')
                ->where(\DB::raw('COALESCE(m.delivery_date, m.transaction_date)'), '<', $startDate)
                ->select('mi.ingredient_id', \DB::raw('SUM(mi.total_in_base) as total'))
                ->groupBy('mi.ingredient_id')
                ->pluck('total', 'ingredient_id');

            // Mutations keluar sebelum bulan ini (sale/transfer)
            $prevOut = \DB::table('mutation_items as mi')
                ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
                ->where('m.source_store_id', $storeId)
                ->where('m.status', 'confirmed')
                ->where(\DB::raw('COALESCE(m.delivery_date, m.transaction_date)'), '<', $startDate)
                ->whereIn('m.type', ['sale_internal','sale_external_out'])
                ->select('mi.ingredient_id', \DB::raw('SUM(mi.total_in_base) as total'))
                ->groupBy('mi.ingredient_id')
                ->pluck('total', 'ingredient_id');

            // Pemakaian sebelum bulan ini (qty_pack → base via packaging).
            // PENTING: pakai subquery untuk ambil 1 packaging saja per ingredient,
            // supaya tidak duplikat kalau bahan punya >1 packaging aktif.
            $prevUsageRows = \DB::table('daily_usages as du')
                ->leftJoinSub(
                    \DB::table('ingredient_packagings')
                        ->select('ingredient_id', \DB::raw('MIN(pack_to_base) as pack_to_base'))
                        ->where('is_active', 1)
                        ->groupBy('ingredient_id'),
                    'p',
                    'p.ingredient_id', '=', 'du.ingredient_id'
                )
                ->where('du.store_id', $storeId)
                ->where('du.usage_date', '<', $startDate)
                ->select(
                    'du.ingredient_id',
                    \DB::raw('SUM(du.qty_pack * COALESCE(p.pack_to_base, 1)) as total_base')
                )
                ->groupBy('du.ingredient_id')
                ->pluck('total_base', 'ingredient_id');

            $allCarryIds = collect($prevIn->keys())
                ->merge($prevOut->keys())
                ->merge($prevUsageRows->keys())
                ->unique();

            foreach ($allCarryIds as $iid) {
                $bal = (float)($prevIn[$iid] ?? 0) - (float)($prevOut[$iid] ?? 0) - (float)($prevUsageRows[$iid] ?? 0);
                if ($bal > 0.001) {
                    $carryOverMap[$iid] = $bal;
                }
            }
        }

        // ── Collect all ingredient IDs ─────────────────────────────
        $ingIds = collect(array_keys($opnameOpeningMap))   // dari opname bulan lalu
            ->merge(array_keys($carryOverMap))             // dari saldo akhir bulan lalu
            ->merge($openingItems->pluck('ingredient_id')) // dari opening_stock mutation
            ->merge($purchaseItems->pluck('ingredient_id'))
            ->merge($saleItems->pluck('ingredient_id'))
            ->merge($wasteItems->pluck('ingredient_id'))   // dari waste
            ->merge(collect($usageMap)->keys())
            ->unique()->values();

        if ($ingIds->isEmpty()) {
            return view('inventory.daily-ledger.index', [
                'stores' => $stores, 'store' => $store,
                'month' => $month, 'year' => $year, 'daysInMonth' => $daysInMonth,
                'tableData' => [], 'ingredients' => collect(), 'activeDays' => [], 'storeId' => $storeId,
            ]);
        }

        // ── Load ingredients: urut kategori → urutan input (id) ────
        $catSort     = IngredientCategory::pluck('sort_order', 'name')->toArray();
        $userOrder   = DB::table('user_ingredient_orders')
            ->where('user_id', auth()->id())
            ->pluck('sort_order', 'ingredient_id')
            ->toArray();

        $ingredients = Ingredient::with(['packagings' => fn($q) => $q->where('is_active', true)->orderBy('id')])
            ->whereIn('id', $ingIds)
            ->where('type', '!=', 'semi_finished')
            ->get()
            ->sort(function ($a, $b) use ($catSort, $userOrder) {
                // Prioritas 1: urutan custom user (kalau ada)
                $ua = $userOrder[$a->id] ?? null;
                $ub = $userOrder[$b->id] ?? null;
                if ($ua !== null && $ub !== null) return $ua <=> $ub;
                if ($ua !== null) return -1;
                if ($ub !== null) return 1;
                // Prioritas 2 (default): kategori (sort_order) → urutan input (id)
                $ai = $catSort[$a->category] ?? 9999;
                $bi = $catSort[$b->category] ?? 9999;
                return $ai !== $bi ? $ai <=> $bi : $a->id <=> $b->id;
            })
            ->values()
            ->keyBy('id');

        // ── Precompute default packaging per ingredient ────────────
        // Dipakai sebagai fallback untuk mutation_items tanpa packaging_id
        $defaultPkgByIng = [];
        foreach ($ingredients as $ingId => $ing) {
            $defaultPkgByIng[$ingId] = $ing->packagings->first()?->id;
        }

        // ── Build data skeleton ────────────────────────────────────
        // tableData[ingId] = satu entry per ingredient (mutasi & opening)
        // tableRows = flat list per (ingId × pkgId) untuk tampilan per kemasan
        //
        // Untuk zhisheng/supplier/int_in/int_out: array [pkgKey => base_qty]
        // pkgKey = string(packaging_id) atau string(defaultPkgId) jika null
        $tableData = [];
        $tableRows = []; // [{ing_id, pkg_id, packaging, is_first, opening_base, days:[...]}, ...]

        // ── Stok awal PER (bahan × kemasan) ──────────────────────────
        $opnameOpeningByPkg = [];
        if ($prevOpname) {
            foreach ($prevOpname->items as $it) {
                $k = $it->ingredient_id . '-' . ($it->packaging_id ?: 0);
                $opnameOpeningByPkg[$k] = ($opnameOpeningByPkg[$k] ?? 0) + $opnamePackedBase($it);
            }
        }
        $openingItemsByPkg = [];
        foreach ($openingItems as $it) {
            $k = $it->ingredient_id . '-' . ($it->packaging_id ?: 0);
            $openingItemsByPkg[$k] = ($openingItemsByPkg[$k] ?? 0) + (float) $it->total_in_base;
        }
        // Opening per baris: opname (per kemasan) → carryover (per bahan, di baris pertama) → opening_stock (per kemasan)
        $openingFor = function ($ingId, $pid, $isFirst) use ($prevOpname, $opnameOpeningByPkg, $carryOverMap, $openingItemsByPkg) {
            $k = $ingId . '-' . ($pid ?: 0);
            if ($prevOpname)            return (float) ($opnameOpeningByPkg[$k] ?? 0);
            if (!empty($carryOverMap))  return $isFirst ? (float) ($carryOverMap[$ingId] ?? 0) : 0.0;
            return (float) ($openingItemsByPkg[$k] ?? 0);
        };

        foreach ($ingredients->keys() as $ingId) {
            $ing      = $ingredients[$ingId];
            $pkgs     = $ing->packagings; // sudah filter is_active=true

            // Skeleton per ingredient (untuk mutasi & opening)
            $row = ['opening_base' => 0.0, 'days' => []];
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $row['days'][$d] = [
                    'zhisheng' => [], 'supplier' => [],
                    'int_in'   => [], 'int_out'  => [],
                    'waste'    => [],
                ];
            }
            $tableData[$ingId] = $row;

            // Baris per kemasan aktif (pemakaian)
            if ($pkgs->isEmpty()) {
                // Tidak ada kemasan — 1 baris dengan pkg_id = null
                $pkgKey = 'null';
                $days   = [];
                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $days[$d] = ['pemakaian' => $usageMap[$ingId][$pkgKey][$d] ?? 0.0];
                }
                $tableRows[] = [
                    'ing_id'       => $ingId,
                    'pkg_id'       => null,
                    'packaging'    => null,
                    'is_first'     => true,
                    'opening_base' => $openingFor($ingId, null, true),
                    'days'         => $days,
                ];
            } else {
                foreach ($pkgs as $i => $pkg) {
                    $pkgKey = (string) $pkg->id;
                    $days   = [];
                    for ($d = 1; $d <= $daysInMonth; $d++) {
                        $days[$d] = ['pemakaian' => $usageMap[$ingId][$pkgKey][$d] ?? 0.0];
                    }
                    $tableRows[] = [
                        'ing_id'       => $ingId,
                        'pkg_id'       => $pkg->id,
                        'packaging'    => $pkg,
                        'is_first'     => $i === 0,
                        'opening_base' => $openingFor($ingId, $pkg->id, $i === 0),
                        'days'         => $days,
                    ];
                }
            }
        }

        // ── Isi stok awal ──────────────────────────────────────────
        // Prioritas 1: physical_qty dari opname end_month bulan sebelumnya
        foreach ($opnameOpeningMap as $ingId => $physQty) {
            if (isset($tableData[$ingId])) {
                $tableData[$ingId]['opening_base'] = $physQty;
            }
        }
        // Prioritas 2: carry-over dari saldo akhir bulan lalu (hitung otomatis)
        if (empty($opnameOpeningMap)) {
            foreach ($carryOverMap as $ingId => $bal) {
                if (isset($tableData[$ingId])) {
                    $tableData[$ingId]['opening_base'] = $bal;
                }
            }
        }
        // Prioritas 3 (fallback): opening_stock mutation dalam bulan ini
        // — hanya dipakai jika tidak ada opname & tidak ada carry-over
        if (empty($opnameOpeningMap) && empty($carryOverMap)) {
            foreach ($openingItems as $item) {
                $ingId = $item->ingredient_id;
                if (isset($tableData[$ingId])) {
                    $tableData[$ingId]['opening_base'] += (float)$item->total_in_base;
                }
            }
        }

        // ── Isi pembelian per hari (per packaging) ─────────────────
        foreach ($purchaseItems as $item) {
            $ingId  = $item->ingredient_id;
            if (!isset($tableData[$ingId])) continue;
            // Pakai delivery_date jika ada (tanggal stok diakui), fallback ke transaction_date
            $day    = (int)(($item->mutation->delivery_date ?? $item->mutation->transaction_date)->format('j'));
            $mt     = $item->mutation->type;
            $qty    = (float)$item->total_in_base;
            // NULL packaging_id → fallback ke default packaging ingredient ini
            $pkgKey = (string)($item->packaging_id ?? $defaultPkgByIng[$ingId] ?? 'null');

            if ($mt === 'purchase_zhisheng') {
                $tableData[$ingId]['days'][$day]['zhisheng'][$pkgKey] =
                    ($tableData[$ingId]['days'][$day]['zhisheng'][$pkgKey] ?? 0) + $qty;
            } elseif ($mt === 'purchase_supplier') {
                $tableData[$ingId]['days'][$day]['supplier'][$pkgKey] =
                    ($tableData[$ingId]['days'][$day]['supplier'][$pkgKey] ?? 0) + $qty;
            } else {
                $tableData[$ingId]['days'][$day]['int_in'][$pkgKey] =
                    ($tableData[$ingId]['days'][$day]['int_in'][$pkgKey] ?? 0) + $qty;
            }
        }

        // ── Isi penjualan per hari (per packaging) ─────────────────
        foreach ($saleItems as $item) {
            $ingId  = $item->ingredient_id;
            if (!isset($tableData[$ingId])) continue;
            // Tanggal pengakuan stok keluar = delivery_date (fallback transaction_date),
            // konsisten dengan sisi pembelian & dengan FIFO/StockLedger.
            $day    = (int)(($item->mutation->delivery_date ?? $item->mutation->transaction_date)->format('j'));
            $pkgKey = (string)($item->packaging_id ?? $defaultPkgByIng[$ingId] ?? 'null');
            $tableData[$ingId]['days'][$day]['int_out'][$pkgKey] =
                ($tableData[$ingId]['days'][$day]['int_out'][$pkgKey] ?? 0) + (float)$item->total_in_base;
        }

        // ── Isi waste per hari (per packaging) ─────────────────────
        foreach ($wasteItems as $item) {
            $ingId  = $item->ingredient_id;
            if (!isset($tableData[$ingId])) continue;
            $day    = (int)$item->wasteLog->waste_date->format('j');
            $pkgKey = (string)($item->packaging_id ?? $defaultPkgByIng[$ingId] ?? 'null');
            $tableData[$ingId]['days'][$day]['waste'][$pkgKey] =
                ($tableData[$ingId]['days'][$day]['waste'][$pkgKey] ?? 0) + (float)$item->qty_base;
        }

        // ── Compute active days per section (sparse columns) ───────
        $activeDays = ['zhisheng' => [], 'supplier' => [], 'int_in' => [], 'int_out' => [], 'waste' => []];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            foreach (array_keys($activeDays) as $key) {
                foreach ($tableData as $row) {
                    if (!empty($row['days'][$d][$key]) && array_sum($row['days'][$d][$key]) > 0) {
                        $activeDays[$key][$d] = $d;
                        break;
                    }
                }
            }
        }

        // ── Tanggal yang sudah dikonfirmasi (keyed by day number) ──────────────
        $confirmedDates = DailyConfirmation::where('store_id', $storeId)
            ->whereBetween('confirmation_date', [$startDate, $endDate])
            ->pluck('confirmation_date')
            ->mapWithKeys(fn($d) => [(int)$d->format('j') => true])
            ->all();

        // ── Status lock ────────────────────────────────────────────────────────
        // Month-lock DINONAKTIFKAN: data boleh diedit kapan saja, tanpa batas waktu.
        $lastEditDay       = Carbon::create($year, $month, 1)->addMonth()->addDays(6);
        $isPastMonth       = $year < now()->year || ($year == now()->year && $month < now()->month);
        $approvedExtension = null;
        $isLocked          = false;
        $editRequest       = null;

        // ── Hitung breakdown stok AKHIR per (ingredient × packaging) ──────────
        // Untuk bulan SEKARANG: pakai FIFO state aktual dari mutation_items.remaining_qty.
        // closingBreakdown[ingId][pkgId] = ['dus' => x, 'pack' => y]
        $isCurrentMonth = ($year == now()->year && $month == now()->month);
        $closingBreakdown = [];

        // Cek opname end_month BULAN INI (yang sedang dilihat) — bukan bulan lalu.
        // Jika sudah ada opname approved, stok akhir = physical opname (frozen snapshot),
        // bukan FIFO remaining yang bisa tergeser oleh pemakaian bulan berikutnya.
        $currentMonthOpname = Opname::with('items.packaging')
            ->where('store_id', $storeId)
            ->where('period_month', $month)
            ->where('period_year', $year)
            ->where('period_type', 'end_month')
            ->where('status', 'approved')
            ->first();

        if ($currentMonthOpname) {
            foreach ($currentMonthOpname->items as $item) {
                $closingBreakdown[$item->ingredient_id][$item->packaging_id ?? 0] = $opnamePackedBase($item);
            }
        } elseif ($isCurrentMonth) {
            $batchesGrouped = \App\Models\MutationItem::whereHas('mutation', fn($q) =>
                    $q->where('destination_store_id', $storeId)
                      ->where('status', 'confirmed')
                )
                ->whereIn('ingredient_id', $ingIds)
                ->where('remaining_qty', '>', 0)
                ->get(['ingredient_id', 'packaging_id', 'remaining_qty'])
                ->groupBy('ingredient_id');

            // Saldo bertanda per (ingredient × packaging) — SAMA dengan halaman Saldo Stok:
            // FIFO remaining bila positif (akurat, termasuk opname); minus (received−demand)
            // bila kemasan dipakai melebihi stoknya. closingBreakdown menyimpan nilai BASE.
            $K = fn($i, $p) => $i . '-' . ($p ?: 0);
            $recv = []; $dem = [];
            foreach (MutationItem::whereHas('mutation', fn($q) =>
                        $q->where('destination_store_id', $storeId)->where('status', 'confirmed'))
                    ->whereIn('ingredient_id', $ingIds)
                    ->selectRaw('ingredient_id, packaging_id, SUM(total_in_base) t')
                    ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
                $recv[$K($r->ingredient_id, $r->packaging_id)] = (float) $r->t;
            }
            foreach (MutationItem::whereHas('mutation', fn($q) =>
                        $q->where('source_store_id', $storeId)->where('status', 'confirmed')
                          ->whereIn('type', ['sale_internal', 'sale_external_out']))
                    ->whereIn('ingredient_id', $ingIds)
                    ->selectRaw('ingredient_id, packaging_id, SUM(total_in_base) t')
                    ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
                $k = $K($r->ingredient_id, $r->packaging_id); $dem[$k] = ($dem[$k] ?? 0) + (float) $r->t;
            }
            $conv = [];
            foreach ($ingredients as $iX) {
                foreach ($iX->packagings as $p) {
                    $conv[$p->id] = ['ptb' => (float) $p->pack_to_base, 'ctb' => (float) $p->crate_to_pack * (float) $p->pack_to_base];
                }
            }
            foreach (DailyUsage::where('store_id', $storeId)->where('qty_pack', '>', 0)
                    ->whereIn('ingredient_id', $ingIds)
                    ->whereExists(fn($q) => $q->from('daily_confirmations')
                        ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                        ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date'))
                    ->selectRaw('ingredient_id, packaging_id, SUM(qty_pack) p')
                    ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
                $ptbU = ($r->packaging_id && isset($conv[$r->packaging_id])) ? $conv[$r->packaging_id]['ptb'] : 1;
                $k = $K($r->ingredient_id, $r->packaging_id); $dem[$k] = ($dem[$k] ?? 0) + (float) $r->p * $ptbU;
            }
            foreach (\App\Models\WasteLogItem::query()
                    ->join('waste_logs', 'waste_logs.id', '=', 'waste_log_items.waste_log_id')
                    ->where('waste_logs.store_id', $storeId)->where('waste_log_items.source_type', 'raw')
                    ->whereIn('waste_log_items.ingredient_id', $ingIds)
                    ->selectRaw('waste_log_items.ingredient_id ing, waste_log_items.packaging_id pkg, SUM(waste_log_items.qty_crate) c, SUM(waste_log_items.qty_pack) p, SUM(waste_log_items.qty_base) b')
                    ->groupBy('waste_log_items.ingredient_id', 'waste_log_items.packaging_id')->get() as $r) {
                $base = ($r->pkg && isset($conv[$r->pkg]))
                    ? ((float) $r->c * $conv[$r->pkg]['ctb'] + (float) $r->p * $conv[$r->pkg]['ptb'])
                    : (float) $r->b;
                $k = $K($r->ing, $r->pkg); $dem[$k] = ($dem[$k] ?? 0) + $base;
            }
            // opname: variance NEGATIF per kemasan = ikut mengurangi stok
            foreach (\App\Models\OpnameItem::query()
                    ->join('opnames', 'opnames.id', '=', 'opname_items.opname_id')
                    ->where('opnames.store_id', $storeId)->where('opnames.status', 'approved')
                    ->whereIn('opname_items.ingredient_id', $ingIds)
                    ->where('opname_items.variance', '<', 0)
                    ->selectRaw('opname_items.ingredient_id ing, opname_items.packaging_id pkg, SUM(opname_items.variance) v')
                    ->groupBy('opname_items.ingredient_id', 'opname_items.packaging_id')->get() as $r) {
                $k = $K($r->ing, $r->pkg); $dem[$k] = ($dem[$k] ?? 0) + abs((float) $r->v);
            }

            // Pemakaian harian DRAFT (belum dikonfirmasi): TIDAK memotong FIFO/Saldo Stok,
            // tapi ikut mengurangi "stok akhir" yang tampil di halaman pencatatan harian —
            // supaya angka import/ketik langsung terlihat memotong tanpa nunggu konfirmasi.
            $draftDem = [];
            foreach (DailyUsage::where('store_id', $storeId)->where('qty_pack', '>', 0)
                    ->whereIn('ingredient_id', $ingIds)
                    ->whereNotExists(fn($q) => $q->from('daily_confirmations')
                        ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                        ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date'))
                    ->selectRaw('ingredient_id, packaging_id, SUM(qty_pack) p')
                    ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
                $ptbU = ($r->packaging_id && isset($conv[$r->packaging_id])) ? $conv[$r->packaging_id]['ptb'] : 1;
                $draftDem[$K($r->ingredient_id, $r->packaging_id)] = (float) $r->p * $ptbU;
            }

            foreach ($ingIds as $iid) {
                $ing = $ingredients[$iid] ?? null;
                if (!$ing) continue;
                $defaultPkgId = $ing->packagings->first()?->id;
                $byPkg = ($batchesGrouped[$iid] ?? collect())->groupBy(fn($b) => $b->packaging_id ?: $defaultPkgId);
                foreach ($ing->packagings as $pkg) {
                    $fifo    = ($byPkg[$pkg->id] ?? collect())->sum('remaining_qty');
                    $draftB  = $draftDem[$K($iid, $pkg->id)] ?? 0;
                    // demand termasuk pemakaian draft supaya over-usage tampil minus di ledger
                    $signed  = ($recv[$K($iid, $pkg->id)] ?? 0) - ($dem[$K($iid, $pkg->id)] ?? 0) - $draftB;
                    // base bertanda: minus hanya bila over; selain itu FIFO remaining dikurangi pemakaian draft
                    $closingBreakdown[$iid][$pkg->id] = ($signed < -0.001) ? $signed : ((float) $fifo - $draftB);
                }
            }
        }

        return view('inventory.daily-ledger.index', compact(
            'stores', 'store', 'tableData', 'tableRows', 'ingredients',
            'month', 'year', 'daysInMonth', 'activeDays', 'storeId',
            'prevOpname', 'confirmedDates', 'closingBreakdown', 'isCurrentMonth',
            'isLocked', 'lastEditDay', 'isPastMonth', 'approvedExtension', 'editRequest'
        ));
    }

    // ── AJAX: simpan pemakaian harian ──────────────────────────────
    public function saveUsage(Request $request)
    {
        $request->validate([
            'store_id'      => 'required|exists:stores,id',
            'ingredient_id' => 'required|exists:ingredients,id',
            'packaging_id'  => 'nullable|exists:ingredient_packagings,id',
            'date'          => 'required|date',
            'qty_pack'      => 'required|numeric|min:0',
        ]);

        abort_unless(in_array($request->store_id, auth()->user()->accessibleStoreIds()), 403);

        if ($err = $this->lockError((int)$request->store_id, $request->date)) {
            return response()->json(['error' => $err], 422);
        }

        // Catatan: pemakaian BOLEH melebihi stok (over-usage diizinkan).
        // Stok akhir akan tampil MINUS sebagai penanda — tidak diblokir.

        $key = [
            'store_id'      => $request->store_id,
            'ingredient_id' => $request->ingredient_id,
            'packaging_id'  => $request->packaging_id ?: null,
            'usage_date'    => $request->date,
        ];

        if ((float)$request->qty_pack === 0.0) {
            DailyUsage::where($key)->delete();
        } else {
            DailyUsage::updateOrCreate($key, [
                'qty_pack'   => $request->qty_pack,
                'created_by' => auth()->id(),
            ]);
        }

        // Cek apakah tanggal sudah dikonfirmasi.
        // - Belum dikonfirmasi (draft) → input disimpan TAPI saldo stok belum diupdate
        // - Sudah dikonfirmasi → recalculate FIFO supaya saldo stok langsung sinkron
        $isConfirmed = DailyConfirmation::where('store_id', $request->store_id)
            ->where('confirmation_date', $request->date)
            ->exists();

        if ($isConfirmed) {
            FifoService::recalculate((int)$request->store_id, (int)$request->ingredient_id);
        }

        return response()->json([
            'ok'          => true,
            'is_confirmed' => $isConfirmed,
            'note'        => $isConfirmed
                ? 'Tersimpan & saldo stok telah diupdate.'
                : 'Tersimpan sebagai draft. Saldo stok belum berkurang — konfirmasi tanggal dulu untuk mengurangi stok.',
        ]);
    }

    // ── AJAX: hapus pemakaian BANYAK sel sekaligus (blok ala Excel) ─────────────
    // Satu request + satu transaksi + recalculate FIFO SEKALI per bahan. Ini mencegah
    // race condition yang terjadi bila tiap sel dikirim terpisah (recalc bahan yang
    // sama berjalan paralel → saldo stok jadi kacau).
    public function bulkDeleteUsage(Request $request)
    {
        $data = $request->validate([
            'store_id'              => 'required|exists:stores,id',
            'cells'                 => 'required|array|min:1',
            'cells.*.ingredient_id' => 'required|integer',
            'cells.*.packaging_id'  => 'nullable|integer',
            'cells.*.date'          => 'required|date',
        ]);

        $storeId = (int) $data['store_id'];
        abort_unless(in_array($storeId, auth()->user()->accessibleStoreIds()), 403);

        $affectedIngs = [];
        $errors       = [];
        $cleared      = 0;

        \DB::transaction(function () use ($data, $storeId, &$affectedIngs, &$errors, &$cleared) {
            foreach ($data['cells'] as $c) {
                if ($err = $this->lockError($storeId, $c['date'])) { $errors[$err] = true; continue; }
                $q = DailyUsage::where('store_id', $storeId)
                    ->where('ingredient_id', (int) $c['ingredient_id'])
                    ->where('usage_date', $c['date']);
                empty($c['packaging_id']) ? $q->whereNull('packaging_id') : $q->where('packaging_id', (int) $c['packaging_id']);
                $cleared += $q->delete();
                $affectedIngs[(int) $c['ingredient_id']] = true;
            }
        });

        // Recalculate FIFO sekali per bahan (sekuensial, tidak paralel) — aman dari race.
        foreach (array_keys($affectedIngs) as $ingId) {
            FifoService::recalculate($storeId, (int) $ingId);
        }

        return response()->json([
            'ok'      => true,
            'cleared' => $cleared,
            'errors'  => array_keys($errors),
        ]);
    }

    // ── AJAX: toggle konfirmasi per tanggal ────────────────────────────────────
    // Aturan urutan:
    //   - Konfirmasi tgl D  → semua tgl 1..D-1 dalam bulan yang sama harus sudah dikonfirmasi.
    //   - Batalkan tgl D    → tidak boleh ada tgl D+1..31 yang masih dikonfirmasi
    //                         (harus batalkan dari tgl terbesar dulu).
    // Input tetap bisa diedit kapan saja — konfirmasi hanya tanda validasi.
    public function confirmDate(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'date'     => 'required|date',
        ]);

        abort_unless(in_array($request->store_id, auth()->user()->accessibleStoreIds()), 403);

        $storeId  = (int)$request->store_id;
        $date     = \Carbon\Carbon::parse($request->date);

        if ($err = $this->lockError($storeId, $request->date)) {
            return response()->json(['error' => $err], 422);
        }
        $day      = $date->day;
        $month    = $date->month;
        $year     = $date->year;

        $existing = DailyConfirmation::where('store_id', $storeId)
            ->where('confirmation_date', $date->toDateString())
            ->first();

        if ($existing) {
            // ── Batalkan: pastikan tidak ada tgl sesudahnya yang dikonfirmasi ──
            $nextConfirmed = DailyConfirmation::where('store_id', $storeId)
                ->whereYear('confirmation_date', $year)
                ->whereMonth('confirmation_date', $month)
                ->where('confirmation_date', '>', $date->toDateString())
                ->orderBy('confirmation_date')
                ->first();

            if ($nextConfirmed) {
                $nextDay = (int)$nextConfirmed->confirmation_date->format('j');
                return response()->json([
                    'error' => "Tgl $nextDay sudah dikonfirmasi. Batalkan tgl $nextDay dulu (harus urut dari belakang)."
                ], 422);
            }

            $existing->delete();

            // Setelah batal konfirmasi → recalculate FIFO supaya pemakaian hari itu
            // dikembalikan ke saldo stok (karena tidak terkonfirmasi lagi)
            $this->recalcAffectedIngredients($storeId, $date->toDateString());

            return response()->json(['status' => 'draft']);
        }

        // ── Konfirmasi: pastikan semua tgl sebelumnya sudah dikonfirmasi ──────
        if ($day > 1) {
            $startOfMonth   = \Carbon\Carbon::create($year, $month, 1)->toDateString();
            $dayBefore      = \Carbon\Carbon::create($year, $month, $day - 1)->toDateString();
            $confirmedCount = DailyConfirmation::where('store_id', $storeId)
                ->whereBetween('confirmation_date', [$startOfMonth, $dayBefore])
                ->count();

            if ($confirmedCount < $day - 1) {
                // Cari tgl pertama yang belum dikonfirmasi
                $confirmedDays = DailyConfirmation::where('store_id', $storeId)
                    ->whereBetween('confirmation_date', [$startOfMonth, $dayBefore])
                    ->pluck('confirmation_date')
                    ->map(fn($d) => (int)$d->format('j'))
                    ->flip()->all();

                for ($d = 1; $d < $day; $d++) {
                    if (!isset($confirmedDays[$d])) {
                        return response()->json([
                            'error' => "Tgl $d belum dikonfirmasi. Konfirmasi harus urut — konfirmasi tgl $d terlebih dahulu."
                        ], 422);
                    }
                }
            }
        }

        DailyConfirmation::create([
            'store_id'          => $storeId,
            'confirmation_date' => $date->toDateString(),
            'confirmed_by'      => auth()->id(),
        ]);

        // Setelah konfirmasi → recalculate FIFO supaya pemakaian hari itu MENGURANGI saldo stok
        $this->recalcAffectedIngredients($storeId, $date->toDateString());

        return response()->json(['status' => 'confirmed']);
    }

    /**
     * Recalculate FIFO untuk semua bahan yang ada catatan pemakaian di tanggal tsb.
     * Dipanggil setelah toggle konfirmasi supaya saldo stok ikut update.
     */
    private function recalcAffectedIngredients(int $storeId, string $date): void
    {
        $ingredientIds = DailyUsage::where('store_id', $storeId)
            ->where('usage_date', $date)
            ->where('qty_pack', '>', 0)
            ->pluck('ingredient_id')
            ->unique();

        foreach ($ingredientIds as $iid) {
            FifoService::recalculate($storeId, (int)$iid);
        }
    }

    // ── Helper: cek apakah tanggal sudah terkunci ──────────────────────────────
    // Return null = boleh edit, return string = pesan error lock.
    // Super Admin selalu boleh edit tanpa batasan.
    private function lockError(int $storeId, string $date): ?string
    {
        // Periode tertutup oleh opname: tanggal <= opname approved terakhir tidak bisa diinput.
        if (Opname::isDateLocked($storeId, $date)) {
            return Opname::lockMessageFor($storeId);
        }
        // Periode tertutup oleh snapshot HPP: pencatatan harian bulan itu tidak bisa diubah.
        if (\App\Models\HppSnapshot::isDateLocked($storeId, $date)) {
            $c = \Carbon\Carbon::parse($date);
            return \App\Models\HppSnapshot::lockMessageFor($storeId, $c->month, $c->year);
        }
        return null;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // EXPORT TEMPLATE EXCEL — Pemakaian Harian per bulan
    // ═════════════════════════════════════════════════════════════════════════
    /**
     * Parse kolom A template: "ingId-pkgId" → [ingId, pkgId].
     * Format lama (hanya "ingId") tetap didukung → pakai kemasan default.
     */
    private function parseTemplateKey($raw, array $defaultPkgMap): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') return [null, null];
        if (str_contains($raw, '-')) {
            $parts = explode('-', $raw, 2);
            $i = is_numeric($parts[0]) ? (int) $parts[0] : null;
            $p = (isset($parts[1]) && is_numeric($parts[1]) && (int) $parts[1] > 0) ? (int) $parts[1] : null;
            // pkgId 0/null → fallback default kemasan bahan tsb
            if ($i !== null && $p === null) $p = $defaultPkgMap[$i] ?? null;
            return [$i, $p];
        }
        if (is_numeric($raw)) {
            $i = (int) $raw;
            return [$i, $defaultPkgMap[$i] ?? null];
        }
        return [null, null];
    }

    public function exportTemplate(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'month'    => 'required|integer|min:1|max:12',
            'year'     => 'required|integer|min:2020',
        ]);

        $storeId = (int) $request->store_id;
        abort_unless(in_array($storeId, auth()->user()->accessibleStoreIds()), 403);

        $month = (int) $request->month;
        $year  = (int) $request->year;
        $daysInMonth = Carbon::create($year, $month)->daysInMonth;
        $store = Store::findOrFail($storeId);

        $bulanID = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

        // Ambil semua bahan baku aktif — urutan sama dengan tampilan web: kategori → id input
        $catSort = IngredientCategory::pluck('sort_order', 'name')->toArray();
        $userOrder = DB::table('user_ingredient_orders')
            ->where('user_id', auth()->id())
            ->pluck('sort_order', 'ingredient_id')
            ->toArray();

        $ingredients = Ingredient::with(['packagings' => fn($q) => $q->where('is_active', true)->orderBy('id')])
            ->where('ingredients.is_active', true)
            ->where('type', '!=', 'semi_finished')
            ->get()
            ->sort(function ($a, $b) use ($catSort, $userOrder) {
                $ua = $userOrder[$a->id] ?? null;
                $ub = $userOrder[$b->id] ?? null;
                if ($ua !== null && $ub !== null) return $ua <=> $ub;
                if ($ua !== null) return -1;
                if ($ub !== null) return 1;
                $ai = $catSort[$a->category] ?? 9999;
                $bi = $catSort[$b->category] ?? 9999;
                return $ai !== $bi ? $ai <=> $bi : $a->id <=> $b->id;
            })
            ->values();

        // Ambil pemakaian existing untuk pre-fill — keyed per (bahan × kemasan × hari)
        $existing = DailyUsage::where('store_id', $storeId)
            ->whereYear('usage_date', $year)
            ->whereMonth('usage_date', $month)
            ->get()
            ->mapWithKeys(fn($u) => [$u->ingredient_id . '-' . ($u->packaging_id ?: 0) . '-' . (int)$u->usage_date->format('j') => (float)$u->qty_pack]);

        // Build spreadsheet
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pemakaian ' . $month . '-' . $year);

        // Row 1: Title
        $sheet->setCellValue('A1', 'PEMAKAIAN HARIAN — ' . $store->name . ' — ' . $bulanID[$month] . ' ' . $year);
        $sheet->mergeCells('A1:' . $this->columnLetter(3 + $daysInMonth) . '1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Row 2: Petunjuk
        $sheet->setCellValue('A2', 'PETUNJUK: Isi qty pemakaian per pack di kolom tanggal. Kosongkan = tidak ada pemakaian. JANGAN ubah kolom A (ID) & B (Nama).');
        $sheet->mergeCells('A2:' . $this->columnLetter(3 + $daysInMonth) . '2');
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Row 4: Header
        $headerRow = 4;
        $sheet->setCellValue("A{$headerRow}", 'ID');
        $sheet->setCellValue("B{$headerRow}", 'Bahan');
        $sheet->setCellValue("C{$headerRow}", 'Satuan');
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $col = $this->columnLetter(3 + $d);
            $sheet->setCellValue("{$col}{$headerRow}", $d);
        }

        // Style header
        $lastCol = $this->columnLetter(3 + $daysInMonth);
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")
            ->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Lebar kolom
        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(35);
        $sheet->getColumnDimension('C')->setWidth(10);
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $sheet->getColumnDimension($this->columnLetter(3 + $d))->setWidth(7);
        }

        // Data rows — SATU baris per (bahan × kemasan aktif). Bahan dengan >1 kemasan
        // dapat beberapa baris; ID di kolom A = "ingId-pkgId" agar impor tahu kemasannya.
        $row = $headerRow + 1;
        foreach ($ingredients as $ing) {
            $pkgs  = $ing->packagings;
            $multi = $pkgs->count() > 1;
            $variants = $pkgs->isEmpty()
                ? [[null, $ing->name]]
                : $pkgs->map(fn($p) => [
                    $p->id,
                    $ing->name . ($multi ? ' [@' . (int) $p->crate_to_pack . ' pack]' : ''),
                  ])->all();

            foreach ($variants as [$pkgId, $label]) {
                $key = $ing->id . '-' . ($pkgId ?: 0);
                $sheet->setCellValueExplicit("A{$row}", $key, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue("B{$row}", $label);
                $sheet->setCellValue("C{$row}", $ing->unit_base);

                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $pkKey = $ing->id . '-' . ($pkgId ?: 0) . '-' . $d;
                    if (isset($existing[$pkKey])) {
                        $col = $this->columnLetter(3 + $d);
                        $sheet->setCellValue("{$col}{$row}", $existing[$pkKey]);
                    }
                }

                $sheet->getStyle("A{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');

                $row++;
            }
        }

        // Freeze pane (header + 3 kolom kiri)
        $sheet->freezePane('D' . ($headerRow + 1));

        // Output
        $filename = 'Pemakaian_' . str_replace(' ', '_', $store->name) . '_' . $bulanID[$month] . '_' . $year . '.xlsx';

        $writer = new XlsxWriter($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // IMPORT EXCEL — Pemakaian Harian per bulan
    // ═════════════════════════════════════════════════════════════════════════
    public function importUsage(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'month'    => 'required|integer|min:1|max:12',
            'year'     => 'required|integer|min:2020',
        ]);

        $storeId = (int) $request->store_id;
        abort_unless(in_array($storeId, auth()->user()->accessibleStoreIds()), 403);

        $month = (int) $request->month;
        $year  = (int) $request->year;
        $daysInMonth = Carbon::create($year, $month)->daysInMonth;

        // ── Mode: confirm (force save) atau validate ──
        if ($request->filled('temp_file') && $request->force) {
            $tempPath = storage_path('app/import-temp/' . basename($request->temp_file));
            if (!file_exists($tempPath)) {
                return back()->with('error', 'File temporary tidak ditemukan (kemungkinan sudah dihapus). Upload ulang.');
            }
            try {
                $spreadsheet = IOFactory::load($tempPath);
            } catch (\Exception $e) {
                return back()->with('error', 'Gagal baca file: ' . $e->getMessage());
            }
            $result = $this->saveImport($spreadsheet, $storeId, $month, $year, $daysInMonth);
            @unlink($tempPath);
            session()->forget('import_preview');
            return redirect()->route('inventory.daily-ledger.index', [
                'store_id' => $storeId, 'month' => $month, 'year' => $year,
            ])->with('success', $result);
        }

        // ── Mode validate (upload baru) ──
        $request->validate(['file' => 'required|file|mimes:xlsx,xls']);
        try {
            $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal baca file: ' . $e->getMessage());
        }

        // Jalankan validasi
        $issues = $this->validateImport($spreadsheet, $storeId, $month, $year, $daysInMonth);

        if (empty($issues)) {
            // Bersih, langsung save
            $result = $this->saveImport($spreadsheet, $storeId, $month, $year, $daysInMonth);
            $alertType = str_contains($result, 'dilewati') ? 'warning' : 'success';
            return back()->with($alertType, $result);
        }

        // Ada issues — simpan file ke temp & redirect ke preview (PRG pattern)
        $tempDir = storage_path('app/import-temp');
        if (!is_dir($tempDir)) @mkdir($tempDir, 0755, true);
        $tempName = uniqid('imp_', true) . '.xlsx';
        $request->file('file')->move($tempDir, $tempName);

        session()->put('import_preview', [
            'issues'    => $issues,
            'temp_file' => $tempName,
            'store_id'  => $storeId,
            'month'     => $month,
            'year'      => $year,
        ]);

        return redirect()->route('inventory.daily-ledger.import-preview');
    }

    // ── Tampilkan halaman preview (PRG: GET route) ──
    public function importPreview()
    {
        $data = session('import_preview');
        if (!$data) {
            return redirect()->route('inventory.daily-ledger.index')
                ->with('error', 'Sesi preview sudah berakhir. Silakan upload ulang.');
        }

        $store = Store::findOrFail($data['store_id']);
        $bulanID = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

        return view('inventory.daily-ledger.import-preview', [
            'issues'    => $data['issues'],
            'temp_file' => $data['temp_file'],
            'store_id'  => $data['store_id'],
            'month'     => $data['month'],
            'year'      => $data['year'],
            'store'     => $store,
            'bulanLbl'  => $bulanID[$data['month']] . ' ' . $data['year'],
        ]);
    }

    // ── VALIDASI: cek apakah usage > stok available pada hari tersebut ──
    private function validateImport($spreadsheet, int $storeId, int $month, int $year, int $daysInMonth): array
    {
        $sheet = $spreadsheet->getActiveSheet();
        $startDate = Carbon::create($year, $month, 1)->toDateString();
        $issues = [];

        // 1. Hitung opening base per ingredient untuk bulan ini
        $opening = $this->computeOpeningPerIngredient($storeId, $startDate);

        // 2. Ambil semua mutasi IN/OUT dalam bulan ini per (ingredient, day)
        $endDate = Carbon::create($year, $month, $daysInMonth)->toDateString();

        $monthIn = \DB::table('mutation_items as mi')
            ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
            ->where('m.destination_store_id', $storeId)
            ->where('m.status', 'confirmed')
            ->whereBetween(\DB::raw('COALESCE(m.delivery_date, m.transaction_date)'), [$startDate, $endDate])
            ->select('mi.ingredient_id', \DB::raw('COALESCE(m.delivery_date, m.transaction_date) as recog_date'), \DB::raw('SUM(mi.total_in_base) as total'))
            ->groupBy('mi.ingredient_id', \DB::raw('COALESCE(m.delivery_date, m.transaction_date)'))
            ->get();

        $monthOut = \DB::table('mutation_items as mi')
            ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
            ->where('m.source_store_id', $storeId)
            ->where('m.status', 'confirmed')
            ->whereBetween(\DB::raw('COALESCE(m.delivery_date, m.transaction_date)'), [$startDate, $endDate])
            ->whereIn('m.type', ['sale_internal','sale_external_out'])
            ->select('mi.ingredient_id', \DB::raw('COALESCE(m.delivery_date, m.transaction_date) as recog_date'), \DB::raw('SUM(mi.total_in_base) as total'))
            ->groupBy('mi.ingredient_id', \DB::raw('COALESCE(m.delivery_date, m.transaction_date)'))
            ->get();

        $inMap  = []; // [ingId][day] = base in
        $outMap = []; // [ingId][day] = base out
        foreach ($monthIn as $row) {
            $d = (int) Carbon::parse($row->recog_date)->format('j');
            $inMap[$row->ingredient_id][$d] = ($inMap[$row->ingredient_id][$d] ?? 0) + (float)$row->total;
        }
        foreach ($monthOut as $row) {
            $d = (int) Carbon::parse($row->recog_date)->format('j');
            $outMap[$row->ingredient_id][$d] = ($outMap[$row->ingredient_id][$d] ?? 0) + (float)$row->total;
        }

        // 3. Load ingredients map + packaging
        $ingredientsData = Ingredient::with(['packagings' => fn($q) => $q->where('is_active', true)->orderBy('id')])
            ->where('is_active', true)
            ->get()
            ->keyBy('id');
        $defaultPkgMap = $ingredientsData->map(fn($i) => $i->packagings->first()?->id)->all();

        // 4. Agregasi pemakaian (base) per bahan per hari dari SEMUA baris (semua kemasan),
        //    karena 1 bahan kini bisa punya >1 baris (per kemasan) — stok dipakai bersama.
        $usageBaseByIngDay = [];
        $highestRow = $sheet->getHighestRow();
        for ($r = 5; $r <= $highestRow; $r++) {
            [$ingId, $pkgId] = $this->parseTemplateKey($sheet->getCell("A{$r}")->getValue(), $defaultPkgMap);
            if (!$ingId) continue;
            $ing = $ingredientsData[$ingId] ?? null;
            if (!$ing) continue;

            $pkg = $pkgId ? $ing->packagings->firstWhere('id', $pkgId) : $ing->packagings->first();
            $ptb = $pkg?->pack_to_base ?? 1;

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $col = $this->columnLetter(3 + $d);
                $val = $sheet->getCell("{$col}{$r}")->getValue();
                if (!is_numeric($val)) continue;
                $usageBaseByIngDay[$ingId][$d] = ($usageBaseByIngDay[$ingId][$d] ?? 0) + (float) $val * $ptb;
            }
        }

        // 5. Replay kronologis per bahan
        foreach ($usageBaseByIngDay as $ingId => $days) {
            $ing = $ingredientsData[$ingId] ?? null;
            if (!$ing) continue;
            $ptbMain = $ing->packagings->first()?->pack_to_base ?? 1;
            $balance = $opening[$ingId] ?? 0;

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $balance += $inMap[$ingId][$d] ?? 0;

                $usageBase = $days[$d] ?? 0;
                $outBase   = $outMap[$ingId][$d] ?? 0;
                $totalConsumed = $usageBase + $outBase;

                if ($totalConsumed > $balance + 0.001) {
                    $issues[] = [
                        'ingredient' => $ing->name,
                        'unit'       => $ing->unit_base,
                        'day'        => $d,
                        'usage_pack' => round($ptbMain > 0 ? $usageBase / $ptbMain : $usageBase, 2),
                        'avail_pack' => round($ptbMain > 0 ? $balance / $ptbMain : $balance, 2),
                        'avail_base' => round($balance, 2),
                        'usage_base' => round($usageBase, 2),
                    ];
                }

                $balance -= $totalConsumed;
            }
        }

        return $issues;
    }

    // Hitung opening base per ingredient untuk bulan tertentu (tanggal awal bulan)
    private function computeOpeningPerIngredient(int $storeId, string $startDate): array
    {
        // Cek opname end_month bulan sebelumnya
        $prev = Carbon::parse($startDate)->subMonth();
        $prevOpname = Opname::with('items.packaging')
            ->where('store_id', $storeId)
            ->where('period_month', $prev->month)
            ->where('period_year',  $prev->year)
            ->where('period_type',  'end_month')
            ->where('status',       'approved')
            ->first();

        if ($prevOpname) {
            // Stok awal HANYA dus+pack (abaikan eceran pcs/gr) — KONSISTEN dgn tampilan
            // Pencatatan Harian & Saldo Stok. Fallback physical_qty utk bahan tanpa kemasan.
            $packedBase = function ($i) {
                $pkg = $i->packaging;
                $ctb = $pkg ? (float)$pkg->crate_to_pack * (float)$pkg->pack_to_base : 0;
                $ptb = $pkg ? (float)$pkg->pack_to_base : 0;
                $qty = ($ctb > 0 ? (int)($i->physical_crate ?? 0) * $ctb : 0)
                     + ($ptb > 0 ? (int)($i->physical_pack  ?? 0) * $ptb : 0);
                if ($qty <= 0 && !$pkg) $qty = round((float)$i->physical_qty, 4);
                return (float)$qty;
            };
            // SUM per ingredient_id — bahan dengan >1 kemasan punya >1 baris opname
            return $prevOpname->items
                ->groupBy('ingredient_id')
                ->map(fn($grp) => $grp->sum($packedBase))
                ->all();
        }

        // Fallback: hitung dari history
        $prevIn = \DB::table('mutation_items as mi')
            ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
            ->where('m.destination_store_id', $storeId)
            ->where('m.status', 'confirmed')
            ->where(\DB::raw('COALESCE(m.delivery_date, m.transaction_date)'), '<', $startDate)
            ->select('mi.ingredient_id', \DB::raw('SUM(mi.total_in_base) as total'))
            ->groupBy('mi.ingredient_id')
            ->pluck('total', 'ingredient_id');

        $prevOut = \DB::table('mutation_items as mi')
            ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
            ->where('m.source_store_id', $storeId)
            ->where('m.status', 'confirmed')
            ->where(\DB::raw('COALESCE(m.delivery_date, m.transaction_date)'), '<', $startDate)
            ->whereIn('m.type', ['sale_internal','sale_external_out'])
            ->select('mi.ingredient_id', \DB::raw('SUM(mi.total_in_base) as total'))
            ->groupBy('mi.ingredient_id')
            ->pluck('total', 'ingredient_id');

        // Subquery: ambil 1 baris packaging per ingredient (cegah duplikat saat multi-packaging aktif)
        $prevUsage = \DB::table('daily_usages as du')
            ->leftJoinSub(
                \DB::table('ingredient_packagings')
                    ->select('ingredient_id', \DB::raw('MIN(pack_to_base) as pack_to_base'))
                    ->where('is_active', 1)
                    ->groupBy('ingredient_id'),
                'p',
                'p.ingredient_id', '=', 'du.ingredient_id'
            )
            ->where('du.store_id', $storeId)
            ->where('du.usage_date', '<', $startDate)
            ->select('du.ingredient_id', \DB::raw('SUM(du.qty_pack * COALESCE(p.pack_to_base, 1)) as total_base'))
            ->groupBy('du.ingredient_id')
            ->pluck('total_base', 'ingredient_id');

        $opening = [];
        $allIds = collect($prevIn->keys())->merge($prevOut->keys())->merge($prevUsage->keys())->unique();
        foreach ($allIds as $iid) {
            $bal = (float)($prevIn[$iid] ?? 0) - (float)($prevOut[$iid] ?? 0) - (float)($prevUsage[$iid] ?? 0);
            $opening[$iid] = max(0, $bal);
        }
        return $opening;
    }

    // Logic save (extracted dari method lama)
    private function saveImport($spreadsheet, int $storeId, int $month, int $year, int $daysInMonth): string
    {

        $sheet = $spreadsheet->getActiveSheet();
        $headerRow = 4;
        $dataStartRow = 5;
        $highestRow = $sheet->getHighestRow();

        // Map ingredient IDs yang bisa diakses + default packaging_id per ingredient
        $ingredientList = Ingredient::with(['packagings' => fn($q) => $q->where('is_active', true)->orderBy('id')])
            ->where('is_active', true)
            ->where('type', '!=', 'semi_finished')
            ->get()
            ->keyBy('id');

        $validIngIds   = $ingredientList->keys()->flip()->all();
        $defaultPkgMap = $ingredientList->map(fn($i) => $i->packagings->first()?->id)->all();

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = [];
        $affectedIngs = [];

        \DB::beginTransaction();
        try {
            for ($r = $dataStartRow; $r <= $highestRow; $r++) {
                [$ingId, $pkgId] = $this->parseTemplateKey($sheet->getCell("A{$r}")->getValue(), $defaultPkgMap);
                if (!$ingId) continue;
                if (!isset($validIngIds[$ingId])) {
                    $errors[] = "Baris {$r}: ID bahan {$ingId} tidak valid";
                    continue;
                }

                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $col = $this->columnLetter(3 + $d);
                    $val = $sheet->getCell("{$col}{$r}")->getValue();

                    // Cek lock per tanggal
                    $dateStr = Carbon::create($year, $month, $d)->toDateString();
                    if ($err = $this->lockError($storeId, $dateStr)) {
                        $skipped++;
                        continue;
                    }

                    if ($val === null || $val === '' || (is_numeric($val) && (float)$val == 0.0)) {
                        // Cell kosong ATAU 0 → hapus existing kalau ada (jangan simpan baris
                        // qty=0; konsisten dengan input manual yang menghapus saat qty=0).
                        $deleted = DailyUsage::where('store_id', $storeId)
                            ->where('ingredient_id', $ingId)
                            ->where('packaging_id', $pkgId)
                            ->where('usage_date', $dateStr)
                            ->delete();
                        if ($deleted) {
                            $affectedIngs[$ingId] = true;
                        }
                        continue;
                    }

                    if (!is_numeric($val) || $val < 0) {
                        $errors[] = "Baris {$r} tgl {$d}: nilai '{$val}' bukan angka valid";
                        continue;
                    }

                    $existing = DailyUsage::where('store_id', $storeId)
                        ->where('ingredient_id', $ingId)
                        ->where('packaging_id', $pkgId)
                        ->where('usage_date', $dateStr)
                        ->first();

                    if ($existing) {
                        if ((float)$existing->qty_pack != (float)$val) {
                            $existing->update([
                                'qty_pack'   => $val,
                                'created_by' => auth()->id(),
                            ]);
                            $updated++;
                            $affectedIngs[$ingId] = true;
                        }
                    } else {
                        DailyUsage::create([
                            'store_id'      => $storeId,
                            'ingredient_id' => $ingId,
                            'packaging_id'  => $pkgId,
                            'usage_date'    => $dateStr,
                            'qty_pack'      => $val,
                            'created_by'    => auth()->id(),
                        ]);
                        $inserted++;
                        $affectedIngs[$ingId] = true;
                    }
                }
            }
            \DB::commit();
        } catch (\Exception $e) {
            \DB::rollback();
            throw new \Exception('Gagal import: ' . $e->getMessage());
        }

        // Recalc FIFO untuk semua bahan yang terdampak
        foreach (array_keys($affectedIngs) as $ingId) {
            FifoService::recalculate($storeId, $ingId);
        }

        $lockMsg = Opname::lockMessageFor($storeId);
        $msg = "Import selesai: {$inserted} baru, {$updated} diperbarui";
        if ($skipped > 0) $msg .= ". ⚠️ {$skipped} data dilewati karena periode terkunci — {$lockMsg}";
        if (count($errors) > 0) {
            $msg .= ". Error: " . implode('; ', array_slice($errors, 0, 5));
            if (count($errors) > 5) $msg .= ' (dan ' . (count($errors) - 5) . ' lainnya)';
        }
        return $msg;
    }

    // Helper: konversi nomor kolom → huruf (1=A, 2=B, ..., 27=AA)
    private function columnLetter(int $col): string
    {
        $letter = '';
        while ($col > 0) {
            $rem    = ($col - 1) % 26;
            $letter = chr(65 + $rem) . $letter;
            $col    = intval(($col - 1) / 26);
        }
        return $letter;
    }

    /** Simpan urutan bahan custom per user (drag-drop dari halaman pencatatan harian). */
    public function saveOrder(Request $request)
    {
        $request->validate([
            'ingredient_ids'   => 'required|array',
            'ingredient_ids.*' => 'integer|exists:ingredients,id',
        ]);

        $userId = auth()->id();
        $ids    = $request->input('ingredient_ids');

        DB::transaction(function () use ($userId, $ids) {
            DB::table('user_ingredient_orders')->where('user_id', $userId)->delete();
            $rows = [];
            foreach ($ids as $i => $ingId) {
                $rows[] = [
                    'user_id'       => $userId,
                    'ingredient_id' => (int) $ingId,
                    'sort_order'    => $i + 1,
                ];
            }
            if ($rows) DB::table('user_ingredient_orders')->insert($rows);
        });

        return response()->json(['ok' => true]);
    }

    /** Reset urutan ke default (kategori → nama). */
    public function resetOrder()
    {
        DB::table('user_ingredient_orders')->where('user_id', auth()->id())->delete();
        return response()->json(['ok' => true]);
    }
}
