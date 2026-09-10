<?php
namespace App\Http\Controllers\MasterData;
use App\Http\Controllers\Controller;
use App\Models\{Recipe, Menu, Ingredient, Store};
use Illuminate\Http\Request;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $query = Recipe::with(['menu', 'store', 'ingredient', 'createdBy']);
        if ($request->menu_id) $query->where('menu_id', $request->menu_id);
        // Filter toko TIDAK dipasang di query: daftar tokonya harus dihitung dari
        // seluruh baris satu versi, jadi penyaringan dilakukan setelah digabung.
        $semua = $query->get();

        // ── Satukan versi yang isinya SAMA PERSIS ────────────────────────────
        // Dua toko yang menyimpan resep modifikasi identik pada tanggal berlaku
        // yang sama tersimpan sebagai dua recipe_group_id terpisah. Untuk dilihat,
        // itu satu resep yang sama — jadi ditampilkan SATU baris dengan dua badge
        // toko, bukan dua baris kembar. Datanya tetap terpisah di database supaya
        // salah satu toko masih bisa diubah sendiri nanti.
        //
        // Sidik jari versi = menu + tanggal berlaku + cakupan + set bahan/qty/unit.
        // Cakupan (default vs khusus toko) ikut dibedakan: resep default berlaku
        // untuk semua toko, sedangkan resep khusus menimpa default hanya di toko
        // itu — dua hal berbeda, tidak boleh ditampilkan sebagai satu baris.
        $versi = $semua->groupBy('recipe_group_id')->map(function ($rows) {
            $r0    = $rows->first();
            $bahan = $rows->unique('ingredient_id')->sortBy('ingredient_id')
                ->map(fn($r) => $r->ingredient_id . ':' . (float) $r->qty_usage . ':' . $r->unit)
                ->implode('|');
            $cakupan = $rows->contains(fn($r) => $r->store_id === null) ? 'DEFAULT' : 'TOKO';
            return [
                'sidik' => $r0->menu_id . '|' . $r0->effective_from->toDateString()
                         . '|' . $cakupan . '|' . $bahan,
                'rows'  => $rows,
            ];
        });

        $gabung = $versi->groupBy('sidik')->map(function ($grup) {
            $rows = collect();
            foreach ($grup as $v) $rows = $rows->merge($v['rows']);
            return $rows;
        })->values();

        if ($request->store_id) {
            $sid    = $request->store_id === 'default' ? null : (int) $request->store_id;
            $gabung = $gabung->filter(
                fn($rows) => $rows->contains(fn($r) => $r->store_id === $sid)
            )->values();
        }

        $gabung  = $gabung->sortByDesc(fn($rows) => $rows->max('created_at'))->values();
        $recipes = $this->paginasi($gabung, $request, 20);

        $menus   = Menu::where('is_active', true)->orderBy('name')->get();
        $stores  = Store::where('is_active', true)->orderBy('name')->get();
        return view('master.recipes.index', compact('recipes', 'menus', 'stores'));
    }

    /** Paginasi manual: satuannya VERSI resep, bukan baris bahan. */
    private function paginasi($items, Request $request, int $perPage)
    {
        $page = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();
        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }
    public function create()
    {
        $menus       = Menu::where('is_active', true)->orderBy('name')->get();
        $ingredients = Ingredient::where('ingredients.is_active', true)->orderedByCategory()->get();
        $stores      = Store::where('is_active', true)->orderBy('name')->get();
        return view('master.recipes.form', compact('menus', 'ingredients', 'stores'));
    }
    public function duplicate(Recipe $recipe)
    {
        // Semua resep dalam 1 versi share recipe_group_id (set bahan sama untuk N toko)
        $sourceItems = Recipe::where('recipe_group_id', $recipe->recipe_group_id)
            ->with('ingredient')->get()
            ->unique('ingredient_id')->values();

        $menus       = Menu::where('is_active', true)->orderBy('name')->get();
        $ingredients = Ingredient::where('ingredients.is_active', true)->orderedByCategory()->get();
        $stores      = Store::where('is_active', true)->orderBy('name')->get();

        return view('master.recipes.form', compact('menus', 'ingredients', 'stores', 'sourceItems', 'recipe'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'menu_id'               => 'required|exists:menus,id',
            'store_ids'             => 'nullable|array',
            'store_ids.*'           => 'exists:stores,id',
            'effective_from'        => 'required|date',
            'items'                 => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|exists:ingredients,id',
            'items.*.qty_usage'     => 'required|numeric|min:0.001',
            'items.*.unit'          => 'required|string',
        ]);
        $storeIds = $request->input('store_ids', []);
        if (empty($storeIds)) $storeIds = [null]; // tidak ada ceklis = default semua toko
        $groupId  = (string) \Illuminate\Support\Str::uuid();

        foreach ($storeIds as $sid) {
            foreach ($request->items as $item) {
                Recipe::create([
                    'menu_id'         => $request->menu_id,
                    'store_id'        => $sid ?: null,
                    'recipe_group_id' => $groupId,
                    'ingredient_id'   => $item['ingredient_id'],
                    'qty_usage'       => $item['qty_usage'],
                    'unit'            => $item['unit'],
                    'effective_from'  => $request->effective_from,
                    'created_by'      => auth()->id(),
                ]);
            }
        }
        return redirect()->route('master.recipes.index')->with('success', 'Resep disimpan.');
    }
}
