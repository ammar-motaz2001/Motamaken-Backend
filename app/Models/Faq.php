<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $faq_category_id
 * @property string $question_en
 * @property string $question_ar
 * @property string $answer_en
 * @property string $answer_ar
 * @property string $audience
 * @property bool $is_published
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read FaqCategory $category
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq whereAnswerAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq whereAnswerEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq whereAudience($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq whereFaqCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq whereIsPublished($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq whereQuestionAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq whereQuestionEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Faq whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['faq_category_id', 'question_en', 'question_ar', 'answer_en', 'answer_ar', 'audience', 'is_published', 'sort_order'])]
class Faq extends Model
{
    public const AUDIENCES = ['all', 'client', 'freelancer', 'influencer'];

    /**
     * is_published becomes a boolean.
     */
    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    /**
     * The FAQ section the question is listed under.
     *
     * @return BelongsTo<FaqCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, 'faq_category_id');
    }
}
