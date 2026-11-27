<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessAlert extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
