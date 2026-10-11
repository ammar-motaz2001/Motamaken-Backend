<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['draw_id', 'user_id', 'tickets'])]
class DrawEntry extends Model
{
    protected function casts(): array
    {
        return ['tickets' => 'integer'];
    }
}
