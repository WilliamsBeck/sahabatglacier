<?php

namespace Tests\Feature\AksesPerToko;

use App\Models\Ingredient;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PBI-028 — akses per toko di semua modul.
 *
 * Skenario bersama: dua toko (A & B). Admin area A, admin area B, dan supervisor A
 * hanya memegang tokonya masing-masing; super admin bebas semua toko.
 */
abstract class AksesPerTokoTestCase extends TestCase
{
    use RefreshDatabase;

    protected const PESAN_TOLAK = 'Anda tidak memiliki akses ke toko ini.';

    protected Store $tokoA;
    protected Store $tokoB;
    protected User $superAdmin;
    protected User $adminA;
    protected User $adminB;
    protected User $supervisorA;
    protected Ingredient $bahan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokoA = Store::forceCreate(['store_code' => '1', 'name' => 'Toko A', 'area' => 'Area 1']);
        $this->tokoB = Store::forceCreate(['store_code' => '2', 'name' => 'Toko B', 'area' => 'Area 2']);

        $this->superAdmin  = User::factory()->superAdmin()->create();
        $this->adminA      = $this->denganToko(User::factory()->adminArea()->create(), $this->tokoA);
        $this->adminB      = $this->denganToko(User::factory()->adminArea()->create(), $this->tokoB);
        $this->supervisorA = $this->denganToko(User::factory()->supervisor()->create(), $this->tokoA);

        $this->bahan = Ingredient::forceCreate([
            'name' => 'Gula', 'category' => 'bubuk', 'type' => 'raw', 'unit_base' => 'gram',
        ]);
    }

    protected function denganToko(User $user, Store $toko): User
    {
        $user->stores()->attach($toko->id, ['assigned_at' => now()]);
        return $user;
    }

    /** Request JSON supaya penolakan 403 bisa dicek pesannya. */
    protected function kirimJson(User $user, string $method, string $url): TestResponse
    {
        return $this->actingAs($user)->json($method, $url);
    }

    /**
     * Semua aksi ditolak 403 dengan pesan "Anda tidak memiliki akses ke toko ini."
     *
     * @param array<int, array{0:string, 1:string}> $aksi [method, url]
     */
    protected function pastikanSemuaDitolak(User $user, array $aksi): void
    {
        foreach ($aksi as [$method, $url]) {
            $this->kirimJson($user, $method, $url)
                ->assertForbidden()
                ->assertJson(['message' => self::PESAN_TOLAK], false);
        }
    }

    /**
     * Supervisor: setiap aksi UBAH ditolak (RestrictViewer) — dikembalikan dengan pesan
     * error, bukan dijalankan.
     *
     * @param array<int, array{0:string, 1:string}> $aksi [method, url]
     */
    protected function pastikanSupervisorTidakBisaMengubah(array $aksi): void
    {
        foreach ($aksi as [$method, $url]) {
            $this->actingAs($this->supervisorA)->from('/')->call($method, $url)
                ->assertRedirect('/')
                ->assertSessionHas('error');
        }
    }
}
