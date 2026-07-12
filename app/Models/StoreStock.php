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
     * Model standar reorder point:
     *   Reorder Point (ROP) = lead_time + safety_stock  → titik pesan
     *
     * Logic:
     *   - critical : DOS < ROP                       → sudah di titik pesan, order SEKARANG
     *   - warning  : DOS < ROP + siklus order        → ancang-ancang 1 siklus sebelum
     *   - ok       : DOS >= ROP + siklus order       → aman
     *   - no_par   : lead_time belum diset
     *   - no_data  : DOS tidak bisa dihitung (tidak ada data pemakaian)
     *
     * @param  float|null $dos
     * @param  int|null   $leadTimeDays    Waktu tunggu kiriman (hari)
     * @param  int|null   $safetyStockDays Cadangan di atas lead time (hari)
     * @param  int|null   $orderCycleDays  Siklus order (hari), untuk zona warning
     * @return string
     */
    public function dosStatus(?float $dos, ?int $leadTimeDays = null, ?int $safetyStockDays = 0, ?int $orderCycleDays = null): string
    {
        if ($dos === null)           return 'no_data';
        if ($leadTimeDays === null)  return 'no_par';

        $safety = max(0, (int) $safetyStockDays);
        $rop    = $leadTimeDays + $safety;                        // titik pesan (reorder point)
        $warnAt = $rop + ($orderCycleDays ? (int) $orderCycleDays : 0); // ancang-ancang 1 siklus

        if ($dos < $rop)    return 'critical';
        if ($dos < $warnAt) return 'warning';
        return 'ok';
    }
}
