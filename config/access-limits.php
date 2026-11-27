<?php

return [
    'file' => (int) env('ACCESS_FILE_LIMIT', 10),
    'details' => (int) env('ACCESS_DETAIL_LIMIT', 30),
    'window_seconds' => (int) env('ACCESS_WINDOW_SECONDS', 60),
    'alert_cooldown_seconds' => (int) env('ACCESS_ALERT_COOLDOWN_SECONDS', 900),
];
