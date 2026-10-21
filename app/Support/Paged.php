<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The `{data, meta}` shape of every list, for lists presented by hand rather than through a resource.
 */
class Paged
{
    /**
     * A paginated API answer: the items run through the presenter, plus page numbers and the total.
     *
     * @param  callable(mixed): array<string, mixed>  $present
     * @param  array<string, mixed>  $extra
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public static function of(LengthAwarePaginator $page, callable $present, array $extra = []): array
    {
        return [
            'data' => array_values(array_map($present, $page->items())),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            ...$extra,
        ];
    }

    /**
     * A short user summary for admin lists.
     *
     * @return array{id: int, fullName: string, username: ?string, avatarUrl: ?string}|null
     */
    public static function user(mixed $user): ?array
    {
        return $user ? ['id' => $user->id, 'fullName' => $user->full_name, 'username' => $user->username, 'avatarUrl' => $user->avatar_url] : null;
    }
}
