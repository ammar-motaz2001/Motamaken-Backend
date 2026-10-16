<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'type', 'target_id'])]
class Favorite extends Model
{
    public const UPDATED_AT = null;

    public const PROJECT = 'project';

    public const PROFILE = 'profile';

    public const TYPES = [self::PROJECT, self::PROFILE];
}
