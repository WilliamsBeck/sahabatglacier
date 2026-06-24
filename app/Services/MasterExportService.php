<?php

namespace App\Services;

/**
 * Cermin dari MasterImportService: mengubah data master di DB menjadi baris
 * Excel dengan header = template impor, sehingga file hasil ekspor LANGSUNG
 * bisa diimpor kembali (mis. di komputer lain). Digerakkan config yang sama
 * (config/master-imports.php).
 */
class MasterExportService
{
    public function __construct(private MasterImportService $import) {}

    /**
     * Baris untuk satu entitas: [header, ...dataRows].
     * Kolom relasi (id) dipulihkan ke NAMA agar cocok lintas-DB.
     */
    public function rows(array $cfg): array
    {
        $columns   = $cfg['columns'];
        $relations = $cfg['relations'] ?? [];
        $model     = $cfg['model'];
        $uniqueBy  = $cfg['unique_by'] ?? [];

        $headers = array_map(fn($c) => $c['header'], $columns);

        // Preload peta [id => nama] tiap relasi (hindari N+1).
        $relMaps = [];
        foreach ($relations as $header => $rel) {
            $relMaps[$header] = $rel['model']::pluck($rel['match'], 'id');
        }

        $out  = [$headers];
        $seen = [];   // buang duplikat berdasarkan unique_by (impor hanya bisa simpan 1 per kunci)
        foreach ($model::orderBy('id')->get() as $rec) {
            if ($uniqueBy) {
                $key = implode('||', array_map(fn($k) => (string) $rec->{$k}, $uniqueBy));
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
            }
            $row = [];
            foreach ($columns as $col) {
                $header = $col['header'];
                if (isset($relations[$header])) {
                    $id  = $rec->{$relations[$header]['target']};
                    $val = $id !== null ? ($relMaps[$header][$id] ?? '') : '';
                } else {
                    $val = $rec->{$col['field']};
                    if ($val instanceof \DateTimeInterface) $val = $val->format('Y-m-d');
                    elseif (is_bool($val))                  $val = $val ? 'ya' : 'tidak';
                }
                $row[] = $val ?? '';
            }
            $out[] = $row;
        }

        return $out;
    }

    /** Map [sheetName => rows] untuk ekspor bundle multi-sheet. */
    public function bundleRows(array $bundle): array
    {
        $out = [];
        foreach ($bundle['members'] as $slug => $sheet) {
            $out[$sheet] = $this->rows($this->import->config($slug));
        }
        return $out;
    }
}
