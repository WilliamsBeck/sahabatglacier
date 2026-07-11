<?php
namespace App\Http\Controllers\Inventory;
use App\Http\Controllers\Controller;
use App\Models\{Mutation, MutationItem, Store, Supplier, Ingredient, IngredientCategory, IngredientPackaging, StockLedger, StoreStock, UnlockRequest};
use App\Services\{MutationService, FifoService, MonthLockService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ArrayExport;

class MutationController extends Controller
{
    public function index(Request $request)
    {
        $storeIds = auth()->user()->accessibleStoreIds();
        $query = Mutation::with(['destinationStore','sourceStore','supplier'])
            ->withCount('items')
            ->where('type', '!=', 'opening_stock')   // stok awal (opname) tidak ditampilkan di daftar mutasi
            ->where(function($q) use ($storeIds) {
                $q->whereIn('destination_store_id',$storeIds)->orWhereIn('source_store_id',$storeIds);
            });
        if ($request->type)            $query->where('type', $request->type);
        if ($request->source_store_id) $query->where('source_store_id', $request->source_store_id);
        if ($request->dest_store_id)   $query->where('destination_store_id', $request->dest_store_id);
        if ($request->status)          $query->where('status', $request->status);
        if ($request->date_from) $query->where('transaction_date','>=',$request->date_from);
        if ($request->date_to)   $query->where('transaction_date','<=',$request->date_to);
        $mutations   = $query->latest()->paginate(20);
        $stores      = auth()->user()->accessibleStores();
        $myStoreIds  = $stores->pluck('id')->all();
        // Filter dropdown: toko sendiri di atas, sisanya di bawah
        $allStores   = Store::where('is_active', true)->orderBy('name')->get();
        $filterStores = $allStores->sortBy(fn($s) => in_array($s->id, $myStoreIds) ? 0 : 1)->values();
        return view('inventory.mutations.index', compact('mutations','stores','filterStores','myStoreIds'));
    }

    public function create()
    {
        $stores        = auth()->user()->accessibleStores();
        $myStoreIds    = $stores->pluck('id')->all();
        $allStores     = Store::where('is_active', true)->orderBy('name')->get();
        // Toko lain di atas untuk daftar "semua toko" (dipakai sbg penerima transfer internal).
        $sourceStores  = $allStores->sortBy(fn($s) => in_array($s->id, $myStoreIds) ? 0 : 1)->values();
        $suppliers = Supplier::where('is_active',true)->orderBy('name')->get();

        // Untuk JS: daftar toko sendiri (pengirim) & semua toko (penerima transfer internal).
        $storesMineJs = $stores->map(fn($s) => ['id' => $s->id, 'name' => $s->name])->values()->all();
        $storesAllJs  = $sourceStores->map(fn($s) => ['id' => $s->id, 'name' => $s->name])->values()->all();

        // Data supplier untuk JS filtering (pusat vs lokal)
        $suppliersJs = $suppliers->map(fn($s) => [
            'id'   => $s->id,
            'name' => $s->name,
            'type' => $s->type,   // 'zhisheng' | 'local_supplier' | 'other'
        ])->values()->all();

        $categoryOrder = IngredientCategory::orderedNames();

        $ingredients = Ingredient::with(['packagings' => fn($q) => $q->where('is_active',true)->orderBy('id')])
            ->where('is_active',true)
            ->get()
            ->sort(function ($a, $b) use ($categoryOrder) {
                // raw sebelum semi_finished
                $ta = $a->type === 'semi_finished' ? 1 : 0;
                $tb = $b->type === 'semi_finished' ? 1 : 0;
                if ($ta !== $tb) return $ta - $tb;
                // dalam raw: urutkan by category order lalu urutan input (id)
                if ($a->type === 'raw') {
                    $ca = array_search($a->category, $categoryOrder);
                    $cb = array_search($b->category, $categoryOrder);
                    $ca = $ca === false ? 99 : $ca;
                    $cb = $cb === false ? 99 : $cb;
                    if ($ca !== $cb) return $ca - $cb;
                }
                // urutan sesuai input awal (id), bukan abjad
                return $a->id <=> $b->id;
            })
            ->values();

        // ID supplier Zhisheng (untuk filter bahan di form)
        $zhishengId = Supplier::where('name','like','%zhisheng%')->value('id');

        // Data ingredient + packagings untuk JS (disiapkan di PHP agar Blade tidak error)
        $ingredientJs = $ingredients->map(function ($i) {
            return [
                'id'       => $i->id,
                'name'     => $i->name,
                'unit'     => $i->unit_base,
                'type'     => $i->type,
                'category' => $i->category,
                'packagings' => $i->packagings->map(function ($p) {
                    return [
                        'id'             => $p->id,
                        'packaging_name' => $p->packaging_name,
                        'supplier_id'    => $p->supplier_id,
                        'crate_to_pack'  => $p->crate_to_pack,
                        'pack_to_base'   => $p->pack_to_base,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        return view('inventory.mutations.create', compact('stores','sourceStores','myStoreIds','suppliers','ingredients','zhishengId','ingredientJs','suppliersJs','storesMineJs','storesAllJs'));
    }

    public function store(Request $request)
    {
        // Abaikan baris yang qty-nya kosong/0 (mis. dari "Muat Semua Bahan Zhisheng" —
        // user hanya mengisi qty bahan yang benar-benar dibeli).
        $request->merge([
            'items' => collect($request->input('items', []))->filter(function ($it) {
                return ((float) ($it['qty_crate'] ?? 0)
                      + (float) ($it['qty_pack'] ?? 0)
                      + (float) ($it['qty_base'] ?? 0)) > 0;
            })->values()->all(),
        ]);

        $needsDest   = in_array($request->type, ['purchase_zhisheng','purchase_supplier','sale_internal','sale_external']);
        $needsSource = in_array($request->type, ['sale_internal','sale_external_out']);

        $request->validate([
            'type'                   => 'required|in:purchase_zhisheng,purchase_supplier,sale_internal,sale_external,sale_external_out',
            'destination_store_id'   => ($needsDest   ? 'required' : 'nullable').'|exists:stores,id',
            'source_store_id'        => ($needsSource ? 'required' : 'nullable').'|exists:stores,id',
            'supplier_id'            => 'nullable|exists:suppliers,id',
            'external_sender'        => 'nullable|string|max:255|required_if:type,sale_external',
            'external_receiver'      => 'nullable|string|max:255|required_if:type,sale_external_out',
            'invoice_no'             => 'nullable|string|max:255|unique:mutations,invoice_no',
            'transaction_date'       => 'required|date',
            'delivery_date'          => 'nullable|date|after_or_equal:transaction_date',
            'notes'                  => 'nullable|string',
            'items'                  => 'required|array|min:1',
            'items.*.ingredient_id'  => 'required|exists:ingredients,id',
            'items.*.packaging_id'   => 'nullable|exists:ingredient_packagings,id',
            'items.*.qty_crate'      => 'nullable|integer|min:0',
            'items.*.qty_pack'       => 'nullable|integer|min:0',
            'items.*.qty_base'       => 'nullable|numeric|min:0',
            'items.*.price_per_base' => 'required|numeric|min:0',
        ], [
            'destination_store_id.required' => 'Toko penerima wajib dipilih.',
            'source_store_id.required'      => 'Toko pengirim wajib dipilih.',
            'external_sender.required_if'   => 'Pengirim wajib diisi untuk Pembelian Eksternal.',
            'external_receiver.required_if' => 'Penerima/pembeli wajib diisi untuk Penjualan Eksternal.',
            'invoice_no.unique'             => 'No. SJ sudah dipakai di mutasi lain. Gunakan nomor yang berbeda.',
            'delivery_date.required'        => 'Tanggal penerimaan wajib diisi untuk pembelian.',
            'delivery_date.after_or_equal'  => 'Tanggal penerimaan tidak boleh lebih awal dari tanggal pengiriman.',
        ]);

        // Validasi: toko pengirim dan penerima tidak boleh sama
        if ($request->source_store_id && $request->destination_store_id
            && $request->source_store_id == $request->destination_store_id) {
            return back()->withInput()->withErrors(['destination_store_id' => 'Toko pengirim dan toko penerima tidak boleh sama.']);
        }

        // Validasi: qty tidak boleh melebihi stok toko pengirim (transfer internal & penjualan eksternal)
        $needsStockCheck = in_array($request->type, ['sale_internal','sale_external_out']);
        if ($needsStockCheck && $request->source_store_id) {
            $overErrors = [];
            foreach ($request->items as $i => $item) {
                // Stok tersedia untuk barang keluar = PACK UTUH saja (sisa gram/pack terbuka
                // tidak ikut dihitung), PER KEMASAN di toko pengirim.
                $available = FifoService::availableWholePacksBase(
                    (int) $request->source_store_id,
                    (int) $item['ingredient_id'],
                    !empty($item['packaging_id']) ? (int) $item['packaging_id'] : null
                );

                $requested = $this->convertToBase($item);
                if ($requested > $available + 0.001) {
                    $ing = Ingredient::find($item['ingredient_id']);
                    // Format available dalam Dus/Pack jika ada packaging
                    $pkg  = !empty($item['packaging_id']) ? IngredientPackaging::find($item['packaging_id']) : null;
                    $availDisplay = $pkg
                        ? floor($available / ($pkg->crate_to_pack * $pkg->pack_to_base)) . ' Dus ' .
                          floor(fmod($available, $pkg->crate_to_pack * $pkg->pack_to_base) / $pkg->pack_to_base) . ' Pack'
                        : number_format($available, 0, ',', '.') . ' ' . $ing->unit_base;
                    $overErrors["items.{$i}.qty_crate"] =
                        "Stok {$ing->name} tidak cukup — tersedia: {$availDisplay}, diminta: "
                        . number_format($requested, 0, ',', '.') . " {$ing->unit_base}.";
                }
            }
            if (!empty($overErrors)) {
                return back()->withErrors($overErrors)->withInput();
            }
        }

        // ── Lock periode oleh opname: transaksi <= tanggal opname approved ditolak ──
        $txDateStr = $request->delivery_date ?: $request->transaction_date;
        $c = \Carbon\Carbon::parse($txDateStr);
        foreach (array_filter([$request->destination_store_id, $request->source_store_id]) as $sid) {
            if (\App\Models\Opname::isDateLocked((int)$sid, $txDateStr)) {
                return back()->withInput()->with('error', \App\Models\Opname::lockMessageFor((int)$sid));
            }
            if (\App\Models\HppSnapshot::isDateLocked((int)$sid, $txDateStr)) {
                return back()->withInput()->with('error', \App\Models\HppSnapshot::lockMessageFor((int)$sid, $c->month, $c->year));
            }
        }

        // ── Wajib ada opname akhir bulan sebelumnya sebelum input mutasi ──────
        $storeForCheck = $request->destination_store_id ?? $request->source_store_id;
        if ($storeForCheck) {
            $msg = \App\Models\Opname::missingPreviousOpname((int)$storeForCheck, $txDateStr);
            if ($msg) return back()->withInput()->with('error', $msg);
        }

        // Untuk pembelian dari pusat, otomatis pakai supplier Zhisheng
        $supplierId = $request->supplier_id;
        if ($request->type === 'purchase_zhisheng' && !$supplierId) {
            $supplierId = Supplier::where('name', 'like', '%zhisheng%')->value('id');
        }

        $mutation = null;
        DB::transaction(function () use ($request, $supplierId, &$mutation) {
            $mutation = Mutation::create([
                'type'                 => $request->type,
                'destination_store_id' => $request->destination_store_id,
                'source_store_id'      => $request->source_store_id,
                'supplier_id'          => $supplierId,
                'external_sender'      => $request->type === 'sale_external' ? $request->external_sender : null,
                'external_receiver'    => $request->type === 'sale_external_out' ? $request->external_receiver : null,
                'invoice_no'           => $request->invoice_no,
                'transaction_date'     => $request->transaction_date,
                'delivery_date'        => $request->delivery_date,
                'notes'                => $request->notes,
                'created_by'           => auth()->id(),
            ]);

            foreach ($request->items as $item) {
                $totalInBase = $this->convertToBase($item);
                $mutation->items()->create([
                    'ingredient_id'          => $item['ingredient_id'],
                    'packaging_id'           => $item['packaging_id'] ?? null,
                    'qty_crate'              => $item['qty_crate'] ?? null,
                    'qty_pack'               => $item['qty_pack'] ?? null,
                    'qty_base'               => $item['qty_base'] ?? null,
                    'total_in_base'          => $totalInBase,
                    'price_per_base'         => $item['price_per_base'],
                    'selling_price_per_base' => $item['selling_price_per_base'] ?? null,
                    'cost_subtotal'          => $totalInBase * $item['price_per_base'],
                    'remaining_qty'          => $totalInBase,
                ]);
            }

            // Simpan sebagai draft — stok belum diupdate
            // User harus konfirmasi setelah barang diterima
        });

        return redirect()->route('inventory.mutations.show', $mutation)
            ->with('success', 'Mutasi disimpan sebagai draft. Konfirmasi setelah barang diterima untuk update stok.');
    }

    private function blockIfOpnameExistsAfter(Mutation $mutation): void
    {
        // Periode mutasi tertutup oleh opname approved? Pakai mekanisme lock yang sama
        // dengan store()/confirm() (berbasis periode, mode-agnostic) agar konsisten.
        $date = ($mutation->delivery_date ?? $mutation->transaction_date)->toDateString();
        $c = \Carbon\Carbon::parse($date);
        foreach (array_filter([$mutation->destination_store_id, $mutation->source_store_id]) as $sid) {
            if (\App\Models\Opname::isDateLocked((int)$sid, $date)) {
                abort(403, \App\Models\Opname::lockMessageFor((int)$sid));
            }
            if (\App\Models\HppSnapshot::isDateLocked((int)$sid, $date)) {
                abort(403, \App\Models\HppSnapshot::lockMessageFor((int)$sid, $c->month, $c->year));
            }
        }
    }

    public function edit(Mutation $mutation)
    {
        abort_if($mutation->status !== 'draft', 403, 'Hanya mutasi draft yang bisa diedit.');
        abort_if($mutation->type === 'opening_stock', 403, 'Input stok awal tidak bisa diedit.');
        $this->blockIfOpnameExistsAfter($mutation);

        $mutation->load(['items.ingredient', 'items.packaging']);
        $stores    = auth()->user()->accessibleStores();
        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get();

        return view('inventory.mutations.edit', compact('mutation', 'stores', 'suppliers'));
    }

    public function update(Request $request, Mutation $mutation)
    {
        abort_if($mutation->status !== 'draft', 403, 'Hanya mutasi draft yang bisa diedit.');
        abort_if($mutation->type === 'opening_stock', 403, 'Input stok awal tidak bisa diedit.');
        $this->blockIfOpnameExistsAfter($mutation);

        $isConfirm      = $request->action === 'confirm';
        $needsDelivery  = $isConfirm && $mutation->type !== 'opening_stock';

        $request->validate([
            'transaction_date'  => 'required|date',
            'delivery_date'     => ($needsDelivery ? 'required' : 'nullable')
                                   . '|date|after_or_equal:transaction_date',
            'invoice_no'        => 'nullable|string|max:255|unique:mutations,invoice_no,' . $mutation->id,
            'external_sender'   => 'nullable|string|max:255',
            'external_receiver' => 'nullable|string|max:255',
            'notes'             => 'nullable|string',
            'items'             => 'required|array|min:1',
            'items.*.item_id'   => 'required|exists:mutation_items,id',
            'items.*.qty_crate' => 'nullable|integer|min:0',
            'items.*.qty_pack'  => 'nullable|integer|min:0',
            'items.*.qty_base'  => 'nullable|numeric|min:0',
            'items.*.price_per_base' => 'required|numeric|min:0',
        ], [
            'delivery_date.required'       => 'Tanggal penerimaan wajib diisi sebelum konfirmasi.',
            'delivery_date.after_or_equal' => 'Tanggal penerimaan tidak boleh lebih awal dari tanggal pengiriman.',
            'invoice_no.unique'            => 'No. SJ sudah dipakai di mutasi lain. Gunakan nomor yang berbeda.',
        ]);

        // Lock periode oleh opname / snapshot HPP
        $txDateStr = $request->delivery_date ?: $request->transaction_date;
        $c = \Carbon\Carbon::parse($txDateStr);
        foreach (array_filter([$mutation->destination_store_id, $mutation->source_store_id]) as $sid) {
            if (\App\Models\Opname::isDateLocked((int)$sid, $txDateStr)) {
                return back()->withInput()->with('error', \App\Models\Opname::lockMessageFor((int)$sid));
            }
            if (\App\Models\HppSnapshot::isDateLocked((int)$sid, $txDateStr)) {
                return back()->withInput()->with('error', \App\Models\HppSnapshot::lockMessageFor((int)$sid, $c->month, $c->year));
            }
        }

        DB::transaction(function () use ($request, $mutation) {
            $mutation->update([
                'transaction_date' => $request->transaction_date,
                'delivery_date'    => $request->delivery_date ?: null,
                'invoice_no'       => $request->invoice_no,
                'notes'            => $request->notes,
            ] + ($mutation->type === 'sale_external' && $request->filled('external_sender')
                    ? ['external_sender' => $request->external_sender] : [])
              + ($mutation->type === 'sale_external_out' && $request->filled('external_receiver')
                    ? ['external_receiver' => $request->external_receiver] : []));

            foreach ($request->items as $itemData) {
                $item = $mutation->items->firstWhere('id', $itemData['item_id']);
                if (!$item) continue;

                $totalInBase = $this->convertToBaseFromItem($item, $itemData);
                $item->update([
                    'qty_crate'      => $itemData['qty_crate'] ?? null,
                    'qty_pack'       => $itemData['qty_pack'] ?? null,
                    'qty_base'       => $itemData['qty_base'] ?? null,
                    'total_in_base'  => $totalInBase,
                    'price_per_base' => $itemData['price_per_base'],
                    'cost_subtotal'  => $totalInBase * $itemData['price_per_base'],
                    'remaining_qty'  => $totalInBase,
                ]);
            }
        });

        // Jika user klik "Konfirmasi Sekarang"
        if ($request->action === 'confirm') {
            $mutation->load('items');
            MutationService::confirm($mutation);
            return redirect()->route('inventory.mutations.show', $mutation)
                ->with('success', 'Mutasi dikonfirmasi. Stok telah diupdate.');
        }

        return redirect()->route('inventory.mutations.show', $mutation)
            ->with('success', 'Draft berhasil diperbarui.');
    }

    private function convertToBaseFromItem($item, array $data): float
    {
        if ($item->packaging_id) {
            $packaging = $item->packaging ?? IngredientPackaging::find($item->packaging_id);
            if ($packaging) {
                return $packaging->convertToBase(
                    (int)($data['qty_crate'] ?? 0),
                    (int)($data['qty_pack'] ?? 0),
                    (float)($data['qty_base'] ?? 0)
                );
            }
        }
        return (float)($data['qty_base'] ?? 0);
    }

    public function destroy(Mutation $mutation)
    {
        // ── Lock check ──────────────────────────────────────────────────────────
        $txDate  = $mutation->transaction_date;
        $txMonth = $txDate->month;
        $txYear  = $txDate->year;
        if (MonthLockService::isLocked('mutation', $mutation->id, $txMonth, $txYear)) {
            return redirect()->route('inventory.mutations.show', $mutation)
                ->with('error', MonthLockService::lockMessage($txMonth, $txYear));
        }

        // ── Lock periode oleh opname / snapshot HPP ──────────────────────────────
        // Hanya mutasi CONFIRMED yang memengaruhi FIFO/valuasi historis. Bila
        // tanggalnya sudah ditutup opname approved atau snapshot HPP, menghapusnya
        // akan merusak stok & valuasi yang sudah dibekukan → blokir (konsisten dgn
        // store()/confirm()/update()). Draft tidak menyentuh stok, tetap boleh dihapus.
        if ($mutation->status === 'confirmed') {
            $lockDate = ($mutation->delivery_date ?? $mutation->transaction_date)->toDateString();
            $lc = \Carbon\Carbon::parse($lockDate);
            foreach (array_filter([$mutation->destination_store_id, $mutation->source_store_id]) as $sid) {
                if (\App\Models\Opname::isDateLocked((int)$sid, $lockDate)) {
                    return redirect()->route('inventory.mutations.show', $mutation)
                        ->with('error', \App\Models\Opname::lockMessageFor((int)$sid));
                }
                if (\App\Models\HppSnapshot::isDateLocked((int)$sid, $lockDate)) {
                    return redirect()->route('inventory.mutations.show', $mutation)
                        ->with('error', \App\Models\HppSnapshot::lockMessageFor((int)$sid, $lc->month, $lc->year));
                }
            }
        }

        DB::transaction(function () use ($mutation) {
            // Jika sudah confirmed, hapus ledger dan hitung ulang saldo stok
            if ($mutation->status === 'confirmed') {
                // Kumpulkan pasangan (store_id, ingredient_id) yang terpengaruh (dari ledger)
                $affectedPairs = StockLedger::where('reference_type', 'Mutation')
                    ->where('reference_id', $mutation->id)
                    ->get(['store_id', 'ingredient_id'])
                    ->unique(fn($e) => $e->store_id . '-' . $e->ingredient_id)
                    ->values();

                // Hapus ledger entries mutasi ini
                StockLedger::where('reference_type', 'Mutation')
                    ->where('reference_id', $mutation->id)
                    ->delete();

                // Hitung ulang balance_after dari awal untuk setiap pasangan
                foreach ($affectedPairs as $pair) {
                    $entries = StockLedger::where('store_id', $pair->store_id)
                        ->where('ingredient_id', $pair->ingredient_id)
                        ->orderBy('movement_date')
                        ->orderBy('id')
                        ->get();

                    $balance = 0;
                    foreach ($entries as $entry) {
                        $balance += $entry->qty_change;
                        $entry->update(['balance_after' => $balance]);
                    }

                    StoreStock::updateOrCreate(
                        ['store_id' => $pair->store_id, 'ingredient_id' => $pair->ingredient_id],
                        ['stock_balance' => $balance]
                    );
                }
            }

            // Kumpulkan data untuk FIFO recalculate SEBELUM items dihapus.
            // Dua arah:
            //  - source store: sale_internal mendeduct stok dari toko pengirim.
            //  - destination store: pembelian / opening_stock / sale_external adalah
            //    batch MASUK. Menghapusnya membuat batch hilang, sehingga remaining_qty
            //    batch lain (yang menyerap deduksi) jadi basi → WAJIB recalc juga.
            // Pakai key unik supaya tidak recalc ganda untuk pasangan yang sama.
            $fifoRecalcPairs = [];
            if ($mutation->status === 'confirmed') {
                $mutation->loadMissing('items');
                foreach ($mutation->items as $item) {
                    if ($mutation->source_store_id && in_array($mutation->type, ['sale_internal','sale_external_out'])) {
                        $fifoRecalcPairs[$mutation->source_store_id . '-' . $item->ingredient_id] = [
                            'store_id'      => $mutation->source_store_id,
                            'ingredient_id' => $item->ingredient_id,
                        ];
                    }
                    if ($mutation->destination_store_id) {
                        $fifoRecalcPairs[$mutation->destination_store_id . '-' . $item->ingredient_id] = [
                            'store_id'      => $mutation->destination_store_id,
                            'ingredient_id' => $item->ingredient_id,
                        ];
                    }
                }
            }

            $mutation->items()->delete();
            $mutation->delete();

            // Hitung ulang FIFO remaining_qty untuk toko terdampak
            // (dilakukan SETELAH delete supaya batch/deduksi yang dihapus tidak ikut terhitung)
            foreach ($fifoRecalcPairs as $pair) {
                FifoService::recalculate($pair['store_id'], $pair['ingredient_id']);
            }
        });

        return redirect()->route('inventory.mutations.index')
            ->with('success', 'Mutasi berhasil dihapus.');
    }

    public function show(Mutation $mutation)
    {
        $mutation->load(['items.ingredient','items.packaging','destinationStore','sourceStore','supplier','createdBy','confirmedBy']);

        // Lock info
        $txDate    = $mutation->transaction_date;
        $txMonth   = $txDate->month;
        $txYear    = $txDate->year;
        $isLocked  = MonthLockService::isLocked('mutation', $mutation->id, $txMonth, $txYear);
        $isPastLock = MonthLockService::isPastLock($txMonth, $txYear);
        $hasPending = UnlockRequest::hasPendingRequest('mutation', $mutation->id);
        $hasUnlock  = UnlockRequest::hasApprovedUnlock('mutation', $mutation->id);

        return view('inventory.mutations.show', compact(
            'mutation', 'isLocked', 'isPastLock', 'hasPending', 'hasUnlock', 'txMonth', 'txYear'
        ));
    }

    public function confirm(Mutation $mutation)
    {
        abort_if($mutation->status !== 'draft', 422, 'Hanya mutasi draft yang bisa dikonfirmasi.');

        // Tanggal penerimaan wajib ada sebelum konfirmasi (kecuali opening_stock)
        if ($mutation->type !== 'opening_stock' && !$mutation->delivery_date) {
            return back()->with('error',
                'Tanggal penerimaan belum diisi. Edit draft ini dan isi tanggal penerimaan terlebih dahulu.');
        }

        // Lock periode oleh opname / snapshot HPP
        $txDateStr = ($mutation->delivery_date ?? $mutation->transaction_date)->format('Y-m-d');
        $c = \Carbon\Carbon::parse($txDateStr);
        foreach (array_filter([$mutation->destination_store_id, $mutation->source_store_id]) as $sid) {
            if (\App\Models\Opname::isDateLocked((int)$sid, $txDateStr)) {
                return back()->with('error', \App\Models\Opname::lockMessageFor((int)$sid));
            }
            if (\App\Models\HppSnapshot::isDateLocked((int)$sid, $txDateStr)) {
                return back()->with('error', \App\Models\HppSnapshot::lockMessageFor((int)$sid, $c->month, $c->year));
            }
        }

        MutationService::confirm($mutation);
        return back()->with('success', 'Mutasi berhasil dikonfirmasi. Stok telah diupdate.');
    }

    public function cancel(Mutation $mutation)
    {
        // ── Lock check ──────────────────────────────────────────────────────────
        $txDate  = $mutation->transaction_date;
        $txMonth = $txDate->month;
        $txYear  = $txDate->year;
        if (MonthLockService::isLocked('mutation', $mutation->id, $txMonth, $txYear)) {
            return back()->with('error', MonthLockService::lockMessage($txMonth, $txYear));
        }

        MutationService::cancel($mutation);
        return back()->with('success','Mutasi dibatalkan.');
    }

    // API: ambil harga terakhir bahan dari pembelian
    // API: harga TERAKHIR pembelian utk bahan tertentu — GLOBAL (semua toko),
    // PER kemasan (kalau packaging_id diberikan), dan bisa difilter tipe.
    public function lastPrice(Ingredient $ingredient, Request $request)
    {
        $packagingId = $request->packaging_id;
        $type        = $request->type; // mis. 'purchase_zhisheng'

        $base = fn() => MutationItem::query()
            ->join('mutations', 'mutations.id', '=', 'mutation_items.mutation_id')
            ->where('mutations.status', 'confirmed')
            ->where('mutation_items.ingredient_id', $ingredient->id)
            ->where('mutation_items.price_per_base', '>', 0)
            ->when($packagingId, fn($q) => $q->where('mutation_items.packaging_id', $packagingId))
            ->orderByRaw('COALESCE(mutations.delivery_date, mutations.transaction_date) DESC')
            ->orderByDesc('mutation_items.id');

        // 1) Prioritas: pembelian dengan tipe yang sama
        $types = $type ? [$type] : ['purchase_zhisheng', 'purchase_supplier'];
        $priceBase = (float) ($base()->whereIn('mutations.type', $types)->value('mutation_items.price_per_base') ?? 0);

        // 2) Fallback: harga terakhir dari sumber manapun (mis. opening_stock dari opname)
        //    agar tetap ada referensi walau belum pernah ada pembelian tipe ini.
        if ($priceBase <= 0) {
            $priceBase = (float) ($base()->value('mutation_items.price_per_base') ?? 0);
        }
        // price_per_dus jika packaging diberikan
        $priceDus = 0;
        if ($packagingId && $priceBase > 0) {
            $pkg = IngredientPackaging::find($packagingId);
            if ($pkg) {
                $crateToBase = (float) $pkg->crate_to_pack * (float) $pkg->pack_to_base;
                $priceDus    = $crateToBase > 0 ? (int) round($priceBase * $crateToBase) : 0;
            }
        }

        return response()->json([
            'price_per_base' => $priceBase,
            'price_per_dus'  => $priceDus,
        ]);
    }

    // API: ambil info harga stok bahan di toko tertentu (untuk pembelian internal)
    // Return: weighted average + detail batch FIFO
    public function stockPrice(Ingredient $ingredient, Request $request)
    {
        $storeId    = $request->store_id;
        $packagingId = $request->packaging_id; // OPTIONAL: filter batch by packaging tertentu

        // Ambil semua batch yang masih ada sisa (remaining_qty > 0) di toko ini
        $query = MutationItem::with('mutation')
            ->whereHas('mutation', fn($q) =>
                $q->where('destination_store_id', $storeId)
                  ->where('status', 'confirmed')
                  ->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'opening_stock', 'sale_internal', 'sale_external'])
            )
            ->where('ingredient_id', $ingredient->id)
            ->where('remaining_qty', '>', 0)
            ->orderBy('id');

        // Filter by packaging kalau user sudah pilih
        if ($packagingId) {
            $query->where('packaging_id', $packagingId);
        }

        $batches = $query->get(['id', 'price_per_base', 'remaining_qty', 'packaging_id']);

        // Eceran (pcs/gr) sudah tidak masuk FIFO — jadi tidak perlu dibuang lagi di sini.
        // Batch FIFO hanya berisi pack utuh; transfer/penjualan ambil pack utuh apa adanya.
        $batches = $batches->filter(fn($b) => (float) $b->remaining_qty > 0)->values();

        if ($batches->isEmpty()) {
            return response()->json([
                'avg_price_per_base' => 0,
                'batches'            => [],
            ]);
        }

        $totalQty   = $batches->sum('remaining_qty');
        $totalValue = $batches->sum(fn($b) => $b->remaining_qty * $b->price_per_base);
        $avgBase    = $totalQty > 0 ? $totalValue / $totalQty : 0;

        // Pakai packaging yg DIPILIH user. Kalau kosong, fallback ke packaging pertama.
        $packaging = $packagingId
            ? $ingredient->packagings()->where('id', $packagingId)->first()
            : $ingredient->packagings()->where('is_active', true)->orderBy('id')->first();
        $crateToBase = $packaging ? ($packaging->crate_to_pack * $packaging->pack_to_base) : 0;

        // Load semua packaging untuk lookup konversi per batch
        $allPackagings = $ingredient->packagings->keyBy('id');
        $defaultPkgId  = $ingredient->packagings()->where('is_active', true)->orderBy('id')->first()?->id;

        // Gabungkan batch by (packaging_id × price_per_base) — jangan campur kemasan beda
        $groupedBatches = $batches
            ->groupBy(fn($b) => $b->packaging_id . '_' . $b->price_per_base)
            ->map(function ($group) use ($allPackagings, $defaultPkgId) {
                $first         = $group->first();
                $priceBase     = (float) $first->price_per_base;
                $batchPkgId    = $first->packaging_id ?: $defaultPkgId;
                $batchPkg      = $allPackagings[$batchPkgId] ?? null;
                $batchCtb      = $batchPkg ? $batchPkg->crate_to_pack * $batchPkg->pack_to_base : 0;
                $batchPtb      = $batchPkg ? (float) $batchPkg->pack_to_base : 0;

                return [
                    'remaining_qty'   => (float) $group->sum('remaining_qty'),
                    'price_per_base'  => $priceBase,
                    'packaging_id'    => $first->packaging_id,
                    'packaging_name'  => $batchPkg?->packaging_name ?? '(Tanpa Kemasan)',
                    'crate_to_pack'   => $batchPkg?->crate_to_pack ?? 0,
                    'pack_to_base'    => $batchPtb,
                    // Harga per dus pakai konversi PACKAGING BATCH-nya sendiri, floor utk recover input asli
                    'price_per_crate' => $batchCtb > 0 ? (int) round($priceBase * $batchCtb) : 0,
                    'price_per_pack'  => $batchPtb > 0 ? (int) round($priceBase * $batchPtb) : 0,
                ];
            })
            ->values();

        return response()->json([
            'avg_price_per_base'  => round($avgBase, 6),
            'avg_price_per_crate' => $crateToBase > 0 ? (int) round($avgBase * $crateToBase) : 0,
            'crate_to_base'       => $crateToBase,
            'batches'             => $groupedBatches,
        ]);
    }

    // API: ringkasan stok toko — ingredient + qty TOTAL + qty PER packaging
    public function storeStockSummary(Store $store)
    {
        $items = MutationItem::whereHas('mutation', fn($q) =>
            $q->where('destination_store_id', $store->id)
              ->where('status','confirmed')
              ->whereIn('type',['purchase_zhisheng','purchase_supplier','opening_stock','sale_internal','sale_external'])
        )
        ->where('remaining_qty','>',0)
        ->get(['ingredient_id','remaining_qty','packaging_id']);

        // Eceran (pcs/gr) opname terakhir per (bahan, kemasan) — untuk DIABAIKAN (pack segel saja).
        $lastOpId = \App\Models\Opname::where('store_id',$store->id)->where('status','approved')
            ->orderByDesc('opname_date')->orderByDesc('id')->value('id');
        $looseMap = $lastOpId
            ? \App\Models\OpnameItem::where('opname_id',$lastOpId)->whereNotNull('packaging_id')
                ->where('physical_base','>',0)->get(['ingredient_id','packaging_id','physical_base'])
                ->mapWithKeys(fn($i)=>[$i->ingredient_id.'-'.$i->packaging_id => (float)$i->physical_base])->all()
            : [];

        $data = $items->groupBy('ingredient_id')->map(function ($g) {
            // Qty per packaging_id (NULL → key 0). FIFO hanya berisi pack utuh; eceran
            // sudah tidak masuk stok, jadi tidak perlu dikurangi lagi.
            $perPackaging = $g->groupBy(fn($r) => $r->packaging_id ?: 0)
                ->map(fn($pg) => (float) $pg->sum('remaining_qty'));
            return [
                'qty'           => (float) $perPackaging->sum(),
                'packagings'    => $g->pluck('packaging_id')->filter()->unique()->values(),
                'per_packaging' => $perPackaging, // {pkg_id: qty_base SEGEL}
            ];
        });

        return response()->json($data);
    }

    public function export(Request $request)
    {
        $storeIds = auth()->user()->accessibleStoreIds();
        $query = Mutation::with(['supplier', 'sourceStore', 'destinationStore', 'items.ingredient'])
            ->where(function ($q) use ($storeIds) {
                $q->whereIn('destination_store_id', $storeIds)
                  ->orWhereIn('source_store_id', $storeIds);
            });

        if ($request->store_id) {
            $sid = $request->store_id;
            $query->where(function ($q) use ($sid) {
                $q->where('destination_store_id', $sid)->orWhere('source_store_id', $sid);
            });
        }
        if ($request->type)      $query->where('type', $request->type);
        if ($request->status)    $query->where('status', $request->status);
        if ($request->date_from) $query->where('transaction_date', '>=', $request->date_from);
        if ($request->date_to)   $query->where('transaction_date', '<=', $request->date_to);

        $mutations = $query->orderBy('transaction_date')->get();

        $data = [['Tgl Transaksi', 'No SJ/Ref', 'No Invoice', 'Tipe', 'Status', 'Pengirim/Supplier', 'Toko Tujuan', 'Bahan', 'Qty Base', 'Harga/Base', 'Subtotal']];
        foreach ($mutations as $m) {
            $items = $m->items;
            if ($items->isEmpty()) {
                $data[] = [
                    $m->transaction_date->format('d/m/Y'),
                    $m->reference_no ?? '-', $m->invoice_no ?? '-',
                    $m->type_label ?? $m->type, $m->status,
                    $m->supplier?->name ?? $m->sourceStore?->name ?? '-',
                    $m->destinationStore?->name ?? '-',
                    '-', '-', '-', '-',
                ];
            } else {
                foreach ($items as $k => $item) {
                    $data[] = [
                        $k === 0 ? $m->transaction_date->format('d/m/Y') : '',
                        $k === 0 ? ($m->reference_no ?? '-') : '',
                        $k === 0 ? ($m->invoice_no ?? '-') : '',
                        $k === 0 ? ($m->type_label ?? $m->type) : '',
                        $k === 0 ? $m->status : '',
                        $k === 0 ? ($m->supplier?->name ?? $m->sourceStore?->name ?? '-') : '',
                        $k === 0 ? ($m->destinationStore?->name ?? '-') : '',
                        $item->ingredient?->name ?? '-',
                        $item->total_in_base,
                        $item->price_per_base,
                        $item->cost_subtotal,
                    ];
                }
            }
        }
        $data[] = ['', '', '', '', '', '', '', '', '', 'TOTAL',
            $mutations->flatMap->items->sum('cost_subtotal')];

        return Excel::download(new ArrayExport($data), 'mutasi_' . now()->format('Y-m-d') . '.xlsx');
    }

    private function convertToBase(array $item): float
    {
        if (!empty($item['packaging_id'])) {
            $packaging = IngredientPackaging::find($item['packaging_id']);
            if ($packaging) {
                return $packaging->convertToBase(
                    (int)($item['qty_crate'] ?? 0),
                    (int)($item['qty_pack'] ?? 0),
                    (float)($item['qty_base'] ?? 0)
                );
            }
        }
        return (float)($item['qty_base'] ?? 0);
    }
}
