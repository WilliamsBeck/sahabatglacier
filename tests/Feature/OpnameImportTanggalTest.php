<?php

namespace Tests\Feature;

use App\Http\Controllers\Opname\OpnameController;
use App\Models\Opname;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Kasus live (Nov 2026): tanggal di sel C1 template import diketik ulang di Excel
 * → tersimpan sebagai angka seri / teks "31/10/2026" → upload SO error 500.
 */
class OpnameImportTanggalTest extends TestCase
{
    use RefreshDatabase;

    private function baca($v): ?string
    {
        $m = new \ReflectionMethod(OpnameController::class, 'tanggalDariExcel');
        $m->setAccessible(true);
        return $m->invoke(app(OpnameController::class), $v);
    }

    public function test_berbagai_bentuk_tanggal_excel_terbaca(): void
    {
        $this->assertSame('2026-10-31', $this->baca('2026-10-31'));          // teks asli template
        $this->assertSame('2026-10-31', $this->baca(46326));                 // angka seri Excel
        $this->assertSame('2026-10-31', $this->baca(46326.0));
        $this->assertSame('2026-10-31', $this->baca('31/10/2026'));          // hari/bulan/tahun
        $this->assertSame('2026-10-31', $this->baca('31-10-2026'));
        $this->assertSame('2026-10-31', $this->baca(new \DateTime('2026-10-31')));
    }

    public function test_tanggal_tidak_masuk_akal_ditolak(): void
    {
        foreach ([null, '', 'abc', '31/13/2026', '2026-02-30', 5, '46326x'] as $v) {
            $this->assertNull($this->baca($v), var_export($v, true) . ' harus ditolak');
        }
    }

    public function test_upload_dengan_tanggal_rusak_memberi_pesan_bukan_error_500(): void
    {
        $toko  = Store::forceCreate(['store_code' => '1', 'name' => 'Toko A', 'area' => 'Area 1']);
        $admin = User::factory()->superAdmin()->create();

        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet();
        $ws->setCellValue('A1', 'METADATA');
        $ws->setCellValue('B1', $toko->id);
        $ws->setCellValue('C1', '31 Okt 2026');
        $ws->setCellValue('D1', 'end_month');
        $path = tempnam(sys_get_temp_dir(), 'so') . '.xlsx';
        (new Xlsx($ss))->save($path);

        $this->actingAs($admin)
            ->post(route('opname.opnames.import'), ['file' => new UploadedFile($path, 'so.xlsx', null, null, true)])
            ->assertSessionHasErrors(['file' => 'Tanggal opname di sel C1 tidak terbaca. Isi dengan format 2026-10-31 (tahun-bulan-tanggal) atau 31/10/2026.']);

        $this->assertSame(0, Opname::count());
        @unlink($path);
    }
}
