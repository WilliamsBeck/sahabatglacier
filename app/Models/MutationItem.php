<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MutationItem extends Model
{
    protected $fillable = [
        'mutation_id', 'ingredient_id', 'packaging_id',
        'qty_crate', 'qty_pack', 'qty_base', 'total_in_base',
        'price_per_base', 'gross_price_per_base', 'selling_price_per_base', 'cost_subtotal', 'remaining_qty',
    ];

    public function mutation()  { return $this->belongsTo(Mutation::class); }
    public function ingredient(){ return $this->belongsTo(Ingredient::class); }
    public function packaging() { return $this->belongsTo(IngredientPackaging::class); }
}
