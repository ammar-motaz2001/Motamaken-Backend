<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminContactController extends Controller
{
    /**
     * Contact messages.
     *
     * What visitors and users sent through "Contact us", newest first. Unhandled ones by default.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function index(Request $request): array
    {
        $request->validate([
            'status' => ['nullable', Rule::in([ContactMessage::NEW, ContactMessage::HANDLED])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = ContactMessage::where('status', $request->input('status', ContactMessage::NEW))
            ->latest('id')->paginate($request->integer('perPage', 20));

        return [
            'data' => $page->getCollection()->map(fn (ContactMessage $m) => $this->present($m))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
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
            'status' => ContactMessage::HANDLED, 'admin_note' => $data['note'] ?? null,
            'handled_by' => $request->user()->id, 'handled_at' => now(),
        ]);

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
            'createdAt' => $m->created_at?->toIso8601String(), 'handledAt' => $m->handled_at?->toIso8601String(),
        ];
    }
}
