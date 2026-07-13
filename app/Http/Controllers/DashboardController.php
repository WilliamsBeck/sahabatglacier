<?php
namespace App\Http\Controllers;

use App\Models\{StoreStock, Store, WasteLog, WasteLogItem, ProductionLog, DailyUsage, AuditLog, Ingredient, Opname, OpnameItem};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    const DOS_WINDOW = 30;

    public function index()
    {
        $user     = auth()->user();
        $storeIds = $user->accessibleStoreIds();
        $stores   = $user->accessibleStores();

        // Filter ke satu toko bila dipilih dari store picker (?store_id=)
        $selectedStoreId = request('store_id');
        if ($selectedStoreId !== null && $selectedStoreId !== '' && in_array((int) $selectedStoreId, $storeIds)) {
            $sid      = (int) $selectedStoreId;
            $storeIds = [$sid];
            $stores   = $stores->where('id', $sid)->values();
        }
        $selectedStore = count($storeIds) === 1 ? $stores->first() : null;

        [$month, $year] = [$this->currentMonth(), $this->currentYear()];

        // ── 1. Toko aktif ──────────────────────────────────────────────────────
        $totalActiveStores = Store::whereIn('id', $storeIds)
            ->where('is_active', true)->count();

        // ── 2. Low stock (DOS < lead_time) ───────────────────────────────────
        $storeConfigs = Store::whereIn('id', $storeIds)
            ->get(['id', 'lead_time_days', 'order_cycle_days', 'safety_stock_days', 'dos_window_days'])
            ->keyBy('id');

        // Rumus DOS DISAMAKAN dengan halaman Saldo Stok (StockController):
        //  - pembagi rata-rata = WINDOW PENUH (bukan hari aktif)
        //  - basis stok        = FIFO pack UTUH per kemasan (bukan stock_balance ledger)
        //  - acuan window       = mundur dari TANGGAL TERAKHIR DIKONFIRMASI (bukan hari ini)
        //  - granularitas       = per (bahan × kemasan)
        // Dihitung per toko lalu digabung, supaya daftar "Stok Menipis" konsisten dgn Saldo Stok.
        $ingMap   = Ingredient::with(['packagings' => fn($q) => $q->where('is_active', true)->orderBy('id')])
            ->where('is_active', true)->get()->keyBy('id');
        $storeMap = $stores->keyBy('id');
        $kkey     = fn($i, $p) => $i . '-' . ($p ?: 0);

        // pkgConv untuk konversi base (ptb/ctb) — sama untuk semua toko
        $pkgConv = [];
        foreach ($ingMap as $ingX) {
            foreach ($ingX->packagings as $p) {
                $pkgConv[$p->id] = ['ptb' => (float) $p->pack_to_base,
                                    'ctb' => (float) $p->crate_to_pack * (float) $p->pack_to_base];
            }
        }

        $lowStocks = collect();
        foreach ($storeIds as $sid) {
            $cfg             = $storeConfigs[$sid] ?? null;
            $leadTimeDays    = $cfg?->leadTimeDays();
            $orderCycleDays  = $cfg?->orderCycleDays();
            $safetyStockDays = $cfg?->safetyStockDays() ?? 0;
            $dosWindowDays   = $cfg?->dosWindowDays() ?? self::DOS_WINDOW;

            // Window DOS mundur dari tanggal terakhir dikonfirmasi (sama dgn Saldo Stok)
            $lastConfirmed = \App\Models\DailyConfirmation::where('store_id', $sid)
                ->orderByDesc('confirmation_date')->first()?->confirmation_date;
            if (!$lastConfirmed) continue;

            $dosTo   = $lastConfirmed->toDateString();
            $dosFrom = $lastConfirmed->copy()->subDays($dosWindowDays - 1)->toDateString();
            // Pemakaian dalam BASE per bahan (konversi qty_pack × ptb per kemasan lalu jumlah).
            // Dipakai untuk DOS tingkat bahan (gabungan semua kemasan).
            $usageBaseByIng = [];
            foreach (DailyUsage::where('store_id', $sid)
                    ->whereBetween('usage_date', [$dosFrom, $dosTo])
                    ->where('qty_pack', '>', 0)
                    ->whereExists(fn($q) => $q->from('daily_confirmations')
                        ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                        ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date'))
                    ->groupBy('ingredient_id', 'packaging_id')
                    ->selectRaw('ingredient_id, packaging_id, SUM(qty_pack) as p')
                    ->get() as $r) {
                $ptbU = ($r->packaging_id && isset($pkgConv[$r->packaging_id])) ? $pkgConv[$r->packaging_id]['ptb'] : 1;
                $usageBaseByIng[$r->ingredient_id] = ($usageBaseByIng[$r->ingredient_id] ?? 0) + (float) $r->p * $ptbU;
            }
            if (empty($usageBaseByIng)) continue;

            // FIFO pack utuh per (ing,pkg)
            $fifoByKey = [];
            foreach (\App\Models\MutationItem::whereHas('mutation', fn($q) =>
                        $q->where('destination_store_id', $sid)->where('status', 'confirmed')
                          ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'opening_stock', 'sale_internal', 'sale_external']))
                    ->where('remaining_qty', '>', 0)
                    ->selectRaw('ingredient_id, packaging_id, SUM(remaining_qty) as t')
                    ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
                $fifoByKey[$kkey($r->ingredient_id, $r->packaging_id)] = (float) $r->t;
            }

            // received & demand per (ing,pkg) — untuk deteksi saldo MINUS (over-usage), sama dgn Saldo Stok
            $receivedMap = [];
            foreach (\App\Models\MutationItem::whereHas('mutation', fn($q) =>
                        $q->where('destination_store_id', $sid)->where('status', 'confirmed'))
                    ->selectRaw('ingredient_id, packaging_id, SUM(total_in_base) as t')
                    ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
                $receivedMap[$kkey($r->ingredient_id, $r->packaging_id)] = (float) $r->t;
            }
            $demandMap = [];
            foreach (\App\Models\MutationItem::whereHas('mutation', fn($q) =>
                        $q->where('source_store_id', $sid)->where('status', 'confirmed')
                          ->whereIn('type', ['sale_internal', 'sale_external_out']))
                    ->selectRaw('ingredient_id, packaging_id, SUM(total_in_base) as t')
                    ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
                $k = $kkey($r->ingredient_id, $r->packaging_id);
                $demandMap[$k] = ($demandMap[$k] ?? 0) + (float) $r->t;
            }
            foreach (DailyUsage::where('store_id', $sid)->where('qty_pack', '>', 0)
                    ->whereExists(fn($q) => $q->from('daily_confirmations')
                        ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                        ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date'))
                    ->selectRaw('ingredient_id, packaging_id, SUM(qty_pack) as p')
                    ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
                $ptbU = ($r->packaging_id && isset($pkgConv[$r->packaging_id])) ? $pkgConv[$r->packaging_id]['ptb'] : 1;
                $k = $kkey($r->ingredient_id, $r->packaging_id);
                $demandMap[$k] = ($demandMap[$k] ?? 0) + (float) $r->p * $ptbU;
            }
            foreach (OpnameItem::query()
                    ->join('opnames', 'opnames.id', '=', 'opname_items.opname_id')
                    ->where('opnames.store_id', $sid)->where('opnames.status', 'approved')
                    ->where('opname_items.variance', '<', 0)
                    ->selectRaw('opname_items.ingredient_id as ing, opname_items.packaging_id as pkg, SUM(opname_items.variance) as v')
                    ->groupBy('opname_items.ingredient_id', 'opname_items.packaging_id')->get() as $r) {
                $k = $kkey($r->ing, $r->pkg);
                $demandMap[$k] = ($demandMap[$k] ?? 0) + abs((float) $r->v);
            }
            foreach (WasteLogItem::query()
                    ->join('waste_logs', 'waste_logs.id', '=', 'waste_log_items.waste_log_id')
                    ->where('waste_logs.store_id', $sid)->where('waste_log_items.source_type', 'raw')
                    ->selectRaw('waste_log_items.ingredient_id as ing, waste_log_items.packaging_id as pkg, SUM(waste_log_items.qty_crate) as c, SUM(waste_log_items.qty_pack) as p, SUM(waste_log_items.qty_base) as b')
                    ->groupBy('waste_log_items.ingredient_id', 'waste_log_items.packaging_id')->get() as $r) {
                $base = ($r->pkg && isset($pkgConv[$r->pkg]))
                    ? ((float) $r->c * $pkgConv[$r->pkg]['ctb'] + (float) $r->p * $pkgConv[$r->pkg]['ptb'])
                    : (float) $r->b;
                $k = $kkey($r->ing, $r->pkg);
                $demandMap[$k] = ($demandMap[$k] ?? 0) + $base;
            }

            // On-order (dalam perjalanan) = mutasi masuk berstatus draft. Dipakai untuk
            // Inventory Position supaya item yang barangnya sudah di jalan tidak dialarmkan.
            $onOrderMap = [];
            foreach (\App\Models\MutationItem::whereHas('mutation', fn($q) =>
                        $q->where('destination_store_id', $sid)->where('status', 'draft')
                          ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'sale_internal', 'sale_external']))
                    ->selectRaw('ingredient_id, packaging_id, SUM(total_in_base) as t')
                    ->groupBy('ingredient_id', 'packaging_id')->get() as $r) {
                $onOrderMap[$kkey($r->ingredient_id, $r->packaging_id)] = (float) $r->t;
            }

            // Bangun baris low-stock per BAHAN (gabungan semua kemasan).
            // Stok & on-order dijumlah lintas kemasan → bahan tak tampil 0 palsu hanya
            // karena satu kemasan kosong sementara kemasan lain masih berstok.
            foreach ($usageBaseByIng as $ingId => $usageBase) {
                $ing = $ingMap[$ingId] ?? null;
                if (!$ing) continue;

                $totalStock = 0.0; $totalOnOrder = 0.0;
                foreach ($ing->packagings as $pkg) {
                    $k          = $kkey($ingId, $pkg->id);
                    $wholeBase  = $fifoByKey[$k] ?? 0;
                    $signed     = ($receivedMap[$k] ?? 0) - ($demandMap[$k] ?? 0);
                    $totalStock   += ($signed < -0.001 ? $signed : $wholeBase);
                    $totalOnOrder += ($onOrderMap[$k] ?? 0);
                }

                // pembagi = WINDOW PENUH (bukan hari aktif)
                $avgDailyBase = (float) $usageBase / $dosWindowDays;
                if ($avgDailyBase < 0.001) continue;

                $dos    = $totalStock / $avgDailyBase;                    // DOS stok fisik (tampilan)
                $posDos = ($totalStock + $totalOnOrder) / $avgDailyBase;  // Inventory Position (status)
                $status = (new StoreStock())->dosStatus($posDos, $leadTimeDays, $safetyStockDays, $orderCycleDays);
                if (!in_array($status, ['critical', 'warning'])) continue;

                $lowStocks->push((object) [
                    'ingredient'      => $ing,
                    'packaging'       => $ing->packagings->first(),
                    'store'           => $storeMap[$sid] ?? null,
                    'store_id'        => $sid,
                    'ingredient_id'   => $ingId,
                    'sealed_balance'  => $totalStock,
                    'dos_value'       => round($dos, 1),
                    'dos_status'      => $status,
                    'lead_time_days'  => $leadTimeDays,
                    'order_cycle_days'=> $orderCycleDays,
                ]);
            }
        }
        $lowStocks = $lowStocks->sortBy('dos_value')->values();

        // ── 3. Total waste bulan ini ───────────────────────────────────────────
        $totalWaste = WasteLog::whereIn('store_id', $storeIds)
            ->whereMonth('waste_date', $month)->whereYear('waste_date', $year)
            ->sum('total_loss_amount');

        // ── 4. Total produksi bahan setengah jadi bulan ini ───────────────────
        $totalProduksi = ProductionLog::whereIn('store_id', $storeIds)
            ->whereMonth('production_date', $month)
            ->whereYear('production_date', $year)
            ->count(); // jumlah batch produksi

        // ── 5. Toko belum update pencatatan harian s/d kemarin ────────────────
        $yesterday = Carbon::yesterday()->toDateString();

        // store_ids yang sudah punya konfirmasi pencatatan untuk tanggal kemarin
        $updatedStoreIds = \App\Models\DailyConfirmation::whereIn('store_id', $storeIds)
            ->where('confirmation_date', $yesterday)
            ->distinct()->pluck('store_id')->toArray();

        $storesNotUpdated = $stores->filter(fn($s) => $s->is_active)
            ->whereNotIn('id', $updatedStoreIds)
            ->values();

        // Ambil tanggal terakhir KONFIRMASI per toko (untuk info di list)
        $lastUsageDates = \App\Models\DailyConfirmation::whereIn('store_id', $storeIds)
            ->groupBy('store_id')
            ->selectRaw('store_id, MAX(confirmation_date) as last_date')
            ->pluck('last_date', 'store_id');

        // ── 6. Nilai stok saat ini (Σ sisa FIFO × harga) ─────────────────────
        $stockValue = (float) DB::table('mutation_items as mi')
            ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
            ->where('m.status', 'confirmed')
            ->whereIn('m.destination_store_id', $storeIds)
            ->selectRaw('SUM(mi.remaining_qty * mi.price_per_base) as v')
            ->value('v');

        // ── 7. Grafik pemakaian bahan baku (pencatatan harian) ──────────────────
        $chartMonth  = (int) request('chart_month', now()->month);
        $chartYear   = (int) request('chart_year',  now()->year);
        // Clamp ke rentang valid
        $chartMonth  = max(1, min(12, $chartMonth));
        $chartYear   = max(2020, min((int) now()->year, $chartYear));

        $chartPeriod = Carbon::create($chartYear, $chartMonth, 1);
        $monthStart  = $chartPeriod->copy()->startOfMonth()->toDateString();
        $monthEnd    = $chartPeriod->copy()->endOfMonth()->toDateString();
        $daysInMonth = $chartPeriod->daysInMonth;

        // Daftar bahan untuk dropdown (yang pernah dipakai bulan ini).
        // HARUS pakai filter konfirmasi yang SAMA dengan grafik di bawah — kalau tidak,
        // pemakaian draft (belum dikonfirmasi) ikut muncul di dropdown padahal grafiknya
        // kosong saat dipilih.
        $usedIngredientIds = DailyUsage::whereIn('store_id', $storeIds)
            ->whereBetween('usage_date', [$monthStart, $monthEnd])
            ->where('qty_pack', '>', 0)
            ->whereExists(fn($q) => $q
                ->from('daily_confirmations')
                ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date')
            )
            ->distinct()->pluck('ingredient_id');
        $chartIngredients = Ingredient::whereIn('ingredients.id', $usedIngredientIds)
            ->orderedByCategory()->get(['ingredients.id', 'ingredients.name', 'ingredients.unit_base']);

        $chartSelectedId = request('chart_ingredient');
        if ($chartSelectedId && !$usedIngredientIds->contains((int) $chartSelectedId)) {
            $chartSelectedId = null;
        }
        $chartIngredient = $chartSelectedId
            ? $chartIngredients->firstWhere('id', (int) $chartSelectedId)
            : null;

        // Query dasar: pemakaian per hari (qty_base = qty_pack × pack_to_base)
        $usageBase = DB::table('daily_usages as du')
            ->leftJoin('ingredient_packagings as ip', 'ip.id', '=', 'du.packaging_id')
            ->whereIn('du.store_id', $storeIds)
            ->whereBetween('du.usage_date', [$monthStart, $monthEnd])
            ->where('du.qty_pack', '>', 0)
            ->whereExists(fn($q) => $q
                ->from('daily_confirmations')
                ->whereColumn('daily_confirmations.store_id', 'du.store_id')
                ->whereColumn('daily_confirmations.confirmation_date', 'du.usage_date')
            );

        $chartData = array_fill(1, $daysInMonth, 0.0);

        if ($chartIngredient) {
            // Mode kuantitas: total pack bahan terpilih per hari
            $rows = (clone $usageBase)
                ->where('du.ingredient_id', $chartIngredient->id)
                ->selectRaw('DAY(du.usage_date) as d, SUM(du.qty_pack) as q')
                ->groupBy('d')->pluck('q', 'd');
            foreach ($rows as $d => $q) { $chartData[(int) $d] = (float) $q; }
            $chartMode = 'qty';
            $chartUnit = 'pack';
        } else {
            // Mode nilai (Rp): Σ qty_base × harga rata-rata pembelian per bahan
            $priceMap = DB::table('mutation_items as mi')
                ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
                ->where('m.status', 'confirmed')
                ->whereIn('m.destination_store_id', $storeIds)
                ->selectRaw('mi.ingredient_id, SUM(mi.total_in_base * mi.price_per_base) val, SUM(mi.total_in_base) qty')
                ->groupBy('mi.ingredient_id')->get()
                ->mapWithKeys(fn($r) => [$r->ingredient_id => $r->qty > 0 ? $r->val / $r->qty : 0]);

            $rows = (clone $usageBase)
                ->selectRaw('DAY(du.usage_date) as d, du.ingredient_id, SUM(du.qty_pack * COALESCE(ip.pack_to_base,1)) as q')
                ->groupBy('d', 'du.ingredient_id')->get();
            foreach ($rows as $r) {
                $chartData[(int) $r->d] += (float) $r->q * (float) ($priceMap[$r->ingredient_id] ?? 0);
            }
            $chartMode = 'value';
            $chartUnit = 'Rp';
        }

        $chartLabels = range(1, $daysInMonth);
        $chartData   = array_values($chartData);
        $chartIngredientName = $chartIngredient->name ?? null;

        // ── 8. Top bahan waste bulan ini ──────────────────────────────────────
        $topWaste = WasteLogItem::query()
            ->join('waste_logs', 'waste_logs.id', '=', 'waste_log_items.waste_log_id')
            ->whereIn('waste_logs.store_id', $storeIds)
            ->whereMonth('waste_logs.waste_date', $month)
            ->whereYear('waste_logs.waste_date', $year)
            ->whereNotNull('waste_log_items.ingredient_id')
            ->groupBy('waste_log_items.ingredient_id')
            ->selectRaw('waste_log_items.ingredient_id, SUM(waste_log_items.subtotal_loss) as total_loss')
            ->orderByDesc('total_loss')
            ->limit(5)
            ->with('ingredient:id,name')
            ->get();
        $topWasteMax = $topWaste->max('total_loss') ?: 1;

        // ── 9. Aktivitas terbaru (real-time feed) ─────────────────────────────
        // Skip baris item/teknis biar feed berisi peristiwa yang bermakna saja
        $recentActivityQuery = AuditLog::latest()
            ->whereNotIn('model', [
                'MutationItem', 'WasteLogItem', 'ProductionLogItem', 'OpnameItem',
                'StockLedger', 'DailyUsage', 'IngredientComposition',
            ]);

        // Bila satu toko dipilih, hanya tampilkan aktivitas terkait toko itu
        if ($selectedStore) {
            $sid = $selectedStore->id;
            $recentActivityQuery->where(function ($q) use ($sid) {
                foreach (['store_id', 'source_store_id', 'destination_store_id'] as $key) {
                    $q->orWhereRaw("JSON_EXTRACT(new_values, '$.\"$key\"') = ?", [$sid])
                      ->orWhereRaw("JSON_EXTRACT(old_values, '$.\"$key\"') = ?", [$sid]);
                }
            });
        }

        $recentActivity = $recentActivityQuery->limit(8)->get();

        return view('dashboard.index', compact(
            'totalActiveStores', 'lowStocks', 'totalWaste', 'totalProduksi',
            'storesNotUpdated', 'lastUsageDates', 'yesterday',
            'selectedStore', 'stockValue',
            'chartIngredients', 'chartSelectedId', 'chartIngredientName',
            'chartLabels', 'chartData', 'chartMode', 'chartUnit',
            'chartMonth', 'chartYear',
            'topWaste', 'topWasteMax', 'recentActivity'
        ));
    }

    private function currentMonth(): int { return now()->month; }
    private function currentYear():  int { return now()->year;  }
}
