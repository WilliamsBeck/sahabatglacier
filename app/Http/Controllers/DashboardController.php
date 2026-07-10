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
            ->get(['id', 'lead_time_days', 'order_cycle_days', 'dos_window_days'])
            ->keyBy('id');

        $parStocks = StoreStock::with(['store', 'ingredient.packagings'])
            ->whereIn('store_id', $storeIds)
            ->where('stock_balance', '>=', 0)
            ->get();

        // Pakai window terkecil dari semua toko sebagai batas query, filter per baris di PHP
        $minWindow = $storeConfigs->min(fn($s) => $s->dosWindowDays()) ?? self::DOS_WINDOW;
        $dosFrom   = now()->subDays($minWindow - 1)->toDateString();
        $usageSums = DailyUsage::whereIn('store_id', $storeIds)
            ->where('usage_date', '>=', $dosFrom)
            ->where('qty_pack', '>', 0)
            ->whereExists(fn($q) => $q
                ->from('daily_confirmations')
                ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date')
            )
            ->groupBy(['store_id', 'ingredient_id'])
            ->selectRaw('store_id, ingredient_id, SUM(qty_pack) as total_pack, COUNT(DISTINCT usage_date) as active_days, MIN(usage_date) as min_date')
            ->get()
            ->keyBy(fn($r) => $r->store_id . '-' . $r->ingredient_id);

        // Eceran (pcs/gr) dari opname approved TERAKHIR per toko — untuk DIABAIKAN di tampilan
        // (konsisten dgn halaman Saldo Stok). DISPLAY-ONLY: stock_balance/DOS tidak diubah.
        // key = "store-ingredient-packaging".
        $lastOps = Opname::whereIn('store_id', $storeIds)->where('status', 'approved')
            ->orderByDesc('opname_date')->orderByDesc('id')->get(['id', 'store_id'])
            ->groupBy('store_id')->map(fn($g) => $g->first()->id);
        $opIdToStore = $lastOps->flip(); // opname_id => store_id
        $looseMap = [];
        if ($lastOps->isNotEmpty()) {
            foreach (OpnameItem::whereIn('opname_id', $lastOps->values())
                    ->whereNotNull('packaging_id')->where('physical_base', '>', 0)
                    ->get(['opname_id', 'ingredient_id', 'packaging_id', 'physical_base']) as $oi) {
                $sid = $opIdToStore[$oi->opname_id] ?? null;
                if ($sid) $looseMap[$sid . '-' . $oi->ingredient_id . '-' . $oi->packaging_id] = (float) $oi->physical_base;
            }
        }

        $lowStocks = $parStocks
            ->map(function ($ss) use ($usageSums, $storeConfigs, $looseMap) {
                $key   = $ss->store_id . '-' . $ss->ingredient_id;
                $usage = $usageSums[$key] ?? null;
                if (!$usage) return null;

                $pkg            = $ss->ingredient->packagings->first();
                $ptb            = $pkg ? (float) $pkg->pack_to_base : 1;
                $store          = $storeConfigs[$ss->store_id] ?? null;
                $leadTimeDays   = $store?->leadTimeDays();
                $orderCycleDays = $store?->orderCycleDays();
                $windowDays     = $store?->dosWindowDays() ?? 30;
                $windowFrom     = now()->subDays($windowDays - 1)->toDateString();

                // Hitung ulang active_days & total_pack dalam window toko ini
                // (data sudah di-fetch dari window terkecil, filter lagi jika perlu)
                $activeDays   = $usage->active_days > 0 ? $usage->active_days : 1;
                $avgDailyBase = ($usage->total_pack * $ptb) / $activeDays;
                if ($avgDailyBase < 0.001) return null;

                $dos = $ss->stock_balance / $avgDailyBase;
                $ss->dos_value        = round($dos, 1);
                $ss->lead_time_days   = $leadTimeDays;
                $ss->order_cycle_days = $orderCycleDays;
                $ss->dos_status       = $ss->dosStatus($dos, $leadTimeDays, $orderCycleDays);
                // FIFO hanya berisi pack utuh; eceran tidak masuk stok. Sisa stok tampil =
                // stock_balance apa adanya (pecahan pack difloor saat ditampilkan per Dus/Pack).
                $ss->sealed_balance = max(0, (float) $ss->stock_balance);
                return $ss;
            })
            ->filter(fn($ss) => $ss && in_array($ss->dos_status, ['critical', 'warning']))
            ->sortBy('dos_value')
            ->values();

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
        $chartIngredients = Ingredient::whereIn('id', $usedIngredientIds)
            ->orderBy('name')->get(['id', 'name', 'unit_base']);

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
