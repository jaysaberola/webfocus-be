<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerService;
use App\Models\User;
use App\Support\ServiceCatalogLabelResolver;
use App\Support\StorageUrl;
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

        $customers = User::with([
                'roles',
                'owner:id,fname,lname,email',
                'customerServices' => fn ($q) => $q->where('status', 'Active')->orderBy('id'),
            ])
            ->withCount([
                'customerServices as active_services_count' => fn ($q) => $q->where('status', 'Active'),
                'salesTransactions as orders_count',
            ])
            ->role(self::ROLE)
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($qq) use ($request) {
                    $qq->where('fname', 'like', "%{$request->search}%")
                       ->orWhere('lname', 'like', "%{$request->search}%")
                       ->orWhere('email', 'like', "%{$request->search}%")
                       ->orWhere('mname', 'like', "%{$request->search}%")
                       ->orWhere('website', 'like', "%{$request->search}%");
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
                $serviceSummary = $this->summarizeCustomerServices($customer);

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
                    'orders_count' => (int) ($customer->orders_count ?? 0),
                    'owner_id' => $customer->owner_id,
                    'owner' => $owner ? [
                        'id' => $owner->id,
                        'name' => $ownerName,
                        'email' => $owner->email,
                    ] : null,
                    'owner_name' => $ownerName,
                    'client_classification' => $customer->client_classification,
                    'client_type' => $customer->client_type,
                    'billing_in_charge' => $customer->billing_in_charge,
                    'contact_person' => $customer->contact_person,
                    'website' => $customer->website,
                    'service_name' => $serviceSummary['service_name'],
                    'plan_name' => $serviceSummary['plan_name'],
                    'subject' => $serviceSummary['subject'],
                    'product_category' => $serviceSummary['product_category'],
                    'domain' => $serviceSummary['domain'],
                    'subject_domain' => $serviceSummary['subject_domain'],
                    'services' => $serviceSummary['services'],
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
        $this->normalizeClientRequest($request);

        $validated = $request->validate(array_merge(
            $this->clientProfileRules(true),
            [
                'services' => ['nullable', 'array'],
                'services.*' => ['required', 'string', 'max:255'],
                'addons' => ['nullable', 'array'],
                'addons.*' => ['string', 'max:255'],
                'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            ]
        ));

        [$fname, $lname] = $this->resolveContactNames($validated);

        $avatarPath = null;
        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
        }

        $filePaths = $this->storeClientDocuments($request);

        $customer = User::create(array_merge(
            [
                'fname' => $fname,
                'lname' => $lname,
                'mname' => $validated['company'],
                'email' => $validated['email'],
                'mobile' => isset($validated['mobile']) && $validated['mobile'] !== ''
                    ? '+63' . $validated['mobile']
                    : null,
                'phone' => $validated['phone'] ?? null,
                'address_street' => $validated['address_street'] ?? null,
                'avatar' => $avatarPath,
                'password' => Hash::make('password'),
                'is_active' => true,
                'owner_id' => $validated['owner_id'] ?? null,
            ],
            $this->clientProfileAttributes($validated),
            $filePaths
        ));

        $customer->assignRole(self::ROLE);

        $renewAt = now()->addYear();

        foreach ($validated['services'] ?? [] as $serviceTitle) {
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

    /**
     * @return array{
     *     service_name: ?string,
     *     plan_name: ?string,
     *     subject: ?string,
     *     product_category: ?string,
     *     domain: ?string,
     *     subject_domain: ?string,
     *     services: list<array<string, mixed>>
     * }
     */
    private function summarizeCustomerServices(User $customer): array
    {
        $website = trim((string) ($customer->website ?? ''));
        $lines = collect($customer->customerServices ?? [])
            ->map(function (CustomerService $service) use ($website) {
                $labels = ServiceCatalogLabelResolver::describe(
                    $service->title,
                    $service->category,
                    $service->plan,
                    $website,
                );

                return [
                    'id' => $service->id,
                    'title' => $service->title,
                    'category' => $service->category,
                    'plan' => $service->plan,
                    'status' => $service->status,
                    'renew_at' => optional($service->renew_at)->toIso8601String(),
                    'service_name' => $labels['service_name'],
                    'plan_name' => $labels['plan_name'],
                    'subject' => $labels['subject'],
                    'product_category' => $labels['product_category'],
                    'domain' => $labels['domain'],
                ];
            })
            ->values();

        $unique = fn (string $key) => $lines
            ->pluck($key)
            ->filter(fn ($value) => filled($value))
            ->unique()
            ->values();

        $serviceNames = $unique('service_name');
        $planNames = $unique('plan_name');
        $subjects = $unique('subject');
        $productCategories = $unique('product_category');
        $domains = $unique('domain');

        $subjectDomain = collect([$subjects->implode(', '), $domains->implode(', ')])
            ->filter()
            ->implode(' ');

        return [
            'service_name' => $serviceNames->isEmpty() ? null : $serviceNames->implode(', '),
            'plan_name' => $planNames->isEmpty() ? null : $planNames->implode(', '),
            'subject' => $subjects->isEmpty() ? null : $subjects->implode(', '),
            'product_category' => $productCategories->isEmpty() ? null : $productCategories->implode(', '),
            'domain' => $domains->isEmpty() ? null : $domains->implode(', '),
            'subject_domain' => $subjectDomain !== '' ? $subjectDomain : null,
            'services' => $lines->all(),
        ];
    }

    public function show(User $customer)
    {
        abort_unless($customer->hasRole(self::ROLE), 404);

        $activityLogs = DB::table('audits')
            ->leftJoin('users as actors', 'audits.user_id', '=', 'actors.id')
            ->where(function ($query) use ($customer) {
                $query->where(function ($auditable) use ($customer) {
                    $auditable
                        ->where('audits.auditable_id', $customer->id)
                        ->where(function ($type) {
                            $type->where('audits.auditable_type', User::class)
                                ->orWhere('audits.auditable_type', 'App\\Models\\User')
                                ->orWhere('audits.auditable_type', 'like', '%User');
                        });
                })->orWhere('audits.user_id', $customer->id);
            })
            ->orderByDesc('audits.created_at')
            ->select([
                'audits.id',
                'audits.event',
                'audits.auditable_type',
                'audits.auditable_id',
                'audits.old_values',
                'audits.new_values',
                'audits.ip_address',
                'audits.user_agent',
                'audits.user_id',
                'audits.created_at',
                'actors.fname as actor_fname',
                'actors.lname as actor_lname',
                'actors.email as actor_email',
            ])
            ->get()
            ->map(function ($audit) {
                $actorName = trim(($audit->actor_fname ?? '') . ' ' . ($audit->actor_lname ?? ''));
                if ($actorName === '') {
                    $actorName = $audit->actor_email;
                }

                return [
                    'id' => $audit->id,
                    'event' => $audit->event,
                    'auditable_type' => class_basename((string) $audit->auditable_type),
                    'auditable_id' => $audit->auditable_id,
                    'old_values' => json_decode($audit->old_values, true) ?: [],
                    'new_values' => json_decode($audit->new_values, true) ?: [],
                    'ip_address' => $audit->ip_address,
                    'user_agent' => $audit->user_agent,
                    'user_id' => $audit->user_id,
                    'actor_name' => $actorName ?: null,
                    'created_at' => $audit->created_at,
                ];
            })
            ->values();

        return response()->json([
            'data' => array_merge([
                'id' => $customer->id,
                'fname' => $customer->fname,
                'lname' => $customer->lname,
                'company' => $customer->mname,
                'email' => $customer->email,
                'mobile' => $customer->mobile,
                'phone' => $customer->phone,
                'address_street' => $customer->address_street,
                'address_city' => $customer->address_city,
                'address_province' => $customer->address_province,
                'address_region' => $customer->address_region,
                'address_zip' => $customer->address_zip,
                'address_country' => $customer->address_country,
                'avatar' => $customer->avatar,
                'type' => 'Customer',
                'role' => self::ROLE,
                'created_at' => $customer->created_at,
                'date_registered' => optional($customer->created_at)->format('F d, Y'),
                'is_active' => $customer->is_active,
                'owner_id' => $customer->owner_id,
                'audits' => $activityLogs,
            ], $this->clientProfilePayload($customer)),
        ]);
    }

    public function update(Request $request, User $customer)
    {
        abort_unless($customer->hasRole(self::ROLE), 404);

        if ($request->isMethod('POST') && in_array(strtoupper((string) $request->input('_method')), ['PUT', 'PATCH'], true)) {
            $request->setMethod('PUT');
        }

        $this->normalizeClientRequest($request);

        $validated = $request->validate(array_merge(
            $this->clientProfileRules(false, $customer->id),
            [
                'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
                'is_active' => ['sometimes', 'boolean'],
                'services' => ['nullable', 'array'],
                'services.*' => ['string', 'max:255'],
                'addons' => ['nullable', 'array'],
                'addons.*' => ['string', 'max:255'],
            ]
        ));

        [$fname, $lname] = $this->resolveContactNames($validated, $customer);

        $avatarPath = $customer->avatar;
        if ($request->hasFile('avatar')) {
            if ($customer->avatar && Storage::disk('public')->exists($customer->avatar)) {
                Storage::disk('public')->delete($customer->avatar);
            }
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
        }

        $filePaths = $this->storeClientDocuments($request, $customer);

        $customer->update(array_merge(
            [
                'fname' => $fname,
                'lname' => $lname,
                'mname' => $validated['company'] ?? $customer->mname,
                'email' => $validated['email'],
                'mobile' => array_key_exists('mobile', $validated) && $validated['mobile'] !== null && $validated['mobile'] !== ''
                    ? '+63' . $validated['mobile']
                    : $customer->mobile,
                'phone' => $validated['phone'] ?? $customer->phone,
                'address_street' => $validated['address_street'] ?? $customer->address_street,
                'avatar' => $avatarPath,
                'is_active' => array_key_exists('is_active', $validated)
                    ? (bool) $validated['is_active']
                    : $customer->is_active,
                'owner_id' => array_key_exists('owner_id', $validated)
                    ? $validated['owner_id']
                    : $customer->owner_id,
            ],
            $this->clientProfileAttributes($validated),
            $filePaths
        ));

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

    private function normalizeClientRequest(Request $request): void
    {
        if ($request->has('owner_id') && ($request->input('owner_id') === '' || $request->input('owner_id') === 'null')) {
            $request->merge(['owner_id' => null]);
        }

        if ($request->has('mobile')) {
            $digits = preg_replace('/\D+/', '', (string) $request->input('mobile'));
            if (str_starts_with((string) $digits, '63') && strlen((string) $digits) >= 11) {
                $digits = substr((string) $digits, 2, 9);
            } elseif (strlen((string) $digits) > 9) {
                $digits = substr((string) $digits, -9);
            }
            $request->merge(['mobile' => $digits ?: null]);
        }
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

    /**
     * @return array<string, mixed>
     */
    private function clientProfileRules(bool $creating, ?int $ignoreUserId = null): array
    {
        $emailRule = $creating
            ? ['required', 'email', 'unique:users,email']
            : ['required', 'email', Rule::unique('users')->ignore($ignoreUserId)];

        return [
            'fname' => [$creating ? 'nullable' : 'nullable', 'string', 'max:255'],
            'lname' => ['nullable', 'string', 'max:255'],
            'company' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'email' => $emailRule,
            'address_street' => ['nullable', 'string', 'max:500'],
            'address_city' => ['nullable', 'string', 'max:255'],
            'address_province' => ['nullable', 'string', 'max:255'],
            'address_region' => ['nullable', 'string', 'max:255'],
            'address_zip' => ['nullable', 'string', 'max:50'],
            'address_country' => ['nullable', 'string', 'max:255'],
            'shipping_street' => ['nullable', 'string', 'max:500'],
            'shipping_city' => ['nullable', 'string', 'max:255'],
            'shipping_province' => ['nullable', 'string', 'max:255'],
            'shipping_region' => ['nullable', 'string', 'max:255'],
            'shipping_zip' => ['nullable', 'string', 'max:50'],
            'shipping_country' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'regex:/^\d{9}$/'],
            'phone' => ['nullable', 'string', 'max:50'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'industry' => ['nullable', 'string', 'max:255'],
            'tax_classification' => ['nullable', 'string', 'max:255'],
            'tin_number' => ['nullable', 'string', 'max:100'],
            'other_numbers' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:10'],
            'workdrive_folder_url' => ['nullable', 'string', 'max:500'],
            'workdrive_folder_id' => ['nullable', 'string', 'max:255'],
            'client_classification' => ['nullable', 'string', 'max:100'],
            'client_type' => ['nullable', 'string', 'max:100'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'ownership' => ['nullable', 'string', 'max:100'],
            'billing_in_charge' => ['nullable', 'string', 'max:255'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'bir_certificate' => ['nullable', 'file', 'max:5120'],
            'business_permit' => ['nullable', 'file', 'max:5120'],
            'sec_dti_registration' => ['nullable', 'file', 'max:5120'],
            'valid_id_signatories' => ['nullable', 'file', 'max:5120'],
            'gen_info_sheet' => ['nullable', 'file', 'max:5120'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: string, 1: string}
     */
    private function resolveContactNames(array $validated, ?User $existing = null): array
    {
        $contact = trim((string) ($validated['contact_person'] ?? ''));
        if ($contact !== '') {
            $parts = preg_split('/\s+/', $contact, 2) ?: [];
            $fname = $parts[0] ?? 'Client';
            $lname = $parts[1] ?? ($validated['company'] ?? 'Account');

            return [$fname, $lname];
        }

        $fname = trim((string) ($validated['fname'] ?? $existing?->fname ?? ''));
        $lname = trim((string) ($validated['lname'] ?? $existing?->lname ?? ''));

        if ($fname === '') {
            $fname = 'Client';
        }
        if ($lname === '') {
            $lname = (string) ($validated['company'] ?? $existing?->mname ?? 'Account');
        }

        return [$fname, $lname];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function clientProfileAttributes(array $validated): array
    {
        $keys = [
            'industry',
            'tax_classification',
            'tin_number',
            'other_numbers',
            'currency',
            'workdrive_folder_url',
            'workdrive_folder_id',
            'client_classification',
            'client_type',
            'contact_person',
            'website',
            'ownership',
            'billing_in_charge',
            'exchange_rate',
            'address_city',
            'address_province',
            'address_region',
            'address_zip',
            'address_country',
            'shipping_street',
            'shipping_city',
            'shipping_province',
            'shipping_region',
            'shipping_zip',
            'shipping_country',
        ];

        $attrs = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $validated)) {
                $attrs[$key] = $validated[$key];
            }
        }

        if (!array_key_exists('currency', $attrs) || $attrs['currency'] === null || $attrs['currency'] === '') {
            $attrs['currency'] = 'PHP';
        }

        if (!array_key_exists('exchange_rate', $attrs) || $attrs['exchange_rate'] === null || $attrs['exchange_rate'] === '') {
            $attrs['exchange_rate'] = 1;
        }

        return $attrs;
    }

    /**
     * @return array<string, mixed>
     */
    private function clientProfilePayload(User $customer): array
    {
        return [
            'industry' => $customer->industry,
            'tax_classification' => $customer->tax_classification,
            'tin_number' => $customer->tin_number,
            'other_numbers' => $customer->other_numbers,
            'currency' => $customer->currency ?: 'PHP',
            'workdrive_folder_url' => $customer->workdrive_folder_url,
            'workdrive_folder_id' => $customer->workdrive_folder_id,
            'client_classification' => $customer->client_classification,
            'client_type' => $customer->client_type,
            'contact_person' => $customer->contact_person,
            'website' => $customer->website,
            'ownership' => $customer->ownership,
            'billing_in_charge' => $customer->billing_in_charge,
            'exchange_rate' => $customer->exchange_rate,
            'shipping_street' => $customer->shipping_street,
            'shipping_city' => $customer->shipping_city,
            'shipping_province' => $customer->shipping_province,
            'shipping_region' => $customer->shipping_region,
            'shipping_zip' => $customer->shipping_zip,
            'shipping_country' => $customer->shipping_country,
            'bir_certificate' => $customer->bir_certificate,
            'business_permit' => $customer->business_permit,
            'sec_dti_registration' => $customer->sec_dti_registration,
            'valid_id_signatories' => $customer->valid_id_signatories,
            'gen_info_sheet' => $customer->gen_info_sheet,
            'bir_certificate_url' => StorageUrl::publicAsset($customer->bir_certificate),
            'business_permit_url' => StorageUrl::publicAsset($customer->business_permit),
            'sec_dti_registration_url' => StorageUrl::publicAsset($customer->sec_dti_registration),
            'valid_id_signatories_url' => StorageUrl::publicAsset($customer->valid_id_signatories),
            'gen_info_sheet_url' => StorageUrl::publicAsset($customer->gen_info_sheet),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function storeClientDocuments(Request $request, ?User $existing = null): array
    {
        $fields = [
            'bir_certificate',
            'business_permit',
            'sec_dti_registration',
            'valid_id_signatories',
            'gen_info_sheet',
        ];

        $paths = [];
        foreach ($fields as $field) {
            if (!$request->hasFile($field)) {
                continue;
            }

            $previous = $existing?->{$field};
            if ($previous && Storage::disk('public')->exists($previous)) {
                Storage::disk('public')->delete($previous);
            }

            $paths[$field] = $request->file($field)->store('client-documents', 'public');
        }

        return $paths;
    }
}
