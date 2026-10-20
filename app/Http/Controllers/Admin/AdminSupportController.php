<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\SupportService;
use App\Support\LikeSearch;
use App\Support\Paged;
use App\Support\RuntimeConfig;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminSupportController extends Controller
{
    public function __construct(private readonly SupportService $support) {}

    /**
     * Support tickets.
     *
     * Waiting ones (`open`) by default, oldest activity first so nobody waits too long. Search the subject.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, counts: array<string, int>}
     */
    public function index(Request $request): array
    {
        $request->validate([
            'status' => ['nullable', Rule::in(SupportTicket::STATUSES)],
            'category' => ['nullable', Rule::in(SupportTicket::CATEGORIES)],
            /** `me` for tickets assigned to you. */
            'assigned' => ['nullable', 'in:me'],
            'userId' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $request->input('status', SupportTicket::OPEN);

        $page = SupportTicket::with(['user', 'assignee'])
            ->where('status', $status)
            ->when($request->input('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->input('assigned') === 'me', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($request->input('userId'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->input('q'), fn ($q, $term) => LikeSearch::apply($q, ['subject'], $term))
            ->orderBy('last_message_at', $status === SupportTicket::OPEN ? 'asc' : 'desc')
            ->paginate($request->integer('perPage', 20));

        return Paged::of($page, fn (SupportTicket $t) => SupportService::presentTicket($t, false, true), [
            'counts' => SupportTicket::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all(),
        ]);
    }

    /**
     * Show a ticket.
     *
     * @return array{data: array<string, mixed>}
     */
    public function show(int $ticket): array
    {
        return ['data' => SupportService::presentTicket(SupportTicket::with(['user', 'assignee', 'messages.user'])->findOrFail($ticket), true, true)];
    }

    /**
     * Answer a ticket.
     *
     * The user is notified; the ticket waits for them (`answered`) and is assigned to you if nobody had it.
     *
     * @return array{data: array<string, mixed>}
     */
    public function reply(Request $request, int $ticket): array
    {
        $request->validate([
            'body' => ['required_without:file', 'nullable', 'string', 'max:5000'],
            'file' => ['nullable', 'file', 'mimes:'.RuntimeConfig::attachmentTypes(), 'max:'.RuntimeConfig::uploadKb('document')],
        ]);
        $ticket = SupportTicket::with('user')->findOrFail($ticket);
        $this->support->reply($ticket, $request->user(), $request->input('body'), $request->file('file'), true);

        return $this->show($ticket->id);
    }

    /**
     * Update a ticket.
     *
     * Change its status (close or reopen) or who handles it.
     *
     * @return array{data: array<string, mixed>}
     */
    public function update(Request $request, int $ticket): array
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'assignedTo' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_admin', true)],
        ]);
        SupportTicket::findOrFail($ticket)->update(collect(['status' => 'status', 'assignedTo' => 'assigned_to'])
            ->filter(fn ($c, $i) => array_key_exists($i, $data))->mapWithKeys(fn ($c, $i) => [$c => $data[$i]])->all());

        return $this->show($ticket);
    }
}
