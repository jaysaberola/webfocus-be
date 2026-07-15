<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class CustomerController extends Controller
{
    private const ROLE = 'customer';

    private function ensureCustomerRole(): void
    {
        Role::firstOrCreate(
            ['name' => self::ROLE, 'guard_name' => 'sanctum'],
            ['description' => 'Customer']
        );
    }

    public function index(Request $request)
    {
        $this->ensureCustomerRole();

        $perPage = $request->integer('per_page', 10);

        $customers = User::with('roles')
            ->role(self::ROLE)
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
            ->when($request->filled('date_registered_from'), function ($q) use ($request) {
                $q->whereDate('created_at', '>=', $request->input('date_registered_from'));
            })
            ->when($request->filled('date_registered_to'), function ($q) use ($request) {
                $q->whereDate('created_at', '<=', $request->input('date_registered_to'));
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $isActive = strtolower((string) $request->status) === 'active';
                $q->where('is_active', $isActive);
            })
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => $customers->through(function ($customer) {
                return [
                    'id' => $customer->id,
                    'name' => $customer->full_name,
                    'email' => $customer->email,
                    'type' => 'Customer',
                    'role' => $customer->getRoleNames()->first(),
                    'created_at' => $customer->created_at,
                    'date_registered' => optional($customer->created_at)->format('F d, Y'),
                    'status' => $customer->is_active ? 'Active' : 'Inactive',
                ];
            }),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureCustomerRole();

        $validated = $request->validate([
            'fname' => ['required', 'string', 'max:255'],
            'lname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
        ]);

        $customer = User::create([
            'fname' => $validated['fname'],
            'lname' => $validated['lname'],
            'email' => $validated['email'],
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $customer->assignRole(self::ROLE);

        return response()->json([
            'message' => 'Customer created successfully',
            'data' => [
                'id' => $customer->id,
                'name' => $customer->full_name,
                'email' => $customer->email,
                'role' => self::ROLE,
            ],
        ], 201);
    }

    public function show(User $customer)
    {
        abort_unless($customer->hasRole(self::ROLE), 404);

        $activityLogs = DB::table('audits')
            ->where('user_id', $customer->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($audit) => [
                'id' => $audit->id,
                'event' => $audit->event,
                'auditable_type' => class_basename($audit->auditable_type),
                'auditable_id' => $audit->auditable_id,
                'old_values' => json_decode($audit->old_values, true),
                'new_values' => json_decode($audit->new_values, true),
                'ip_address' => $audit->ip_address,
                'user_agent' => $audit->user_agent,
                'created_at' => $audit->created_at,
            ]);

        return response()->json([
            'data' => [
                'id' => $customer->id,
                'fname' => $customer->fname,
                'lname' => $customer->lname,
                'email' => $customer->email,
                'type' => 'Customer',
                'role' => self::ROLE,
                'created_at' => $customer->created_at,
                'date_registered' => optional($customer->created_at)->format('F d, Y'),
                'is_active' => $customer->is_active,
                'audits' => $activityLogs,
            ],
        ]);
    }

    public function update(Request $request, User $customer)
    {
        abort_unless($customer->hasRole(self::ROLE), 404);

        $validated = $request->validate([
            'fname' => ['required', 'string'],
            'lname' => ['required', 'string'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($customer->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $customer->update($validated);
        $customer->syncRoles([self::ROLE]);

        return response()->json([
            'message' => 'Customer updated successfully',
        ]);
    }
}
