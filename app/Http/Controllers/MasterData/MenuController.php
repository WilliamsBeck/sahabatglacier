<?php
namespace App\Http\Controllers\MasterData;
use App\Http\Controllers\Controller;
use App\Models\{Menu, MenuCategory, Recipe, Ingredient, Store};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MenuController extends Controller
{
    public function index(Request $request)
    {
        $query = Menu::select('menus.*',
                DB::raw('(SELECT COUNT(DISTINCT recipe_group_id) + (CASE WHEN SUM(CASE WHEN recipe_group_id IS NULL THEN 1 ELSE 0 END) > 0 THEN 1 ELSE 0 END) FROM recipes WHERE recipes.menu_id = menus.id) as recipe_versions_count')
            )
            ->leftJoin('menu_categories as mc', 'menus.category_id', '=', 'mc.id');

        if ($request->search)      $query->where('menus.name', 'like', "%{$request->search}%");
        if ($request->category_id) $query->where('menus.category_id', $request->category_id);

        $query->orderByRaw('mc.sort_order IS NULL')
              ->orderBy('mc.sort_order')
              ->orderBy('menus.id');

        $menus          = $query->paginate(20)->withQueryString();
        $menuCategories = MenuCategory::ordered()->get();
        return view('master.menus.index', compact('menus', 'menuCategories'));
    }

    public function create()
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403, 'Hanya Super Admin yang bisa menambah menu.');
        $ingredients     = Ingredient::where('ingredients.is_active', true)->orderedByCategory()->get();
        $menuCategories  = MenuCategory::ordered()->get();
        $stores          = Store::where('is_active', true)->orderBy('name')->get();
        return view('master.menus.form', compact('ingredients', 'menuCategories', 'stores'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403, 'Hanya Super Admin yang bisa menambah menu.');
        $request->validate([
            'name'                      => 'required|string',
            'category'                  => 'nullable|string',
            'items.*.ingredient_id'     => 'nullable|exists:ingredients,id',
            'items.*.qty_usage'         => 'nullable|integer|min:1',
        ]);

        DB::transaction(function () use ($request) {
            $menu = Menu::create([
                'name'        => $request->name,
                'category'    => $request->category,
                'category_id' => $request->category_id ?: null,
                'is_active'   => $request->has('is_active'),
                'count_in_total' => $request->has('count_in_total'),
            ]);

            $effectiveFrom = $request->effective_from ?? now()->toDateString();
            foreach ($request->input('items', []) as $item) {
                if (empty($item['ingredient_id']) || empty($item['qty_usage'])) continue;
                Recipe::create([
                    'menu_id'        => $menu->id,
                    'ingredient_id'  => $item['ingredient_id'],
                    'qty_usage'      => $item['qty_usage'],
                    'unit'           => $item['unit'] ?? 'gram',
                    'effective_from' => $effectiveFrom,
                    'created_by'     => auth()->id(),
                ]);
            }
        });

        return redirect()->route('master.menus.index')->with('success', 'Menu dan resep berhasil disimpan.');
    }

    public function show(Menu $menu)
    {
        $menu->load(['recipes.ingredient', 'recipes.store']);
        $recipes = $menu->recipes()->orderByDesc('effective_from')->get()->groupBy('recipe_group_id');
        return view('master.menus.show', compact('menu', 'recipes'));
    }
    public function edit(Menu $menu)   { return $this->renderForm($menu); }

    private function renderForm(Menu $menu)
    {
        $menu->load(['recipes.ingredient', 'recipes.store']);
        // 1 versi = 1 recipe_group_id (banyak toko share resep yang sama)
        $recipes        = $menu->recipes()->orderByDesc('effective_from')->get()
            ->groupBy('recipe_group_id');
        $ingredients    = Ingredient::where('ingredients.is_active', true)->orderedByCategory()->get();
        $menuCategories = MenuCategory::ordered()->get();
        // Admin area: hanya boleh membuat versi resep utk toko yang dia pegang
        $stores         = auth()->user()->isSuperAdmin()
            ? Store::where('is_active', true)->orderBy('name')->get()
            : auth()->user()->accessibleStores()->where('is_active', true)->sortBy('name')->values();
        return view('master.menus.form', compact('menu', 'recipes', 'ingredients', 'menuCategories', 'stores'));
    }

    public function update(Request $request, Menu $menu)
    {
        $request->validate([
            'name'                      => 'required|string',
            'category'                  => 'nullable|string',
            'items.*.ingredient_id'     => 'nullable|exists:ingredients,id',
            'items.*.qty_usage'         => 'nullable|integer|min:1',
        ]);

        // Admin area: hanya boleh mengelola VERSI RESEP utk toko yang dia pegang.
        // Info menu (nama/kategori/status) tidak diubah, dan versi default (semua
        // toko) tidak boleh dibuat — wajib pilih toko miliknya.
        $isAdminArea = !auth()->user()->isSuperAdmin();
        if ($isAdminArea) {
            $storeIds   = array_map('intval', $request->input('store_ids', []));
            $accessible = auth()->user()->accessibleStoreIds();
            abort_if(empty($storeIds), 403, 'Pilih toko dulu — admin area tidak bisa membuat resep default (semua toko).');
            abort_if(count(array_diff($storeIds, $accessible)) > 0, 403, 'Anda hanya bisa membuat resep untuk toko yang Anda pegang.');
        }

        DB::transaction(function () use ($request, $menu, $isAdminArea) {
            if (!$isAdminArea) {
                $menu->update([
                    'name'        => $request->name,
                    'category'    => $request->category,
                    'category_id' => $request->category_id ?: null,
                    'is_active'   => $request->has('is_active'),
                'count_in_total' => $request->has('count_in_total'),
                ]);
            }

            // Simpan versi resep baru jika ada item yang diisi
            $hasItems = collect($request->input('items', []))
                ->filter(fn($i) => !empty($i['ingredient_id']) && !empty($i['qty_usage']))
                ->count() > 0;

            if ($hasItems) {
                $effectiveFrom = $request->effective_from ?? now()->toDateString();
                // store_ids = array toko (kosong = berlaku default semua toko = [null])
                $storeIds = $request->input('store_ids', []);
                if (empty($storeIds)) $storeIds = [null];
                else $storeIds = array_map(fn($s) => (int) $s, $storeIds);
                $groupId = (string) \Illuminate\Support\Str::uuid();

                // Hapus resep lama yg overlap (effective_from + store_id target)
                $menu->recipes()
                    ->where('effective_from', $effectiveFrom)
                    ->where(function ($q) use ($storeIds) {
                        if (in_array(null, $storeIds, true)) $q->orWhereNull('store_id');
                        $non = array_filter($storeIds, fn($s) => $s !== null);
                        if (!empty($non)) $q->orWhereIn('store_id', $non);
                    })
                    ->delete();

                foreach ($storeIds as $sid) {
                    foreach ($request->input('items', []) as $item) {
                        if (empty($item['ingredient_id']) || empty($item['qty_usage'])) continue;
                        Recipe::create([
                            'menu_id'         => $menu->id,
                            'store_id'        => $sid,
                            'recipe_group_id' => $groupId,
                            'ingredient_id'   => $item['ingredient_id'],
                            'qty_usage'       => $item['qty_usage'],
                            'unit'            => $item['unit'] ?? 'gram',
                            'effective_from'  => $effectiveFrom,
                            'created_by'      => auth()->id(),
                        ]);
                    }
                }
            }
        });

        return redirect()->route('master.menus.edit', $menu)->with('success', 'Menu berhasil diupdate.');
    }

    public function destroy(Menu $menu)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403, 'Hanya Super Admin yang bisa menghapus menu.');
        $hasData = \App\Models\MonthlySale::where('menu_id', $menu->id)->exists();

        if ($hasData) {
            return back()->with('error',
                'Menu "' . $menu->name . '" tidak bisa dihapus karena sudah ada data penjualan. '
                . 'Nonaktifkan menu jika tidak ingin digunakan lagi.');
        }

        // Hapus resep dulu (cascade) â€” resep tanpa transaksi aman dihapus
        $menu->recipes()->delete();
        $menu->delete();
        return back()->with('success', 'Menu dihapus.');
    }

    public function destroyRecipeVersion(Menu $menu, string $group)
    {
        $query = $group === 'kosong'
            ? $menu->recipes()->whereNull('recipe_group_id')
            : $menu->recipes()->where('recipe_group_id', $group);

        // Admin area: hanya boleh hapus versi milik toko yang dia pegang.
        // Versi default (store_id NULL) atau versi yang menyangkut toko lain = terlarang.
        if (!auth()->user()->isSuperAdmin()) {
            $verStoreIds = (clone $query)->pluck('store_id');
            $accessible  = auth()->user()->accessibleStoreIds();
            abort_if($verStoreIds->contains(null), 403, 'Versi default (semua toko) hanya bisa dihapus Super Admin.');
            abort_if($verStoreIds->filter(fn($s) => !in_array((int)$s, $accessible))->isNotEmpty(),
                403, 'Versi ini menyangkut toko di luar wewenang Anda.');
        }

        $query->delete();
        return back()->with('success', 'Versi resep dihapus.');
    }
}
