<?php

return [
    'python' => env('GAULOIS_PYTHON_BIN', 'python3'),
    'script_path' => env('GAULOIS_SCRIPT_PATH', base_path('../ripair_import/gaulois.py')),
    'working_directory' => env('GAULOIS_WORKING_DIR', base_path('../ripair_import')),
    'logs_directory' => env('GAULOIS_LOGS_DIR', base_path('../ripair_import/logs')),
    'seeds_directory' => env('GAULOIS_SEEDS_DIR', base_path('../ripair_import')),
    'timeout' => (int) env('GAULOIS_TIMEOUT_SECONDS', 900),
];
