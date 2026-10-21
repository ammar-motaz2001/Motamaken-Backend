<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $name_en
 * @property string $name_ar
 * @property array<array-key, mixed> $permissions
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminRole newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminRole newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminRole query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminRole whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminRole whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminRole whereIsSystem($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminRole whereNameAr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminRole whereNameEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminRole wherePermissions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminRole whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminRole whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['slug', 'name_en', 'name_ar', 'permissions'])]
class AdminRole extends Model
{
    /**
     * Permissions come back as a list of keys; is_system as a boolean.
     */
    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_system' => 'boolean'];
    }

    /**
     * The admins who have this role.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Whether the role grants the permission, either by name or through the `*` wildcard.
     */
    public function allows(string $permission): bool
    {
        $granted = $this->permissions ?? [];

        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }
}
