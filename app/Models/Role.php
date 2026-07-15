<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class Role extends SpatieRole
{
    protected $fillable = [
        'name',
        'guard_name',
        'description',
    ];

    protected static function boot()
    {
        // Skip parent::boot() to prevent Spatie's broken deleting event
        static::bootTraits();

        static::saved(function () {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });

        static::deleted(function () {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }
}