<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'fname' => ['required', 'string', 'max:255'],
            'lname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role'  => ['required', Rule::exists('roles', 'name')],
        ]);

        $user = User::create([
            'fname'    => $validated['fname'],
            'lname'    => $validated['lname'],
            'email'    => $validated['email'],
            'password' => Hash::make('password'), // or generate random
            'is_active'=> true,
        ]);

        // Assign role (Spatie)
        $user->assignRole($validated['role']);

        return response()->json([
            'message' => 'User created successfully',
            'data' => [
                'id'    => $user->id,
                'name'  => $user->full_name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
            ],
        ], 201);
    }

    public function fetch_roles(){
        return response()->json([
            'data' => Role::where('guard_name', 'sanctum')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function index(Request $request)
    {
        $perPage = $request->integer('per_page', 10);

        $users = User::with('roles')
            ->whereDoesntHave('roles', function ($q) {
                $q->where('name', 'customer');
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($qq) use ($request) {
                    $qq->where('fname', 'like', "%{$request->search}%")
                       ->orWhere('lname', 'like', "%{$request->search}%")
                       ->orWhere('email', 'like', "%{$request->search}%");
                });
            })
            ->when($request->filled('name'), function ($q) use ($request) {
                $term = $request->input('name');
                $q->where(function ($qq) use ($term) {
                    $qq->where('fname', 'like', "%{$term}%")
                       ->orWhere('mname', 'like', "%{$term}%")
                       ->orWhere('lname', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('email'), function ($q) use ($request) {
                $q->where('email', 'like', '%' . $request->input('email') . '%');
            })
            ->when($request->filled('role'), function ($q) use ($request) {
                $role = $request->input('role');
                $q->whereHas('roles', function ($roleQuery) use ($role) {
                    $roleQuery->where('name', 'like', '%' . $role . '%');
                });
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $status = strtolower($request->input('status'));
                if ($status === 'active') {
                    $q->where('is_active', true);
                } elseif ($status === 'inactive') {
                    $q->where('is_active', false);
                }
            })
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => $users->through(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'role' => $user->getRoleNames()->first(),
                    'status' => $user->is_active ? 'Active' : 'Inactive',
                ];
            }),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function show(User $user)
    {
        $activityLogs = DB::table('audits')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($audit) => [
                'id'             => $audit->id,
                'event'          => $audit->event,
                'auditable_type' => class_basename($audit->auditable_type),
                'auditable_id'   => $audit->auditable_id,
                'old_values'     => json_decode($audit->old_values, true),
                'new_values'     => json_decode($audit->new_values, true),
                'ip_address'     => $audit->ip_address,
                'user_agent'     => $audit->user_agent,
                'created_at'     => $audit->created_at,
            ]);

        return response()->json([
            'data' => [
                'id'        => $user->id,
                'fname'     => $user->fname,
                'lname'     => $user->lname,
                'email'     => $user->email,
                'role'      => $user->getRoleNames()->first(),
                'is_active' => $user->is_active,
                'audits'    => $activityLogs,
            ],
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'fname' => ['required', 'string'],
            'lname' => ['required', 'string'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'role'  => ['required', Rule::exists('roles', 'name')],
            'is_active' => ['boolean'],
        ]);

        $user->update($validated);
        $user->syncRoles([$validated['role']]);

        return response()->json([
            'message' => 'User updated successfully',
        ]);
    }
}
