<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'code', 'awarded_at', 'awarded_by', 'reason'])]
class UserMedal extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['awarded_at' => 'datetime'];
    }
}
