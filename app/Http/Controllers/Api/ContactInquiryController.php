<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactInquiry;
use App\Models\CustomerNotification;
use Illuminate\Http\Request;

class ContactInquiryController extends Controller
{
    private const STATUSES = ['new', 'in_review', 'responded', 'closed'];

    public function index(Request $request)
    {
        $this->assertCanView($request);

        $perPage = min(max($request->integer('per_page', 15), 1), 200);

        $query = ContactInquiry::query()
            ->when($request->filled('search'), function ($builder) use ($request) {
                $term = '%' . trim((string) $request->input('search')) . '%';
                $builder->where(function ($q) use ($term) {
                    $q->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('contact_number', 'like', $term)
                        ->orWhere('inquiry_type', 'like', $term)
                        ->orWhere('message', 'like', $term);
                });
            })
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->input('status')))
            ->latest('created_at')
            ->latest('id');

        return response()->json($query->paginate($perPage));
    }

    public function show(Request $request, ContactInquiry $contactInquiry)
    {
        $this->assertCanView($request);

        if (! $contactInquiry->read_at) {
            $contactInquiry->update(['read_at' => now()]);
            $contactInquiry->refresh();
        }

        $staffId = (int) ($request->user()?->id ?? 0);
        if ($staffId > 0) {
            CustomerNotification::query()
                ->where('customer_id', $staffId)
                ->where('reference_key', 'admin:contact-inquiry:' . $contactInquiry->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return response()->json(['data' => $this->mapInquiry($contactInquiry)]);
    }

    public function update(Request $request, ContactInquiry $contactInquiry)
    {
        $this->assertCanManage($request);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', self::STATUSES)],
        ]);

        $contactInquiry->update($validated);

        return response()->json([
            'message' => 'Inquiry updated.',
            'data' => $this->mapInquiry($contactInquiry->fresh()),
        ]);
    }

    private function mapInquiry(ContactInquiry $inquiry): array
    {
        return [
            'id' => $inquiry->id,
            'inquiry_type' => $inquiry->inquiry_type,
            'first_name' => $inquiry->first_name,
            'last_name' => $inquiry->last_name,
            'email' => $inquiry->email,
            'contact_number' => $inquiry->contact_number,
            'message' => $inquiry->message,
            'preferred_services' => $inquiry->preferred_services ?? [],
            'status' => $inquiry->status,
            'read_at' => optional($inquiry->read_at)->toIso8601String(),
            'created_at' => optional($inquiry->created_at)->toIso8601String(),
            'updated_at' => optional($inquiry->updated_at)->toIso8601String(),
        ];
    }

    private function assertCanView(Request $request): void
    {
        abort_unless($request->user()?->can('commerce_inquiries.view'), 403);
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()?->can('commerce_inquiries.manage'), 403);
    }
}
