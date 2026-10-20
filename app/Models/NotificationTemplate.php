<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['type', 'text_en', 'text_ar', 'subject_en', 'subject_ar', 'is_active', 'updated_by'])]
class NotificationTemplate extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

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

        return static::substitute($text, $replace);
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

        return $subject ? static::substitute($subject, $replace) : null;
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
