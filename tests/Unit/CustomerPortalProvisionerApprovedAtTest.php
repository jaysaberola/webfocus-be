<?php

namespace Tests\Unit;

use App\Models\CustomerPaymentProof;
use App\Models\ProvisioningRun;
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

    public function test_completed_provisioning_run_is_active_live(): void
    {
        $run = new ProvisioningRun();
        $run->status = 'completed';

        $transaction = new SalesTransaction();
        $transaction->payment_status = 'paid';
        $transaction->order_status = 'processing';
        $transaction->setRelation('provisioningRun', $run);
        $transaction->setRelation('paymentProofs', collect());

        $this->assertSame(
            CustomerPortalProvisioner::STATUS_ACTIVE,
            CustomerPortalProvisioner::resolveServiceStatus($transaction)
        );
    }

    public function test_finished_countdown_without_a_run_is_active_live(): void
    {
        $item = new \App\Models\SalesTransactionItem();
        $item->name = 'Business Hosting';
        $item->item_type = 'service';

        $transaction = new SalesTransaction();
        $transaction->payment_status = 'paid';
        $transaction->order_status = 'processing';
        $transaction->notes = '[APPROVED_AT:2026-10-01T08:00:00+00:00]';
        $transaction->setRelation('provisioningRun', null);
        $transaction->setRelation('paymentProofs', collect());
        $transaction->setRelation('items', collect([$item]));

        $this->assertTrue(CustomerPortalProvisioner::isProvisioningFinished($transaction));
        $this->assertSame(
            CustomerPortalProvisioner::STATUS_ACTIVE,
            CustomerPortalProvisioner::resolveServiceStatus($transaction)
        );
    }
}
