<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Rules\Recaptcha;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Site pages', 'The information pages of the footer and the contact form.', weight: 14)]
class ContactController extends Controller
{
    /**
     * Send a message.
     *
     * The "Contact us" form, for visitors and users. With a token, the name and email default to the account's.
     * The admins are notified.
     *
     * @unauthenticated
     *
     * @return array{success: bool}
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');

        $data = $request->validate([
            'name' => [$user ? 'nullable' : 'required', 'string', 'min:2', 'max:100'],
            'email' => [$user?->email ? 'nullable' : 'required', 'email', 'max:255'],
            /** `general`, `account`, `payments`, `disputes`, `technical`, `partnership` or `other`. */
            'topic' => ['required', Rule::in(ContactMessage::TOPICS)],
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            /** The reCAPTCHA checkbox token; not needed when logged in, or when the server has no reCAPTCHA keys. */
            'recaptchaToken' => $user ? ['nullable'] : Recaptcha::rules(),
        ]);

        $message = ContactMessage::create([
            'user_id' => $user?->id,
            'name' => $data['name'] ?? $user?->full_name,
            'email' => $data['email'] ?? $user?->email,
            'topic' => $data['topic'],
            'subject' => $data['subject'],
            'message' => $data['message'],
        ]);

        foreach (User::where('is_admin', true)->get() as $admin) {
            $admin->notify(new ActivityNotification('admin.contact_message', $user, [
                'contactId' => $message->id, 'subject' => $message->subject, 'from' => $message->name,
            ]));
        }

        return response()->json(['success' => true], 201);
    }
}
