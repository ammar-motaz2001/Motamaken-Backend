<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * For list endpoints that take nothing but a page size.
 */
class PaginatedRequest extends FormRequest
{
    /**
     * Validates the page size.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['perPage' => ['nullable', 'integer', 'min:1', 'max:50']];
    }
}
