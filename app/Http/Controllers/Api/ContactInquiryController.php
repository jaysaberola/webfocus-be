<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContactInquiryReplyMail;
use App\Models\ContactInquiry;
use App\Models\CustomerNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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

    public function reply(Request $request, ContactInquiry $contactInquiry)
    {
        $this->assertCanManage($request);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $thread = $this->normalizeConversation($contactInquiry);
        $thread[] = [
            'from' => 'sales',
            'text' => trim($validated['message']),
            'at' => now()->toIso8601String(),
        ];

        $updates = ['conversation' => $thread];
        if (in_array($contactInquiry->status, ['new', 'in_review'], true)) {
            $updates['status'] = 'responded';
        }

        $contactInquiry->update($updates);
        $contactInquiry->refresh();

        $replyText = trim($validated['message']);
        $customerEmail = trim((string) $contactInquiry->email);
        $emailed = false;

        if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            try {
                Mail::to($customerEmail)->send(new ContactInquiryReplyMail($contactInquiry, $replyText));
                $emailed = true;
            } catch (\Throwable) {
                // Conversation is saved even if outbound mail fails.
            }
        }

        return response()->json([
            'message' => $emailed
                ? 'Reply saved and emailed to ' . $customerEmail . '.'
                : 'Reply saved. Could not send email — check the customer email on this inquiry.',
            'data' => $this->mapInquiry($contactInquiry),
            'meta' => [
                'emailed' => $emailed,
                'recipient' => $emailed ? $customerEmail : null,
            ],
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
            'conversation' => $this->normalizeConversation($inquiry),
            'preferred_services' => $inquiry->preferred_services ?? [],
            'status' => $inquiry->status,
            'read_at' => optional($inquiry->read_at)->toIso8601String(),
            'created_at' => optional($inquiry->created_at)->toIso8601String(),
            'updated_at' => optional($inquiry->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @return list<array{from: string, text: string, at?: string|null}>
     */
    private function normalizeConversation(ContactInquiry $inquiry): array
    {
        $raw = $inquiry->conversation;
        if (is_array($raw) && $raw !== []) {
            $rows = [];
            foreach ($raw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $text = trim((string) ($row['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $from = ($row['from'] ?? '') === 'sales' ? 'sales' : 'client';
                $entry = [
                    'from' => $from,
                    'text' => $text,
                ];
                if (! empty($row['at'])) {
                    $entry['at'] = (string) $row['at'];
                }
                $rows[] = $entry;
            }

            if ($rows !== []) {
                return $rows;
            }
        }

        $text = trim((string) $inquiry->message);
        if ($text === '') {
            return [];
        }

        return [[
            'from' => 'client',
            'text' => $text,
            'at' => optional($inquiry->created_at)->toIso8601String(),
        ]];
    }

    private function assertCanView(Request $request): void
    {
        abort_unless($request->user()?->can('commerce_inquiries.view'), 403);
        $this->assertInquiriesStaffRole($request);
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()?->can('commerce_inquiries.manage'), 403);
        $this->assertInquiriesStaffRole($request);
    }

    private function assertInquiriesStaffRole(Request $request): void
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->hasRole('admin')) {
            return;
        }

        abort_if(
            $user->hasAnyRole(['sales_staff', 'sales_admin', 'finance_admin']),
            403,
            'Inquiries are only available to Customer Care.',
        );

        abort_unless(
            $user->hasRole('customer_care'),
            403,
            'Inquiries are only available to Customer Care.',
        );
    }
}
