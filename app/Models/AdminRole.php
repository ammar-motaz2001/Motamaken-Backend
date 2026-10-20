<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name_en', 'name_ar', 'permissions'])]
class AdminRole extends Model
{
    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_system' => 'boolean'];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function allows(string $permission): bool
    {
        $granted = $this->permissions ?? [];

        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }
}
