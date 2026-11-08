<?php

namespace Tests\Feature\AksesPerToko;

use App\Models\Mutation;

class MutasiTest extends AksesPerTokoTestCase
{
    private function pembelian(int $tokoId, string $status = 'draft'): Mutation
    {
        return Mutation::forceCreate([
            'type' => 'purchase_supplier', 'destination_store_id' => $tokoId,
            'transaction_date' => '2026-11-02', 'status' => $status, 'created_by' => $this->superAdmin->id,
        ]);
    }

    /** Transfer A → B yang sudah dikirim tapi belum tiba (masih di perjalanan). */
    private function transferAkeB(): Mutation
    {
        return Mutation::forceCreate([
            'type' => 'sale_internal', 'source_store_id' => $this->tokoA->id, 'destination_store_id' => $this->tokoB->id,
            'transaction_date' => '2026-11-02', 'delivery_date' => null,
            'status' => 'confirmed', 'created_by' => $this->adminA->id,
        ]);
    }

    /** @return array<int, array{0:string, 1:string}> */
    private function semuaAksi(Mutation $m): array
    {
        return [
            ['GET',    route('inventory.mutations.show', $m)],
            ['GET',    route('inventory.mutations.edit', $m)],
            ['PUT',    route('inventory.mutations.update', $m)],
            ['DELETE', route('inventory.mutations.destroy', $m)],
            ['POST',   route('inventory.mutations.confirm', $m)],
            ['POST',   route('inventory.mutations.unconfirm', $m)],
            ['POST',   route('inventory.mutations.cancel', $m)],
            ['POST',   route('inventory.mutations.terima', $m)],
        ];
    }

    public function test_admin_area_ditolak_di_mutasi_toko_lain(): void
    {
        $milikB = $this->pembelian($this->tokoB->id);
        $this->pastikanSemuaDitolak($this->adminA, $this->semuaAksi($milikB));
        $this->assertSame('draft', $milikB->fresh()->status);
    }

    public function test_admin_area_diterima_di_mutasi_tokonya(): void
    {
        $milikA = $this->pembelian($this->tokoA->id);
        $this->actingAs($this->adminA)->get(route('inventory.mutations.show', $milikA))->assertOk();
        $this->actingAs($this->adminA)->get(route('inventory.mutations.edit', $milikA))->assertOk();

        $this->actingAs($this->adminA)->delete(route('inventory.mutations.destroy', $milikA))->assertRedirect();
        $this->assertNull(Mutation::find($milikA->id), 'draft milik toko sendiri bisa dihapus');
    }

    public function test_transfer_bisa_dibuka_pengirim_maupun_penerima(): void
    {
        $t = $this->transferAkeB();
        $this->actingAs($this->adminA)->get(route('inventory.mutations.show', $t))->assertOk();
        $this->actingAs($this->adminB)->get(route('inventory.mutations.show', $t))->assertOk();
    }

    public function test_aksi_transfer_hanya_oleh_pengirim_dan_terima_hanya_oleh_penerima(): void
    {
        $t = $this->transferAkeB();

        // Penerima (B) tidak boleh mengubah / membatalkan kiriman milik toko A
        $this->pastikanSemuaDitolak($this->adminB, [
            ['GET',    route('inventory.mutations.edit', $t)],
            ['PUT',    route('inventory.mutations.update', $t)],
            ['DELETE', route('inventory.mutations.destroy', $t)],
            ['POST',   route('inventory.mutations.confirm', $t)],
            ['POST',   route('inventory.mutations.unconfirm', $t)],
            ['POST',   route('inventory.mutations.cancel', $t)],
        ]);
        $this->assertSame('confirmed', $t->fresh()->status);

        // Pengirim (A) tidak boleh menekan Terima atas nama toko penerima
        $this->pastikanSemuaDitolak($this->adminA, [['POST', route('inventory.mutations.terima', $t)]]);

        // Penerima (B) boleh Terima — lolos cek toko, lalu berhenti di validasi tanggal
        $this->kirimJson($this->adminB, 'POST', route('inventory.mutations.terima', $t))
            ->assertStatus(422)->assertJsonValidationErrors('delivery_date');
    }

    public function test_supervisor_tidak_bisa_mengubah_mutasi_tokonya(): void
    {
        $milikA = $this->pembelian($this->tokoA->id);
        $this->actingAs($this->supervisorA)->get(route('inventory.mutations.show', $milikA))->assertOk();

        $this->pastikanSupervisorTidakBisaMengubah([
            ['PUT',    route('inventory.mutations.update', $milikA)],
            ['DELETE', route('inventory.mutations.destroy', $milikA)],
            ['POST',   route('inventory.mutations.confirm', $milikA)],
            ['POST',   route('inventory.mutations.cancel', $milikA)],
        ]);
        $this->assertSame('draft', $milikA->fresh()->status);

        // Toko lain tetap tertutup walau hanya melihat
        $this->pastikanSemuaDitolak($this->supervisorA, [
            ['GET', route('inventory.mutations.show', $this->pembelian($this->tokoB->id))],
        ]);
    }

    public function test_super_admin_bebas_semua_toko(): void
    {
        $milikB = $this->pembelian($this->tokoB->id);
        $this->actingAs($this->superAdmin)->get(route('inventory.mutations.show', $milikB))->assertOk();
        $this->actingAs($this->superAdmin)->delete(route('inventory.mutations.destroy', $milikB))->assertRedirect();
        $this->assertNull(Mutation::find($milikB->id));
    }

    public function test_api_stok_toko_lain_ditolak(): void
    {
        $this->pastikanSemuaDitolak($this->adminA, [
            ['GET', url("api-internal/store/{$this->tokoB->id}/stock-summary")],
            ['GET', url("api-internal/ingredient/{$this->bahan->id}/stock-price?store_id={$this->tokoB->id}")],
            ['GET', url("api-internal/ingredient/{$this->bahan->id}/last-price?store_id={$this->tokoB->id}")],
            ['GET', route('api.opname.system-qty', ['store_id' => $this->tokoB->id, 'date' => '2026-11-02'])],
        ]);
        $this->actingAs($this->adminA)->getJson(url("api-internal/store/{$this->tokoA->id}/stock-summary"))->assertOk();
        $this->actingAs($this->superAdmin)->getJson(url("api-internal/store/{$this->tokoB->id}/stock-summary"))->assertOk();
    }
}
