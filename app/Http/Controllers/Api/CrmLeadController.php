<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Models\User;
use Illuminate\Http\Request;

class CrmLeadController extends Controller
{
    public function index(Request $request)
    {
        $this->assertCanView($request);

        $perPage = min(max($request->integer('per_page', 15), 1), 200);

        $query = CrmLead::query()
            ->with(['leadOwner:id,fname,lname,email', 'createdBy:id,fname,lname,email'])
            ->when($request->filled('search'), function ($builder) use ($request) {
                $term = '%' . trim((string) $request->input('search')) . '%';
                $builder->where(function ($q) use ($term) {
                    $q->where('company', 'like', $term)
                        ->orWhere('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('mobile', 'like', $term);
                });
            })
            ->latest('created_at')
            ->latest('id');

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $this->assertCanManage($request);
        $validated = $this->validatedPayload($request);
        $staff = $request->user();

        $lead = CrmLead::create(array_merge($validated, [
            'lead_owner_id' => $validated['lead_owner_id'] ?? $staff?->id,
            'created_by_id' => $staff?->id,
        ]));

        return response()->json([
            'message' => 'Lead created successfully.',
            'data' => $this->mapLead($lead->fresh(['leadOwner', 'createdBy'])),
        ], 201);
    }

    public function show(Request $request, CrmLead $crmLead)
    {
        $this->assertCanView($request);

        return response()->json([
            'data' => $this->mapLead($crmLead->load(['leadOwner', 'createdBy'])),
        ]);
    }

    public function update(Request $request, CrmLead $crmLead)
    {
        $this->assertCanManage($request);
        $validated = $this->validatedPayload($request, false);

        $crmLead->update($validated);

        return response()->json([
            'message' => 'Lead updated successfully.',
            'data' => $this->mapLead($crmLead->fresh(['leadOwner', 'createdBy'])),
        ]);
    }

    private function validatedPayload(Request $request, bool $requireCore = true): array
    {
        $rules = [
            'lead_owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'encoded_by' => ['nullable', 'string', 'max:255'],
            'lead_source' => [$requireCore ? 'required' : 'sometimes', 'nullable', 'string', 'max:120'],
            'lead_status' => [$requireCore ? 'required' : 'sometimes', 'nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'max:8'],
            'workdrive_folder_url' => ['nullable', 'string', 'max:500'],
            'workdrive_folder_id' => ['nullable', 'string', 'max:255'],
            'salutation' => ['nullable', 'string', 'max:40'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => [$requireCore ? 'required' : 'sometimes', 'nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:120'],
            'company' => [$requireCore ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:255'],
            'interested_in' => ['nullable', 'string', 'max:120'],
            'interested_specify' => ['nullable', 'string'],
            'address_street' => ['nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:120'],
            'address_province' => ['nullable', 'string', 'max:120'],
            'address_zip' => ['nullable', 'string', 'max:20'],
            'address_country' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ];

        return $request->validate($rules);
    }

    private function mapLead(CrmLead $lead): array
    {
        return [
            'id' => $lead->id,
            'lead_owner_id' => $lead->lead_owner_id,
            'lead_owner' => $this->mapUser($lead->leadOwner),
            'created_by_id' => $lead->created_by_id,
            'encoded_by' => $lead->encoded_by,
            'lead_source' => $lead->lead_source,
            'lead_status' => $lead->lead_status,
            'currency' => $lead->currency,
            'workdrive_folder_url' => $lead->workdrive_folder_url,
            'workdrive_folder_id' => $lead->workdrive_folder_id,
            'salutation' => $lead->salutation,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'title' => $lead->title,
            'company' => $lead->company,
            'email' => $lead->email,
            'mobile' => $lead->mobile,
            'website' => $lead->website,
            'interested_in' => $lead->interested_in,
            'interested_specify' => $lead->interested_specify,
            'address_street' => $lead->address_street,
            'address_city' => $lead->address_city,
            'address_province' => $lead->address_province,
            'address_zip' => $lead->address_zip,
            'address_country' => $lead->address_country,
            'description' => $lead->description,
            'remarks' => $lead->remarks,
            'created_at' => optional($lead->created_at)->toIso8601String(),
            'updated_at' => optional($lead->updated_at)->toIso8601String(),
        ];
    }

    private function mapUser(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $name = trim(($user->fname ?? '') . ' ' . ($user->lname ?? ''));

        return [
            'id' => $user->id,
            'fname' => $user->fname,
            'lname' => $user->lname,
            'email' => $user->email,
            'name' => $name !== '' ? $name : ($user->email ?? null),
        ];
    }

    private function assertCanView(Request $request): void
    {
        abort_unless($request->user()?->can('commerce_leads.view'), 403);
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()?->can('commerce_leads.manage'), 403);
    }
}
