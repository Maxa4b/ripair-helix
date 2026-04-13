<?php

return [
    'root' => env('CSV_EXPLORER_ROOT', storage_path('app/private/csv-explorer')),
    'label' => env('CSV_EXPLORER_LABEL', 'CSV Explorer VPS'),
    'extensions' => array_values(array_filter(array_map(
        static fn (string $value) => strtolower(trim($value)),
        explode(',', (string) env('CSV_EXPLORER_EXTENSIONS', 'csv,tsv,txt'))
    ))),
];
