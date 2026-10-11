<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['escrow_id', 'client_id', 'amount_cents', 'currency', 'note'])]
class Tip extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }
}
