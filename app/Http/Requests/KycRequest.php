<?php

namespace App\Http\Requests;

use App\Models\KycSubmission;
use App\Support\RuntimeConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KycRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** `national_id`, `passport` or `driver_license`. */
            'documentType' => ['required', Rule::in(KycSubmission::DOCUMENT_TYPES)],
            'documentNumber' => ['required', 'string', 'min:4', 'max:40'],
            /** As written on the document. */
            'fullName' => ['required', 'string', 'min:3', 'max:120'],
            'dateOfBirth' => ['required', 'date', 'before:-18 years', 'after:1900-01-01'],
            /** The front of the document: a photo or PDF, up to 10 MB. */
            'front' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.RuntimeConfig::uploadKb('document')],
            /** The back of the document, when it has one. */
            'back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.RuntimeConfig::uploadKb('document')],
            /** A selfie holding the document. */
            'selfie' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.RuntimeConfig::uploadKb('document')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['dateOfBirth.before' => __('kyc.adult')];
    }
}
