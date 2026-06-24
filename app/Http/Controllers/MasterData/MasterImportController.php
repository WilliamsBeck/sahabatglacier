<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Exports\ArrayExport;
use App\Exports\MultiSheetExport;
use App\Services\MasterImportService;
use App\Services\MasterExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class MasterImportController extends Controller
{
    public function __construct(
        private MasterImportService $service,
        private MasterExportService $export,
    ) {}

    /** Unduh DATA nyata satu entitas (header = template) → siap diimpor ulang. */
    public function exportData(string $entity)
    {
        $cfg = $this->cfgOrFail($entity);
        return Excel::download(new ArrayExport($this->export->rows($cfg)), "{$entity}_data.xlsx");
    }

    /** Unduh DATA bundle multi-sheet (Bahan + Kemasan + Komposisi). */
    public function bundleExportData(string $bundle)
    {
        $cfg = $this->bundleOrFail($bundle);
        return Excel::download(new MultiSheetExport($this->export->bundleRows($cfg)), "{$bundle}_data.xlsx");
    }

    private function cfgOrFail(string $entity): array
    {
        $cfg = $this->service->config($entity);
        abort_if(!$cfg, 404, 'Entitas impor tidak dikenal.');
        return $cfg;
    }

    /** Unduh template Excel (header + baris contoh). */
    public function template(string $entity)
    {
        $cfg = $this->cfgOrFail($entity);
        $data = $this->service->templateData($cfg);
        return Excel::download(new ArrayExport($data), "template_{$entity}.xlsx");
    }

    /** Upload + validasi → simpan sementara → tampilkan pratinjau. */
    public function preview(string $entity, Request $request)
    {
        $cfg = $this->cfgOrFail($entity);
        $request->validate(['file' => 'required|file|mimes:xlsx,xls|max:5120']);

        $token = (string) Str::uuid();
        $request->file('file')->storeAs('imports', "$token.xlsx");
        $path = Storage::path("imports/$token.xlsx");

        try {
            $parsed = $this->service->parse($cfg, $path);
        } catch (\Throwable $e) {
            Storage::delete("imports/$token.xlsx");
            return back()->withErrors(['file' => 'Gagal membaca file: ' . $e->getMessage()]);
        }

        return view('master.import-preview', [
            'entity'  => $entity,
            'cfg'     => $cfg,
            'token'   => $token,
            'parsed'  => $parsed,
        ]);
    }

    /** Konfirmasi → commit transaksional → hapus file temp. */
    public function commit(string $entity, Request $request)
    {
        $cfg = $this->cfgOrFail($entity);
        $request->validate(['token' => 'required|uuid']);
        $token = $request->token;
        $rel   = "imports/$token.xlsx";

        if (!Storage::exists($rel)) {
            return redirect()->route($cfg['route_index'])
                ->withErrors(['import' => 'File impor sudah kedaluwarsa, silakan upload ulang.']);
        }

        try {
            $res = $entity === 'recipes'
                ? $this->service->commitRecipes($cfg, Storage::path($rel))
                : $this->service->commit($cfg, Storage::path($rel));
        } catch (\Throwable $e) {
            return redirect()->route($cfg['route_index'])
                ->withErrors(['import' => $e->getMessage()]);
        } finally {
            Storage::delete($rel);
        }

        return redirect()->route($cfg['route_index'])
            ->with('success', "Impor {$cfg['label']} berhasil: {$res['new']} data baru, {$res['update']} diperbarui.");
    }

    // ── Bundle (multi-sheet: Bahan + Kemasan + Komposisi dalam 1 file) ─────────

    private function bundleOrFail(string $bundle): array
    {
        $cfg = $this->service->bundleConfig($bundle);
        abort_if(!$cfg, 404, 'Bundle impor tidak dikenal.');
        return $cfg;
    }

    public function bundleTemplate(string $bundle)
    {
        $cfg = $this->bundleOrFail($bundle);
        return Excel::download(new MultiSheetExport($this->service->bundleTemplateData($cfg)), "template_{$bundle}.xlsx");
    }

    public function bundlePreview(string $bundle, Request $request)
    {
        $cfg = $this->bundleOrFail($bundle);
        $request->validate(['file' => 'required|file|mimes:xlsx,xls|max:5120']);

        $token = (string) Str::uuid();
        $request->file('file')->storeAs('imports', "$token.xlsx");

        try {
            $result = $this->service->parseBundle($cfg, Storage::path("imports/$token.xlsx"));
        } catch (\Throwable $e) {
            Storage::delete("imports/$token.xlsx");
            return back()->withErrors(['file' => 'Gagal membaca file: ' . $e->getMessage()]);
        }

        return view('master.import-bundle-preview', [
            'bundle' => $bundle,
            'cfg'    => $cfg,
            'token'  => $token,
            'result' => $result,
        ]);
    }

    public function bundleCommit(string $bundle, Request $request)
    {
        $cfg = $this->bundleOrFail($bundle);
        $request->validate(['token' => 'required|uuid']);
        $rel = "imports/{$request->token}.xlsx";

        if (!Storage::exists($rel)) {
            return redirect()->route($cfg['route_index'])
                ->withErrors(['import' => 'File impor sudah kedaluwarsa, silakan upload ulang.']);
        }

        try {
            $res = $this->service->commitBundle($cfg, Storage::path($rel));
        } catch (\Throwable $e) {
            return redirect()->route($cfg['route_index'])->withErrors(['import' => $e->getMessage()]);
        } finally {
            Storage::delete($rel);
        }

        $parts = collect($res)->map(fn($r) => "{$r['label']}: {$r['new']} baru/{$r['update']} update")->implode(' • ');
        return redirect()->route($cfg['route_index'])->with('success', "Impor berhasil — {$parts}.");
    }
}
