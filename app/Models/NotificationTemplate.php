<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $type
 * @property string $text_en
 * @property string $text_ar
 * @property string|null $subject_en
 * @property string|null $subject_ar
 * @property bool $is_active
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereSubjectAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereSubjectEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereTextAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereTextEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereUpdatedBy($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['type', 'text_en', 'text_ar', 'subject_en', 'subject_ar', 'is_active', 'updated_by'])]
class NotificationTemplate extends Model
{
    /**
     * is_active becomes a boolean.
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Any save or delete clears the cached templates, so edits apply to the next notification.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('notification_templates'));
        static::deleted(fn () => Cache::forget('notification_templates'));
    }

    /**
     * The active templates keyed by type.
     *
     * @return array<string, array{text_en: string, text_ar: string, subject_en: ?string, subject_ar: ?string}>
     */
    public static function active(): array
    {
        return Cache::remember('notification_templates', 300, fn () => static::where('is_active', true)
            ->get(['type', 'text_en', 'text_ar', 'subject_en', 'subject_ar'])->keyBy('type')
            ->map(fn (self $t) => $t->only(['text_en', 'text_ar', 'subject_en', 'subject_ar']))->all());
    }

    /**
     * The text of a notification type in the current language: the admin's wording when there is one, else the default.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function text(string $type, array $replace): string
    {
        $template = static::active()[$type] ?? null;

        if (! $template) {
            $line = __("notifications.{$type}", $replace);

            // Medals and announcements created in the panel have no line of their own.
            if ($line === "notifications.{$type}" && str_starts_with($type, 'medal.')) {
                $name = app()->getLocale() === 'ar' ? ($replace['medalAr'] ?? $replace['medal'] ?? '') : ($replace['medal'] ?? '');

                return __('notifications.medal_generic', ['medal' => $name]);
            }

            return $line;
        }

        $text = app()->getLocale() === 'ar' ? $template['text_ar'] : $template['text_en'];

        return self::substitute($text, $replace);
    }

    /**
     * The email subject for a type, or null to use the default.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function subject(string $type, array $replace): ?string
    {
        $template = static::active()[$type] ?? null;
        $subject = $template ? (app()->getLocale() === 'ar' ? $template['subject_ar'] : $template['subject_en']) : null;

        return $subject ? self::substitute($subject, $replace) : null;
    }

    /**
     * Placeholders are written `:name` like the language files (and `{{name}}` is accepted too).
     *
     * @param  array<string, mixed>  $replace
     */
    private static function substitute(string $text, array $replace): string
    {
        // Longest first, so `:name` does not eat the start of `:names`.
        uksort($replace, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($replace as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $text = str_replace([":{$key}", "{{{$key}}}", "{{ {$key} }}"], (string) $value, $text);
            }
        }

        return $text;
    }
}
