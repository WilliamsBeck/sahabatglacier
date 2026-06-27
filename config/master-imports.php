<?php

use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\IngredientComposition;
use App\Models\IngredientPackaging;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Recipe;
use App\Models\Store;
use App\Models\Supplier;

/*
|--------------------------------------------------------------------------
| Registry impor massal master data
|--------------------------------------------------------------------------
| Tiap entitas mendefinisikan kolom template, kunci unik untuk upsert, dan
| relasi yang diacu lewat nama. Dibaca oleh MasterImportService &
| MasterImportController. Lihat docs/superpowers/specs/2026-06-17-*.
|
| Struktur kolom:
|   header   : judul kolom di template (dicocokkan case-insensitive)
|   field    : nama atribut pada model
|   rules    : aturan validasi Laravel (per sel)
|   cast     : int|float|bool|string (opsional)
|   required : true bila wajib diisi
|
| Struktur relasi (key = nama header kolom sumber):
|   model    : class model acuan
|   match    : kolom yang dicocokkan (mis. 'name')
|   target   : atribut id pada model tujuan (mis. 'category_id')
|   nullable : boleh kosong
|   keep_name: simpan juga nilai string ke kolom 'field' sumber
|   label    : label untuk pesan error
*/

return [

    'ingredient-categories' => [
        'label'       => 'Kategori Bahan',
        'model'       => IngredientCategory::class,
        'route_index' => 'master.ingredient-categories.index',
        'unique_by'   => ['name'],
        'columns'     => [
            ['header' => 'name',       'field' => 'name',       'rules' => 'required|string|max:100', 'required' => true],
            ['header' => 'label',      'field' => 'label',      'rules' => 'nullable|string|max:100'],
            ['header' => 'sort_order', 'field' => 'sort_order', 'rules' => 'nullable|integer|min:0', 'cast' => 'int'],
        ],
        'sample_rows' => [
            ['solid', 'Solid', 1],
            ['bubuk', 'Bubuk', 2],
        ],
    ],

    'menu-categories' => [
        'label'       => 'Kategori Menu',
        'model'       => MenuCategory::class,
        'route_index' => 'master.menu-categories.index',
        'unique_by'   => ['name'],
        'columns'     => [
            ['header' => 'name',       'field' => 'name',       'rules' => 'required|string|max:100', 'required' => true],
            ['header' => 'sort_order', 'field' => 'sort_order', 'rules' => 'nullable|integer|min:0', 'cast' => 'int'],
        ],
        'sample_rows' => [
            ['Minuman', 1],
            ['Makanan', 2],
        ],
    ],

    'suppliers' => [
        'label'       => 'Supplier',
        'model'       => Supplier::class,
        'route_index' => 'master.suppliers.index',
        'unique_by'   => ['name'],
        'columns'     => [
            ['header' => 'name',      'field' => 'name',      'rules' => 'required|string|max:150', 'required' => true],
            ['header' => 'type',      'field' => 'type',      'rules' => 'required|in:zhisheng,local_supplier,other', 'required' => true, 'lower' => true],
            ['header' => 'contact',   'field' => 'contact',   'rules' => 'nullable|string|max:150'],
            ['header' => 'address',   'field' => 'address',   'rules' => 'nullable|string|max:255'],
        ],
        'sample_rows' => [
            ['PT Zhisheng Pacific Trading', 'zhisheng', '0211234567', 'Jakarta'],
            ['Toko Sayur Pak Budi', 'local_supplier', '08123456789', 'Bandung'],
        ],
    ],

    'stores' => [
        'label'       => 'Toko',
        'model'       => Store::class,
        'route_index' => 'master.stores.index',
        'unique_by'   => ['store_code'],
        'columns'     => [
            ['header' => 'store_code', 'field' => 'store_code', 'rules' => 'required|string|max:50', 'required' => true],
            ['header' => 'name',       'field' => 'name',       'rules' => 'required|string|max:150', 'required' => true],
            ['header' => 'area',       'field' => 'area',       'rules' => 'nullable|string|max:100'],
        ],
        'sample_rows' => [
            ['GBK-01', 'Glacier Senayan GBK', 'Jakarta'],
        ],
    ],

    'ingredients' => [
        'label'       => 'Bahan',
        'model'       => Ingredient::class,
        'route_index' => 'master.ingredients.index',
        'unique_by'   => ['name'],
        'columns'     => [
            ['header' => 'name',      'field' => 'name',      'rules' => 'required|string|max:150', 'required' => true],
            ['header' => 'type',      'field' => 'type',      'rules' => 'required|in:raw,semi_finished', 'required' => true, 'lower' => true],
            // Kategori divalidasi dinamis dari master "Kategori Bahan Baku" (ingredient_categories)
            ['header' => 'category',  'field' => 'category',  'rules' => 'nullable', 'lower' => true,
                'in_from' => ['model' => IngredientCategory::class, 'column' => 'name']],
            // unit_base mengikuti form: gram/pcs. Alias "gr","g" -> gram; "pc","buah" -> pcs.
            ['header' => 'unit_base', 'field' => 'unit_base', 'rules' => 'required|in:gram,pcs', 'required' => true, 'lower' => true,
                'map' => ['gr' => 'gram', 'g' => 'gram', 'gram' => 'gram', 'pcs' => 'pcs', 'pc' => 'pcs', 'buah' => 'pcs']],
        ],
        'sample_rows' => [
            ['Susu Cair UHT', 'raw', 'solid', 'ml'],
            ['Bubuk Greentea', 'raw', 'bubuk', 'gr'],
        ],
    ],

    'menus' => [
        'label'       => 'Menu',
        'model'       => Menu::class,
        'route_index' => 'master.menus.index',
        'unique_by'   => ['name'],
        'columns'     => [
            ['header' => 'name',      'field' => 'name',      'rules' => 'required|string|max:150', 'required' => true],
            ['header' => 'category',  'field' => 'category',  'rules' => 'required|string', 'required' => true],
        ],
        'relations' => [
            'category' => [
                'model' => MenuCategory::class, 'match' => 'name', 'target' => 'category_id',
                'nullable' => false, 'keep_name' => true, 'label' => 'Kategori Menu',
            ],
        ],
        'sample_rows' => [
            ['Es Teh Manis', 'Minuman'],
        ],
    ],

    'packagings' => [
        'label'       => 'Kemasan Bahan',
        'model'       => IngredientPackaging::class,
        'route_index' => 'master.ingredients.index',
        'unique_by'   => ['ingredient_id', 'packaging_name'],
        'columns'     => [
            ['header' => 'ingredient',     'field' => 'ingredient',     'rules' => 'required', 'required' => true],
            ['header' => 'packaging_name', 'field' => 'packaging_name', 'rules' => 'required|string|max:100', 'required' => true],
            ['header' => 'supplier',       'field' => 'supplier',       'rules' => 'nullable'],
            // Desimal diizinkan: kolom DB menyimpan pecahan (mis. pack_to_base 277,7778).
            ['header' => 'crate_to_pack',  'field' => 'crate_to_pack',  'rules' => 'required|numeric|gt:0', 'cast' => 'float', 'required' => true],
            ['header' => 'pack_to_base',   'field' => 'pack_to_base',   'rules' => 'required|numeric|gt:0', 'cast' => 'float', 'required' => true],
        ],
        'relations' => [
            'ingredient' => ['model' => Ingredient::class, 'match' => 'name', 'target' => 'ingredient_id', 'nullable' => false, 'label' => 'Bahan'],
            'supplier'   => ['model' => Supplier::class,   'match' => 'name', 'target' => 'supplier_id',   'nullable' => true,  'label' => 'Supplier'],
        ],
        'sample_rows' => [
            ['Susu Cair UHT', 'Karton 12x1L', 'PT Zhisheng Pacific Trading', 12, 1000],
        ],
    ],

    'ingredient-compositions' => [
        'label'       => 'Komposisi Bahan',
        'model'       => IngredientComposition::class,
        'route_index' => 'master.ingredients.index',
        'unique_by'   => ['parent_id', 'child_id'],
        'columns'     => [
            ['header' => 'parent',     'field' => 'parent',     'rules' => 'required', 'required' => true],
            ['header' => 'child',      'field' => 'child',      'rules' => 'required', 'required' => true],
            ['header' => 'qty_needed', 'field' => 'qty_needed', 'rules' => 'required|numeric|gt:0', 'cast' => 'float', 'required' => true],
        ],
        'relations' => [
            'parent' => ['model' => Ingredient::class, 'match' => 'name', 'target' => 'parent_id', 'nullable' => false, 'label' => 'Bahan induk (parent)'],
            'child'  => ['model' => Ingredient::class, 'match' => 'name', 'target' => 'child_id',  'nullable' => false, 'label' => 'Bahan baku (child)'],
        ],
        'sample_rows' => [
            ['Teh Seduh', 'Bubuk Teh', 5],
        ],
    ],

    // Resep memakai COMMIT khusus (commitRecipes) karena perlu recipe_group_id (UUID)
    // & created_by. Kolom di bawah dipakai untuk template, ekspor, validasi & pratinjau.
    'recipes' => [
        'label'       => 'Resep Menu',
        'model'       => Recipe::class,
        'route_index' => 'master.recipes.index',
        'unique_by'   => ['recipe_group_id', 'ingredient_id'],
        'columns'     => [
            ['header' => 'menu',           'field' => 'menu',           'rules' => 'required', 'required' => true],
            ['header' => 'ingredient',     'field' => 'ingredient',     'rules' => 'required', 'required' => true],
            ['header' => 'qty_usage',      'field' => 'qty_usage',      'rules' => 'required|numeric|gt:0', 'cast' => 'float', 'required' => true],
            ['header' => 'unit',           'field' => 'unit',           'rules' => 'required|string|max:20', 'required' => true, 'lower' => true],
            ['header' => 'effective_from', 'field' => 'effective_from', 'rules' => 'nullable|date'],
        ],
        'relations' => [
            'menu'       => ['model' => Menu::class,       'match' => 'name', 'target' => 'menu_id',       'nullable' => false, 'label' => 'Menu'],
            'ingredient' => ['model' => Ingredient::class, 'match' => 'name', 'target' => 'ingredient_id', 'nullable' => false, 'label' => 'Bahan'],
        ],
        'sample_rows' => [
            ['Es Teh Manis', 'Gula Pasir', 15, 'gram', ''],
        ],
    ],

];
