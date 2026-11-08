<?php

namespace Tests\Feature\AksesPerToko;

use App\Models\Ingredient;
use App\Models\ProductionLog;

class ProduksiTest extends AksesPerTokoTestCase
{
    private function produksi(int $tokoId): ProductionLog
    {
        $setengahJadi = Ingredient::forceCreate([
            'name' => 'Sirup Gula ' . $tokoId, 'category' => 'sirup', 'type' => 'semi_finished', 'unit_base' => 'ml',
        ]);
        return ProductionLog::forceCreate([
            'store_id' => $tokoId, 'semi_finished_id' => $setengahJadi->id, 'qty_produced' => 1000,
            'production_date' => '2026-11-02', 'created_by' => $this->superAdmin->id,
        ]);
    }

    /** @return array<int, array{0:string, 1:string}> */
    private function semuaAksi(ProductionLog $p): array
    {
        return [
            ['GET',    route('production.logs.show', $p)],
            ['GET',    route('production.logs.edit', $p)],
            ['PUT',    route('production.logs.update', $p)],
            ['DELETE', route('production.logs.destroy', $p)],
        ];
    }

    public function test_admin_area_ditolak_di_produksi_toko_lain(): void
    {
        $milikB = $this->produksi($this->tokoB->id);
        $this->pastikanSemuaDitolak($this->adminA, $this->semuaAksi($milikB));
        $this->assertNotNull(ProductionLog::find($milikB->id));
    }

    // Catatan: hapus/ubah log produksi menghitung ulang FIFO, yang memakai GET_LOCK
    // (khusus MySQL/MariaDB) — tidak bisa dijalankan di SQLite test. Lolosnya cek toko
    // dibuktikan lewat halaman detail & edit, yang memakai cek yang sama.
    public function test_admin_area_diterima_di_produksi_tokonya(): void
    {
        $milikA = $this->produksi($this->tokoA->id);
        $this->actingAs($this->adminA)->get(route('production.logs.show', $milikA))->assertOk();
        $this->actingAs($this->adminA)->get(route('production.logs.edit', $milikA))->assertOk();
    }

    public function test_supervisor_tidak_bisa_mengubah_produksi_tokonya(): void
    {
        $milikA = $this->produksi($this->tokoA->id);
        $this->actingAs($this->supervisorA)->get(route('production.logs.show', $milikA))->assertOk();

        $this->pastikanSupervisorTidakBisaMengubah([
            ['PUT',    route('production.logs.update', $milikA)],
            ['DELETE', route('production.logs.destroy', $milikA)],
        ]);
        $this->assertNotNull(ProductionLog::find($milikA->id));
    }

    public function test_super_admin_bebas_semua_toko(): void
    {
        $milikB = $this->produksi($this->tokoB->id);
        $this->actingAs($this->superAdmin)->get(route('production.logs.show', $milikB))->assertOk();
        $this->actingAs($this->superAdmin)->get(route('production.logs.edit', $milikB))->assertOk();
    }
}
