<?php

return [
    'enabled' => env('PDF_INSPECTION_ENABLED', false),
    // Remains false until the deployment's corpus and isolation tests have passed.
    'production_enabled' => env('PDF_PRODUCTION_ENABLED', false),
    'docker' => env('PDF_DOCKER_BINARY', 'docker'),
    'image' => env('PDF_INSPECTOR_IMAGE', 'viz-ecom-qpdf:local'),
    'timeout' => 5.0,
    'memory_bytes' => 268435456,
    'output_bytes' => 16777216,
    'max_objects' => 10000,
    'max_depth' => 32,
];
