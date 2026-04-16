<?php

$inputExtensions = array_values(array_filter(array_map(
    static fn (string $value): string => strtolower(trim($value)),
    explode(',', (string) env('COMPANY_ENRICHMENT_INPUT_EXTENSIONS', 'csv,tsv,txt,parquet'))
), static fn (string $value): bool => $value !== ''));

return [
    'input_root' => env('COMPANY_ENRICHMENT_INPUT_ROOT', storage_path('app/private/prospecting/imports')),
    'input_label' => env('COMPANY_ENRICHMENT_INPUT_LABEL', 'Sources VPS'),
    'input_extensions' => $inputExtensions,
    'generated_seed_directory' => env('COMPANY_ENRICHMENT_GENERATED_SEED_DIRECTORY', '_generated'),
    'generated_seed_filename' => env('COMPANY_ENRICHMENT_GENERATED_SEED_FILENAME', 'domain_seed.csv'),
    'jobs_directory' => env('COMPANY_ENRICHMENT_JOBS_DIRECTORY', storage_path('app/private/prospecting/enrichment/jobs')),
    'output_root' => env('COMPANY_ENRICHMENT_OUTPUT_ROOT', storage_path('app/private/prospecting/enrichment/runs')),
    'pipeline_root' => env('COMPANY_ENRICHMENT_PIPELINE_ROOT', base_path('company_enrichment')),
    'default_config' => env('COMPANY_ENRICHMENT_DEFAULT_CONFIG', base_path('company_enrichment/config.example.yaml')),
    'python_binary' => env('COMPANY_ENRICHMENT_PYTHON_BINARY', 'python'),
    'php_binary' => env('COMPANY_ENRICHMENT_PHP_BINARY', 'php'),
    'job_list_limit' => (int) env('COMPANY_ENRICHMENT_JOB_LIST_LIMIT', 15),
    'disable_process_launch' => filter_var(env('COMPANY_ENRICHMENT_DISABLE_PROCESS_LAUNCH', false), FILTER_VALIDATE_BOOL),
];
