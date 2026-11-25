<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['path', 'original_name', 'preview_path', 'sha256', 'scanned_sha256'])]
class CandidateFile extends Model
{
    protected $table = 'files';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['review_required' => 'boolean', 'processing_version' => 'integer', 'processing_attempts' => 'integer',
            'scan_attempts' => 'integer', 'preview_attempts' => 'integer', 'size' => 'integer', 'scanned_at' => 'immutable_datetime'];
    }
}
