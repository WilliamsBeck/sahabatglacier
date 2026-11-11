<?php

namespace Tests\Feature;

use App\Http\Controllers\Opname\OpnameController;
use App\Models\DailyConfirmation;
use App\Models\DailyUsage;
use App\Models\Ingredient;
use App\Models\Opname;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kasus live (Nov 2026): SO akhir Oktober diinput bertanggal 30 Okt lalu di-approve,
 * pencatatan harian sampai 31 Okt dikonfirmasi. Akibatnya saling kunci:
 *  - batal approve ditolak: "ada pencatatan harian terkonfirmasi setelah 30 Okt";
 *  - batal konfirmasi 31 Okt ditolak: "periode sudah ditutup opname" (akhir periode = 31 Okt).
 * Sebabnya dua aturan memakai patokan berbeda (tanggal input vs akhir periode).
 */
class OpnameSalingKunciTest extends TestCase
{
    use RefreshDatabase;

    private Store $toko;
    private User $admin;
    private Opname $so;
    private Ingredient $bahan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->toko  = Store::forceCreate(['store_code' => '1', 'name' => 'Toko A', 'area' => 'Area 1']);
        $this->admin = User::factory()->superAdmin()->create();
        $this->bahan = Ingredient::forceCreate(['name' => 'Gula', 'category' => 'bubuk', 'type' => 'raw', 'unit_base' => 'gram']);

        // SO akhir Oktober, tapi tanggal inputnya keliru 30 Okt
        $this->so = Opname::forceCreate([
            'store_id' => $this->toko->id, 'opname_date' => '2026-10-30', 'period_month' => 10, 'period_year' => 2026,
            'period_type' => 'end_month', 'status' => 'approved', 'performed_by' => $this->admin->id,
        ]);
    }

    private function pemakaianTerkonfirmasi(string $tgl): void
    {
        DailyUsage::forceCreate(['store_id' => $this->toko->id, 'ingredient_id' => $this->bahan->id,
            'usage_date' => $tgl, 'qty_pack' => 1, 'created_by' => $this->admin->id]);
        DailyConfirmation::forceCreate(['store_id' => $this->toko->id, 'confirmation_date' => $tgl,
            'confirmed_by' => $this->admin->id]);
    }

    private function alasanTidakBisaDibatalkan(): ?string
    {
        $m = new \ReflectionMethod(OpnameController::class, 'transactionsAfterReason');
        $m->setAccessible(true);
        return $m->invoke(app(OpnameController::class), $this->so->fresh());
    }

    public function test_pencatatan_di_dalam_periode_tidak_menghalangi_batal_approve(): void
    {
        $this->pemakaianTerkonfirmasi('2026-10-31');

        // Tgl 31 memang terkunci selama SO approved (masuk periode Oktober)...
        $this->assertTrue(Opname::isDateLocked($this->toko->id, '2026-10-31'));
        // ...maka TIDAK boleh sekaligus menghalangi batal approve — dulu saling kunci.
        $this->assertNull($this->alasanTidakBisaDibatalkan());
    }

    public function test_pencatatan_sesudah_periode_tetap_menghalangi_batal_approve(): void
    {
        $this->pemakaianTerkonfirmasi('2026-11-01');

        $this->assertSame(
            'sudah ada pencatatan harian terkonfirmasi setelah tanggal 31 Oktober 2026.',
            $this->alasanTidakBisaDibatalkan()
        );
    }

    public function test_kunci_periode_dan_batal_approve_memakai_akhir_periode_yang_sama(): void
    {
        $this->assertSame('2026-10-31', $this->so->akhirPeriode()->toDateString());
        $this->assertSame('2026-10-31', Opname::lockDateFor($this->toko->id));

        $tengah = Opname::forceCreate([
            'store_id' => $this->toko->id, 'opname_date' => '2026-11-14', 'period_month' => 11, 'period_year' => 2026,
            'period_type' => 'mid_month', 'status' => 'approved', 'performed_by' => $this->admin->id,
        ]);
        $this->assertSame('2026-11-15', $tengah->akhirPeriode()->toDateString());
        $this->assertSame('2026-11-15', Opname::lockDateFor($this->toko->id));
    }
}
