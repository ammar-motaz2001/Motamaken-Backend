<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['to', 'subject', 'notification', 'status', 'error'])]
class MailLog extends Model
{
    public const UPDATED_AT = null;
}
