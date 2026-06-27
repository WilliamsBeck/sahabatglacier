<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Mesin impor massal master data generik (digerakkan config/master-imports.php).
 * Lihat docs/superpowers/specs/2026-06-17-master-data-bulk-import-design.md
 */
class MasterImportService
{
    /** Ambil konfigurasi satu entitas, atau null jika tidak terdaftar. */
    public function config(string $entity): ?array
    {
        return config("master-imports.$entity");
    }

    /**
     * Baca + validasi file. Mengembalikan:
     *   ['rows' => [{row_num, status: new|update|error, attrs, display, errors[]}],
     *    'summary' => ['total','new','update','error']]
     */
    /**
     * @param string|null $sheetName Nama sheet spesifik (untuk file multi-sheet/bundle).
     * @param array $pendingNames Map [ModelClass => [nama,...]] yang dianggap valid meski
     *        belum ada di DB (akan dibuat lebih dulu pada commit bundle).
     */
    public function parse(array $cfg, string $filePath, ?string $sheetName = null, array $pendingNames = []): array
    {
        $book = IOFactory::load($filePath);
        if ($sheetName !== null) {
            $ws = $book->getSheetByName($sheetName);
            if (!$ws) throw new \RuntimeException("Sheet '{$sheetName}' tidak ditemukan dalam file.");
        } else {
            $ws = $book->getSheetByName('Data') ?? $book->getActiveSheet();
        }
        $columns  = $cfg['columns'];
        $relations = $cfg['relations'] ?? [];
        $uniqueBy = $cfg['unique_by'];
        $model    = $cfg['model'];

        // Peta header -> index kolom (baris 1), case-insensitive
        $headerMap = [];
        $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ws->getHighestDataColumn());
        for ($c = 1; $c <= $highestCol; $c++) {
            $h = strtolower(trim((string) $ws->getCellByColumnAndRow($c, 1)->getValue()));
            if ($h !== '') $headerMap[$h] = $c;
        }

        $rows      = [];
        $seenKeys  = [];               // deteksi duplikat dalam file
        $counts    = ['new' => 0, 'update' => 0, 'error' => 0];
        $maxRow    = $ws->getHighestDataRow();

        for ($r = 2; $r <= $maxRow; $r++) {
            // Ambil nilai mentah per kolom
            $raw = [];
            foreach ($columns as $col) {
                $idx = $headerMap[strtolower($col['header'])] ?? null;
                $val = $idx ? trim((string) $ws->getCellByColumnAndRow($idx, $r)->getValue()) : '';
                // Normalisasi nilai enum (case-insensitive): "Solid" -> "solid"
                if (!empty($col['lower']) && $val !== '') $val = strtolower($val);
                // Pemetaan alias: mis. "gr" -> "gram"
                if (!empty($col['map']) && $val !== '') $val = $col['map'][strtolower($val)] ?? $val;
                $raw[$col['header']] = $val;
            }

            // Lewati baris yang sepenuhnya kosong
            if (collect($raw)->every(fn($v) => $v === '')) continue;

            $errors  = [];
            $attrs   = [];
            $display = $raw;

            // 1) Validasi nilai per kolom (kecuali kolom relasi → divalidasi di tahap relasi)
            $relationCols = array_keys($relations);
            $validatable  = [];
            $vrules       = [];
            $vattrs       = [];
            $vmessages    = [
                'required' => ':attribute wajib diisi.',
                'integer'  => ':attribute harus berupa angka bulat.',
                'numeric'  => ':attribute harus berupa angka.',
                'min'      => ':attribute minimal :min.',
                'gt'       => ':attribute harus lebih besar dari :value.',
                'max'      => ':attribute maksimal :max karakter.',
                'string'   => ':attribute harus berupa teks.',
            ];
            foreach ($columns as $col) {
                if (in_array($col['header'], $relationCols)) continue;
                $val = $raw[$col['header']];
                $validatable[$col['field']] = $val === '' ? null : $val;
                $rule = $col['rules'] ?? 'nullable';
                // Daftar nilai valid diambil dinamis dari tabel master (mis. kategori bahan)
                if (!empty($col['in_from'])) {
                    $names = $col['in_from']['model']::pluck($col['in_from']['column'])
                        ->map(fn($n) => strtolower((string) $n))->filter()->values()->all();
                    if ($names) $rule .= '|in:' . implode(',', $names);
                }
                $vrules[$col['field']] = $rule;
                $vattrs[$col['field']] = $col['header'];
                // Pesan khusus untuk enum (in:...) → sebutkan pilihan yang valid
                if (preg_match('/in:([^|]+)/', $rule, $m)) {
                    $opsi = str_replace(',', ', ', $m[1]);
                    $vmessages[$col['field'] . '.in'] = ":attribute tidak valid. Pilih salah satu: {$opsi}.";
                }
            }
            $validator = Validator::make($validatable, $vrules, $vmessages, $vattrs);
            foreach ($validator->errors()->all() as $msg) $errors[] = $msg;

            // 2) Susun atribut dari kolom non-relasi
            foreach ($columns as $col) {
                if (in_array($col['header'], $relationCols)) continue;
                $val = $raw[$col['header']];
                $cast = $col['cast'] ?? 'string';
                if ($cast === 'bool') {
                    $attrs[$col['field']] = $this->toBool($val);          // kosong = true
                } elseif ($val === '') {
                    if (!empty($col['required'])) { /* error sudah ditangani validator */ }
                    // kolom opsional kosong → biarkan default DB (tidak di-set)
                } else {
                    $attrs[$col['field']] = $this->cast($val, $cast);
                }
            }

            // 3) Resolusi relasi (lookup by nama → id)
            foreach ($relations as $colHeader => $rel) {
                $val = $raw[$colHeader] ?? '';
                if ($val === '') {
                    if (empty($rel['nullable'])) {
                        $errors[] = ucfirst($rel['label']) . " wajib diisi.";
                    } else {
                        $attrs[$rel['target']] = null;
                    }
                    continue;
                }
                $found = $rel['model']::where($rel['match'], $val)->first();
                if ($found) {
                    $attrs[$rel['target']] = $found->id;
                    if (!empty($rel['keep_name'])) $attrs[$colHeader] = $val;
                } elseif (in_array($val, $pendingNames[$rel['model']] ?? [], true)) {
                    // Akan dibuat lebih dulu dalam impor bundle ini → dianggap valid saat pratinjau.
                    $attrs[$rel['target']] = null;
                    if (!empty($rel['keep_name'])) $attrs[$colHeader] = $val;
                } else {
                    $errors[] = "{$rel['label']} '{$val}' tidak ditemukan.";
                }
            }

            // 4) Validasi khusus: parent != child untuk komposisi
            if (isset($relations['parent'], $relations['child'])
                && ($raw['parent'] ?? '') !== '' && $raw['parent'] === ($raw['child'] ?? null)) {
                $errors[] = "Parent dan child tidak boleh bahan yang sama.";
            }

            // 5) Kunci unik + deteksi duplikat dalam file
            $keyParts = [];
            $keyComplete = true;
            foreach ($uniqueBy as $k) {
                if (!array_key_exists($k, $attrs) || $attrs[$k] === null || $attrs[$k] === '') { $keyComplete = false; break; }
                $keyParts[] = (string) $attrs[$k];
            }
            $status = 'error';
            if (empty($errors)) {
                if ($keyComplete) {
                    $keyStr = implode('||', $keyParts);
                    if (isset($seenKeys[$keyStr])) {
                        $errors[] = "Duplikat dengan baris {$seenKeys[$keyStr]} dalam file ini.";
                    } else {
                        $seenKeys[$keyStr] = $r;
                        $uniqueAttrs = array_intersect_key($attrs, array_flip($uniqueBy));
                        $exists = $model::where($uniqueAttrs)->exists();
                        $status = $exists ? 'update' : 'new';
                    }
                } else {
                    // Kunci belum lengkap karena mengacu data yang akan dibuat lebih dulu
                    // dalam impor bundle (relasi pending) → anggap data baru.
                    $status = 'new';
                }
            }

            if (!empty($errors)) $status = 'error';
            $counts[$status]++;

            $rows[] = [
                'row_num' => $r,
                'status'  => $status,
                'attrs'   => $attrs,
                'display' => $display,
                'errors'  => $errors,
            ];
        }

        return [
            'rows'    => $rows,
            'summary' => [
                'total'  => count($rows),
                'new'    => $counts['new'],
                'update' => $counts['update'],
                'error'  => $counts['error'],
            ],
        ];
    }

    /**
     * Commit hasil parse ke DB dalam 1 transaction. Melempar exception
     * (rollback) bila masih ada baris error.
     * @return array ['new'=>int,'update'=>int]
     */
    public function commit(array $cfg, string $filePath): array
    {
        $parsed = $this->parse($cfg, $filePath);
        if ($parsed['summary']['error'] > 0) {
            throw new \RuntimeException('Masih ada baris yang error; impor dibatalkan.');
        }

        $model    = $cfg['model'];
        $uniqueBy = $cfg['unique_by'];
        $new = 0; $update = 0;

        DB::transaction(function () use ($parsed, $model, $uniqueBy, &$new, &$update) {
            foreach ($parsed['rows'] as $row) {
                if ($row['status'] === 'error') continue;
                $attrs       = $row['attrs'];
                $uniqueAttrs = array_intersect_key($attrs, array_flip($uniqueBy));
                $model::updateOrCreate($uniqueAttrs, $attrs);
                $row['status'] === 'update' ? $update++ : $new++;
            }
        });

        return ['new' => $new, 'update' => $update];
    }

    /**
     * Commit KHUSUS resep: kelompokkan baris per menu jadi satu recipe_group_id (UUID),
     * isi created_by dari super admin, store global (null). Mengganti resep lama menu itu.
     * @return array ['new'=>int,'update'=>int]
     */
    public function commitRecipes(array $cfg, string $filePath, ?string $sheet = null): array
    {
        $parsed = $this->parse($cfg, $filePath, $sheet);
        if ($parsed['summary']['error'] > 0) {
            throw new \RuntimeException('Masih ada baris yang error; impor dibatalkan.');
        }

        $userId = \App\Models\User::where('role', 'super_admin')->value('id')
            ?? \App\Models\User::value('id');
        if (!$userId) {
            throw new \RuntimeException('Belum ada akun pengguna. Buat akun admin dulu sebelum impor resep.');
        }

        $new = 0; $update = 0;
        DB::transaction(function () use ($parsed, $userId, &$new, &$update) {
            // Kelompokkan baris valid per menu_id
            $byMenu = [];
            foreach ($parsed['rows'] as $row) {
                if ($row['status'] === 'error') continue;
                $byMenu[$row['attrs']['menu_id']][] = $row['attrs'];
            }
            foreach ($byMenu as $menuId => $items) {
                // Resep global menu ini diganti utuh (hapus lalu tulis ulang sebagai 1 versi).
                $existed = \App\Models\Recipe::where('menu_id', $menuId)->whereNull('store_id')->exists();
                \App\Models\Recipe::where('menu_id', $menuId)->whereNull('store_id')->delete();

                $gid = (string) \Illuminate\Support\Str::uuid();
                foreach ($items as $a) {
                    \App\Models\Recipe::create([
                        'menu_id'         => $menuId,
                        'store_id'        => null,
                        'recipe_group_id' => $gid,
                        'ingredient_id'   => $a['ingredient_id'],
                        'qty_usage'       => $a['qty_usage'],
                        'unit'            => $a['unit'],
                        'effective_from'  => $a['effective_from'] ?? now()->toDateString(),
                        'created_by'      => $userId,
                    ]);
                    $existed ? $update++ : $new++;
                }
            }
        });

        return ['new' => $new, 'update' => $update];
    }

    /** Data array untuk template (header + baris contoh). */
    public function templateData(array $cfg): array
    {
        $headers = array_map(fn($c) => $c['header'], $cfg['columns']);
        return array_merge([$headers], $cfg['sample_rows'] ?? []);
    }

    // ── Bundle: beberapa entitas dalam satu file multi-sheet ───────────────────

    /** Definisi bundle. member = slug entitas; sheet = nama sheet di file. */
    public function bundleConfig(string $bundle): ?array
    {
        $bundles = [
            'bahan' => [
                'label'       => 'Bahan, Kemasan & Komposisi',
                'route_index' => 'master.ingredients.index',
                'members' => [
                    'ingredients'             => 'Bahan',
                    'packagings'              => 'Kemasan',
                    'ingredient-compositions' => 'Komposisi',
                ],
            ],
            'menu-resep' => [
                'label'       => 'Menu & Resep',
                'route_index' => 'master.menus.index',
                'members' => [
                    'menus'   => 'Menu',
                    'recipes' => 'Resep',
                ],
            ],
        ];
        return $bundles[$bundle] ?? null;
    }

    /** Map [sheetName => data array] untuk template multi-sheet. */
    public function bundleTemplateData(array $bundle): array
    {
        $out = [];
        foreach ($bundle['members'] as $slug => $sheet) {
            $out[$sheet] = $this->templateData($this->config($slug));
        }
        return $out;
    }

    /**
     * Pratinjau bundle: parse tiap sheet. Nama bahan dari sheet "Bahan" diperlakukan
     * sebagai "pending" agar Kemasan/Komposisi yang mengacu bahan baru tidak dianggap error.
     * @return array ['members' => [slug => ['cfg','sheet','parsed']], 'has_error' => bool, 'total_save' => int]
     */
    public function parseBundle(array $bundle, string $filePath): array
    {
        $pending  = [];

        // Untuk bundle bahan: build pending ingredients dari sheet Bahan
        if (isset($bundle['members']['ingredients'])) {
            $ingSlug   = 'ingredients';
            $ingSheet  = $bundle['members'][$ingSlug];
            $ingParsed = $this->parse($this->config($ingSlug), $filePath, $ingSheet);
            $pendingIngredients = collect($ingParsed['rows'])
                ->where('status', '!=', 'error')
                ->pluck('attrs.name')->filter()->values()->all();
            $pending = [\App\Models\Ingredient::class => $pendingIngredients];
        }

        $members   = [];
        $hasError  = false;
        $totalSave = 0;
        foreach ($bundle['members'] as $slug => $sheet) {
            $parsed = $this->parse($this->config($slug), $filePath, $sheet, $pending);
            if ($parsed['summary']['error'] > 0) $hasError = true;
            $totalSave += $parsed['summary']['new'] + $parsed['summary']['update'];
            $members[$slug] = ['cfg' => $this->config($slug), 'sheet' => $sheet, 'parsed' => $parsed];
        }

        return ['members' => $members, 'has_error' => $hasError, 'total_save' => $totalSave];
    }

    /**
     * Commit bundle dalam 1 transaction, berurutan: Bahan dulu (agar id tersedia),
     * lalu Kemasan & Komposisi (relasi resolve dari DB di transaksi yang sama).
     * @return array [slug => ['new'=>int,'update'=>int]]
     */
    public function commitBundle(array $bundle, string $filePath): array
    {
        $result = [];
        DB::transaction(function () use ($bundle, $filePath, &$result) {
            foreach ($bundle['members'] as $slug => $sheet) {
                $cfg = $this->config($slug);
                // Resep pakai commitRecipes (butuh recipe_group_id)
                if ($slug === 'recipes') {
                    $result[$slug] = $this->commitRecipes($cfg, $filePath, $sheet);
                    $result[$slug]['label'] = $cfg['label'];
                    continue;
                }
                $parsed = $this->parse($cfg, $filePath, $sheet);
                if ($parsed['summary']['error'] > 0) {
                    throw new \RuntimeException("Sheet '{$sheet}' masih ada baris error; impor dibatalkan.");
                }
                $new = 0; $update = 0;
                foreach ($parsed['rows'] as $row) {
                    if ($row['status'] === 'error') continue;
                    $uniqueAttrs = array_intersect_key($row['attrs'], array_flip($cfg['unique_by']));
                    $cfg['model']::updateOrCreate($uniqueAttrs, $row['attrs']);
                    $row['status'] === 'update' ? $update++ : $new++;
                }
                $result[$slug] = ['new' => $new, 'update' => $update, 'label' => $cfg['label']];
            }
        });
        return $result;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function cast(string $val, string $type)
    {
        return match ($type) {
            'int'   => (int) $val,
            'float' => (float) str_replace(',', '.', $val),
            'bool'  => $this->toBool($val),
            default => $val,
        };
    }

    private function toBool(string $val): bool
    {
        $v = strtolower(trim($val));
        if ($v === '') return true; // default aktif
        return in_array($v, ['1', 'true', 'ya', 'yes', 'aktif', 'y'], true);
    }
}
