<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        $request->validate(['perPage' => ['nullable', 'integer', 'min:1', 'max:50']]);

        return NotificationResource::collection(
            $request->user()->notifications()->orderByDesc('id')->paginate($request->integer('perPage', 20)),
        );
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
