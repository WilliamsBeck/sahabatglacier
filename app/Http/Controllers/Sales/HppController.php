<?php
namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\{MonthlySale, MonthlyRevenue, Recipe, IngredientComposition, IngredientPackaging, MutationItem, Opname};
use App\Exports\ArrayExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;
use Carbon\Carbon;

class HppController extends Controller
{
    // ── Load komposisi: [parent_id => [{child_id, child, qty_needed}]] ────────
    private function loadCompositionMap(): array
    {
        $map = [];
        IngredientComposition::with('child')->get()->each(function ($c) use (&$map) {
            $map[$c->parent_id][] = (object)[
                'child_id'   => $c->child_id,
                'child'      => $c->child,
                'qty_needed' => $c->qty_needed_exact,
            ];
        });
        return $map;
    }

    // ── Harga pembelian terakhir dari PT Zhisheng per bahan ──────────────────
    // Hanya mengambil transaksi purchase_zhisheng, tanpa batas stok (pakai harga
    // terakhir meskipun stok kosong). Tidak ada weighted-avg — murni harga terbaru.
    private function buildBatchPrice(int $storeId, array $ingIds, string $upToDate): \Illuminate\Support\Collection
    {
        if (empty($ingIds)) return collect();

        // Ambil harga terakhir dari Zhisheng secara global (semua toko) —
        // harga Rp 0 diabaikan, urutkan dari tgl terima terbaru.
        $rows = MutationItem::whereHas('mutation', fn($q) =>
                $q->where('status', 'confirmed')
                  ->where('delivery_date', '<=', $upToDate)
                  // termasuk opening_stock (harga dari opname) & purchase_supplier (supplier lokal).
                  // sale_external (pembelian eksternal) SENGAJA dikecualikan: harganya diinput manual
                  // & bersifat insidental, tidak boleh dipakai sebagai harga acuan HPP.
                  ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'opening_stock', 'sale_internal'])
            )
            ->whereIn('ingredient_id', $ingIds)
            ->where('price_per_base', '>', 0)
            ->join('mutations', 'mutations.id', '=', 'mutation_items.mutation_id')
            ->orderByDesc('mutations.delivery_date')
            ->orderByDesc('mutations.id')
            ->get(['mutation_items.ingredient_id', 'mutation_items.price_per_base']);

        // Ambil harga terbaru (pertama setelah diurutkan DESC) per ingredient
        return $rows->groupBy('ingredient_id')
            ->map(fn($g) => (float) $g->first()->price_per_base);
    }

    // ── Harga rata-rata tertimbang dari SISA batch FIFO per bahan ─────────────
    // Σ(remaining_qty × price_per_base) / Σ(remaining_qty) — sama persis dengan
    // cara halaman detail Stok Opname menghitung "Nilai Fisik". Dipakai untuk
    // menilai SO Akhir agar HPP Aktual konsisten dengan tampilan opname.
    private function buildRemainingPriceMap(int $storeId, array $ingIds): array
    {
        if (empty($ingIds)) return [];

        $batches = MutationItem::whereHas('mutation', fn($q) =>
                $q->where('destination_store_id', $storeId)
                  ->where('status', 'confirmed')
                  ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'opening_stock', 'sale_internal', 'sale_external'])
            )
            ->whereIn('ingredient_id', $ingIds)
            ->where('remaining_qty', '>', 0)
            ->get(['ingredient_id', 'remaining_qty', 'price_per_base'])
            ->groupBy('ingredient_id');

        $map = [];
        foreach ($ingIds as $id) {
            $group    = $batches[$id] ?? collect();
            $totalQty = $group->sum('remaining_qty');
            $totalVal = $group->sum(fn($b) => $b->remaining_qty * $b->price_per_base);
            $map[$id] = $totalQty > 0 ? $totalVal / $totalQty : 0;
        }
        return $map;
    }

    // ── Core kalkulasi HPP untuk satu toko ────────────────────────────────────
    // Mengembalikan ['summary' => ..., 'menuRows' => ..., 'ingredientRows' => ...]
    // atau null jika tidak ada data penjualan.
    private function calcHppForStore(int $storeId, int $month, int $year, string $periodType): ?array
    {
        $monthStart = Carbon::create($year, $month, 1);
        $dateTo     = $periodType === 'mid_month'
                        ? Carbon::create($year, $month, 15)
                        : Carbon::create($year, $month)->endOfMonth();
        $dateEnd    = $dateTo->toDateString();

        $omset = MonthlyRevenue::where('store_id', $storeId)
            ->where('month', $month)->where('year', $year)
            ->where('period_type', $periodType)
            ->value('total_revenue') ?? 0;

        $sales = MonthlySale::with('menu')
            ->where('store_id', $storeId)
            ->where('month', $month)->where('year', $year)
            ->where('period_type', $periodType)->get();

        // SO Akhir & Awal — diambil lebih awal
        $prevMonth  = $monthStart->copy()->subMonth();
        $soAkhir    = Opname::where('store_id', $storeId)
            ->where('period_month', $month)->where('period_year', $year)
            ->where('period_type', $periodType)->where('status', 'approved')
            ->with('items.ingredient')->first();
        $soAwal     = Opname::where('store_id', $storeId)
            ->where('period_month', $prevMonth->month)->where('period_year', $prevMonth->year)
            ->where('period_type', 'end_month')->where('status', 'approved')
            ->with('items')->first();

        // Tampilkan jika ada data penjualan, omset, ATAU ada SO Akhir (untuk HPP aktual)
        if ($sales->isEmpty() && $omset <= 0 && !$soAkhir) return null;

        $menuIds    = $sales->pluck('menu_id')->unique()->all();
        // Resep PER TOKO — resolusi per VERSI, bukan per bahan:
        //   - Kalau toko ini punya versi resep sendiri → versi itu dipakai SEPENUHNYA
        //     dan resep default DIABAIKAN TOTAL (tidak dicampur per-bahan).
        //   - Kalau tidak ada versi khusus toko → pakai versi default (store_id NULL).
        //   - Di dalam pool terpilih, ambil versi dgn effective_from TERBARU (<= dateEnd).
        //     (Satu versi = satu kombinasi tanggal berlaku + toko.)
        $allRecipes = Recipe::with('ingredient')
            ->whereIn('menu_id', $menuIds)
            ->where(fn($q) => $q->where('store_id', $storeId)->orWhereNull('store_id'))
            ->where('effective_from', '<=', $dateEnd)
            ->get()
            ->groupBy('menu_id')
            ->map(function ($rows) {
                $pool = $rows->whereNotNull('store_id');          // versi khusus toko menang
                if ($pool->isEmpty()) $pool = $rows->whereNull('store_id');
                if ($pool->isEmpty()) return collect();

                $latest = $pool->map(fn($r) => $r->effective_from->format('Y-m-d'))->max();
                return $pool
                    ->filter(fn($r) => $r->effective_from->format('Y-m-d') === $latest)
                    ->groupBy('ingredient_id')
                    ->map(fn($g) => $g->first());
            });

        $compositionMap = $this->loadCompositionMap();

        $rawIngIds = [];
        foreach ($menuIds as $menuId) {
            foreach ($allRecipes[$menuId] ?? collect() as $recipe) {
                $id = $recipe->ingredient_id;
                if (isset($compositionMap[$id])) {
                    foreach ($compositionMap[$id] as $c) $rawIngIds[] = $c->child_id;
                } else {
                    $rawIngIds[] = $id;
                }
            }
        }
        // Kalau tidak ada menu terjual, pakai semua bahan dari SO Akhir sebagai sumber
        if (empty($rawIngIds) && $soAkhir) {
            $rawIngIds = $soAkhir->items->pluck('ingredient_id')->unique()->all();
        }
        // Kalau masih kosong (tidak ada SO juga), tidak ada yang bisa dihitung
        if (empty($rawIngIds)) return null;
        // Sertakan SEMUA bahan dari SO Akhir & SO Awal (bukan hanya bahan resep),
        // supaya map nilai/qty pembelian/opname lengkap & total HPP Aktual tidak bocor.
        if ($soAkhir) $rawIngIds = array_merge($rawIngIds, $soAkhir->items->pluck('ingredient_id')->all());
        if ($soAwal)  $rawIngIds = array_merge($rawIngIds, $soAwal->items->pluck('ingredient_id')->all());
        $rawIngIds  = array_values(array_unique($rawIngIds));
        $batchPrice = $this->buildBatchPrice($storeId, $rawIngIds, $dateEnd);

        // Nilai SO Akhir = qty × harga BEKU opname (price_per_base yg dikunci saat
        // approve — sudah FIFO-berlapis). Itu nilai historis yg benar; JANGAN dinilai
        // ulang dgn batch sekarang (batch sudah berubah). Fallback weighted bila kosong.
        $remainingPriceMap = $this->buildRemainingPriceMap($storeId, $rawIngIds);
        $closingValMap = $soAkhir ? $soAkhir->items->groupBy('ingredient_id')
            ->map(fn($g) => $g->sum(fn($i) => (float)$i->physical_qty
                * (float)(($i->price_per_base ?? 0) > 0
                    ? $i->price_per_base
                    : ($remainingPriceMap[$i->ingredient_id] ?? 0))))->all() : [];

        // ── Map nilai & qty per bahan (untuk HPP AKTUAL) — dihitung lebih awal ──
        // supaya harga AKTUAL per-unit juga bisa dipakai untuk HPP Ideal.
        $closingMap     = $soAkhir ? $soAkhir->items->groupBy('ingredient_id')->map(fn($g) => (float)$g->sum('physical_qty'))->all() : [];
        $openingMap     = $soAwal  ? $soAwal->items->groupBy('ingredient_id')->map(fn($g) => (float)$g->sum('physical_qty'))->all()  : [];
        // Nilai SO Awal = qty × harga BEKU opname periode lalu (nilai historis benar).
        $openingValMap  = $soAwal  ? $soAwal->items->groupBy('ingredient_id')
            ->map(fn($g) => $g->sum(fn($i) => (float)$i->physical_qty * (float)$i->price_per_base))->all()  : [];

        $purchaseValMap = MutationItem::whereHas('mutation', fn($q) =>
                $q->where('destination_store_id', $storeId)->where('status', 'confirmed')
                  ->whereBetween(\DB::raw('COALESCE(delivery_date, transaction_date)'), [$monthStart, $dateTo])
                  ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'sale_internal', 'sale_external']))
            ->whereIn('ingredient_id', $rawIngIds)->get(['ingredient_id', 'cost_subtotal'])
            ->groupBy('ingredient_id')->map(fn($g) => (float)$g->sum('cost_subtotal'));
        $salesOutValMap = MutationItem::whereHas('mutation', fn($q) =>
                $q->where('source_store_id', $storeId)->where('status', 'confirmed')
                  ->whereBetween(\DB::raw('COALESCE(delivery_date, transaction_date)'), [$monthStart, $dateTo])
                  ->whereIn('type', ['sale_internal', 'sale_external_out']))
            ->whereIn('ingredient_id', $rawIngIds)->get(['ingredient_id', 'cost_subtotal'])
            ->groupBy('ingredient_id')->map(fn($g) => (float)$g->sum('cost_subtotal'));
        $purchaseMap = MutationItem::whereHas('mutation', fn($q) =>
                $q->where('destination_store_id', $storeId)->where('status', 'confirmed')
                  ->whereBetween(\DB::raw('COALESCE(delivery_date, transaction_date)'), [$monthStart, $dateTo])
                  ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'sale_internal', 'sale_external']))
            ->whereIn('ingredient_id', $rawIngIds)->get(['ingredient_id', 'total_in_base'])
            ->groupBy('ingredient_id')->map(fn($g) => $g->sum('total_in_base'));
        $salesOutMap = MutationItem::whereHas('mutation', fn($q) =>
                $q->where('source_store_id', $storeId)->where('status', 'confirmed')
                  ->whereBetween(\DB::raw('COALESCE(delivery_date, transaction_date)'), [$monthStart, $dateTo])
                  ->whereIn('type', ['sale_internal', 'sale_external_out']))
            ->whereIn('ingredient_id', $rawIngIds)->get(['ingredient_id', 'total_in_base'])
            ->groupBy('ingredient_id')->map(fn($g) => $g->sum('total_in_base'));

        // Harga per-unit AKTUAL periode ini = HPP aktual ÷ qty terpakai.
        // Dipakai sebagai harga untuk HPP IDEAL juga → Selisih HPP murni mencerminkan
        // selisih KUANTITAS (boros/hemat), bukan beda harga. Fallback ke harga beli
        // terakhir (batchPrice) bila tidak ada data aktual untuk bahan itu.
        $idealPriceMap = [];
        foreach ($rawIngIds as $iid) {
            $actBase = (float)($openingMap[$iid] ?? 0) + (float)($purchaseMap[$iid] ?? 0)
                     - (float)($salesOutMap[$iid] ?? 0) - (float)($closingMap[$iid] ?? 0);
            $actVal  = (float)($openingValMap[$iid] ?? 0) + (float)($purchaseValMap[$iid] ?? 0)
                     - (float)($salesOutValMap[$iid] ?? 0) - (float)($closingValMap[$iid] ?? 0);
            $unit = ($actBase > 0 && $actVal > 0) ? $actVal / $actBase : 0;
            $idealPriceMap[$iid] = $unit > 0 ? $unit : (float)($batchPrice[$iid] ?? 0);
        }
        $idealPrice = fn($id) => (float)($idealPriceMap[$id] ?? ($batchPrice[$id] ?? 0));

        // ── menuRows ──────────────────────────────────────────────────────────
        $menuRows = $sales->map(function ($sale) use ($allRecipes, $compositionMap, $idealPrice) {
            $hppPerPcs = 0;
            $ingRows   = [];
            foreach ($allRecipes[$sale->menu_id] ?? collect() as $recipe) {
                $id = $recipe->ingredient_id;
                if (isset($compositionMap[$id])) {
                    foreach ($compositionMap[$id] as $comp) {
                        $price     = $idealPrice($comp->child_id);
                        $qtyPerPcs = $recipe->qty_usage * $comp->qty_needed;
                        $usage     = $sale->total_sold * $qtyPerPcs;
                        $hppPerPcs += $qtyPerPcs * $price;
                        $ingRows[] = (object)[
                            'ingredient'   => $comp->child,
                            'via_composed' => $recipe->ingredient->name,
                            'qty_per_pcs'  => $qtyPerPcs,
                            'total_usage'  => $usage,
                            'price'        => $price,
                            'hpp'          => $usage * $price,
                        ];
                    }
                } else {
                    $price     = $idealPrice($id);
                    $usage     = $sale->total_sold * $recipe->qty_usage;
                    $hppPerPcs += $recipe->qty_usage * $price;
                    $ingRows[] = (object)[
                        'ingredient'   => $recipe->ingredient,
                        'via_composed' => null,
                        'qty_per_pcs'  => $recipe->qty_usage,
                        'total_usage'  => $usage,
                        'price'        => $price,
                        'hpp'          => $usage * $price,
                    ];
                }
            }
            return (object)[
                'menu'        => $sale->menu,
                'total_sold'  => $sale->total_sold,
                'hpp_per_pcs' => $hppPerPcs,
                'hpp_ideal'   => $sale->total_sold * $hppPerPcs,
                'ingredients' => $ingRows,
            ];
        })->sortByDesc('hpp_ideal')->values();

        // ── ingredientRows ────────────────────────────────────────────────────
        $idealAgg = [];
        foreach ($menuRows as $row) {
            foreach ($row->ingredients as $ing) {
                $id = $ing->ingredient->id;
                if (!isset($idealAgg[$id])) {
                    $idealAgg[$id] = ['ingredient' => $ing->ingredient, 'usage_base' => 0.0, 'hpp' => 0.0, 'price' => $ing->price];
                }
                $idealAgg[$id]['usage_base'] += $ing->total_usage;
                $idealAgg[$id]['hpp']        += $ing->hpp;
            }
        }

        // (Map nilai & qty per bahan sudah dihitung lebih awal, sebelum menuRows.)

        // SELALU sertakan SEMUA bahan dari SO Akhir (bukan hanya saat tak ada menu).
        // Bahan yang tidak dipakai resep tetap punya HPP AKTUAL (terpakai/waste/selisih
        // opname), jadi harus ikut di total. Tanpa ini, total HPP Aktual bocor begitu
        // ada penjualan (idealAgg cuma berisi bahan resep).
        if ($soAkhir) {
            foreach ($soAkhir->items as $item) {
                $ingId = $item->ingredient_id;
                if (!isset($idealAgg[$ingId])) {
                    $idealAgg[$ingId] = ['ingredient' => $item->ingredient, 'usage_base' => 0.0, 'hpp' => 0.0, 'price' => 0];
                }
            }
        }

        $packagingMap = IngredientPackaging::where('is_active', true)
            ->whereIn('ingredient_id', array_keys($idealAgg) ?: [0])
            ->orderBy('id')->get()
            ->groupBy('ingredient_id')->map(fn($g) => $g->first());

        // Urutan kategori (sama dengan halaman Stok Opname) untuk sorting baris.
        $catOrder = \App\Models\IngredientCategory::pluck('sort_order', 'name');

        $ingredientRows = collect($idealAgg)->map(function ($agg, $ingId) use (
            $batchPrice, $idealPriceMap, $closingMap, $openingMap, $purchaseMap, $salesOutMap,
            $closingValMap, $openingValMap, $purchaseValMap, $salesOutValMap,
            $packagingMap, $soAkhir
        ) {
            $pkg      = $packagingMap[$ingId] ?? null;
            $dusSize  = ($pkg && $pkg->crate_to_pack && $pkg->pack_to_base)
                        ? (int)$pkg->crate_to_pack * (int)$pkg->pack_to_base : null;
            // Harga tampil = harga AKTUAL per-unit periode (HPP aktual ÷ qty), sama
            // dengan yang dipakai untuk HPP Ideal. Fallback harga beli terakhir.
            $avgPrice  = (float)($idealPriceMap[$ingId] ?? ($batchPrice[$ingId] ?? 0));
            $idealBase = (float)$agg['usage_base'];
            $hppIdeal  = (float)$agg['hpp'];
            $hasActual  = $soAkhir && array_key_exists($ingId, $closingMap);
            $actualBase = null; $hppAktual = null;

            if ($hasActual) {
                $openingQty  = $openingMap[$ingId] ?? 0;
                $purchaseQty = (float)($purchaseMap[$ingId] ?? 0);
                $salesOutQty = (float)($salesOutMap[$ingId] ?? 0);
                $closingQty  = $closingMap[$ingId];
                $actualBase  = max(0, $openingQty + $purchaseQty - $salesOutQty - $closingQty);

                // HPP Aktual = Nilai SO Awal + Nilai Pembelian Masuk − Nilai Jual Keluar − Nilai SO Akhir.
                // JANGAN di-max(0): bahan yang stok akhirnya dinilai lebih tinggi dari
                // awal+beli (mis. sisa = batch baru lebih mahal) menghasilkan nilai
                // negatif yang HARUS saling menutup di total agar akumulasi tepat.
                $openingVal  = $openingValMap[$ingId]  ?? 0;
                $purchaseVal = $purchaseValMap[$ingId] ?? 0;
                $salesOutVal = $salesOutValMap[$ingId] ?? 0;
                $closingVal  = $closingValMap[$ingId]  ?? 0;
                $hppAktual   = $openingVal + $purchaseVal - $salesOutVal - $closingVal;
            }

            // Saklar per bahan (Master Data -> Bahan): "HPP Ideal ikut HPP Aktual".
            // Dipakai untuk bahan di luar resep menu (mis. Single/Double/Big Bag) yang
            // Ideal-nya selalu 0 sehingga memunculkan selisih semu.
            $ikutAktual = $hasActual && ($agg['ingredient']->ideal_follows_actual ?? false);
            $idealDelta = 0.0;
            if ($ikutAktual) {
                $idealBase  = $actualBase;
                $idealDelta = $hppAktual - $hppIdeal;   // utk dijumlahkan ke total HPP Ideal
                $hppIdeal   = $hppAktual;
            }

            return (object)[
                'ideal_ikut_aktual' => $ikutAktual,
                'ideal_delta'       => $idealDelta,
                'ingredient'   => $agg['ingredient'],
                'dus_size'     => $dusSize,
                'avg_price'    => $avgPrice,
                'ideal_base'   => $idealBase,
                'ideal_dus'    => $dusSize ? $idealBase / $dusSize : null,
                'hpp_ideal'    => $hppIdeal,
                'has_actual'   => $hasActual,
                'actual_base'  => $actualBase,
                'actual_dus'   => ($dusSize && $hasActual) ? $actualBase / $dusSize : null,
                'hpp_aktual'   => $hppAktual,
                'selisih_base' => $hasActual ? $idealBase - $actualBase : null,
                'selisih_dus'  => ($dusSize && $hasActual) ? ($idealBase - $actualBase) / $dusSize : null,
                'selisih_hpp'  => $hasActual ? $hppIdeal - $hppAktual : null,
                // % selisih terhadap HPP Aktual (semua baris dapat angka). Negatif = boros.
                'selisih_pct'  => ($hasActual && abs($hppAktual) > 0.0001)
                    ? (($hppIdeal - $hppAktual) / abs($hppAktual) * 100) : null,
            ];
        })->sortBy([
            // Urutan SAMA dengan Stok Opname: kategori (sort_order) lalu ingredient_id.
            fn($a, $b) => ($catOrder[$a->ingredient->category ?? ''] ?? 9999)
                      <=> ($catOrder[$b->ingredient->category ?? ''] ?? 9999),
            fn($a, $b) => ($a->ingredient->id ?? 0) <=> ($b->ingredient->id ?? 0),
        ])->values();

        // ── Summary ───────────────────────────────────────────────────────────
        // Bahan bersaklar "Ideal ikut Aktual" tidak ada di resep, jadi tidak terhitung
        // lewat menuRows. Selisihnya ditambahkan supaya total Ideal di kartu ringkasan
        // dan di tabel bahan tetap sama angkanya.
        $totalHppIdeal  = $menuRows->sum('hpp_ideal') + $ingredientRows->sum('ideal_delta');
        $totalHppAktual = $ingredientRows->where('has_actual', true)->sum('hpp_aktual');
        $hasAktualAny   = $ingredientRows->where('has_actual', true)->isNotEmpty();

        $summary = (object)[
            'omset'         => (float)$omset,
            'hpp_ideal'     => $totalHppIdeal,
            'hpp_aktual'    => $hasAktualAny ? $totalHppAktual : null,
            'pct_hpp_ideal' => $omset > 0 ? ($totalHppIdeal / $omset * 100) : null,
            'pct_hpp_aktual'=> ($hasAktualAny && $omset > 0) ? ($totalHppAktual / $omset * 100) : null,
            'margin_ideal'  => $omset > 0 ? (1 - $totalHppIdeal / $omset) * 100 : null,
            'margin_aktual' => ($hasAktualAny && $omset > 0) ? (1 - $totalHppAktual / $omset) * 100 : null,
            'selisih_hpp'   => $hasAktualAny ? $totalHppIdeal - $totalHppAktual : null,
            'has_opname'    => $soAkhir !== null,
        ];

        return compact('summary', 'menuRows', 'ingredientRows');
    }

    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $storeIds   = auth()->user()->accessibleStoreIds();
        $month      = (int)($request->month      ?? now()->month);
        $year       = (int)($request->year       ?? now()->year);
        $stores     = auth()->user()->accessibleStores();
        // Ikut toko yang dipilih di pemilih toko (topbar) bila halaman dibuka tanpa filter
        $storeId    = $request->store_id ?? session('active_store_id') ?? ($storeIds[0] ?? null);
        $periodType = $request->period_type      ?? 'end_month';

        $empty = fn() => view('sales.hpp', [
            'stores'     => $stores, 'month' => $month,
            'year'       => $year,   'storeId' => $storeId,
            'periodType' => $periodType,
            'menuRows' => collect(), 'ingredientRows' => collect(), 'summary' => null,
        ]);

        if (!$storeId || !in_array($storeId, $storeIds)) return $empty();

        // Bila ada SNAPSHOT terkunci untuk periode ini → tampilkan angka beku itu
        // (tidak dihitung ulang), supaya laporan periode lampau tidak berubah.
        $snapshot = \App\Models\HppSnapshot::with('lockedBy')
            ->where('store_id', $storeId)->where('month', $month)
            ->where('year', $year)->where('period_type', $periodType)->first();

        if ($snapshot) {
            $result = $this->restoreSnapshot($snapshot);
            return view('sales.hpp', array_merge($result, [
                'stores' => $stores, 'month' => $month,
                'year'   => $year,   'storeId' => $storeId,
                'periodType' => $periodType,
                'locked'   => true,
                'lockedAt' => $snapshot->created_at,
                'lockedBy' => $snapshot->lockedBy?->name,
            ]));
        }

        $result = $this->calcHppForStore((int)$storeId, $month, $year, $periodType);
        if (!$result) return $empty();

        return view('sales.hpp', array_merge($result, [
            'stores' => $stores, 'month' => $month,
            'year'   => $year,   'storeId' => $storeId,
            'periodType' => $periodType,
            'locked'   => false,
        ]));
    }

    // ── Sub-tab CONE & CUP: rekonsiliasi selisih pemakaian ──────────────────────
    // Selisih (Terpakai aktual − Terjual ideal) dipecah: Rusak (waste) + Overfill
    // (manual) + Tidak ada penjelasan. Cakupan: bahan kategori 'cup' + Ice Cream Cone.
    public function coneCup(Request $request)
    {
        $storeIds = auth()->user()->accessibleStoreIds();
        $month    = (int)($request->month ?? now()->month);
        $year     = (int)($request->year  ?? now()->year);
        $stores   = auth()->user()->accessibleStores();
        $storeId  = $request->store_id ?? session('active_store_id') ?? ($storeIds[0] ?? null);

        $rows = ($storeId && in_array($storeId, $storeIds))
            ? $this->buildConeCupRows((int) $storeId, $month, $year)
            : collect();

        return view('sales.hpp-cone-cup', compact('stores', 'storeId', 'month', 'year', 'rows'));
    }

    /** Baris rekonsiliasi Cone & Cup — dipakai halaman Cone & Cup dan laporan cetak. */
    private function buildConeCupRows(int $storeId, int $month, int $year)
    {
        // Bahan setengah jadi (mis. "Remahan Cone", dibuat dari cone) SENGAJA
        // dikecualikan — namanya kebetulan mengandung kata "cone", tapi ini bukan
        // bahan baku yang dipakai langsung di resep menu, jadi tidak relevan
        // untuk rekonsiliasi terjual vs terpakai di sini.
        $coneCup = \App\Models\Ingredient::where('type', '!=', 'semi_finished')
            ->where(fn($q) => $q->where('category', 'cup')->orWhere('name', 'like', '%cone%'))
            ->orderByRaw("category = 'cup' DESC")   // cup dulu, lalu cone
            ->orderBy('id')->get(['id', 'name', 'category']);

        $rows = collect();
        {
            // Terjual (ideal) & Terpakai (aktual) — reuse perhitungan HPP.
            $hpp     = $this->calcHppForStore((int)$storeId, $month, $year, 'end_month');
            $hppById = $hpp ? $hpp['ingredientRows']->keyBy(fn($r) => $r->ingredient->id) : collect();

            // Rusak = total qty waste bahan ini di periode.
            $monthStart = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
            $monthEnd   = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
            $rusakMap = \App\Models\WasteLogItem::query()
                ->join('waste_logs', 'waste_logs.id', '=', 'waste_log_items.waste_log_id')
                ->where('waste_logs.store_id', $storeId)
                ->whereBetween('waste_logs.waste_date', [$monthStart, $monthEnd])
                ->whereIn('waste_log_items.ingredient_id', $coneCup->pluck('id'))
                ->groupBy('waste_log_items.ingredient_id')
                ->selectRaw('waste_log_items.ingredient_id, SUM(waste_log_items.source_qty) as rusak')
                ->pluck('rusak', 'waste_log_items.ingredient_id');

            // Overfill manual + koreksi manual angka Rusak (null = ikut catatan waste).
            $manualRows   = \App\Models\ConeCupOverfill::where('store_id', $storeId)
                ->where('month', $month)->where('year', $year)
                ->get(['ingredient_id', 'qty', 'rusak_override', 'note']);
            $overfillMap  = $manualRows->pluck('qty', 'ingredient_id');
            $rusakOverride = $manualRows->whereNotNull('rusak_override')
                ->pluck('rusak_override', 'ingredient_id');
            $catatanMap   = $manualRows->pluck('note', 'ingredient_id');

            // Terjual khusus CONE: hanya dihitung dari menu kategori "Ice Cone"
            // (bukan dari menu lain yang resepnya kebetulan memakai cone, mis. Sundae).
            $coneTerjualMap = [];
            $coneIds = $coneCup->where('category', '!=', 'cup')->pluck('id')->all();
            if ($coneIds) {
                $iceConeMenuIds = \App\Models\Menu::whereHas('menuCategory',
                        fn($q) => $q->where('name', 'like', '%cone%'))
                    ->pluck('id')->all();
                $salesByMenu = MonthlySale::where('store_id', $storeId)
                    ->where('month', $month)->where('year', $year)
                    ->where('period_type', 'end_month')
                    ->whereIn('menu_id', $iceConeMenuIds ?: [0])
                    ->pluck('total_sold', 'menu_id');
                $coneRecipes = Recipe::whereIn('ingredient_id', $coneIds)
                    ->whereIn('menu_id', $iceConeMenuIds ?: [0])
                    ->whereNull('store_id')->get();
                foreach ($coneRecipes as $rec) {
                    $sold = (float) ($salesByMenu[$rec->menu_id] ?? 0);
                    $coneTerjualMap[$rec->ingredient_id] =
                        ($coneTerjualMap[$rec->ingredient_id] ?? 0) + $sold * (float) $rec->qty_usage;
                }
            }

            $rows = $coneCup->map(function ($ing) use ($hppById, $rusakMap, $overfillMap, $rusakOverride, $coneTerjualMap, $catatanMap) {
                $h        = $hppById[$ing->id] ?? null;
                $terjual  = array_key_exists($ing->id, $coneTerjualMap)
                    ? (float) $coneTerjualMap[$ing->id]
                    : ($h ? (float) $h->ideal_base : 0.0);
                $terpakai = ($h && $h->actual_base !== null) ? (float) $h->actual_base : 0.0;
                // Tanda: + = HEMAT (terpakai lebih kecil dari terjual), - = BOROS.
                $selisih  = $terjual - $terpakai;

                // Rusak: pakai koreksi manual bila ada, selain itu ikut catatan waste.
                $rusakWaste = (float) ($rusakMap[$ing->id] ?? 0);
                $isOverride = $rusakOverride->has($ing->id);
                $rusak      = $isOverride ? (float) $rusakOverride[$ing->id] : $rusakWaste;

                $overfill = (float) ($overfillMap[$ing->id] ?? 0);
                return (object)[
                    'ingredient'   => $ing,
                    'terjual'      => $terjual,
                    'terpakai'     => $terpakai,
                    'selisih'      => $selisih,
                    'rusak'        => $rusak,
                    'rusak_waste'  => $rusakWaste,   // angka asli dari catatan waste
                    'is_override'  => $isOverride,   // true = sudah dikoreksi manual
                    'catatan'      => $catatanMap[$ing->id] ?? null,   // isian bebas dari user
                    'overfill'     => $overfill,
                    // Tidak ada penjelasan = Terjual - Terpakai + Rusak - Overfill
                    //                        = Selisih + Rusak - Overfill (rumus tetap,
                    //   TIDAK tergantung tanda Selisih — Rusak selalu ditambah, Overfill
                    //   selalu dikurang).
                    //   selisih -1.407, rusak 1.409, overfill 0 -> +2
                    //   selisih     +6, rusak     0, overfill 8 -> -2
                    //   selisih +2.351, rusak     7, overfill 0 -> +2.358
                    'unexplained'  => $selisih + $rusak - $overfill,
                ];
            });
        }

        return $rows;
    }

    /**
     * Laporan Analisa HPP siap cetak (Ctrl+P → Simpan sebagai PDF).
     * Memuat: ringkasan, analisa per menu, analisa per bahan, dan rekonsiliasi Cone & Cup.
     */
    public function printReport(Request $request)
    {
        $storeIds   = auth()->user()->accessibleStoreIds();
        $storeId    = (int) ($request->store_id ?? ($storeIds[0] ?? 0));
        $month      = (int) ($request->month ?? now()->month);
        $year       = (int) ($request->year  ?? now()->year);
        $periodType = $request->period_type ?? 'end_month';

        abort_unless($storeId && in_array($storeId, $storeIds), 403);

        $result = $this->calcHppForStore($storeId, $month, $year, $periodType);
        if (!$result) {
            return redirect()->route('sales.hpp.index', [
                    'store_id' => $storeId, 'month' => $month, 'year' => $year, 'period_type' => $periodType,
                ])->with('error', 'Tidak ada data HPP untuk periode ini — belum ada penjualan, omset, maupun stok opname.');
        }

        return view('sales.hpp-print', [
            'store'       => \App\Models\Store::find($storeId),
            'month'       => $month,
            'year'        => $year,
            'periodType'  => $periodType,
            'periodLabel' => $periodType === 'mid_month' ? 'Tengah Bulan (1–15)' : 'Akhir Bulan (1–30/31)',
            'monthLabel'  => \Carbon\Carbon::create($year, $month)->isoFormat('MMMM Y'),
            'summary'     => $result['summary'],
            'menuRows'    => $result['menuRows'],
            'ingRows'     => $result['ingredientRows'],
            'coneCupRows' => $this->buildConeCupRows($storeId, $month, $year),
        ]);
    }

    // Simpan overfill manual (per toko/bulan/bahan).
    public function saveOverfill(Request $request)
    {
        $request->validate([
            'store_id'   => 'required|exists:stores,id',
            'month'      => 'required|integer|between:1,12',
            'year'       => 'required|integer|min:2020',
            'overfill'   => 'nullable|array',
            'overfill.*' => 'nullable|numeric|min:0',
            'rusak'      => 'nullable|array',
            'rusak.*'    => 'nullable|numeric|min:0',
            // Angka Rusak asli dari catatan waste — dipakai membandingkan apakah
            // user benar-benar mengubahnya. Kalau sama, override tidak disimpan
            // supaya baris itu tetap mengikuti catatan waste bila nanti berubah.
            'rusak_base'   => 'nullable|array',
            'rusak_base.*' => 'nullable|numeric',
            'catatan'      => 'nullable|array',
            'catatan.*'    => 'nullable|string|max:255',
        ]);
        abort_unless(in_array((int)$request->store_id, auth()->user()->accessibleStoreIds()), 403);

        $overfills = $request->overfill ?? [];
        $rusaks    = $request->rusak ?? [];
        $bases     = $request->rusak_base ?? [];
        $catatans  = $request->catatan ?? [];

        foreach (array_unique(array_merge(array_keys($overfills), array_keys($rusaks), array_keys($catatans))) as $ingId) {
            $qty = (float) ($overfills[$ingId] ?? 0);

            // Rusak: simpan override HANYA bila berbeda dari angka catatan waste.
            $rusakOverride = null;
            if (array_key_exists($ingId, $rusaks) && $rusaks[$ingId] !== null && $rusaks[$ingId] !== '') {
                $input = (float) $rusaks[$ingId];
                $base  = (float) ($bases[$ingId] ?? 0);
                if (abs($input - $base) > 0.001) $rusakOverride = $input;
            }

            $catatan = trim((string) ($catatans[$ingId] ?? ''));

            \App\Models\ConeCupOverfill::updateOrCreate(
                ['store_id' => (int)$request->store_id, 'ingredient_id' => (int)$ingId,
                 'month' => (int)$request->month, 'year' => (int)$request->year],
                ['qty' => $qty, 'rusak_override' => $rusakOverride,
                 'note' => $catatan !== '' ? $catatan : null, 'created_by' => auth()->id()]
            );
        }
        return back()->with('success', 'Data cone & cup tersimpan.');
    }

    // Ubah snapshot JSON kembali ke struktur yang dipakai view (Collection + objek).
    private function restoreSnapshot(\App\Models\HppSnapshot $snapshot): array
    {
        $d = json_decode($snapshot->payload);
        return [
            'summary'        => $d->summary ?? null,
            'menuRows'       => collect($d->menuRows ?? []),
            'ingredientRows' => collect($d->ingredientRows ?? []),
        ];
    }

    // ── Kunci HPP: simpan snapshot angka periode ini ─────────────────────────
    public function lock(Request $request)
    {
        $request->validate([
            'store_id'    => 'required|exists:stores,id',
            'month'       => 'required|integer|between:1,12',
            'year'        => 'required|integer|min:2020',
            'period_type' => 'required|in:mid_month,end_month',
        ]);
        $storeId = (int) $request->store_id;
        abort_unless(in_array($storeId, auth()->user()->accessibleStoreIds()), 403);

        $result = $this->calcHppForStore($storeId, (int)$request->month, (int)$request->year, $request->period_type);
        if (!$result) return back()->with('error', 'Tidak ada data HPP untuk dikunci.');

        \App\Models\HppSnapshot::updateOrCreate(
            ['store_id' => $storeId, 'month' => (int)$request->month,
             'year' => (int)$request->year, 'period_type' => $request->period_type],
            [
                'omset'      => $result['summary']->omset ?? 0,
                'hpp_ideal'  => $result['summary']->hpp_ideal ?? 0,
                'hpp_aktual' => $result['summary']->hpp_aktual,
                'payload'    => json_encode($result),
                'locked_by'  => auth()->id(),
            ]
        );

        return back()->with('success', 'HPP periode ini berhasil dikunci (snapshot tersimpan).');
    }

    // ── Buka kunci HPP (hanya Super Admin) ───────────────────────────────────
    public function unlock(Request $request)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403, 'Hanya Super Admin yang dapat membuka kunci HPP.');
        $request->validate([
            'store_id'    => 'required|exists:stores,id',
            'month'       => 'required|integer|between:1,12',
            'year'        => 'required|integer|min:2020',
            'period_type' => 'required|in:mid_month,end_month',
        ]);
        \App\Models\HppSnapshot::where('store_id', (int)$request->store_id)
            ->where('month', (int)$request->month)->where('year', (int)$request->year)
            ->where('period_type', $request->period_type)->delete();

        return back()->with('success', 'Kunci HPP dibuka. Angka kembali dihitung live.');
    }

    // ── Export HPP ───────────────────────────────────────────────────────────
    public function export(Request $request)
    {
        $storeIds   = auth()->user()->accessibleStoreIds();
        $storeId    = (int)($request->store_id ?? ($storeIds[0] ?? null));
        $month      = (int)($request->month ?? now()->month);
        $year       = (int)($request->year  ?? now()->year);
        $periodType = $request->period_type ?? 'end_month';

        if (!$storeId || !in_array($storeId, $storeIds)) abort(403);

        $result = $this->calcHppForStore($storeId, $month, $year, $periodType);
        if (!$result) {
            // Jangan tampilkan halaman 404 — kembalikan ke halaman HPP dengan pesan jelas.
            return redirect()->route('sales.hpp.index', [
                    'store_id' => $storeId, 'month' => $month, 'year' => $year, 'period_type' => $periodType,
                ])->with('error', 'Tidak ada data HPP untuk periode ini — belum ada penjualan, omset, maupun stok opname. Tidak ada yang bisa diekspor.');
        }

        $store     = \App\Models\Store::find($storeId);
        $label     = \Carbon\Carbon::create($year, $month)->isoFormat('MMMM Y');
        $period    = $periodType === 'mid_month' ? 'Tengah Bulan' : 'Akhir Bulan';
        $summary   = $result['summary'];
        $menuRows  = $result['menuRows'];
        $ingRows   = $result['ingredientRows'];

        $data = [];

        // Summary
        $data[] = ["LAPORAN HPP — {$store->name} — {$label} ({$period})"];
        $data[] = [];
        $data[] = ['Omset', $summary->omset];
        $data[] = ['HPP Ideal', $summary->hpp_ideal];
        $data[] = ['% HPP Ideal', $summary->pct_hpp_ideal ? number_format($summary->pct_hpp_ideal, 2, ',', '.') . '%' : '-'];
        $data[] = ['HPP Aktual', $summary->hpp_aktual ?? '-'];
        $data[] = ['% HPP Aktual', $summary->pct_hpp_aktual ? number_format($summary->pct_hpp_aktual, 2, ',', '.') . '%' : '-'];
        $data[] = ['Margin Ideal', $summary->margin_ideal ? number_format($summary->margin_ideal, 2, ',', '.') . '%' : '-'];
        $data[] = [];

        // Menu Rows
        $data[] = ['--- DETAIL PER MENU ---'];
        $data[] = ['Menu', 'Qty Terjual', 'HPP/Pcs', 'Total HPP Ideal'];
        foreach ($menuRows as $r) {
            $data[] = [$r->menu?->name ?? '-', $r->total_sold,
                number_format($r->hpp_per_pcs, 2, ',', '.'), $r->hpp_ideal];
        }
        $data[] = ['TOTAL', $menuRows->sum('total_sold'), '', $menuRows->sum('hpp_ideal')];
        $data[] = [];

        // Ingredient Rows
        $data[] = ['--- DETAIL PER BAHAN ---'];
        // Qty dilaporkan dalam DUS (sama seperti laporan cetak). Kolom satuan dasar
        // tetap disertakan sebagai rincian. Angka dikirim sebagai bilangan - bukan
        // teks berformat - supaya bisa langsung dijumlah/di-pivot di Excel.
        $data[] = [
            'Bahan', 'Satuan', 'Isi per Dus', 'Harga Avg/Base',
            'Pemakaian Ideal (Dus)',  'Pemakaian Ideal (base)',
            'HPP Ideal',
            'Pemakaian Aktual (Dus)', 'Pemakaian Aktual (base)',
            'HPP Aktual',
            'Selisih (Dus)', 'Selisih HPP',
        ];
        $dus = fn($v) => $v === null ? '-' : round((float) $v, 2);
        foreach ($ingRows as $r) {
            $data[] = [
                $r->ingredient->name,
                $r->ingredient->unit_base,
                $r->dus_size ?? '-',
                $r->avg_price,
                $dus($r->ideal_dus),
                round((float) $r->ideal_base, 3),
                $r->hpp_ideal,
                $r->has_actual ? $dus($r->actual_dus) : '-',
                $r->actual_base !== null ? round((float) $r->actual_base, 3) : '-',
                $r->hpp_aktual ?? '-',
                $r->has_actual ? $dus($r->selisih_dus) : '-',
                $r->selisih_hpp ?? '-',
            ];
        }

        return Excel::download(
            new ArrayExport($data),
            "hpp_{$store->name}_{$month}-{$year}.xlsx"
        );
    }

    // ── Perbandingan HPP multi-toko ───────────────────────────────────────────
    public function compare(Request $request)
    {
        $storeIds   = auth()->user()->accessibleStoreIds();
        $stores     = auth()->user()->accessibleStores();
        $month      = (int)($request->month      ?? now()->month);
        $year       = (int)($request->year       ?? now()->year);
        $periodType = $request->period_type      ?? 'end_month';

        // Validasi: hanya store yang boleh diakses user
        $selectedIds = collect($request->store_ids ?? [])
            ->map(fn($id) => (int)$id)
            ->filter(fn($id) => in_array($id, $storeIds))
            ->values()->all();

        $compareData = [];
        foreach ($selectedIds as $sid) {
            $result  = $this->calcHppForStore($sid, $month, $year, $periodType);
            $compareData[] = (object)[
                'store'   => $stores->firstWhere('id', $sid),
                'summary' => $result ? $result['summary'] : null,
                'menuRows'=> $result ? $result['menuRows'] : collect(),
            ];
        }

        return view('sales.hpp-compare', compact(
            'stores', 'selectedIds', 'month', 'year', 'periodType', 'compareData'
        ));
    }
}
