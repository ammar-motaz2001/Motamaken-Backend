<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Notifications\ContactReply;
use App\Support\LikeSearch;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminContactController extends Controller
{
    /**
     * Contact messages.
     *
     * What visitors and users sent through the contact form, newest first: new ones by default. Archived messages are
     * left out unless asked for. Search the name, email, subject and text.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, counts: array<string, int>}
     */
    public function index(Request $request): array
    {
        $request->validate([
            'status' => ['nullable', Rule::in([ContactMessage::NEW, ContactMessage::HANDLED])],
            'topic' => ['nullable', Rule::in(ContactMessage::TOPICS)],
            'q' => ['nullable', 'string', 'max:100'],
            /** `1` for the archive. */
            'archived' => ['nullable', 'boolean'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->filtered($request)->latest('id')->paginate($request->integer('perPage', 20));

        return Paged::of($page, fn (ContactMessage $m) => $this->present($m), [
            'counts' => [
                'new' => ContactMessage::where('status', ContactMessage::NEW)->whereNull('archived_at')->count(),
                'handled' => ContactMessage::where('status', ContactMessage::HANDLED)->whereNull('archived_at')->count(),
                'archived' => ContactMessage::whereNotNull('archived_at')->count(),
            ],
        ]);
    }

    /**
     * @return Builder<ContactMessage>
     */
    public function filtered(Request $request): Builder
    {
        return ContactMessage::query()
            ->when($request->boolean('archived'), fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at')
                ->where('status', $request->input('status', ContactMessage::NEW)))
            ->when($request->input('topic'), fn ($q, $topic) => $q->where('topic', $topic))
            ->when($request->input('q'), fn ($q, $term) => LikeSearch::apply($q, ['name', 'email', 'subject', 'message'], $term));
    }

    /**
     * Mark as handled.
     *
     * With an optional note for the other admins.
     *
     * @return array{data: array<string, mixed>}
     */
    public function handle(Request $request, int $message): array
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $message = ContactMessage::findOrFail($message);
        $message->update([
            'status' => ContactMessage::HANDLED, 'admin_note' => $data['note'] ?? $message->admin_note,
            'handled_by' => $request->user()->id, 'handled_at' => now(),
        ]);

        return ['data' => $this->present($message)];
    }

    /**
     * Reply by email.
     *
     * Emails the answer to the sender (quoting their message) and marks the message handled.
     *
     * @return array{data: array<string, mixed>}
     */
    public function reply(Request $request, int $message): array
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:5', 'max:5000']]);
        $message = ContactMessage::findOrFail($message);

        Notification::route('mail', [$message->email => $message->name])
            ->notify((new ContactReply($message->name, $message->subject, $data['body'], $message->message))->locale(app()->getLocale()));

        $message->update([
            'reply' => $data['body'], 'replied_at' => now(), 'status' => ContactMessage::HANDLED,
            'handled_by' => $request->user()->id, 'handled_at' => $message->handled_at ?? now(),
        ]);

        return ['data' => $this->present($message)];
    }

    /**
     * Archive or restore.
     *
     * @return array{data: array<string, mixed>}
     */
    public function archive(Request $request, int $message): array
    {
        $data = $request->validate(['archived' => ['required', 'boolean']]);
        $message = ContactMessage::findOrFail($message);
        $message->update(['archived_at' => $data['archived'] ? now() : null]);

        return ['data' => $this->present($message)];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ContactMessage $m): array
    {
        return [
            'id' => $m->id, 'userId' => $m->user_id, 'name' => $m->name, 'email' => $m->email, 'topic' => $m->topic,
            'subject' => $m->subject, 'message' => $m->message, 'status' => $m->status, 'adminNote' => $m->admin_note,
            'reply' => $m->reply, 'repliedAt' => $m->replied_at?->toIso8601String(), 'archivedAt' => $m->archived_at?->toIso8601String(),
            'createdAt' => $m->created_at?->toIso8601String(), 'handledAt' => $m->handled_at?->toIso8601String(),
        ];
    }
}
