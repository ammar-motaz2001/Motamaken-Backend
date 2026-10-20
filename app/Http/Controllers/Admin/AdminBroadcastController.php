<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcast;
use App\Models\Broadcast;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminBroadcastController extends Controller
{
    /**
     * Announcements.
     *
     * What the staff sent, to whom and to how many people. Newest first.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function index(Request $request): array
    {
        $page = Broadcast::with(['creator', 'targetUser'])->latest('id')->paginate($request->integer('perPage', 20));

        return Paged::of($page, fn (Broadcast $b) => self::present($b));
    }

    /**
     * Send an announcement.
     *
     * To `all` users, one account type (`clients`, `freelancers`, `influencers`) or one `user` (with `userId`), in both
     * languages. Always in the bell; also by email with `channels: ["in_app", "email"]`. Suspended accounts are skipped.
     * Delivered in the background; `recipients` fills in when done. `preview: true` only counts the audience.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'titleEn' => ['required', 'string', 'max:150'],
            'titleAr' => ['required', 'string', 'max:150'],
            'bodyEn' => ['required', 'string', 'max:2000'],
            'bodyAr' => ['required', 'string', 'max:2000'],
            'audience' => ['required', Rule::in(Broadcast::AUDIENCES)],
            'userId' => ['nullable', 'required_if:audience,user', 'integer', 'exists:users,id'],
            'channels' => ['nullable', 'array'],
            'channels.*' => [Rule::in(['in_app', 'email'])],
            'preview' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('preview')) {
            return response()->json(['data' => ['recipients' => SendBroadcast::audience($data['audience'], $data['userId'] ?? null)->count()]]);
        }

        $broadcast = Broadcast::create([
            'title_en' => $data['titleEn'], 'title_ar' => $data['titleAr'], 'body_en' => $data['bodyEn'], 'body_ar' => $data['bodyAr'],
            'audience' => $data['audience'], 'target_user_id' => $data['audience'] === 'user' ? $data['userId'] : null,
            'channels' => array_values(array_unique(['in_app', ...($data['channels'] ?? [])])),
            'created_by' => $request->user()->id,
        ]);
        SendBroadcast::dispatch($broadcast);

        return response()->json(['data' => self::present($broadcast->refresh()->load(['creator', 'targetUser']))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Broadcast $b): array
    {
        return [
            'id' => $b->id, 'titleEn' => $b->title_en, 'titleAr' => $b->title_ar, 'bodyEn' => $b->body_en, 'bodyAr' => $b->body_ar,
            'audience' => $b->audience, 'user' => Paged::user($b->targetUser), 'channels' => $b->channels, 'recipients' => $b->recipients,
            'createdBy' => Paged::user($b->creator), 'createdAt' => $b->created_at?->toIso8601String(),
        ];
    }
}
