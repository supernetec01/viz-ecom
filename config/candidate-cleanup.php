<?php

return [
    'lock_root' => storage_path('framework/candidate-file-locks'),
    'staging_root' => storage_path('app/pdf-inspection'),
    'max_orphan_ratio' => 0.20,
    'empty_metadata_limit' => 10,
    'scheduled_enabled' => env('CANDIDATE_CLEANUP_SCHEDULED', false),
    'production_force_enabled' => env('CANDIDATE_CLEANUP_PRODUCTION_FORCE', false),
];
