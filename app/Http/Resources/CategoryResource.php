<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** `project` for project categories, `influencer` for platforms such as Instagram. */
            'type' => $this->type,
            /** Stable key for filtering, e.g. `website-it-software` or `instagram`. */
            'slug' => $this->slug,
            /** In the language of the `Accept-Language` header. */
            'name' => $this->name,
            'icon' => $this->icon,
            /** The top-level category this one belongs to, for subcategories. */
            'parentId' => $this->parent_id,
        ];
    }
}
