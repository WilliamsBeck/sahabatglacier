<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreStock extends Model
{
    protected $table = 'store_stocks';
    public $timestamps = false;
    protected $fillable = ['store_id', 'ingredient_id', 'stock_balance', 'min_stock_base', 'par_level_days', 'updated_at'];

    public function store()      { return $this->belongsTo(Store::class); }
    public function ingredient() { return $this->belongsTo(Ingredient::class); }

    /** Legacy: manual threshold dalam base unit */
    public function isLowStock(): bool
    {
        return $this->min_stock_base !== null && $this->stock_balance < $this->min_stock_base;
    }

    /**
     * Status DOS berdasarkan lead time (reorder point) toko.
     *
     * Logic:
     *   - critical : DOS < lead_time                   → harus order SEKARANG
     *   - warning  : DOS < lead_time + 15% order_cycle → segera order dalam waktu dekat
     *   - ok       : DOS >= lead_time + 15% order_cycle → aman
     *   - no_par   : lead_time belum diset
     *   - no_data  : DOS tidak bisa dihitung (tidak ada data pemakaian)
     *
     * @param  float|null $dos
     * @param  int|null   $leadTimeDays   Waktu tunggu kiriman (hari)
     * @param  int|null   $orderCycleDays Siklus order (hari), untuk buffer warning
     * @return string
     */
    public function dosStatus(?float $dos, ?int $leadTimeDays = null, ?int $orderCycleDays = null): string
    {
        if ($dos === null)           return 'no_data';
        if ($leadTimeDays === null)  return 'no_par';

        // Warning buffer: 15% siklus order (atau 3 hari jika siklus belum diset)
        $buffer  = $orderCycleDays ? (int)ceil($orderCycleDays * 0.15) : 3;
        $warnAt  = $leadTimeDays + $buffer;

        if ($dos < $leadTimeDays)  return 'critical';
        if ($dos < $warnAt)        return 'warning';
        return 'ok';
    }
}
