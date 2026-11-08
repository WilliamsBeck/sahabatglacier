<?php

namespace Tests\Feature\AksesPerToko;

use App\Models\WasteLog;

class WasteTest extends AksesPerTokoTestCase
{
    private function waste(int $tokoId): WasteLog
    {
        return WasteLog::forceCreate([
            'store_id' => $tokoId, 'waste_date' => '2026-11-02', 'recorded_by' => $this->superAdmin->id,
        ]);
    }

    /** @return array<int, array{0:string, 1:string}> */
    private function semuaAksi(WasteLog $w): array
    {
        return [
            ['GET',    route('waste.logs.show', $w)],
            ['GET',    route('waste.logs.edit', $w)],
            ['PUT',    route('waste.logs.update', $w)],
            ['DELETE', route('waste.logs.destroy', $w)],
        ];
    }

    public function test_admin_area_ditolak_di_waste_toko_lain(): void
    {
        $milikB = $this->waste($this->tokoB->id);
        $this->pastikanSemuaDitolak($this->adminA, $this->semuaAksi($milikB));
        $this->assertNotNull(WasteLog::find($milikB->id));
    }

    public function test_admin_area_diterima_di_waste_tokonya(): void
    {
        $milikA = $this->waste($this->tokoA->id);
        $this->actingAs($this->adminA)->get(route('waste.logs.show', $milikA))->assertOk();

        $this->actingAs($this->adminA)->delete(route('waste.logs.destroy', $milikA))->assertRedirect();
        $this->assertNull(WasteLog::find($milikA->id));
    }

    public function test_supervisor_tidak_bisa_mengubah_waste_tokonya(): void
    {
        $milikA = $this->waste($this->tokoA->id);
        $this->actingAs($this->supervisorA)->get(route('waste.logs.show', $milikA))->assertOk();

        $this->pastikanSupervisorTidakBisaMengubah([
            ['PUT',    route('waste.logs.update', $milikA)],
            ['DELETE', route('waste.logs.destroy', $milikA)],
        ]);
        $this->assertNotNull(WasteLog::find($milikA->id));
    }

    public function test_super_admin_bebas_semua_toko(): void
    {
        $milikB = $this->waste($this->tokoB->id);
        $this->actingAs($this->superAdmin)->get(route('waste.logs.show', $milikB))->assertOk();
        $this->actingAs($this->superAdmin)->delete(route('waste.logs.destroy', $milikB))->assertRedirect();
        $this->assertNull(WasteLog::find($milikB->id));
    }
}
