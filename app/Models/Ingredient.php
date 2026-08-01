<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    protected $fillable = ['name', 'type', 'category', 'unit_base', 'is_active', 'ideal_follows_actual'];

    const CATEGORIES = ['bubuk', 'teh', 'sirup', 'selai', 'solid', 'kemasan'];
    const CATEGORY_ORDER = ['solid', 'bubuk', 'teh', 'sirup', 'selai', 'kemasan'];

    protected $casts = ['is_active' => 'boolean', 'ideal_follows_actual' => 'boolean'];

    public function packagings()
    {
        return $this->hasMany(IngredientPackaging::class);
    }

    /**
     * Urutkan: kategori (sort_order dari ingredient_categories) → urutan input (id).
     * Bukan abjad. Dipakai untuk semua dropdown/list bahan baku.
     */
    public function scopeOrderedByCategory($query)
    {
        return $query
            ->leftJoin('ingredient_categories as ic', 'ingredients.category', '=', 'ic.name')
            ->orderByRaw('ic.sort_order IS NULL')
            ->orderBy('ic.sort_order')
            ->orderBy('ingredients.id')
            ->select('ingredients.*');
    }

    // Bahan pembentuk (parent = semi_finished, child = raw)
    public function compositions()
    {
        return $this->hasMany(IngredientComposition::class, 'parent_id');
    }

    public function compositionParents()
    {
        return $this->hasMany(IngredientComposition::class, 'child_id');
    }

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    public function isRaw(): bool
    {
        return $this->type === 'raw';
    }

    public function isSemiFinished(): bool
    {
        return $this->type === 'semi_finished';
    }

    public function getTypeLabelAttribute(): string
    {
        return $this->type === 'raw' ? 'Bahan Baku' : 'Setengah Jadi';
    }
}
