<?php

namespace App\Http\Controllers;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Rules\CleanText;
use App\Services\SupportService;
use App\Support\Paged;
use App\Support\RuntimeConfig;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Support', 'Conversations with the Mutamakin support team.', weight: 15)]
class SupportController extends Controller
{
    public function __construct(private readonly SupportService $support) {}

    /**
     * My support tickets.
     *
     * Newest activity first.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function index(Request $request): array
    {
        $page = SupportTicket::where('user_id', $request->user()->id)->orderByDesc('last_message_at')->paginate($request->integer('perPage', 15));

        return Paged::of($page, fn (SupportTicket $t) => SupportService::presentTicket($t));
    }

    /**
     * Contact support.
     *
     * Opens a ticket with the first message (and an optional attachment, `multipart/form-data`). The staff answer in the
     * same thread and you are notified.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:150', new CleanText],
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'body' => ['required', 'string', 'min:10', 'max:5000', new CleanText],
            'file' => ['nullable', 'file', 'mimes:'.RuntimeConfig::attachmentTypes(), 'max:'.RuntimeConfig::uploadKb('document')],
        ]);

        $ticket = $this->support->open($request->user(), $data['subject'], $data['category'], $data['body'], $request->file('file'));

        return response()->json(['data' => SupportService::presentTicket($ticket->load('messages.user'), true)], 201);
    }

    /**
     * Show a ticket.
     *
     * With the whole conversation, oldest first.
     *
     * @return array{data: array<string, mixed>}
     */
    public function show(Request $request, int $ticket): array
    {
        return ['data' => SupportService::presentTicket($this->mine($request, $ticket)->load('messages.user'), true)];
    }

    /**
     * Reply on a ticket.
     *
     * Reopens it for the staff. Not possible once closed: open a new ticket.
     *
     * @return array{data: array<string, mixed>}
     */
    public function reply(Request $request, int $ticket): array
    {
        $request->validate([
            'body' => ['required_without:file', 'nullable', 'string', 'max:5000', new CleanText],
            'file' => ['nullable', 'file', 'mimes:'.RuntimeConfig::attachmentTypes(), 'max:'.RuntimeConfig::uploadKb('document')],
        ]);
        $ticket = $this->mine($request, $ticket);
        $this->support->reply($ticket, $request->user(), $request->input('body'), $request->file('file'), false);

        return ['data' => SupportService::presentTicket($ticket->refresh()->load('messages.user'), true)];
    }

    /**
     * Close a ticket.
     *
     * When the problem is solved.
     *
     * @return array{data: array<string, mixed>}
     */
    public function close(Request $request, int $ticket): array
    {
        $ticket = $this->mine($request, $ticket);
        $ticket->update(['status' => SupportTicket::CLOSED]);

        return ['data' => SupportService::presentTicket($ticket->load('messages.user'), true)];
    }

    /**
     * Download an attachment.
     *
     * Through the signed link in the ticket.
     *
     * @unauthenticated
     */
    public function attachment(int $message): StreamedResponse
    {
        $message = SupportMessage::findOrFail($message);
        abort_unless($message->attachment_path, 404);

        return Storage::disk('local')->download($message->attachment_path, $message->attachment_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    private function mine(Request $request, int $ticket): SupportTicket
    {
        return SupportTicket::where('user_id', $request->user()->id)->findOrFail($ticket);
    }
}
