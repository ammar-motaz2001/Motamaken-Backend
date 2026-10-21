<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $to
 * @property string|null $subject
 * @property string|null $notification
 * @property string $status
 * @property string|null $error
 * @property Carbon $created_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MailLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MailLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MailLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MailLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MailLog whereError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MailLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MailLog whereNotification($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MailLog whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MailLog whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MailLog whereTo($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['to', 'subject', 'notification', 'status', 'error'])]
class MailLog extends Model
{
    public const UPDATED_AT = null;
}
