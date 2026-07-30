<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

class ManageableRoles
{
    public const EXCLUDED = [
        'staff',
        'user',
        'customer',
    ];

    public static function query(): Builder
    {
        return Role::query()
            ->where('guard_name', 'sanctum')
            ->whereNotIn('name', self::EXCLUDED);
    }
}
