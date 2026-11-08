<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PBI-032 — Master User:
 *  1. jenis akun sendiri tidak bisa diubah lewat form edit;
 *  2. penambahan & pencabutan toko per user tercatat di Log Aktivitas;
 *  3. kolom username tampil di tabel user.
 */
class MasterUserTest extends TestCase
{
    use RefreshDatabase;

    private Store $tokoA;
    private Store $tokoB;
    private User $superAdmin;
    private User $manager;
    private User $adminArea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokoA = Store::forceCreate(['store_code' => '1', 'name' => 'Toko Alfa', 'area' => 'Area 1']);
        $this->tokoB = Store::forceCreate(['store_code' => '2', 'name' => 'Toko Beta', 'area' => 'Area 2']);

        $this->superAdmin = User::factory()->superAdmin()->create(['username' => 'bos_besar']);
        $this->manager    = User::factory()->manager()->create();
        $this->manager->stores()->attach([$this->tokoA->id, $this->tokoB->id], ['assigned_at' => now()]);
        $this->adminArea  = User::factory()->adminArea()->create(['username' => 'admin_alfa', 'name' => 'Admin Alfa']);
        $this->adminArea->stores()->attach($this->tokoA->id, ['assigned_at' => now()]);
    }

    private function dataUbah(User $u, array $ubah = []): array
    {
        return array_merge(['name' => $u->name, 'email' => $u->email, 'role' => $u->role], $ubah);
    }

    /** Log akses toko terbaru untuk user $target. */
    private function logToko(User $target): ?AuditLog
    {
        return AuditLog::where('model', 'User')->where('model_id', $target->id)
            ->where('description', 'like', 'Akses toko%')->latest('id')->first();
    }

    // ── 1. Jenis akun sendiri ──────────────────────────────────────────────

    public function test_super_admin_tidak_bisa_menurunkan_jenis_akunnya_sendiri(): void
    {
        $this->actingAs($this->superAdmin)
            ->from(route('master.users.edit', $this->superAdmin))
            ->put(route('master.users.update', $this->superAdmin), $this->dataUbah($this->superAdmin, ['role' => 'manager']))
            ->assertRedirect(route('master.users.edit', $this->superAdmin))
            ->assertSessionHasErrors(['role' => 'Anda tidak bisa mengubah jenis akun Anda sendiri. Minta Super Admin lain untuk mengubahnya bila memang perlu.']);

        $this->assertSame('super_admin', $this->superAdmin->fresh()->role);
    }

    public function test_data_lain_milik_sendiri_tetap_bisa_diubah(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('master.users.update', $this->superAdmin), $this->dataUbah($this->superAdmin, ['name' => 'Nama Baru']))
            ->assertRedirect(route('master.users.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Nama Baru', $this->superAdmin->fresh()->name);
        $this->assertSame('super_admin', $this->superAdmin->fresh()->role);
    }

    public function test_jenis_akun_user_lain_tetap_bisa_diubah(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('master.users.update', $this->adminArea), $this->dataUbah($this->adminArea, ['role' => 'supervisor']))
            ->assertSessionHasNoErrors();

        $this->assertSame('supervisor', $this->adminArea->fresh()->role);
    }

    public function test_form_edit_akun_sendiri_mengunci_pilihan_jenis_akun(): void
    {
        $this->actingAs($this->superAdmin)->get(route('master.users.edit', $this->superAdmin))
            ->assertOk()
            ->assertSee('Jenis akun Anda sendiri tidak bisa diubah di sini.');

        $this->actingAs($this->superAdmin)->get(route('master.users.edit', $this->adminArea))
            ->assertOk()
            ->assertDontSee('Jenis akun Anda sendiri tidak bisa diubah di sini.');
    }

    // ── 2. Log Aktivitas akses toko ────────────────────────────────────────

    public function test_penambahan_toko_tercatat_di_log_aktivitas(): void
    {
        $this->actingAs($this->manager)
            ->post(route('master.users.assign-store', $this->adminArea), ['store_ids' => [$this->tokoB->id]])
            ->assertSessionHasNoErrors();

        $log = $this->logToko($this->adminArea);
        $this->assertNotNull($log, 'penambahan toko harus tercatat');
        $this->assertSame($this->manager->id, $log->user_id, 'pelaku tercatat');
        $this->assertSame('updated', $log->action);
        $this->assertSame('Akses toko ditambahkan untuk Admin Alfa (admin_alfa): Toko Beta', $log->description);
        $this->assertSame(['akses_toko' => 'Toko Alfa'], $log->old_values);
        $this->assertSame(['akses_toko' => 'Toko Alfa, Toko Beta'], $log->new_values);
    }

    public function test_pencabutan_toko_tercatat_di_log_aktivitas(): void
    {
        $this->adminArea->stores()->attach($this->tokoB->id, ['assigned_at' => now()]);

        $this->actingAs($this->superAdmin)
            ->delete(route('master.users.revoke-store', [$this->adminArea, $this->tokoB]))
            ->assertSessionHas('success');

        $log = $this->logToko($this->adminArea);
        $this->assertNotNull($log, 'pencabutan toko harus tercatat');
        $this->assertSame($this->superAdmin->id, $log->user_id);
        $this->assertSame('Akses toko dicabut dari Admin Alfa (admin_alfa): Toko Beta', $log->description);
        $this->assertSame(['akses_toko' => 'Toko Alfa, Toko Beta'], $log->old_values);
        $this->assertSame(['akses_toko' => 'Toko Alfa'], $log->new_values);
    }

    public function test_toko_saat_membuat_user_juga_tercatat(): void
    {
        $this->actingAs($this->superAdmin)->post(route('master.users.store'), [
            'name' => 'Supervisor Baru', 'username' => 'spv_baru', 'email' => 'spv@contoh.id',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
            'role' => 'supervisor', 'store_ids' => [$this->tokoA->id, $this->tokoB->id],
        ])->assertSessionHasNoErrors();

        $baru = User::where('username', 'spv_baru')->firstOrFail();
        $log  = $this->logToko($baru);
        $this->assertNotNull($log);
        $this->assertSame('Akses toko ditambahkan untuk Supervisor Baru (spv_baru): Toko Alfa, Toko Beta', $log->description);
        $this->assertSame(['akses_toko' => '(tidak ada)'], $log->old_values);
    }

    public function test_tidak_ada_log_bila_tidak_ada_yang_berubah(): void
    {
        // Toko yang sudah dipegang di-assign ulang → tidak ada perubahan, tidak ada log
        $this->actingAs($this->superAdmin)
            ->post(route('master.users.assign-store', $this->adminArea), ['store_ids' => [$this->tokoA->id]]);

        // Pencabutan toko terakhir oleh manager ditolak → tidak ada log
        $this->actingAs($this->manager)
            ->delete(route('master.users.revoke-store', [$this->adminArea, $this->tokoA]))
            ->assertSessionHas('error');

        $this->assertNull($this->logToko($this->adminArea));
        $this->assertTrue($this->adminArea->stores()->where('stores.id', $this->tokoA->id)->exists());
    }

    public function test_log_akses_toko_tampil_di_halaman_log_aktivitas(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('master.users.assign-store', $this->adminArea), ['store_ids' => [$this->tokoB->id]]);

        $this->actingAs($this->superAdmin)->get(route('audit.index'))
            ->assertOk()
            ->assertSee('Akses toko ditambahkan untuk Admin Alfa (admin_alfa): Toko Beta');
    }

    // ── 3. Kolom username ──────────────────────────────────────────────────

    public function test_kolom_username_tampil_di_tabel_user(): void
    {
        $this->actingAs($this->superAdmin)->get(route('master.users.index'))
            ->assertOk()
            ->assertSee('<th>Username</th>', false)
            ->assertSee('admin_alfa')
            ->assertSee('bos_besar');
    }
}
