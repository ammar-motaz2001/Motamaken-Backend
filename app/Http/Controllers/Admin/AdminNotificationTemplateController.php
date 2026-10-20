<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminNotificationTemplateController extends Controller
{
    /**
     * Notification templates.
     *
     * Every event that sends a notification (proposal accepted, milestone released, dispute opened...), with the default
     * wording in both languages, the placeholders it uses (`:name`, `:project`...) and the staff's wording when set. The
     * same text is used in the bell and in the email.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function index(): array
    {
        $en = trans('notifications', [], 'en');
        $ar = trans('notifications', [], 'ar');
        $custom = NotificationTemplate::all()->keyBy('type');
        $skip = ['email_subject', 'email_greeting', 'email_action', 'email_footer', 'medal_generic'];

        return ['data' => collect($en)->filter(fn ($text, $type) => is_string($text) && ! in_array($type, $skip, true))
            ->map(function (string $text, string $type) use ($ar, $custom) {
                preg_match_all('/:([a-zA-Z]+)/', $text, $matches);
                $template = $custom->get($type);

                return [
                    'type' => $type,
                    'group' => collect(config('notifications.groups'))->search(fn ($prefixes) => collect($prefixes)->contains(fn ($p) => str_starts_with($type, $p))) ?: 'other',
                    'defaultEn' => $text,
                    'defaultAr' => is_string($ar[$type] ?? null) ? $ar[$type] : $text,
                    'placeholders' => array_values(array_unique($matches[1])),
                    'custom' => $template ? [
                        'textEn' => $template->text_en, 'textAr' => $template->text_ar, 'subjectEn' => $template->subject_en,
                        'subjectAr' => $template->subject_ar, 'isActive' => $template->is_active, 'updatedAt' => $template->updated_at?->toIso8601String(),
                    ] : null,
                ];
            })->values()->all()];
    }

    /**
     * Save a template.
     *
     * Replaces the default wording of one event in both languages, with an optional email subject. Use the same
     * placeholders as the default.
     *
     * @return array{success: bool}
     */
    public function update(Request $request, string $type): array
    {
        abort_unless(is_string(trans("notifications.{$type}", [], 'en')) && trans("notifications.{$type}", [], 'en') !== "notifications.{$type}", 404);
        $data = $request->validate([
            'textEn' => ['required', 'string', 'max:500'],
            'textAr' => ['required', 'string', 'max:500'],
            'subjectEn' => ['nullable', 'string', 'max:150'],
            'subjectAr' => ['nullable', 'string', 'max:150'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        NotificationTemplate::updateOrCreate(['type' => $type], [
            'text_en' => $data['textEn'], 'text_ar' => $data['textAr'], 'subject_en' => $data['subjectEn'] ?? null,
            'subject_ar' => $data['subjectAr'] ?? null, 'is_active' => $data['isActive'] ?? true, 'updated_by' => $request->user()->id,
        ]);

        return ['success' => true];
    }

    /**
     * Back to the default wording.
     *
     * @return array{success: bool}
     */
    public function destroy(string $type): array
    {
        NotificationTemplate::where('type', $type)->first()?->delete();

        return ['success' => true];
    }
}
