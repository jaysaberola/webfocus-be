<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search');

        $roles = Role::when($search, function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            })
            ->when($request->filled('name'), function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->input('name') . '%');
            })
            ->when($request->filled('description'), function ($q) use ($request) {
                $q->where('description', 'like', '%' . $request->input('description') . '%');
            })
            ->when($request->filled('updated_from'), function ($q) use ($request) {
                $q->whereDate('updated_at', '>=', $request->input('updated_from'));
            })
            ->when($request->filled('updated_to'), function ($q) use ($request) {
                $q->whereDate('updated_at', '<=', $request->input('updated_to'));
            })
            ->orderBy('name')
            ->paginate($request->get('per_page', 10));

        return response()->json($roles);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|unique:roles,name',
            'description' => 'nullable|string',
        ]);

        $role = Role::create([
            'name' => $request->name,
            'description' => $request->description,
            'guard_name' => 'sanctum',
        ]);

        return response()->json([
            'message' => 'Role created successfully',
            'role' => $role,
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $request->validate([
            'name' => 'required|unique:roles,name,' . $role->id,
            'description' => 'nullable|string',
        ]);

        $role->update($request->only('name', 'description'));

        return response()->json([
            'message' => 'Role updated successfully',
            'role' => $role,
        ]);
    }

    public function destroy(Role $role)
    {
        if ($role->name === 'admin') {
            return response()->json(['message' => 'Cannot delete administrator role'], 403);
        }

        $roleId = $role->id;

        // Clean up pivot tables first
        DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
        DB::table('model_has_roles')->where('role_id', $roleId)->delete();

        // Bypass Eloquent events entirely — go straight to DB
        DB::table('roles')->where('id', $roleId)->delete();

        // Clear Spatie's permission cache manually
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['message' => 'Role deleted successfully']);
    }
}
