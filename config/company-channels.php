<?php

return [
    'shopee' => ['shopee.vn', 'shopee.com'],
    'tiktok' => ['tiktok.com'],
    'lazada' => ['lazada.vn', 'lazada.com'],
    'youtube' => ['youtube.com', 'youtu.be'],
    'facebook' => ['facebook.com', 'fb.com', 'fb.watch'],
    'website' => array_values(array_filter(array_map('trim', explode(',', env('COMPANY_WEBSITE_DOMAINS', ''))))),
];
