<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Case-insensitive "contains" search that works on PostgreSQL and SQLite and treats % and _ literally.
 */
class LikeSearch
{
    /**
     * @param  array<int, string>  $columns  Fully qualified column names, matched with OR.
     */
    public static function apply(Builder $query, array $columns, string $term): Builder
    {
        $pattern = '%'.addcslashes(mb_strtolower(trim($term)), '%_\\').'%';

        return $query->where(function ($query) use ($columns, $pattern) {
            foreach ($columns as $column) {
                $query->orWhereRaw("lower({$column}) like ? escape '\\'", [$pattern]);
            }
        });
    }
}
