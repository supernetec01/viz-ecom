<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobReview extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime'];
    }
}
