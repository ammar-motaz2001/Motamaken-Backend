<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Report;
use App\Support\Sql;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminMessageController extends Controller
{
    /**
     * Chat statistics.
     *
     * How the chat is used, without reading anyone's messages: conversations (one-to-one and groups), messages per day
     * and per kind, files sent and their size, the busiest hours, and the messages reported to the moderators.
     *
     * @return array{data: array<string, mixed>}
     */
    public function stats(Request $request): array
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']]);
        $days = $request->integer('days', 30);
        $since = now()->subDays($days - 1)->startOfDay();
        $perDay = Message::where('created_at', '>=', $since)->selectRaw('date(created_at) as day, count(*) as total')->groupBy('day')->pluck('total', 'day');

        return ['data' => [
            'days' => $days,
            'conversations' => [
                'total' => Conversation::count(),
                'direct' => Conversation::where('type', 'direct')->count(),
                'group' => Conversation::where('type', 'group')->count(),
                /** With at least one message in the period. */
                'active' => Conversation::where('last_message_at', '>=', $since)->count(),
            ],
            'messages' => [
                'total' => Message::count(),
                'period' => (int) $perDay->sum(),
                /** `text`, `voice`, `attachment`. */
                'byType' => Message::where('created_at', '>=', $since)->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type')->map(fn ($n) => (int) $n)->all(),
                'removed' => Message::whereNotNull('removed_at')->count(),
            ],
            'files' => [
                'count' => Message::whereNotNull('attachment_path')->where('created_at', '>=', $since)->count(),
                'bytes' => (int) Message::whereNotNull('attachment_path')->where('created_at', '>=', $since)->sum('attachment_size'),
                /** By kind of file (image, pdf, audio...). */
                'byMime' => Message::whereNotNull('attachment_mime')->where('created_at', '>=', $since)
                    ->selectRaw(Sql::mimeKind('attachment_mime').' as kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind')->map(fn ($n) => (int) $n)->all(),
            ],
            'series' => collect(range(0, $days - 1))->map(fn ($i) => ['date' => $d = $since->copy()->addDays($i)->toDateString(), 'messages' => (int) ($perDay[$d] ?? 0)])->all(),
            /** Messages per hour of the day (server time), for staffing support. */
            'byHour' => collect(range(0, 23))->mapWithKeys(fn ($h) => [$h => 0])->replace(
                Message::where('created_at', '>=', $since)->selectRaw(Sql::hour('created_at').' as hour, count(*) as total')->groupBy('hour')->pluck('total', 'hour')->map(fn ($n) => (int) $n)->all(),
            )->values()->all(),
            'reports' => [
                'open' => Report::where('target_type', 'message')->where('status', Report::OPEN)->count(),
                'total' => Report::where('target_type', 'message')->count(),
            ],
            /** Senders with the most messages in the period: a spam signal. Counts only. */
            'topSenders' => Message::where('messages.created_at', '>=', $since)->join('users', 'users.id', '=', 'messages.sender_id')
                ->selectRaw('users.id, users.first_name, users.last_name, users.username, count(*) as total')
                ->groupBy('users.id', 'users.first_name', 'users.last_name', 'users.username')->orderByDesc('total')->limit(10)->get()
                ->map(fn ($row) => ['id' => $row->id, 'fullName' => trim("{$row->first_name} {$row->last_name}") ?: $row->username, 'messages' => (int) $row->total])->all(),
        ]];
    }
}
