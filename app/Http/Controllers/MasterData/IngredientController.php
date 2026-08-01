<?php
namespace App\Http\Controllers\MasterData;
use App\Http\Controllers\Controller;
use App\Models\{Ingredient, IngredientCategory, IngredientComposition, IngredientPackaging, Supplier};
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $query = Ingredient::query()
            ->leftJoin('ingredient_categories as ic', 'ingredients.category', '=', 'ic.name')
            ->select('ingredients.*');
        if ($request->search)
            $query->where('ingredients.name', 'like', "%{$request->search}%");
        if ($request->type)
            $query->where('ingredients.type', $request->type);
        if ($request->category)
            $query->where('ingredients.category', $request->category);

        // Urut per kategori sesuai master (Solid, Bubuk, Sirup, …); tanpa kategori di akhir.
        // Dalam tiap kategori: ikut urutan input (urutan file Excel) = urutan id.
        $ingredients = $query->orderByRaw('ic.sort_order IS NULL')
            ->orderBy('ic.sort_order')
            ->orderBy('ingredients.id')
            ->paginate(20)->withQueryString();

        $categories = IngredientCategory::ordered()->get();
        return view('master.ingredients.index', compact('ingredients', 'categories'));
    }

    public function create()
    {
        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get();
        $rawIngredients = Ingredient::where('ingredients.is_active', true)->where('type', 'raw')->orderedByCategory()->get();
        $categories = IngredientCategory::ordered()->get();
        return view('master.ingredients.form', compact('suppliers', 'rawIngredients', 'categories'));
    }

    /** Parse angka berformat Indonesia: "3.000" => 3000 ; "0,5" => 0.5 */
    private function num($v): float
    {
        if ($v === null || $v === '') return 0.0;
        return (float) str_replace(',', '.', str_replace('.', '', (string) $v));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'type' => 'required|in:raw,semi_finished',
            'category' => 'nullable|in:' . implode(',', IngredientCategory::orderedNames()),
            'unit_base' => 'required|in:gram,pcs',
        ]);
        $data['is_active'] = $request->has('is_active');
        $data['ideal_follows_actual'] = $request->has('ideal_follows_actual');
        $data['category'] = $request->type === 'raw' ? $request->category : null;

        $ingredient = Ingredient::create($data);

        if ($request->type === 'raw') {
            foreach ($request->input('packagings', []) as $pack) {
                if (empty($pack['packaging_name']) || empty($pack['crate_to_pack']))
                    continue;
                $ingredient->packagings()->create([
                    'supplier_id' => $pack['supplier_id'] ?: null,
                    'packaging_name' => $pack['packaging_name'],
                    'crate_to_pack' => $pack['crate_to_pack'],
                    'pack_to_base' => (float) str_replace(',', '.', $pack['pack_to_base'] ?? 0),
                    'is_active' => true,
                ]);
            }
        }

        // Simpan komposisi (bahan setengah jadi)
        if ($request->type === 'semi_finished') {
            $totalOutput = $this->num($request->input('total_output'));
            if ($totalOutput <= 0) $totalOutput = 1;
            foreach ($request->input('compositions', []) as $comp) {
                if (empty($comp['child_id']) || empty($comp['qty_used'])) continue;
                IngredientComposition::create([
                    'parent_id'  => $ingredient->id,
                    'child_id'   => $comp['child_id'],
                    'qty_needed' => $this->num($comp['qty_used']) / $totalOutput,
                ]);
            }
        }

        if ($request->input('action') === 'save_and_new') {
            return redirect()->route('master.ingredients.create')
                ->with('success', "Bahan \"{$ingredient->name}\" ditambahkan. Silakan input bahan berikutnya.");
        }

        return redirect()->route('master.ingredients.index')->with('success', 'Bahan ditambahkan.');
    }

    public function show(Ingredient $ingredient)
    {
        $ingredient->load(['packagings.supplier', 'compositions.child']);
        return view('master.ingredients.show', compact('ingredient'));
    }

    public function edit(Ingredient $ingredient)
    {
        $ingredient->load(['packagings.supplier', 'compositions.child']);
        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get();
        $rawIngredients = Ingredient::where('ingredients.is_active', true)->where('type', 'raw')->orderedByCategory()->get();
        $categories = IngredientCategory::ordered()->get();
        return view('master.ingredients.form', compact('ingredient', 'suppliers', 'rawIngredients', 'categories'));
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'type' => 'required|in:raw,semi_finished',
            'category' => 'nullable|in:' . implode(',', IngredientCategory::orderedNames()),
            'unit_base' => 'required|in:gram,pcs',
        ]);
        $data['is_active'] = $request->has('is_active');
        $data['ideal_follows_actual'] = $request->has('ideal_follows_actual');
        $data['category'] = $request->type === 'raw' ? $request->category : null;
        $ingredient->update($data);

        if ($request->type === 'raw') {
            // Update kemasan yang sudah ada
            foreach ($request->input('existing_packagings', []) as $packId => $pack) {
                if (empty($pack['packaging_name']) || empty($pack['crate_to_pack']))
                    continue;
                $ingredient->packagings()->where('id', $packId)->update([
                    'supplier_id' => $pack['supplier_id'] ?: null,
                    'packaging_name' => $pack['packaging_name'],
                    'crate_to_pack' => $pack['crate_to_pack'],
                    'pack_to_base' => (float) str_replace(',', '.', $pack['pack_to_base'] ?? 0),
                ]);
            }
            // Tambah kemasan baru
            foreach ($request->input('packagings', []) as $pack) {
                if (empty($pack['packaging_name']) || empty($pack['crate_to_pack']))
                    continue;
                $ingredient->packagings()->create([
                    'supplier_id' => $pack['supplier_id'] ?: null,
                    'packaging_name' => $pack['packaging_name'],
                    'crate_to_pack' => $pack['crate_to_pack'],
                    'pack_to_base' => (float) str_replace(',', '.', $pack['pack_to_base'] ?? 0),
                    'is_active' => true,
                ]);
            }
        }

        $totalOutput = $this->num($request->input('total_output'));
        if ($totalOutput <= 0) $totalOutput = 1;
        foreach ($request->input('compositions', []) as $comp) {
            if (empty($comp['child_id']) || empty($comp['qty_used'])) {
                continue;
            }
            IngredientComposition::updateOrCreate(
                [
                    'parent_id' => $ingredient->id,
                    'child_id'  => $comp['child_id'],
                ],
                [
                    'qty_needed' => $this->num($comp['qty_used']) / $totalOutput,
                ]
            );
        }

        // Hapus komposisi yang di-request untuk dihapus
        foreach ($request->input('delete_compositions', []) as $compId) {
            IngredientComposition::where('id', $compId)->where('parent_id', $ingredient->id)->delete();
        }

        return redirect()->route('master.ingredients.index')->with('success', 'Bahan diupdate.');
    }

    public function destroy(Ingredient $ingredient)
    {
        $hasData = \App\Models\MutationItem::where('ingredient_id', $ingredient->id)->exists()
            || \App\Models\Recipe::where('ingredient_id', $ingredient->id)->exists()
            || \App\Models\DailyUsage::where('ingredient_id', $ingredient->id)->exists()
            || \App\Models\OpnameItem::where('ingredient_id', $ingredient->id)->exists()
            || \App\Models\StockLedger::where('ingredient_id', $ingredient->id)->exists()
            || \App\Models\StoreStock::where('ingredient_id', $ingredient->id)
                ->where('stock_balance', '>', 0)->exists()
            || IngredientComposition::where('child_id', $ingredient->id)
                ->orWhere('parent_id', $ingredient->id)->exists()
            || \App\Models\WasteLogItem::where('ingredient_id', $ingredient->id)->exists()
            || \App\Models\ProductionLogItem::where('raw_ingredient_id', $ingredient->id)->exists();

        if ($hasData) {
            return back()->with(
                'error',
                'Bahan "' . $ingredient->name . '" tidak bisa dihapus karena sudah dipakai di transaksi/resep. '
                . 'Nonaktifkan saja kalau tidak ingin digunakan lagi.'
            );
        }

        // Hapus kemasan dulu (cascade-ish)
        $ingredient->packagings()->delete();
        $ingredient->delete();
        return back()->with('success', 'Bahan dihapus.');
    }

    public function packagings(Ingredient $ingredient)
    {
        return response()->json($ingredient->packagings()->where('is_active', true)->get());
    }

    public function compositions(Ingredient $ingredient)
    {
        return response()->json($ingredient->compositions()->with('child')->get());
    }
}
