<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Support\Paged;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Support conversations ("Contact support"): a user opens a ticket, the staff answer in the same thread.
 */
class SupportService
{
    /**
     * Open a ticket with its first message and tell the admins who handle support.
     */
    public function open(User $user, string $subject, string $category, string $body, ?UploadedFile $file): SupportTicket
    {
        $ticket = DB::transaction(function () use ($user, $subject, $category, $body, $file) {
            $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => $subject, 'category' => $category, 'last_message_at' => now()]);
            $this->add($ticket, $user, $body, $file, false);

            return $ticket;
        });

        foreach (User::where('is_admin', true)->get()->filter(fn (User $a) => $a->canAdmin('support')) as $admin) {
            $admin->notify(new ActivityNotification('admin.support_ticket', $user, ['ticketId' => $ticket->id, 'subject' => $subject]));
        }

        return $ticket;
    }

    /**
     * Add a message to a ticket. A staff reply marks it answered and is assigned to that staff member if nobody has it;
     * a user reply reopens it and tells the assignee. Users cannot write on a closed ticket.
     */
    public function reply(SupportTicket $ticket, User $author, ?string $body, ?UploadedFile $file, bool $asStaff): SupportMessage
    {
        if ($ticket->status === SupportTicket::CLOSED && ! $asStaff) {
            throw new ApiException(__('support.closed'), 'ticket_closed');
        }

        $message = DB::transaction(function () use ($ticket, $author, $body, $file, $asStaff) {
            $message = $this->add($ticket, $author, $body, $file, $asStaff);
            $ticket->update([
                'status' => $asStaff ? SupportTicket::ANSWERED : SupportTicket::OPEN,
                'last_message_at' => now(),
                'assigned_to' => $asStaff ? ($ticket->assigned_to ?? $author->id) : $ticket->assigned_to,
            ]);

            return $message;
        });

        if ($asStaff) {
            $ticket->user->notify(new ActivityNotification('support.replied', null, ['ticketId' => $ticket->id, 'subject' => $ticket->subject]));
        } elseif ($ticket->assigned_to && $assignee = User::find($ticket->assigned_to)) {
            $assignee->notify(new ActivityNotification('admin.support_reply', $author, ['ticketId' => $ticket->id, 'subject' => $ticket->subject]));
        }

        return $message;
    }

    /**
     * Store one message of a ticket, with its optional attachment kept on the private disk.
     */
    private function add(SupportTicket $ticket, User $author, ?string $body, ?UploadedFile $file, bool $asStaff): SupportMessage
    {
        return $ticket->messages()->create([
            'user_id' => $author->id,
            'is_staff' => $asStaff,
            'body' => $body,
            'attachment_path' => $file?->store("support/{$ticket->id}", 'local'),
            'attachment_name' => $file?->getClientOriginalName(),
            'attachment_mime' => $file?->getMimeType(),
            'attachment_size' => $file?->getSize(),
        ]);
    }

    /**
     * The API shape of a ticket, optionally with its messages. Staff names are hidden from users.
     *
     * @return array<string, mixed>
     */
    public static function presentTicket(SupportTicket $t, bool $withMessages = false, bool $forStaff = false): array
    {
        return [
            'id' => $t->id,
            'subject' => $t->subject,
            /** `account`, `payments`, `projects`, `technical` or `other`. */
            'category' => $t->category,
            /** `open` (waiting for the staff), `answered` (waiting for you) or `closed`. */
            'status' => $t->status,
            'user' => $forStaff && $t->relationLoaded('user') ? Paged::user($t->user) : null,
            'assignedTo' => $forStaff && $t->relationLoaded('assignee') ? Paged::user($t->assignee) : null,
            'lastMessageAt' => $t->last_message_at?->toIso8601String(),
            'createdAt' => $t->created_at?->toIso8601String(),
            'messages' => $withMessages ? $t->messages->map(fn (SupportMessage $m) => [
                'id' => $m->id,
                'isStaff' => $m->is_staff,
                /** Staff names stay private for users: they see "Mutamakin support". */
                'author' => $forStaff || ! $m->is_staff ? ($m->user ? ['id' => $m->user->id, 'fullName' => $m->user->full_name, 'avatarUrl' => $m->user->avatar_url] : null) : null,
                'body' => $m->body,
                'attachment' => $m->attachment_path ? [
                    'name' => $m->attachment_name, 'mime' => $m->attachment_mime, 'size' => $m->attachment_size,
                    'url' => URL::temporarySignedRoute('support.attachment', now()->addMinutes(30), ['message' => $m->id]),
                ] : null,
                'createdAt' => $m->created_at?->toIso8601String(),
            ])->all() : null,
        ];
    }
}
