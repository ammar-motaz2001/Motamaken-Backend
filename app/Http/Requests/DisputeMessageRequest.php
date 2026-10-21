<?php

namespace App\Http\Requests;

use App\Support\RuntimeConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DisputeMessageRequest extends FormRequest
{
    /**
     * Validates the text and optional attachment of a dispute message.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:3000'],
            /** Evidence: a screenshot, document or archive, up to 10 MB. Send as `multipart/form-data`. */
            'file' => ['nullable', 'file', 'mimes:'.RuntimeConfig::attachmentTypes(), 'max:'.RuntimeConfig::uploadKb('document')],
        ];
    }

    /**
     * A dispute message needs some text or a file.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (trim((string) $this->input('body')) === '' && ! $this->hasFile('file')) {
                $validator->errors()->add('body', __('chat.body_required'));
            }
        }];
    }
}
