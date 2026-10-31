<?php

namespace App\Http\Requests;

use App\Models\Message;
use App\Rules\CleanText;
use App\Support\RuntimeConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SendMessageRequest extends FormRequest
{
    // Browsers record AAC voice notes in an MP4 box, which file sniffing reports as video/mp4.
    private const VOICE_MIME_TYPES = 'audio/webm,video/webm,video/mp4,audio/ogg,audio/mpeg,audio/mp4,audio/x-m4a,audio/aac,audio/wav,audio/x-wav';

    /**
     * The message type sent, or guessed: an attachment when a file came, text otherwise.
     */
    public function messageType(): string
    {
        return $this->input('type') ?: ($this->hasFile('file') ? Message::TYPE_ATTACHMENT : Message::TYPE_TEXT);
    }

    /**
     * Validates the message type, text, file (audio types for voice notes) and voice duration.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limit = config('marketplace.chat.max_file_kb');
        $file = $this->messageType() === Message::TYPE_VOICE
            ? ['mimetypes:'.self::VOICE_MIME_TYPES]
            // Files the paperclip accepts, chosen by the admins from a list without anything that runs in a browser.
            : ['mimes:'.RuntimeConfig::attachmentTypes()];

        return [
            /** `text` (default), `voice` (recorded with the mic button) or `attachment` (paperclip). Inferred from `file` when omitted. */
            'type' => ['nullable', Rule::in([Message::TYPE_TEXT, Message::TYPE_VOICE, Message::TYPE_ATTACHMENT])],
            /** The text. Required for `text`; an optional caption for `voice` and `attachment`. */
            'body' => ['nullable', 'string', 'max:5000', new CleanText],
            /** The voice recording or attachment (up to 20 MB). Send the request as `multipart/form-data`. */
            'file' => ['nullable', 'file', "max:{$limit}", ...$file],
            /** Length of the voice message in seconds. Required for `voice`. */
            'duration' => ['nullable', 'integer', 'min:1', 'max:3600'],
        ];
    }

    /**
     * Text messages need a body and no file; other types need a file, and voice notes their duration.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $type = $this->messageType();
            $hasFile = $this->hasFile('file');
            $hasBody = trim((string) $this->input('body')) !== '';

            match (true) {
                $type === Message::TYPE_TEXT && $hasFile => $validator->errors()->add('type', __('chat.text_has_no_file')),
                $type === Message::TYPE_TEXT && ! $hasBody => $validator->errors()->add('body', __('chat.body_required')),
                $type !== Message::TYPE_TEXT && ! $hasFile => $validator->errors()->add('file', __('chat.file_required')),
                $type === Message::TYPE_VOICE && ! $this->filled('duration') => $validator->errors()->add('duration', __('chat.duration_required')),
                default => null,
            };
        }];
    }
}
