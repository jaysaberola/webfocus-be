<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PermissionMatrixController extends Controller
{
    public function index()
    {
        $roles = Role::select('id', 'name')->get();

        $permissions = Permission::all()->map(function ($perm) {
            [$module, $action] = explode('.', $perm->name, 2);

            return [
                'id'     => $perm->id,
                'name'   => $perm->name,
                'module' => strtoupper(str_replace('_', ' ', $module)),
                'label'  => 'User can ' . str_replace('_', ' ', $action) . ' ' . str_replace('_', ' ', $module),
            ];
        });

        $assigned = [];

        foreach ($roles as $role) {
            $assigned[$role->id] = $role->permissions->pluck('id')->toArray();
        }

        return response()->json([
            'roles'       => $roles,
            'permissions' => $permissions,
            'assigned'    => $assigned,
        ]);
    }

    public function sync(Request $request)
    {
        foreach ($request->assigned as $roleId => $permissions) {
            $role = Role::findOrFail($roleId);
            $role->syncPermissions($permissions);
        }

        return response()->json(['status' => 'ok']);
    }
}
