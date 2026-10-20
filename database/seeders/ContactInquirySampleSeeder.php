<?php

namespace Database\Seeders;

use App\Models\ContactInquiry;
use App\Services\CommerceStaffNotifier;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ContactInquirySampleSeeder extends Seeder
{
    public function run(): void
    {
        $samples = [
            [
                'email' => 'sample.inquiry.new@webfocus.local',
                'inquiry_type' => 'General Inquiry',
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'contact_number' => '09171234567',
                'message' => 'Hi, I would like to know more about your hosting plans for a small business website.',
                'preferred_services' => ['Hosting', 'Domains'],
                'status' => 'new',
                'read_at' => null,
                'created_at' => Carbon::now()->subHours(2),
                'conversation' => [[
                    'from' => 'client',
                    'text' => 'Hi, I would like to know more about your hosting plans for a small business website.',
                    'at' => Carbon::now()->subHours(2)->toIso8601String(),
                ]],
                'notify' => true,
            ],
            [
                'email' => 'sample.inquiry.review@webfocus.local',
                'inquiry_type' => 'Sales / Quotation',
                'first_name' => 'Jay',
                'last_name' => 'Saberola',
                'contact_number' => '+639473529584',
                'message' => 'We need a company website with e-commerce. Can you send a quotation?',
                'preferred_services' => ['Web Design', 'E-commerce'],
                'status' => 'in_review',
                'read_at' => Carbon::now()->subDay(),
                'created_at' => Carbon::now()->subDays(2),
                'conversation' => [
                    [
                        'from' => 'client',
                        'text' => 'We need a company website with e-commerce. Can you send a quotation?',
                        'at' => Carbon::now()->subDays(2)->toIso8601String(),
                    ],
                ],
                'notify' => false,
            ],
            [
                'email' => 'sample.inquiry.replied@webfocus.local',
                'inquiry_type' => 'Technical Support',
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'contact_number' => '09987654321',
                'message' => 'Our email on the domain is not receiving messages since yesterday.',
                'preferred_services' => ['Email Hosting'],
                'status' => 'responded',
                'read_at' => Carbon::now()->subDays(3),
                'created_at' => Carbon::now()->subDays(4),
                'conversation' => [
                    [
                        'from' => 'client',
                        'text' => 'Our email on the domain is not receiving messages since yesterday.',
                        'at' => Carbon::now()->subDays(4)->toIso8601String(),
                    ],
                    [
                        'from' => 'sales',
                        'text' => 'Thank you for reaching out. We checked your MX records and applied a fix. Please try sending a test email within the hour.',
                        'at' => Carbon::now()->subDays(3)->toIso8601String(),
                    ],
                    [
                        'from' => 'client',
                        'text' => 'Received now, thank you!',
                        'at' => Carbon::now()->subDays(3)->addHours(5)->toIso8601String(),
                    ],
                ],
                'notify' => false,
            ],
            [
                'email' => 'sample.inquiry.closed@webfocus.local',
                'inquiry_type' => 'Billing',
                'first_name' => 'Carlo',
                'last_name' => 'Mendoza',
                'contact_number' => '09189876543',
                'message' => 'Please confirm if my renewal payment was received.',
                'preferred_services' => null,
                'status' => 'closed',
                'read_at' => Carbon::now()->subWeek(),
                'created_at' => Carbon::now()->subWeeks(2),
                'conversation' => [
                    [
                        'from' => 'client',
                        'text' => 'Please confirm if my renewal payment was received.',
                        'at' => Carbon::now()->subWeeks(2)->toIso8601String(),
                    ],
                    [
                        'from' => 'sales',
                        'text' => 'Yes, your payment was posted on Oct 6. Your service is active until next year.',
                        'at' => Carbon::now()->subWeeks(2)->addDay()->toIso8601String(),
                    ],
                ],
                'notify' => false,
            ],
        ];

        foreach ($samples as $row) {
            $notify = (bool) ($row['notify'] ?? false);
            unset($row['notify']);

            $email = $row['email'];
            $payload = $row;
            unset($payload['email']);

            $inquiry = ContactInquiry::query()->updateOrCreate(
                ['email' => $email],
                $payload,
            );

            if ($notify && $inquiry->wasRecentlyCreated) {
                $name = trim($inquiry->first_name . ' ' . $inquiry->last_name) ?: $inquiry->email;
                app(CommerceStaffNotifier::class)->notifyOwnerAndRoles(
                    null,
                    ['customer_care'],
                    'admin:contact-inquiry:' . $inquiry->id,
                    'New Contact Us Inquiry',
                    "{$name} sent a {$inquiry->inquiry_type} message via Contact Us.",
                    'contact_inquiry',
                    '/public/commerce-admin?tab=inquiries&inquiry=' . $inquiry->id,
                    false,
                );
            }
        }

        $this->command?->info('Sample contact inquiries ready (4 records). Open Commerce Admin → Inquiries as Customer Care.');
    }
}
