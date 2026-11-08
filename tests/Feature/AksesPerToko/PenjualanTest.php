<?php

namespace Tests\Feature\AksesPerToko;

use App\Models\MonthlyRevenue;

/**
 * Penjualan & HPP tidak memakai nomor ID — tokonya dibawa lewat store_id di URL/form,
 * dan dicek middleware store.access (aturan yang sama: User::bisaAksesToko).
 */
class PenjualanTest extends AksesPerTokoTestCase
{
    private function omset(int $tokoId): MonthlyRevenue
    {
        return MonthlyRevenue::forceCreate([
            'store_id' => $tokoId, 'month' => 10, 'year' => 2026, 'period_type' => 'end_month',
            'total_revenue' => 1000000, 'recorded_by' => $this->superAdmin->id,
        ]);
    }

    private function periode(int $tokoId): array
    {
        return ['store_id' => $tokoId, 'month' => 10, 'year' => 2026, 'period_type' => 'end_month'];
    }

    /** @return array<int, array{0:string, 1:string}> */
    private function semuaAksi(int $tokoId): array
    {
        $p = $this->periode($tokoId);
        return [
            ['GET',    route('sales.period.show', $p)],
            ['GET',    route('sales.period.edit', $p)],
            ['PUT',    route('sales.period.update', $p)],
            ['DELETE', route('sales.period.destroy', $p)],
            ['GET',    route('sales.hpp.index', $p)],
            ['POST',   route('sales.hpp.lock', $p)],
            ['POST',   route('sales.hpp.unlock', $p)],
        ];
    }

    public function test_admin_area_ditolak_di_penjualan_toko_lain(): void
    {
        $milikB = $this->omset($this->tokoB->id);
        $this->pastikanSemuaDitolak($this->adminA, $this->semuaAksi($this->tokoB->id));
        $this->assertNotNull(MonthlyRevenue::find($milikB->id));
    }

    public function test_admin_area_diterima_di_penjualan_tokonya(): void
    {
        $milikA = $this->omset($this->tokoA->id);
        $this->actingAs($this->adminA)->get(route('sales.period.show', $this->periode($this->tokoA->id)))->assertOk();

        $this->actingAs($this->adminA)->delete(route('sales.period.destroy', $this->periode($this->tokoA->id)))->assertRedirect();
        $this->assertNull(MonthlyRevenue::find($milikA->id));
    }

    public function test_supervisor_tidak_bisa_mengubah_penjualan_tokonya(): void
    {
        $milikA = $this->omset($this->tokoA->id);
        $this->actingAs($this->supervisorA)->get(route('sales.period.show', $this->periode($this->tokoA->id)))->assertOk();

        $this->pastikanSupervisorTidakBisaMengubah([
            ['PUT',    route('sales.period.update', $this->periode($this->tokoA->id))],
            ['DELETE', route('sales.period.destroy', $this->periode($this->tokoA->id))],
            ['POST',   route('sales.hpp.lock', $this->periode($this->tokoA->id))],
        ]);
        $this->assertNotNull(MonthlyRevenue::find($milikA->id));
    }

    public function test_super_admin_bebas_semua_toko(): void
    {
        $milikB = $this->omset($this->tokoB->id);
        $this->actingAs($this->superAdmin)->get(route('sales.period.show', $this->periode($this->tokoB->id)))->assertOk();
        $this->actingAs($this->superAdmin)->delete(route('sales.period.destroy', $this->periode($this->tokoB->id)))->assertRedirect();
        $this->assertNull(MonthlyRevenue::find($milikB->id));
    }
}
