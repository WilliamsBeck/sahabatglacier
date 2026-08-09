<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MutationItem extends Model
{
    protected $fillable = [
        'mutation_id', 'ingredient_id', 'packaging_id',
        'qty_crate', 'qty_pack', 'qty_base', 'total_in_base',
        'price_per_base', 'price_per_crate', 'gross_price_per_base', 'selling_price_per_base', 'cost_subtotal', 'remaining_qty',
    ];

    public function mutation()  { return $this->belongsTo(Mutation::class); }
    public function ingredient(){ return $this->belongsTo(Ingredient::class); }
    public function packaging() { return $this->belongsTo(IngredientPackaging::class); }

    /**
     * Harga per dus untuk DITAMPILKAN.
     * Pakai angka yang diketik user apa adanya bila ada; kalau belum (data lama),
     * baru dihitung dari harga per satuan dasar seperti cara lama.
     */
    public function getHargaPerDusAttribute(): ?int
    {
        if ($this->price_per_crate !== null) return (int) round($this->price_per_crate);

        $pkg = $this->packaging;
        $ctb = $pkg ? (float) $pkg->crate_to_pack * (float) $pkg->pack_to_base : 0;
        if ($ctb <= 0) return null;

        return (int) round((float) ($this->gross_price_per_base ?? $this->price_per_base) * $ctb);
    }
}
