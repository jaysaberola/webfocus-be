<?php

namespace Tests\Unit;

use App\Models\CustomerPaymentProof;
use App\Models\SalesTransaction;
use App\Services\CustomerPortalProvisioner;
use Carbon\Carbon;
use Tests\TestCase;

class CustomerPortalProvisionerApprovedAtTest extends TestCase
{
    public function test_reads_approved_at_from_notes_not_payment_date(): void
    {
        $transaction = new SalesTransaction();
        $transaction->id = 1;
        $transaction->transaction_no = 'ST-20260923-0001';
        $transaction->notes = "Payment date: 2026-09-20\n[APPROVED_AT:2026-09-23T03:11:00+00:00]";
        $transaction->setRelation('paymentProofs', collect());

        $this->assertSame(
            Carbon::parse('2026-09-23T03:11:00+00:00')->toIso8601String(),
            CustomerPortalProvisioner::approvedAt($transaction)
        );
    }

    public function test_falls_back_to_verified_proof_updated_at(): void
    {
        $proof = new CustomerPaymentProof();
        $proof->status = 'Verified & Credited';
        $proof->updated_at = Carbon::parse('2026-09-23 11:00:00');

        $transaction = new SalesTransaction();
        $transaction->id = 2;
        $transaction->transaction_no = 'ST-20260923-0002';
        $transaction->notes = 'Payment date: 2026-09-20';
        $transaction->setRelation('paymentProofs', collect([$proof]));

        $this->assertSame(
            Carbon::parse('2026-09-23 11:00:00')->toIso8601String(),
            CustomerPortalProvisioner::approvedAt($transaction)
        );
    }

    public function test_append_approval_stamp_adds_marker_once(): void
    {
        $first = CustomerPortalProvisioner::appendApprovalStamp(
            'Paynamics checkout',
            'PRF-260923-0001',
            Carbon::parse('2026-09-23T03:11:00+00:00')
        );

        $this->assertStringContainsString('Payment verified via proof PRF-260923-0001.', $first);
        $this->assertStringContainsString('[APPROVED_AT:2026-09-23T03:11:00+00:00]', $first);

        $second = CustomerPortalProvisioner::appendApprovalStamp(
            $first,
            'PRF-260923-0001',
            Carbon::parse('2026-09-24T03:11:00+00:00')
        );

        $this->assertSame(1, preg_match_all('/\[APPROVED_AT:[^\]]+\]/', $second));
        $this->assertStringContainsString('[APPROVED_AT:2026-09-23T03:11:00+00:00]', $second);
    }
}
