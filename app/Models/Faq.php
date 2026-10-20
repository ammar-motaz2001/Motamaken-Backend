<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['faq_category_id', 'question_en', 'question_ar', 'answer_en', 'answer_ar', 'audience', 'is_published', 'sort_order'])]
class Faq extends Model
{
    public const AUDIENCES = ['all', 'client', 'freelancer', 'influencer'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    /**
     * @return BelongsTo<FaqCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, 'faq_category_id');
    }
}
