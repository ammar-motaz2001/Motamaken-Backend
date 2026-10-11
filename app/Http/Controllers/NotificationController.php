<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Header & account', 'The logged-in header: balance, badges, notifications, language/currency/theme, own profile.', weight: 4)]
class NotificationController extends Controller
{
    /**
     * List notifications.
     *
     * The bell dropdown: newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
            /** Only this group: `messages`, `proposals`, `projects`, `payments`, `disputes` or `account`. */
            'group' => ['nullable', Rule::in(array_keys(config('notifications.groups')))],
            /** `1` for unread only. */
            'unread' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $query = $user->notifications()
            ->when($request->input('group'), fn ($query, $group) => $this->inGroup($query, $group))
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'));

        return NotificationResource::collection($query->orderByDesc('id')->paginate($request->integer('perPage', 20)))
            ->additional(['unreadByGroup' => collect(array_keys(config('notifications.groups')))->mapWithKeys(
                fn ($group) => [$group => $this->inGroup($user->unreadNotifications(), $group)->count()],
            )]);
    }

    /**
     * Notifications whose type starts with one of the group's prefixes (the type is stored at the start of `data`).
     */
    private function inGroup($query, string $group)
    {
        return $query->where(function ($query) use ($group) {
            foreach (config("notifications.groups.{$group}") as $prefix) {
                $query->orWhere('data', 'like', '%"type":"'.$prefix.'%');
            }
        });
    }

    /**
     * Mark one as read.
     */
    public function read(Request $request, string $notification): NotificationResource
    {
        $notification = $request->user()->notifications()->findOrFail($notification);
        $notification->markAsRead();

        return new NotificationResource($notification);
    }

    /**
     * Mark all as read.
     *
     * @return array{success: bool}
     */
    public function readAll(Request $request): array
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ['success' => true];
    }
}
