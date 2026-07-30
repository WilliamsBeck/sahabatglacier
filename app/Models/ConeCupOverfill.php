<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConeCupOverfill extends Model
{
    protected $fillable = [
        'store_id', 'ingredient_id', 'month', 'year', 'qty', 'rusak_override', 'note', 'created_by',
    ];

    protected $casts = [
        'qty'            => 'float',
        'rusak_override' => 'float',   // null = ikut angka dari catatan waste
        'month'          => 'int',
        'year'           => 'int',
    ];

    public function store()      { return $this->belongsTo(Store::class); }
    public function ingredient() { return $this->belongsTo(Ingredient::class); }
}
