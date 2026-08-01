<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    protected $fillable = ['name', 'category', 'category_id', 'is_active', 'count_in_total'];

    protected $casts = ['is_active' => 'boolean', 'count_in_total' => 'boolean'];

    public function menuCategory()
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    /**
     * Urutkan: kategori menu (sort_order) → urutan input (id). Bukan abjad.
     */
    public function scopeOrderedByCategory($query)
    {
        return $query
            ->leftJoin('menu_categories as mc', 'menus.category_id', '=', 'mc.id')
            ->orderByRaw('mc.sort_order IS NULL')
            ->orderBy('mc.sort_order')
            ->orderBy('menus.id')
            ->select('menus.*');
    }

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    public function sales()
    {
        return $this->hasMany(MonthlySale::class);
    }

    // Ambil resep aktif per tanggal tertentu
    public function activeRecipes(string $date)
    {
        return $this->recipes()
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('ingredient_id')
            ->map(fn($group) => $group->first());
    }
}
