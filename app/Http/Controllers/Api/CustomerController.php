<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

        $customers = User::with(['roles', 'owner:id,fname,lname,email'])
            ->withCount([
                'customerServices as active_services_count' => fn ($q) => $q->where('status', 'Active'),
            ])
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

        $paginator = $customers->through(function ($customer) {
                $owner = $customer->owner;
                $ownerName = $owner
                    ? (trim(($owner->fname ?? '') . ' ' . ($owner->lname ?? '')) ?: ($owner->email ?? null))
                    : null;

                return [
                    'id' => $customer->id,
                    'name' => $customer->mname ?: $customer->full_name,
                    'representative' => $customer->full_name,
                    'company' => $customer->mname,
                    'email' => $customer->email,
                    'type' => 'Customer',
                    'role' => $customer->getRoleNames()->first(),
                    'created_at' => $customer->created_at,
                    'date_registered' => optional($customer->created_at)->format('F d, Y'),
                    'status' => $customer->is_active ? 'Active' : 'Inactive',
                    'active_services_count' => (int) ($customer->active_services_count ?? 0),
                    'owner_id' => $customer->owner_id,
                    'owner' => $owner ? [
                        'id' => $owner->id,
                        'name' => $ownerName,
                        'email' => $owner->email,
                    ] : null,
                    'owner_name' => $ownerName,
                ];
            });

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureCustomerRole();

        $validated = $request->validate([
            'fname' => ['required', 'string', 'max:255'],
            'lname' => ['required', 'string', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'address_street' => ['required', 'string', 'max:500'],
            'mobile' => ['required', 'string', 'regex:/^\d{9}$/'],
            'phone' => ['nullable', 'string', 'max:50'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => ['required', 'string', 'max:255'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['string', 'max:255'],
        ]);

        $avatarPath = null;
        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
        }

        $customer = User::create([
            'fname' => $validated['fname'],
            'lname' => $validated['lname'],
            'mname' => $validated['company'],
            'email' => $validated['email'],
            'mobile' => '+63' . $validated['mobile'],
            'phone' => $validated['phone'] ?? null,
            'address_street' => $validated['address_street'],
            'avatar' => $avatarPath,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $customer->assignRole(self::ROLE);

        $renewAt = now()->addYear();

        foreach ($validated['services'] as $serviceTitle) {
            CustomerService::create([
                'customer_id' => $customer->id,
                'title' => $serviceTitle,
                'category' => $this->resolveServiceCategory($serviceTitle),
                'plan' => $serviceTitle,
                'status' => 'Active',
                'renew_label' => 'Renews',
                'renew_at' => $renewAt,
                'renew_note' => $renewAt->format('M j, Y') . ' · 365 days left',
            ]);
        }

        foreach ($validated['addons'] ?? [] as $addonTitle) {
            if (!trim($addonTitle)) {
                continue;
            }

            CustomerService::create([
                'customer_id' => $customer->id,
                'title' => $addonTitle,
                'category' => 'Add-on',
                'plan' => $addonTitle,
                'status' => 'Active',
                'renew_label' => 'Renews',
                'renew_at' => $renewAt,
                'renew_note' => $renewAt->format('M j, Y') . ' · 365 days left',
            ]);
        }

        return response()->json([
            'message' => 'Customer created successfully',
            'data' => [
                'id' => $customer->id,
                'name' => $customer->mname ?: $customer->full_name,
                'email' => $customer->email,
                'role' => self::ROLE,
            ],
        ], 201);
    }

    private function resolveServiceCategory(string $name): string
    {
        $haystack = strtolower(trim($name));

        if (str_contains($haystack, 'domain')) {
            return 'Domains';
        }
        if (str_contains($haystack, 'dedicated') || str_contains($haystack, 'baremetal') || str_contains($haystack, 'bare-metal')) {
            return 'Dedicated Server';
        }
        if (str_contains($haystack, 'hosting') || str_contains($haystack, 'cloud') || str_contains($haystack, 'canvas')) {
            return 'Shared Hosting';
        }
        if (str_contains($haystack, 'dms') || str_contains($haystack, 'document')) {
            return 'Document Management System';
        }
        if (str_contains($haystack, 'design') || str_contains($haystack, 'web focus') || str_contains($haystack, 'canvas')) {
            return 'Custom Web Design';
        }

        if (preg_match('/business starter|professional corporate|e-?commerce plus|starter launch|website template/i', $haystack)) {
            return 'Custom Web Design';
        }

        return 'Hosting';
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
                'company' => $customer->mname,
                'email' => $customer->email,
                'mobile' => $customer->mobile,
                'phone' => $customer->phone,
                'address_street' => $customer->address_street,
                'avatar' => $customer->avatar,
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

        if ($request->isMethod('POST') && in_array(strtoupper((string) $request->input('_method')), ['PUT', 'PATCH'], true)) {
            $request->setMethod('PUT');
        }

        $validated = $request->validate([
            'fname' => ['required', 'string', 'max:255'],
            'lname' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($customer->id)],
            'address_street' => ['nullable', 'string', 'max:500'],
            'mobile' => ['nullable', 'string', 'regex:/^\d{9}$/'],
            'phone' => ['nullable', 'string', 'max:50'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'services' => ['nullable', 'array'],
            'services.*' => ['string', 'max:255'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['string', 'max:255'],
        ]);

        $avatarPath = $customer->avatar;
        if ($request->hasFile('avatar')) {
            if ($customer->avatar && Storage::disk('public')->exists($customer->avatar)) {
                Storage::disk('public')->delete($customer->avatar);
            }
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
        }

        $customer->update([
            'fname' => $validated['fname'],
            'lname' => $validated['lname'],
            'mname' => $validated['company'] ?? $customer->mname,
            'email' => $validated['email'],
            'mobile' => isset($validated['mobile']) ? '+63' . $validated['mobile'] : $customer->mobile,
            'phone' => $validated['phone'] ?? $customer->phone,
            'address_street' => $validated['address_street'] ?? $customer->address_street,
            'avatar' => $avatarPath,
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : $customer->is_active,
        ]);

        $customer->syncRoles([self::ROLE]);

        if ($request->has('services') || $request->has('addons')) {
            $this->syncCustomerServices(
                $customer,
                $validated['services'] ?? [],
                $validated['addons'] ?? []
            );
        }

        return response()->json([
            'message' => 'Customer updated successfully',
            'data' => [
                'id' => $customer->id,
                'name' => $customer->mname ?: $customer->full_name,
                'email' => $customer->email,
            ],
        ]);
    }

    public function destroy(User $customer)
    {
        abort_unless($customer->hasRole(self::ROLE), 404);
        $customer->delete();

        return response()->json([
            'message' => 'Customer deleted successfully',
        ]);
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:users,id'],
        ]);

        $customers = User::query()
            ->role(self::ROLE)
            ->whereIn('id', $validated['ids'])
            ->get();

        foreach ($customers as $customer) {
            $customer->delete();
        }

        return response()->json([
            'message' => $customers->count() . ' customer(s) deleted successfully',
            'deleted' => $customers->pluck('id')->values(),
        ]);
    }

    private function syncCustomerServices(User $customer, array $services, array $addons): void
    {
        $desired = collect($services)
            ->merge($addons)
            ->map(fn ($title) => trim((string) $title))
            ->filter()
            ->unique()
            ->values();

        $addonTitles = collect($addons)
            ->map(fn ($title) => trim((string) $title))
            ->filter()
            ->unique();

        $existing = CustomerService::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'Active')
            ->get();

        $renewAt = now()->addYear();

        foreach ($desired as $title) {
            if ($existing->contains(fn (CustomerService $service) => $service->title === $title)) {
                continue;
            }

            $isAddon = $addonTitles->contains($title);

            CustomerService::create([
                'customer_id' => $customer->id,
                'title' => $title,
                'category' => $isAddon ? 'Add-on' : $this->resolveServiceCategory($title),
                'plan' => $title,
                'status' => 'Active',
                'renew_label' => 'Renews',
                'renew_at' => $renewAt,
                'renew_note' => $renewAt->format('M j, Y') . ' · 365 days left',
            ]);
        }

        foreach ($existing as $service) {
            if ($desired->contains($service->title)) {
                continue;
            }

            if ($service->sales_transaction_id) {
                continue;
            }

            $service->update(['status' => 'Expired']);
        }
    }
}
