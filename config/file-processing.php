<?php

return [
    'image' => env('FILE_PROCESSOR_IMAGE', 'viz-ecom-file-processor:local'),
    'definitions_root' => env('CLAMAV_DATABASE_DIR', storage_path('app/clamav-definitions')),
    'definitions_max_age_hours' => 72,
    'staging_root' => storage_path('app/file-processing'),
    'timeout' => 35.0,
    'memory_bytes' => 1073741824,
    'tmp_bytes' => 67108864,
];
