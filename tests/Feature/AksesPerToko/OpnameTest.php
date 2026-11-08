<?php

namespace Tests\Feature\AksesPerToko;

use App\Models\Opname;
use App\Models\OpnameItem;

class OpnameTest extends AksesPerTokoTestCase
{
    private function opname(int $tokoId): Opname
    {
        return Opname::forceCreate([
            'store_id' => $tokoId, 'opname_date' => '2026-10-31', 'period_month' => 10, 'period_year' => 2026,
            'period_type' => 'end_month', 'status' => 'draft', 'performed_by' => $this->superAdmin->id,
        ]);
    }

    private function item(Opname $o): OpnameItem
    {
        return OpnameItem::forceCreate([
            'opname_id' => $o->id, 'ingredient_id' => $this->bahan->id,
            'system_qty' => 0, 'physical_qty' => 0, 'variance' => 0,
        ]);
    }

    /** @return array<int, array{0:string, 1:string}> */
    private function semuaAksi(Opname $o, OpnameItem $item): array
    {
        return [
            ['GET',    route('opname.opnames.show', $o)],
            ['GET',    route('opname.opnames.edit', $o)],
            ['GET',    route('opname.opnames.export', $o)],
            ['PUT',    route('opname.opnames.update', $o)],
            ['DELETE', route('opname.opnames.destroy', $o)],
            ['POST',   route('opname.opnames.approve', $o)],
            ['POST',   route('opname.opnames.unapprove', $o)],
            ['POST',   route('opname.opnames.koreksi-harga', $o)],
            ['POST',   route('opname.opnames.recalculate', $o)],
            ['POST',   route('opname.opnames.add-batch', $o)],
            ['DELETE', route('opname.opnames.items.destroy', [$o, $item])],
        ];
    }

    public function test_admin_area_ditolak_di_opname_toko_lain(): void
    {
        $milikB = $this->opname($this->tokoB->id);
        $this->pastikanSemuaDitolak($this->adminA, $this->semuaAksi($milikB, $this->item($milikB)));
        $this->assertSame('draft', $milikB->fresh()->status);
        $this->assertSame(1, $milikB->items()->count());
    }

    public function test_admin_area_diterima_di_opname_tokonya(): void
    {
        $milikA = $this->opname($this->tokoA->id);
        $this->actingAs($this->adminA)->get(route('opname.opnames.show', $milikA))->assertOk();

        $this->actingAs($this->adminA)->delete(route('opname.opnames.destroy', $milikA))->assertRedirect();
        $this->assertNull(Opname::find($milikA->id), 'opname draft toko sendiri bisa dihapus');
    }

    public function test_supervisor_tidak_bisa_mengubah_opname_tokonya(): void
    {
        $milikA = $this->opname($this->tokoA->id);
        $this->actingAs($this->supervisorA)->get(route('opname.opnames.show', $milikA))->assertOk();

        $this->pastikanSupervisorTidakBisaMengubah([
            ['PUT',    route('opname.opnames.update', $milikA)],
            ['POST',   route('opname.opnames.approve', $milikA)],
            ['DELETE', route('opname.opnames.destroy', $milikA)],
        ]);
        $this->assertSame('draft', $milikA->fresh()->status);
    }

    public function test_super_admin_bebas_semua_toko(): void
    {
        $milikB = $this->opname($this->tokoB->id);
        $this->actingAs($this->superAdmin)->get(route('opname.opnames.show', $milikB))->assertOk();
        $this->actingAs($this->superAdmin)->delete(route('opname.opnames.destroy', $milikB))->assertRedirect();
        $this->assertNull(Opname::find($milikB->id));
    }
}
