<?php
namespace App\Http\Controllers\Opname;

use App\Http\Controllers\Controller;
use App\Models\{Opname, OpnameItem, Ingredient, IngredientPackaging, MutationItem, Mutation, StockLedger, UnlockRequest};
use App\Services\{StockLedgerService, FifoService, MonthLockService};
use App\Exports\ArrayExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\{Fill, Font, Alignment, Border};

class OpnameController extends Controller
{
    public function index(Request $request)
    {
        $storeIds = auth()->user()->accessibleStoreIds();
        $query    = Opname::with(['store', 'performedBy'])
            ->whereIn('store_id', $storeIds);

        if ($request->store_id)    $query->where('store_id', $request->store_id);
        if ($request->period_type) $query->where('period_type', $request->period_type);
        if ($request->month)       $query->where('period_month', $request->month);
        if ($request->year)        $query->where('period_year', $request->year ?? now()->year);

        $opnames = $query->latest('opname_date')->paginate(20);
        $stores  = auth()->user()->accessibleStores();
        return view('opname.index', compact('opnames', 'stores'));
    }

    public function create()
    {
        $stores      = auth()->user()->accessibleStores();
        $ingredients = Ingredient::where('is_active', true)->where('type', '!=', 'semi_finished')
            ->leftJoin('ingredient_categories as ic', 'ingredients.category', '=', 'ic.name')
            ->orderByRaw('ic.sort_order IS NULL')->orderBy('ic.sort_order')->orderBy('ingredients.id')
            ->select('ingredients.*')->get();
        return view('opname.create', compact('stores', 'ingredients'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'store_id'    => 'required|exists:stores,id',
            'opname_date' => 'required|date',
            'period_type' => 'required|in:mid_month,end_month',
        ]);

        $date       = $request->opname_date;
        $carbonDate = \Carbon\Carbon::parse($date);
        $periodType = $request->period_type;
        $month      = $carbonDate->month;
        $year       = $carbonDate->year;

        // ── Lock check: bulan sudah lewat H+7? ─────────────────────────────────
        if (!auth()->user()->isSuperAdmin() && MonthLockService::isPastLock($month, $year)) {
            return back()->withInput()
                ->with('error', MonthLockService::lockMessage($month, $year));
        }

        // Cek duplikat opname
        $exists = Opname::where('store_id', $request->store_id)
            ->where('period_month', $month)
            ->where('period_year', $year)
            ->where('period_type', $periodType)
            ->exists();

        if ($exists) {
            return back()->withErrors(['error' => 'Opname periode ini sudah ada untuk toko ini.']);
        }

        $opname = null;
        DB::transaction(function () use ($request, $date, $month, $year, $periodType, &$opname) {
            $opname = Opname::create([
                'store_id'     => $request->store_id,
                'opname_date'  => $date,
                'period_month' => $month,
                'period_year'  => $year,
                'period_type'  => $periodType,
                'status'       => 'draft',
                'opname_mode'  => $request->input('opname_mode', 'bulanan'),
                'performed_by' => auth()->id(),
                'notes'        => $request->notes,
            ]);

            // ── Load per-packaging rows (sama seperti API systemQty) ────────────
            $allPackagings = IngredientPackaging::where('is_active', true)
                ->whereHas('ingredient', fn($q) => $q->where('type', '!=', 'semi_finished'))
                ->orderBy('ingredient_id')->orderBy('id')->get();
            $allIngredients = Ingredient::where('is_active', true)->where('type', '!=', 'semi_finished')
                ->leftJoin('ingredient_categories as ic', 'ingredients.category', '=', 'ic.name')
                ->orderByRaw('ic.sort_order IS NULL')->orderBy('ic.sort_order')->orderBy('ingredients.id')
                ->select('ingredients.*')->get();
            $ingIdsWithPkg  = $allPackagings->pluck('ingredient_id')->unique()->all();
            $pkgIds         = $allPackagings->pluck('id')->all();

            $remainingByPkg = MutationItem::whereHas('mutation', fn($q) =>
                    $q->where('destination_store_id', $request->store_id)->where('status', 'confirmed')
                      ->whereRaw('COALESCE(mutations.delivery_date, mutations.transaction_date) <= ?', [$date])
                )
                ->whereIn('packaging_id', $pkgIds)
                ->selectRaw('packaging_id, SUM(remaining_qty) as total_remaining')
                ->groupBy('packaging_id')
                ->pluck('total_remaining', 'packaging_id');

            $ingNoPkg = $allIngredients->filter(fn($i) => !in_array($i->id, $ingIdsWithPkg));
            $remainingByIng = collect();
            if ($ingNoPkg->isNotEmpty()) {
                $remainingByIng = MutationItem::whereHas('mutation', fn($q) =>
                        $q->where('destination_store_id', $request->store_id)->where('status', 'confirmed')
                          ->whereRaw('COALESCE(mutations.delivery_date, mutations.transaction_date) <= ?', [$date])
                    )
                    ->whereIn('ingredient_id', $ingNoPkg->pluck('id')->all())
                    ->whereNull('packaging_id')
                    ->selectRaw('ingredient_id, SUM(remaining_qty) as total_remaining')
                    ->groupBy('ingredient_id')
                    ->pluck('total_remaining', 'ingredient_id');
            }

            $submittedItems = $request->input('items', []);  // keyed by row_key

            $saveItem = function ($ingredientId, $packagingObj, $sysQty, $rowKey) use ($request, $opname, $submittedItems) {
                $data = $submittedItems[$rowKey] ?? [];

                $physicalCrate = isset($data['physical_crate']) && $data['physical_crate'] !== '' ? (int)$data['physical_crate'] : null;
                $physicalPack  = isset($data['physical_pack'])  && $data['physical_pack']  !== '' ? (int)$data['physical_pack']  : null;
                $physicalBase  = isset($data['physical_base'])  && $data['physical_base']  !== '' ? (float)$data['physical_base'] : null;

                if ($packagingObj && ($physicalCrate !== null || $physicalPack !== null || $physicalBase !== null)) {
                    $physicalQty = $packagingObj->convertToBase(
                        $physicalCrate ?? 0,
                        $physicalPack  ?? 0,
                        $physicalBase  ?? 0
                    );
                } elseif ($physicalBase !== null) {
                    $physicalQty = $physicalBase;
                } else {
                    $physicalQty = 0;
                }

                // Harga manual (per dus) → per base. Hanya tersimpan kalau user mengisi
                // (yaitu saat bahan belum punya harga dari data sebelumnya).
                $pricePerBase = null;
                $hargaDusInput = $this->rupiah($data['price_per_dus'] ?? null);
                if ($hargaDusInput !== null && $hargaDusInput > 0) {
                    $crateToBase = $packagingObj
                        ? (float)$packagingObj->crate_to_pack * (float)$packagingObj->pack_to_base : 0;
                    $pricePerBase = $crateToBase > 0
                        ? $hargaDusInput / $crateToBase
                        : $hargaDusInput;
                }

                OpnameItem::create([
                    'opname_id'      => $opname->id,
                    'ingredient_id'  => $ingredientId,
                    'packaging_id'   => $packagingObj?->id,
                    'system_qty'     => round($sysQty, 4),
                    'physical_qty'   => round($physicalQty, 4),
                    'physical_crate' => $physicalCrate,
                    'physical_pack'  => $physicalPack,
                    'physical_base'  => $physicalBase,
                    'variance'       => round($physicalQty - $sysQty, 4),
                    'price_per_base' => $pricePerBase,
                ]);
            };

            // Satu baris per kemasan
            foreach ($allPackagings as $pkg) {
                $sysQty = round((float)($remainingByPkg[$pkg->id] ?? 0), 4);
                $saveItem($pkg->ingredient_id, $pkg, $sysQty, 'pkg_' . $pkg->id);
            }
            // Bahan tanpa kemasan
            foreach ($ingNoPkg as $ing) {
                $sysQty = round((float)($remainingByIng[$ing->id] ?? 0), 4);
                $saveItem($ing->id, null, $sysQty, 'ing_' . $ing->id);
            }
            // Batch tambahan (harga berbeda) — key: pkg_X_bN atau ing_X_bN
            $pkgCache = $allPackagings->keyBy('id');
            foreach ($submittedItems as $rowKey => $data) {
                if (!preg_match('/^(pkg|ing)_\d+_b\d+$/', $rowKey)) continue;
                $ingId = isset($data['ingredient_id']) ? (int)$data['ingredient_id'] : null;
                $pkgId = isset($data['packaging_id'])  && $data['packaging_id'] !== '' ? (int)$data['packaging_id'] : null;
                if (!$ingId) continue;
                $pkgObj = $pkgId ? $pkgCache[$pkgId] ?? null : null;
                $saveItem($ingId, $pkgObj, 0, $rowKey);
            }
        });

        return redirect()->route('opname.opnames.show', $opname)
            ->with('success', 'Opname berhasil dibuat. Isi stok fisik lalu klik <strong>Approve</strong>.');
    }

    /**
     * API: ambil system_qty + harga rata-rata per bahan untuk store+date tertentu.
     * Dipakai oleh form buat opname (AJAX).
     */
    public function systemQty(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'date'     => 'required|date',
        ]);

        $storeId = $request->store_id;

        // Load semua kemasan aktif beserta bahan-nya
        $allPackagings  = IngredientPackaging::where('is_active', true)
            ->whereHas('ingredient', fn($q) => $q->where('type', '!=', 'semi_finished'))
            ->with('ingredient', 'supplier')
            ->orderBy('ingredient_id')->orderBy('id')
            ->get();

        $allIngredients = Ingredient::where('is_active', true)->where('type', '!=', 'semi_finished')
            ->leftJoin('ingredient_categories as ic', 'ingredients.category', '=', 'ic.name')
            ->orderByRaw('ic.sort_order IS NULL')->orderBy('ic.sort_order')->orderBy('ingredients.id')
            ->select('ingredients.*', 'ic.sort_order as cat_sort_order')->get();
        $catSortMap     = $allIngredients->pluck('cat_sort_order', 'id'); // ingredient_id → cat_sort_order
        $ingIdsWithPkg  = $allPackagings->pluck('ingredient_id')->unique()->all();
        $pkgIds         = $allPackagings->pluck('id')->all();

        $opnameDate = $request->date;

        // ── System qty per kemasan ─────────────────────────────────────────────
        $remainingByPkg = $pkgIds
            ? MutationItem::whereHas('mutation', fn($q) =>
                    $q->where('destination_store_id', $storeId)->where('status', 'confirmed')
                      ->whereRaw('COALESCE(mutations.delivery_date, mutations.transaction_date) <= ?', [$opnameDate])
                )
                ->whereIn('packaging_id', $pkgIds)
                ->selectRaw('packaging_id, SUM(remaining_qty) as total_remaining')
                ->groupBy('packaging_id')
                ->pluck('total_remaining', 'packaging_id')
            : collect();

        // ── Harga rata-rata per kemasan (weighted avg sisa batch = saldo stok) ─
        $batchesByPkg = $pkgIds
            ? MutationItem::whereHas('mutation', fn($q) =>
                    $q->where('destination_store_id', $storeId)
                      ->where('status', 'confirmed')
                      ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'opening_stock',
                                         'sale_internal', 'sale_external'])
                )
                ->whereIn('packaging_id', $pkgIds)
                ->where('remaining_qty', '>', 0)
                ->get(['packaging_id', 'ingredient_id', 'remaining_qty', 'price_per_base'])
                ->groupBy('packaging_id')
            : collect();

        // ── Bahan tanpa kemasan ────────────────────────────────────────────────
        $ingNoPkg = $allIngredients->filter(fn($i) => !in_array($i->id, $ingIdsWithPkg));

        $remainingByIng = $ingNoPkg->isNotEmpty()
            ? MutationItem::whereHas('mutation', fn($q) =>
                    $q->where('destination_store_id', $storeId)->where('status', 'confirmed')
                      ->whereRaw('COALESCE(mutations.delivery_date, mutations.transaction_date) <= ?', [$opnameDate])
                )
                ->whereIn('ingredient_id', $ingNoPkg->pluck('id')->all())
                ->whereNull('packaging_id')
                ->selectRaw('ingredient_id, SUM(remaining_qty) as total_remaining')
                ->groupBy('ingredient_id')
                ->pluck('total_remaining', 'ingredient_id')
            : collect();

        $batchesByIng = $ingNoPkg->isNotEmpty()
            ? MutationItem::whereHas('mutation', fn($q) =>
                    $q->where('destination_store_id', $storeId)
                      ->where('status', 'confirmed')
                      ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'opening_stock',
                                         'sale_internal', 'sale_external'])
                )
                ->whereIn('ingredient_id', $ingNoPkg->pluck('id')->all())
                ->whereNull('packaging_id')
                ->where('remaining_qty', '>', 0)
                ->get(['ingredient_id', 'remaining_qty', 'price_per_base'])
                ->groupBy('ingredient_id')
            : collect();

        // ── Bangun result ──────────────────────────────────────────────────────
        // Tandai bahan yang punya > 1 kemasan supaya JS bisa tampilkan sub-label
        $pkgCountByIng = $allPackagings->groupBy('ingredient_id')->map->count();

        // Harga cadangan untuk bahan yang stoknya habis (tidak ada batch tersisa),
        // supaya kolom harga di halaman input tidak kosong dan harus diketik manual.
        $idsSemua = $allPackagings->pluck('ingredient_id')
            ->merge($ingNoPkg->pluck('id'))->unique()->values()->all();
        $cadangan = $this->hargaCadangan($storeId, $idsSemua);

        $result = [];

        // Satu entry per kemasan
        foreach ($allPackagings as $pkg) {
            $ing       = $pkg->ingredient;
            $isMulti   = ($pkgCountByIng[$ing->id] ?? 1) > 1;
            $sysQty    = round((float)($remainingByPkg[$pkg->id] ?? 0), 4);
            $group     = $batchesByPkg[$pkg->id] ?? collect();
            $totalQty  = $group->sum('remaining_qty');
            $totalVal  = $group->sum(fn($b) => $b->remaining_qty * $b->price_per_base);
            $priceBase = $totalQty > 0 ? round($totalVal / $totalQty, 6)
                                       : round((float)($cadangan[$ing->id] ?? 0), 6);
            $ctrPack   = (int)$pkg->crate_to_pack;
            $packBase  = (float)$pkg->pack_to_base;
            $priceDus  = ($ctrPack && $packBase) ? (int)round($priceBase * $ctrPack * $packBase) : 0;

            // Cantumkan nama supplier HANYA jika dari supplier lokal (bukan Zhisheng/pusat)
            $supLabel  = ($pkg->supplier && $pkg->supplier->type !== 'zhisheng') ? $pkg->supplier->name : null;
            $labelParts = array_filter([$isMulti ? '@ ' . $ctrPack . ' pack' : null, $supLabel]);

            $result[] = [
                'row_key'        => 'pkg_' . $pkg->id,
                'ingredient_id'  => $ing->id,
                'cat_sort_order' => $catSortMap[$ing->id] ?? 9999,
                'name'           => $ing->name,
                'unit_base'      => $ing->unit_base,
                'packaging_id'   => $pkg->id,
                'pkg_label'      => $labelParts ? implode(' · ', $labelParts) : null,
                'supplier'       => $pkg->supplier?->name,   // dipakai kolom Supplier di template
                'system_qty'     => $sysQty,
                'crate_to_pack'  => $ctrPack,
                'pack_to_base'   => $packBase,
                'price_per_base' => $priceBase,
                'price_per_dus'  => $priceDus,
            ];
        }

        // Bahan tanpa kemasan
        foreach ($ingNoPkg->sortBy('id') as $ing) {
            $sysQty    = round((float)($remainingByIng[$ing->id] ?? 0), 4);
            $group     = $batchesByIng[$ing->id] ?? collect();
            $totalQty  = $group->sum('remaining_qty');
            $totalVal  = $group->sum(fn($b) => $b->remaining_qty * $b->price_per_base);
            $priceBase = $totalQty > 0 ? round($totalVal / $totalQty, 6)
                                       : round((float)($cadangan[$ing->id] ?? 0), 6);

            $result[] = [
                'row_key'        => 'ing_' . $ing->id,
                'ingredient_id'  => $ing->id,
                'cat_sort_order' => $catSortMap[$ing->id] ?? 9999,
                'name'           => $ing->name,
                'unit_base'      => $ing->unit_base,
                'packaging_id'   => null,
                'pkg_label'      => null,
                'supplier'       => null,
                'system_qty'     => $sysQty,
                'crate_to_pack'  => 0,
                'pack_to_base'   => 0,
                'price_per_base' => $priceBase,
                'price_per_dus'  => 0,
            ];
        }

        // Urutkan: kategori (sort_order) → ingredient_id → row_key (untuk multi-kemasan)
        usort($result, fn($a, $b) =>
            ($a['cat_sort_order'] ?? 9999) <=> ($b['cat_sort_order'] ?? 9999)
            ?: $a['ingredient_id'] <=> $b['ingredient_id']
            ?: $a['row_key'] <=> $b['row_key']
        );

        return response()->json($result);
    }

    public function show(Opname $opname)
    {
        $opname->load(['store', 'items.ingredient', 'items.packaging.supplier', 'performedBy', 'approvedBy']);
        // Draft bulanan: sinkronkan stok sistem dari transaksi terkini (mis. setelah
        // mutasi diperbaiki) supaya fisik vs sistem tetap akurat.
        $this->refreshDraftSystemQty($opname);
        $opname->setRelation('items', $this->sortedItems($opname));
        $priceMap   = $this->displayPriceMap($opname);
        $fifoPrice  = $this->fifoEffectivePrice($opname);
        $lockData   = $this->buildLockData($opname);
        return view('opname.show', compact('opname', 'priceMap', 'fifoPrice') + $lockData);
    }

    public function edit(Opname $opname)
    {
        abort_if($opname->status === 'approved', 403, 'Opname yang sudah approved tidak bisa diedit.');
        $opname->load(['items.ingredient', 'items.packaging.supplier', 'store', 'performedBy', 'approvedBy']);
        $this->refreshDraftSystemQty($opname);
        $opname->setRelation('items', $this->sortedItems($opname));
        $priceMap = $this->displayPriceMap($opname);
        $fifoPrice = $this->fifoEffectivePrice($opname);
        $lockData = $this->buildLockData($opname);
        return view('opname.show', compact('opname', 'priceMap', 'fifoPrice') + $lockData);
    }

    // Harga untuk tampilan: opname approved → pakai harga beku (price_per_base item);
    // draft → harga rata-rata sisa batch terkini (live).
    /**
     * Harga cadangan saat stok habis (tidak ada batch FIFO tersisa), berurutan:
     *   1) harga pembelian TERAKHIR di toko ini
     *   2) harga dari opname sebelumnya yang sudah approved
     * Tanpa ini kolom harga tampil kosong dan harus diketik manual tiap kali.
     */
    private function hargaCadangan(int $storeId, array $ingIds, ?int $kecualiOpnameId = null): array
    {
        if (empty($ingIds)) return [];

        $beliTerakhir = MutationItem::query()
            ->join('mutations', 'mutations.id', '=', 'mutation_items.mutation_id')
            ->where('mutations.destination_store_id', $storeId)
            ->where('mutations.status', 'confirmed')
            ->whereIn('mutations.type', ['purchase_zhisheng', 'purchase_supplier', 'opening_stock', 'sale_internal'])
            ->whereIn('mutation_items.ingredient_id', $ingIds)
            ->where('mutation_items.price_per_base', '>', 0)
            ->where('mutation_items.total_in_base', '>', 0)   // jaga-jaga: abaikan batch qty 0
            ->orderByDesc(\DB::raw('COALESCE(mutations.delivery_date, mutations.transaction_date)'))
            ->orderByDesc('mutation_items.id')
            ->get(['mutation_items.ingredient_id', 'mutation_items.price_per_base'])
            ->groupBy('ingredient_id')->map(fn($g) => (float) $g->first()->price_per_base);

        $opnameLalu = OpnameItem::query()
            ->join('opnames', 'opnames.id', '=', 'opname_items.opname_id')
            ->where('opnames.store_id', $storeId)
            ->where('opnames.status', 'approved')
            ->when($kecualiOpnameId, fn($q) => $q->where('opnames.id', '!=', $kecualiOpnameId))
            ->whereIn('opname_items.ingredient_id', $ingIds)
            ->where('opname_items.price_per_base', '>', 0)
            // Harga hanya dianggap referensi kalau stok fisiknya BENAR-BENAR ada saat itu.
            // Tanpa ini: harga yang diketik untuk item 0 dus (tak pernah jadi batch nyata)
            // ikut jadi "harga terakhir" dan menimpa harga Rp 0 yang justru sudah benar
            // (mis. barang gratis/donasi yang datang belakangan).
            ->where('opname_items.physical_qty', '>', 0)
            ->orderByDesc('opnames.opname_date')
            ->orderByDesc('opnames.id')
            ->get(['opname_items.ingredient_id', 'opname_items.price_per_base'])
            ->groupBy('ingredient_id')->map(fn($g) => (float) $g->first()->price_per_base);

        $map = [];
        foreach ($ingIds as $id) {
            $map[$id] = (float) ($beliTerakhir[$id] ?? $opnameLalu[$id] ?? 0);
        }
        return $map;
    }

    /**
     * Baca angka rupiah dari input. Titik = pemisah ribuan, koma = desimal.
     * JS di halaman sudah melucuti titik sebelum submit, tapi kalau JS gagal
     * jalan, "750.000" akan terbaca PHP sebagai 750 - harga jadi salah total.
     * Karena itu dibersihkan lagi di sini.
     */
    private function rupiah($v): ?float
    {
        if ($v === null || $v === '' || !is_scalar($v)) return null;
        $bersih = str_replace(',', '.', str_replace('.', '', trim((string) $v)));
        return is_numeric($bersih) ? (float) $bersih : null;
    }

    private function displayPriceMap(Opname $opname): array
    {
        if ($opname->status === 'approved') {
            $map = [];
            foreach ($opname->items as $item) {
                if (($item->price_per_base ?? 0) > 0) {
                    $map[$item->ingredient_id] = (float) $item->price_per_base;
                }
            }
            // Lengkapi bahan yg belum punya harga beku dengan harga live
            $live = $this->buildPriceMap($opname);
            return $map + $live;
        }
        return $this->buildPriceMap($opname);
    }

    // Harga efektif per item via FIFO BERLAPIS: stok fisik dinilai dari batch
    // TERBARU dulu (karena FIFO — yang lama keluar duluan, sisa = yang baru).
    // Hasil: price_per_base efektif = nilai_fifo / physical_qty. Lebih akurat
    // daripada weighted (yang mencampur semua batch tanpa lihat berapa yg tersisa).
    // Berlaku untuk opname BULANAN draft. (stok_awal pakai harga per-batch input;
    // approved pakai harga beku.)
    private function fifoEffectivePrice(Opname $opname): array
    {
        if ($opname->status === 'approved' || $opname->opname_mode === 'stok_awal') return [];

        $map = [];
        foreach ($opname->items as $item) {
            $phys = (float) $item->physical_qty;
            if ($phys <= 0) continue;

            // Urut by TANGGAL transaksi (bukan id) — FIFO sejati. Stok yang tersisa
            // dinilai dari batch TANGGAL TERBARU dulu (yang lama sudah keluar duluan).
            $batches = MutationItem::query()
                ->join('mutations', 'mutations.id', '=', 'mutation_items.mutation_id')
                ->where('mutations.destination_store_id', $opname->store_id)
                ->where('mutations.status', 'confirmed')
                ->where('mutation_items.ingredient_id', $item->ingredient_id)
                ->when($item->packaging_id,
                    fn($q) => $q->where('mutation_items.packaging_id', $item->packaging_id),
                    fn($q) => $q->whereNull('mutation_items.packaging_id'))
                ->where('mutation_items.remaining_qty', '>', 0)
                ->orderByDesc(\DB::raw('COALESCE(mutations.delivery_date, mutations.transaction_date)'))
                ->orderByDesc('mutation_items.id')
                ->get(['mutation_items.remaining_qty', 'mutation_items.price_per_base']);

            if ($batches->isEmpty()) continue;

            $left = $phys; $val = 0.0; $lastPpb = (float) $batches->first()->price_per_base;
            foreach ($batches as $b) {
                if ($left <= 0) break;
                $take = min($left, (float) $b->remaining_qty);
                $val += $take * (float) $b->price_per_base;
                $left -= $take;
                $lastPpb = (float) $b->price_per_base;
            }
            if ($left > 0) $val += $left * $lastPpb; // fisik > sistem → kelebihan pakai harga terbaru

            $map[$item->id] = $phys > 0 ? $val / $phys : 0;
        }
        return $map;
    }

    private function sortedItems(Opname $opname)
    {
        $catOrder = \App\Models\IngredientCategory::pluck('sort_order', 'name');
        return $opname->items->sortBy([
            fn($a, $b) => ($catOrder[$a->ingredient->category ?? ''] ?? 9999)
                      <=> ($catOrder[$b->ingredient->category ?? ''] ?? 9999),
            fn($a, $b) => $a->ingredient_id <=> $b->ingredient_id,
            fn($a, $b) => $a->id <=> $b->id,
        ])->values();
    }

    private function buildLockData(Opname $opname): array
    {
        $month = $opname->period_month;
        $year  = $opname->period_year;
        return [
            'isLocked'   => MonthLockService::isLocked('opname', $opname->id, $month, $year),
            'isPastLock' => MonthLockService::isPastLock($month, $year),
            'hasPending' => UnlockRequest::hasPendingRequest('opname', $opname->id),
            'hasUnlock'  => UnlockRequest::hasApprovedUnlock('opname', $opname->id),
            'lockMonth'  => $month,
            'lockYear'   => $year,
        ];
    }

    /**
     * Harga rata-rata tertimbang per ingredient dari sisa batch FIFO —
     * sama persis dengan cara Saldo Stok menghitung harga.
     * Σ(remaining_qty × price_per_base) / Σ(remaining_qty)
     */
    private function buildPriceMap(Opname $opname): array
    {
        $ingIds  = $opname->items->pluck('ingredient_id')->unique()->all();
        $batches = MutationItem::whereHas('mutation', fn($q) =>
                $q->where('destination_store_id', $opname->store_id)
                  ->where('status', 'confirmed')
                  ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'opening_stock', 'sale_internal', 'sale_external'])
            )
            ->whereIn('ingredient_id', $ingIds)
            ->where('remaining_qty', '>', 0)
            ->get(['ingredient_id', 'remaining_qty', 'price_per_base'])
            ->groupBy('ingredient_id');

        $map = [];
        $perluCadangan = [];
        foreach ($ingIds as $id) {
            $group    = $batches[$id] ?? collect();
            $totalQty = $group->sum('remaining_qty');
            $totalVal = $group->sum(fn($b) => $b->remaining_qty * $b->price_per_base);
            $map[$id] = $totalQty > 0 ? $totalVal / $totalQty : 0;
            // Cadangan HANYA saat stok BENAR-BENAR tidak ada (totalQty 0), bukan saat
            // stok memang ada tapi harganya kebetulan 0 (mis. barang gratis/donasi).
            // Sebelumnya dicek dari $map[$id] <= 0, jadi stok nyata ber-harga Rp 0
            // ikut ditimpa cadangan (harga lama) — padahal Rp 0 itu SUDAH BENAR.
            if ($totalQty <= 0) $perluCadangan[] = $id;
        }

        // Stok habis (0 dus 0 pack) -> tidak ada batch tersisa sehingga harga
        // rata-rata jadi 0 dan kolom harga tampil kosong. Isi dengan cadangan.
        if ($perluCadangan) {
            $map = array_replace($map, $this->hargaCadangan($opname->store_id, $perluCadangan, $opname->id));
        }

        return $map;
    }

    // Pesan kunci HPP bila periode opname ini sudah di-snapshot. null = tidak terkunci.
    private function hppLockMsg(Opname $opname): ?string
    {
        return \App\Models\HppSnapshot::isPeriodLocked(
                $opname->store_id, $opname->period_month, $opname->period_year, $opname->period_type)
            ? \App\Models\HppSnapshot::lockMessageFor($opname->store_id, $opname->period_month, $opname->period_year)
            : null;
    }

    public function update(Request $request, Opname $opname)
    {
        abort_if($opname->status === 'approved', 403);
        if ($m = $this->hppLockMsg($opname)) return back()->with('error', $m);

        // ── Lock check ──────────────────────────────────────────────────────────
        if (MonthLockService::isLocked('opname', $opname->id, $opname->period_month, $opname->period_year)) {
            return back()->with('error', MonthLockService::lockMessage($opname->period_month, $opname->period_year));
        }

        DB::transaction(function () use ($request, $opname) {
            foreach ($request->items as $itemId => $data) {
                $item = $opname->items()->find($itemId);
                if (!$item) continue;

                // Konversi fisik ke base unit (CTN + Pack + Pcs/Gr)
                $physicalQty = 0;
                if ($item->packaging_id && $item->packaging) {
                    $physicalQty = $item->packaging->convertToBase(
                        (int)($data['physical_crate'] ?? 0),
                        (int)($data['physical_pack']  ?? 0),
                        (float)($data['physical_base'] ?? 0)
                    );
                } else {
                    $physicalQty = (float)($data['physical_base'] ?? 0);
                }

                // Bulatkan ke 4 desimal untuk hindari floating-point noise
                $physicalQty = round($physicalQty, 4);
                $systemQty   = round($item->system_qty, 4);
                $variance    = round($physicalQty - $systemQty, 4);

                $updateData = [
                    'physical_crate' => $data['physical_crate'] ?? null,
                    'physical_pack'  => $data['physical_pack']  ?? null,
                    'physical_base'  => $data['physical_base']  ?? null,
                    'physical_qty'   => $physicalQty,
                    'variance'       => $variance,
                ];

                // Simpan harga/dus → price_per_base. Angka apa pun DIISI (termasuk 0 = gratis
                // yang disengaja). Field DIKOSONGKAN → biarkan NULL (nanti pakai saran harga).
                $hargaDus = $this->rupiah($data['price_per_dus'] ?? null);
                if ($hargaDus !== null) {
                    $pkg         = $item->packaging;
                    $crateToBase = $pkg ? (float)$pkg->crate_to_pack * (float)$pkg->pack_to_base : 0;
                    $updateData['price_per_base'] = $crateToBase > 0
                        ? $hargaDus / $crateToBase
                        : $hargaDus;
                }

                $item->update($updateData);
            }
        });

        return back()->with('success', 'Stok fisik disimpan.');
    }

    public function addBatch(Request $request, Opname $opname)
    {
        abort_if($opname->status === 'approved', 403);
        abort_if($opname->opname_mode !== 'stok_awal', 403, 'Hanya mode stok_awal yang mendukung multi-batch.');

        $request->validate([
            'ingredient_id' => 'required|exists:ingredients,id',
            'packaging_id'  => 'nullable|exists:ingredient_packagings,id',
        ]);

        $item = OpnameItem::create([
            'opname_id'     => $opname->id,
            'ingredient_id' => $request->ingredient_id,
            'packaging_id'  => $request->packaging_id ?: null,
            'system_qty'    => 0,
            'physical_qty'  => 0,
            'variance'      => 0,
        ]);

        // AJAX (tanpa reload): balas data baris baru agar bisa disisipkan di tempat.
        if ($request->expectsJson() || $request->ajax()) {
            $item->load('ingredient', 'packaging.supplier');
            $pkg = $item->packaging;
            $ctr = $pkg ? (int) $pkg->crate_to_pack : 0;
            $batchNum = $opname->items()
                ->where('ingredient_id', $item->ingredient_id)
                ->where('packaging_id', $item->packaging_id)->count();
            $multi = $opname->items()
                ->where('ingredient_id', $item->ingredient_id)
                ->distinct('packaging_id')->count('packaging_id') > 1;
            $sup = ($pkg && $pkg->supplier && $pkg->supplier->type !== 'zhisheng') ? $pkg->supplier->name : null;
            $label = collect([($multi && $ctr) ? '@ ' . $ctr . ' pack' : null, $sup, 'Batch ' . $batchNum])
                ->filter()->implode(' · ');

            return response()->json([
                'id'            => $item->id,
                'ingredient_id' => $item->ingredient_id,
                'packaging_id'  => $item->packaging_id,
                'ingredient'    => $item->ingredient->name,
                'label'         => $label,
                'crate_to_pack' => $ctr,
                'pack_to_base'  => $pkg ? (float) $pkg->pack_to_base : 1,
            ]);
        }

        return back()->with('success', 'Baris batch baru ditambahkan.');
    }

    public function destroyItem(Opname $opname, OpnameItem $item)
    {
        abort_if($opname->status === 'approved', 403);
        abort_if($item->opname_id !== $opname->id, 404);

        $count = $opname->items()
            ->where('ingredient_id', $item->ingredient_id)
            ->where('packaging_id', $item->packaging_id)
            ->count();

        abort_if($count <= 1, 422, 'Tidak bisa menghapus baris terakhir untuk bahan ini.');

        $item->delete();

        if (request()->expectsJson() || request()->ajax()) {
            return response()->json(['ok' => true]);
        }
        return back()->with('success', 'Baris batch dihapus.');
    }

    /**
     * Hitung ulang STOK SISTEM + variance untuk opname DRAFT, dari data transaksi
     * terkini (mutasi/dll). Dipakai supaya saat user memperbaiki mutasi setelah
     * membuat draft opname, stok sistem di draft ikut ter-update → fisik vs sistem
     * tetap sinkron. Tidak menyentuh opname approved (sudah terkunci).
     * Mode stok_awal dilewati (system_qty memang 0 / batch manual).
     */
    private function refreshDraftSystemQty(Opname $opname): void
    {
        if ($opname->status !== 'draft' || $opname->opname_mode === 'stok_awal') return;

        $req  = new \Illuminate\Http\Request([
            'store_id' => $opname->store_id,
            'date'     => $opname->opname_date->toDateString(),
        ]);
        $rows = collect(json_decode($this->systemQty($req)->getContent(), true));
        if ($rows->isEmpty()) return;

        $map = $rows->keyBy(fn($r) => $r['ingredient_id'] . '_' . ($r['packaging_id'] ?? ''));

        foreach ($opname->items as $item) {
            $key = $item->ingredient_id . '_' . ($item->packaging_id ?? '');
            $sys = isset($map[$key]) ? round((float) $map[$key]['system_qty'], 4) : 0.0;
            $var = round((float) $item->physical_qty - $sys, 4);

            if (abs($sys - (float) $item->system_qty) > 0.0001
                || abs($var - (float) $item->variance) > 0.0001) {
                $item->update(['system_qty' => $sys, 'variance' => $var]);
            }
        }
        $opname->load('items');
    }

    /**
     * Tambahkan baris untuk bahan/kemasan AKTIF yang belum ada di opname draft ini
     * (mis. bahan baru dibuat setelah opname dibuat). Mengembalikan jumlah baris baru.
     */
    private function syncMissingItems(Opname $opname): int
    {
        if ($opname->status !== 'draft') return 0;

        $req  = new \Illuminate\Http\Request([
            'store_id' => $opname->store_id,
            'date'     => $opname->opname_date->toDateString(),
        ]);
        $rows = collect(json_decode($this->systemQty($req)->getContent(), true));
        if ($rows->isEmpty()) return 0;

        $existing = $opname->items
            ->map(fn($i) => $i->ingredient_id . '_' . ($i->packaging_id ?? ''))
            ->flip();

        $added = 0;
        foreach ($rows as $r) {
            $key = $r['ingredient_id'] . '_' . ($r['packaging_id'] ?? '');
            if (isset($existing[$key])) continue;

            $sys = round((float) ($r['system_qty'] ?? 0), 4);
            OpnameItem::create([
                'opname_id'     => $opname->id,
                'ingredient_id' => $r['ingredient_id'],
                'packaging_id'  => $r['packaging_id'] ?? null,
                'system_qty'    => $sys,
                'physical_qty'  => 0,
                'variance'      => round(0 - $sys, 4),
            ]);
            $added++;
        }

        if ($added) $opname->load('items');
        return $added;
    }

     /*
     * Hitung ulang STOK SISTEM + variance dari data terkini (tombol manual).
     */
    public function recalculate(Opname $opname)
    {
        abort_if($opname->status === 'approved', 403);

        if (MonthLockService::isLocked('opname', $opname->id, $opname->period_month, $opname->period_year)) {
            return back()->with('error', MonthLockService::lockMessage($opname->period_month, $opname->period_year));
        }

        // Tambahkan bahan/kemasan baru yang belum terdaftar di opname ini.
        $added = $this->syncMissingItems($opname);
        $extra = $added ? " {$added} bahan baru ditambahkan." : '';

        // Mode bulanan: refresh stok sistem dari transaksi terkini lalu hitung variance.
        if ($opname->opname_mode !== 'stok_awal') {
            $this->refreshDraftSystemQty($opname);
            return back()->with('success', 'Stok sistem & variance disinkronkan dari data terkini.' . $extra);
        }

        // Mode stok_awal: cukup hitung ulang variance.
        foreach ($opname->items as $item) {
            $variance = round($item->physical_qty, 4) - round($item->system_qty, 4);
            $item->update(['variance' => round($variance, 4)]);
        }
        return back()->with('success', 'Variance berhasil dihitung ulang.' . $extra);
    }

    /**
     * Alasan (string) bila ada mutasi/pencatatan harian terkonfirmasi SETELAH tanggal
     * opname — transaksi tsb bergantung pada stok opname ini, jadi opname tidak boleh
     * dihapus/dibatalkan. NULL bila aman.
     */
    private function transactionsAfterReason(Opname $opname): ?string
    {
        $afterDate = \Carbon\Carbon::parse($opname->opname_date)->toDateString();

        $hasMutation = \App\Models\Mutation::where(function ($q) use ($opname) {
                $q->where('destination_store_id', $opname->store_id)
                  ->orWhere('source_store_id', $opname->store_id);
            })
            ->where('status', 'confirmed')
            ->whereNotIn('type', ['opening_stock'])
            ->where(\DB::raw('COALESCE(delivery_date, transaction_date)'), '>', $afterDate)
            ->exists();

        $hasDaily = \App\Models\DailyUsage::where('store_id', $opname->store_id)
            ->where('qty_pack', '>', 0)
            ->where('usage_date', '>', $afterDate)
            ->whereExists(fn($q) => $q->from('daily_confirmations')
                ->whereColumn('daily_confirmations.store_id', 'daily_usages.store_id')
                ->whereColumn('daily_confirmations.confirmation_date', 'daily_usages.usage_date'))
            ->exists();

        if (!$hasMutation && !$hasDaily) return null;

        $reasons = array_filter([
            $hasMutation ? 'sudah ada mutasi' : null,
            $hasDaily    ? 'sudah ada pencatatan harian terkonfirmasi' : null,
        ]);
        return implode(' dan ', $reasons)
            . ' setelah tanggal ' . \Carbon\Carbon::parse($opname->opname_date)->isoFormat('D MMMM Y') . '.';
    }

    /**
     * Batalkan approve: kembalikan opname ke draft & balikkan efek FIFO-nya,
     * supaya bisa diedit lalu di-approve ulang. Hanya bila belum ada transaksi
     * setelahnya (dijaga di pemanggil).
     */
    public function unapprove(Opname $opname)
    {
        abort_unless(auth()->user()->isSuperAdmin() || auth()->user()->isAdminArea(), 403);
        abort_if($opname->status !== 'approved', 422, 'Hanya opname approved yang bisa dibatalkan.');

        if ($m = $this->hppLockMsg($opname)) {
            return redirect()->route('opname.opnames.show', $opname)->with('error', $m);
        }
        if (MonthLockService::isLocked('opname', $opname->id, $opname->period_month, $opname->period_year)) {
            return redirect()->route('opname.opnames.show', $opname)
                ->with('error', MonthLockService::lockMessage($opname->period_month, $opname->period_year));
        }
        if ($reason = $this->transactionsAfterReason($opname)) {
            return redirect()->route('opname.opnames.show', $opname)
                ->with('error', 'Tidak dapat membatalkan approve karena ' . $reason);
        }

        DB::transaction(function () use ($opname) {
            $affectedIngIds = $opname->items->pluck('ingredient_id')->unique()->all();
            $storeId        = $opname->store_id;

            // Draft dulu supaya variance-nya tidak lagi ikut dihitung saat recalculate.
            $opname->update(['status' => 'draft']);

            StockLedger::where('reference_type', 'Opname')->where('reference_id', $opname->id)->delete();
            Mutation::where('type', 'opening_stock')
                ->where('notes', 'Auto-generated dari Opname #' . $opname->id)
                ->each(function ($m) { $m->items()->delete(); $m->delete(); });

            foreach ($affectedIngIds as $ingId) {
                FifoService::recalculate($storeId, $ingId);
            }
        });

        // Tambahkan bahan/kemasan baru yang dibuat setelah opname ini dibuat.
        $opname->refresh()->load('items');
        $added = $this->syncMissingItems($opname);
        $extra = $added ? " {$added} bahan baru ikut ditambahkan." : '';

        return redirect()->route('opname.opnames.edit', $opname)
            ->with('success', 'Approve dibatalkan. Opname kembali ke draft & bisa diedit — jangan lupa Approve lagi setelah selesai.' . $extra);
    }

    public function destroy(Opname $opname)
    {
        // Approved boleh dihapus oleh Admin Area & Super Admin (viewer diblok middleware).
        if ($opname->status === 'approved'
            && !auth()->user()->isSuperAdmin() && !auth()->user()->isAdminArea()) {
            abort(403, 'Opname yang sudah disetujui hanya dapat dihapus oleh Admin Area atau Super Admin.');
        }

        // Terkunci oleh snapshot HPP? Tidak boleh dihapus (snapshot bergantung padanya).
        if ($m = $this->hppLockMsg($opname)) {
            return redirect()->route('opname.opnames.show', $opname)->with('error', $m);
        }

        if (MonthLockService::isLocked('opname', $opname->id, $opname->period_month, $opname->period_year)) {
            return redirect()->route('opname.opnames.show', $opname)
                ->with('error', MonthLockService::lockMessage($opname->period_month, $opname->period_year));
        }

        // Tidak bisa hapus jika sudah ada mutasi/pencatatan harian SETELAH tanggal opname.
        if ($reason = $this->transactionsAfterReason($opname)) {
            return redirect()->route('opname.opnames.show', $opname)
                ->with('error', 'Opname tidak dapat dihapus karena ' . $reason);
        }

        DB::transaction(function () use ($opname) {
            if ($opname->status === 'approved') {
                // ── Kumpulkan ingredient yang terdampak ────────────────────────
                $affectedIngIds = $opname->items->pluck('ingredient_id')->unique()->all();
                $storeId        = $opname->store_id;

                // ── Hapus stock_ledger adjustment dari opname ini ──────────────
                StockLedger::where('reference_type', 'Opname')
                    ->where('reference_id', $opname->id)
                    ->delete();

                // ── Hapus mutation bootstrap yang dibuat saat approve ──────────
                Mutation::where('type', 'opening_stock')
                    ->where('notes', 'Auto-generated dari Opname #' . $opname->id)
                    ->each(function ($m) {
                        $m->items()->delete();
                        $m->delete();
                    });

                // ── Hapus opname & items DULU sebelum recalculate ─────────────
                // Supaya FifoService::recalculate tidak lagi membaca variance
                // dari opname ini (step 7: negative variance deduction).
                $opname->items()->delete();
                $opname->delete();

                // ── Recalculate FIFO & store_stocks untuk semua bahan terdampak ─
                foreach ($affectedIngIds as $ingId) {
                    FifoService::recalculate($storeId, $ingId);
                }

                return; // sudah dihapus, skip delete di bawah
            }

            $opname->items()->delete();
            $opname->delete();
        });

        return redirect()->route('opname.opnames.index')->with('success', 'Opname berhasil dihapus.');
    }

    public function approve(Opname $opname)
    {
        abort_if($opname->status === 'approved', 422, 'Opname sudah di-approve sebelumnya.');

        // ── Lock check ──────────────────────────────────────────────────────────
        if (MonthLockService::isLocked('opname', $opname->id, $opname->period_month, $opname->period_year)) {
            return back()->with('error', MonthLockService::lockMessage($opname->period_month, $opname->period_year));
        }

        // Opname BULANAN: kunci dulu harga efektif FIFO BERLAPIS ke tiap item
        // (dihitung dari state FIFO SEBELUM approve) supaya nilai yang dibekukan =
        // yang tampil di layar (akurat per-lapisan, bukan weighted).
        if ($opname->opname_mode !== 'stok_awal') {
            foreach ($this->fifoEffectivePrice($opname) as $itemId => $ppb) {
                if ($ppb > 0) OpnameItem::where('id', $itemId)->update(['price_per_base' => $ppb]);
            }
            $opname->load('items');
        }

        DB::transaction(function () use ($opname) {
            $opname->update(['status' => 'approved', 'approved_by' => auth()->id()]);

            // ── Langkah 1: catat adjustment & recalculate untuk item yg punya selisih ──
            foreach ($opname->items as $item) {
                if ($item->variance == 0) continue;

                StockLedgerService::record(
                    $opname->store_id, $item->ingredient_id,
                    $opname->opname_date->format('Y-m-d'), 'opname_adjustment',
                    $item->variance, 'Opname', $opname->id,
                    "Opname adjustment"
                );

                FifoService::recalculate($opname->store_id, $item->ingredient_id);
            }

            // ── Langkah 2: bootstrap FIFO ────────────────────────────────────────
            // Mode stok_awal: setiap item buat mutation-item LANGSUNG (satu per batch)
            //   agar harga per-batch tersimpan terpisah di FIFO.
            // Mode bulanan: pakai delta (physical − curr) agar tidak double-count.
            $opening = null;

            if ($opname->opname_mode === 'stok_awal') {
                // Stok awal — langsung buat satu mutation item per opname item
                foreach ($opname->items as $item) {
                    if ($item->physical_qty <= 0) continue;

                    // Harga sudah diisi user (termasuk 0 = gratis) → pakai apa adanya.
                    // Hanya kalau BELUM diisi (NULL) → ambil saran harga pembelian terakhir.
                    if ($item->price_per_base !== null) {
                        $lastPrice = (float) $item->price_per_base;
                    } else {
                        $lastPrice = \App\Models\MutationItem::whereHas('mutation', fn($q) =>
                                $q->where('destination_store_id', $opname->store_id)->where('status', 'confirmed')
                                  ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'opening_stock', 'sale_internal']))
                            ->where('ingredient_id', $item->ingredient_id)
                            ->where('packaging_id', $item->packaging_id)
                            ->where('price_per_base', '>', 0)
                            ->latest('id')->value('price_per_base') ?? 0;
                    }

                    $opening = $opening ?: \App\Models\Mutation::create([
                        'type'                 => 'opening_stock',
                        'destination_store_id' => $opname->store_id,
                        'transaction_date'     => $opname->opname_date,
                        'delivery_date'        => $opname->opname_date,
                        'status'               => 'confirmed',
                        'notes'                => 'Auto-generated dari Opname #' . $opname->id,
                        'created_by'           => auth()->id(),
                        'confirmed_by'         => auth()->id(),
                    ]);

                    $pkg         = $item->packaging;
                    $ptb         = $pkg ? (float)$pkg->pack_to_base : 0;
                    $crateToBase = ($pkg && $pkg->crate_to_pack && $ptb) ? (float)$pkg->crate_to_pack * $ptb : 0;

                    // Simpan HANYA Dus + Pack utuh ke FIFO (eceran pcs/gr TIDAK masuk stok).
                    // Saldo Stok menampilkan pack utuh via floor(remaining/ptb) — sisa eceran
                    // otomatis tidak dihitung, tanpa perlu dikurangi lagi.
                    $physCrate = (int)($item->physical_crate ?? 0);
                    $physPack  = (int)($item->physical_pack  ?? 0);
                    $qty = ($crateToBase > 0 ? $physCrate * $crateToBase : 0)
                         + ($ptb > 0        ? $physPack  * $ptb         : 0);
                    if ($qty <= 0 && !$pkg) $qty = round($item->physical_qty, 4);
                    if ($qty <= 0) continue;

                    \App\Models\MutationItem::create([
                        'mutation_id'            => $opening->id,
                        'ingredient_id'          => $item->ingredient_id,
                        'packaging_id'           => $item->packaging_id,
                        'qty_crate'              => $physCrate,
                        'qty_pack'               => $physPack,
                        'qty_base'               => 0,
                        'total_in_base'          => $qty,
                        'remaining_qty'          => $qty,
                        'price_per_base'         => $lastPrice,
                        'selling_price_per_base' => 0,
                        'cost_subtotal'          => $qty * $lastPrice,
                    ]);
                }
            } else {
                // Bulanan — delta-based (physical − curr FIFO)
                foreach ($opname->items as $item) {
                    if ($item->physical_qty <= 0) continue;

                    $curr = \App\Models\MutationItem::whereHas('mutation', fn($q) =>
                            $q->where('destination_store_id', $opname->store_id)->where('status', 'confirmed'))
                        ->where('ingredient_id', $item->ingredient_id)
                        ->when($item->packaging_id,
                            fn($q) => $q->where('packaging_id', $item->packaging_id),
                            fn($q) => $q->whereNull('packaging_id'))
                        ->sum('remaining_qty');

                    $pkg         = $item->packaging;
                    $ptb         = $pkg ? (float)$pkg->pack_to_base : 0;
                    $crateToBase = ($pkg && $pkg->crate_to_pack && $ptb) ? (float)$pkg->crate_to_pack * $ptb : 0;

                    // Hanya Dus + Pack utuh yang masuk FIFO (eceran pcs/gr tidak distok).
                    // Saldo Stok memfloor ke pack utuh, jadi eceran otomatis tidak dihitung.
                    $physCrate = (int)($item->physical_crate ?? 0);
                    $physPack  = (int)($item->physical_pack  ?? 0);
                    $packedQty = ($crateToBase > 0 ? $physCrate * $crateToBase : 0)
                               + ($ptb > 0        ? $physPack  * $ptb         : 0);
                    if ($packedQty <= 0 && !$pkg) $packedQty = round((float) $item->physical_qty, 4);

                    $delta = round($packedQty - $curr, 4);
                    if ($delta <= 0) continue;

                    // Harga sudah diisi user (termasuk 0 = gratis) → pakai apa adanya.
                    // Hanya kalau BELUM diisi (NULL) → ambil saran harga pembelian terakhir.
                    if ($item->price_per_base !== null) {
                        $lastPrice = (float) $item->price_per_base;
                    } else {
                        $lastPrice = \App\Models\MutationItem::whereHas('mutation', fn($q) =>
                                $q->where('destination_store_id', $opname->store_id)->where('status', 'confirmed')
                                  ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'opening_stock', 'sale_internal']))
                            ->where('ingredient_id', $item->ingredient_id)
                            ->where('packaging_id', $item->packaging_id)
                            ->where('price_per_base', '>', 0)
                            ->latest('id')->value('price_per_base') ?? 0;
                    }

                    $opening = $opening ?: \App\Models\Mutation::create([
                        'type'                 => 'opening_stock',
                        'destination_store_id' => $opname->store_id,
                        'transaction_date'     => $opname->opname_date,
                        'delivery_date'        => $opname->opname_date,
                        'status'               => 'confirmed',
                        'notes'                => 'Auto-generated dari Opname #' . $opname->id,
                        'created_by'           => auth()->id(),
                        'confirmed_by'         => auth()->id(),
                    ]);

                    \App\Models\MutationItem::create([
                        'mutation_id'            => $opening->id,
                        'ingredient_id'          => $item->ingredient_id,
                        'packaging_id'           => $item->packaging_id,
                        'qty_crate'              => $crateToBase > 0 ? (int) floor($delta / $crateToBase) : 0,
                        'qty_pack'               => $ptb > 0 ? (int) floor(fmod($delta, max($crateToBase, $ptb)) / $ptb) : 0,
                        'qty_base'               => 0,
                        'total_in_base'          => $delta,
                        'remaining_qty'          => $delta,
                        'price_per_base'         => $lastPrice,
                        'selling_price_per_base' => 0,
                        'cost_subtotal'          => $delta * $lastPrice,
                    ]);
                }
            }

            // Sync FIFO & store_stocks sekali per bahan
            foreach ($opname->items->pluck('ingredient_id')->unique() as $iid) {
                FifoService::recalculate($opname->store_id, (int) $iid);
            }

            // ── Bekukan harga: simpan harga rata-rata sisa batch ke tiap opname item ──
            // Setelah FIFO disesuaikan, sisa batch = inventaris akhir periode. Harganya
            // dikunci ke price_per_base supaya nilai SO Akhir tidak bergeser saat ada
            // transaksi/recalculate berikutnya.
            $this->freezeItemPrices($opname);
        });

        // Redirect eksplisit ke SHOW (bukan back()) — kalau approve dipicu dari halaman
        // edit, back() akan membuka edit lagi padahal sudah approved → error 403.
        return redirect()->route('opname.opnames.show', $opname)
            ->with('success', 'Opname disetujui. Stok otomatis disesuaikan.');
    }

    // Kunci harga ke setiap opname item.
    // PENTING: item yang sudah punya harga sendiri (diinput user / per-batch pada
    // mode stok_awal) TIDAK ditimpa rata-rata gabungan — harga per-batch dijaga.
    // Hanya item tanpa harga yang diisi dari rata-rata tertimbang FIFO.
    private function freezeItemPrices(Opname $opname): void
    {
        $priceMap = $this->buildPriceMap($opname); // weighted-avg sisa batch terkini
        foreach ($opname->items as $item) {
            if ((float) $item->price_per_base > 0) continue; // jaga harga per-batch
            $frozen = $priceMap[$item->ingredient_id] ?? null;
            if ($frozen !== null && $frozen > 0) {
                $item->update(['price_per_base' => $frozen]);
            }
        }
    }

    // Export detail item satu opname
    public function export(Opname $opname)
    {
        $opname->load(['store', 'items.ingredient', 'items.packaging', 'performedBy']);

        $periodLabel = $opname->period_type === 'mid_month' ? 'Tengah Bulan' : 'Akhir Bulan';
        $monthLabel  = \Carbon\Carbon::create($opname->period_year, $opname->period_month)
                            ->isoFormat('MMMM Y');

        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $ss->getActiveSheet();
        $ws->setTitle('Opname');

        // ── Row 1: METADATA (sama persis dengan template import) ──
        $ws->setCellValue('A1', 'METADATA');
        $ws->setCellValue('B1', $opname->store_id);
        $ws->setCellValue('C1', $opname->opname_date->toDateString());
        $ws->setCellValue('D1', $opname->period_type);
        $ws->setCellValue('E1', $opname->opname_mode ?? 'bulanan');
        $ws->getStyle('A1:K1')->applyFromArray([
            'font' => ['size' => 8, 'color' => ['rgb' => 'AAAAAA']],
        ]);

        // ── Row 2: Judul ──
        $modeLabel = ($opname->opname_mode === 'stok_awal') ? ' [STOK AWAL]' : '';
        $ws->setCellValue('A2', "STOK OPNAME — {$opname->store->name} — {$opname->opname_date->format('d/m/Y')} — {$periodLabel}{$modeLabel}  |  Status: {$opname->status}  |  Oleh: " . ($opname->performedBy?->name ?? '-'));
        $ws->mergeCells('A2:H2');
        $ws->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $ws->getStyle('A2')->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // ── Row 3: Header — A=RowKey(hidden), B=Nama, C=Kemasan, D-F=FISIK, G=Harga/Dus, H=Catatan
        $ws->setCellValue('A3', 'ID');
        $ws->setCellValue('B3', 'Nama Bahan');
        $ws->setCellValue('C3', 'Pack/Dus');
        $ws->setCellValue('D3', 'FISIK Dus');
        $ws->setCellValue('E3', 'FISIK Pack');
        $ws->setCellValue('F3', 'FISIK Gr/Pcs');
        $ws->setCellValue('G3', 'Harga/Dus');
        $ws->setCellValue('H3', 'Catatan');
        $ws->getStyle('A3:H3')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'color' => ['rgb' => '1e3a5f']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);

        // Lebar kolom — A kecil (Row Key tersembunyi)
        $ws->getColumnDimension('A')->setWidth(4);
        $ws->getColumnDimension('B')->setWidth(34);
        $ws->getColumnDimension('C')->setWidth(16);
        foreach (['D','E','F'] as $c) $ws->getColumnDimension($c)->setWidth(12);
        $ws->getColumnDimension('G')->setWidth(14);
        $ws->getColumnDimension('H')->setWidth(28);
        $ws->freezePane('D4');

        // ── Row 4+: Data ──
        $row = 4;
        foreach ($opname->items as $item) {
            $pkg      = $item->packaging;
            $ctb      = $pkg ? (float)$pkg->crate_to_pack : 0;
            $ptb      = $pkg ? (float)$pkg->pack_to_base  : 0;
            $pkgLabel = ($pkg && $ctb > 0) ? (int)$ctb : ($item->ingredient?->unit_base ?? '-');

            // Stok Sistem breakdown
            $sysTotal = (float)$item->system_qty;
            if ($pkg && $ctb > 0 && $ptb > 0) {
                $sysDus  = (int)floor($sysTotal / ($ctb * $ptb));
                $sysRem  = fmod($sysTotal, $ctb * $ptb);
                $sysPack = (int)floor($sysRem / $ptb);
            } elseif ($pkg && $ptb > 0) {
                $sysDus  = 0;
                $sysPack = (int)floor($sysTotal / $ptb);
            } else {
                $sysDus = 0; $sysPack = 0;
            }

            // Stok Fisik dari data yang sudah diisi
            $physCrate = (int)($item->physical_crate ?? 0);
            $physPack  = (int)($item->physical_pack  ?? 0);
            $physTotal = (float)($item->physical_qty ?? 0);
            $physBase  = round($physTotal
                - ($ctb > 0 && $ptb > 0 ? $physCrate * $ctb * $ptb : 0)
                - ($ptb > 0             ? $physPack  * $ptb         : 0), 3);
            if ($physBase < 0) $physBase = 0;

            $dusSize     = ($ctb > 0 && $ptb > 0) ? $ctb * $ptb : ($ptb > 0 ? $ptb : 1);
            $pricePerDus = ($item->price_per_base ?? 0) > 0 ? (int)round((float)$item->price_per_base * $dusSize) : '';

            // Kolom: A=RowKey(hidden), B=Nama, C=Kemasan, D=FisikDus, E=FisikPack, F=FisikGr/Pcs, G=Harga/Dus, H=Catatan
            $ws->setCellValueByColumnAndRow(1, $row, $item->row_key ?? ($item->ingredient_id . '_' . ($item->packaging_id ?? '')));
            $ws->setCellValueByColumnAndRow(2, $row, $item->ingredient?->name ?? '-');
            $ws->setCellValueByColumnAndRow(3, $row, $pkgLabel);
            $ws->setCellValueByColumnAndRow(4, $row, $physCrate ?: ($pkg && $ctb > 0 ? 0 : ''));
            $ws->setCellValueByColumnAndRow(5, $row, $physPack  ?: ($pkg ? 0 : ''));
            $ws->setCellValueByColumnAndRow(6, $row, $physBase);
            $ws->setCellValueByColumnAndRow(7, $row, $pricePerDus);
            $ws->setCellValueByColumnAndRow(8, $row, $item->notes ?? '');

            $ws->getStyle("A{$row}")->applyFromArray([
                'font' => ['size' => 7, 'color' => ['rgb' => 'CCCCCC']],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'color' => ['rgb' => 'FAFAFA']],
            ]);
            $ws->getStyle("B{$row}:C{$row}")->applyFromArray([
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'color' => ['rgb' => 'F2F2F2']],
                'font' => ['color' => ['rgb' => '444444']],
            ]);
            $ws->getStyle("D{$row}:G{$row}")->applyFromArray([
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'color' => ['rgb' => 'FFFDE7']],
                'font' => ['bold' => true],
            ]);

            if ($row % 2 === 0) {
                $ws->getStyle("A{$row}:H{$row}")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F9FAFB');
            }

            $row++;
        }

        $writer   = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        $filename = "opname_{$opname->store->name}_{$opname->period_year}-{$opname->period_month}.xlsx";

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TEMPLATE DOWNLOAD
    // ═══════════════════════════════════════════════════════════════════════
    public function downloadTemplate(Request $request)
    {
        $request->validate([
            'store_id'    => 'required|exists:stores,id',
            'date'        => 'required|date',
            'period_type' => 'required|in:mid_month,end_month',
            'opname_mode' => 'nullable|in:bulanan,stok_awal',
        ]);

        $storeId    = (int)$request->store_id;
        $date       = $request->date;
        $periodType = $request->period_type;
        $opnameMode = $request->input('opname_mode', 'bulanan');
        $store      = \App\Models\Store::findOrFail($storeId);

        // Ambil data bahan + sistem qty (sama seperti API systemQty)
        $apiReq  = new \Illuminate\Http\Request(['store_id' => $storeId, 'date' => $date]);
        $apiResp = $this->systemQty($apiReq);
        $rows    = json_decode($apiResp->getContent(), true);

        // ── Build Spreadsheet ───────────────────────────────────────────────
        $ss   = new Spreadsheet();
        $ws   = $ss->getActiveSheet();
        $ws->setTitle('Opname');

        $periodLabel = $periodType === 'mid_month' ? 'Tengah Bulan (1-15)' : 'Akhir Bulan (1-30/31)';
        $carbon      = \Carbon\Carbon::parse($date);

        // Baris 1: metadata (jangan diubah)
        $ws->setCellValue('A1', 'METADATA');
        $ws->setCellValue('B1', $storeId);
        $ws->setCellValue('C1', $date);
        $ws->setCellValue('D1', $periodType);
        $ws->setCellValue('E1', $opnameMode);
        $ws->getRowDimension(1)->setRowHeight(14);

        // Baris 2: judul
        $modeLabel = $opnameMode === 'stok_awal' ? ' [STOK AWAL]' : '';
        $ws->setCellValue('A2', "TEMPLATE STOK OPNAME — {$store->name} — {$carbon->format('d/m/Y')} — {$periodLabel}{$modeLabel}");
        $ws->mergeCells('A2:H2');
        $ws->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $ws->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Baris 3: header — A=RowKey(hidden), B=Nama Bahan, C=Supplier, D=Pack/Dus,
        // E-G=FISIK, H=Harga/Dus, I=Catatan. Kolom Supplier membedakan bahan yang
        // dipasok >1 supplier (mis. Lemon @15000 dari 2 supplier berbeda).
        $ws->setCellValue('A3', 'ID');
        $ws->setCellValue('B3', 'Nama Bahan');
        $ws->setCellValue('C3', 'Supplier');
        $ws->setCellValue('D3', 'Pack/Dus');
        $ws->setCellValue('E3', 'FISIK Dus ✏');
        $ws->setCellValue('F3', 'FISIK Pack ✏');
        $ws->setCellValue('G3', 'FISIK Gr/Pcs ✏');
        $ws->setCellValue('H3', 'Harga/Dus ✏');
        $ws->setCellValue('I3', 'Catatan');

        $ws->getStyle('A3:I3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '1e3a5f']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Baris data mulai baris 4
        $rowNum = 4;
        foreach ($rows as $r) {
            $ws->setCellValueByColumnAndRow(1, $rowNum, $r['row_key']);
            $ws->setCellValueByColumnAndRow(2, $rowNum, $r['name']);
            $ws->setCellValueByColumnAndRow(3, $rowNum, $r['supplier'] ?? '-');
            $ws->setCellValueByColumnAndRow(4, $rowNum, ($r['crate_to_pack'] ?? 0) > 0 ? $r['crate_to_pack'] : ($r['unit_base'] ?? '-'));
            $ws->setCellValueByColumnAndRow(5, $rowNum, ''); // FISIK Dus
            $ws->setCellValueByColumnAndRow(6, $rowNum, ''); // FISIK Pack
            $ws->setCellValueByColumnAndRow(7, $rowNum, ''); // FISIK Gr/Pcs
            $ws->setCellValueByColumnAndRow(8, $rowNum, ''); // Harga/Dus
            $ws->setCellValueByColumnAndRow(9, $rowNum, ''); // Catatan

            $ws->getStyle("A{$rowNum}")->applyFromArray([
                'font' => ['size' => 7, 'color' => ['rgb' => 'CCCCCC']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'FAFAFA']],
            ]);
            $ws->getStyle("B{$rowNum}:D{$rowNum}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'F2F2F2']],
                'font' => ['color' => ['rgb' => '444444']],
            ]);
            $ws->getStyle("E{$rowNum}:H{$rowNum}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'FFFDE7']],
                'font' => ['bold' => true],
            ]);
            $rowNum++;
        }

        // Column widths
        $ws->getColumnDimension('A')->setWidth(4);
        $ws->getColumnDimension('B')->setWidth(34);
        $ws->getColumnDimension('C')->setWidth(26);   // Supplier
        $ws->getColumnDimension('D')->setWidth(12);
        $ws->getColumnDimension('E')->setWidth(12);
        $ws->getColumnDimension('F')->setWidth(12);
        $ws->getColumnDimension('G')->setWidth(12);
        $ws->getColumnDimension('H')->setWidth(14);
        $ws->getColumnDimension('I')->setWidth(28);

        // Style metadata row 1 (kecil & abu)
        $ws->getStyle('A1:E1')->applyFromArray([
            'font' => ['size' => 7, 'color' => ['rgb' => 'CCCCCC']],
        ]);

        // Freeze pane — mulai kolom E (setelah Nama, Supplier, Pack/Dus)
        $ws->freezePane('E4');

        $filename = 'template_opname_' . $store->name . '_' . $carbon->format('Ymd') . '.xlsx';
        $writer   = new XlsxWriter($ss);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  IMPORT FORM
    // ═══════════════════════════════════════════════════════════════════════
    public function importForm()
    {
        $stores = auth()->user()->accessibleStores();
        return view('opname.import', compact('stores'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  IMPORT PROCESS
    // ═══════════════════════════════════════════════════════════════════════
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls|max:5120']);

        $path = $request->file('file')->getRealPath();
        $ss   = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $ws   = $ss->getActiveSheet();

        // ── Baca metadata baris 1 ────────────────────────────────────────────
        if ($ws->getCell('A1')->getValue() !== 'METADATA') {
            return back()->withErrors(['file' => 'File tidak valid: pastikan menggunakan template yang benar.']);
        }
        $storeId    = (int)$ws->getCell('B1')->getValue();
        $date       = $ws->getCell('C1')->getValue();
        $periodType = $ws->getCell('D1')->getValue();
        $opnameMode = $ws->getCell('E1')->getValue() ?: 'bulanan';
        if (!in_array($opnameMode, ['bulanan', 'stok_awal'])) $opnameMode = 'bulanan';

        // Validasi metadata
        $storeIds = auth()->user()->accessibleStoreIds();
        if (!in_array($storeId, $storeIds)) {
            return back()->withErrors(['file' => 'Toko tidak ditemukan atau tidak dapat diakses.']);
        }
        if (!in_array($periodType, ['mid_month', 'end_month'])) {
            return back()->withErrors(['file' => 'Tipe periode tidak valid.']);
        }

        $carbon = \Carbon\Carbon::parse($date);
        $month  = $carbon->month;
        $year   = $carbon->year;

        // Cek opname-based period lock untuk store ini
        if (Opname::isDateLocked($storeId, $date)) {
            return back()->withErrors(['file' => Opname::lockMessageFor($storeId)]);
        }

        // Cek duplikat
        if (Opname::where('store_id', $storeId)->where('period_month', $month)
                  ->where('period_year', $year)->where('period_type', $periodType)->exists()) {
            return back()->withErrors(['file' => "Opname periode {$month}/{$year} ({$periodType}) untuk toko ini sudah ada."]);
        }

        // ── Kenali posisi kolom dari JUDUL di baris 3 ────────────────────────
        // Dibaca dari header (bukan posisi tetap) supaya template lama—yang belum
        // punya kolom Supplier—tetap bisa diimpor setelah layout template berubah.
        $colMap = ['dus' => 4, 'pack' => 5, 'base' => 6, 'harga' => 7];   // default = layout lama
        $found  = [];
        for ($c = 1; $c <= 15; $c++) {
            $h = strtolower(trim((string) $ws->getCellByColumnAndRow($c, 3)->getValue()));
            if ($h === '') continue;
            if (str_contains($h, 'harga'))                              $found['harga'] = $c;
            elseif (str_contains($h, 'dus'))                            $found['dus']   = $c;  // "FISIK Dus"
            elseif (str_contains($h, 'pack'))                           $found['pack']  = $c;
            elseif (str_contains($h, 'gr') || str_contains($h, 'pcs'))  $found['base']  = $c;
        }
        // Pakai hasil deteksi hanya bila lengkap; kalau tidak, tetap pakai layout lama.
        if (count(array_intersect_key($found, $colMap)) === count($colMap)) {
            $colMap = array_merge($colMap, $found);
        }

        // ── Baca baris data (mulai baris 4) ─────────────────────────────────
        $errors = [];
        $items  = [];
        $rowNum = 4;
        $maxRow = $ws->getHighestDataRow();

        while ($rowNum <= $maxRow) {
            $rowKey  = trim((string)$ws->getCellByColumnAndRow(1, $rowNum)->getValue());
            if ($rowKey === '') { $rowNum++; continue; }

            $fisikDus  = $ws->getCellByColumnAndRow($colMap['dus'],   $rowNum)->getValue();
            $fisikPack = $ws->getCellByColumnAndRow($colMap['pack'],  $rowNum)->getValue();
            $fisikBase = $ws->getCellByColumnAndRow($colMap['base'],  $rowNum)->getValue();
            $hargaDus  = $ws->getCellByColumnAndRow($colMap['harga'], $rowNum)->getValue();

            // Validasi nilai
            foreach ([
                "Baris {$rowNum} Fisik Dus"    => $fisikDus,
                "Baris {$rowNum} Fisik Pack"   => $fisikPack,
                "Baris {$rowNum} Fisik Pcs/Gr" => $fisikBase,
                "Baris {$rowNum} Harga/Dus"    => $hargaDus,
            ] as $label => $val) {
                if ($val !== '' && $val !== null && (!is_numeric($val) || (float)$val < 0)) {
                    $errors[] = "{$label}: nilai harus angka ≥ 0 (dapat dikosongkan).";
                }
            }

            $items[] = [
                'row_key'        => $rowKey,
                'physical_crate' => $fisikDus !== '' && $fisikDus !== null ? (int)$fisikDus   : null,
                'physical_pack'  => $fisikPack !== '' && $fisikPack !== null ? (int)$fisikPack : null,
                'physical_base'  => $fisikBase !== '' && $fisikBase !== null ? (float)$fisikBase : null,
                'price_per_dus'  => $hargaDus !== '' && $hargaDus !== null && (float)$hargaDus > 0 ? (float)$hargaDus : null,
            ];
            $rowNum++;
        }

        if (!empty($errors)) {
            return back()->withErrors(['file' => implode(' | ', $errors)]);
        }
        if (empty($items)) {
            return back()->withErrors(['file' => 'Tidak ada data bahan dalam file.']);
        }

        // ── Ambil data sistem qty untuk semua row_key ────────────────────────
        $apiReq  = new \Illuminate\Http\Request(['store_id' => $storeId, 'date' => $date]);
        $apiData = json_decode($this->systemQty($apiReq)->getContent(), true);
        $sysMap  = collect($apiData)->keyBy('row_key');

        // ── Simpan opname ────────────────────────────────────────────────────
        $opname = null;
        DB::transaction(function () use (
            $storeId, $date, $month, $year, $periodType, $opnameMode, $items, $sysMap, &$opname
        ) {
            $opname = Opname::create([
                'store_id'     => $storeId,
                'opname_date'  => $date,
                'period_month' => $month,
                'period_year'  => $year,
                'period_type'  => $periodType,
                'status'       => 'draft',
                'opname_mode'  => $opnameMode,
                'performed_by' => auth()->id(),
                'notes'        => 'Import dari Excel',
            ]);

            foreach ($items as $item) {
                $sys = $sysMap->get($item['row_key']);
                if (!$sys) continue;

                $ingId  = $sys['ingredient_id'];
                $pkgId  = $sys['packaging_id'];
                $sysQty = (float)$sys['system_qty'];
                $pkg    = $pkgId ? IngredientPackaging::find($pkgId) : null;

                $c = $item['physical_crate'];
                $p = $item['physical_pack'];
                $b = $item['physical_base'];

                if ($pkg && ($c !== null || $p !== null || $b !== null)) {
                    $physQty = $pkg->convertToBase($c ?? 0, $p ?? 0, $b ?? 0);
                } elseif ($b !== null) {
                    $physQty = $b;
                } else {
                    $physQty = 0;
                }

                // Konversi harga/dus → price_per_base
                $pricePerBase = null;
                if (!empty($item['price_per_dus'])) {
                    $crateToBase = $pkg ? (float)$pkg->crate_to_pack * (float)$pkg->pack_to_base : 0;
                    $pricePerBase = $crateToBase > 0
                        ? $item['price_per_dus'] / $crateToBase
                        : $item['price_per_dus'];
                }

                OpnameItem::create([
                    'opname_id'      => $opname->id,
                    'ingredient_id'  => $ingId,
                    'packaging_id'   => $pkgId,
                    'system_qty'     => round($sysQty, 4),
                    'physical_qty'   => round($physQty, 4),
                    'physical_crate' => $c,
                    'physical_pack'  => $p,
                    'physical_base'  => $b,
                    'variance'       => round($physQty - $sysQty, 4),
                    'price_per_base' => $pricePerBase,
                ]);
            }
        });

        return redirect()->route('opname.opnames.show', $opname)
            ->with('success', 'Opname berhasil diimport. Periksa data lalu klik Approve.');
    }
}