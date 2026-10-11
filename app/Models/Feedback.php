<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'context', 'reference_id', 'rating', 'comment'])]
class Feedback extends Model
{
    public const CONTEXTS = ['deposit', 'withdrawal', 'promotion', 'subscription', 'payment_method', 'escrow'];

    protected $table = 'feedback';

    public const UPDATED_AT = null;
}
