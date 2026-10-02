<?php

namespace App\Http\Requests;

use App\Models\Message;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SendMessageRequest extends FormRequest
{
    /** Files the paperclip button accepts. Anything that can run in a browser (html, svg, js) is left out on purpose. */
    private const ATTACHMENT_EXTENSIONS = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar';

    private const VOICE_MIME_TYPES = 'audio/webm,video/webm,audio/ogg,audio/mpeg,audio/mp4,audio/x-m4a,audio/aac,audio/wav,audio/x-wav';

    public function messageType(): string
    {
        return $this->input('type') ?: ($this->hasFile('file') ? Message::TYPE_ATTACHMENT : Message::TYPE_TEXT);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limit = config('marketplace.chat.max_file_kb');
        $file = $this->messageType() === Message::TYPE_VOICE
            ? ['mimetypes:'.self::VOICE_MIME_TYPES]
            : ['mimes:'.self::ATTACHMENT_EXTENSIONS];

        return [
            /** `text` (default), `voice` (recorded with the mic button) or `attachment` (paperclip). Inferred from `file` when omitted. */
            'type' => ['nullable', Rule::in([Message::TYPE_TEXT, Message::TYPE_VOICE, Message::TYPE_ATTACHMENT])],
            /** The text. Required for `text`; an optional caption for `voice` and `attachment`. */
            'body' => ['nullable', 'string', 'max:5000'],
            /** The voice recording or attachment (up to 20 MB). Send the request as `multipart/form-data`. */
            'file' => ['nullable', 'file', "max:{$limit}", ...$file],
            /** Length of the voice message in seconds. Required for `voice`. */
            'duration' => ['nullable', 'integer', 'min:1', 'max:3600'],
        ];
    }

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
