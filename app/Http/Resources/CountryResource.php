<?php

namespace App\Http\Resources;

use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Country
 */
class CountryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** ISO 3166-1 alpha-2 code, e.g. `EG`. */
            'code' => $this->code,
            /** In the language of the `Accept-Language` header. */
            'name' => $this->name,
        ];
    }
}
